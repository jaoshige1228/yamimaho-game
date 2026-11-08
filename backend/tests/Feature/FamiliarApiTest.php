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
