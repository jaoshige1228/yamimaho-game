<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * テストモード（/admins 管理画面・/test_battles テスト戦闘の API）を閉じているときは 404 にする。
 * 切り替えは config('game.test_mode_enabled')（環境変数 GAME_TEST_MODE）。
 */
class EnsureTestModeEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('game.test_mode_enabled')) {
            abort(404);
        }

        return $next($request);
    }
}
