<?php

namespace App\Services\Player;

use App\Models\User;

class PartyGoldService
{
    public function getGold(User $user): int
    {
        return max(0, (int) ($user->gold ?? 0));
    }

    public function setGold(User $user, int $gold): int
    {
        $user->gold = max(0, $gold);
        $user->save();

        return (int) $user->gold;
    }

    public function addGold(User $user, int $amount): int
    {
        if ($amount <= 0) {
            return $this->getGold($user);
        }

        return $this->setGold($user, $this->getGold($user) + $amount);
    }

    public function spendGold(User $user, int $amount): int
    {
        if ($amount <= 0) {
            return $this->getGold($user);
        }

        $user->refresh();
        $current = $this->getGold($user);
        if ($current < $amount) {
            throw new \InvalidArgumentException('ゴールドが足りません。');
        }

        return $this->setGold($user, $current - $amount);
    }

    public function resetGold(User $user): void
    {
        $this->setGold($user, 0);
    }

    /**
     * 撤退ペナルティ: 所持金を 0.8 倍（端数切り上げ）にする。
     */
    public function applyRetreatPenalty(User $user): int
    {
        $current = $this->getGold($user);
        $next = (int) ceil($current * 0.8);

        return $this->setGold($user, $next);
    }
}
