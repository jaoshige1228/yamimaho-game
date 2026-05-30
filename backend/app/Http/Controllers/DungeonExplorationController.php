<?php

namespace App\Http\Controllers;

use App\Services\Dungeon\Exploration\DungeonExplorationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DungeonExplorationController extends Controller
{
    public function __construct(
        private readonly DungeonExplorationService $exploration,
    ) {}

    public function continue(Request $request): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        $sessionId = (string) $request->input('session_id', '');
        if ($sessionId === '') {
            return response()->json(['message' => 'session_id が必要です'], 422);
        }

        try {
            return response()->json($this->exploration->continue($user, $sessionId));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function choose(Request $request): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        $sessionId = (string) $request->input('session_id', '');
        $slotId = (string) $request->input('slot_id', '');
        if ($sessionId === '' || $slotId === '') {
            return response()->json(['message' => 'session_id と slot_id が必要です'], 422);
        }

        try {
            return response()->json($this->exploration->choose($user, $sessionId, $slotId));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }
}
