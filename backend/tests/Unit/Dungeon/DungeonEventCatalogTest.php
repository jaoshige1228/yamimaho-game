<?php

namespace Tests\Unit\Dungeon;

use App\Services\Dungeon\DungeonEventCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DungeonEventCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
    }

    public function test_resolve_event_returns_dialogue_only_ref_without_epilogue(): void
    {
        $catalog = new DungeonEventCatalog;

        $event = $catalog->resolveEvent(1, 'stumble_near_fall');

        $this->assertTrue($event->isDialogueOnly);
        $this->assertTrue($event->skipEpilogue);
        $this->assertSame('line1', $event->startNodeKey);
    }

    public function test_resolve_event_returns_mechanical_ref_with_epilogue(): void
    {
        $catalog = new DungeonEventCatalog;

        $event = $catalog->resolveEvent(1, 'trap_arrow');

        $this->assertFalse($event->isDialogueOnly);
        $this->assertFalse($event->skipEpilogue);
    }

    public function test_pick_random_event_code_uses_mechanical_events_only(): void
    {
        $catalog = new DungeonEventCatalog;
        $codes = [];

        for ($i = 0; $i < 80; $i++) {
            $codes[] = $catalog->pickRandomEventCode(1);
        }

        $unique = array_unique($codes);
        $this->assertContains('trap_arrow', $unique);
        $this->assertNotContains('stumble_near_fall', $unique);
        $this->assertNotContains('maj_umai_leaf', $unique);
    }

    public function test_mysterious_presence_weight_is_16_after_every_floor_is_cleared(): void
    {
        $catalog = new DungeonEventCatalog;

        // 踏破前の重み（dungeon_events.csv）は、1・2 層が 9、3 層が 8。全層を踏破したあとは、全層で 16
        $before = [1 => 9, 2 => 9, 3 => 8];
        foreach ([1, 2, 3] as $floor) {
            $weightsBefore = $catalog->mechanicalEventWeights($floor);
            $weightsAfter = $catalog->mechanicalEventWeights($floor, true);

            $this->assertSame($before[$floor], $weightsBefore['mysterious_presence'], "{$floor}層 踏破前");
            $this->assertSame(16, $weightsAfter['mysterious_presence'], "{$floor}層 全層踏破後");
            // ほかのイベントの重みは変わらない
            $this->assertSame(
                array_diff_key($weightsBefore, ['mysterious_presence' => 1]),
                array_diff_key($weightsAfter, ['mysterious_presence' => 1]),
            );
        }

        // 重みの合計: 1 層は 94 → 101（謎の気配 9 → 16）。2 層は「ステラ登場」（重み 10）が加わって 104 → 111、3 層は謎の気配が 8・あやしい回復の泉が 9 で 104 → 112
        $this->assertSame([94, 104, 104], array_map(fn (int $f) => array_sum($catalog->mechanicalEventWeights($f)), [1, 2, 3]));
        $this->assertSame([101, 111, 112], array_map(fn (int $f) => array_sum($catalog->mechanicalEventWeights($f, true)), [1, 2, 3]));
    }

    public function test_pick_random_dialogue_event_code_uses_dialogue_events_only(): void
    {
        $catalog = new DungeonEventCatalog;
        $codes = [];

        for ($i = 0; $i < 40; $i++) {
            $codes[] = $catalog->pickRandomDialogueEventCode(1);
        }

        $unique = array_unique($codes);
        $this->assertContains('stumble_near_fall', $unique);
        $this->assertContains('maj_umai_leaf', $unique);
        $this->assertContains('talent_man', $unique);
        $this->assertContains('music_talk', $unique);
        $this->assertNotContains('trap_arrow', $unique);
    }
}
