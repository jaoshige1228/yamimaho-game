<?php

namespace App\Services\Dungeon;

use App\Models\BattleState;
use App\Models\DungeonExplorationSession;
use App\Models\User;
use App\Services\Dungeon\Exploration\ExplorationBattleLauncher;

/**
 * 層選択画面の「時空の裂け目へ」。一度、探索イベントの裂け目でボス戦（4 人の魔法使い）が始まったら、
 * ダンジョンに潜らずに、そのボス戦だけを、何度でも直接始められる。
 */
class DungeonRiftService
{
    public function __construct(
        private readonly DungeonProgressService $progress = new DungeonProgressService,
        private readonly ExplorationBattleLauncher $launcher = new ExplorationBattleLauncher,
    ) {}

    /**
     * 裂け目のボス戦を始める。戦闘の内容・勝ったあとの扱い（待機画面に戻って全回復）は、探索イベントの裂け目と同じ。
     *
     * @return array<string, mixed>  battle_id・state・events などを持つ、探索イベントの戦闘開始と同じ形
     *
     * @throws \InvalidArgumentException  まだ裂け目に行ったことがない（403）
     * @throws \RuntimeException  いま戦闘中・ダンジョンの探索中（409）
     */
    public function startBattle(User $user): array
    {
        $battle = DungeonFloorConfig::eventBattle('rift');
        if ($battle === null || ! $this->progress->hasReachedRift($user)) {
            throw new \InvalidArgumentException('まだ時空の裂け目へは行けません。');
        }

        $active = BattleState::query()->where('user_id', $user->id)->where('status', 'active')->exists();
        $exploring = $this->progress->isInDungeon($user)
            || DungeonExplorationSession::query()->where('user_id', $user->id)->exists();
        if ($active || $exploring) {
            throw new \RuntimeException('いまは時空の裂け目へ行けません。');
        }

        // 裂け目の入口は 3 層を踏破したあと。勝ったときは、最終層のボスを倒したときと同じ後始末（待機画面に戻り、全回復）になる
        return $this->launcher->startEventBattle(
            $user,
            DungeonFloorConfig::maxFloor(),
            0,
            $battle['enemy_codes'],
            ExplorationBattleLauncher::RIFT_META,
        );
    }
}
