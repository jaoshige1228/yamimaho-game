<?php

namespace App\Services\Dungeon;

use App\Models\DungeonDialogueEventMaster;
use App\Models\DungeonEventMaster;
use App\Models\DungeonEventNode;

class DungeonEventCatalog
{
    public function pickRandomEventCode(int $floor): string
    {
        return $this->pickFromPool($this->mechanicalEventPool($floor), $floor, 'mechanical');
    }

    public function pickRandomDialogueEventCode(int $floor): string
    {
        return $this->pickFromPool($this->dialogueEventPool($floor), $floor, 'dialogue');
    }

    /**
     * @param  list<array{code: string, weight: int}>  $pool
     */
    private function pickFromPool(array $pool, int $floor, string $kind): string
    {
        if ($pool === []) {
            throw new \RuntimeException("No dungeon {$kind} events configured for floor {$floor}.");
        }

        $total = array_sum(array_column($pool, 'weight'));
        $roll = random_int(1, max(1, $total));
        $cursor = 0;

        foreach ($pool as $entry) {
            $cursor += $entry['weight'];
            if ($roll <= $cursor) {
                return $entry['code'];
            }
        }

        return $pool[array_key_last($pool)]['code'];
    }

    public function resolveEvent(int $floor, string $code): ExplorationEventRef
    {
        $mechanical = DungeonEventMaster::query()
            ->where('floor', $floor)
            ->where('code', $code)
            ->first();

        if ($mechanical !== null) {
            return new ExplorationEventRef(
                code: (string) $mechanical->code,
                name: (string) $mechanical->name,
                startNodeKey: (string) $mechanical->start_node_key,
                skipEpilogue: (bool) $mechanical->skip_epilogue,
                isDialogueOnly: false,
            );
        }

        $dialogue = DungeonDialogueEventMaster::query()
            ->where('floor', $floor)
            ->where('code', $code)
            ->first();

        if ($dialogue !== null) {
            return new ExplorationEventRef(
                code: (string) $dialogue->code,
                name: (string) $dialogue->name,
                startNodeKey: (string) $dialogue->start_node_key,
                skipEpilogue: true,
                isDialogueOnly: true,
            );
        }

        throw new \InvalidArgumentException("Unknown dungeon event: {$code} (floor {$floor})");
    }

    /**
     * @deprecated Use resolveEvent() instead.
     */
    public function findEvent(int $floor, string $code): DungeonEventMaster
    {
        $event = DungeonEventMaster::query()
            ->where('floor', $floor)
            ->where('code', $code)
            ->first();
        if ($event === null) {
            throw new \InvalidArgumentException("Unknown dungeon event: {$code} (floor {$floor})");
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

    /**
     * @return list<array{code: string, weight: int}>
     */
    private function mechanicalEventPool(int $floor): array
    {
        $pool = [];

        foreach (DungeonEventMaster::query()->where('floor', $floor)->get() as $event) {
            $pool[] = [
                'code' => (string) $event->code,
                'weight' => (int) $event->weight,
            ];
        }

        return $pool;
    }

    /**
     * @return list<array{code: string, weight: int}>
     */
    private function dialogueEventPool(int $floor): array
    {
        $pool = [];

        foreach (DungeonDialogueEventMaster::query()->where('floor', $floor)->get() as $event) {
            $pool[] = [
                'code' => (string) $event->code,
                'weight' => (int) $event->weight,
            ];
        }

        return $pool;
    }
}
