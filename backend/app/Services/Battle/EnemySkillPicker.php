<?php

namespace App\Services\Battle;

use App\Services\MasterData\MasterDataProvider;

class EnemySkillPicker
{
    /** @var (callable(): int)|null */
    private $roll = null;

    /**
     * @param  (callable(): int)|null  $roll
     *        テスト用。1〜100 の整数を返す。null のときは random_int(1, 100)。
     */
    public function __construct(
        private readonly MasterDataProvider $masters = new MasterDataProvider,
        ?callable $roll = null,
    ) {
        $this->roll = $roll;
    }

    /**
     * @return array{skill_code: string, label: string, action_type: string, target_type: string, coefficient: float}
     */
    public function pick(string $enemyCode): array
    {
        $skills = $this->masters->enemySkillsFor($enemyCode);
        if ($skills === []) {
            return [
                'skill_code' => 'attack',
                'label' => '攻撃',
                'action_type' => 'punch',
                'target_type' => 'ally_single',
                'coefficient' => 1.0,
            ];
        }

        $totalWeight = array_sum(array_column($skills, 'weight'));
        $remaining = $this->rollValue() % $totalWeight;

        foreach ($skills as $skill) {
            $remaining -= (int) $skill['weight'];
            if ($remaining < 0) {
                return [
                    'skill_code' => (string) $skill['skill_code'],
                    'label' => (string) $skill['label'],
                    'action_type' => (string) $skill['action_type'],
                    'target_type' => (string) $skill['target_type'],
                    'coefficient' => (float) $skill['coefficient'],
                ];
            }
        }

        $last = $skills[count($skills) - 1];

        return [
            'skill_code' => (string) $last['skill_code'],
            'label' => (string) $last['label'],
            'action_type' => (string) $last['action_type'],
            'target_type' => (string) $last['target_type'],
            'coefficient' => (float) $last['coefficient'],
        ];
    }

    private function rollValue(): int
    {
        if ($this->roll !== null) {
            return ($this->roll)();
        }

        return random_int(0, PHP_INT_MAX);
    }
}
