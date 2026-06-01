<?php

namespace Tests\Unit\Growth;

use App\Models\CharacterMaster;
use App\Models\User;
use App\Models\UserCharacter;
use App\Services\Growth\LevelGrowthService;
use App\Services\MasterData\LevelTableProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LevelGrowthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_exp_table_uses_cumulative_thresholds(): void
    {
        $levels = new LevelTableProvider;

        $this->assertSame(0, $levels->cumulativeExpForLevel(1));
        $this->assertSame(50, $levels->cumulativeExpForLevel(2));
        $this->assertSame(105, $levels->cumulativeExpForLevel(3));
        $this->assertSame(166, $levels->cumulativeExpForLevel(4));
        $this->assertSame(50, $levels->expToNextLevel(1));
        $this->assertSame(105, $levels->expToNextLevel(2));
        $this->assertSame(166, $levels->expToNextLevel(3));
    }

    public function test_stats_use_ceil_on_level_up(): void
    {
        $this->seed(\Database\Seeders\MasterDataSeeder::class);

        $master = CharacterMaster::query()->where('code', 'pc1')->firstOrFail();
        $growth = new LevelGrowthService;

        $lv2 = $growth->statsForLevel($master, 2);

        $this->assertSame(17, $lv2['mag']);
        $this->assertSame(50, $lv2['mp']);
    }

    public function test_stats_multiply_from_previous_level(): void
    {
        $this->seed(\Database\Seeders\MasterDataSeeder::class);

        $master = CharacterMaster::query()->where('code', 'pc1')->firstOrFail();
        $growth = new LevelGrowthService;

        $lv2 = $growth->statsForLevel($master, 2);
        $lv3 = $growth->statsForLevel($master, 3);

        $this->assertSame(17, $lv2['mag']);
        $this->assertSame(21, $lv3['mag']);
        $this->assertSame(11, $lv2['def']);
        $this->assertSame(14, $lv3['def']);
    }

    public function test_stats_scale_with_level(): void
    {
        $this->seed(\Database\Seeders\MasterDataSeeder::class);

        $master = CharacterMaster::query()->where('code', 'pc1')->firstOrFail();
        $growth = new LevelGrowthService;

        $lv1 = $growth->statsForLevel($master, 1);
        $lv3 = $growth->statsForLevel($master, 3);

        $this->assertSame($master->hp, $lv1['hp']);
        $this->assertSame(
            (int) ceil((int) ceil($master->hp * 1.1) * 1.1),
            $lv3['hp'],
        );
        $this->assertSame($master->str + 4, $lv3['str']);
        $this->assertSame($master->spd + 4, $lv3['spd']);
    }

    public function test_grant_exp_levels_up_character(): void
    {
        $this->seed(\Database\Seeders\MasterDataSeeder::class);

        $user = User::factory()->create();
        $master = CharacterMaster::query()->where('code', 'pc1')->firstOrFail();
        $growth = new LevelGrowthService;
        $stats = $growth->statsForLevel($master, 1);

        $character = UserCharacter::query()->create([
            'user_id' => $user->id,
            'character_master_id' => $master->id,
            'level' => 1,
            'exp' => 0,
            'hp' => $stats['hp'],
            'mp' => $stats['mp'],
            'str' => $stats['str'],
            'mag' => $stats['mag'],
            'def' => $stats['def'],
            'spd' => $stats['spd'],
            'know' => $stats['know'],
            'spirit' => $stats['spirit'],
        ]);
        $character->setRelation('characterMaster', $master);

        $events = $growth->grantExp($character, 50);

        $this->assertSame(2, $character->level);
        $this->assertSame(50, $character->exp);
        $this->assertNotEmpty($events);
        $this->assertSame('level_up', $events[0]['type']);
    }

    public function test_grant_exp_can_skip_multiple_levels(): void
    {
        $this->seed(\Database\Seeders\MasterDataSeeder::class);

        $user = User::factory()->create();
        $master = CharacterMaster::query()->where('code', 'pc1')->firstOrFail();
        $growth = new LevelGrowthService;
        $stats = $growth->statsForLevel($master, 1);

        $character = UserCharacter::query()->create([
            'user_id' => $user->id,
            'character_master_id' => $master->id,
            'level' => 1,
            'exp' => 0,
            'hp' => $stats['hp'],
            'mp' => $stats['mp'],
            'str' => $stats['str'],
            'mag' => $stats['mag'],
            'def' => $stats['def'],
            'spd' => $stats['spd'],
            'know' => $stats['know'],
            'spirit' => $stats['spirit'],
        ]);
        $character->setRelation('characterMaster', $master);

        $events = $growth->grantExp($character, 166);

        $this->assertSame(4, $character->level);
        $this->assertSame(166, $character->exp);
        $this->assertCount(3, $events);
    }
}
