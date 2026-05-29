<?php

namespace App\Http\Controllers;

use App\Services\Player\UserCharacterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class PlayerController extends Controller
{
    public function __construct(
        private readonly UserCharacterService $characters,
    ) {}

    public function party(): JsonResponse
    {
        return $this->partyResponse();
    }

    public function stats(): JsonResponse
    {
        return $this->partyResponse();
    }

    private function partyResponse(): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        $this->characters->ensureParty($user);
        $party = $this->characters->partyInSlotOrder($user);

        return response()->json([
            'characters' => $party->map(
                fn ($character, $slotId) => $this->characters->toPartyUnitPayload($character, $slotId),
            )->values(),
        ]);
    }
}
