<?php

namespace App\Services\MasterData;

use App\Models\LevelMaster;
use Illuminate\Support\Facades\Schema;

class LevelTableProvider
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $cache = null;

    /**
     * @return array<string, mixed>
     */
    public function findLevel(int $level): array
    {
        $all = $this->levels();

        if (! isset($all[$level])) {
            throw new \InvalidArgumentException("Level master not found: {$level}");
        }

        return $all[$level];
    }

    /**
     * 指定レベル到達に必要な累計 EXP（Lv1 は 0）。
     */
    public function cumulativeExpForLevel(int $level): int
    {
        if ($level <= 1) {
            return 0;
        }

        return (int) $this->findLevel($level - 1)['exp_to_next'];
    }

    /**
     * CSV 上の exp_to_next（そのレベルから次へ上がるまでの累計 EXP 閾値）。
     */
    public function expToNextLevel(int $level): int
    {
        return (int) $this->findLevel($level)['exp_to_next'];
    }

    public function maxLevel(): int
    {
        return max(array_keys($this->levels()));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function levels(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        if ($this->hasLevelMastersTable() && LevelMaster::query()->exists()) {
            $this->cache = LevelMaster::query()
                ->orderBy('level')
                ->get()
                ->keyBy('level')
                ->map(fn (LevelMaster $row) => $this->normalizeLevel($row->toArray()))
                ->all();
        } else {
            $this->cache = $this->loadFromCsv();
        }

        return $this->cache;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadFromCsv(): array
    {
        $levels = [];
        foreach (CsvMasterReader::read('level_masters.csv') as $row) {
            $level = (int) $row['level'];
            $levels[$level] = $this->normalizeLevel($row);
        }

        return $levels;
    }

    private function hasLevelMastersTable(): bool
    {
        return Schema::hasTable('level_masters');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeLevel(array $row): array
    {
        return [
            'level' => (int) $row['level'],
            'exp_to_next' => (int) $row['exp_to_next'],
            'hp_growth' => (float) $row['hp_growth'],
            'mp_growth' => (float) $row['mp_growth'],
            'stat_growth' => (float) $row['stat_growth'],
        ];
    }
}
