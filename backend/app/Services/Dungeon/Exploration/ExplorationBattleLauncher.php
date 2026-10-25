<?php

namespace App\Services\Dungeon\Exploration;

use App\Models\BattleState;
use App\Models\EnemyMaster;
use App\Models\User;
use App\Services\Battle\BattleFactory;
use App\Services\Battle\BattleOrchestrator;

class ExplorationBattleLauncher
{
    public function __construct(
        private readonly BattleFactory $factory = new BattleFactory,
        private readonly BattleOrchestrator $orchestrator = new BattleOrchestrator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function startBossBattle(User $user, int $floor, int $step, string $enemyCode = 'inu_moe'): array
    {
        $master = EnemyMaster::query()
            ->where('floor', $floor)
            ->where('code', $enemyCode)
            ->firstOrFail();

        $enemies = [[
            'slot' => 'enemy_1',
            'master_code' => $master->code,
            'floor' => $floor,
            'name' => $master->name,
            'sprite' => $master->sprite,
        ]];

        $meta = [
            'source' => 'dungeon',
            'floor' => $floor,
            'step' => $step,
            'boss' => true,
            'can_flee' => false,
        ];

        return $this->launchBattle($user, $enemies, $meta, [
            'boss_encounter' => true,
        ]);
    }

    /**
     * @param  list<string>  $enemyCodes
     * @return array<string, mixed>
     */
    public function startMidBossBattle(
        User $user,
        int $floor,
        int $step,
        array $enemyCodes,
        string $postBattleNode,
        ?string $sessionId,
    ): array {
        $enemies = [];
        $slot = 1;
        foreach ($enemyCodes as $code) {
            $master = EnemyMaster::query()
                ->where('floor', $floor)
                ->where('code', $code)
                ->firstOrFail();

            $enemies[] = [
                'slot' => 'enemy_'.$slot,
                'master_code' => $master->code,
                'floor' => $floor,
                'name' => $master->name,
                'sprite' => $master->sprite,
            ];
            $slot++;
        }

        $meta = [
            'source' => 'dungeon',
            'floor' => $floor,
            'step' => $step,
            'boss' => false,
            'mid_boss' => true,
            'can_flee' => false,
            'bgm' => 'broken',
            'resume_session_id' => $sessionId,
            'post_battle_node' => $postBattleNode,
        ];

        return $this->launchBattle($user, $enemies, $meta, [
            'mid_boss_encounter' => true,
            'exploration_session_id' => $sessionId,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $enemies
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function launchBattle(User $user, array $enemies, array $meta, array $extra = []): array
    {
        $battleId = $this->factory->newBattleId();
        $result = $this->orchestrator->createBattleForUser($user, $enemies, $meta);

        BattleState::query()->create([
            'id' => $battleId,
            'user_id' => $user->id,
            'state' => $result['state'],
            'status' => $result['state']['status'],
        ]);

        return array_merge([
            'event' => 'battle',
            'battle_id' => $battleId,
            'state' => $this->orchestrator->engine()->publicState($result['state']),
            'events' => array_merge(
                [['type' => 'battle_started', 'battle_id' => $battleId]],
                $result['events'],
            ),
            'floor' => (int) ($meta['floor'] ?? 1),
            'step' => (int) ($meta['step'] ?? 0),
        ], $extra);
    }
}
