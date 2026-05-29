<?php

namespace App\Console\Commands;

use App\Services\Battle\BattleSimulator;
use App\Services\Battle\Strategies\AggressivePunchStrategy;
use Illuminate\Console\Command;

class BattleSimulateCommand extends Command
{
    protected $signature = 'battle:simulate {--runs=10 : 実行する戦闘数}';

    protected $description = 'バックエンドのみで戦闘を自動進行し、勝敗の分布を表示（バランス検証用）';

    public function handle(BattleSimulator $simulator): int
    {
        $runs = max(1, (int) $this->option('runs'));
        $strategy = new AggressivePunchStrategy;

        $this->info("Simulating {$runs} battles (API/DB 不要)...");

        $summary = ['victory' => 0, 'defeat' => 0, 'active' => 0];
        $turns = [];

        foreach ($simulator->runMany($runs, $strategy) as $run) {
            $summary[$run['result']] = ($summary[$run['result']] ?? 0) + 1;
            $turns[] = $run['player_turns'];
        }

        $this->table(
            ['result', 'count'],
            collect($summary)->map(fn ($c, $r) => [$r, $c])->values()->all(),
        );

        if ($turns !== []) {
            $this->line('Player turns: min='.min($turns).' max='.max($turns).' avg='.round(array_sum($turns) / count($turns), 1));
        }

        return self::SUCCESS;
    }
}
