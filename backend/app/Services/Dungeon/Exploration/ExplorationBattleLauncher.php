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
            'name' => $master->name,
        ]];

        $meta = [
            'source' => 'dungeon',
            'floor' => $floor,
            'step' => $step,
            'boss' => true,
            'can_flee' => false,
        ];

        $battleId = $this->factory->newBattleId();
        $result = $this->orchestrator->createBattleForUser($user, $enemies, $meta);

        BattleState::query()->create([
            'id' => $battleId,
            'state' => $result['state'],
            'status' => $result['state']['status'],
        ]);

        return [
            'event' => 'battle',
            'battle_id' => $battleId,
            'boss_encounter' => true,
            'state' => $this->orchestrator->engine()->publicState($result['state']),
            'events' => array_merge(
                [['type' => 'battle_started', 'battle_id' => $battleId]],
                $result['events'],
            ),
            'floor' => $floor,
            'step' => $step,
        ];
    }
}
