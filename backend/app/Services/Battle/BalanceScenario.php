<?php

namespace App\Services\Battle;

/**
 * バランス検証用の戦闘シナリオ定義。
 */
final class BalanceScenario
{
    /**
     * @param  array<string, array{master_code: string, level: int, weapon?: string|null, armor?: string|null}>  $party
     * @param  list<array<string, string|int>>  $enemies
     */
    public function __construct(
        public readonly string $label,
        public readonly array $party,
        public readonly array $enemies,
        public readonly ?int $minPlayerTurns = null,
        public readonly ?int $maxPlayerTurns = null,
    ) {}

    /**
     * @return list<self>
     */
    public static function defaults(): array
    {
        $advancedParty = static fn (int $level): array => [
            'pc1' => ['master_code' => 'pc1', 'level' => $level, 'weapon' => 'staff_advanced', 'armor' => 'robe_advanced'],
            'pc2' => ['master_code' => 'pc2', 'level' => $level, 'weapon' => 'staff_advanced', 'armor' => 'robe_advanced'],
            'pc3' => ['master_code' => 'pc3', 'level' => $level, 'weapon' => 'staff_advanced', 'armor' => 'robe_advanced'],
            'pc4' => ['master_code' => 'pc4', 'level' => $level, 'weapon' => 'staff_advanced', 'armor' => 'robe_advanced'],
        ];

        $masterParty = static fn (int $level): array => [
            'pc1' => ['master_code' => 'pc1', 'level' => $level, 'weapon' => 'staff_master', 'armor' => 'robe_master'],
            'pc2' => ['master_code' => 'pc2', 'level' => $level, 'weapon' => 'staff_master', 'armor' => 'robe_master'],
            'pc3' => ['master_code' => 'pc3', 'level' => $level, 'weapon' => 'staff_master', 'armor' => 'robe_master'],
            'pc4' => ['master_code' => 'pc4', 'level' => $level, 'weapon' => 'staff_master', 'armor' => 'robe_master'],
        ];

        return [
            new self(
                label: '1F bat Lv3 basic',
                party: [
                    'pc1' => ['master_code' => 'pc1', 'level' => 3, 'weapon' => 'staff_basic', 'armor' => 'robe_basic'],
                    'pc2' => ['master_code' => 'pc2', 'level' => 3, 'weapon' => 'staff_basic', 'armor' => 'robe_basic'],
                    'pc3' => ['master_code' => 'pc3', 'level' => 3, 'weapon' => 'staff_basic', 'armor' => 'robe_basic'],
                    'pc4' => ['master_code' => 'pc4', 'level' => 3, 'weapon' => 'staff_basic', 'armor' => 'robe_basic'],
                ],
                enemies: [
                    ['slot' => 'enemy_1', 'master_code' => 'bat', 'floor' => 1, 'name' => 'コーモリ'],
                ],
                minPlayerTurns: 2,
                maxPlayerTurns: 4,
            ),
            new self(
                label: '1F boss Lv8 advanced',
                party: $advancedParty(8),
                enemies: [
                    ['slot' => 'enemy_1', 'master_code' => 'inu_moe', 'floor' => 1, 'name' => 'イフリーヌ'],
                ],
                minPlayerTurns: 8,
                maxPlayerTurns: 12,
            ),
            new self(
                label: '2F bat Lv9 advanced',
                party: $advancedParty(9),
                enemies: [
                    ['slot' => 'enemy_1', 'master_code' => 'bat', 'floor' => 2, 'name' => 'コーモリ'],
                ],
                minPlayerTurns: 3,
                maxPlayerTurns: 5,
            ),
            new self(
                label: '2F mid boss Lv10 advanced',
                party: $advancedParty(10),
                enemies: [
                    ['slot' => 'enemy_1', 'master_code' => 'npc_kasu', 'floor' => 2, 'name' => 'カース'],
                    ['slot' => 'enemy_2', 'master_code' => 'npc_gusu', 'floor' => 2, 'name' => 'グース'],
                ],
                minPlayerTurns: 10,
                maxPlayerTurns: 15,
            ),
            new self(
                label: '2F boss Lv14 master',
                party: $masterParty(14),
                enemies: [
                    ['slot' => 'enemy_1', 'master_code' => 'inu_moe', 'floor' => 2, 'name' => 'イフリーヌ'],
                ],
                minPlayerTurns: 12,
                maxPlayerTurns: 18,
            ),
        ];
    }
}
