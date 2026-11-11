<?php

namespace App\Http\Controllers;

use App\Services\Player\FamiliarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FamiliarController extends Controller
{
    public function __construct(
        private readonly FamiliarService $familiars,
    ) {}

    public function show(): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        return response()->json($this->familiars->statusFor($user));
    }

    /**
     * 魔石を与える。`count` で個数を指定できる（省略は 1 個。上限は `familiar.bulk_feed_count`）。
     */
    public function feed(Request $request): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        $count = $request->input('count', 1);
        $max = (int) config('familiar.bulk_feed_count');
        if (! is_numeric($count) || (int) $count != $count || (int) $count < 1 || (int) $count > $max) {
            return response()->json(['message' => "魔石の個数は 1〜{$max} 個で指定してください。"], 422);
        }

        try {
            return response()->json($this->familiars->feed($user, (int) $count));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
