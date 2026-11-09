<?php

namespace Tests\Unit\Dungeon;

use App\Services\Dungeon\DungeonStepEncounterBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DungeonStepEncounterBuilderTest extends TestCase
{
    use RefreshDatabase;

    private DungeonStepEncounterBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
        $this->assertDatabaseHas('enemy_masters', ['code' => 'bat', 'floor' => 1]);
        $this->builder = new DungeonStepEncounterBuilder;
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: list<string>, 3: int, 4: int}>
     */
    public static function floor1Bands(): array
    {
        return [
            '1〜10 コーモリ1〜2体' => [1, 10, ['bat'], 1, 2],
            '11〜14 コーモリ2〜3体' => [11, 14, ['bat'], 2, 3],
            '15〜20 コーモリ/ヘビビ2〜3体' => [15, 20, ['bat', 'snake'], 2, 3],
            '21〜25 コーモリ/ヘビビ/ムシプリンス2〜3体' => [21, 25, ['bat', 'snake', 'beetle'], 2, 3],
            '26〜30 ヘビビ/ムシプリンス2〜3体' => [26, 30, ['snake', 'beetle'], 2, 3],
        ];
    }

    /**
     * @param  list<string>  $expectedCodes
     */
    #[DataProvider('floor1Bands')]
    public function test_floor1_band_spawns_expected_enemies(
        int $from,
        int $to,
        array $expectedCodes,
        int $countMin,
        int $countMax,
    ): void {
        $seenCodes = [];
        $seenCounts = [];

        for ($step = $from; $step <= $to; $step++) {
            for ($i = 0; $i < 15; $i++) {
                $enemies = $this->builder->build(1, $step);
                $seenCounts[] = count($enemies);

                $this->assertGreaterThanOrEqual($countMin, count($enemies), "step {$step}");
                $this->assertLessThanOrEqual($countMax, count($enemies), "step {$step}");
                foreach ($enemies as $enemy) {
                    $this->assertContains($enemy['master_code'], $expectedCodes, "step {$step}");
                    $seenCodes[] = $enemy['master_code'];
                }
            }
        }

        // 帯の中の全種類が出て、出現数も下限・上限の両方が出ること
        $this->assertEqualsCanonicalizing($expectedCodes, array_values(array_unique($seenCodes)));
        $this->assertContains($countMin, $seenCounts);
        $this->assertContains($countMax, $seenCounts);
    }

    public function test_boss_step_spawns_inu_moe(): void
    {
        $enemies = $this->builder->build(1, 31);
        $this->assertCount(1, $enemies);
        $this->assertSame('inu_moe', $enemies[0]['master_code']);
        $this->assertSame(1, $enemies[0]['floor']);
        $this->assertSame('イフリーヌ', $enemies[0]['name']);
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: list<string>, 3: int, 4: int}>
     */
    public static function floor2Bands(): array
    {
        return [
            '1〜10 ジャック1〜2体' => [1, 10, ['jack', 'mimic'], 1, 2],
            '11〜20 ジャック/刈り取る奴2〜3体' => [11, 20, ['jack', 'shinigami', 'mimic'], 2, 3],
            '21〜25 ジャック/刈り取る奴/ヘルナイト2〜3体' => [21, 25, ['jack', 'shinigami', 'knight', 'mimic'], 2, 3],
            '26〜30 刈り取る奴/ヘルナイト2〜3体' => [26, 30, ['shinigami', 'knight', 'mimic'], 2, 3],
        ];
    }

    /**
     * ミミックはレア（約 3%）なので、「全種類が出ること」は通常の敵だけで確かめる。
     *
     * @param  list<string>  $allowedCodes
     */
    #[DataProvider('floor2Bands')]
    public function test_floor2_band_spawns_expected_enemies(
        int $from,
        int $to,
        array $allowedCodes,
        int $countMin,
        int $countMax,
    ): void {
        $seenCodes = [];
        $seenCounts = [];

        for ($step = $from; $step <= $to; $step++) {
            for ($i = 0; $i < 15; $i++) {
                $enemies = $this->builder->build(2, $step);
                $seenCounts[] = count($enemies);

                $this->assertGreaterThanOrEqual($countMin, count($enemies), "step {$step}");
                $this->assertLessThanOrEqual($countMax, count($enemies), "step {$step}");
                foreach ($enemies as $enemy) {
                    $this->assertContains($enemy['master_code'], $allowedCodes, "step {$step}");
                    $this->assertSame(2, $enemy['floor']);
                    $seenCodes[] = $enemy['master_code'];
                }
            }
        }

        $regularCodes = array_values(array_diff($allowedCodes, ['mimic']));
        $this->assertEqualsCanonicalizing($regularCodes, array_values(array_unique(array_diff($seenCodes, ['mimic']))));
        $this->assertContains($countMin, $seenCounts);
        $this->assertContains($countMax, $seenCounts);
    }

    public function test_mimic_is_rare_on_floor_two(): void
    {
        $mimics = 0;
        $total = 0;

        foreach ([5, 15, 23, 28] as $step) {
            for ($i = 0; $i < 400; $i++) {
                foreach ($this->builder->build(2, $step) as $enemy) {
                    $total++;
                    $mimics += $enemy['master_code'] === 'mimic' ? 1 : 0;
                }
            }
        }

        // 設定上は約 3%。出るには出るが、ほとんどは通常の敵であること
        $rate = $mimics / $total;
        $this->assertGreaterThan(0.01, $rate);
        $this->assertLessThan(0.07, $rate);
    }

    public function test_floor2_boss_step_spawns_dragon_with_two_cultists(): void
    {
        $enemies = $this->builder->build(2, 31);

        $this->assertSame(['mahou', 'dragon', 'mahou'], array_column($enemies, 'master_code'));
        $this->assertSame(['enemy_1', 'enemy_2', 'enemy_3'], array_column($enemies, 'slot'));
        $this->assertSame(['邪教徒 A', 'エルドラゴン', '邪教徒 B'], array_column($enemies, 'name'));
        $this->assertSame([2, 2, 2], array_column($enemies, 'floor'));
    }

    public function test_floor3_boss_step_spawns_zero_with_two_navis(): void
    {
        $enemies = $this->builder->build(3, 31);

        $this->assertSame(['navi', 'zero', 'navi'], array_column($enemies, 'master_code'));
        $this->assertSame(['ナビ A', 'ゼロ', 'ナビ B'], array_column($enemies, 'name'));
        $this->assertSame([3, 3, 3], array_column($enemies, 'floor'));
        $this->assertSame(['3_navi', '3_zero', '3_navi'], array_column($enemies, 'sprite'));
    }

    public function test_floor3_enemies_appear_by_depth(): void
    {
        $appearing = function (int $from, int $to): array {
            $codes = [];
            for ($i = 0; $i < 300; $i++) {
                $step = random_int($from, $to);
                foreach ($this->builder->build(3, $step) as $enemy) {
                    $codes[$enemy['master_code']] = true;
                }
            }
            ksort($codes);

            return array_keys($codes);
        };

        // 序盤はバニッシャーだけ、中盤はクリーナーが加わり、終盤はリーパー・クラッシャー（バニッシャーはもう出ない）
        $this->assertSame(['bunisher'], $appearing(1, 10));
        $this->assertSame(['bunisher', 'cleaner'], $appearing(11, 20));
        $this->assertSame(['cleaner', 'crusher', 'leaper'], $appearing(21, 30));
    }

    public function test_every_floor3_step_has_an_encounter_band(): void
    {
        for ($step = 1; $step <= 30; $step++) {
            $enemies = $this->builder->build(3, $step);

            $this->assertNotEmpty($enemies, "深さ {$step}");
            $this->assertLessThanOrEqual(3, count($enemies));
            $this->assertNotContains('zero', array_column($enemies, 'master_code'), 'ボスは通常戦に出ない');
            $this->assertNotContains('navi', array_column($enemies, 'master_code'), 'ボスは通常戦に出ない');
        }
    }

    public function test_encounter_includes_floor_for_battle_lookup(): void
    {
        $enemies = $this->builder->build(2, 5);

        $this->assertNotEmpty($enemies);
        $this->assertTrue(collect($enemies)->every(fn (array $enemy) => $enemy['floor'] === 2));
    }
}
