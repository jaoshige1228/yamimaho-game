<?php

namespace Database\Seeders;

use App\Models\CharacterLevelStatPrediction;
use App\Models\CharacterMaster;
use App\Services\Growth\LevelGrowthService;
use App\Services\MasterData\LevelTableProvider;
use Illuminate\Database\Seeder;

class CharacterLevelStatPredictionSeeder extends Seeder
{
    public function run(): void
    {
        $growth = new LevelGrowthService;
        $levels = new LevelTableProvider;
        $maxLevel = $levels->maxLevel();

        CharacterMaster::query()
            ->orderBy('code')
            ->each(function (CharacterMaster $character) use ($growth, $maxLevel): void {
                for ($level = 1; $level <= $maxLevel; $level++) {
                    $stats = $growth->statsForLevel($character, $level);

                    CharacterLevelStatPrediction::query()->updateOrCreate(
                        [
                            'character_master_id' => $character->id,
                            'level' => $level,
                        ],
                        $stats,
                    );
                }
            });
    }
}
