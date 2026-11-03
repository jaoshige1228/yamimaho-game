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

    public function test_encounter_includes_floor_for_battle_lookup(): void
    {
        $enemies = $this->builder->build(2, 5);

        $this->assertNotEmpty($enemies);
        $this->assertTrue(collect($enemies)->every(fn (array $enemy) => $enemy['floor'] === 2));
    }
}
