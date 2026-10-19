<?php

namespace Modules\JkControl\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\JkControl\Http\Requests\StoreOperatorEventRequest;
use Modules\JkControl\Models\OperatorSession;
use Modules\JkControl\Services\OperatorSessionService;

class OperatorEventController extends Controller
{
    public function __construct(private readonly OperatorSessionService $sessions) {}

    /**
     * POST /api/v1/jk/operator-events
     */
    public function store(StoreOperatorEventRequest $request): JsonResponse
    {
        $worker = $request->worker();
        $at = $request->occurredAt();

        [$session, $status] = $request->input('action') === 'login'
            ? $this->sessions->login($worker, $request->workstation(), $at, $request->user())
            : $this->sessions->logout($worker, $at, $request->user());

        return response()->json([
            'status' => $status,
            'data' => [
                'worker' => ['id' => $worker->id, 'code' => $worker->code, 'name' => $worker->name],
                'session' => $session ? $this->serialize($session) : null,
            ],
        ], $status === OperatorSessionService::LOGGED_IN ? 201 : 200);
    }

    private function serialize(OperatorSession $session): array
    {
        return [
            'id' => $session->id,
            'workstation_id' => $session->workstation_id,
            'started_at' => $session->started_at?->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'employee_activity_id' => $session->employee_activity_id,
        ];
    }
}
