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

        $kappa = $provider->findEnemy('kappa');
        $kappa2 = $provider->findEnemy('kappa2');

        $this->assertSame(13, $kappa['exp_reward']);
        $this->assertSame(26, $kappa2['exp_reward']);
    }

    public function test_enemy_masters_load_exp_reward_from_db(): void
    {
        $this->seed(\Database\Seeders\MasterDataSeeder::class);

        $provider = new MasterDataProvider;
        $kappa = $provider->findEnemy('kappa');

        $this->assertSame(13, $kappa['exp_reward']);
        $this->assertSame(13, EnemyMaster::query()->where('code', 'kappa')->value('exp_reward'));
    }
}
