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

        return [
            'level' => $level,
            'max_level' => $maxLevel,
            'max_reached' => $level >= $maxLevel,
            'stones_total' => (int) ($familiar?->stones_total ?? 0),
            'feed_cost' => $cost,
            'can_feed' => $level < $maxLevel && $gold >= $cost,
            'gold' => $gold,
            'abilities' => $abilities,
        ];
    }

    /**
     * 魔石を 1 つ与える（100G）。必要数に達したらレベルアップする。
     *
     * @return array<string, mixed>
     */
    public function feed(User $user): array
    {
        $leveledUp = false;

        DB::transaction(function () use ($user, &$leveledUp): void {
            $familiar = UserFamiliar::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['level' => 0, 'stones_total' => 0, 'stones_progress' => 0],
            );
            $familiar = UserFamiliar::query()->whereKey($familiar->id)->lockForUpdate()->firstOrFail();

            if ((int) $familiar->level >= (int) config('familiar.max_level')) {
                throw new \InvalidArgumentException('使い魔はこれ以上強くなりません。');
            }

            $this->gold->spendGold($user, (int) config('familiar.feed_cost'));

            $familiar->stones_total = (int) $familiar->stones_total + 1;
            $familiar->stones_progress = (int) $familiar->stones_progress + 1;

            $required = (int) config('familiar.levels.'.((int) $familiar->level + 1).'.stones');
            if ($familiar->stones_progress >= $required) {
                $familiar->level = (int) $familiar->level + 1;
                $familiar->stones_progress = 0;
                $leveledUp = true;
            }

            $familiar->save();
        });

        $status = $this->statusFor($user);

        return [
            ...$status,
            'leveled_up' => $leveledUp,
            'message' => $leveledUp
                ? "使い魔がレベルアップした！（Lv{$status['level']}）"
                : '魔石を与えた。',
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
