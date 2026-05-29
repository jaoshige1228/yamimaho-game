<?php

namespace App\Http\Controllers;

use App\Services\Dungeon\DungeonAdvanceService;
use App\Services\Dungeon\DungeonProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class DungeonController extends Controller
{
    public function __construct(
        private readonly DungeonProgressService $progress,
        private readonly DungeonAdvanceService $advance,
    ) {}

    public function show(): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        return response()->json([
            'step' => $this->progress->getStep($user),
            'max_step' => $this->progress->maxStep(),
        ]);
    }

    public function enter(): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        return response()->json([
            'message' => 'ダンジョンに入った。',
            'step' => $this->progress->getStep($user),
        ]);
    }

    public function advance(): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        return response()->json($this->advance->advance($user));
    }
}
