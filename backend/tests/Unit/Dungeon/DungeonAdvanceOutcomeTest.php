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

    public function test_skip_battles_redistributes_battle_rate_to_exploration(): void
    {
        $user = User::factory()->create();
        UserDungeonProgress::query()->create([
            'user_id' => $user->id,
            'step' => 0,
            'skip_battle_encounters' => true,
        ]);

        $service = new DungeonAdvanceOutcome;

        $counts = ['exploration' => 0, 'flavor' => 0, 'battle' => 0];
        for ($i = 0; $i < 1000; $i++) {
            $counts[$service->roll($user)]++;
        }

        $this->assertSame(0, $counts['battle']);
        $this->assertGreaterThan($counts['flavor'], $counts['exploration']);
        $this->assertGreaterThan(800, $counts['exploration']);
        $this->assertGreaterThan(50, $counts['flavor']);
    }
}
