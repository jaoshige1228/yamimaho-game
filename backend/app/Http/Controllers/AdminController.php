<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminDungeonSettingsRequest;
use App\Http\Requests\AdminGrantExpRequest;
use App\Services\Admin\AdminPartyService;
use App\Services\Dungeon\DungeonProgressService;
use App\Services\Player\UserCharacterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AdminController extends Controller
{
    public function __construct(
        private readonly AdminPartyService $adminParty,
        private readonly UserCharacterService $characters,
        private readonly DungeonProgressService $dungeonProgress,
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

    public function dungeonSettingsShow(): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        return response()->json(array_merge(
            [
                'skip_battles' => $this->dungeonProgress->shouldSkipBattles($user),
                'force_dialogue_events' => $this->dungeonProgress->shouldForceDialogueEvents($user),
            ],
            $this->dungeonProgress->statusPayload($user),
        ));
    }

    public function dungeonSettings(AdminDungeonSettingsRequest $request): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        $validated = $request->validated();
        $this->dungeonProgress->setSkipBattles($user, (bool) $validated['skip_battles']);
        $this->dungeonProgress->setForceDialogueEvents($user, (bool) $validated['force_dialogue_events']);

        if ($validated['reset_progress']) {
            $this->dungeonProgress->reset($user);
        }

        return response()->json(array_merge(
            [
                'message' => 'ダンジョン設定を反映しました。',
                'skip_battles' => (bool) $validated['skip_battles'],
                'force_dialogue_events' => (bool) $validated['force_dialogue_events'],
            ],
            $this->dungeonProgress->statusPayload($user),
        ));
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
