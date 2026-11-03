<?php

namespace App\Http\Controllers;

use App\Http\Requests\TestBattleStartRequest;
use App\Models\BattleState;
use App\Services\Battle\BattleFactory;
use App\Services\Battle\BattleOrchestrator;
use App\Services\TestBattle\TestBattleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class TestBattleController extends Controller
{
    public function __construct(
        private readonly TestBattleService $testBattles = new TestBattleService,
        private readonly BattleFactory $factory = new BattleFactory,
        private readonly BattleOrchestrator $orchestrator = new BattleOrchestrator,
    ) {}

    public function status(): JsonResponse
    {
        return response()->json(['available' => true]);
    }

    public function catalog(): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        return response()->json($this->testBattles->catalog($user));
    }

    public function start(TestBattleStartRequest $request): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json(['message' => '未ログイン'], 401);
        }

        $validated = $request->validated();
        $preset = $validated['preset'] ?? null;

        try {
            $built = $preset !== null
                ? $this->testBattles->buildBossEncounter($preset)
                : $this->testBattles->buildRegularEncounter(
                    (int) $validated['floor'],
                    (string) $validated['code'],
                    (int) $validated['count'],
                );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $battleId = $this->factory->newBattleId();
        $result = $this->orchestrator->createBattleForUser(
            $user,
            $built['enemies'],
            $built['meta'],
            $validated['equipment'] ?? [],
        );

        BattleState::query()->create([
            'id' => $battleId,
            'user_id' => $user->id,
            'state' => $result['state'],
            'status' => $result['state']['status'],
        ]);

        return response()->json(array_merge([
            'battle_id' => $battleId,
            'state' => $this->orchestrator->engine()->publicState($result['state']),
            'events' => array_merge(
                [['type' => 'battle_started', 'battle_id' => $battleId]],
                $result['events'],
            ),
        ], $built['encounter']));
    }
}
