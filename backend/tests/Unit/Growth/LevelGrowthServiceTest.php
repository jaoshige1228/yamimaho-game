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

    public function test_every_stat_grows_by_a_fixed_amount_per_level(): void
    {
        $this->seed(\Database\Seeders\MasterDataSeeder::class);

        $master = CharacterMaster::query()->where('code', 'pc1')->firstOrFail();
        $growth = new LevelGrowthService;

        $perLevel = [
            'hp' => 10,
            'mp' => 3,
            'mag' => 2,
            'def' => 2,
            'str' => 1,
            'spd' => 1,
            'know' => 1,
            'spirit' => 1,
            'vit' => 1,
        ];

        for ($level = 1; $level <= 5; $level++) {
            // Lv4 に上がるときだけ別の上昇値（GROWTH_BY_REACHED_LEVEL）なので、通常の増分の確認から除く
            if ($level + 1 === 4) {
                continue;
            }

            $current = $growth->statsForLevel($master, $level);
            $next = $growth->statsForLevel($master, $level + 1);

            foreach ($perLevel as $stat => $amount) {
                $this->assertSame($current[$stat] + $amount, $next[$stat], "Lv{$level}→Lv".($level + 1)." {$stat}");
            }
        }
    }

    public function test_stats_scale_with_level(): void
    {
        $this->seed(\Database\Seeders\MasterDataSeeder::class);

        $master = CharacterMaster::query()->where('code', 'pc1')->firstOrFail();
        $growth = new LevelGrowthService;

        $lv1 = $growth->statsForLevel($master, 1);
        $lv3 = $growth->statsForLevel($master, 3);
        $lv20 = $growth->statsForLevel($master, 20);

        $this->assertSame($master->hp, $lv1['hp']);
        $this->assertSame($master->hp + 20, $lv3['hp']);
        $this->assertSame($master->str + 2, $lv3['str']);
        $this->assertSame($master->spd + 2, $lv3['spd']);

        // Lv2〜20 の19回のうち、Lv4 到達の1回だけ上昇値が大きい（HP+20・MP+6・魔力防御+4・他+3）
        $this->assertSame($master->hp + 18 * 10 + 20, $lv20['hp']);
        $this->assertSame($master->mp + 18 * 3 + 6, $lv20['mp']);
        $this->assertSame($master->mag + 18 * 2 + 4, $lv20['mag']);
        $this->assertSame($master->def + 18 * 2 + 4, $lv20['def']);
        $this->assertSame($master->vit + 18 * 1 + 3, $lv20['vit']);
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
