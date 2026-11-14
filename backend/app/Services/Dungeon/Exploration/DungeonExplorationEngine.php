<?php

namespace App\Services\Dungeon\Exploration;

use App\Models\DungeonEventNode;
use App\Models\DungeonExplorationSession;
use App\Models\User;
use App\Models\UserCharacter;
use App\Services\Dungeon\DungeonEventCatalog;
use App\Services\Dungeon\DungeonFloorConfig;
use App\Services\Dungeon\DungeonProgressService;
use App\Services\Dungeon\PartyExplorationDamageService;
use App\Services\Growth\LevelGrowthService;
use App\Services\Player\ItemInventoryService;
use App\Services\Player\PartyGoldService;
use App\Services\Player\UserCharacterService;

class DungeonExplorationEngine
{
    private const EPILOGUE_TEXT = '一行は先に進むことにした';

    /** {damage_amount} / {gold_amount} の展開で、後続の効果ノードを探す最大ノード数 */
    private const EFFECT_AMOUNT_LOOKAHEAD = 8;

    /** @var array<string, array{name: string, sprite: string}> */
    private const NPC_SPEAKERS = [
        'gusu' => ['name' => 'グース', 'sprite' => 'npc_gusu'],
        'kasu' => ['name' => 'カース', 'sprite' => 'npc_kasu'],
        // ステラ（同じ魔法学校の生徒。2 層・3 層の「ステラ登場」）。立ち絵は characters/stella.webp（PC と同じ 600×1200）
        'stella' => ['name' => 'ステラ', 'sprite' => 'stella'],
    ];

    public function __construct(
        private readonly DungeonEventCatalog $catalog = new DungeonEventCatalog,
        private readonly PartyExplorationDamageService $damage = new PartyExplorationDamageService,
        private readonly UserCharacterService $characters = new UserCharacterService,
        private readonly DungeonProgressService $progress = new DungeonProgressService,
        private readonly LevelGrowthService $growth = new LevelGrowthService,
        private readonly ItemInventoryService $items = new ItemInventoryService,
        private readonly PartyGoldService $gold = new PartyGoldService,
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
            'last_stat_check_label' => null,
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
        $floor = (int) $session->floor_at_start;

        while ($nodeKey !== null && $nodeKey !== '') {
            $node = $this->catalog->findNode($session->event_code, $nodeKey, $floor);
            $context = $session->context ?? [];

            if (in_array($node->node_type, ['party_choice', 'party_choice_skip'], true)) {
                $session->current_node_key = $nodeKey;
                $segment = $this->choiceSegment(
                    $user,
                    $node,
                    $context,
                    includeSkip: $node->node_type === 'party_choice_skip',
                    eventCode: $session->event_code,
                    floor: $floor,
                );
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

            if ($node->node_type === 'assign_rescuer') {
                $except = $context['target_slot'] ?? null;
                $picked = $this->damage->pickRandomAliveSlot($user, is_string($except) ? $except : null);
                if ($picked !== null) {
                    $context['chosen_slot'] = $picked;
                }
                $session->context = $context;
                $nodeKey = (string) $node->next_default;
                continue;
            }

            if ($node->node_type === 'check_party_level') {
                $nodeKey = $this->executeCheckPartyLevel($user, $node);
                continue;
            }

            if ($node->node_type === 'check_floor_cleared') {
                $nodeKey = $this->executeCheckFloorCleared($user, $node);
                continue;
            }

            if ($node->node_type === 'stat_check_each_ally') {
                $this->capturePartySnapshot($user, $context);
                $lines = array_merge($lines, $this->buildStatCheckEachAllyLines($user, $node, $context, $floor));
                $session->context = $context;
                $nodeKey = (string) $node->next_default;
                continue;
            }

            if ($this->isDeferredEffectNode($node->node_type)) {
                if ($lines !== []) {
                    $this->markLastLineSyncParty($lines, $node);
                }

                $nodeKey = $this->commitEffectNode($user, $node, $context, $nodeKey);
                $session->context = $context;
                $session->save();
                continue;
            }

            if ($node->node_type === 'start_battle') {
                if ($lines !== []) {
                    $session->current_node_key = $nodeKey;
                    $session->save();

                    return [
                        'segment' => ['kind' => 'story', 'lines' => $lines],
                        'next_node' => $nodeKey,
                        'complete' => false,
                    ];
                }

                $postBattleNode = (string) ($node->next_on_success ?: '');
                if ($postBattleNode !== '') {
                    $context['post_battle_node'] = $postBattleNode;
                }
                $session->context = $context;
                $session->current_node_key = $nodeKey;
                $session->save();

                $eventFloor = (int) $session->floor_at_start;
                $midBossEventCode = DungeonFloorConfig::midBossEventCode($eventFloor);
                if ($midBossEventCode !== null && $session->event_code === $midBossEventCode) {
                    return [
                        'segment' => [
                            'kind' => 'battle',
                            'mid_boss' => true,
                            'enemy_codes' => DungeonFloorConfig::midBossEnemyCodes($eventFloor),
                            'post_battle_node' => $postBattleNode,
                            'boss' => false,
                        ],
                        'next_node' => null,
                        'complete' => true,
                    ];
                }

                // ノードの next_default が戦闘のセット（config の dungeon.event_battles。裂け目の4人の魔法使いなど）を指すとき
                $eventBattle = DungeonFloorConfig::eventBattle((string) $node->next_default);
                if ($eventBattle !== null) {
                    return [
                        'segment' => [
                            'kind' => 'battle',
                            'event_battle' => true,
                            'enemy_codes' => $eventBattle['enemy_codes'],
                            'rift' => $eventBattle['rift'],
                            'boss' => true,
                        ],
                        'next_node' => null,
                        'complete' => true,
                    ];
                }

                return [
                    'segment' => [
                        'kind' => 'battle',
                        // 単体の敵コード（その層の enemy_masters の行）
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

            if (
                $node->node_type === 'narration'
                && (
                    str_contains((string) $node->text, '{success_rate}')
                    || str_contains((string) $node->text, '{stat_check_label}')
                )
            ) {
                $context = $this->applyUpcomingStatCheckPreviewContext(
                    $session->event_code,
                    (string) $node->next_default,
                    $user,
                    $context,
                    $floor,
                );
                $session->context = $context;
            }

            if ($node->node_type === 'dialogue') {
                if ($node->speaker_role === 'random_ally_store') {
                    if (empty($context['reactor_slot'])) {
                        $picked = $this->damage->pickRandomAliveSlot($user);
                        if ($picked !== null) {
                            $context['reactor_slot'] = $picked;
                        }
                    }
                } elseif ($node->speaker_role === 'random_ally_except_target_store') {
                    $picked = $this->damage->pickRandomAliveSlot($user, $context['target_slot'] ?? null);
                    if ($picked !== null) {
                        $context['reactor_slot'] = $picked;
                    }
                } elseif ($node->speaker_role === 'random_ally_except_target_and_reactor_as_helper') {
                    $picked = $this->pickRandomExceptTargetAndReactor($user, $context);
                    if ($picked !== null) {
                        $context['helper_slot'] = $picked;
                    }
                }
            }

            $this->applyUpcomingEffectAmounts($session->event_code, $node, $context, $floor);
            $session->context = $context;

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

        $node = $this->catalog->findNode($session->event_code, $nodeKey, (int) $session->floor_at_start);

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

            $dice = $result['dice'] ?? null;

            $run = $this->runUntilSegment($session, $user);
            if ($run['complete']) {
                return [
                    'segment' => $this->segmentWithStatCheckResult($run['segment'], $dice),
                    'complete' => true,
                ];
            }

            $session->current_node_key = $run['next_node'];
            $session->save();

            return [
                'segment' => $this->segmentWithStatCheckResult($run['segment'], $dice),
                'complete' => false,
            ];
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

        if ($node->node_type === 'start_battle') {
            $run = $this->runUntilSegment($session, $user);
            if ($run['complete']) {
                return ['segment' => $run['segment'], 'complete' => true];
            }

            $session->current_node_key = $run['next_node'];
            $session->save();

            return ['segment' => $run['segment'], 'complete' => false];
        }

        if (in_array($node->node_type, ['dialogue', 'narration'], true)) {
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
        if ((bool) ($context['skip_epilogue'] ?? false)) {
            return null;
        }

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
            'grant_item',
            'grant_gold',
        ], true);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function markLastLineSyncParty(array &$lines, ?DungeonEventNode $effectNode = null): void
    {
        if ($lines === []) {
            return;
        }

        $syncKey = array_key_last($lines);

        if ($effectNode !== null) {
            $narrationKey = $this->findLastNarrationLineKey($lines);
            if ($narrationKey !== null) {
                $this->appendEffectAmountToLine($lines, $narrationKey, $effectNode);
                $syncKey = $narrationKey;
            } else {
                $this->appendEffectAmountToLine($lines, $syncKey, $effectNode);
            }
        }

        $lines[$syncKey]['sync_party'] = true;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function findLastNarrationLineKey(array $lines): ?int
    {
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (($lines[$i]['type'] ?? '') === 'narration') {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function appendEffectAmountToLine(array &$lines, int $key, DungeonEventNode $effectNode): void
    {
        if (($lines[$key]['type'] ?? '') === 'dialogue') {
            return;
        }

        $text = (string) ($lines[$key]['text'] ?? '');

        if ($effectNode->node_type === 'grant_gold') {
            $amount = (int) ($effectNode->stat_multiplier ?? 0);
            if ($amount <= 0) {
                return;
            }
            if (str_contains($text, '{gold_amount}')) {
                $lines[$key]['text'] = str_replace('{gold_amount}', (string) $amount, $text);

                return;
            }
            if (! $this->textContainsAmount($text, $amount)) {
                $lines[$key]['text'] = rtrim($text)."\n{$amount}ゴールド！";
            }

            return;
        }

        if (in_array($effectNode->node_type, ['apply_damage', 'apply_damage_chosen', 'apply_damage_party'], true)) {
            $amount = (int) ($effectNode->fixed_damage ?? 0);
            if ($amount <= 0) {
                return;
            }
            if (str_contains($text, '{damage_amount}')) {
                $lines[$key]['text'] = str_replace('{damage_amount}', (string) $amount, $text);

                return;
            }
            if ($amount > 500) {
                return;
            }
            if (! $this->textContainsAmount($text, $amount)) {
                $lines[$key]['text'] = rtrim($text)."\n{$amount}ダメージ！";
            }
        }
    }

    private function textContainsAmount(string $text, int $amount): bool
    {
        return str_contains($text, (string) $amount)
            || str_contains($text, '{gold_amount}')
            || str_contains($text, '{damage_amount}');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function applyUpcomingEffectAmounts(string $eventCode, DungeonEventNode $node, array &$context, int $floor): void
    {
        unset($context['pending_gold_amount'], $context['pending_damage_amount']);

        $nextKey = (string) ($node->next_default ?? '');

        // 効果ノードまでセリフ・地の文だけが続く場合も、その量を {damage_amount} 等に展開できるよう先読みする
        for ($depth = 0; $depth < self::EFFECT_AMOUNT_LOOKAHEAD && $nextKey !== ''; $depth++) {
            try {
                $next = $this->catalog->findNode($eventCode, $nextKey, $floor);
            } catch (\InvalidArgumentException) {
                return;
            }

            if ($next->node_type === 'grant_gold') {
                $context['pending_gold_amount'] = (int) ($next->stat_multiplier ?? 0);

                return;
            }

            if (in_array($next->node_type, ['apply_damage', 'apply_damage_chosen', 'apply_damage_party'], true)) {
                $context['pending_damage_amount'] = (int) ($next->fixed_damage ?? 0);

                return;
            }

            if (! in_array($next->node_type, ['narration', 'dialogue'], true)) {
                return;
            }

            $nextKey = (string) ($next->next_default ?? '');
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordEffectAmountInContext(DungeonEventNode $node, array &$context): void
    {
        if ($node->node_type === 'grant_gold') {
            $context['last_gold_amount'] = (int) ($node->stat_multiplier ?? 0);
            unset($context['pending_gold_amount']);

            return;
        }

        if (in_array($node->node_type, ['apply_damage', 'apply_damage_chosen', 'apply_damage_party'], true)) {
            $context['last_damage_amount'] = (int) ($node->fixed_damage ?? 0);
            unset($context['pending_damage_amount']);
        }
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
        $this->recordEffectAmountInContext($node, $context);
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
            'grant_item' => $this->executeGrantItem($user, $node),
            'grant_gold' => $this->executeGrantGold($user, $node),
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
            $minHp = max(0, (int) ($node->stat_multiplier ?? 0));
            $this->damage->applyDamageToSlot($user, $targetSlot, (int) $node->fixed_damage, $minHp);
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
            $minHp = max(0, (int) ($node->stat_multiplier ?? 0));
            $this->damage->applyDamageToSlot($user, $chosenSlot, (int) $node->fixed_damage, $minHp);
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
     * パーティー全員が必要レベル（stat_multiplier）以上なら next_on_success、一人でも未満なら next_on_fail。
     * HP に関係なく所持している全メンバーを対象にする。
     */
    private function executeCheckPartyLevel(User $user, DungeonEventNode $node): string
    {
        $requiredLevel = (int) ($node->stat_multiplier ?? 0);
        if ($requiredLevel <= 0) {
            throw new \InvalidArgumentException('check_party_level ノードに stat_multiplier（必要レベル）が必要です。');
        }

        $party = $this->characters->partyInSlotOrder($user);
        $allReached = $party->isNotEmpty()
            && $party->every(fn (UserCharacter $character) => (int) $character->level >= $requiredLevel);

        return $allReached
            ? (string) ($node->next_on_success ?: $node->next_default)
            : (string) ($node->next_on_fail ?: $node->next_default);
    }

    /**
     * stat_multiplier の層を踏破済み（その層のボスを倒したことがある）なら next_on_success、まだなら next_on_fail。
     * 「謎の気配」の空間の裂け目に入れる条件（3 層のボスを倒したあと）に使う。
     */
    private function executeCheckFloorCleared(User $user, DungeonEventNode $node): string
    {
        $requiredFloor = (int) ($node->stat_multiplier ?? 0);
        if ($requiredFloor <= 0) {
            throw new \InvalidArgumentException('check_floor_cleared ノードに stat_multiplier（踏破が必要な層）が必要です。');
        }

        return $this->progress->getClearedFloor($user) >= $requiredFloor
            ? (string) ($node->next_on_success ?: $node->next_default)
            : (string) ($node->next_on_fail ?: $node->next_default);
    }

    private function executeGrantItem(User $user, DungeonEventNode $node): string
    {
        $itemCode = trim((string) ($node->stat_attr ?? ''));
        if ($itemCode === '') {
            throw new \InvalidArgumentException('grant_item ノードに stat_attr（アイテムコード）が必要です。');
        }

        $amount = (int) ($node->stat_multiplier ?? 1);
        if ($amount <= 0) {
            $amount = 1;
        }

        $this->items->add($user, $itemCode, $amount);

        return (string) $node->next_default;
    }

    private function executeGrantGold(User $user, DungeonEventNode $node): string
    {
        $amount = (int) ($node->stat_multiplier ?? 0);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('grant_gold ノードに stat_multiplier（付与量）が必要です。');
        }

        $this->gold->addGold($user, $amount);

        return (string) $node->next_default;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildStatCheckEachAllyLines(User $user, DungeonEventNode $node, array &$context, int $floor): array
    {
        $attemptTemplate = trim((string) ($node->text ?? ''));
        $successTemplate = trim((string) ($node->dialogue_pc1 ?? ''));
        $failTemplate = trim((string) ($node->dialogue_pc2 ?? ''));
        if ($attemptTemplate === '' || $successTemplate === '' || $failTemplate === '') {
            throw new \InvalidArgumentException(
                'stat_check_each_ally ノードに text（挑戦）・dialogue_pc1（成功）・dialogue_pc2（失敗）が必要です。',
            );
        }

        $attr = (string) ($node->stat_attr ?: 'spirit');
        $multiplier = $this->effectiveStatMultiplier($node, 3, $floor);
        $failDamage = (int) ($node->fixed_damage ?? 0);
        $label = $this->statCheckLabel($attr);
        $lines = [];

        foreach (['pc1', 'pc2', 'pc3', 'pc4'] as $slotId) {
            $party = $this->characters->partyInSlotOrder($user);
            /** @var UserCharacter|null $character */
            $character = $party->get($slotId);
            if ($character === null || $character->hp <= 0) {
                continue;
            }

            $rate = $this->successRateForSlot($user, $slotId, $attr, $multiplier);

            // 判定中の味方を {name} として差し込む。event 全体の context は汚さない
            $allyContext = array_merge($context, [
                'chosen_slot' => $slotId,
                'last_success_rate' => $rate,
                'last_stat_check_label' => $label,
                'pending_damage_amount' => $failDamage,
            ]);

            $lines[] = [
                'type' => 'narration',
                'text' => $this->interpolate($attemptTemplate, $user, $allyContext),
                'sfx' => null,
            ];

            // 成功・失敗が分かる行（結果の文）の 1 行目に、ダイスロールのログを付ける
            $dice = $this->rollD100($rate);
            if ($dice['success']) {
                $lines[] = [
                    'type' => 'narration',
                    'text' => $this->interpolate($successTemplate, $user, $allyContext),
                    'sfx' => null,
                    'dice' => $this->diceLog($dice),
                ];
            } else {
                $failLine = [
                    'type' => 'narration',
                    'text' => $this->interpolate($failTemplate, $user, $allyContext),
                    'sfx' => null,
                    'dice' => $this->diceLog($dice),
                ];
                if ($failDamage > 0) {
                    $this->damage->applyDamageToSlot($user, $slotId, $failDamage);
                    $failLine['sync_party'] = true;
                    $failLine['party'] = $this->partyPayload($user);
                }
                $lines[] = $failLine;
            }
        }

        return $lines;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function partyPayload(User $user): array
    {
        return $this->characters->partyInSlotOrder($user)
            ->map(fn (UserCharacter $character, string $slotId) => $this->characters->toPartyUnitPayload($character, $slotId))
            ->values()
            ->all();
    }

    /**
     * 成功率（%）で、100 面ダイスを 1 回振る。出目が成功率以下なら成功（1d100<=成功率）。
     * テスト用の固定（test_stat_success）があるときは、その結果と矛盾しない出目を作る。
     *
     * @return array{rate: int, roll: int, success: bool}
     */
    private function rollD100(int $rate): array
    {
        $forcedSuccess = config('game.dungeon.test_stat_success');
        if ($forcedSuccess === true) {
            return ['rate' => $rate, 'roll' => 1, 'success' => true];
        }
        if ($forcedSuccess === false) {
            return ['rate' => $rate, 'roll' => min(100, $rate + 1), 'success' => false];
        }

        $forcedRoll = config('game.dungeon.test_roll');
        $roll = is_numeric($forcedRoll) ? (int) $forcedRoll : random_int(1, 100);

        return ['rate' => $rate, 'roll' => $roll, 'success' => $roll <= $rate];
    }

    /**
     * 結果表示の 1 行目に出す「ダイスロールのログ」。例: 「1d100<=55 ＞ 70 ＞ 失敗」
     *
     * @param  array{rate: int, roll: int, success: bool}  $dice
     * @return array{text: string, result: string, rate: int, roll: int}
     */
    private function diceLog(array $dice): array
    {
        return [
            'text' => sprintf('1d100<=%d ＞ %d ＞ %s', $dice['rate'], $dice['roll'], $dice['success'] ? '成功' : '失敗'),
            'result' => $dice['success'] ? 'success' : 'fail',
            'rate' => $dice['rate'],
            'roll' => $dice['roll'],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function applyChoice(DungeonExplorationSession $session, User $user, string $choiceId): void
    {
        $node = $this->catalog->findNode($session->event_code, (string) $session->current_node_key, (int) $session->floor_at_start);
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
        $floor = (int) $session->floor_at_start;
        $multiplier = $this->effectiveStatMultiplier($node, 2, $floor);
        $slot = $this->slotForStatCheck($attr, $context);

        $rate = $this->successRateForSlot($user, $slot, $attr, $multiplier);
        $context['last_success_rate'] = $rate;
        $context['last_stat_check_label'] = $this->statCheckLabel($attr);

        if (! $performRoll) {
            return [
                'preview_line' => null,
                'next_node' => $node->node_key,
                'context' => $context,
            ];
        }

        $dice = $this->rollD100($rate);
        $next = $dice['success']
            ? ($node->next_on_success ?: $node->next_default)
            : ($node->next_on_fail ?: $node->next_default);

        return [
            'preview_line' => null,
            'next_node' => $next,
            'context' => $context,
            'success' => $dice['success'],
            'dice' => $dice,
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
        $rate = $this->clampSuccessRate((int) ($node->stat_multiplier ?? 50));

        if (! $performRoll) {
            return [
                'preview_line' => null,
                'next_node' => $node->node_key,
                'context' => $context,
            ];
        }

        $dice = $this->rollD100($rate);
        $next = $dice['success']
            ? ($node->next_on_success ?: $node->next_default)
            : ($node->next_on_fail ?: $node->next_default);

        return [
            'preview_line' => null,
            'next_node' => $next,
            'context' => $context,
            'success' => $dice['success'],
            'dice' => $dice,
        ];
    }

    /**
     * 成功・失敗が判明した（= 成功・失敗の効果音が鳴る）ときの結果に、ダイスロールのログを付ける。
     * ログは、最初に表示される文（1 行目）の `dice` に入れる。表示される文がなければ、ログだけの行を作る。
     *
     * @param  array<string, mixed>  $segment
     * @param  array{rate: int, roll: int, success: bool}|null  $dice
     * @return array<string, mixed>
     */
    private function segmentWithStatCheckResult(array $segment, ?array $dice): array
    {
        if ($dice === null) {
            return $segment;
        }

        $segment['stat_check_result'] = $dice['success'] ? 'success' : 'fail';

        $kind = (string) ($segment['kind'] ?? '');
        if ($kind !== 'story' && $kind !== 'choice') {
            // 戦闘・終了などは文を持たない（探索イベントの作りでは、判定の直後は必ず地の文かセリフ）
            return $segment;
        }

        $lines = array_values($segment['lines'] ?? []);
        $target = null;
        foreach ($lines as $i => $line) {
            // 画面の暗転だけの行（文が空）にはログを付けない。見えないまま流れてしまうため
            if (trim((string) ($line['text'] ?? '')) !== '' && empty($line['screen_fade_ms'])) {
                $target = $i;
                break;
            }
        }

        if ($target === null) {
            array_unshift($lines, ['type' => 'narration', 'text' => '', 'sfx' => null]);
            $target = 0;
        }
        $lines[$target]['dice'] = $this->diceLog($dice);
        $segment['lines'] = $lines;

        return $segment;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function findUpcomingStatCheckNode(string $eventCode, string $nodeKey, int $floor, int $maxDepth = 8): ?DungeonEventNode
    {
        if ($nodeKey === '' || $maxDepth <= 0) {
            return null;
        }

        try {
            $node = $this->catalog->findNode($eventCode, $nodeKey, $floor);
        } catch (\InvalidArgumentException) {
            return null;
        }

        if ($node->node_type === 'stat_check') {
            return $node;
        }

        $next = (string) ($node->next_default ?? '');
        if ($next !== '') {
            return $this->findUpcomingStatCheckNode($eventCode, $next, $floor, $maxDepth - 1);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function applyUpcomingStatCheckPreviewContext(
        string $eventCode,
        string $startNodeKey,
        User $user,
        array $context,
        int $floor,
    ): array {
        $statNode = $this->findUpcomingStatCheckNode($eventCode, $startNodeKey, $floor);
        if ($statNode === null) {
            return $context;
        }

        $attr = (string) $statNode->stat_attr;
        $multiplier = $this->effectiveStatMultiplier($statNode, 2, $floor);
        $slot = $this->slotForStatCheck($attr, $context);

        $context['last_success_rate'] = $this->successRateForSlot($user, $slot, $attr, $multiplier);
        $context['last_stat_check_label'] = $this->statCheckLabel($attr);

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function slotForStatCheck(string $attr, array $context): string
    {
        if (in_array($attr, ['str', 'know'], true)) {
            return (string) ($context['chosen_slot'] ?? $context['target_slot'] ?? '');
        }

        return (string) ($context['target_slot'] ?? '');
    }

    private function statCheckLabel(string $attr): string
    {
        return match ($attr) {
            'str' => '筋力判定',
            'spd' => '素早さ判定',
            'know' => '知力判定',
            'mag' => '魔力判定',
            'def' => '防御判定',
            'spirit' => '精神判定',
            'vit' => '体力判定',
            default => '能力判定',
        };
    }

    private function statValueForCharacter(UserCharacter $character, string $attr): int
    {
        return match ($attr) {
            'str' => (int) $character->str,
            'spd' => (int) $character->spd,
            'know' => (int) $character->know,
            'mag' => (int) $character->mag,
            'def' => (int) $character->def,
            'spirit' => (int) $character->spirit,
            'vit' => (int) $character->vit,
            default => 0,
        };
    }

    private function hpRatioForCharacter(UserCharacter $character): float
    {
        $master = $character->characterMaster;
        if ($master === null) {
            return 0.0;
        }

        $maxHp = (int) $this->growth->statsForLevel($master, $character->level)['hp'];
        if ($maxHp <= 0) {
            return 0.0;
        }

        return min(1.0, max(0.0, (int) $character->hp / $maxHp));
    }

    private function maxSuccessRate(): int
    {
        return (int) config('game.dungeon.max_stat_success_rate', 95);
    }

    private function clampSuccessRate(int $rate): int
    {
        return min($this->maxSuccessRate(), max(0, $rate));
    }

    private function scaledStatMultiplier(int $baseMultiplier, int $floor): int
    {
        $scale = DungeonFloorConfig::statMultiplierScale($floor);

        return max(1, (int) ceil($baseMultiplier * $scale / 100));
    }

    /**
     * 判定ノードがその層で使う倍率。層ごとの上書き（dungeon_event_floor_overrides.csv）があればその値そのもの、
     * なければノードの倍率に層補正（stat_multiplier_scale）をかけたもの。
     */
    private function effectiveStatMultiplier(DungeonEventNode $node, int $defaultMultiplier, int $floor): int|float
    {
        if ($node->statMultiplierOverridden) {
            return $node->stat_multiplier;
        }

        return $this->scaledStatMultiplier((int) ($node->stat_multiplier ?? $defaultMultiplier), $floor);
    }

    /**
     * @param  int|float  $multiplier  その層で使う倍率（effectiveStatMultiplier の結果）
     */
    private function successRateForSlot(User $user, string $slotId, string $attr, int|float $multiplier): int
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

        $character->loadMissing('characterMaster');
        $value = $this->statValueForCharacter($character, $attr);
        $hpRatio = $this->hpRatioForCharacter($character);

        // 小数の倍率（2.3 など）で浮動小数点の誤差が出ても、端数は切り上げる
        return $this->clampSuccessRate((int) ceil(round($value * $multiplier * $hpRatio, 6)));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function nodeToLine(User $user, DungeonEventNode $node, array $context): ?array
    {
        if ($node->node_type === 'narration') {
            $line = [
                'type' => 'narration',
                'text' => $this->interpolate((string) $node->text, $user, $context),
                'sfx' => $node->sfx ?: null,
            ];
            if ($node->sfx === 'screen_fade') {
                $line['screen_fade_ms'] = 1000;
            }

            return $line;
        }

        if ($node->node_type === 'dialogue') {
            $speakerRole = (string) $node->speaker_role;
            if (str_starts_with($speakerRole, 'npc:')) {
                $npcKey = substr($speakerRole, 4);
                $npc = self::NPC_SPEAKERS[$npcKey] ?? null;
                $text = trim((string) $node->text);
                if ($npc === null || $text === '') {
                    return null;
                }

                return [
                    'type' => 'dialogue',
                    'character' => $npc['sprite'],
                    'speaker_name' => $npc['name'],
                    'speaker_sprite' => $npc['sprite'],
                    'text' => $this->interpolate($text, $user, $context),
                    'sfx' => $node->sfx ?: null,
                ];
            }

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
        $helperSlot = (string) ($context['helper_slot'] ?? '');

        $targetName = $this->nameForSlot($user, $targetSlot);
        $chosenName = $this->nameForSlot($user, $chosenSlot);
        $helperName = $this->nameForSlot($user, $helperSlot);

        $replacements = [
            '{target_name}' => $targetName,
            '{chosen_name}' => $chosenName,
            '{helper_name}' => $helperName,
            '{name}' => $chosenSlot !== '' ? $chosenName : $targetName,
            '{success_rate}' => (string) ($context['last_success_rate'] ?? 0),
            '{stat_check_label}' => (string) ($context['last_stat_check_label'] ?? ''),
            '{gold_amount}' => $this->goldAmountForInterpolation($context),
            '{damage_amount}' => $this->damageAmountForInterpolation($context),
        ];

        foreach (['PC1' => 'pc1', 'PC2' => 'pc2', 'PC3' => 'pc3', 'PC4' => 'pc4'] as $placeholder => $slot) {
            $replacements['{'.$placeholder.'}'] = $this->nameForSlot($user, $slot);
            $replacements['{'.$placeholder.'のファミリーネーム}'] = $this->secondNameForSlot($user, $slot);
        }

        $text = str_replace(array_keys($replacements), array_values($replacements), $text);

        return str_replace('\\n', "\n", $text);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function goldAmountForInterpolation(array $context): string
    {
        $amount = $context['pending_gold_amount'] ?? $context['last_gold_amount'] ?? 0;

        return (string) max(0, (int) $amount);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function damageAmountForInterpolation(array $context): string
    {
        $amount = $context['pending_damage_amount'] ?? $context['last_damage_amount'] ?? 0;

        return (string) max(0, (int) $amount);
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

    private function secondNameForSlot(User $user, string $slotId): string
    {
        if ($slotId === '') {
            return '???';
        }

        $party = $this->characters->partyInSlotOrder($user);
        /** @var UserCharacter|null $character */
        $character = $party->get($slotId);

        return $character?->characterMaster->second_name ?? '???';
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

        if ($role === 'random_ally_except_target_store') {
            return $context['reactor_slot']
                ?? $this->damage->pickRandomAliveSlot($user, $context['target_slot'] ?? null);
        }

        if ($role === 'random_ally_except_target_and_reactor') {
            return $this->pickRandomExceptTargetAndReactor($user, $context);
        }

        if ($role === 'random_ally_except_target_and_reactor_as_helper') {
            return $context['helper_slot']
                ?? $this->pickRandomExceptTargetAndReactor($user, $context);
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

    /**
     * @param  array<string, mixed>  $context
     */
    private function pickRandomExceptTargetAndReactor(User $user, array $context): ?string
    {
        $except = array_values(array_filter([
            $context['target_slot'] ?? null,
            $context['reactor_slot'] ?? null,
        ], fn ($slot) => is_string($slot) && $slot !== ''));

        return $this->damage->pickRandomAliveSlot($user, $except);
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
    private function choiceSegment(
        User $user,
        DungeonEventNode $node,
        array $context,
        bool $includeSkip = false,
        string $eventCode = '',
        int $floor = 1,
    ): array {
        $options = [];
        $party = $this->characters->partyInSlotOrder($user);

        $statNode = null;
        if (in_array($node->node_type, ['party_choice', 'party_choice_skip'], true) && $eventCode !== '') {
            $startKey = (string) ($node->next_on_success ?: $node->next_default ?: '');
            $statNode = $this->findUpcomingStatCheckNode($eventCode, $startKey, $floor);
        }

        $statAttr = $statNode !== null ? (string) $statNode->stat_attr : '';
        $statMultiplier = $statNode !== null ? $this->effectiveStatMultiplier($statNode, 2, $floor) : 0;

        foreach ($party as $slotId => $character) {
            if ($character->hp <= 0) {
                continue;
            }

            if ($statNode !== null) {
                $rate = $this->successRateForSlot($user, $slotId, $statAttr, $statMultiplier);
                $label = $character->characterMaster->name.'（成功率'.$rate.'%）';
            } else {
                $label = $character->characterMaster->name;
            }

            $options[] = [
                'slot_id' => $slotId,
                'name' => $character->characterMaster->name,
                'str' => (int) $character->str,
                'success_rate' => $statNode !== null
                    ? $this->successRateForSlot($user, $slotId, $statAttr, $statMultiplier)
                    : null,
                'label' => $label,
            ];
        }

        if ($includeSkip) {
            $skipLabel = trim((string) ($node->dialogue_pc4 ?? ''));
            $options[] = [
                'slot_id' => 'skip',
                'name' => $skipLabel !== '' ? $skipLabel : '毒見しない',
                'str' => 0,
                'success_rate' => null,
                'label' => $skipLabel !== '' ? $skipLabel : '毒見しない',
            ];
        }

        $prompt = $this->interpolate((string) $node->text, $user, $context);
        if ($statNode !== null) {
            $prompt = $this->statCheckLabel($statAttr)."\n".$prompt;
        }

        $segment = [
            'kind' => 'choice',
            'prompt' => $prompt,
            'options' => $options,
        ];

        if ($statNode !== null) {
            $segment['stat_check_label'] = $this->statCheckLabel($statAttr);
        }

        return $segment;
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
