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
