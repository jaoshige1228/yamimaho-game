<?php

namespace App\Services\Dungeon\Exploration;

use App\Models\DungeonEventNode;
use App\Models\DungeonExplorationSession;
use App\Models\User;
use App\Models\UserCharacter;
use App\Services\Dungeon\DungeonEventCatalog;
use App\Services\Dungeon\DungeonProgressService;
use App\Services\Dungeon\PartyExplorationDamageService;
use App\Services\Player\UserCharacterService;

class DungeonExplorationEngine
{
    private const EPILOGUE_TEXT = '一行は先に進むことにした';

    public function __construct(
        private readonly DungeonEventCatalog $catalog = new DungeonEventCatalog,
        private readonly PartyExplorationDamageService $damage = new PartyExplorationDamageService,
        private readonly UserCharacterService $characters = new UserCharacterService,
        private readonly DungeonProgressService $progress = new DungeonProgressService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildInitialContext(User $user, string $eventCode): array
    {
        $target = $this->damage->pickRandomAliveSlot($user);

        return [
            'target_slot' => $target,
            'chosen_slot' => null,
            'last_success_rate' => null,
        ];
    }

    /**
     * Run nodes from current key until a segment must be returned to the client.
     *
     * @return array{segment: array<string, mixed>, next_node: ?string, complete: bool}
     */
    public function runUntilSegment(DungeonExplorationSession $session, User $user): array
    {
        $lines = [];
        $nodeKey = $session->current_node_key;

        while ($nodeKey !== null && $nodeKey !== '') {
            $node = $this->catalog->findNode($session->event_code, $nodeKey);
            $context = $session->context ?? [];

            if (in_array($node->node_type, ['party_choice', 'party_choice_skip'], true)) {
                $session->current_node_key = $nodeKey;
                $segment = $this->choiceSegment($user, $node, $context, includeSkip: $node->node_type === 'party_choice_skip');
                if ($lines !== []) {
                    $segment['lines'] = $lines;
                }

                return [
                    'segment' => $segment,
                    'next_node' => $nodeKey,
                    'complete' => false,
                ];
            }

            if ($node->node_type === 'binary_choice') {
                $session->current_node_key = $nodeKey;
                $segment = $this->binaryChoiceSegment($user, $node, $context);
                if ($lines !== []) {
                    $segment['lines'] = $lines;
                }

                return [
                    'segment' => $segment,
                    'next_node' => $nodeKey,
                    'complete' => false,
                ];
            }

            if ($this->isDeferredEffectNode($node->node_type)) {
                if ($lines !== []) {
                    $this->markLastLineSyncParty($lines);
                }

                $nodeKey = $this->commitEffectNode($user, $node, $context, $nodeKey);
                $session->context = $context;
                $session->save();
                continue;
            }

            if ($node->node_type === 'start_battle') {
                $session->current_node_key = $nodeKey;
                $session->save();

                return [
                    'segment' => [
                        'kind' => 'battle',
                        'enemy_code' => (string) ($node->next_default ?: 'inu_moe'),
                        'boss' => true,
                    ],
                    'next_node' => null,
                    'complete' => true,
                ];
            }

            if ($node->node_type === 'game_over') {
                $this->progress->reset($user);
                $this->characters->restorePartyToFull($user);

                return [
                    'segment' => ['kind' => 'game_over', 'text' => '全滅した……'],
                    'next_node' => null,
                    'complete' => true,
                ];
            }

            if ($node->node_type === 'chance_branch') {
                $result = $this->executeChanceBranch($node, $context, true);
                $nodeKey = $result['next_node'];
                continue;
            }

            if ($node->node_type === 'stat_check') {
                $session->current_node_key = $nodeKey;
                $session->save();

                return [
                    'segment' => ['kind' => 'story', 'lines' => $lines],
                    'next_node' => $nodeKey,
                    'complete' => false,
                ];
            }

            if ($node->node_type === 'end') {
                return $this->resolveEndNode($session, $nodeKey, $lines, $context);
            }

            if ($node->node_type === 'narration' && str_contains((string) $node->text, '{success_rate}')) {
                $context['last_success_rate'] = $this->successRateForUpcomingCheck(
                    $session->event_code,
                    (string) $node->next_default,
                    $user,
                    $context,
                );
                $session->context = $context;
            }

            if ($node->node_type === 'dialogue' && $node->speaker_role === 'random_ally_store') {
                if (empty($context['reactor_slot'])) {
                    $picked = $this->damage->pickRandomAliveSlot($user);
                    if ($picked !== null) {
                        $context['reactor_slot'] = $picked;
                        $session->context = $context;
                    }
                }
            }

            $line = $this->nodeToLine($user, $node, $context);
            if ($line !== null) {
                $lines[] = $line;
            }

            $nodeKey = $node->next_default;
        }

        return [
            'segment' => ['kind' => 'story', 'lines' => $lines],
            'next_node' => null,
            'complete' => $lines === [],
        ];
    }

    /**
     * Continue after story segment — advances past stat_check preview if needed.
     *
     * @return array{segment: array<string, mixed>, complete: bool}
     */
    public function continueSession(DungeonExplorationSession $session, User $user): array
    {
        $nodeKey = $session->current_node_key;
        if ($nodeKey === null || $nodeKey === '') {
            return ['segment' => ['kind' => 'complete'], 'complete' => true];
        }

        $node = $this->catalog->findNode($session->event_code, $nodeKey);

        if ($node->node_type === 'end') {
            $context = $session->context ?? [];
            $epilogue = $this->tryReturnEpilogueSegment($session, $context, $nodeKey);
            if ($epilogue !== null) {
                return [
                    'segment' => $epilogue['segment'],
                    'complete' => false,
                ];
            }

            return ['segment' => ['kind' => 'complete'], 'complete' => true];
        }

        if ($node->node_type === 'stat_check' || $node->node_type === 'chance_branch') {
            $context = $session->context ?? [];
            $result = $node->node_type === 'chance_branch'
                ? $this->executeChanceBranch($node, $context, true)
                : $this->executeStatCheck($session, $user, $node, $context, true);
            $session->context = $result['context'];
            $session->current_node_key = $result['next_node'];
            $session->save();

            $run = $this->runUntilSegment($session, $user);
            if ($run['complete']) {
                return ['segment' => $run['segment'], 'complete' => true];
            }

            $session->current_node_key = $run['next_node'];
            $session->save();

            return ['segment' => $run['segment'], 'complete' => false];
        }

        if ($this->isDeferredEffectNode($node->node_type)) {
            $context = $session->context ?? [];
            $nodeKey = $this->commitEffectNode($user, $node, $context, $nodeKey);
            $session->context = $context;
            $session->current_node_key = $nodeKey;
            $session->save();

            $run = $this->runUntilSegment($session, $user);
            if ($run['complete']) {
                return ['segment' => $run['segment'], 'complete' => true];
            }

            $session->current_node_key = $run['next_node'];
            $session->save();

            return ['segment' => $run['segment'], 'complete' => false];
        }

        $nodeKey = $node->next_default;
        $session->current_node_key = $nodeKey;
        $session->save();

        $run = $this->runUntilSegment($session, $user);
        if ($run['complete']) {
            return ['segment' => $run['segment'], 'complete' => true];
        }

        $session->current_node_key = $run['next_node'];
        $session->save();

        return ['segment' => $run['segment'], 'complete' => false];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $context
     * @return array{segment: array<string, mixed>, next_node: ?string, complete: bool}
     */
    private function resolveEndNode(
        DungeonExplorationSession $session,
        string $nodeKey,
        array $lines,
        array $context,
    ): array {
        if ($lines !== []) {
            $session->current_node_key = $nodeKey;
            $session->context = $context;
            $session->save();

            return [
                'segment' => ['kind' => 'story', 'lines' => $lines],
                'next_node' => $nodeKey,
                'complete' => false,
            ];
        }

        $epilogue = $this->tryReturnEpilogueSegment($session, $context, $nodeKey);
        if ($epilogue !== null) {
            return $epilogue;
        }

        return [
            'segment' => ['kind' => 'complete'],
            'next_node' => null,
            'complete' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{segment: array<string, mixed>, next_node: string, complete: bool}|null
     */
    private function tryReturnEpilogueSegment(
        DungeonExplorationSession $session,
        array $context,
        string $resumeNodeKey,
    ): ?array {
        if ((bool) ($context['epilogue_shown'] ?? false)) {
            return null;
        }

        $context['epilogue_shown'] = true;
        $session->context = $context;
        $session->current_node_key = $resumeNodeKey;
        $session->save();

        return [
            'segment' => [
                'kind' => 'story',
                'lines' => [$this->fixedEpilogueLine()],
            ],
            'next_node' => $resumeNodeKey,
            'complete' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fixedEpilogueLine(): array
    {
        return [
            'type' => 'narration',
            'text' => self::EPILOGUE_TEXT,
            'sfx' => null,
        ];
    }

    private function isDeferredEffectNode(string $nodeType): bool
    {
        return in_array($nodeType, [
            'apply_damage',
            'apply_damage_chosen',
            'apply_damage_party',
            'restore_party',
            'check_party_alive',
        ], true);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function markLastLineSyncParty(array &$lines): void
    {
        if ($lines === []) {
            return;
        }

        $lines[array_key_last($lines)]['sync_party'] = true;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function commitEffectNode(
        User $user,
        DungeonEventNode $node,
        array &$context,
        string $effectNodeKey,
    ): string {
        /** @var array<string, string> $outcomes */
        $outcomes = $context['committed_effect_outcomes'] ?? [];
        if (isset($outcomes[$effectNodeKey])) {
            return $outcomes[$effectNodeKey];
        }

        $this->capturePartySnapshot($user, $context);
        $next = $this->executeEffectNode($user, $node, $context);
        $outcomes[$effectNodeKey] = $next;
        $context['committed_effect_outcomes'] = $outcomes;

        /** @var list<string> $committed */
        $committed = $context['committed_effects'] ?? [];
        if (! in_array($effectNodeKey, $committed, true)) {
            $committed[] = $effectNodeKey;
        }
        $context['committed_effects'] = $committed;

        return $next;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function capturePartySnapshot(User $user, array &$context): void
    {
        if (isset($context['presentation_party_snapshot'])) {
            return;
        }

        $context['presentation_party_snapshot'] = $this->characters->partyInSlotOrder($user)
            ->map(fn (UserCharacter $character, string $slotId) => $this->characters->toPartyUnitPayload($character, $slotId))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function executeEffectNode(User $user, DungeonEventNode $node, array &$context): string
    {
        return match ($node->node_type) {
            'apply_damage' => $this->executeApplyDamage($user, $node, $context),
            'apply_damage_chosen' => $this->executeApplyDamageChosen($user, $node, $context),
            'apply_damage_party' => $this->executeApplyDamageParty($user, $node),
            'restore_party' => $this->executeRestoreParty($user, $node),
            'check_party_alive' => $this->executeCheckPartyAlive($user, $node),
            default => throw new \InvalidArgumentException("Unknown effect node type: {$node->node_type}"),
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function executeApplyDamage(User $user, DungeonEventNode $node, array $context): string
    {
        $targetSlot = (string) ($context['target_slot'] ?? '');
        if ($targetSlot !== '') {
            $this->damage->applyDamageToSlot($user, $targetSlot, (int) $node->fixed_damage);
        }

        return (string) $node->next_default;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function executeApplyDamageChosen(User $user, DungeonEventNode $node, array $context): string
    {
        $chosenSlot = (string) ($context['chosen_slot'] ?? '');
        if ($chosenSlot !== '') {
            $this->damage->applyDamageToSlot($user, $chosenSlot, (int) $node->fixed_damage);
        }

        return (string) $node->next_default;
    }

    private function executeApplyDamageParty(User $user, DungeonEventNode $node): string
    {
        $this->damage->applyDamageToParty($user, (int) $node->fixed_damage);

        return (string) $node->next_default;
    }

    private function executeRestoreParty(User $user, DungeonEventNode $node): string
    {
        $percent = (int) ($node->stat_multiplier ?? 0);
        if ($percent >= 1 && $percent <= 99) {
            $this->damage->restorePartyByPercent($user, $percent);
        } else {
            $this->damage->restorePartyToFull($user);
        }

        return (string) $node->next_default;
    }

    private function executeCheckPartyAlive(User $user, DungeonEventNode $node): string
    {
        return $this->damage->isPartyWiped($user)
            ? (string) ($node->next_on_fail ?: $node->next_default)
            : (string) ($node->next_on_success ?: $node->next_default);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function applyChoice(DungeonExplorationSession $session, User $user, string $choiceId): void
    {
        $node = $this->catalog->findNode($session->event_code, (string) $session->current_node_key);
        $context = $session->context ?? [];

        if ($node->node_type === 'binary_choice') {
            $next = $choiceId === 'yes'
                ? ($node->next_on_success ?: null)
                : ($node->next_on_fail ?: null);
            if ($next === null || $next === '') {
                throw new \InvalidArgumentException('選択先ノードが未定義です。');
            }
            $session->current_node_key = $next;
        } elseif ($choiceId === 'skip') {
            $next = $node->next_on_fail ?: $node->next_default;
            if ($next === null || $next === '') {
                throw new \InvalidArgumentException('スキップ先ノードが未定義です。');
            }
            $session->current_node_key = $next;
        } else {
            $context['chosen_slot'] = $choiceId;
            if ($node->node_type === 'party_choice_skip') {
                $next = $node->next_on_success ?: $node->next_default;
            } else {
                $next = $node->next_on_success ?: $node->next_default ?: 'force_open';
            }
            if ($next === null || $next === '') {
                throw new \InvalidArgumentException('選択先ノードが未定義です。');
            }
            $session->current_node_key = $next;
        }

        $session->context = $context;
        $session->save();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{preview_line: ?array<string, mixed>, next_node: ?string, context: array<string, mixed>}
     */
    private function executeStatCheck(
        DungeonExplorationSession $session,
        User $user,
        DungeonEventNode $node,
        array $context,
        bool $performRoll = false,
    ): array {
        $attr = (string) $node->stat_attr;
        $multiplier = (int) ($node->stat_multiplier ?? 2);
        $slot = $attr === 'str'
            ? (string) ($context['chosen_slot'] ?? $context['target_slot'] ?? '')
            : (string) ($context['target_slot'] ?? '');

        $rate = $this->successRateForSlot($user, $slot, $attr, $multiplier);
        $context['last_success_rate'] = $rate;

        if (! $performRoll) {
            return [
                'preview_line' => null,
                'next_node' => $node->node_key,
                'context' => $context,
            ];
        }

        $forcedSuccess = config('game.dungeon.test_stat_success');
        if ($forcedSuccess === true) {
            $success = true;
        } elseif ($forcedSuccess === false) {
            $success = false;
        } else {
            $forcedRoll = config('game.dungeon.test_roll');
            $roll = is_numeric($forcedRoll) ? (int) $forcedRoll : random_int(1, 100);
            $success = $roll <= $rate;
        }
        $next = $success
            ? ($node->next_on_success ?: $node->next_default)
            : ($node->next_on_fail ?: $node->next_default);

        return [
            'preview_line' => null,
            'next_node' => $next,
            'context' => $context,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{preview_line: ?array<string, mixed>, next_node: ?string, context: array<string, mixed>}
     */
    private function executeChanceBranch(
        DungeonEventNode $node,
        array $context,
        bool $performRoll = false,
    ): array {
        $rate = min(100, max(0, (int) ($node->stat_multiplier ?? 50)));

        if (! $performRoll) {
            return [
                'preview_line' => null,
                'next_node' => $node->node_key,
                'context' => $context,
            ];
        }

        $forcedSuccess = config('game.dungeon.test_stat_success');
        if ($forcedSuccess === true) {
            $success = true;
        } elseif ($forcedSuccess === false) {
            $success = false;
        } else {
            $forcedRoll = config('game.dungeon.test_roll');
            $roll = is_numeric($forcedRoll) ? (int) $forcedRoll : random_int(1, 100);
            $success = $roll <= $rate;
        }

        $next = $success
            ? ($node->next_on_success ?: $node->next_default)
            : ($node->next_on_fail ?: $node->next_default);

        return [
            'preview_line' => null,
            'next_node' => $next,
            'context' => $context,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function successRateForUpcomingCheck(
        string $eventCode,
        string $nextNodeKey,
        User $user,
        array $context,
    ): int {
        if ($nextNodeKey === '') {
            return $this->successRateForContext($user, $context, 'spd', 2);
        }

        try {
            $nextNode = $this->catalog->findNode($eventCode, $nextNodeKey);
        } catch (\InvalidArgumentException) {
            return $this->successRateForContext($user, $context, 'spd', 2);
        }

        if ($nextNode->node_type !== 'stat_check') {
            return $this->successRateForContext($user, $context, 'spd', 2);
        }

        $attr = (string) $nextNode->stat_attr;
        $multiplier = (int) ($nextNode->stat_multiplier ?? 2);
        $slot = $attr === 'str'
            ? (string) ($context['chosen_slot'] ?? $context['target_slot'] ?? '')
            : (string) ($context['target_slot'] ?? '');

        return $this->successRateForSlot($user, $slot, $attr, $multiplier);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function successRateForContext(User $user, array $context, string $attr, int $multiplier): int
    {
        $slot = $attr === 'str'
            ? (string) ($context['chosen_slot'] ?? $context['target_slot'] ?? '')
            : (string) ($context['target_slot'] ?? '');

        return $this->successRateForSlot($user, $slot, $attr, $multiplier);
    }

    private function successRateForSlot(User $user, string $slotId, string $attr, int $multiplier): int
    {
        if ($slotId === '') {
            return 0;
        }

        $party = $this->characters->partyInSlotOrder($user);
        /** @var UserCharacter|null $character */
        $character = $party->get($slotId);
        if ($character === null) {
            return 0;
        }

        $value = match ($attr) {
            'str' => (int) $character->str,
            'spd' => (int) $character->spd,
            default => 0,
        };

        return min(100, $value * $multiplier);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function nodeToLine(User $user, DungeonEventNode $node, array $context): ?array
    {
        if ($node->node_type === 'narration') {
            return [
                'type' => 'narration',
                'text' => $this->interpolate((string) $node->text, $user, $context),
                'sfx' => $node->sfx ?: null,
            ];
        }

        if ($node->node_type === 'dialogue') {
            $slot = $this->resolveSpeakerSlot($user, $node, $context);
            if ($slot === null) {
                return null;
            }

            $text = $this->dialogueTextForSlot($node, $slot);
            if ($text === null || $text === '') {
                $text = trim((string) $node->text);
            }
            if ($text === '') {
                return null;
            }

            $contextWithSpeaker = array_merge($context, [
                'chosen_slot' => $context['chosen_slot'] ?? $slot,
                'target_slot' => $context['target_slot'] ?? $slot,
            ]);

            return [
                'type' => 'dialogue',
                'character' => $slot,
                'text' => $this->interpolate($text, $user, $contextWithSpeaker),
                'sfx' => $node->sfx ?: null,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function interpolate(string $text, User $user, array $context): string
    {
        $targetSlot = (string) ($context['target_slot'] ?? '');
        $chosenSlot = (string) ($context['chosen_slot'] ?? '');

        $targetName = $this->nameForSlot($user, $targetSlot);
        $chosenName = $this->nameForSlot($user, $chosenSlot);

        $replacements = [
            '{target_name}' => $targetName,
            '{chosen_name}' => $chosenName,
            '{name}' => $chosenSlot !== '' ? $chosenName : $targetName,
            '{success_rate}' => (string) ($context['last_success_rate'] ?? 0),
        ];

        $text = str_replace(array_keys($replacements), array_values($replacements), $text);

        return str_replace('\\n', "\n", $text);
    }

    private function nameForSlot(User $user, string $slotId): string
    {
        if ($slotId === '') {
            return '???';
        }

        $party = $this->characters->partyInSlotOrder($user);
        /** @var UserCharacter|null $character */
        $character = $party->get($slotId);

        return $character?->characterMaster->name ?? '???';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveSpeakerSlot(User $user, DungeonEventNode $node, array $context): ?string
    {
        $role = (string) $node->speaker_role;

        if (str_starts_with($role, 'fixed:')) {
            return substr($role, 6) ?: null;
        }

        if ($role === 'target') {
            return $context['target_slot'] ?? null;
        }

        if ($role === 'chosen') {
            return $context['chosen_slot'] ?? null;
        }

        if ($role === 'random_ally_except_target') {
            return $this->damage->pickRandomAliveSlot($user, $context['target_slot'] ?? null);
        }

        if ($role === 'random_ally_store') {
            return $context['reactor_slot'] ?? $this->damage->pickRandomAliveSlot($user);
        }

        if ($role === 'random_ally') {
            return $this->damage->pickRandomAliveSlot($user);
        }

        if ($role === 'random_ally_except_chosen') {
            return $this->damage->pickRandomAliveSlot($user, $context['chosen_slot'] ?? null);
        }

        if ($role === 'random_ally_except_reactor') {
            return $this->damage->pickRandomAliveSlot($user, $context['reactor_slot'] ?? null);
        }

        return null;
    }

    private function dialogueTextForSlot(DungeonEventNode $node, string $slotId): ?string
    {
        return match ($slotId) {
            'pc1' => $node->dialogue_pc1,
            'pc2' => $node->dialogue_pc2,
            'pc3' => $node->dialogue_pc3,
            'pc4' => $node->dialogue_pc4,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function choiceSegment(User $user, DungeonEventNode $node, array $context, bool $includeSkip = false): array
    {
        $options = [];
        $party = $this->characters->partyInSlotOrder($user);

        foreach ($party as $slotId => $character) {
            if ($character->hp <= 0) {
                continue;
            }
            $label = $node->node_type === 'party_choice_skip'
                ? $character->characterMaster->name
                : $character->characterMaster->name.'（筋力'.(int) $character->str.'）';
            $options[] = [
                'slot_id' => $slotId,
                'name' => $character->characterMaster->name,
                'str' => (int) $character->str,
                'label' => $label,
            ];
        }

        if ($includeSkip) {
            $skipLabel = trim((string) ($node->dialogue_pc4 ?? ''));
            $options[] = [
                'slot_id' => 'skip',
                'name' => $skipLabel !== '' ? $skipLabel : '毒見しない',
                'str' => 0,
                'label' => $skipLabel !== '' ? $skipLabel : '毒見しない',
            ];
        }

        return [
            'kind' => 'choice',
            'prompt' => $this->interpolate((string) $node->text, $user, $context),
            'options' => $options,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function binaryChoiceSegment(User $user, DungeonEventNode $node, array $context): array
    {
        return [
            'kind' => 'choice',
            'prompt' => $this->interpolate((string) $node->text, $user, $context),
            'options' => [
                [
                    'slot_id' => 'yes',
                    'name' => 'はい',
                    'label' => trim((string) ($node->dialogue_pc1 ?? '')) !== '' ? $node->dialogue_pc1 : 'はい',
                ],
                [
                    'slot_id' => 'no',
                    'name' => 'いいえ',
                    'label' => trim((string) ($node->dialogue_pc2 ?? '')) !== '' ? $node->dialogue_pc2 : 'いいえ',
                ],
            ],
        ];
    }
}
