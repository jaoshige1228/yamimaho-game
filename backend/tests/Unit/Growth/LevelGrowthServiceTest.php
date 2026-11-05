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
            // Lv4 に上がるときだけ別の上昇値（GROWTH_BY_REACHED_LEVEL）なので、通常の増分の確認から除く（ここは Lv6 まで。Lv7・Lv12 以降も別の上昇値）
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

        // Lv2〜20 の19回の内訳: 通常 8回（Lv2,3,5,6,8,9,10,11）+ Lv4（HP+20 MP+6 魔力防御+4 他+3）
        // + Lv7（他+3、ほかは通常）+ Lv12〜20 のうち Lv18 を除く8回（HP+15 MP+5 魔力防御+4 他+2）
        // + Lv18（HP+30 MP+10 魔力防御+8 他+4）
        $this->assertSame($master->hp + 8 * 10 + 20 + 10 + 8 * 15 + 30, $lv20['hp']);
        $this->assertSame($master->mp + 8 * 3 + 6 + 3 + 8 * 5 + 10, $lv20['mp']);
        $this->assertSame($master->mag + 8 * 2 + 4 + 2 + 8 * 4 + 8, $lv20['mag']);
        $this->assertSame($master->def + 8 * 2 + 4 + 2 + 8 * 4 + 8, $lv20['def']);
        $this->assertSame($master->vit + 8 * 1 + 3 + 3 + 8 * 2 + 4, $lv20['vit']);
    }

    public function test_only_reaching_level_18_uses_the_special_growth(): void
    {
        $this->seed(\Database\Seeders\MasterDataSeeder::class);

        $master = CharacterMaster::query()->where('code', 'pc1')->firstOrFail();
        $growth = new LevelGrowthService;

        $gained = function (int $from, int $to) use ($growth, $master): array {
            $before = $growth->statsForLevel($master, $from);
            $after = $growth->statsForLevel($master, $to);

            return array_map(fn (string $stat) => $after[$stat] - $before[$stat], array_combine(array_keys($before), array_keys($before)));
        };

        // Lv12 以降の通常（Lv17→Lv18 の前後）
        $late = ['hp' => 15, 'mp' => 5, 'mag' => 4, 'def' => 4, 'str' => 2, 'spd' => 2, 'know' => 2, 'spirit' => 2, 'vit' => 2];
        $this->assertEquals($late, $gained(16, 17));
        $this->assertEquals($late, $gained(18, 19));
        $this->assertEquals($late, $gained(19, 20));

        // Lv18 に上がるときだけ: HP+30 MP+10 魔力+8 防御+8 その他+4
        $this->assertEquals(
            ['hp' => 30, 'mp' => 10, 'mag' => 8, 'def' => 8, 'str' => 4, 'spd' => 4, 'know' => 4, 'spirit' => 4, 'vit' => 4],
            $gained(17, 18),
        );
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
