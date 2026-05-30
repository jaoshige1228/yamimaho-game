<?php

namespace App\Services\Dungeon;

use App\Models\DungeonEncounterMaster;

class DungeonEncounterCatalog
{
    /**
     * @return array{enemies: list<array<string, string>>, boss: bool}
     */
    public function pickRandomEncounter(): array
    {
        $rows = DungeonEncounterMaster::query()->get();
        if ($rows->isEmpty()) {
            throw new \RuntimeException('No dungeon encounters configured.');
        }

        $total = $rows->sum('weight');
        $roll = random_int(1, max(1, $total));
        $cursor = 0;

        foreach ($rows as $row) {
            $cursor += (int) $row->weight;
            if ($roll <= $cursor) {
                return [
                    'enemies' => $row->enemies,
                    'boss' => (bool) $row->boss,
                ];
            }
        }

        $last = $rows->last();

        return [
            'enemies' => $last->enemies,
            'boss' => (bool) $last->boss,
        ];
    }
}
