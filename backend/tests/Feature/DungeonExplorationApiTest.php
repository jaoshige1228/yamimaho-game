<?php

namespace Tests\Feature;

use App\Models\DungeonExplorationSession;
use App\Models\ItemMaster;
use App\Models\User;
use App\Models\UserCharacter;
use App\Models\UserItem;
use App\Services\Growth\LevelGrowthService;
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
        $afterRoll->assertJsonPath('segment.stat_check_result', 'success');

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
        $start = $service->startEvent($user, 1, 1, 'trap_arrow');
        $this->assertSame('story', $start['segment']['kind']);

        $failStory = $service->continue($user, $start['session_id']);
        $this->assertSame('story', $failStory['segment']['kind']);
        $this->assertSame('fail', $failStory['segment']['stat_check_result'] ?? null);
        $this->assertStringContainsString('突き刺さる', json_encode($failStory, JSON_UNESCAPED_UNICODE));
        $this->assertIsArray($failStory['party_snapshot'] ?? null);
        $failLines = $failStory['segment']['lines'] ?? [];
        $this->assertTrue(
            collect($failLines)->contains(fn (array $line) => ! empty($line['sync_party'])),
            '突き刺さる行付近に sync_party が付いていること'
        );
        $this->assertSame(
            $beforeHp - 30,
            (int) UserCharacter::query()->where('user_id', $user->id)->sum('hp'),
            '突き刺さる描写と同時に DB へダメージがコミットされていること'
        );

        $epilogue = $service->continue($user, $start['session_id']);
        $this->assertSame('story', $epilogue['segment']['kind']);
        $this->assertSame(self::EPILOGUE_TEXT, $epilogue['segment']['lines'][0]['text']);

        $done = $service->continue($user, $start['session_id']);
        $this->assertSame('complete', $done['segment']['kind']);
        $this->assertSame($beforeHp - 30, (int) UserCharacter::query()->where('user_id', $user->id)->sum('hp'));
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
        $start->assertJsonPath('segment.stat_check_label', '筋力判定');
        $start->assertJsonPath('segment.prompt', fn ($text) => str_contains((string) $text, '筋力判定'));
        $start->assertJsonPath('segment.options.0.label', fn ($label) => str_contains((string) $label, '成功率')
            && ! preg_match('/筋力\d+/u', (string) $label));

        $slotId = $start->json('segment.options.0.slot_id');
        $this->assertNotEmpty($slotId);

        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        $potionId = ItemMaster::query()->where('code', 'potion_hp_s')->value('id');
        $beforeQty = (int) (UserItem::query()
            ->where('user_id', $user->id)
            ->where('item_master_id', $potionId)
            ->value('quantity') ?? 0);

        $picked = $this->postJson('/api/dungeon/exploration/choose', [
            'session_id' => $sessionId,
            'slot_id' => $slotId,
        ])->assertOk();
        $picked->assertJsonPath('segment.kind', 'story');
        $picked->assertJsonPath('segment.lines.0.text', fn ($text) => str_contains((string) $text, 'こじ開ける')
            && str_contains((string) $text, '筋力判定')
            && str_contains((string) $text, '成功率'));

        $afterRoll = $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])
            ->assertOk();
        $afterRoll->assertJsonPath('segment.kind', 'story');
        $afterRoll->assertJsonPath('segment.lines.0.text', fn ($text) => str_contains((string) $text, '開いた'));
        $afterRoll->assertJsonPath('segment.lines', fn ($lines) => collect($lines)->contains(
            fn ($line) => str_contains((string) ($line['text'] ?? ''), 'HP回復薬'),
        ));

        $afterQty = (int) (UserItem::query()
            ->where('user_id', $user->id)
            ->where('item_master_id', $potionId)
            ->value('quantity') ?? 0);
        $this->assertSame($beforeQty + 1, $afterQty);

        $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])
            ->assertOk()
            ->assertJsonPath('segment.kind', 'story')
            ->assertJsonPath('segment.lines.0.text', self::EPILOGUE_TEXT);

        $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])
            ->assertOk()
            ->assertJsonPath('segment.kind', 'complete');
    }

    public function test_healing_spring_restores_party_by_twenty_percent(): void
    {
        config([
            'game.dungeon.test_force' => 'exploration',
            'game.dungeon.test_event_code' => 'healing_spring',
        ]);

        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        $character = UserCharacter::query()->where('user_id', $user->id)->firstOrFail();
        $growth = app(LevelGrowthService::class);
        $stats = $growth->statsForLevel($character->characterMaster, $character->level);
        $maxHp = (int) $stats['hp'];
        $maxMp = (int) $stats['mp'];
        $character->update(['hp' => 10, 'mp' => 5]);

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $sessionId = $start->json('session_id');
        $transcript = json_encode($start->json('segment'), JSON_UNESCAPED_UNICODE);

        while (true) {
            $step = $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])
                ->assertOk();
            $transcript .= json_encode($step->json('segment'), JSON_UNESCAPED_UNICODE);
            if ($step->json('segment.kind') === 'complete') {
                break;
            }
        }

        $this->assertStringContainsString('泉', $transcript);
        $this->assertStringContainsString('休息を経て、元気になった！', $transcript);
        $this->assertStringContainsString(self::EPILOGUE_TEXT, $transcript);

        $character->refresh();
        $expectedHp = min($maxHp, 10 + intdiv($maxHp * 20, 100));
        $expectedMp = min($maxMp, 5 + intdiv($maxMp * 20, 100));
        $this->assertSame($expectedHp, (int) $character->hp);
        $this->assertSame($expectedMp, (int) $character->mp);
        $this->assertLessThan($maxHp, (int) $character->hp);
    }

    public function test_rainbow_spring_restores_party_to_full(): void
    {
        config([
            'game.dungeon.test_force' => 'exploration',
            'game.dungeon.test_event_code' => 'rainbow_spring',
        ]);

        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        $character = UserCharacter::query()->where('user_id', $user->id)->firstOrFail();
        $growth = app(LevelGrowthService::class);
        $stats = $growth->statsForLevel($character->characterMaster, $character->level);
        $maxHp = (int) $stats['hp'];
        $maxMp = (int) $stats['mp'];
        $character->update(['hp' => 10, 'mp' => 5]);

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $sessionId = $start->json('session_id');
        $transcript = json_encode($start->json('segment'), JSON_UNESCAPED_UNICODE);

        while (true) {
            $step = $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])
                ->assertOk();
            $transcript .= json_encode($step->json('segment'), JSON_UNESCAPED_UNICODE);
            if ($step->json('segment.kind') === 'complete') {
                break;
            }
        }

        $this->assertStringContainsString('虹色', $transcript);
        $this->assertStringContainsString('みなぎった', $transcript);
        $this->assertStringContainsString(self::EPILOGUE_TEXT, $transcript);

        $character->refresh();
        $this->assertSame($maxHp, (int) $character->hp);
        $this->assertSame($maxMp, (int) $character->mp);
    }

    public function test_suspicious_spring_skip_choice_does_not_repeat(): void
    {
        config([
            'game.dungeon.test_force' => 'exploration',
            'game.dungeon.test_event_code' => 'suspicious_spring',
        ]);

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $sessionId = $start->json('session_id');
        $this->assertNotEmpty($sessionId);

        $choice = $start->json('segment.kind') === 'choice' ? $start : null;
        if ($choice === null) {
            for ($i = 0; $i < 8; $i++) {
                $step = $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])
                    ->assertOk();
                if ($step->json('segment.kind') === 'choice') {
                    $choice = $step;
                    break;
                }
            }
        }

        $this->assertNotNull($choice, '選択肢セグメントに到達できませんでした');
        $this->assertDatabaseHas('dungeon_event_nodes', [
            'event_code' => 'suspicious_spring',
            'node_key' => 'choose',
            'next_on_success' => 'taste_roll',
            'next_on_fail' => 'skip_react',
            'dialogue_pc4' => '毒見しない',
        ]);
        $choice->assertJsonPath('segment.prompt', '誰が毒見する？');
        $skip = collect($choice->json('segment.options'))
            ->firstWhere('slot_id', 'skip');
        $this->assertNotNull($skip);
        $this->assertSame('毒見しない', $skip['label']);

        $afterSkip = $this->postJson('/api/dungeon/exploration/choose', [
            'session_id' => $sessionId,
            'slot_id' => 'skip',
        ])->assertOk();

        $afterSkip->assertJsonPath('segment.kind', 'story');
        $afterSkip->assertJsonPath('segment.lines.0.type', 'dialogue');

        $transcript = json_encode($afterSkip->json('segment'), JSON_UNESCAPED_UNICODE);
        while ($afterSkip->json('segment.kind') !== 'complete') {
            $afterSkip = $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])
                ->assertOk();
            $this->assertNotSame('choice', $afterSkip->json('segment.kind'));
            $transcript .= json_encode($afterSkip->json('segment'), JSON_UNESCAPED_UNICODE);
        }

        $this->assertStringContainsString(self::EPILOGUE_TEXT, $transcript);
        $afterSkip->assertJsonPath('segment.kind', 'complete');
    }

    public function test_suspicious_spring_bad_damage_applied_after_bad_reaction_line(): void
    {
        config([
            'game.dungeon.test_force' => 'exploration',
            'game.dungeon.test_event_code' => 'suspicious_spring',
            'game.dungeon.test_roll' => 99,
        ]);

        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        $character = UserCharacter::query()->where('user_id', $user->id)->firstOrFail();
        $beforeHp = (int) $character->hp;

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $sessionId = $start->json('session_id');
        $choice = $this->reachExplorationChoiceSegment($start, $sessionId);
        $slotId = collect($choice->json('segment.options'))
            ->first(fn (array $opt) => $opt['slot_id'] !== 'skip')['slot_id'];

        $afterPick = $this->postJson('/api/dungeon/exploration/choose', [
            'session_id' => $sessionId,
            'slot_id' => $slotId,
        ])->assertOk();

        $afterPick->assertJsonPath('segment.kind', 'story');
        $transcript = json_encode($afterPick->json('segment'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('口にした', $transcript);
        $this->assertStringContainsString('マズ', $transcript);
        $afterPick->assertJsonPath('party_snapshot', fn ($snapshot) => is_array($snapshot) && $snapshot !== []);
        $lines = $afterPick->json('segment.lines');
        $this->assertTrue(
            collect($lines)->contains(fn (array $line) => ! empty($line['sync_party'])),
            'マズいセリフに sync_party が付いていること'
        );

        $character->refresh();
        $this->assertLessThan($beforeHp, (int) $character->hp, 'choose 直後に DB へダメージがコミットされていること');

        while (true) {
            $step = $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])
                ->assertOk();
            $transcript .= json_encode($step->json('segment'), JSON_UNESCAPED_UNICODE);
            if ($step->json('segment.kind') === 'complete') {
                break;
            }
        }

        $this->assertStringContainsString('胃腸', $transcript);
    }

    public function test_suspicious_spring_ok_restores_twenty_percent(): void
    {
        config([
            'game.dungeon.test_force' => 'exploration',
            'game.dungeon.test_event_code' => 'suspicious_spring',
            'game.dungeon.test_roll' => 1,
        ]);

        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        $character = UserCharacter::query()->where('user_id', $user->id)->firstOrFail();
        $growth = app(LevelGrowthService::class);
        $stats = $growth->statsForLevel($character->characterMaster, $character->level);
        $maxHp = (int) $stats['hp'];
        $beforeHp = 30;
        $character->update(['hp' => $beforeHp]);

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $sessionId = $start->json('session_id');
        $choice = $this->reachExplorationChoiceSegment($start, $sessionId);
        $slotId = collect($choice->json('segment.options'))
            ->first(fn (array $opt) => $opt['slot_id'] !== 'skip')['slot_id'];

        $this->postJson('/api/dungeon/exploration/choose', [
            'session_id' => $sessionId,
            'slot_id' => $slotId,
        ])->assertOk();

        while (true) {
            $step = $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])
                ->assertOk();
            if ($step->json('segment.kind') === 'complete') {
                break;
            }
        }

        $character->refresh();
        $expectedHp = min($maxHp, $beforeHp + intdiv($maxHp * 20, 100));
        $this->assertSame($expectedHp, (int) $character->hp);
        $this->assertLessThan($maxHp, (int) $character->hp);
    }

    public function test_suspicious_spring_party_taste_advances_to_story(): void
    {
        config([
            'game.dungeon.test_force' => 'exploration',
            'game.dungeon.test_event_code' => 'suspicious_spring',
            'game.dungeon.test_roll' => 1,
        ]);

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $sessionId = $start->json('session_id');
        $this->assertNotEmpty($sessionId);

        $choice = $start->json('segment.kind') === 'choice' ? $start : null;
        if ($choice === null) {
            for ($i = 0; $i < 8; $i++) {
                $step = $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])
                    ->assertOk();
                if ($step->json('segment.kind') === 'choice') {
                    $choice = $step;
                    break;
                }
            }
        }

        $this->assertNotNull($choice);
        $slotId = collect($choice->json('segment.options'))
            ->first(fn (array $opt) => $opt['slot_id'] !== 'skip')['slot_id'];
        $this->assertNotEmpty($slotId);

        $afterPick = $this->postJson('/api/dungeon/exploration/choose', [
            'session_id' => $sessionId,
            'slot_id' => $slotId,
        ])->assertOk();

        $afterPick->assertJsonPath('segment.kind', 'story');
        $this->assertStringContainsString(
            '口にした',
            json_encode($afterPick->json('segment'), JSON_UNESCAPED_UNICODE)
        );
    }

    public function test_mysterious_presence_no_choice_advances_to_story(): void
    {
        config([
            'game.dungeon.test_force' => 'exploration',
            'game.dungeon.test_event_code' => 'mysterious_presence',
        ]);

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $sessionId = $start->json('session_id');
        $this->assertNotEmpty($sessionId);

        $choice = $this->reachExplorationChoiceSegment($start, $sessionId);
        $this->assertDatabaseHas('dungeon_event_nodes', [
            'event_code' => 'mysterious_presence',
            'node_key' => 'choose',
            'next_on_success' => 'enter1',
            'next_on_fail' => 'decline1',
        ]);
        $choice->assertJsonPath('segment.prompt', '空間の裂け目に、入りますか？');

        $afterNo = $this->postJson('/api/dungeon/exploration/choose', [
            'session_id' => $sessionId,
            'slot_id' => 'no',
        ])->assertOk();

        $afterNo->assertJsonPath('segment.kind', 'story');
        $this->assertStringContainsString(
            '自殺行為',
            json_encode($afterNo->json('segment'), JSON_UNESCAPED_UNICODE)
        );

        $transcript = json_encode($afterNo->json('segment'), JSON_UNESCAPED_UNICODE);
        while ($afterNo->json('segment.kind') !== 'complete') {
            $afterNo = $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])
                ->assertOk();
            $this->assertNotSame('choice', $afterNo->json('segment.kind'));
            $transcript .= json_encode($afterNo->json('segment'), JSON_UNESCAPED_UNICODE);
        }

        $this->assertStringContainsString(self::EPILOGUE_TEXT, $transcript);
    }

    public function test_mysterious_presence_yes_choice_advances_to_story(): void
    {
        config([
            'game.dungeon.test_force' => 'exploration',
            'game.dungeon.test_event_code' => 'mysterious_presence',
        ]);

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $sessionId = $start->json('session_id');
        $choice = $this->reachExplorationChoiceSegment($start, $sessionId);

        $afterYes = $this->postJson('/api/dungeon/exploration/choose', [
            'session_id' => $sessionId,
            'slot_id' => 'yes',
        ])->assertOk();

        $this->assertNotSame('choice', $afterYes->json('segment.kind'));
        $this->assertContains($afterYes->json('segment.kind'), ['story', 'battle', 'game_over']);
    }

    public function test_gold_pickup_grants_fifty_gold(): void
    {
        config([
            'game.dungeon.test_force' => 'exploration',
            'game.dungeon.test_event_code' => 'gold_pickup',
        ]);

        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        $beforeGold = (int) $user->gold;

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $sessionId = $start->json('session_id');
        $start->assertJsonPath('segment.kind', 'story');
        $start->assertJsonPath('segment.lines', fn ($lines) => collect($lines)->contains(
            fn ($line) => str_contains((string) ($line['text'] ?? ''), 'ゴールド'),
        ));

        $this->completeExplorationSession($sessionId);

        $user->refresh();
        $this->assertSame($beforeGold + 50, (int) $user->gold);
    }

    public function test_flash_flood_completes_with_party_spirit_checks(): void
    {
        config([
            'game.dungeon.test_force' => 'exploration',
            'game.dungeon.test_event_code' => 'flash_flood',
            'game.dungeon.test_stat_success' => true,
        ]);

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $sessionId = $start->json('session_id');
        $start->assertJsonPath('segment.kind', 'story');
        $start->assertJsonPath('segment.lines', fn ($lines) => collect($lines)->contains(
            fn ($line) => str_contains((string) ($line['text'] ?? ''), '鉄砲水'),
        ));

        $this->completeExplorationSession($sessionId);
    }

    public function test_flash_flood_spirit_fail_syncs_party_on_each_fail_line(): void
    {
        config([
            'game.dungeon.test_force' => 'exploration',
            'game.dungeon.test_event_code' => 'flash_flood',
            'game.dungeon.test_stat_success' => false,
        ]);

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $sessionId = $start->json('session_id');

        $spiritSegment = null;
        $responses = [$start];
        for ($i = 0; $i < 20; $i++) {
            if (($responses[array_key_last($responses)]->json('segment.kind') ?? '') === 'complete') {
                break;
            }
            $responses[] = $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])
                ->assertOk();
        }

        foreach ($responses as $response) {
            $lines = $response->json('segment.lines') ?? [];
            if (collect($lines)->contains(fn ($line) => str_contains((string) ($line['text'] ?? ''), 'パニック'))) {
                $spiritSegment = $response;
                break;
            }
        }

        $this->assertNotNull($spiritSegment, '精神判定セグメントに到達すること');

        $swallowedLine = collect($spiritSegment->json('segment.lines'))
            ->first(fn ($line) => str_contains((string) ($line['text'] ?? ''), '水に飲まれた'));
        $this->assertNotNull($swallowedLine, '「水に飲まれた」行が含まれること');
        $this->assertEmpty($swallowedLine['sync_party'] ?? null, '水に飲まれた行では HP を sync しないこと');

        $failLines = collect($spiritSegment->json('segment.lines'))
            ->filter(fn ($line) => str_contains((string) ($line['text'] ?? ''), 'パニック'));

        $this->assertGreaterThanOrEqual(1, $failLines->count());
        $this->assertTrue(
            $failLines->every(fn ($line) => ! empty($line['sync_party']) && is_array($line['party'] ?? null)),
            '失敗行ごとに sync_party と party が付いていること',
        );

        $syncedHp = collect($failLines->first()['party'] ?? [])->sum('hp');
        $snapshotHp = collect($spiritSegment->json('party_snapshot') ?? [])->sum('hp');
        $this->assertNotSame($snapshotHp, $syncedHp, '失敗 sync 時点で HP 合計が snapshot と異なること');
    }

    public function test_man_eating_plant_str_fail_leaves_victim_at_one_hp(): void
    {
        config([
            'game.dungeon.test_force' => 'exploration',
            'game.dungeon.test_event_code' => 'man_eating_plant',
            'game.dungeon.test_stat_success' => false,
        ]);

        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        $service = app(\App\Services\Dungeon\Exploration\DungeonExplorationService::class);

        $start = $service->startEvent($user, 1, 1, 'man_eating_plant');
        $sessionId = $start['session_id'];
        $session = DungeonExplorationSession::query()->findOrFail($sessionId);
        $targetSlot = (string) (($session->context ?? [])['target_slot'] ?? 'pc1');

        $target = app(\App\Services\Player\UserCharacterService::class)
            ->partyInSlotOrder($user)
            ->get($targetSlot);
        $this->assertNotNull($target);
        $target->hp = 100;
        $target->save();

        $failStory = null;
        for ($i = 0; $i < 40; $i++) {
            $step = $service->continue($user, $sessionId);
            if (($step['segment']['stat_check_result'] ?? null) === 'fail') {
                $failStory = $step;
            }
            if (($step['segment']['kind'] ?? '') === 'complete') {
                break;
            }
        }

        $this->assertNotNull($failStory, '筋力判定失敗ルートに到達すること');
        $this->assertStringContainsString(
            '飲み込まれ',
            json_encode($failStory, JSON_UNESCAPED_UNICODE),
        );

        $victim = app(\App\Services\Player\UserCharacterService::class)
            ->partyInSlotOrder($user->fresh())
            ->get($targetSlot);
        $this->assertNotNull($victim);
        $this->assertSame(1, (int) $victim->hp);
    }

    public function test_trapped_chest_know_success_grants_loot(): void
    {
        config([
            'game.dungeon.test_force' => 'exploration',
            'game.dungeon.test_event_code' => 'trapped_chest',
            'game.dungeon.test_stat_success' => true,
        ]);

        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
        $beforeGold = (int) $user->gold;

        $start = $this->postJson('/api/dungeon/advance')->assertOk();
        $sessionId = $start->json('session_id');
        $choice = $this->reachExplorationChoiceSegment($start, $sessionId);
        $choice->assertJsonPath('segment.stat_check_label', '知力判定');
        $choice->assertJsonPath('segment.prompt', fn ($text) => str_contains((string) $text, '知力判定')
            && substr_count((string) $text, '知力判定') === 1);
        $choice->assertJsonPath('segment.options.0.label', fn ($label) => str_contains((string) $label, '成功率')
            && ! preg_match('/知力\d+/u', (string) $label));
        $slotId = $choice->json('segment.options.0.slot_id');
        $this->assertNotSame('skip', $slotId);

        $this->postJson('/api/dungeon/exploration/choose', [
            'session_id' => $sessionId,
            'slot_id' => $slotId,
        ])->assertOk();

        $this->completeExplorationSession($sessionId);

        $user->refresh();
        $hasGold = (int) $user->gold > $beforeGold;
        $hasItem = UserItem::query()
            ->where('user_id', $user->id)
            ->whereIn('item_master_id', ItemMaster::query()
                ->whereIn('code', ['potion_hp_s', 'potion_mp_s'])
                ->pluck('id'))
            ->where('quantity', '>', 0)
            ->exists();

        $this->assertTrue($hasGold || $hasItem);
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

    /**
     * @param  \Illuminate\Testing\TestResponse  $start
     */
    private function reachExplorationChoiceSegment($start, string $sessionId)
    {
        $choice = $start->json('segment.kind') === 'choice' ? $start : null;
        if ($choice !== null) {
            return $choice;
        }

        for ($i = 0; $i < 12; $i++) {
            $step = $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])
                ->assertOk();
            if ($step->json('segment.kind') === 'choice') {
                return $step;
            }
        }

        $this->fail('選択肢セグメントに到達できませんでした');
    }

    private function completeExplorationSession(string $sessionId): void
    {
        for ($i = 0; $i < 40; $i++) {
            $step = $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])
                ->assertOk();
            if ($step->json('segment.kind') === 'complete') {
                return;
            }
        }

        $this->fail('探索イベントを完了できませんでした');
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
