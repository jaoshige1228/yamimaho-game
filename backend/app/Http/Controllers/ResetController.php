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
            $user = $this->players->resolveOrCreateUser();
        }

        BattleState::query()->delete();
        $this->players->resetProgress($user);

        return response()->json([
            'message' => '記録をリセットしました。',
        ]);
    }
}
