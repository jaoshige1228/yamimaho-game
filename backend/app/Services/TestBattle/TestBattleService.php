<?php

namespace App\Services\TestBattle;

use App\Models\EnemyMaster;
use App\Services\Dungeon\DungeonFloorConfig;

class TestBattleService
{
    /** @var list<string> */
    private const BOSS_ONLY_CODES = ['inu_moe', 'npc_kasu', 'npc_gusu'];

    /**
     * @return array{enemies: list<array<string, mixed>>, boss_presets: list<array<string, string>>}
     */
    public function catalog(): array
    {
        $enemies = EnemyMaster::query()
            ->whereNotIn('code', self::BOSS_ONLY_CODES)
            ->orderBy('floor')
            ->orderBy('code')
            ->get()
            ->map(fn (EnemyMaster $master) => [
                'floor' => (int) $master->floor,
                'code' => $master->code,
                'name' => $master->name,
                'sprite' => $master->sprite,
                'level' => (int) $master->level,
            ])
            ->values()
            ->all();

        return [
            'enemies' => $enemies,
            'boss_presets' => [
                [
                    'id' => 'floor1_boss',
                    'label' => '1層ボス戦',
                    'description' => 'イフリーヌ',
                ],
                [
                    'id' => 'floor2_boss',
                    'label' => '2層ボス戦',
                    'description' => 'イフリーヌ',
                ],
                [
                    'id' => 'floor2_mid_boss',
                    'label' => '2層中間ボス戦',
                    'description' => 'グース＆カース',
                ],
            ],
        ];
    }

    /**
     * @return array{enemies: list<array<string, mixed>>, meta: array<string, mixed>, encounter: array<string, mixed>}
     */
    public function buildRegularEncounter(int $floor, string $code, int $count): array
    {
        if ($count < 1 || $count > 3) {
            throw new \InvalidArgumentException('出現数は 1〜3 です。');
        }

        $master = $this->findEnemy($floor, $code);
        if (in_array($code, self::BOSS_ONLY_CODES, true)) {
            throw new \InvalidArgumentException('この敵はボス戦プリセットから選んでください。');
        }

        $enemies = [];
        for ($i = 1; $i <= $count; $i++) {
            $enemies[] = [
                'slot' => 'enemy_'.$i,
                'master_code' => $master->code,
                'floor' => $floor,
                'name' => $this->displayName($master, $i, $count),
                'sprite' => $master->sprite,
            ];
        }

        return [
            'enemies' => $enemies,
            'meta' => [
                'source' => 'test',
                'can_flee' => false,
            ],
            'encounter' => [],
        ];
    }

    /**
     * @return array{enemies: list<array<string, mixed>>, meta: array<string, mixed>, encounter: array<string, mixed>}
     */
    public function buildBossEncounter(string $presetId): array
    {
        return match ($presetId) {
            'floor1_boss' => $this->buildFloorBoss(1, bossEncounter: true),
            'floor2_boss' => $this->buildFloorBoss(2, bossEncounter: true),
            'floor2_mid_boss' => $this->buildFloor2MidBoss(),
            default => throw new \InvalidArgumentException('不明なボス戦です。'),
        };
    }

    /**
     * @return array{enemies: list<array<string, mixed>>, meta: array<string, mixed>, encounter: array<string, mixed>}
     */
    private function buildFloorBoss(int $floor, bool $bossEncounter): array
    {
        $code = DungeonFloorConfig::bossEnemyCode($floor) ?? 'inu_moe';
        $master = $this->findEnemy($floor, $code);

        return [
            'enemies' => [[
                'slot' => 'enemy_1',
                'master_code' => $master->code,
                'floor' => $floor,
                'name' => $master->name,
                'sprite' => $master->sprite,
            ]],
            'meta' => [
                'source' => 'test',
                'floor' => $floor,
                'boss' => true,
                'can_flee' => false,
            ],
            'encounter' => $bossEncounter ? ['boss_encounter' => true] : [],
        ];
    }

    /**
     * @return array{enemies: list<array<string, mixed>>, meta: array<string, mixed>, encounter: array<string, mixed>}
     */
    private function buildFloor2MidBoss(): array
    {
        $floor = 2;
        $codes = DungeonFloorConfig::midBossEnemyCodes($floor);
        if ($codes === []) {
            $codes = ['npc_kasu', 'npc_gusu'];
        }

        $enemies = [];
        $slot = 1;
        foreach ($codes as $code) {
            $master = $this->findEnemy($floor, $code);
            $enemies[] = [
                'slot' => 'enemy_'.$slot,
                'master_code' => $master->code,
                'floor' => $floor,
                'name' => $master->name,
                'sprite' => $master->sprite,
            ];
            $slot++;
        }

        return [
            'enemies' => $enemies,
            'meta' => [
                'source' => 'test',
                'floor' => $floor,
                'boss' => false,
                'mid_boss' => true,
                'bgm' => 'broken',
                'can_flee' => false,
            ],
            'encounter' => ['mid_boss_encounter' => true],
        ];
    }

    private function findEnemy(int $floor, string $code): EnemyMaster
    {
        $master = EnemyMaster::query()
            ->where('floor', $floor)
            ->where('code', $code)
            ->first();

        if ($master === null) {
            throw new \InvalidArgumentException("敵 {$code}（{$floor}層）が見つかりません。");
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
