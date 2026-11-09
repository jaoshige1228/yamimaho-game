<?php

namespace App\Http\Middleware;

use App\Services\Player\DemoPlayerService;
use App\Services\Player\UserCharacterService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * リクエストの「プレイヤー」を決める。
 *
 * - ブラウザごとのセーブ（game.per_browser_saves = true、既定）: Cookie のトークンに紐づくユーザーを使う。
 *   Cookie がない・不正・未登録なら、新しいゲストを作って Cookie を発行する（そのブラウザ専用のセーブ）。
 *   セッションのユーザー（古い「デモユーザーでログイン済み」）は見ない。
 * - false: 従来どおり、全ブラウザが共有のデモユーザーを使う。
 */
class EnsureDemoPlayer
{
    /** Cookie の有効期間（分）。400 日（ブラウザが許す上限） */
    private const COOKIE_MINUTES = 400 * 24 * 60;

    public function __construct(
        private readonly DemoPlayerService $players,
        private readonly UserCharacterService $characters,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('game.per_browser_saves', true)) {
            return $this->handleSharedDemoUser($request, $next);
        }

        $cookieName = (string) config('game.player_cookie', 'yamimaho_player');
        $user = $this->players->findByToken($request->cookie($cookieName));

        if ($user === null) {
            if (! $this->guestCreationAllowed()) {
                return response()->json([
                    'message' => '混み合っています。しばらくしてからもう一度お試しください。',
                ], 429);
            }

            ['user' => $user, 'token' => $token] = $this->players->createGuest();
            Cookie::queue(Cookie::make(
                $cookieName,
                $token,
                self::COOKIE_MINUTES,
                '/',
                null,
                config('session.secure'),
                true,
                false,
                'lax',
            ));
        } else {
            $this->characters->ensureParty($user);
        }

        // セッションには保存しない（毎リクエスト、Cookie から決める）
        Auth::setUser($user);

        return $next($request);
    }

    /**
     * 従来の動き（共有のデモユーザー）。
     */
    private function handleSharedDemoUser(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null) {
            $user = $this->players->resolveOrCreateUser();
            Auth::login($user);
        } else {
            $this->characters->ensureParty($user);
        }

        return $next($request);
    }

    /**
     * 新しいゲストを作ってよいか（1 時間あたりの全体の上限）。Cookie を受け付けないクライアントが
     * リクエストのたびにユーザーを作り続けるのを止める歯止め。
     */
    private function guestCreationAllowed(): bool
    {
        $limit = (int) config('game.guest_create_per_hour', 300);
        if ($limit <= 0) {
            return true;
        }

        return RateLimiter::attempt('new-guest', $limit, fn () => true, 3600) !== false;
    }
}
