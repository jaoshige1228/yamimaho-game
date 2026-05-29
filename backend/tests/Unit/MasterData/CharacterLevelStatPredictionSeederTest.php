<?php

namespace Tests\Unit\MasterData;

use App\Models\CharacterLevelStatPrediction;
use App\Models\CharacterMaster;
use App\Services\Growth\LevelGrowthService;
use App\Services\MasterData\LevelTableProvider;
use Database\Seeders\CharacterLevelStatPredictionSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterLevelStatPredictionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_fills_all_characters_and_levels(): void
    {
        $this->seed(MasterDataSeeder::class);
        $this->seed(CharacterLevelStatPredictionSeeder::class);

        $characterCount = CharacterMaster::query()->count();
        $maxLevel = (new LevelTableProvider)->maxLevel();

        $this->assertSame($characterCount * $maxLevel, CharacterLevelStatPrediction::query()->count());
    }

    public function test_predictions_match_level_growth_service(): void
    {
        $this->seed(MasterDataSeeder::class);
        $this->seed(CharacterLevelStatPredictionSeeder::class);

        $master = CharacterMaster::query()->where('code', 'pc1')->firstOrFail();
        $growth = new LevelGrowthService;

        foreach ([1, 2, 3, 50] as $level) {
            $expected = $growth->statsForLevel($master, $level);
            $row = CharacterLevelStatPrediction::query()
                ->where('character_master_id', $master->id)
                ->where('level', $level)
                ->firstOrFail();

            $this->assertSame($expected['hp'], $row->hp);
            $this->assertSame($expected['mp'], $row->mp);
            $this->assertSame($expected['str'], $row->str);
            $this->assertSame($expected['mag'], $row->mag);
            $this->assertSame($expected['def'], $row->def);
            $this->assertSame($expected['spd'], $row->spd);
            $this->assertSame($expected['know'], $row->know);
            $this->assertSame($expected['spirit'], $row->spirit);
        }
    }
}
