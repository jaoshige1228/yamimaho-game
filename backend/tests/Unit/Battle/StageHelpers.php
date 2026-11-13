<?php

namespace Tests\Unit\Battle;

/**
 * 防御・攻撃の段階（ユニットの `stages`）をテストで読むための小さな道具。
 */
trait StageHelpers
{
    /**
     * 付いている効果の段階の合計（丸める前の値）。`stages` が無ければ 0。
     *
     * @param  array<string, mixed>  $unit
     */
    private function stageSum(array $unit, string $stat): int
    {
        $sum = 0;
        foreach ($unit['stages'] ?? [] as $entry) {
            if (($entry['stat'] ?? '') === $stat) {
                $sum += (int) $entry['amount'];
            }
        }

        return $sum;
    }

    /**
     * 防御・攻撃の効果を1つ作る（手組みのユニットに入れる用）。
     *
     * @return array{stat: string, amount: int, turns: int, source: string}
     */
    private function stageEntry(string $stat, int $amount, int $turns = 3, string $source = 'test'): array
    {
        return ['stat' => $stat, 'amount' => $amount, 'turns' => $turns, 'source' => $source];
    }
}
