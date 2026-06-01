<?php

namespace App\Services\Dungeon;

class DungeonEncounterCatalog
{
    public function __construct(
        private readonly DungeonStepEncounterBuilder $builder = new DungeonStepEncounterBuilder,
    ) {}

    /**
     * @return array{enemies: list<array<string, string>>, boss: bool}
     */
    public function buildEncounter(int $floor, int $step): array
    {
        $boss = DungeonFloorConfig::isBossStep($floor, $step);
        $enemies = $this->builder->build($floor, $step);

        return [
            'enemies' => $enemies,
            'boss' => $boss,
        ];
    }
}
