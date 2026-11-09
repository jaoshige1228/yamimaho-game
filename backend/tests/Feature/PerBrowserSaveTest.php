<?php

namespace Tests\Feature;

use App\Models\BattleState;
use App\Models\User;
use App\Models\UserCharacter;
use App\Services\Player\PartyGoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * セーブデータはブラウザごと（Cookie `yamimaho_player`）に分かれる。
 * テスト全体は共有のデモユーザーで動くので（phpunit.xml の GAME_PER_BROWSER_SAVES=false）、ここだけ有効にする。
 */
class PerBrowserSaveTest extends TestCase
{
    use RefreshDatabase;

    private const COOKIE = 'yamimaho_player';

    protected function setUp(): void
    {
        parent::setUp();
        config(['game.per_browser_saves' => true, 'game.guest_create_per_hour' => 300]);
        RateLimiter::clear('new-guest');
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
    }

    public function test_first_visit_creates_a_guest_with_a_party_and_issues_a_long_lived_cookie(): void
    {
        $response = $this->getJson('/api/player/party')->assertOk();

        $guests = User::query()->where('email', 'like', 'guest-%@yamimaho.local')->get();
        $this->assertCount(1, $guests);
        $this->assertSame(0, User::query()->where('email', config('game.demo_user_email'))->count(), '共有のデモユーザーは使わない');
        $this->assertSame(4, UserCharacter::query()->where('user_id', $guests[0]->id)->count());
        $this->assertCount(4, $response->json('characters'));

        $cookie = $response->getCookie(self::COOKIE);
        $this->assertNotNull($cookie, 'Cookie が発行される');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $days = ($cookie->getExpiresTime() - time()) / 86400;
        $this->assertGreaterThan(399, $days);
        $this->assertLessThanOrEqual(400.1, $days);
    }

    public function test_the_same_cookie_is_the_same_save(): void
    {
        $token = $this->newBrowser();
        $usersBefore = User::query()->count();

        $first = $this->asBrowser($token)->getJson('/api/player/party')->assertOk();
        $second = $this->asBrowser($token)->getJson('/api/player/party')->assertOk();

        $this->assertSame($usersBefore, User::query()->count(), 'Cookie があれば、新しいユーザーは増えない');
        $this->assertNull($first->getCookie(self::COOKIE), '登録済みのトークンでは Cookie を発行し直さない');
        $this->assertSame($first->json('characters.0.id'), $second->json('characters.0.id'));
    }

    public function test_two_browsers_have_separate_saves(): void
    {
        $a = $this->newBrowser();
        $b = $this->newBrowser();

        // A だけゴールドを足す
        $this->asBrowser($a)->postJson('/api/admins/grant-gold', ['gold' => 1234])->assertOk();

        $this->assertSame(1234, $this->asBrowser($a)->getJson('/api/player/party')->json('gold'));
        $this->assertSame(0, $this->asBrowser($b)->getJson('/api/player/party')->json('gold'), 'B のゴールドは変わらない');

        // B だけ経験値を配る → A のレベルは 1 のまま
        $this->asBrowser($b)->postJson('/api/admins/grant-exp', ['exp' => 50])->assertOk();
        $this->assertSame(2, $this->asBrowser($b)->getJson('/api/admins/party')->json('characters.0.level'));
        $this->assertSame(1, $this->asBrowser($a)->getJson('/api/admins/party')->json('characters.0.level'));

        $this->assertNotSame($this->userOf($a)->id, $this->userOf($b)->id);
    }

    public function test_an_invalid_or_unknown_cookie_starts_a_new_save_instead_of_failing(): void
    {
        $known = $this->newBrowser();

        foreach (['garbage', str_repeat('a', 63), str_repeat('Z', 64), str_repeat('f', 64)] as $bad) {
            $before = User::query()->count();

            $response = $this->asBrowser($bad)->getJson('/api/player/party')->assertOk();

            $this->assertSame($before + 1, User::query()->count(), "不正な Cookie '{$bad}' は新しいゲストになる");
            $newToken = $response->getCookie(self::COOKIE)?->getValue();
            $this->assertNotNull($newToken);
            $this->assertNotSame($bad, $newToken);
            $this->assertNotSame($this->userOf($known)->id, $this->userOf($newToken)->id);
        }
        $this->assertSame(0, User::query()->where('email', config('game.demo_user_email'))->count());
    }

    public function test_a_stale_session_login_as_the_demo_user_is_ignored(): void
    {
        $demo = User::query()->create([
            'name' => '勇者',
            'email' => config('game.demo_user_email'),
            'password' => 'demo-not-for-production',
        ]);

        // 古いブラウザは「デモユーザーでログイン済み」のセッションを持っている
        $response = $this->actingAs($demo)->getJson('/api/player/party')->assertOk();

        $token = $response->getCookie(self::COOKIE)?->getValue();
        $this->assertNotNull($token, 'セッションのユーザーではなく、新しいゲストになる');
        $this->assertNotSame($demo->id, $this->userOf($token)->id);
        $this->assertSame(0, UserCharacter::query()->where('user_id', $demo->id)->count(), 'デモユーザーのセーブには触れない');
    }

    public function test_only_the_hash_of_the_token_is_stored(): void
    {
        $token = $this->newBrowser();

        $hash = User::query()->where('email', 'like', 'guest-%')->value('player_token_hash');

        $this->assertSame(hash('sha256', $token), $hash);
        $this->assertNotSame($token, $hash);
        $this->assertSame(0, User::query()->where('player_token_hash', $token)->count());
    }

    public function test_other_browsers_battles_are_not_visible_or_playable(): void
    {
        $a = $this->newBrowser();
        $b = $this->newBrowser();

        $battleId = $this->asBrowser($a)->postJson('/api/battles/demo')->assertOk()->json('battle_id');

        $this->asBrowser($a)->getJson("/api/battles/{$battleId}")->assertOk();
        $this->asBrowser($b)->getJson("/api/battles/{$battleId}")->assertNotFound();
        $this->asBrowser($b)->postJson("/api/battles/{$battleId}/actions", ['action' => 'punch', 'target_id' => 'enemy_1'])
            ->assertNotFound();
    }

    public function test_reset_only_clears_my_own_save_and_battles(): void
    {
        $a = $this->newBrowser();
        $b = $this->newBrowser();
        $this->asBrowser($a)->postJson('/api/admins/grant-gold', ['gold' => 500])->assertOk();
        $this->asBrowser($b)->postJson('/api/admins/grant-gold', ['gold' => 700])->assertOk();
        $battleA = $this->asBrowser($a)->postJson('/api/battles/demo')->assertOk()->json('battle_id');
        $battleB = $this->asBrowser($b)->postJson('/api/battles/demo')->assertOk()->json('battle_id');

        $this->asBrowser($b)->postJson('/api/reset')->assertOk();

        $this->assertNotNull(BattleState::query()->find($battleA), 'A の戦闘は残る');
        $this->assertNull(BattleState::query()->find($battleB), 'B の戦闘は消える');
        $this->assertSame(500, $this->asBrowser($a)->getJson('/api/player/party')->json('gold'), 'A のゴールドは残る');
        $this->assertSame(0, $this->asBrowser($b)->getJson('/api/player/party')->json('gold'), 'B のゴールドは消える');
    }

    public function test_new_saves_are_limited_per_hour_but_existing_browsers_keep_working(): void
    {
        config(['game.guest_create_per_hour' => 2]);
        RateLimiter::clear('new-guest');

        $first = $this->newBrowser();
        $this->newBrowser();
        $usersBefore = User::query()->count();

        $this->getJson('/api/player/party')
            ->assertStatus(429)
            ->assertJsonPath('message', '混み合っています。しばらくしてからもう一度お試しください。');
        $this->assertSame($usersBefore, User::query()->count(), '上限を超えたら、ユーザーは作らない');

        // Cookie を持つ既存のブラウザは影響を受けない
        $this->asBrowser($first)->getJson('/api/player/party')->assertOk();

        // 0 なら無制限
        config(['game.guest_create_per_hour' => 0]);
        $this->getJson('/api/player/party')->assertOk();
    }

    public function test_switch_off_keeps_the_shared_demo_user(): void
    {
        config(['game.per_browser_saves' => false]);

        $first = $this->getJson('/api/player/party')->assertOk();
        $this->assertNull($first->getCookie(self::COOKIE));

        $this->assertSame(1, User::query()->where('email', config('game.demo_user_email'))->count());
        $this->assertSame(0, User::query()->where('email', 'like', 'guest-%')->count());
    }

    /**
     * 新しいブラウザ（Cookie なし）で 1 回アクセスして、発行された Cookie のトークンを返す。
     */
    private function newBrowser(): string
    {
        $response = $this->getJson('/api/player/party')->assertOk();
        $token = $response->getCookie(self::COOKIE)?->getValue();
        $this->assertNotNull($token);

        return $token;
    }

    /**
     * そのトークンの Cookie を持つブラウザとしてリクエストする。
     *
     * @return $this
     */
    private function asBrowser(string $token): static
    {
        // 同じテストの中ではアプリが使い回されるので、前のリクエストで予約された Cookie が残る。
        // 本番は 1 リクエストごとに別プロセスなので起きない。ここで空にして、本番と同じ状態にする
        app('cookie')->flushQueuedCookies();

        return $this->withCredentials()->withCookie(self::COOKIE, $token);
    }

    private function userOf(string $token): User
    {
        return User::query()->where('player_token_hash', hash('sha256', $token))->firstOrFail();
    }
}
