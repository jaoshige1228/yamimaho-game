<?php

namespace App\Http\Controllers;

use App\Services\Dungeon\DungeonHealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DungeonHealController extends Controller
{
    public function __construct(
        private readonly DungeonHealService $heal,
    ) {}

    public function options(): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        return response()->json([
            'spells' => $this->heal->availableSpells($user),
        ]);
    }

    public function heal(Request $request): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        $mode = (string) $request->input('mode', '');
        $targetSlotId = (string) $request->input('target_slot_id', '');

        if ($targetSlotId === '') {
            return response()->json(['message' => 'target_slot_id が必要です'], 422);
        }

        try {
            if ($mode === 'item') {
                $itemCode = (string) $request->input('item_code', '');
                if ($itemCode === '') {
                    return response()->json(['message' => 'item_code が必要です'], 422);
                }

                return response()->json($this->heal->healWithItem($user, $itemCode, $targetSlotId));
            }

            if ($mode === 'spell') {
                $casterSlotId = (string) $request->input('caster_slot_id', '');
                $spellId = (string) $request->input('spell_id', '');
                if ($casterSlotId === '' || $spellId === '') {
                    return response()->json(['message' => 'caster_slot_id と spell_id が必要です'], 422);
                }

                return response()->json($this->heal->healWithSpell($user, $casterSlotId, $spellId, $targetSlotId));
            }

            return response()->json(['message' => 'mode は item または spell です'], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
