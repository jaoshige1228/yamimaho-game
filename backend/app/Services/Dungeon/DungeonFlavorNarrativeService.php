<?php

namespace App\Services\Dungeon;

use App\Models\User;

class DungeonFlavorNarrativeService
{
    /** @var list<list<string>> */
    private const PATTERNS = [
        [
            '何も起きない。静かなダンジョンに一行の足音だけが木霊する',
        ],
        [
            'ふと、遠くで誰かの悲鳴が聞こえた気がした。他の生徒に何か起きているのだろうか',
            'しかしここでは日常茶飯事だ。一行は静かに目線を合わせると、諦めたように先へ進むのであった',
        ],
        [
            '宝箱だ。……しかしとっくに漁られた後であり、宝箱は虚しく口を開けていた',
        ],
    ];

    public function __construct(
        private readonly DungeonProgressService $progress = new DungeonProgressService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function start(User $user, int $floor, int $step): array
    {
        $pattern = self::PATTERNS[array_rand(self::PATTERNS)];
        $lines = [];

        foreach ($pattern as $text) {
            $lines[] = [
                'type' => 'narration',
                'text' => $text,
            ];
        }

        return array_merge([
            'event' => 'flavor',
            'segment' => [
                'kind' => 'story',
                'lines' => $lines,
            ],
            'floor' => $floor,
            'step' => $step,
        ], $this->progress->statusPayload($user));
    }
}
