<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserCharacter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DungeonExplorationApiTest extends TestCase
{
    private const EPILOGUE_TEXT = '一行は先に進むことにした';

    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
        $this->postJson('/api/battles/demo')->assertOk();
        $this->postJson('/api/dungeon/enter')->assertOk();
    }

    public function test_trap_arrow_event_can_complete_on_success_roll(): void
    {
        config([
            'game.dungeon.test_force' => 'exploration',
            'game.dungeon.test_event_code' => 'trap_arrow',
            'game.dungeon.test_stat_success' => true,
        ]);

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $start->assertJsonPath('event', 'exploration');
        $start->assertJsonPath('segment.kind', 'story');
        $start->assertJsonPath('segment.lines.0.text', fn ($t) => str_contains((string) $t, '飛来'));
        $start->assertJsonPath('segment.lines.1.type', 'dialogue');
        $start->assertJsonPath('segment.lines.1.text', '！？');
        $start->assertJsonPath('segment.lines.2.text', fn ($t) => str_contains((string) $t, '成功率：')
            && ! str_contains((string) $t, '成功率：0%'));
        $sessionId = $start->json('session_id');

        $afterRoll = $this->postJson('/api/dungeon/exploration/continue', [
            'session_id' => $sessionId,
        ])
            ->assertOk()
            ->assertJsonPath('segment.kind', 'story');
        $afterRoll->assertJsonPath('segment.lines.0.text', fn ($text) => str_contains((string) $text, '避ける'));
        $afterRoll->assertJsonPath('segment.lines.0.sfx', 'attack');

        $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])
            ->assertOk()
            ->assertJsonPath('segment.kind', 'story')
            ->assertJsonPath('segment.lines.0.text', self::EPILOGUE_TEXT);

        $done = $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])
            ->assertOk()
            ->assertJsonPath('segment.kind', 'complete');

        $this->assertIsArray($done->json('party'));
    }

    public function test_trap_arrow_applies_damage_on_failed_roll(): void
    {
        config(['game.dungeon.test_stat_success' => false]);

        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        $beforeHp = (int) UserCharacter::query()
            ->where('user_id', $user->id)
            ->sum('hp');

        $service = app(\App\Services\Dungeon\Exploration\DungeonExplorationService::class);
        $start = $service->startEvent($user, 1, 'trap_arrow');
        $this->assertSame('story', $start['segment']['kind']);

        $failStory = $service->continue($user, $start['session_id']);
        $this->assertSame('story', $failStory['segment']['kind']);
        $this->assertStringContainsString('突き刺さる', json_encode($failStory, JSON_UNESCAPED_UNICODE));

        $epilogue = $service->continue($user, $start['session_id']);
        $this->assertSame('story', $epilogue['segment']['kind']);
        $this->assertSame(self::EPILOGUE_TEXT, $epilogue['segment']['lines'][0]['text']);

        $done = $service->continue($user, $start['session_id']);
        $this->assertSame('complete', $done['segment']['kind']);
        $this->assertSame($beforeHp - 20, (int) UserCharacter::query()->where('user_id', $user->id)->sum('hp'));
    }

    public function test_treasure_chest_choice_and_open_success(): void
    {
        config([
            'game.dungeon.test_force' => 'exploration',
            'game.dungeon.test_event_code' => 'treasure_chest',
            'game.dungeon.test_stat_success' => true,
        ]);

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $sessionId = $start->json('session_id');
        $start->assertJsonPath('segment.kind', 'choice');
        $start->assertJsonPath('segment.lines.0.type', 'narration');

        $slotId = $start->json('segment.options.0.slot_id');
        $this->assertNotEmpty($slotId);

        $picked = $this->postJson('/api/dungeon/exploration/choose', [
            'session_id' => $sessionId,
            'slot_id' => $slotId,
        ])->assertOk();
        $picked->assertJsonPath('segment.kind', 'story');
        $picked->assertJsonPath('segment.lines.0.text', fn ($text) => str_contains((string) $text, 'こじ開ける'));

        $afterRoll = $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])
            ->assertOk();
        $afterRoll->assertJsonPath('segment.kind', 'story');
        $afterRoll->assertJsonPath('segment.lines.0.text', fn ($text) => str_contains((string) $text, '開いた'));

        $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])
            ->assertOk()
            ->assertJsonPath('segment.kind', 'story')
            ->assertJsonPath('segment.lines.0.text', self::EPILOGUE_TEXT);

        $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])
            ->assertOk()
            ->assertJsonPath('segment.kind', 'complete');
    }

    public function test_advance_is_rejected_while_exploration_session_active(): void
    {
        config([
            'game.dungeon.test_force' => 'exploration',
            'game.dungeon.test_event_code' => 'trap_arrow',
        ]);

        $this->postJson('/api/dungeon/advance')->assertOk();

        $this->postJson('/api/dungeon/advance')
            ->assertStatus(409);
    }

    protected function tearDown(): void
    {
        config([
            'game.dungeon.test_force' => null,
            'game.dungeon.test_event_code' => null,
            'game.dungeon.test_roll' => null,
            'game.dungeon.test_stat_success' => null,
        ]);

        parent::tearDown();
    }
}
