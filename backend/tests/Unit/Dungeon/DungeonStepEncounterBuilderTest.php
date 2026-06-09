<?php

namespace Tests\Unit\Dungeon;

use App\Services\Dungeon\DungeonStepEncounterBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_depth_1_to_10_spawns_only_bats(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $enemies = $this->builder->build(1, 5);
            $this->assertGreaterThanOrEqual(2, count($enemies));
            $this->assertLessThanOrEqual(3, count($enemies));
            foreach ($enemies as $enemy) {
                $this->assertSame('bat', $enemy['master_code']);
            }
        }
    }

    public function test_depth_11_to_20_spawns_snake_or_bat(): void
    {
        $codes = [];
        for ($i = 0; $i < 30; $i++) {
            foreach ($this->builder->build(1, 15) as $enemy) {
                $codes[] = $enemy['master_code'];
            }
        }

        $this->assertContains('bat', $codes);
        $this->assertContains('snake', $codes);
        $this->assertNotContains('beetle', $codes);
        $this->assertNotContains('inu_moe', $codes);
    }

    public function test_depth_21_to_30_spawns_snake_or_beetle(): void
    {
        $codes = [];
        for ($i = 0; $i < 30; $i++) {
            foreach ($this->builder->build(1, 25) as $enemy) {
                $codes[] = $enemy['master_code'];
            }
        }

        $this->assertContains('snake', $codes);
        $this->assertContains('beetle', $codes);
        $this->assertNotContains('bat', $codes);
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
