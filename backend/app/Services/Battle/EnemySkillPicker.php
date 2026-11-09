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

        return $this->pickFrom($skills);
    }

    /**
     * その敵が持つ技の一覧（enemy_skills.csv の行。技がなければ空）。
     *
     * @return list<array<string, mixed>>
     */
    public function skillsFor(string $enemyCode): array
    {
        return $this->masters->enemySkillsFor($enemyCode);
    }

    /**
     * 技の一覧から、重みに従って 1 つ選ぶ。
     *
     * @param  non-empty-list<array<string, mixed>>  $skills
     * @return array{skill_code: string, label: string, action_type: string, target_type: string, coefficient: float}
     */
    public function pickFrom(array $skills): array
    {
        $totalWeight = array_sum(array_column($skills, 'weight'));
        $remaining = $this->rollValue() % $totalWeight;

        $chosen = $skills[count($skills) - 1];
        foreach ($skills as $skill) {
            $remaining -= (int) $skill['weight'];
            if ($remaining < 0) {
                $chosen = $skill;
                break;
            }
        }

        return [
            'skill_code' => (string) $chosen['skill_code'],
            'label' => (string) $chosen['label'],
            'action_type' => (string) $chosen['action_type'],
            'target_type' => (string) $chosen['target_type'],
            'coefficient' => (float) $chosen['coefficient'],
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
