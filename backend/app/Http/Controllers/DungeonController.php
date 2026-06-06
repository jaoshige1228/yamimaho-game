<?php

namespace App\Http\Controllers;

use App\Services\Dungeon\DungeonAdvanceService;
use App\Services\Dungeon\DungeonProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return response()->json($this->progress->statusPayload($user));
    }

    public function enter(Request $request): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        $floor = (int) $request->input('floor', $this->progress->getFloor($user));
        if ($floor < 1) {
            return response()->json(['message' => '無効な層です'], 422);
        }

        try {
            $this->progress->enterFloor($user, $floor);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(array_merge(
            [
                'message' => "{$floor}層に入った。",
            ],
            $this->progress->statusPayload($user),
        ));
    }

    public function advance(): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        try {
            return response()->json($this->advance->advance($user));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function retreat(): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        return response()->json(array_merge(
            ['message' => 'ダンジョンから撤退した。'],
            $this->progress->retreat($user),
        ));
    }
}
