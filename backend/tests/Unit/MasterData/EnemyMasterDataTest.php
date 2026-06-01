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
        $this->assertSame(13, EnemyMaster::query()->where('code', 'bat')->value('exp_reward'));
        $this->assertSame(1, EnemyMaster::query()->where('code', 'bat')->value('floor'));
    }
}
