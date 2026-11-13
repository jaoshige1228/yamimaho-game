<?php

namespace App\Services\Battle;

/**
 * 使い魔の支援行動の「判断」だけを行う（発動するか・何をするか・誰に対してか）。
 * ダメージや回復を実際に適用するのは BattleEngine。数値は config/familiar.php。
 *
 * 乱数はすべて $roll（fn(int $min, int $max): int）を通す。テストでは固定値を注入できる。
 */
class FamiliarSupport
{
    /** 行動の weight が config に無いときの値（config/familiar.php の actions.*.weight） */
    private const DEFAULT_WEIGHT = 10;

    /** @var (callable(int, int): int)|null */
    private $roll;

    /**
     * @param  (callable(int, int): int)|null  $roll  min〜max（両端を含む）の整数を返す
     */
    public function __construct(?callable $roll = null)
    {
        $this->roll = $roll;
    }

    /**
     * 戦闘に設定された使い魔のレベル（state.meta.familiar.level）。設定がなければ 0（支援なし）。
     *
     * @param  array<string, mixed>  $state
     */
    public function levelOf(array $state): int
    {
        return max(0, (int) ($state['meta']['familiar']['level'] ?? 0));
    }

    /**
     * レベルごとの発動確率（%）。「そのレベル以上」で最も大きいキーの値。Lv0 は 0。
     */
    public function rateFor(int $level): int
    {
        $rate = 0;
        $rates = (array) config('familiar.support_rate', []);
        ksort($rates);
        foreach ($rates as $fromLevel => $percent) {
            if ($level >= (int) $fromLevel) {
                $rate = (int) $percent;
            }
        }

        return $rate;
    }

    /**
     * 一周ごとの発動の抽選。
     */
    public function shouldTrigger(int $level): bool
    {
        $rate = $this->rateFor($level);

        return $rate > 0 && $this->roll(1, 100) <= $rate;
    }

    /**
     * パーティー全員（戦闘不能を含む）の魔力の平均。武器の魔力ボーナスは戦闘ユニットの mag に含まれている。
     *
     * @param  array<string, array<string, mixed>>  $units
     */
    public function averageMag(array $units): float
    {
        $mags = [];
        foreach ($units as $unit) {
            if (($unit['side'] ?? '') === 'ally') {
                $mags[] = (float) ($unit['mag'] ?? 0);
            }
        }

        return $mags === [] ? 0.0 : array_sum($mags) / count($mags);
    }

    /**
     * 行動の係数（ダメージ・回復）。Lv8 以降は強化版。
     */
    public function coefficientFor(string $action, int $level): float
    {
        $key = $level >= (int) config('familiar.upgrade_level') ? 'upgraded' : 'base';

        return (float) config("familiar.actions.{$action}.{$key}");
    }

    /**
     * 攻撃バフの種類（Lv8 以降は強化版）。config/battle.php の buffs のキー。
     */
    public function attackBuffKey(int $level): string
    {
        $key = $level >= (int) config('familiar.upgrade_level') ? 'upgraded' : 'base';

        return (string) config("familiar.actions.ally_attack_buff.{$key}");
    }

    /**
     * 今の状況で何をするかを決める。何もできなければ null。
     *
     * 1. Lv9 で戦闘不能の味方がいれば、必ず蘇生
     * 2. 攻撃バフが使える かつ 傷ついている味方がいない かつ 生存している味方の誰にもバフが付いていない → 必ず攻撃バフ
     * 3. それ以外は、解放済みで成立する行動から、weight の比で1つ選ぶ（回復は傷ついた味方がいるときだけ）。
     *    単体回復の対象は、傷ついた味方のうち HP の割合がいちばん低い1人
     *
     * @param  array<string, array<string, mixed>>  $units
     * @return array{action: string, kind: string, targets: list<string>, portrait: string}|null
     */
    public function decide(array $units, int $level): ?array
    {
        $enemies = $this->ids($units, 'enemy', fn (array $u) => (bool) ($u['alive'] ?? false));
        $aliveAllies = $this->ids($units, 'ally', fn (array $u) => (bool) ($u['alive'] ?? false));
        $fallenAllies = $this->ids($units, 'ally', fn (array $u) => ! ($u['alive'] ?? false));
        // 傷ついている = 生存していて HP が最大未満（戦闘不能は含めない）
        $woundedAllies = $this->ids($units, 'ally', fn (array $u) => ($u['alive'] ?? false) && (int) $u['hp'] < (int) $u['max_hp']);

        $actions = (array) config('familiar.actions');
        $unlocked = fn (string $name): bool => isset($actions[$name]) && $level >= (int) $actions[$name]['level'];

        $chosen = null;
        $targets = [];

        if ($unlocked('revive') && $fallenAllies !== []) {
            $chosen = 'revive';
            $targets = [$this->pick($fallenAllies)];
        } elseif ($unlocked('ally_attack_buff') && $aliveAllies !== [] && $woundedAllies === []
            && ! $this->anyHasBuff($units, $aliveAllies)) {
            $chosen = 'ally_attack_buff';
            $targets = $aliveAllies;
        } else {
            $options = [];
            foreach ($actions as $name => $action) {
                if (! $unlocked($name)) {
                    continue;
                }
                $ok = match ($action['kind']) {
                    'damage' => $enemies !== [],
                    'heal' => $woundedAllies !== [],
                    'buff' => $aliveAllies !== [],
                    default => false, // 蘇生は上の「必ず蘇生」でだけ使う
                };
                if ($ok) {
                    $options[] = $name;
                }
            }
            if ($options === []) {
                return null;
            }

            $chosen = $this->pickWeighted($options, $actions);
            $action = $actions[$chosen];
            $targets = match ($action['kind']) {
                'damage' => $action['scope'] === 'all' ? $enemies : [$this->pick($enemies)],
                'heal' => $action['scope'] === 'all' ? $aliveAllies : [$this->mostWounded($units, $woundedAllies)],
                default => $aliveAllies,
            };
        }

        return [
            'action' => $chosen,
            'kind' => (string) $actions[$chosen]['kind'],
            'targets' => $targets,
            'portrait' => (string) $this->pick((array) config('familiar.portraits')),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $units
     * @param  callable(array<string, mixed>): bool  $filter
     * @return list<string>
     */
    private function ids(array $units, string $side, callable $filter): array
    {
        $ids = [];
        foreach ($units as $id => $unit) {
            if (($unit['side'] ?? '') === $side && $filter($unit)) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, array<string, mixed>>  $units
     * @param  list<string>  $allyIds
     */
    private function anyHasBuff(array $units, array $allyIds): bool
    {
        foreach ($allyIds as $id) {
            // 使い魔の攻撃バフ（stages の source が familiar）が、まだ残っているか
            foreach ($units[$id]['stages'] ?? [] as $entry) {
                if (($entry['source'] ?? '') === BattleEngine::FAMILIAR_SOURCE && (int) ($entry['turns'] ?? 0) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 選択肢を、config/familiar.php の actions.*.weight の比で1つ選ぶ（weight が大きいほど選ばれやすい）。
     * 乱数は 1〜weight の合計の整数を1回だけ使い、選択肢の並び順に weight を数えていって、当たった行動を選ぶ。
     *
     * @param  list<string>  $names  選択肢（actions のキー）
     * @param  array<string, array<string, mixed>>  $actions
     */
    private function pickWeighted(array $names, array $actions): string
    {
        $names = array_values($names);
        $weights = array_map(
            fn (string $name): int => max(1, (int) ($actions[$name]['weight'] ?? self::DEFAULT_WEIGHT)),
            $names,
        );

        $point = $this->roll(1, array_sum($weights));
        foreach ($names as $i => $name) {
            $point -= $weights[$i];
            if ($point <= 0) {
                return $name;
            }
        }

        return $names[array_key_last($names)];
    }

    /**
     * 傷ついた味方のうち、いちばん傷ついている1人（HP の割合 = 現在HP ÷ 最大HP が最も低い人）。
     * 割合が同じなら失った HP が多い人、それも同じなら先に並んでいる人。
     *
     * @param  array<string, array<string, mixed>>  $units
     * @param  list<string>  $woundedIds  傷ついている生存者の ID（1人以上）
     */
    private function mostWounded(array $units, array $woundedIds): string
    {
        $best = $woundedIds[0];
        foreach (array_slice($woundedIds, 1) as $id) {
            $hp = (int) $units[$id]['hp'];
            $max = max(1, (int) $units[$id]['max_hp']);
            $bestHp = (int) $units[$best]['hp'];
            $bestMax = max(1, (int) $units[$best]['max_hp']);

            // 割合の比較は、分数のまま掛け算で行う（hp/max < bestHp/bestMax ⇔ hp×bestMax < bestHp×max）
            $left = $hp * $bestMax;
            $right = $bestHp * $max;
            if ($left < $right || ($left === $right && ($max - $hp) > ($bestMax - $bestHp))) {
                $best = $id;
            }
        }

        return $best;
    }

    /**
     * @param  list<mixed>  $list
     */
    private function pick(array $list): mixed
    {
        $list = array_values($list);

        return $list[$this->roll(0, count($list) - 1)];
    }

    private function roll(int $min, int $max): int
    {
        if ($this->roll !== null) {
            return max($min, min($max, (int) ($this->roll)($min, $max)));
        }

        return random_int($min, $max);
    }
}
