<?php

namespace Tests\Unit\Dungeon;

use App\Models\User;
use App\Models\UserDungeonProgress;
use App\Services\Dungeon\DungeonAdvanceOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DungeonAdvanceOutcomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_roll_uses_four_outcome_buckets(): void
    {
        config([
            'game.dungeon.battle_encounter_rate' => 30,
            'game.dungeon.exploration_event_rate' => 55,
            'game.dungeon.dialogue_event_rate' => 10,
            'game.dungeon.flavor_narrative_rate' => 5,
        ]);

        $user = User::factory()->create();
        UserDungeonProgress::query()->create([
            'user_id' => $user->id,
            'step' => 0,
            'skip_battle_encounters' => false,
        ]);

        $service = new DungeonAdvanceOutcome;
        $counts = ['battle' => 0, 'exploration' => 0, 'dialogue' => 0, 'flavor' => 0];

        for ($i = 0; $i < 5000; $i++) {
            $counts[$service->roll($user)]++;
        }

        $this->assertGreaterThan(1000, $counts['battle']);
        $this->assertGreaterThan(2000, $counts['exploration']);
        $this->assertGreaterThan(200, $counts['dialogue']);
        $this->assertGreaterThan(100, $counts['flavor']);
    }

    public function test_skip_battles_redistributes_battle_rate_to_exploration(): void
    {
        config([
            'game.dungeon.battle_encounter_rate' => 30,
            'game.dungeon.exploration_event_rate' => 55,
            'game.dungeon.dialogue_event_rate' => 10,
            'game.dungeon.flavor_narrative_rate' => 5,
        ]);

        $user = User::factory()->create();
        UserDungeonProgress::query()->create([
            'user_id' => $user->id,
            'step' => 0,
            'skip_battle_encounters' => true,
        ]);

        $service = new DungeonAdvanceOutcome;

        $counts = ['exploration' => 0, 'flavor' => 0, 'battle' => 0, 'dialogue' => 0];
        for ($i = 0; $i < 2000; $i++) {
            $counts[$service->roll($user)]++;
        }

        $this->assertSame(0, $counts['battle']);
        $this->assertGreaterThan($counts['dialogue'], $counts['exploration']);
        $this->assertGreaterThan(1500, $counts['exploration']);
        $this->assertGreaterThan(100, $counts['dialogue']);
        $this->assertGreaterThan(50, $counts['flavor']);
    }

    public function test_force_dialogue_events_always_returns_dialogue(): void
    {
        $user = User::factory()->create();
        UserDungeonProgress::query()->create([
            'user_id' => $user->id,
            'step' => 0,
            'force_dialogue_events' => true,
        ]);

        $service = new DungeonAdvanceOutcome;

        for ($i = 0; $i < 100; $i++) {
            $this->assertSame('dialogue', $service->roll($user));
        }
    }
}
