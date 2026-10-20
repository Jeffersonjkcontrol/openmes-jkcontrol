<?php

namespace Modules\JkControl\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workstation;
use Illuminate\Http\JsonResponse;
use Modules\JkControl\Http\Requests\ClassifyWorkstationDowntimeRequest;
use Modules\JkControl\Services\DowntimeCauseService;

class WorkstationDowntimeReasonController extends Controller
{
    public function __construct(private readonly DowntimeCauseService $causes) {}

    /**
     * POST /api/v1/jk/workstations/{workstation}/downtime-reason
     */
    public function store(ClassifyWorkstationDowntimeRequest $request, Workstation $workstation): JsonResponse
    {
        $stop = $request->stop();

        if (! $this->causes->canClassify($stop, $request->user())) {
            return response()->json([
                'message' => __('Somebody else has already given this stop a cause.'),
                'data' => ['downtime_id' => $stop->id, 'reason_code' => $stop->reason?->code],
            ], 409);
        }

        $stop = $this->causes->classify($stop, $request->reason(), $request->user(), $request->input('worker_card'));

        return response()->json([
            'status' => 'classified',
            'data' => [
                'downtime_id' => $stop->id,
                'workstation_id' => $workstation->id,
                'reason' => [
                    'id' => $stop->reason->id,
                    'code' => $stop->reason->code,
                    'name' => $stop->reason->name,
                ],
                'started_at' => $stop->started_at?->toIso8601String(),
                'ended_at' => $stop->ended_at?->toIso8601String(),
                'notes' => $stop->notes,
            ],
        ]);
    }
}
