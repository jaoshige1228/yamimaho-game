<?php

namespace Tests\Feature;

use App\Models\DungeonEventMaster;
use App\Models\DungeonEventNode;
use App\Models\User;
use App\Models\UserCharacter;
use App\Models\UserDungeonProgress;
use App\Services\Dungeon\DungeonEventCatalog;
use App\Services\Dungeon\DungeonEventFloorOverrides;
use App\Services\Player\UserCharacterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 2 層だけ変えているイベントの数値（dungeon_event_floor_overrides.csv）と、報酬アイテムの差し替え（reward_item_map）。
 */
class DungeonEventFloorOverridesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
        $this->postJson('/api/battles/demo')->assertOk();
        // 3 層は探索イベントだけ先に作ってある（本番の playable_floor は 2）。テストでは 3 層に入れるようにする
        config(['game.dungeon.test_force' => 'exploration', 'game.dungeon.playable_floor' => 3]);
    }

    protected function tearDown(): void
    {
        config([
            'game.dungeon.test_force' => null,
            'game.dungeon.test_event_code' => null,
            'game.dungeon.test_roll' => null,
            'game.dungeon.test_stat_success' => null,
        ]);

        parent::tearDown();
    }

    public function test_every_override_row_points_at_an_existing_node_of_a_matching_type(): void
    {
        $rows = (new DungeonEventFloorOverrides)->all();
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $label = "{$row['floor']}層 {$row['event_code']}.{$row['node_key']}";

            $this->assertTrue(
                DungeonEventMaster::query()->where('floor', $row['floor'])->where('code', $row['event_code'])->exists(),
                "{$label}: その層にそのイベントがない",
            );
            $node = DungeonEventNode::query()
                ->where('event_code', $row['event_code'])
                ->where('node_key', $row['node_key'])
                ->first();
            $this->assertNotNull($node, "{$label}: ノードがない");

            $this->assertTrue(
                $row['stat_multiplier'] !== null || $row['fixed_damage'] !== null,
                "{$label}: 上書きする値がない",
            );
            if ($row['stat_multiplier'] !== null) {
                $this->assertContains(
                    $node->node_type,
                    ['stat_check', 'stat_check_each_ally', 'grant_gold', 'chance_branch'],
                    "{$label}: stat_multiplier を上書きできないノード",
                );
            }
            if ($row['fixed_damage'] !== null) {
                $this->assertContains(
                    $node->node_type,
                    ['apply_damage', 'apply_damage_chosen', 'apply_damage_party', 'stat_check_each_ally'],
                    "{$label}: fixed_damage を上書きできないノード",
                );
            }
        }
    }

    public function test_find_node_applies_overrides_only_on_the_matching_floor(): void
    {
        $catalog = new DungeonEventCatalog;

        $floorOne = $catalog->findNode('treasure_chest', 'str_roll', 1);
        $this->assertSame(3, (int) $floorOne->stat_multiplier);
        $this->assertFalse($floorOne->statMultiplierOverridden);

        $floorTwo = $catalog->findNode('treasure_chest', 'str_roll', 2);
        $this->assertSame(2.3, $floorTwo->stat_multiplier);
        $this->assertTrue($floorTwo->statMultiplierOverridden);

        // 空のセルは上書きしない（人喰い植物の「HPは最低 1 残す」を保つ）
        $plant = $catalog->findNode('man_eating_plant', 'fail_dmg', 2);
        $this->assertSame(200, (int) $plant->fixed_damage);
        $this->assertSame(1, (int) $plant->stat_multiplier);
        $this->assertFalse($plant->statMultiplierOverridden);

        // DB の行そのものは書き換わらない
        $this->assertSame(3, (int) DungeonEventNode::query()
            ->where('event_code', 'treasure_chest')->where('node_key', 'str_roll')->value('stat_multiplier'));
        $this->assertSame(150, (int) DungeonEventNode::query()
            ->where('event_code', 'man_eating_plant')->where('node_key', 'fail_dmg')->value('fixed_damage'));
    }

    public function test_trap_arrow_multiplier_is_not_overridden(): void
    {
        $overridden = collect((new DungeonEventFloorOverrides)->all())
            ->filter(fn (array $row) => $row['stat_multiplier'] !== null && $row['floor'] === 2)
            ->map(fn (array $row) => $row['event_code'].'.'.$row['node_key']);

        $this->assertFalse($overridden->contains('trap_arrow.roll'), '矢のトラップだけは従来どおり×2（層補正）');
        $this->assertContains('treasure_chest.str_roll', $overridden->all());

        $roll = (new DungeonEventCatalog)->findNode('trap_arrow', 'roll', 2);
        $this->assertSame(4, (int) $roll->stat_multiplier);
        $this->assertFalse($roll->statMultiplierOverridden);
    }

    public function test_floor_three_has_the_same_events_as_floor_two_except_the_mid_boss(): void
    {
        $codes = fn (int $floor): array => DungeonEventMaster::query()->where('floor', $floor)->orderBy('code')->pluck('code')->all();

        $this->assertContains('floor2_mid_boss', $codes(2));
        $this->assertNotContains('floor2_mid_boss', $codes(3), 'グースとカース戦は 2 層だけ');
        $this->assertSame(
            array_values(array_diff($codes(2), ['floor2_mid_boss'])),
            $codes(3),
        );

        $dialogue = fn (int $floor): array => \App\Models\DungeonDialogueEventMaster::query()
            ->where('floor', $floor)->orderBy('code')->pluck('code')->all();
        $this->assertSame($dialogue(2), $dialogue(3));
        $this->assertNotEmpty($dialogue(3));

        // 3 層の敵データ（イフリーヌ）ができるまで、謎の気配は抽選に出さない（重み 0）。ほかは 2 層と同じ重み
        foreach (DungeonEventMaster::query()->where('floor', 3)->get() as $event) {
            $weightOnFloorTwo = (int) DungeonEventMaster::query()->where('floor', 2)->where('code', $event->code)->value('weight');
            $this->assertSame(
                $event->code === 'mysterious_presence' ? 0 : $weightOnFloorTwo,
                (int) $event->weight,
                "3層 {$event->code} の重み",
            );
        }
    }

    public function test_floor_three_sets_its_own_value_for_every_check_damage_and_gold(): void
    {
        $overrides = collect((new DungeonEventFloorOverrides)->all())->where('floor', 3)
            ->keyBy(fn (array $row) => $row['event_code'].'.'.$row['node_key']);
        $floorTwo = collect((new DungeonEventFloorOverrides)->all())->where('floor', 2)
            ->keyBy(fn (array $row) => $row['event_code'].'.'.$row['node_key']);

        $eventCodes = DungeonEventMaster::query()->where('floor', 3)->pluck('code');
        $nodes = DungeonEventNode::query()->whereIn('event_code', $eventCodes)->get();

        foreach ($nodes as $node) {
            $key = $node->event_code.'.'.$node->node_key;

            // 判定は、層補正（stat_multiplier_scale）に頼らず 3 層の倍率を直接指定する
            if (in_array($node->node_type, ['stat_check', 'stat_check_each_ally'], true)) {
                $this->assertNotNull($overrides->get($key)['stat_multiplier'] ?? null, "3層 {$key}: 判定の倍率が未設定");
            }
            // ダメージ（落とし穴の即戦闘不能 99999 だけは層に関係なく同じ）
            if (in_array($node->node_type, ['apply_damage', 'apply_damage_chosen', 'apply_damage_party', 'stat_check_each_ally'], true)
                && (int) $node->fixed_damage !== 99999) {
                $this->assertNotNull($overrides->get($key)['fixed_damage'] ?? null, "3層 {$key}: ダメージが未設定");
            }
            if ($node->node_type === 'grant_gold') {
                $this->assertNotNull($overrides->get($key)['stat_multiplier'] ?? null, "3層 {$key}: ゴールドが未設定");
            }
        }

        // 3 層は 2 層より、ダメージが重く、ゴールドが多い
        foreach ($overrides as $key => $row) {
            if ($row['fixed_damage'] !== null && isset($floorTwo[$key]['fixed_damage'])) {
                $this->assertGreaterThan($floorTwo[$key]['fixed_damage'], $row['fixed_damage'], "{$key} のダメージ");
            }
        }
        foreach (['gold_pickup.grant_gold', 'trapped_chest.gold_path', 'safe_chest.gold_path'] as $key) {
            $this->assertGreaterThan($floorTwo[$key]['stat_multiplier'], $overrides[$key]['stat_multiplier'], "{$key} のゴールド");
        }
    }

    public function test_floor_three_multipliers_are_lower_than_floor_two_because_stats_are_higher(): void
    {
        // 3 層は想定レベルが高い（2 層 Lv12/15/18 → 3 層 Lv20/25/30）ぶん、同じ判定でも倍率は小さい
        $catalog = new DungeonEventCatalog;
        foreach ([['treasure_chest', 'str_roll'], ['trapped_chest', 'know_roll'], ['flash_flood', 'spirit_checks'], ['ghost_possession', 'spirit_roll']] as [$event, $node]) {
            $two = $catalog->findNode($event, $node, 2);
            $three = $catalog->findNode($event, $node, 3);
            $this->assertTrue($three->statMultiplierOverridden, "{$event}.{$node}");
            $this->assertLessThan((float) $two->stat_multiplier, (float) $three->stat_multiplier, "{$event}.{$node}");
            $this->assertGreaterThan(1.0, (float) $three->stat_multiplier, "{$event}.{$node}");
        }
    }

    public function test_floor_three_treasure_chest_rates_use_its_own_multiplier_rounded_up(): void
    {
        $this->enterFloor(3);
        config(['game.dungeon.test_event_code' => 'treasure_chest']);

        $options = $this->postJson('/api/dungeon/advance')->assertOk()->json('segment.options');

        $this->assertCount(4, $options);
        foreach ($options as $option) {
            $this->assertSame(
                min(95, (int) ceil(round($option['str'] * 1.35, 6))),
                $option['success_rate'],
                "str {$option['str']}",
            );
        }
    }

    public function test_floor_three_trap_arrow_uses_its_own_multiplier(): void
    {
        $this->enterFloor(3);
        config(['game.dungeon.test_event_code' => 'trap_arrow']);

        $text = collect($this->postJson('/api/dungeon/advance')->assertOk()->json('segment.lines'))->pluck('text')->implode("\n");
        $this->assertSame(1, preg_match('/成功率：(\d+)%/u', $text, $m), $text);

        $speeds = $this->party()->map(fn (UserCharacter $c) => (int) $c->spd);
        $this->assertContains((int) $m[1], $speeds->map(fn (int $spd) => min(95, (int) ceil(round($spd * 1.15, 6))))->all());
    }

    public function test_floor_two_treasure_chest_rates_use_2_3_multiplier_rounded_up(): void
    {
        $this->enterFloor(2);
        config(['game.dungeon.test_event_code' => 'treasure_chest']);

        $options = $this->postJson('/api/dungeon/advance')->assertOk()->json('segment.options');

        $this->assertCount(4, $options);
        foreach ($options as $option) {
            $this->assertSame(
                min(95, (int) ceil(round($option['str'] * 2.3, 6))),
                $option['success_rate'],
                "str {$option['str']}",
            );
            $this->assertStringContainsString("成功率{$option['success_rate']}%", $option['label']);
        }
        // 端数が出る人がいる（切り上げの確認）
        $this->assertTrue(collect($options)->contains(fn ($o) => fmod($o['str'] * 2.3, 1.0) > 0.0));
    }

    public function test_floor_one_treasure_chest_rates_are_unchanged(): void
    {
        $this->enterFloor(1);
        config(['game.dungeon.test_event_code' => 'treasure_chest']);

        $options = $this->postJson('/api/dungeon/advance')->assertOk()->json('segment.options');

        foreach ($options as $option) {
            $this->assertSame(min(95, $option['str'] * 3), $option['success_rate']);
        }
    }

    public function test_floor_two_trap_arrow_still_uses_the_halved_multiplier(): void
    {
        $this->enterFloor(2);
        config(['game.dungeon.test_event_code' => 'trap_arrow']);

        $text = collect($this->postJson('/api/dungeon/advance')->assertOk()->json('segment.lines'))->pluck('text')->implode("\n");
        $this->assertSame(1, preg_match('/成功率：(\d+)%/u', $text, $m), $text);

        $speeds = $this->party()->map(fn (UserCharacter $c) => (int) $c->spd);
        $this->assertContains((int) $m[1], $speeds->map(fn (int $spd) => min(95, $spd * 2))->all());
        $this->assertNotContains((int) $m[1], $speeds->map(fn (int $spd) => min(95, (int) ceil(round($spd * 2.3, 6))))->all());
    }

    public function test_flash_flood_rates_for_every_ally_differ_by_floor(): void
    {
        $spirits = $this->party()->map(fn (UserCharacter $c) => (int) $c->spirit)->values();

        $this->enterFloor(1);
        $this->assertSame(
            $spirits->map(fn (int $v) => min(95, $v * 4))->all(),
            $this->shownRates($this->runEvent('flash_flood', statSuccess: true)),
        );

        $this->enterFloor(2);
        $this->assertSame(
            $spirits->map(fn (int $v) => min(95, (int) ceil(round($v * 2.3, 6))))->all(),
            $this->shownRates($this->runEvent('flash_flood', statSuccess: true)),
        );
    }

    public function test_floor_two_ghost_possession_preview_uses_2_3_multiplier(): void
    {
        $this->enterFloor(2);
        config(['game.dungeon.test_event_code' => 'ghost_possession']);

        $lines = $this->postJson('/api/dungeon/advance')->assertOk()->json('segment.lines');
        $rates = $this->shownRates(collect($lines)->pluck('text')->all());
        $this->assertCount(1, $rates);

        $spirits = $this->party()->map(fn (UserCharacter $c) => (int) $c->spirit);
        $this->assertContains($rates[0], $spirits->map(fn (int $v) => min(95, (int) ceil(round($v * 2.3, 6))))->all());
        $this->assertNotContains($rates[0], $spirits->map(fn (int $v) => min(95, $v * 2))->all());
    }

    public function test_floor_two_trap_arrow_failure_deals_sixty_damage(): void
    {
        $this->enterFloor(2);
        $before = (int) UserCharacter::query()->sum('hp');

        $texts = $this->runEvent('trap_arrow', statSuccess: false);

        $this->assertContains(true, array_map(fn ($t) => str_contains($t, '60ダメージ'), $texts));
        $this->assertSame($before - 60, (int) UserCharacter::query()->sum('hp'));
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function damageCases(): array
    {
        return [
            '1層 矢のトラップ' => [1, 'trap_arrow', '30ダメージ'],
            '2層 矢のトラップ' => [2, 'trap_arrow', '60ダメージ'],
            '1層 人喰い植物' => [1, 'man_eating_plant', '150ダメージ'],
            '2層 人喰い植物' => [2, 'man_eating_plant', '200ダメージ'],
            '1層 鉄砲水' => [1, 'flash_flood', '100ダメージ'],
            '2層 鉄砲水' => [2, 'flash_flood', '150ダメージ'],
            '1層 スフィンクス' => [1, 'sphinx_quiz', '100ダメージ'],
            '2層 スフィンクス' => [2, 'sphinx_quiz', '150ダメージ'],
            '1層 幽霊の取り憑き' => [1, 'ghost_possession', '10ダメージ'],
            '2層 幽霊の取り憑き' => [2, 'ghost_possession', '30ダメージ'],
            '1層 宝箱（トラップあり）' => [1, 'trapped_chest', '30ダメージ'],
            '2層 宝箱（トラップあり）' => [2, 'trapped_chest', '50ダメージ'],
            '1層 あやしい回復の泉（ハズレ）' => [1, 'suspicious_spring', '30ダメージ'],
            '2層 あやしい回復の泉（ハズレ）' => [2, 'suspicious_spring', '100ダメージ'],
            '3層 矢のトラップ' => [3, 'trap_arrow', '120ダメージ'],
            '3層 人喰い植物' => [3, 'man_eating_plant', '390ダメージ'],
            '3層 鉄砲水' => [3, 'flash_flood', '290ダメージ'],
            '3層 スフィンクス' => [3, 'sphinx_quiz', '290ダメージ'],
            '3層 幽霊の取り憑き' => [3, 'ghost_possession', '60ダメージ'],
            '3層 宝箱（トラップあり）' => [3, 'trapped_chest', '100ダメージ'],
            '3層 あやしい回復の泉（ハズレ）' => [3, 'suspicious_spring', '190ダメージ'],
        ];
    }

    #[DataProvider('damageCases')]
    public function test_failure_damage_shown_for_each_floor(int $floor, string $eventCode, string $expected): void
    {
        $this->enterFloor($floor);

        $texts = $this->runEvent($eventCode, statSuccess: false);

        $this->assertTrue(
            collect($texts)->contains(fn ($t) => str_contains($t, $expected)),
            "{$floor}層 {$eventCode}: {$expected} が出ない\n".implode("\n---\n", $texts),
        );
    }

    /**
     * @return array<string, array{int, string, int}>
     */
    public static function goldCases(): array
    {
        return [
            '1層 お金取得' => [1, 'gold_pickup', 50],
            '2層 お金取得' => [2, 'gold_pickup', 100],
            '1層 宝箱（トラップあり）' => [1, 'trapped_chest', 400],
            '2層 宝箱（トラップあり）' => [2, 'trapped_chest', 800],
            '1層 宝箱（無条件）' => [1, 'safe_chest', 200],
            '2層 宝箱（無条件）' => [2, 'safe_chest', 400],
            '3層 お金取得' => [3, 'gold_pickup', 200],
            '3層 宝箱（トラップあり）' => [3, 'trapped_chest', 1600],
            '3層 宝箱（無条件）' => [3, 'safe_chest', 800],
        ];
    }

    #[DataProvider('goldCases')]
    public function test_gold_reward_for_each_floor(int $floor, string $eventCode, int $gold): void
    {
        $this->enterFloor($floor);
        $before = (int) $this->demoUser()->gold;

        $texts = $this->runEvent($eventCode, statSuccess: true);

        $this->assertSame(
            1,
            collect($texts)->filter(fn ($t) => str_contains($t, "{$gold}ゴールド"))->count(),
            "{$gold}ゴールドの表示は 1 回だけ\n".implode("\n---\n", $texts),
        );
        $this->assertSame($before + $gold, (int) $this->demoUser()->fresh()->gold);
    }

    /**
     * @return array<string, array{int, string, string, string}>
     */
    public static function rewardItemCases(): array
    {
        return [
            '1層 宝箱（筋力判定）' => [1, 'treasure_chest', 'potion_hp_s', 'HP回復薬を手に入れた！'],
            '2層 宝箱（筋力判定）' => [2, 'treasure_chest', 'potion_hp_m', 'HP回復薬（中）を手に入れた！'],
            '1層 宝箱（トラップあり・HP）' => [1, 'trapped_chest_hp', 'potion_hp_s', 'HP回復薬を手に入れた！'],
            '2層 宝箱（トラップあり・HP）' => [2, 'trapped_chest_hp', 'potion_hp_m', 'HP回復薬（中）を手に入れた！'],
            '1層 宝箱（トラップあり・MP）' => [1, 'trapped_chest_mp', 'potion_mp_s', 'MP回復薬を手に入れた！'],
            '2層 宝箱（トラップあり・MP）' => [2, 'trapped_chest_mp', 'potion_mp_m', 'MP回復薬（中）を手に入れた！'],
            '1層 宝箱（無条件・HP）' => [1, 'safe_chest_hp', 'potion_hp_s', 'HP回復薬を手に入れた！'],
            '2層 宝箱（無条件・HP）' => [2, 'safe_chest_hp', 'potion_hp_m', 'HP回復薬（中）を手に入れた！'],
            '1層 宝箱（無条件・MP）' => [1, 'safe_chest_mp', 'potion_mp_s', 'MP回復薬を手に入れた！'],
            '2層 宝箱（無条件・MP）' => [2, 'safe_chest_mp', 'potion_mp_m', 'MP回復薬（中）を手に入れた！'],
            '1層 スフィンクス' => [1, 'sphinx_quiz', 'potion_hp_s', 'HP回復薬を手に入れた！'],
            '2層 スフィンクス' => [2, 'sphinx_quiz', 'potion_hp_m', 'HP回復薬（中）を手に入れた！'],
            '3層 宝箱（筋力判定）' => [3, 'treasure_chest', 'potion_hp_l', 'HP回復薬（強）を手に入れた！'],
            '3層 宝箱（トラップあり・HP）' => [3, 'trapped_chest_hp', 'potion_hp_l', 'HP回復薬（強）を手に入れた！'],
            '3層 宝箱（トラップあり・MP）' => [3, 'trapped_chest_mp', 'potion_mp_l', 'MP回復薬（強）を手に入れた！'],
            '3層 宝箱（無条件・HP）' => [3, 'safe_chest_hp', 'potion_hp_l', 'HP回復薬（強）を手に入れた！'],
            '3層 宝箱（無条件・MP）' => [3, 'safe_chest_mp', 'potion_mp_l', 'MP回復薬（強）を手に入れた！'],
            '3層 スフィンクス' => [3, 'sphinx_quiz', 'potion_hp_l', 'HP回復薬（強）を手に入れた！'],
        ];
    }

    #[DataProvider('rewardItemCases')]
    public function test_reward_item_and_its_message_for_each_floor(int $floor, string $scenario, string $itemCode, string $message): void
    {
        $this->enterFloor($floor);

        // chance_branch は test_roll（<= 成功率なら当たり）で出し分ける。判定は成功させる
        [$eventCode, $roll] = match ($scenario) {
            'treasure_chest' => ['treasure_chest', null],
            'trapped_chest_hp' => ['trapped_chest', 55],     // ゴールド(50%)を外し、アイテム 60% のうち HP 薬を当てる
            'trapped_chest_mp' => ['trapped_chest', 70],     // ゴールド(50%)もHP薬(60%)も外す
            'safe_chest_hp' => ['safe_chest', 55],           // ゴールド(50%)を外し、アイテム 60% を当てる
            'safe_chest_mp' => ['safe_chest', 90],           // ゴールドもHP薬も外す
            'sphinx_quiz' => ['sphinx_quiz', null],
        };
        if ($eventCode === 'trapped_chest') {
            // 解除の判定（知識）も同じ test_roll で決まるので、必ず通る（成功率 95%）ように知識を上げておく
            UserCharacter::query()->update(['know' => 100]);
        }
        $texts = $this->runEvent($eventCode, statSuccess: $roll === null ? true : null, roll: $roll);

        $this->assertSame(
            [$itemCode => 1],
            $this->inventory(),
            "{$floor}層 {$scenario}\n".implode("\n---\n", $texts),
        );
        $this->assertTrue(collect($texts)->contains(fn ($t) => $t === $message), "{$message} が出ない\n".implode("\n---\n", $texts));
    }

    public function test_every_reward_item_and_placeholder_points_at_an_existing_item(): void
    {
        $codes = \App\Models\ItemMaster::query()->pluck('code')->all();

        foreach (DungeonEventNode::query()->where('node_type', 'grant_item')->get() as $node) {
            $this->assertContains($node->stat_attr, $codes, "{$node->event_code}.{$node->node_key}");
            foreach ([1, 2, 3] as $floor) {
                $this->assertContains(
                    \App\Services\Dungeon\DungeonFloorConfig::rewardItemCode($floor, (string) $node->stat_attr),
                    $codes,
                    "{$floor}層 {$node->event_code}.{$node->node_key}",
                );
            }
        }

        foreach (DungeonEventNode::query()->where('text', 'like', '%{item:%')->get() as $node) {
            preg_match_all('/\{item:([a-z0-9_]+)\}/', (string) $node->text, $m);
            foreach ($m[1] as $code) {
                $this->assertContains($code, $codes, "{$node->event_code}.{$node->node_key} の {item:{$code}}");
            }
        }

        // 報酬の文面は、アイテム名の直書きではなく {item:コード} で書く（直書きだと 2 層でも小回復薬の名前が出てしまう）
        $this->assertSame(
            0,
            DungeonEventNode::query()->where('text', 'like', '%回復薬を手に入れた%')->count(),
        );
    }

    /**
     * @return array<string, int>
     */
    private function inventory(): array
    {
        return collect(app(\App\Services\Player\ItemInventoryService::class)->inventoryPayload($this->demoUser()))
            ->pluck('quantity', 'code')
            ->map(fn ($q) => (int) $q)
            ->all();
    }

    private function enterFloor(int $floor): void
    {
        UserDungeonProgress::query()->updateOrCreate(
            ['user_id' => $this->demoUser()->id],
            ['floor' => $floor, 'unlocked_floor' => $floor, 'step' => 0, 'in_dungeon' => false],
        );

        $this->postJson('/api/dungeon/enter', ['floor' => $floor])->assertOk();
    }

    private function demoUser(): User
    {
        return User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
    }

    /**
     * @return \Illuminate\Support\Collection<string, UserCharacter>
     */
    private function party(): \Illuminate\Support\Collection
    {
        return app(UserCharacterService::class)->partyInSlotOrder($this->demoUser());
    }

    /**
     * 文面に出ている「成功率：N%」の N を、出た順に返す。
     *
     * @param  list<string>  $texts
     * @return list<int>
     */
    private function shownRates(array $texts): array
    {
        preg_match_all('/成功率：(\d+)%/u', implode("\n", $texts), $matches);

        return array_map('intval', $matches[1]);
    }

    /**
     * イベントを最後まで進め（選択肢は先頭の人を選ぶ）、表示された行の文面をすべて返す。
     *
     * @return list<string>
     */
    private function runEvent(string $eventCode, ?bool $statSuccess, ?int $roll = null): array
    {
        config([
            'game.dungeon.test_event_code' => $eventCode,
            'game.dungeon.test_stat_success' => $statSuccess,
            'game.dungeon.test_roll' => $roll,
        ]);

        $response = $this->postJson('/api/dungeon/advance')->assertOk();
        $sessionId = $response->json('session_id');
        $texts = [];

        for ($i = 0; $i < 60; $i++) {
            foreach ($response->json('segment.lines') ?? [] as $line) {
                $texts[] = (string) ($line['text'] ?? '');
            }

            $kind = $response->json('segment.kind');
            if ($kind === 'complete') {
                return $texts;
            }

            $response = $kind === 'choice'
                ? $this->postJson('/api/dungeon/exploration/choose', [
                    'session_id' => $sessionId,
                    'slot_id' => $response->json('segment.options.0.slot_id'),
                ])->assertOk()
                : $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])->assertOk();
        }

        $this->fail("{$eventCode} を完了できませんでした");
    }
}
