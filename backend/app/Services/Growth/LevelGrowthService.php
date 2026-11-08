<?php

namespace App\Services\Growth;

use App\Models\CharacterMaster;
use App\Models\UserCharacter;
use App\Services\MasterData\LevelTableProvider;

class LevelGrowthService
{
    /** 1レベル上がるごとの上昇値（flat = 筋力・素早さ・知力・精神力・体力） */
    private const DEFAULT_GROWTH = ['hp' => 10, 'mp' => 3, 'mag' => 2, 'def' => 2, 'flat' => 1];

    /** 特定のレベルに上がるときだけ使う上昇値（キー = 到達レベル） */
    private const GROWTH_BY_REACHED_LEVEL = [
        4 => ['hp' => 20, 'mp' => 6, 'mag' => 4, 'def' => 4, 'flat' => 3],
        7 => [...self::DEFAULT_GROWTH, 'flat' => 3],
        18 => ['hp' => 50, 'mp' => 10, 'mag' => 8, 'def' => 8, 'flat' => 4],
    ];

    /** このレベル以上に上がるときは、常にこの上昇値を使う（GROWTH_BY_REACHED_LEVEL に指定のあるレベルを除く） */
    private const LATE_GROWTH_FROM_LEVEL = 12;

    private const LATE_GROWTH = ['hp' => 25, 'mp' => 5, 'mag' => 4, 'def' => 4, 'flat' => 2];

    /** このレベル以上に上がるときは、LATE_GROWTH の代わりにこの上昇値を使う（GROWTH_BY_REACHED_LEVEL に指定のあるレベルを除く） */
    private const VETERAN_GROWTH_FROM_LEVEL = 21;

    private const VETERAN_GROWTH = ['hp' => 40, 'mp' => 6, 'mag' => 6, 'def' => 6, 'flat' => 3];

    /** @var list<string> */
    private const FLAT_STATS = ['str', 'spd', 'know', 'spirit', 'vit'];

    public function __construct(
        private readonly LevelTableProvider $levels = new LevelTableProvider,
    ) {}

    public function cumulativeExpForLevel(int $level): int
    {
        return $this->levels->cumulativeExpForLevel($level);
    }

    /**
     * @return array{hp: int, mp: int, str: int, mag: int, def: int, spd: int, know: int, spirit: int, vit: int}
     */
    public function statsForLevel(CharacterMaster $master, int $level): array
    {
        $level = max(1, min($level, $this->levels->maxLevel()));

        $stats = [
            'hp' => $master->hp,
            'mp' => $master->mp,
            'str' => $master->str,
            'mag' => $master->mag,
            'def' => $master->def,
            'spd' => $master->spd,
            'know' => $master->know,
            'spirit' => $master->spirit,
            'vit' => $master->vit,
        ];

        for ($reached = 2; $reached <= $level; $reached++) {
            $this->applyOneLevelGrowth($stats, $reached);
        }

        return $stats;
    }

    /**
     * プレイヤーのキャラの最大ステータス。statsForLevel に、イベント報酬などで加算された最大MP（max_mp_bonus）を足したもの。
     *
     * @return array{hp: int, mp: int, str: int, mag: int, def: int, spd: int, know: int, spirit: int, vit: int}
     */
    public function statsForCharacter(UserCharacter $character): array
    {
        $stats = $this->statsForLevel($character->characterMaster, (int) $character->level);
        $stats['mp'] += max(0, (int) $character->max_mp_bonus);

        return $stats;
    }

    /**
     * @param  array{hp: int, mp: int, str: int, mag: int, def: int, spd: int, know: int, spirit: int, vit: int}  $stats
     * @param  int  $reachedLevel  この成長で到達するレベル
     */
    private function applyOneLevelGrowth(array &$stats, int $reachedLevel): void
    {
        $growth = $this->growthForReachedLevel($reachedLevel);

        $stats['hp'] += $growth['hp'];
        $stats['mp'] += $growth['mp'];

        $stats['mag'] += $growth['mag'];
        $stats['def'] += $growth['def'];

        foreach (self::FLAT_STATS as $key) {
            $stats[$key] += $growth['flat'];
        }
    }

    /**
     * @return array{hp: int, mp: int, mag: int, def: int, flat: int}
     */
    private function growthForReachedLevel(int $reachedLevel): array
    {
        // レベル個別の指定を最優先（Lv12 以降でも Lv18 だけ別の値、など）
        if (isset(self::GROWTH_BY_REACHED_LEVEL[$reachedLevel])) {
            return self::GROWTH_BY_REACHED_LEVEL[$reachedLevel];
        }

        if ($reachedLevel >= self::VETERAN_GROWTH_FROM_LEVEL) {
            return self::VETERAN_GROWTH;
        }

        return $reachedLevel >= self::LATE_GROWTH_FROM_LEVEL ? self::LATE_GROWTH : self::DEFAULT_GROWTH;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function grantExp(UserCharacter $character, int $amount): array
    {
        if ($amount <= 0) {
            return [];
        }

        $character->exp += $amount;
        $events = [];
        $master = $character->characterMaster;
        $maxLevel = $this->levels->maxLevel();

        while ($character->level < $maxLevel) {
            $nextLevel = $character->level + 1;
            if ($character->exp < $this->levels->cumulativeExpForLevel($nextLevel)) {
                break;
            }

            $before = $this->statsForCharacter($character);
            $character->level = $nextLevel;
            $after = $this->statsForCharacter($character);

            $character->hp = min($after['hp'], $character->hp + max(0, $after['hp'] - $before['hp']));
            $character->mp = min($after['mp'], $character->mp + max(0, $after['mp'] - $before['mp']));
            $character->str = $after['str'];
            $character->mag = $after['mag'];
            $character->def = $after['def'];
            $character->spd = $after['spd'];
            $character->know = $after['know'];
            $character->spirit = $after['spirit'];
            $character->vit = $after['vit'];

            $events[] = [
                'type' => 'level_up',
                'slot_id' => $this->slotIdForCharacter($character),
                'name' => $master->name,
                'level' => $character->level,
            ];
        }

        return $events;
    }

    private function slotIdForCharacter(UserCharacter $character): ?string
    {
        $code = $character->characterMaster->code;
        foreach (config('game.party_slots', []) as $slotId => $masterCode) {
            if ($masterCode === $code) {
                return $slotId;
            }
        }

        return null;
    }
}
