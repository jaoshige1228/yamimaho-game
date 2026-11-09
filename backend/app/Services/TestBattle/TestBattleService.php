<?php

namespace App\Services\TestBattle;

use App\Models\ArmorMaster;
use App\Models\EnemyMaster;
use App\Models\User;
use App\Models\WeaponMaster;
use App\Services\Dungeon\DungeonFloorConfig;
use App\Services\Dungeon\DungeonStepEncounterBuilder;
use App\Services\Player\UserCharacterService;

class TestBattleService
{
    /** @var list<string> */
    private const BOSS_ONLY_CODES = ['inu_moe', 'npc_kasu', 'npc_gusu'];

    public function __construct(
        private readonly UserCharacterService $characters = new UserCharacterService,
    ) {}

    /**
     * @return array{
     *   enemies: list<array<string, mixed>>,
     *   boss_presets: list<array<string, string>>,
     *   weapons: list<array<string, mixed>>,
     *   armors: list<array<string, mixed>>,
     *   party: list<array<string, mixed>>
     * }
     */
    public function catalog(User $user): array
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
                    'description' => 'エルドラゴン＆邪教徒×2',
                ],
                [
                    'id' => 'floor2_mid_boss',
                    'label' => '2層中間ボス戦',
                    'description' => 'グース＆カース',
                ],
                [
                    'id' => 'floor3_boss',
                    'label' => '3層ボス戦',
                    'description' => 'ゼロ＆ナビ×2',
                ],
            ],
            'weapons' => WeaponMaster::query()
                ->orderBy('mag_bonus')
                ->orderBy('code')
                ->get()
                ->map(fn (WeaponMaster $weapon) => [
                    'code' => $weapon->code,
                    'name' => $weapon->name,
                    'mag_bonus' => (int) $weapon->mag_bonus,
                ])
                ->values()
                ->all(),
            'armors' => ArmorMaster::query()
                ->orderBy('def_bonus')
                ->orderBy('code')
                ->get()
                ->map(fn (ArmorMaster $armor) => [
                    'code' => $armor->code,
                    'name' => $armor->name,
                    'def_bonus' => (int) $armor->def_bonus,
                ])
                ->values()
                ->all(),
            'party' => $this->partyEquipment($user),
        ];
    }

    /**
     * 自由装備モードの初期値にする、いまの味方の装備。
     *
     * @return list<array<string, mixed>>
     */
    private function partyEquipment(User $user): array
    {
        $this->characters->ensureParty($user);

        return $this->characters->partyInSlotOrder($user)
            ->map(fn ($character, string $slotId) => [
                'slot_id' => $slotId,
                'name' => $character->characterMaster->name,
                'level' => (int) $character->level,
                'weapon_code' => $character->weapon?->code,
                'armor_code' => $character->armor?->code,
            ])
            ->values()
            ->all();
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
                'floor' => $floor,
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
            'floor3_boss' => $this->buildFloorBoss(3, bossEncounter: true),
            'floor2_mid_boss' => $this->buildFloor2MidBoss(),
            default => throw new \InvalidArgumentException('不明なボス戦です。'),
        };
    }

    /**
     * @return array{enemies: list<array<string, mixed>>, meta: array<string, mixed>, encounter: array<string, mixed>}
     */
    private function buildFloorBoss(int $floor, bool $bossEncounter): array
    {
        // 実際のダンジョンのボス戦と同じ構成（DungeonStepEncounterBuilder）を使う
        $enemies = (new DungeonStepEncounterBuilder)->build($floor, (int) DungeonFloorConfig::bossStep($floor));

        return [
            'enemies' => $enemies,
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
