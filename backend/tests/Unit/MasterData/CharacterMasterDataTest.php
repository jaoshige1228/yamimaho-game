<?php

namespace Tests\Unit\MasterData;

use App\Models\CharacterMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterMasterDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_loads_second_name(): void
    {
        $this->seed(\Database\Seeders\MasterDataSeeder::class);

        $expected = [
            'pc1' => 'シナノガワ',
            'pc2' => 'カレイド',
            'pc3' => 'ウメキダ',
            'pc4' => 'アカリヤ',
        ];

        foreach ($expected as $code => $secondName) {
            $master = CharacterMaster::query()->where('code', $code)->firstOrFail();
            $this->assertSame($secondName, $master->second_name);
        }
    }
}
