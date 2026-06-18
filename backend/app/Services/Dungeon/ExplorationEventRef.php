<?php

namespace App\Services\Dungeon;

final class ExplorationEventRef
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $startNodeKey,
        public readonly bool $skipEpilogue,
        public readonly bool $isDialogueOnly,
    ) {}
}
