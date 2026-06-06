<?php

namespace App\Http\Controllers;

use App\Services\Player\EquipmentManageService;
use App\Services\Player\EquipmentShopService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ShopController extends Controller
{
    public function __construct(
        private readonly EquipmentShopService $shop,
    ) {}

    public function equipment(): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        return response()->json($this->shop->catalog($user));
    }

    public function buyEquipment(Request $request): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        $slotId = (string) $request->input('slot_id', '');
        $kind = (string) $request->input('kind', '');
        $code = (string) $request->input('code', '');

        if ($slotId === '' || $kind === '' || $code === '') {
            return response()->json(['message' => 'slot_id, kind, code が必要です'], 422);
        }

        try {
            return response()->json($this->shop->buy($user, $slotId, $kind, $code));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
