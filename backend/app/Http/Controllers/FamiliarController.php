<?php

namespace App\Http\Controllers;

use App\Services\Player\FamiliarService;
use Illuminate\Http\JsonResponse;
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

    public function feed(): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        try {
            return response()->json($this->familiars->feed($user));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
