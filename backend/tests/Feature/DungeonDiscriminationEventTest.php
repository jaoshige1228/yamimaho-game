<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Dungeon\DungeonEventCatalog;
use App\Services\Dungeon\Exploration\DungeonExplorationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 会話のみイベント「差別とは」（what_is_discrimination）。2 層と 3 層にだけ出る（1 層には出ない）。
 * ガルデ（pc2）・グレン（pc3）・サフィリア（pc1）・シエナ（pc4）の固定の会話が、9 行、順番どおりに流れる。
 */
class DungeonDiscriminationEventTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'what_is_discrimination';

    /** 流れる順番の、[話すキャラ, セリフ] */
    private const LINES = [
        ['pc2', '腹が減ったな'],
        ['pc3', '携帯食のパンあるぞ'],
        ['pc2', 'いただこう'],
        ['pc2', '……固くて不味いパンだ。こういう場所でもなければ食えたもんじゃ……'],
        ['pc1', 'ちょっと'],
        ['pc4', '…………'],
        ['pc2', 'ハッ！！'],
        ['pc2', '…………'],
        ['pc2', '（そうだ……俺もこちら側だということを忘れるな……）'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
        $this->postJson('/api/battles/demo')->assertOk();
    }

    public function test_the_event_is_a_dialogue_only_event_on_floors_two_and_three_but_not_floor_one(): void
    {
        $catalog = new DungeonEventCatalog;

        foreach ([2, 3] as $floor) {
            $event = $catalog->resolveEvent($floor, self::CODE);
            $this->assertTrue($event->isDialogueOnly, "{$floor} 層");
            $this->assertSame('line1', $event->startNodeKey);
            $this->assertDatabaseHas('dungeon_dialogue_event_masters', ['floor' => $floor, 'code' => self::CODE, 'name' => '差別とは', 'weight' => 3]);
        }
        $this->assertDatabaseMissing('dungeon_dialogue_event_masters', ['floor' => 1, 'code' => self::CODE]);
    }

    public function test_the_event_can_be_drawn_on_floors_two_and_three_only(): void
    {
        $catalog = new DungeonEventCatalog;

        foreach ([2, 3] as $floor) {
            $codes = [];
            for ($i = 0; $i < 300; $i++) {
                $codes[] = $catalog->pickRandomDialogueEventCode($floor);
            }
            $this->assertContains(self::CODE, $codes, "{$floor} 層で抽選される");
        }

        $floorOne = [];
        for ($i = 0; $i < 300; $i++) {
            $floorOne[] = $catalog->pickRandomDialogueEventCode(1);
        }
        $this->assertNotContains(self::CODE, $floorOne, '1 層では抽選されない');
    }

    public function test_the_nine_lines_play_in_order_with_the_fixed_speakers_and_then_end(): void
    {
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        $service = app(DungeonExplorationService::class);

        foreach ([2, 3] as $floor) {
            $start = $service->startEvent($user, $floor, 1, self::CODE);
            $steps = [$start];
            for ($i = 0; $i < 40 && ($steps[array_key_last($steps)]['segment']['kind'] ?? '') !== 'complete'; $i++) {
                $steps[] = $service->continue($user, $start['session_id']);
            }
            $this->assertSame('complete', $steps[array_key_last($steps)]['segment']['kind'] ?? null, "{$floor} 層: 最後まで流れる");

            $lines = [];
            foreach ($steps as $step) {
                foreach ($step['segment']['lines'] ?? [] as $line) {
                    if (($line['type'] ?? '') === 'dialogue') {
                        $lines[] = $line;
                    }
                }
            }

            $this->assertSame(array_column(self::LINES, 1), array_column($lines, 'text'), "{$floor} 層: セリフの順番");
            $this->assertSame(array_column(self::LINES, 0), array_column($lines, 'character'), "{$floor} 層: 話すキャラ");
        }
    }
}
