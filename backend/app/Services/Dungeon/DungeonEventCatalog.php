<?php

namespace App\Services\Dungeon;

use App\Models\DungeonEventMaster;
use App\Models\DungeonEventNode;

class DungeonEventCatalog
{
    public function pickRandomEventCode(int $floor): string
    {
        $events = DungeonEventMaster::query()->where('floor', $floor)->get();
        if ($events->isEmpty()) {
            throw new \RuntimeException("No dungeon events configured for floor {$floor}.");
        }

        $total = $events->sum('weight');
        $roll = random_int(1, max(1, $total));
        $cursor = 0;

        foreach ($events as $event) {
            $cursor += (int) $event->weight;
            if ($roll <= $cursor) {
                return (string) $event->code;
            }
        }

        return (string) $events->last()->code;
    }

    public function findEvent(string $code): DungeonEventMaster
    {
        $event = DungeonEventMaster::query()->where('code', $code)->first();
        if ($event === null) {
            throw new \InvalidArgumentException("Unknown dungeon event: {$code}");
        }

        return $event;
    }

    public function findNode(string $eventCode, string $nodeKey): DungeonEventNode
    {
        $node = DungeonEventNode::query()
            ->where('event_code', $eventCode)
            ->where('node_key', $nodeKey)
            ->first();

        if ($node === null) {
            throw new \InvalidArgumentException("Unknown node {$eventCode}.{$nodeKey}");
        }

        return $node;
    }
}
