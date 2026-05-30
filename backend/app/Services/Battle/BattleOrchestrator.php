<?php

namespace App\Services\Battle;

use App\Models\User;
use App\Services\Dungeon\DungeonProgressService;
use App\Services\Player\UserCharacterService;

/**
 * 戦闘の1手〜ターン進行〜敵フェーズまでをまとめるオーケストレータ。
 * HTTP API・ユニットテスト・バランスシミュレーションで同じ経路を使う。
 */
class BattleOrchestrator
{
    public function __construct(
        private readonly BattleFactory $factory = new BattleFactory,
        private readonly BattleEngine $engine = new BattleEngine,
        private readonly BattleRewardService $rewards = new BattleRewardService,
        private readonly DungeonProgressService $dungeonProgress = new DungeonProgressService,
        private readonly UserCharacterService $characters = new UserCharacterService,
    ) {}

    /**
     * @return array{state: array<string, mixed>, events: list<array<string, mixed>>}
     */
    public function createBattle(?User $user = null, string $enemyConfigKey = 'demo_enemies'): array
    {
        $state = $user !== null
            ? $this->factory->createForUser($user, $enemyConfigKey)
            : $this->factory->createDemo($enemyConfigKey);

        return $this->engine->runEnemyTurnsUntilPlayer($state);
    }

    /**
     * @param  list<array<string, string>>  $enemyList
     * @param  array<string, mixed>  $meta
     * @return array{state: array<string, mixed>, events: list<array<string, mixed>>}
     */
    public function createBattleForUser(User $user, array $enemyList, array $meta = []): array
    {
        $state = $this->factory->createForUserWithEnemies($user, $enemyList, $meta);

        return $this->engine->runEnemyTurnsUntilPlayer($state);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{state: array<string, mixed>, events: list<array<string, mixed>>}
     */
    public function submitPlayerAction(
        array $state,
        string $action,
        ?string $spellId = null,
        ?string $targetId = null,
        ?User $user = null,
    ): array {
        $actor = $state['current_actor'] ?? null;
        if ($actor === null) {
            throw new \InvalidArgumentException('行動可能なユニットがいません。');
        }

        $playerResult = $this->engine->applyPlayerAction($state, $actor, $action, $spellId, $targetId);
        $enemyResult = $this->engine->runEnemyTurnsUntilPlayer($playerResult['state']);

        $events = array_merge($playerResult['events'], $enemyResult['events']);
        $state = $enemyResult['state'];

        if ($user !== null) {
            $events = array_merge($events, $this->applyEndOfBattlePersistence($state, $user));
        }

        return [
            'state' => $state,
            'events' => $events,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private function applyEndOfBattlePersistence(array $state, User $user): array
    {
        $meta = $state['meta'] ?? [];
        $isDungeon = ($meta['source'] ?? '') === 'dungeon';

        if (($state['status'] ?? '') === 'victory') {
            $events = $this->rewards->applyVictoryRewards($state, $user);

            return $events;
        }

        if (($state['status'] ?? '') === 'defeat') {
            if ($isDungeon) {
                $this->characters->restorePartyToFull($user);
                $this->dungeonProgress->reset($user);
            } else {
                $this->rewards->syncPartyHpMp($state, $user);
            }
        }

        return [];
    }

    public function engine(): BattleEngine
    {
        return $this->engine;
    }

    public function factory(): BattleFactory
    {
        return $this->factory;
    }
}
