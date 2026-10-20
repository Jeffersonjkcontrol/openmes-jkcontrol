<?php

namespace Modules\JkControl\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workstation;
use Illuminate\Http\JsonResponse;
use Modules\JkControl\Http\Requests\StartWorkstationWorkOrderRequest;
use Modules\JkControl\Services\WorkOrderCardService;

class WorkstationWorkOrderController extends Controller
{
    public function __construct(private readonly WorkOrderCardService $cards) {}

    /**
     * POST /api/v1/jk/workstations/{workstation}/work-order
     */
    public function store(StartWorkstationWorkOrderRequest $request, Workstation $workstation): JsonResponse
    {
        if (($reason = $request->blockReason()) !== null) {
            return response()->json([
                'status' => 'parked',
                'reason' => $reason,
                'data' => [
                    'order_no' => (string) $request->input('order_no'),
                    'workstation_id' => $workstation->id,
                    'parked_batches' => $this->cards->park($workstation),
                ],
            ]);
        }

        ['status' => $status, 'batch' => $batch, 'order' => $order] =
            $this->cards->start($request->workOrder(), $workstation, $request->occurredAt());

        return response()->json([
            'status' => $status,
            'data' => [
                'work_order_id' => $order->id,
                'order_no' => $order->order_no,
                'work_order_status' => $order->status,
                'counting_source' => $order->counting_source,
                'batch_id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'workstation_id' => $workstation->id,
                'started_at' => $batch->started_at?->toIso8601String(),
            ],
        ], $status === 'started' ? 201 : 200);
    }
}
