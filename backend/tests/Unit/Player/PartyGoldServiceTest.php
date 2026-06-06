<?php

namespace Tests\Unit\Player;

use App\Models\User;
use App\Services\Player\PartyGoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartyGoldServiceTest extends TestCase
{
    use RefreshDatabase;

    private PartyGoldService $gold;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gold = new PartyGoldService;
    }

    public function test_initial_gold_is_zero(): void
    {
        $user = User::factory()->create();

        $this->assertSame(0, $this->gold->getGold($user));
    }

    public function test_retreat_penalty_applies_eighty_percent_ceil(): void
    {
        $user = User::factory()->create(['gold' => 100]);
        $this->assertSame(80, $this->gold->applyRetreatPenalty($user->fresh()));

        $user->gold = 99;
        $user->save();
        $this->assertSame(80, $this->gold->applyRetreatPenalty($user->fresh()));

        $user->gold = 1;
        $user->save();
        $this->assertSame(1, $this->gold->applyRetreatPenalty($user->fresh()));
    }
}
