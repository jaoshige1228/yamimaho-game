<?php

namespace App\Services\Dungeon;

use App\Models\DungeonEventNode;
use App\Services\MasterData\CsvMasterReader;

/**
 * 層ごとのイベント数値の上書き（dungeon_event_floor_overrides.csv）。
 *
 * イベントのノード（dungeon_event_nodes.csv）は全層で共有なので、2 層だけ成功率・ダメージ・ゴールドを
 * 変えたいときはここに 1 行足す。空のセルは「上書きしない」。
 *
 * - stat_multiplier … ノードの stat_multiplier を差し替える。判定ノード（stat_check / stat_check_each_ally）では
 *   その層で使う倍率そのもの（層補正 stat_multiplier_scale はかけない。小数可、成功率は切り上げ）。
 *   grant_gold ではゴールド額、chance_branch では成功率（%）
 * - fixed_damage … ノードの fixed_damage（ダメージ量）を差し替える
 */
class DungeonEventFloorOverrides
{
    /** @var array<string, array{stat_multiplier: int|float|null, fixed_damage: int|null}>|null */
    private ?array $rows = null;

    /**
     * 読み込んだノード（メモリ上のみ。保存はしない）に、その層向けの上書きを反映する。
     */
    public function apply(DungeonEventNode $node, int $floor): void
    {
        $override = $this->rows()[self::key($floor, (string) $node->event_code, (string) $node->node_key)] ?? null;
        if ($override === null) {
            return;
        }

        if ($override['stat_multiplier'] !== null) {
            $node->stat_multiplier = $override['stat_multiplier'];
            $node->statMultiplierOverridden = true;
        }
        if ($override['fixed_damage'] !== null) {
            $node->fixed_damage = $override['fixed_damage'];
        }
    }

    /**
     * @return list<array{floor: int, event_code: string, node_key: string, stat_multiplier: int|float|null, fixed_damage: int|null}>
     */
    public function all(): array
    {
        $all = [];
        foreach (CsvMasterReader::read('dungeon_event_floor_overrides.csv') as $row) {
            $all[] = [
                'floor' => (int) $row['floor'],
                'event_code' => (string) $row['event_code'],
                'node_key' => (string) $row['node_key'],
                'stat_multiplier' => self::number($row['stat_multiplier'] ?? ''),
                'fixed_damage' => ($damage = self::number($row['fixed_damage'] ?? '')) !== null ? (int) $damage : null,
            ];
        }

        return $all;
    }

    /**
     * @return array<string, array{stat_multiplier: int|float|null, fixed_damage: int|null}>
     */
    private function rows(): array
    {
        if ($this->rows === null) {
            $this->rows = [];
            foreach ($this->all() as $row) {
                $this->rows[self::key($row['floor'], $row['event_code'], $row['node_key'])] = [
                    'stat_multiplier' => $row['stat_multiplier'],
                    'fixed_damage' => $row['fixed_damage'],
                ];
            }
        }

        return $this->rows;
    }

    private static function key(int $floor, string $eventCode, string $nodeKey): string
    {
        return "{$floor}|{$eventCode}|{$nodeKey}";
    }

    private static function number(string $cell): int|float|null
    {
        $cell = trim($cell);
        if ($cell === '') {
            return null;
        }

        $value = (float) $cell;

        return floor($value) === $value ? (int) $value : $value;
    }
}
