<?php

namespace Tests\Unit\MasterData;

use App\Models\EnemyMaster;
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

        $this->assertSame(13, $bat['exp_reward']);
        $this->assertSame(26, $snake['exp_reward']);
    }

    public function test_enemy_masters_load_exp_reward_from_db(): void
    {
        $this->seed(\Database\Seeders\MasterDataSeeder::class);

        $provider = new MasterDataProvider;
        $bat = $provider->findEnemy('bat');

        $this->assertSame(13, $bat['exp_reward']);
        $this->assertSame(50, $bat['hp']);
        $this->assertSame(1, $bat['floor']);
        $this->assertSame(
            13,
            EnemyMaster::query()->where('floor', 1)->where('code', 'bat')->value('exp_reward'),
        );
    }

    public function test_enemy_masters_are_separated_by_floor(): void
    {
        $this->seed(\Database\Seeders\MasterDataSeeder::class);

        $provider = new MasterDataProvider;
        $batFloor1 = $provider->findEnemy('bat', 1);
        $batFloor2 = $provider->findEnemy('bat', 2);

        $this->assertSame(50, $batFloor1['hp']);
        $this->assertSame(400, $batFloor2['hp']);
        $this->assertSame(13, $batFloor1['exp_reward']);
        $this->assertSame(200, $batFloor2['exp_reward']);
    }
}
