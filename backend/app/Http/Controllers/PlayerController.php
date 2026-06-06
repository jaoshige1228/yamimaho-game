<?php

namespace App\Http\Controllers;

use App\Services\Player\EquipmentManageService;
use App\Services\Player\GameNavigationService;
use App\Services\Player\ItemInventoryService;
use App\Services\Player\PartyGoldService;
use App\Services\Player\UserCharacterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PlayerController extends Controller
{
    public function __construct(
        private readonly UserCharacterService $characters,
        private readonly PartyGoldService $gold = new PartyGoldService,
        private readonly ItemInventoryService $items = new ItemInventoryService,
        private readonly EquipmentManageService $equipment = new EquipmentManageService,
        private readonly GameNavigationService $navigation = new GameNavigationService,
    ) {}

    public function party(): JsonResponse
    {
        return $this->partyResponse();
    }

    public function stats(): JsonResponse
    {
        return $this->partyResponse();
    }

    public function navigation(): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        return response()->json($this->navigation->payload($user));
    }

    public function equipment(): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        return response()->json($this->equipment->payload($user));
    }

    public function equip(Request $request): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        $slotId = (string) $request->input('slot_id', '');
        if ($slotId === '') {
            return response()->json(['message' => 'slot_id が必要です'], 422);
        }

        try {
            return response()->json($this->equipment->equip(
                $user,
                $slotId,
                $request->input('weapon_code'),
                $request->input('armor_code'),
            ));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
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
            'gold' => $this->gold->getGold($user),
            'items' => $this->items->inventoryPayload($user),
            'characters' => $party->map(
                fn ($character, $slotId) => $this->characters->toPartyUnitPayload($character, $slotId),
            )->values(),
        ]);
    }
}
