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
        /** 1 回の挑戦で最大 1 回しか出ないイベントか（dungeon_events.csv の once_per_run） */
        public readonly bool $oncePerRun = false,
    ) {}
}
