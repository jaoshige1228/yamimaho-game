<?php

namespace Tests\Unit\Battle;

use App\Models\User;
use App\Models\UserCharacter;
use App\Services\Growth\LevelGrowthService;
use App\Services\Battle\BattleRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BattleRewardServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
        $this->postJson('/api/battles/demo')->assertOk();
    }

    public function test_victory_rewards_use_enemy_exp_total(): void
    {
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();

        $character = UserCharacter::query()
            ->where('user_id', $user->id)
            ->whereHas('characterMaster', fn ($q) => $q->where('code', 'pc1'))
            ->firstOrFail();
        $beforeExp = (int) $character->exp;

        $state = [
            'units' => [
                'pc1' => [
                    'id' => 'pc1',
                    'side' => 'ally',
                    'name' => 'サフィリア',
                    'alive' => true,
                    'hp' => 50,
                    'mp' => 20,
                    'user_character_id' => $character->id,
                ],
                'enemy_1' => [
                    'id' => 'enemy_1',
                    'side' => 'enemy',
                    'alive' => false,
                    'exp_reward' => 20,
                ],
                'enemy_2' => [
                    'id' => 'enemy_2',
                    'side' => 'enemy',
                    'alive' => false,
                    'exp_reward' => 30,
                ],
            ],
            'meta' => [
                'exp_earned' => 50,
            ],
        ];

        $service = new BattleRewardService;
        $events = $service->applyVictoryRewards($state, $user);

        $expEvent = collect($events)->firstWhere('type', 'exp_gained');
        $this->assertNotNull($expEvent);
        $this->assertSame(50, $expEvent['amount']);

        $character->refresh();
        $this->assertSame($beforeExp + 50, (int) $character->exp);
    }

    public function test_allies_that_are_down_at_the_end_also_receive_exp(): void
    {
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();

        $alive = $this->character($user, 'pc1');
        $dead = $this->character($user, 'pc2');
        $aliveBefore = (int) $alive->exp;
        $deadBefore = (int) $dead->exp;

        $state = [
            'units' => [
                'pc1' => [
                    'id' => 'pc1',
                    'side' => 'ally',
                    'name' => 'サフィリア',
                    'alive' => true,
                    'hp' => 10,
                    'mp' => 10,
                    'user_character_id' => $alive->id,
                ],
                'pc2' => [
                    'id' => 'pc2',
                    'side' => 'ally',
                    'name' => 'ガルデ',
                    'alive' => false,
                    'hp' => 0,
                    'mp' => 7,
                    'user_character_id' => $dead->id,
                ],
            ],
            'meta' => [
                'exp_earned' => 20,
            ],
        ];

        $service = new BattleRewardService;
        $events = $service->applyVictoryRewards($state, $user);

        // 生きている味方にも、戦闘不能の味方にも、同じ経験値が入る
        $expEvents = array_values(array_filter($events, fn (array $e): bool => ($e['type'] ?? '') === 'exp_gained'));
        $this->assertCount(2, $expEvents);
        $this->assertSame(['pc1', 'pc2'], array_column($expEvents, 'slot_id'));
        $this->assertSame([20, 20], array_column($expEvents, 'amount'));

        $alive->refresh();
        $dead->refresh();
        $this->assertSame($aliveBefore + 20, (int) $alive->exp);
        $this->assertSame($deadBefore + 20, (int) $dead->exp);
        $this->assertSame(0, (int) $dead->hp, '経験値が入っても、倒れた味方は HP 0 のまま');
    }

    public function test_a_downed_ally_that_levels_up_stays_down(): void
    {
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();

        $dead = $this->character($user, 'pc2');
        $growth = new LevelGrowthService;
        $before = $growth->statsForCharacter($dead);
        // 次のレベルまであと 5 の位置にして、経験値 20 で Lv1 → Lv2（最大HP・最大MPが増える）にする
        $dead->exp = $growth->cumulativeExpForLevel(2) - 5;
        $dead->save();

        $state = [
            'units' => [
                'pc2' => [
                    'id' => 'pc2',
                    'side' => 'ally',
                    'name' => 'ガルデ',
                    'alive' => false,
                    'hp' => 0,
                    'mp' => 7,
                    'user_character_id' => $dead->id,
                ],
            ],
            'meta' => ['exp_earned' => 20],
        ];

        $events = (new BattleRewardService)->applyVictoryRewards($state, $user);

        $this->assertContains('level_up', array_column($events, 'type'), 'レベルアップのお知らせは出る');
        $dead->refresh();
        $this->assertSame(2, (int) $dead->level);
        $after = $growth->statsForCharacter($dead);
        $this->assertGreaterThan($before['hp'], $after['hp'], '最大HPは増えている');
        $this->assertSame(0, (int) $dead->hp, 'レベルアップの最大HPの増加分で復活しない');
        // MP は生きているキャラと同じく、増えた分が足される（倒れるまでに使った MP が基準）
        $this->assertSame(min($after['mp'], 7 + ($after['mp'] - $before['mp'])), (int) $dead->mp);
    }

    public function test_a_downed_ally_that_jumps_several_levels_stays_down_and_a_living_one_is_healed_by_the_growth(): void
    {
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();

        $alive = $this->character($user, 'pc1');
        $dead = $this->character($user, 'pc2');
        $growth = new LevelGrowthService;
        $aliveBefore = $growth->statsForCharacter($alive);

        $state = [
            'units' => [
                'pc1' => [
                    'id' => 'pc1',
                    'side' => 'ally',
                    'name' => 'サフィリア',
                    'alive' => true,
                    'hp' => 10,
                    'mp' => 10,
                    'user_character_id' => $alive->id,
                ],
                'pc2' => [
                    'id' => 'pc2',
                    'side' => 'ally',
                    'name' => 'ガルデ',
                    'alive' => false,
                    'hp' => 0,
                    'mp' => 7,
                    'user_character_id' => $dead->id,
                ],
            ],
            // 一気に Lv4 まで上がる量
            'meta' => ['exp_earned' => $growth->cumulativeExpForLevel(4)],
        ];

        $events = (new BattleRewardService)->applyVictoryRewards($state, $user);

        $levelUps = array_values(array_filter($events, fn (array $e): bool => ($e['type'] ?? '') === 'level_up'));
        $this->assertCount(6, $levelUps, '2 人とも 3 回ずつレベルアップ');

        $dead->refresh();
        $alive->refresh();
        $this->assertSame(4, (int) $dead->level);
        $this->assertSame(0, (int) $dead->hp, '何段レベルアップしても、倒れた味方は HP 0 のまま');

        // 生きている味方は、今までどおり、最大HPの増加分だけ HP が増える（回帰）
        $aliveAfter = $growth->statsForCharacter($alive);
        $this->assertSame(4, (int) $alive->level);
        $this->assertSame(10 + ($aliveAfter['hp'] - $aliveBefore['hp']), (int) $alive->hp);
    }

    public function test_allies_who_fell_during_the_battle_stay_down_after_victory(): void
    {
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();

        $alive = UserCharacter::query()
            ->where('user_id', $user->id)
            ->whereHas('characterMaster', fn ($q) => $q->where('code', 'pc1'))
            ->firstOrFail();
        $fallen = UserCharacter::query()
            ->where('user_id', $user->id)
            ->whereHas('characterMaster', fn ($q) => $q->where('code', 'pc2'))
            ->firstOrFail();
        // 戦闘前は元気（HP・MP が残っている）。戦闘中に即死などで倒れたことにする
        $this->assertGreaterThan(0, (int) $fallen->hp);
        $this->assertGreaterThan(7, (int) $fallen->mp);

        $state = [
            'units' => [
                'pc1' => [
                    'id' => 'pc1',
                    'side' => 'ally',
                    'name' => 'サフィリア',
                    'alive' => true,
                    'hp' => 10,
                    'mp' => 10,
                    'user_character_id' => $alive->id,
                ],
                'pc2' => [
                    'id' => 'pc2',
                    'side' => 'ally',
                    'name' => 'ガルデ',
                    'alive' => false,
                    'hp' => 0,
                    'mp' => 7,
                    'user_character_id' => $fallen->id,
                ],
            ],
            'meta' => ['exp_earned' => 20],
        ];

        (new BattleRewardService)->applyVictoryRewards($state, $user);

        $fallen->refresh();
        $this->assertSame(0, (int) $fallen->hp, '倒れた味方は、戦闘が終わっても HP 0 のまま（復活しない）');
        $this->assertSame(7, (int) $fallen->mp, '倒れるまでに使った MP も反映される');

        $alive->refresh();
        $this->assertSame(10, (int) $alive->hp);
        $this->assertSame(10, (int) $alive->mp);
    }

    private function character(User $user, string $code): UserCharacter
    {
        return UserCharacter::query()
            ->where('user_id', $user->id)
            ->whereHas('characterMaster', fn ($q) => $q->where('code', $code))
            ->firstOrFail();
    }
}
