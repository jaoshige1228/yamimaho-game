<?php

namespace Tests\Unit\Dungeon;

use App\Services\Dungeon\DungeonFloorConfig;
use Tests\TestCase;

class DungeonFloorConfigTest extends TestCase
{
    public function test_floor_two_mid_boss_step_is_20(): void
    {
        $this->assertSame(20, DungeonFloorConfig::midBossStep(2));
        $this->assertTrue(DungeonFloorConfig::isMidBossStep(2, 20));
        $this->assertFalse(DungeonFloorConfig::isMidBossStep(2, 19));
        $this->assertFalse(DungeonFloorConfig::isMidBossStep(1, 20));
    }

    public function test_floor_two_mid_boss_event_and_enemies(): void
    {
        $this->assertSame('floor2_mid_boss', DungeonFloorConfig::midBossEventCode(2));
        $this->assertSame(['npc_kasu', 'npc_gusu'], DungeonFloorConfig::midBossEnemyCodes(2));
    }
}
