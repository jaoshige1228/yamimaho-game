<?php

namespace Tests\Unit\MasterData;

use App\Services\MasterData\MasterDataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnemyMasterDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_enemy_masters_load_exp_reward_from_csv(): void
    {
        $provider = new MasterDataProvider;

        $bat = $provider->findEnemy('bat');
        $snake = $provider->findEnemy('snake');

        $this->assertSame(20, $bat['exp_reward']);
        $this->assertSame(35, $snake['exp_reward']);
    }
}
