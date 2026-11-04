<?php

namespace App\Services\Dungeon;

use App\Models\EnemyMaster;

class DungeonStepEncounterBuilder
{
    /**
     * @return list<array{slot: string, master_code: string, floor: int, name: string, sprite: string}>
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

        $enemies = [];

        for ($i = 1; $i <= $count; $i++) {
            $code = $this->pickCode($band);
            $master = $this->findEnemyOnFloor($floor, $code);
            $enemies[] = [
                'slot' => 'enemy_'.$i,
                'master_code' => $master->code,
                'floor' => $floor,
                'name' => $this->displayName($master, $i, $count),
                'sprite' => $master->sprite,
            ];
        }

        return $enemies;
    }

    /**
     * @return list<array{slot: string, master_code: string, floor: int, name: string, sprite: string}>
     */
    private function buildBoss(int $floor): array
    {
        $codes = DungeonFloorConfig::bossEnemyCodes($floor);
        if ($codes === []) {
            throw new \RuntimeException("Boss enemies are not configured for floor {$floor}.");
        }

        // 同じ敵が複数いるときだけ A・B…を付ける（エルドラゴン＋邪教徒 A・B のように）
        $totals = array_count_values($codes);
        $seen = [];
        $enemies = [];

        foreach ($codes as $i => $code) {
            $master = $this->findEnemyOnFloor($floor, $code);
            $seen[$code] = ($seen[$code] ?? 0) + 1;

            $enemies[] = [
                'slot' => 'enemy_'.($i + 1),
                'master_code' => $master->code,
                'floor' => $floor,
                'name' => $totals[$code] > 1 ? $master->name.' '.chr(64 + $seen[$code]) : $master->name,
                'sprite' => $master->sprite,
            ];
        }

        return $enemies;
    }

    /**
     * 帯の中から敵 1 体ぶんの種類を選ぶ。weights があれば重み付き、なければ等確率。
     *
     * @param  array{codes: list<string>, weights: array<string, int>}  $band
     */
    private function pickCode(array $band): string
    {
        $codes = $band['codes'];
        $weights = array_filter(
            array_map(fn (string $code): int => max(0, (int) ($band['weights'][$code] ?? 0)), array_combine($codes, $codes)),
        );
        if ($weights === []) {
            return $codes[array_rand($codes)];
        }

        $roll = random_int(1, array_sum($weights));
        foreach ($weights as $code => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return (string) $code;
            }
        }

        return (string) array_key_last($weights);
    }

    /**
     * @return array{codes: list<string>, weights: array<string, int>, count_min: int, count_max: int}
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
                    'weights' => is_array($band['weights'] ?? null) ? $band['weights'] : [],
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
