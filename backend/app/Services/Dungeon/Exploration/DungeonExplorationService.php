<?php

namespace App\Services\Dungeon\Exploration;

use App\Models\DungeonExplorationSession;
use App\Models\User;
use App\Services\Dungeon\DungeonEventCatalog;
use App\Services\Player\UserCharacterService;
use Illuminate\Support\Str;

class DungeonExplorationService
{
    public function __construct(
        private readonly DungeonEventCatalog $catalog = new DungeonEventCatalog,
        private readonly DungeonExplorationEngine $engine = new DungeonExplorationEngine,
        private readonly UserCharacterService $characters = new UserCharacterService,
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
    public function startEvent(User $user, int $step, ?string $eventCode = null): array
    {
        $this->clearSessions($user);

        $code = $eventCode ?? $this->catalog->pickRandomEventCode();
        $event = $this->catalog->findEvent($code);
        $context = $this->engine->buildInitialContext($user, $code);

        $session = DungeonExplorationSession::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'event_code' => $code,
            'current_node_key' => $event->start_node_key,
            'context' => $context,
            'step_at_start' => $step,
        ]);

        return $this->buildResponse($user, $session, $step);
    }

    /**
     * @return array<string, mixed>
     */
    public function continue(User $user, string $sessionId): array
    {
        $session = $this->findSession($user, $sessionId);
        $step = (int) $session->step_at_start;

        $result = $this->engine->continueSession($session, $user);

        if ($result['complete']) {
            $this->clearSessions($user);

            return [
                'event' => 'exploration',
                'step' => $step,
                'session_id' => $sessionId,
                'segment' => $result['segment'],
                'party' => $this->partyPayload($user),
            ];
        }

        $session->refresh();

        return [
            'event' => 'exploration',
            'step' => $step,
            'session_id' => $sessionId,
            'segment' => $result['segment'],
            'party' => $this->partyPayload($user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function choose(User $user, string $sessionId, string $slotId): array
    {
        $session = $this->findSession($user, $sessionId);
        $step = (int) $session->step_at_start;

        $this->engine->applyChoice($session, $user, $slotId);
        $session->refresh();

        return $this->buildResponse($user, $session, $step);
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
    private function buildResponse(User $user, DungeonExplorationSession $session, int $step): array
    {
        $run = $this->engine->runUntilSegment($session, $user);

        if ($run['complete']) {
            $this->clearSessions($user);

            return [
                'event' => 'exploration',
                'step' => $step,
                'session_id' => $session->id,
                'segment' => $run['segment'],
                'party' => $this->partyPayload($user),
            ];
        }

        $session->current_node_key = $run['next_node'];
        $session->save();

        return [
            'event' => 'exploration',
            'step' => $step,
            'session_id' => $session->id,
            'segment' => $run['segment'],
            'party' => $this->partyPayload($user),
        ];
    }

    private function clearSessions(User $user): void
    {
        DungeonExplorationSession::query()->where('user_id', $user->id)->delete();
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
