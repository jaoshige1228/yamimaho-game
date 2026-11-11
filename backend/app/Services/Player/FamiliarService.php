<?php

namespace App\Services\Player;

use App\Models\User;
use App\Models\UserFamiliar;
use Illuminate\Support\Facades\DB;

/**
 * 使い魔のレベルと魔石。魔石は 1 つ 100G で、与えた数に応じてレベルが上がる（config/familiar.php）。
 */
class FamiliarService
{
    public function __construct(
        private readonly PartyGoldService $gold = new PartyGoldService,
    ) {}

    /**
     * 戦闘用。使い魔のレベル（0 = 支援なし）。
     */
    public function levelFor(User $user): int
    {
        return (int) (UserFamiliar::query()->where('user_id', $user->id)->value('level') ?? 0);
    }

    /**
     * 使い魔ページ用。説明は解放済みのレベルだけ返す（未解放の中身は画面に出さない）。
     *
     * @return array<string, mixed>
     */
    public function statusFor(User $user): array
    {
        // 所持金は、ほかの処理で変わっていても最新の値を見る
        $user->refresh();
        $familiar = UserFamiliar::query()->where('user_id', $user->id)->first();
        $level = (int) ($familiar?->level ?? 0);
        $maxLevel = (int) config('familiar.max_level');
        $cost = (int) config('familiar.feed_cost');
        $gold = $this->gold->getGold($user);

        $abilities = [];
        for ($lv = 1; $lv <= $maxLevel; $lv++) {
            $unlocked = $lv <= $level;
            $abilities[] = [
                'level' => $lv,
                'unlocked' => $unlocked,
                'description' => $unlocked ? (string) config("familiar.levels.{$lv}.description") : null,
            ];
        }

        $progress = (int) ($familiar?->stones_progress ?? 0);
        $bulkCount = min((int) config('familiar.bulk_feed_count'), $this->stonesUntilMax($level, $progress));

        return [
            'level' => $level,
            'max_level' => $maxLevel,
            'max_reached' => $level >= $maxLevel,
            'stones_total' => (int) ($familiar?->stones_total ?? 0),
            // 次のレベルまであと何個か（最大レベルなら null）
            'stones_to_next' => $level < $maxLevel ? max(0, $this->stonesRequired($level + 1) - $progress) : null,
            'feed_cost' => $cost,
            'can_feed' => $level < $maxLevel && $gold >= $cost,
            // 「まとめて与える」ボタン用。最大レベルまでの残りが少ないときは、その数だけ
            'bulk_count' => $bulkCount,
            'bulk_cost' => $bulkCount * $cost,
            'can_feed_bulk' => $bulkCount > 0 && $gold >= $bulkCount * $cost,
            'gold' => $gold,
            'abilities' => $abilities,
        ];
    }

    /**
     * そのレベルに上がるのに必要な魔石の数（前のレベルから数えた数）。
     */
    private function stonesRequired(int $level): int
    {
        return (int) config("familiar.levels.{$level}.stones");
    }

    /**
     * 最大レベルになるまでに、あと何個の魔石が要るか。
     */
    private function stonesUntilMax(int $level, int $progress): int
    {
        $remaining = 0;
        for ($next = $level + 1; $next <= (int) config('familiar.max_level'); $next++) {
            $remaining += $this->stonesRequired($next);
        }

        return $level >= (int) config('familiar.max_level') ? 0 : max(0, $remaining - $progress);
    }

    /**
     * 魔石を与える（1 つ 100G）。必要数に達するたびにレベルアップする（まとめて与えると、何段か上がることもある）。
     * 最大レベルになったら、残りの魔石は与えない（その分のゴールドも使わない）。
     *
     * @param  int  $count  与える魔石の数（1 〜 `familiar.bulk_feed_count`）
     * @return array<string, mixed>
     */
    public function feed(User $user, int $count = 1): array
    {
        $count = max(1, min($count, (int) config('familiar.bulk_feed_count')));
        $maxLevel = (int) config('familiar.max_level');
        $cost = (int) config('familiar.feed_cost');
        $fed = 0;
        $startLevel = 0;
        $endLevel = 0;

        DB::transaction(function () use ($user, $count, $maxLevel, $cost, &$fed, &$startLevel, &$endLevel): void {
            $familiar = UserFamiliar::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['level' => 0, 'stones_total' => 0, 'stones_progress' => 0],
            );
            $familiar = UserFamiliar::query()->whereKey($familiar->id)->lockForUpdate()->firstOrFail();

            if ((int) $familiar->level >= $maxLevel) {
                throw new \InvalidArgumentException('使い魔はこれ以上強くなりません。');
            }

            $startLevel = (int) $familiar->level;
            $level = $startLevel;
            $progress = (int) $familiar->stones_progress;

            // 1 個ずつ与えたとして、どこまで進むかを先に数える（最大レベルで止める）
            while ($fed < $count && $level < $maxLevel) {
                $fed++;
                $progress++;
                if ($progress >= $this->stonesRequired($level + 1)) {
                    $level++;
                    $progress = 0;
                }
            }

            $this->gold->spendGold($user, $cost * $fed);

            $familiar->stones_total = (int) $familiar->stones_total + $fed;
            $familiar->stones_progress = $progress;
            $familiar->level = $level;
            $familiar->save();
            $endLevel = $level;
        });

        $status = $this->statusFor($user);
        $leveledUp = $endLevel > $startLevel;

        $message = $fed > 1 ? "魔石を{$fed}個与えた。" : '魔石を与えた。';
        if ($leveledUp) {
            $levelText = $endLevel - $startLevel > 1 ? "Lv{$startLevel}→Lv{$endLevel}" : "Lv{$endLevel}";
            $message = ($fed > 1 ? "魔石を{$fed}個与えた。" : '')."使い魔がレベルアップした！（{$levelText}）";
        }

        return [
            ...$status,
            'fed' => $fed,
            'leveled_up' => $leveledUp,
            'message' => $message,
        ];
    }

    /**
     * 記録のリセット用。使い魔のレベルと魔石数を消す。
     */
    public function reset(User $user): void
    {
        UserFamiliar::query()->where('user_id', $user->id)->delete();
    }
}
