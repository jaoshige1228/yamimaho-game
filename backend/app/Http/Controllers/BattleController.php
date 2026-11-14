<?php

namespace App\Http\Controllers;

use App\Http\Requests\BattleActionRequest;
use App\Models\BattleState;
use App\Services\Battle\BattleOrchestrator;
use App\Services\Battle\BattleFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

    public function demoBoss(): JsonResponse
    {
        $user = Auth::user();
        $id = $this->factory->newBattleId();
        $enemyList = config('battle.demo_boss', []);
        $result = $this->orchestrator->createBattleForUser(
            $user,
            $enemyList,
            ['boss' => true, 'source' => 'demo'],
        );

        $record = BattleState::query()->create([
            'id' => $id,
            'user_id' => $user?->id,
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

    private function startBattle(string $enemyConfigKey): JsonResponse
    {
        $user = Auth::user();
        $id = $this->factory->newBattleId();
        $result = $this->orchestrator->createBattle($user, $enemyConfigKey);

        $record = BattleState::query()->create([
            'id' => $id,
            'user_id' => $user?->id,
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
        $validated = $request->validated();

        // 同じ戦闘への行動を同時に 2 つ受けても、勝利の報酬（ゴールド・経験値・魔法の習得・最大MP）が 2 回入らないように、
        // 戦闘の行を取って（ロックして）から、状態の確認・行動・保存までを 1 つのトランザクションで行う。
        // 途中で失敗したときは、報酬などの途中までの更新も取り消される。
        try {
            return DB::transaction(function () use ($id, $validated): JsonResponse {
                $record = $this->findBattle($id, lock: true);
                $state = $record->state;

                if ($state['status'] !== 'active') {
                    return response()->json(['message' => '戦闘は終了しています。'], 422);
                }

                if (! ($state['awaiting_input'] ?? false)) {
                    return response()->json(['message' => '入力待ちではありません。'], 422);
                }

                $result = $this->orchestrator->submitPlayerAction(
                    $state,
                    $validated['action'],
                    $validated['spell_id'] ?? null,
                    $validated['target_id'] ?? null,
                    Auth::user(),
                );

                $record->state = $result['state'];
                $record->status = $result['state']['status'];
                $record->save();

                return response()->json([
                    'battle_id' => $record->id,
                    'state' => $this->orchestrator->engine()->publicState($result['state']),
                    'events' => $result['events'],
                ]);
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * 戦闘を探す。ほかのユーザー（ブラウザ）の戦闘は 404 にする（user_id が空の戦闘は従来どおり誰でも見られる）。
     * $lock が true のときは、行をロックして取る（トランザクションの中で使う）。
     */
    private function findBattle(string $id, bool $lock = false): BattleState
    {
        $query = BattleState::query();
        if ($lock) {
            $query->lockForUpdate();
        }
        $record = $query->findOrFail($id);

        if ($record->user_id !== null && (int) $record->user_id !== (int) Auth::id()) {
            abort(404);
        }

        return $record;
    }
}
