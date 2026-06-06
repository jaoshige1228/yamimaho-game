<?php

namespace App\Services\Dungeon;

use App\Models\EnemyMaster;

class DungeonStepEncounterBuilder
{
    /**
     * @return list<array{slot: string, master_code: string, name: string, sprite: string}>
     */
    public function build(int $floor, int $step): array
    {
        if (DungeonFloorConfig::isBossStep($floor, $step)) {
            return $this->buildBoss($floor);
        }

        $band = $this->resolveBand($floor, $step);
        $count = random_int(
            (int) ($band['count_min'] ?? 2),
            (int) ($band['count_max'] ?? 3),
        );

        /** @var list<string> $codes */
        $codes = $band['codes'];
        $enemies = [];

        for ($i = 1; $i <= $count; $i++) {
            $code = $codes[array_rand($codes)];
            $master = $this->findEnemyOnFloor($floor, $code);
            $enemies[] = [
                'slot' => 'enemy_'.$i,
                'master_code' => $master->code,
                'name' => $this->displayName($master, $i, $count),
                'sprite' => $master->sprite,
            ];
        }

        return $enemies;
    }

    /**
     * @return list<array{slot: string, master_code: string, name: string, sprite: string}>
     */
    private function buildBoss(int $floor): array
    {
        $code = (string) (DungeonFloorConfig::bossEnemyCode($floor) ?? 'inu_moe');
        $master = $this->findEnemyOnFloor($floor, $code);

        return [[
            'slot' => 'enemy_1',
            'master_code' => $master->code,
            'name' => $master->name,
            'sprite' => $master->sprite,
        ]];
    }

    /**
     * @return array{codes: list<string>, count_min: int, count_max: int}
     */
    private function resolveBand(int $floor, int $step): array
    {
        $bands = DungeonFloorConfig::encounterBands($floor);

        foreach ($bands as $band) {
            $from = (int) ($band['from'] ?? 0);
            $to = (int) ($band['to'] ?? 0);
            if ($step >= $from && $step <= $to) {
                return [
                    'codes' => array_values($band['codes'] ?? []),
                    'count_min' => (int) ($band['count_min'] ?? 2),
                    'count_max' => (int) ($band['count_max'] ?? 3),
                ];
            }
        }

        throw new \RuntimeException("No encounter band for floor {$floor} step {$step}.");
    }

    private function findEnemyOnFloor(int $floor, string $code): EnemyMaster
    {
        $master = EnemyMaster::query()
            ->where('floor', $floor)
            ->where('code', $code)
            ->first();

        if ($master === null) {
            throw new \RuntimeException("Enemy {$code} is not configured for floor {$floor}.");
        }

        return $master;
    }

    private function displayName(EnemyMaster $master, int $index, int $total): string
    {
        if ($total <= 1) {
            return $master->name;
        }

        return $master->name.' '.chr(64 + $index);
    }
}
