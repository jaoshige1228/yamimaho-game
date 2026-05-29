<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminGrantExpRequest;
use App\Services\Admin\AdminPartyService;
use App\Services\Player\UserCharacterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AdminController extends Controller
{
    public function __construct(
        private readonly AdminPartyService $adminParty,
        private readonly UserCharacterService $characters,
    ) {}

    public function status(): JsonResponse
    {
        return response()->json([
            'available' => true,
        ]);
    }

    public function party(): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        $party = $this->characters->partyInSlotOrder($user);

        return response()->json([
            'characters' => $party->map(function ($character, $slotId) {
                return [
                    'slot_id' => $slotId,
                    'name' => $character->characterMaster->name,
                    'level' => $character->level,
                    'exp' => $character->exp,
                    'hp' => $character->hp,
                    'mp' => $character->mp,
                    'str' => $character->str,
                    'mag' => $character->mag,
                    'def' => $character->def,
                    'spd' => $character->spd,
                ];
            })->values(),
        ]);
    }

    public function grantExp(AdminGrantExpRequest $request): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        $exp = (int) $request->validated('exp');
        $results = $this->adminParty->grantExpToParty($user, $exp);

        return response()->json([
            'message' => "パーティー全員に {$exp} の経験値を配りました。",
            'characters' => $results,
        ]);
    }
}
