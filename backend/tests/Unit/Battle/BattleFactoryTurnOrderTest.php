<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\BattleFactory;
use Tests\TestCase;

class BattleFactoryTurnOrderTest extends TestCase
{
    public function test_turn_order_sorts_by_spd_descending(): void
    {
        $factory = new BattleFactory;
        $units = [
            'pc1' => ['spd' => 12, 'alive' => true],
            'pc2' => ['spd' => 11, 'alive' => true],
            'pc3' => ['spd' => 13, 'alive' => true],
            'enemy_1' => ['spd' => 8, 'alive' => true],
        ];

        $order = $factory->buildTurnOrder($units);

        $this->assertSame(['pc3', 'pc1', 'pc2', 'enemy_1'], $order);
    }
}
