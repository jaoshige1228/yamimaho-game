<?php

namespace App\Services\Dungeon\Exploration;

use App\Models\DungeonExplorationSession;
use App\Models\User;
use App\Services\Dungeon\DungeonEventCatalog;
use App\Services\Dungeon\DungeonProgressService;
use App\Services\Player\UserCharacterService;
use Illuminate\Support\Str;

class DungeonExplorationService
{
    public function __construct(
        private readonly DungeonEventCatalog $catalog = new DungeonEventCatalog,
        private readonly DungeonExplorationEngine $engine = new DungeonExplorationEngine,
        private readonly UserCharacterService $characters = new UserCharacterService,
        private readonly DungeonProgressService $progress = new DungeonProgressService,
        private readonly ExplorationBattleLauncher $battleLauncher = new ExplorationBattleLauncher,
    ) {}

    public function hasActiveSession(User $user): bool
    {
        return DungeonExplorationSession::query()
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function startEvent(
        User $user,
        int $floor,
        int $step,
        ?string $eventCode = null,
        bool $dialogueOnlyPool = false,
    ): array {
        $this->clearSessions($user);

        $code = $eventCode ?? ($dialogueOnlyPool
            ? $this->catalog->pickRandomDialogueEventCode($floor)
            : $this->catalog->pickRandomEventCode($floor));
        $event = $this->catalog->resolveEvent($floor, $code);
        $context = $this->engine->buildInitialContext($user, $code);
        $context['skip_epilogue'] = $event->skipEpilogue;

        $session = DungeonExplorationSession::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'event_code' => $code,
            'current_node_key' => $event->startNodeKey,
            'context' => $context,
            'step_at_start' => $step,
            'floor_at_start' => $floor,
        ]);

        return $this->buildResponse($user, $session, $floor, $step);
    }

    /**
     * @return array<string, mixed>
     */
    public function continue(User $user, string $sessionId): array
    {
        $session = $this->findSession($user, $sessionId);
        $floor = (int) $session->floor_at_start;
        $step = (int) $session->step_at_start;

        $result = $this->engine->continueSession($session, $user);

        if ($result['complete']) {
            $special = $this->resolveTerminalSegment($user, $floor, $step, $result['segment']);
            if ($special !== null) {
                return $this->withProgress($special, $user);
            }

            $this->clearSessions($user);

            return $this->withProgress(array_merge([
                'event' => 'exploration',
                'floor' => $floor,
                'step' => $step,
                'session_id' => $sessionId,
                'segment' => $result['segment'],
            ], $this->explorationPartyFields($user, $session)), $user);
        }

        $session->refresh();

        return $this->withProgress(array_merge([
            'event' => 'exploration',
            'floor' => $floor,
            'step' => $step,
            'session_id' => $sessionId,
            'segment' => $result['segment'],
        ], $this->explorationPartyFields($user, $session)), $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function choose(User $user, string $sessionId, string $slotId): array
    {
        $session = $this->findSession($user, $sessionId);
        $floor = (int) $session->floor_at_start;
        $step = (int) $session->step_at_start;

        $this->engine->applyChoice($session, $user, $slotId);
        $session->refresh();

        return $this->buildResponse($user, $session, $floor, $step);
    }

    private function findSession(User $user, string $sessionId): DungeonExplorationSession
    {
        $session = DungeonExplorationSession::query()
            ->where('id', $sessionId)
            ->where('user_id', $user->id)
            ->first();

        if ($session === null) {
            throw new \InvalidArgumentException('探索セッションが見つかりません。');
        }

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResponse(User $user, DungeonExplorationSession $session, int $floor, int $step): array
    {
        $run = $this->engine->runUntilSegment($session, $user);

        if ($run['complete']) {
            $special = $this->resolveTerminalSegment($user, $floor, $step, $run['segment']);
            if ($special !== null) {
                return $this->withProgress($special, $user);
            }

            $this->clearSessions($user);

            return $this->withProgress(array_merge([
                'event' => 'exploration',
                'floor' => $floor,
                'step' => $step,
                'session_id' => $session->id,
                'segment' => $run['segment'],
            ], $this->explorationPartyFields($user, $session)), $user);
        }

        $session->current_node_key = $run['next_node'];
        $session->save();

        return $this->withProgress(array_merge([
            'event' => 'exploration',
            'floor' => $floor,
            'step' => $step,
            'session_id' => $session->id,
            'segment' => $run['segment'],
        ], $this->explorationPartyFields($user, $session)), $user);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withProgress(array $payload, User $user): array
    {
        return array_merge($payload, $this->progress->statusPayload($user));
    }

    private function clearSessions(User $user): void
    {
        DungeonExplorationSession::query()->where('user_id', $user->id)->delete();
    }

    /**
     * @param  array<string, mixed>  $segment
     * @return array<string, mixed>|null
     */
    private function resolveTerminalSegment(User $user, int $floor, int $step, array $segment): ?array
    {
        $kind = $segment['kind'] ?? '';

        if ($kind === 'battle') {
            $this->clearSessions($user);
            $enemyCode = (string) ($segment['enemy_code'] ?? 'inu_moe');

            return array_merge(
                $this->battleLauncher->startBossBattle($user, $floor, $step, $enemyCode),
                ['party' => $this->partyPayload($user)],
            );
        }

        if ($kind === 'game_over') {
            $this->clearSessions($user);

            return [
                'event' => 'exploration',
                'floor' => $floor,
                'step' => 0,
                'segment' => $segment,
                'party' => $this->partyPayload($user),
            ];
        }

        return null;
    }

    /**
     * @return array{party: list<array<string, mixed>>, party_snapshot?: list<array<string, mixed>>}
     */
    private function explorationPartyFields(User $user, DungeonExplorationSession $session): array
    {
        $fields = ['party' => $this->partyPayload($user)];
        $context = $session->context ?? [];
        $snapshot = $context['presentation_party_snapshot'] ?? null;

        if (is_array($snapshot) && $snapshot !== []) {
            $fields['party_snapshot'] = $snapshot;
            unset($context['presentation_party_snapshot']);
            $session->context = $context;
            $session->save();
        }

        return $fields;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function partyPayload(User $user): array
    {
        return $this->characters->partyInSlotOrder($user)
            ->map(fn ($character, $slotId) => $this->characters->toPartyUnitPayload($character, $slotId))
            ->values()
            ->all();
    }
}
