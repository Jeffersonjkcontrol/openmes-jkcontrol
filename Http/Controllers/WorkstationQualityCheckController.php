<?php

namespace Modules\JkControl\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workstation;
use Illuminate\Http\JsonResponse;
use Modules\JkControl\Http\Requests\StoreWorkstationQualityCheckRequest;
use Modules\JkControl\Services\QualityCardService;

class WorkstationQualityCheckController extends Controller
{
    public function __construct(private readonly QualityCardService $quality) {}

    /**
     * POST /api/v1/jk/workstations/{workstation}/quality-check
     */
    public function store(StoreWorkstationQualityCheckRequest $request, Workstation $workstation): JsonResponse
    {
        $batch = $request->batch();
        ['status' => $status, 'check' => $check] =
            $this->quality->record($batch, $request->boolean('passed'), $request->occurredAt());

        return response()->json([
            'status' => $status,
            'data' => [
                'quality_check_id' => $check->id,
                'batch_id' => $batch->id,
                'work_order_id' => $batch->work_order_id,
                'order_no' => $batch->workOrder?->order_no,
                'workstation_id' => $workstation->id,
                'all_passed' => (bool) $check->all_passed,
                'checked_at' => $check->checked_at?->toIso8601String(),
            ],
        ], $status === 'recorded' ? 201 : 200);
    }
}
