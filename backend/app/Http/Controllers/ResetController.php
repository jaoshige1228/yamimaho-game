<?php

namespace App\Http\Controllers;

use App\Models\BattleState;
use App\Services\Player\DemoPlayerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ResetController extends Controller
{
    public function __construct(
        private readonly DemoPlayerService $players,
    ) {}

    public function reset(): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        // 自分の戦闘だけ消す（ほかのブラウザのセーブの戦闘は消さない）
        BattleState::query()->where('user_id', $user->id)->delete();
        $this->players->resetProgress($user);

        return response()->json([
            'message' => '記録をリセットしました。',
        ]);
    }
}
