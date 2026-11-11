<?php

namespace Tests\Feature;

use App\Models\BattleState;
use App\Models\User;
use App\Models\UserFamiliar;
use App\Services\Player\PartyGoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 使い魔ページ: 魔石を 100G で与えてレベルアップし、能力が解放される。
 */
class FamiliarApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
    }

    public function test_initial_status_is_level_zero_with_no_ability_shown(): void
    {
        $status = $this->getJson('/api/familiar')->assertOk()->json();

        $this->assertSame(0, $status['level']);
        $this->assertSame(9, $status['max_level']);
        $this->assertSame(0, $status['stones_total']);
        $this->assertSame(100, $status['feed_cost']);
        $this->assertFalse($status['max_reached']);
        $this->assertCount(9, $status['abilities']);
        $this->assertSame(range(1, 9), array_column($status['abilities'], 'level'));
        foreach ($status['abilities'] as $ability) {
            $this->assertFalse($ability['unlocked']);
            $this->assertNull($ability['description']);
        }
    }

    public function test_feeding_costs_100_gold_and_the_second_stone_reaches_level_one(): void
    {
        $user = $this->user(gold: 250);

        $first = $this->postJson('/api/familiar/feed')->assertOk()
            ->assertJsonPath('message', '魔石を与えた。')
            ->assertJsonPath('leveled_up', false);
        $this->assertSame([0, 1, 150], [$first->json('level'), $first->json('stones_total'), $first->json('gold')]);

        $second = $this->postJson('/api/familiar/feed')->assertOk()
            ->assertJsonPath('leveled_up', true)
            ->assertJsonPath('message', '使い魔がレベルアップした！（Lv1）');
        $this->assertSame([1, 2, 50], [$second->json('level'), $second->json('stones_total'), $second->json('gold')]);

        $this->assertSame(50, (new PartyGoldService)->getGold($user->fresh()));
        $this->assertSame(
            ['敵単体にダメージを与える。'],
            array_values(array_filter(array_column($second->json('abilities'), 'description'))),
        );
    }

    public function test_levels_go_up_at_2_4_8_10_16_20_30_50_and_80_stones_each(): void
    {
        $this->user(gold: 22000);

        // 累計: 2, 6, 14, 24, 40, 60, 90, 140, 220 個
        $thresholds = [1 => 2, 2 => 6, 3 => 14, 4 => 24, 5 => 40, 6 => 60, 7 => 90, 8 => 140, 9 => 220];
        $levelAfter = [];
        for ($stones = 1; $stones <= 220; $stones++) {
            $levelAfter[$stones] = $this->postJson('/api/familiar/feed')->assertOk()->json('level');
        }

        foreach ($thresholds as $level => $stones) {
            $this->assertSame($level, $levelAfter[$stones], "魔石{$stones}個で Lv{$level}");
            $this->assertSame($level - 1, $levelAfter[$stones - 1], "魔石".($stones - 1)."個ではまだ Lv".($level - 1));
        }
        $this->assertSame(0, (new PartyGoldService)->getGold(User::query()->firstOrFail()), '合計 22,000G');
    }

    public function test_abilities_only_show_descriptions_of_unlocked_levels(): void
    {
        $this->user(gold: 0);
        UserFamiliar::query()->create(['user_id' => User::query()->firstOrFail()->id, 'level' => 3, 'stones_total' => 14, 'stones_progress' => 0]);

        $abilities = $this->getJson('/api/familiar')->assertOk()->json('abilities');

        $this->assertSame(
            ['敵単体にダメージを与える。', '味方一人を小回復する。', '使い魔が支援してくれる確率が上がる。', null, null, null, null, null, null],
            array_column($abilities, 'description'),
        );
        $this->assertSame([true, true, true, false, false, false, false, false, false], array_column($abilities, 'unlocked'));
        // 必要魔石数は画面に出さない
        $this->assertStringNotContainsString('stones', json_encode($abilities));
    }

    public function test_feeding_without_enough_gold_fails_and_changes_nothing(): void
    {
        $user = $this->user(gold: 99);

        $this->postJson('/api/familiar/feed')->assertStatus(422)->assertJsonPath('message', 'ゴールドが足りません。');

        $this->assertSame(99, (new PartyGoldService)->getGold($user->fresh()));
        $this->assertSame(0, UserFamiliar::query()->sum('stones_total'));
        $this->assertFalse($this->getJson('/api/familiar')->json('can_feed'));
    }

    public function test_at_max_level_feeding_is_refused_and_keeps_the_gold(): void
    {
        $user = $this->user(gold: 1000);
        UserFamiliar::query()->create(['user_id' => $user->id, 'level' => 9, 'stones_total' => 220, 'stones_progress' => 0]);

        $status = $this->getJson('/api/familiar')->assertOk()->json();
        $this->assertTrue($status['max_reached']);
        $this->assertFalse($status['can_feed']);

        $this->postJson('/api/familiar/feed')->assertStatus(422)->assertJsonPath('message', '使い魔はこれ以上強くなりません。');

        $this->assertSame(1000, (new PartyGoldService)->getGold($user->fresh()));
        $this->assertSame(220, (int) UserFamiliar::query()->firstOrFail()->stones_total);
    }

    public function test_status_tells_how_many_stones_are_left_until_the_next_level(): void
    {
        $user = $this->user(gold: 100000);

        // Lv0 → Lv1 は 2 個、Lv1 → Lv2 は 4 個（前のレベルアップからの数）
        $this->assertSame(2, $this->getJson('/api/familiar')->json('stones_to_next'));

        $this->postJson('/api/familiar/feed')->assertOk()->assertJsonPath('stones_to_next', 1);
        $this->postJson('/api/familiar/feed')->assertOk()->assertJsonPath('stones_to_next', 4);
        $this->postJson('/api/familiar/feed')->assertOk()->assertJsonPath('stones_to_next', 3);

        // 最大レベルでは出さない
        UserFamiliar::query()->where('user_id', $user->id)->update(['level' => 9, 'stones_progress' => 0]);
        $this->assertNull($this->getJson('/api/familiar')->json('stones_to_next'));
    }

    public function test_feeding_ten_stones_at_once_costs_1000_gold_and_can_level_up_several_times(): void
    {
        $user = $this->user(gold: 1000);

        $status = $this->getJson('/api/familiar')->assertOk()->json();
        $this->assertSame([10, 1000, true], [$status['bulk_count'], $status['bulk_cost'], $status['can_feed_bulk']]);

        // Lv0 から 10 個: 2 個で Lv1、続く 4 個で Lv2、残り 4 個は Lv3 に向けた途中（あと 4 個）
        $response = $this->postJson('/api/familiar/feed', ['count' => 10])->assertOk()
            ->assertJsonPath('fed', 10)
            ->assertJsonPath('leveled_up', true)
            ->assertJsonPath('message', '魔石を10個与えた。使い魔がレベルアップした！（Lv0→Lv2）');

        $this->assertSame([2, 10, 4, 0], [
            $response->json('level'), $response->json('stones_total'), $response->json('stones_to_next'), $response->json('gold'),
        ]);
        $this->assertSame(0, (new PartyGoldService)->getGold($user->fresh()));
    }

    public function test_feeding_ten_stones_without_a_level_up_says_so_plainly(): void
    {
        $this->user(gold: 1000);
        \App\Models\UserFamiliar::query()->create(['user_id' => User::query()->firstOrFail()->id, 'level' => 5, 'stones_total' => 40, 'stones_progress' => 0]);

        // Lv5 → Lv6 は 20 個。10 個ではまだ上がらない
        $this->postJson('/api/familiar/feed', ['count' => 10])->assertOk()
            ->assertJsonPath('leveled_up', false)
            ->assertJsonPath('message', '魔石を10個与えた。')
            ->assertJsonPath('stones_to_next', 10);
    }

    public function test_feeding_ten_stones_without_enough_gold_fails_and_changes_nothing(): void
    {
        $user = $this->user(gold: 999);

        $status = $this->getJson('/api/familiar')->assertOk()->json();
        $this->assertFalse($status['can_feed_bulk']);
        $this->assertTrue($status['can_feed'], '1 個ならあげられる');

        $this->postJson('/api/familiar/feed', ['count' => 10])->assertStatus(422)->assertJsonPath('message', 'ゴールドが足りません。');

        $this->assertSame(999, (new PartyGoldService)->getGold($user->fresh()));
        $this->assertSame(0, UserFamiliar::query()->sum('stones_total'));
    }

    public function test_bulk_feeding_stops_at_the_max_level_and_only_charges_for_the_stones_used(): void
    {
        $user = $this->user(gold: 1000);
        // Lv8 で、Lv9 まであと 5 個
        UserFamiliar::query()->create(['user_id' => $user->id, 'level' => 8, 'stones_total' => 135, 'stones_progress' => 75]);

        $status = $this->getJson('/api/familiar')->assertOk()->json();
        $this->assertSame([5, 500, 5], [$status['bulk_count'], $status['bulk_cost'], $status['stones_to_next']]);

        $response = $this->postJson('/api/familiar/feed', ['count' => 10])->assertOk()->assertJsonPath('fed', 5);
        $this->assertSame([9, true, 500, 0], [
            $response->json('level'), $response->json('max_reached'), $response->json('gold'), $response->json('bulk_count'),
        ]);
        $this->assertSame(140, (int) UserFamiliar::query()->firstOrFail()->stones_total);
    }

    public function test_the_stone_count_must_be_between_one_and_ten(): void
    {
        $user = $this->user(gold: 100000);

        foreach ([0, 11, -1, 'abc', 2.5] as $bad) {
            $this->postJson('/api/familiar/feed', ['count' => $bad])->assertStatus(422);
        }
        $this->assertSame(100000, (new PartyGoldService)->getGold($user->fresh()));

        // 省略は 1 個、1 と 10 はそのまま通る
        $this->postJson('/api/familiar/feed')->assertOk()->assertJsonPath('fed', 1);
        $this->postJson('/api/familiar/feed', ['count' => 1])->assertOk()->assertJsonPath('fed', 1);
        $this->postJson('/api/familiar/feed', ['count' => 10])->assertOk()->assertJsonPath('fed', 10);
    }

    public function test_reset_clears_the_familiar(): void
    {
        $user = $this->user(gold: 1000);
        $this->postJson('/api/familiar/feed')->assertOk();
        $this->postJson('/api/familiar/feed')->assertOk();
        $this->assertSame(1, $this->getJson('/api/familiar')->json('level'));

        $this->postJson('/api/reset')->assertOk();

        $this->assertSame(0, UserFamiliar::query()->where('user_id', $user->id)->count());
        $status = $this->getJson('/api/familiar')->assertOk()->json();
        $this->assertSame([0, 0], [$status['level'], $status['stones_total']]);
    }

    public function test_the_familiar_level_is_given_to_new_battles(): void
    {
        $user = $this->user(gold: 0);
        UserFamiliar::query()->create(['user_id' => $user->id, 'level' => 3, 'stones_total' => 14, 'stones_progress' => 0]);

        $battleId = $this->postJson('/api/battles/demo')->assertOk()->json('battle_id');

        $this->assertSame(['level' => 3], BattleState::query()->findOrFail($battleId)->state['meta']['familiar']);
    }

    public function test_battles_have_no_familiar_at_level_zero(): void
    {
        $this->user(gold: 0);

        $battleId = $this->postJson('/api/battles/demo')->assertOk()->json('battle_id');

        $this->assertArrayNotHasKey('familiar', BattleState::query()->findOrFail($battleId)->state['meta'] ?? []);
    }

    private function user(int $gold): User
    {
        // デモユーザーは API を呼ぶと作られる
        $this->getJson('/api/familiar')->assertOk();
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        (new PartyGoldService)->setGold($user, $gold);

        return $user->fresh();
    }
}
