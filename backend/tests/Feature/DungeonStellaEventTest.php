<?php

namespace Tests\Feature;

use App\Models\DungeonEventMaster;
use App\Models\DungeonExplorationSession;
use App\Models\User;
use App\Models\UserDungeonProgress;
use App\Services\Dungeon\DungeonEventCatalog;
use App\Services\Dungeon\DungeonProgressService;
use App\Services\Dungeon\Exploration\DungeonExplorationService;
use App\Services\Player\ItemInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 探索イベント「ステラ登場」（2 層・3 層）。同じ魔法学校のステラが、撤退するところで回復薬をくれる。
 * 1 回のダンジョン挑戦（層に入ってから出るまで）で、最大 1 回しか出ない。
 */
class DungeonStellaEventTest extends TestCase
{
    use RefreshDatabase;

    private const EVENT = 'stella_appears';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
        $this->postJson('/api/battles/demo')->assertOk();
        config(['game.dungeon.test_force' => 'exploration', 'game.dungeon.playable_floor' => 3]);
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

    public function test_the_event_is_in_the_pools_of_floor_2_and_3_only_and_is_once_per_run(): void
    {
        foreach ([2, 3] as $floor) {
            $event = DungeonEventMaster::query()->where('floor', $floor)->where('code', self::EVENT)->firstOrFail();
            $this->assertSame('ステラ登場', $event->name);
            $this->assertTrue($event->once_per_run);
            $this->assertSame(10, (int) $event->weight);
            $this->assertFalse($event->skip_epilogue);
        }
        $this->assertFalse(DungeonEventMaster::query()->where('floor', 1)->where('code', self::EVENT)->exists(), '1 層には出ない');
        // ほかのイベントは「1 回だけ」ではない
        $this->assertSame(
            [self::EVENT],
            DungeonEventMaster::query()->where('once_per_run', true)->pluck('code')->unique()->values()->all(),
        );
    }

    public function test_the_scenes_follow_the_spec_with_stella_speaking_with_her_own_portrait(): void
    {
        $this->enterFloor(2);
        $lines = $this->runEvent();

        // 地の文・セリフが、指定の順番どおり（最後は、どのイベントも付く固定のエピローグ）
        $this->assertSame([
            ['narration', null, '道の向こうから4人の一行が歩いてくる。同じ魔法学校の生徒だ。'],
            ['dialogue', 'ステラ', 'あ……みなさん'],
            ['dialogue', 'PC1', 'ステラ！'],
            ['dialogue', 'ステラ', 'みなさん、この先に進まれるんですね。この先は敵も強いので気をつけてください。あ、そうだ……'],
            ['dialogue', 'ステラ', '私たちは撤退するので、よろしければ……'],
            ['narration', null, 'HP回復薬とMP回復薬を受け取った！'],
            ['dialogue', 'PC1', 'いいの？ありがとう'],
            ['dialogue', 'ステラ', 'いえいえ。じゃあ、頑張ってくださいね'],
            ['narration', null, '一行は先に進むことにした'],
        ], array_map(fn (array $l): array => [
            $l['type'],
            $l['type'] === 'dialogue' ? ($l['speaker_name'] ?? 'PC1') : null,
            $l['text'],
        ], $lines));

        $stella = array_values(array_filter($lines, fn (array $l): bool => ($l['speaker_name'] ?? null) === 'ステラ'));
        $this->assertCount(4, $stella);
        foreach ($stella as $line) {
            // 立ち絵は characters/stella（PC と同じ仕様 = 話者の立ち絵として出る）
            $this->assertSame('stella', $line['speaker_sprite']);
            $this->assertSame('stella', $line['character']);
        }
        // PC1 のセリフは、サフィリア（PC1 の立ち絵）が言う
        $pc1 = array_values(array_filter($lines, fn (array $l): bool => ($l['character'] ?? null) === 'pc1'));
        $this->assertSame(['ステラ！', 'いいの？ありがとう'], array_column($pc1, 'text'));

        // 「受け取った！」の行で、成功音が鳴る（画面が、行の sfx を鳴らす）
        $received = collect($lines)->firstWhere('text', 'HP回復薬とMP回復薬を受け取った！');
        $this->assertSame('success', $received['sfx']);
    }

    public function test_the_stella_portrait_files_exist_in_the_same_size_as_the_party_portraits(): void
    {
        $dir = base_path('../frontend/public/assets/characters');
        $size = fn (string $file): array => array_slice(getimagesize("{$dir}/{$file}") ?: [], 0, 2);

        $this->assertFileExists("{$dir}/stella.png");
        $this->assertFileExists("{$dir}/stella.webp", '画面は WebP を先に読む（PC の立ち絵と同じ）');
        $this->assertSame($size('PC1.webp'), $size('stella.png'));
        $this->assertSame($size('PC1.webp'), $size('stella.webp'));
    }

    /**
     * @return array<string, array{0: int, 1: array<string, int>}>
     */
    public static function rewardsByFloor(): array
    {
        return [
            '2層はHP回復薬（中）とMP回復薬（中）' => [2, ['potion_hp_m' => 1, 'potion_mp_m' => 1]],
            '3層はHP回復薬（強）とMP回復薬（強）' => [3, ['potion_hp_l' => 1, 'potion_mp_l' => 1]],
        ];
    }

    /**
     * @param  array<string, int>  $expected
     */
    #[DataProvider('rewardsByFloor')]
    public function test_stella_gives_a_potion_of_each_kind_matching_the_floor(int $floor, array $expected): void
    {
        $this->enterFloor($floor);
        $this->assertSame([], $this->inventory(), 'もらう前は何も持っていない');

        $this->runEvent();

        $this->assertSame($expected, $this->inventory());
    }

    public function test_it_appears_at_most_once_per_run_and_comes_back_in_the_next_run(): void
    {
        $catalog = new DungeonEventCatalog;
        $this->enterFloor(2);
        $user = $this->demoUser();
        $progress = new DungeonProgressService;

        // まだ出ていない挑戦では、抽選に入っている
        $this->assertContains(self::EVENT, array_keys($catalog->mechanicalEventWeights(2)));
        $this->assertSame([], $progress->onceEventCodes($user));

        // 出た（探索が始まった）ら、この挑戦の記録に入る
        $this->runEvent();
        $this->assertSame([self::EVENT], $progress->onceEventCodes($user));

        // 以降の抽選では出ない（確率の問題ではなく、必ず）。ほかのイベントは出る
        $seen = [];
        for ($i = 0; $i < 400; $i++) {
            $seen[$catalog->pickRandomEventCode(2, false, $progress->onceEventCodes($user))] = true;
        }
        $this->assertArrayNotHasKey(self::EVENT, $seen);
        $this->assertGreaterThan(5, count($seen));

        // 同じ挑戦のうちに、本物の抽選（探索イベントの開始）を何度しても、ステラは出ない
        for ($i = 0; $i < 80; $i++) {
            $this->assertNotSame(self::EVENT, $this->drawEventCode());
        }
    }

    public function test_the_record_is_cleared_when_a_new_run_starts_or_a_run_ends(): void
    {
        $progress = new DungeonProgressService;
        $user = $this->demoUser();

        // 層に入り直すと（新しい挑戦）、また出る
        $this->enterFloor(2);
        $this->runEvent();
        $this->assertSame([self::EVENT], $progress->onceEventCodes($user));
        $this->enterFloor(2);
        $this->assertSame([], $progress->onceEventCodes($user));

        // 撤退・全滅（リセット）・ボス撃破でダンジョンを出たときも、空に戻る
        foreach (['retreat', 'reset', 'boss'] as $how) {
            $this->enterFloor(2);
            $this->runEvent();
            $this->assertSame([self::EVENT], $progress->onceEventCodes($user), $how);

            match ($how) {
                'retreat' => $progress->retreat($user),
                'reset' => $progress->reset($user),
                'boss' => $progress->onBossVictory($user, 2),
            };
            $this->assertSame([], $progress->onceEventCodes($user->fresh()), "{$how} のあと");
        }
    }

    public function test_marking_is_per_user_and_a_floor_3_run_is_independent(): void
    {
        $progress = new DungeonProgressService;
        $this->enterFloor(3);
        $this->runEvent();
        $this->assertSame([self::EVENT], $progress->onceEventCodes($this->demoUser()));

        // ほかのユーザーの記録には影響しない
        $other = User::factory()->create();
        $this->assertSame([], $progress->onceEventCodes($other));
    }

    /**
     * 探索イベントが選ばれたときの本物の抽選（DungeonExplorationService::startEvent。テストの強制なし）を 1 回行い、
     * 選ばれたイベントのコードを返す。
     */
    private function drawEventCode(): string
    {
        $response = app(DungeonExplorationService::class)->startEvent($this->demoUser(), 2, 1);

        return (string) DungeonExplorationSession::query()->whereKey($response['session_id'])->value('event_code');
    }

    /**
     * ステラ登場を最後まで進め、表示された行（辞書）をすべて返す。
     *
     * @return list<array<string, mixed>>
     */
    private function runEvent(): array
    {
        config(['game.dungeon.test_force' => 'exploration', 'game.dungeon.test_event_code' => self::EVENT]);

        $response = $this->postJson('/api/dungeon/advance')->assertOk();
        $sessionId = $response->json('session_id');
        $lines = [];

        for ($i = 0; $i < 40; $i++) {
            foreach ($response->json('segment.lines') ?? [] as $line) {
                $lines[] = $line;
            }
            if ($response->json('segment.kind') === 'complete') {
                return $lines;
            }
            $response = $this->postJson('/api/dungeon/exploration/continue', ['session_id' => $sessionId])->assertOk();
        }

        $this->fail('ステラ登場を完了できませんでした');
    }

    /**
     * @return array<string, int>
     */
    private function inventory(): array
    {
        return collect(app(ItemInventoryService::class)->inventoryPayload($this->demoUser()))
            ->pluck('quantity', 'code')
            ->map(fn ($q) => (int) $q)
            ->filter(fn (int $q) => $q > 0)
            ->all();
    }

    private function enterFloor(int $floor): void
    {
        UserDungeonProgress::query()->updateOrCreate(
            ['user_id' => $this->demoUser()->id],
            ['floor' => $floor, 'unlocked_floor' => $floor, 'step' => 0, 'in_dungeon' => false],
        );

        $this->postJson('/api/dungeon/enter', ['floor' => $floor])->assertOk();
    }

    private function demoUser(): User
    {
        return User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
    }
}
