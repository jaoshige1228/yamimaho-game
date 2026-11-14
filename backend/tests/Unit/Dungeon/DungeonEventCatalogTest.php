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

    public function test_mysterious_presence_weight_doubles_after_every_floor_is_cleared(): void
    {
        $catalog = new DungeonEventCatalog;

        foreach ([1, 2, 3] as $floor) {
            $before = $catalog->mechanicalEventWeights($floor);
            $after = $catalog->mechanicalEventWeights($floor, true);

            $this->assertSame(8, $before['mysterious_presence'], "{$floor}層 踏破前");
            $this->assertSame(16, $after['mysterious_presence'], "{$floor}層 全層踏破後");
            // ほかのイベントの重みは変わらない
            $this->assertSame(
                array_diff_key($before, ['mysterious_presence' => 1]),
                array_diff_key($after, ['mysterious_presence' => 1]),
            );
        }

        // 重みの合計は、1 層が 94 → 102（探索イベントの 8/94 → 16/102）。2・3 層は「ステラ登場」（重み 5）が加わって 99 → 107
        $this->assertSame([94, 99, 99], array_map(fn (int $f) => array_sum($catalog->mechanicalEventWeights($f)), [1, 2, 3]));
        $this->assertSame([102, 107, 107], array_map(fn (int $f) => array_sum($catalog->mechanicalEventWeights($f, true)), [1, 2, 3]));
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
