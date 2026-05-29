<?php

namespace App\Http\Controllers;

use App\Http\Requests\BattleActionRequest;
use App\Models\BattleState;
use App\Services\Battle\BattleOrchestrator;
use App\Services\Battle\BattleFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class BattleController extends Controller
{
    public function __construct(
        private readonly BattleFactory $factory,
        private readonly BattleOrchestrator $orchestrator,
    ) {}

    public function demo(): JsonResponse
    {
        return $this->startBattle('demo_enemies');
    }

    public function demoKappa2(): JsonResponse
    {
        return $this->startBattle('demo_enemies_kappa2');
    }

    private function startBattle(string $enemyConfigKey): JsonResponse
    {
        $user = Auth::user();
        $id = $this->factory->newBattleId();
        $result = $this->orchestrator->createBattle($user, $enemyConfigKey);

        $record = BattleState::query()->create([
            'id' => $id,
            'state' => $result['state'],
            'status' => $result['state']['status'],
        ]);

        $events = array_merge(
            [['type' => 'battle_started', 'battle_id' => $id]],
            $result['events'],
        );

        return response()->json([
            'battle_id' => $id,
            'state' => $this->orchestrator->engine()->publicState($record->state),
            'events' => $events,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $record = $this->findBattle($id);

        return response()->json([
            'battle_id' => $record->id,
            'state' => $this->orchestrator->engine()->publicState($record->state),
            'events' => [],
        ]);
    }

    public function action(BattleActionRequest $request, string $id): JsonResponse
    {
        $record = $this->findBattle($id);
        $state = $record->state;

        if ($state['status'] !== 'active') {
            return response()->json(['message' => '戦闘は終了しています。'], 422);
        }

        if (! ($state['awaiting_input'] ?? false)) {
            return response()->json(['message' => '入力待ちではありません。'], 422);
        }

        $validated = $request->validated();

        try {
            $result = $this->orchestrator->submitPlayerAction(
                $state,
                $validated['action'],
                $validated['spell_id'] ?? null,
                $validated['target_id'] ?? null,
                Auth::user(),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $record->state = $result['state'];
        $record->status = $result['state']['status'];
        $record->save();

        return response()->json([
            'battle_id' => $record->id,
            'state' => $this->orchestrator->engine()->publicState($result['state']),
            'events' => $result['events'],
        ]);
    }

    private function findBattle(string $id): BattleState
    {
        return BattleState::query()->findOrFail($id);
    }
}
