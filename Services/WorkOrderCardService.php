<?php

namespace Modules\JkControl\Services;

use App\Models\Batch;
use App\Models\WorkOrder;
use App\Models\Workstation;
use App\Services\WorkOrder\WorkOrderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A work-order card read at a machine: "from now on, what this machine makes
 * belongs to this order".
 *
 * The core credits machine counts to the order whose batch is running at the
 * workstation (MachineProductionService::resolveActiveWorkOrder), so starting an
 * order here means: one running batch of that order at the workstation, and the
 * batch of whatever ran there before parked (back to PENDING) so it can resume
 * when its card is read again.
 *
 * Only whole-batch orders without routing steps are handled: a step-based order
 * is started step by step in the core, and a card cannot say which step.
 */
class WorkOrderCardService
{
    /** Orders a card can take into production. Held orders wait for a supervisor. */
    private const STARTABLE = [WorkOrder::STATUS_PENDING, WorkOrder::STATUS_ACCEPTED, WorkOrder::STATUS_IN_PROGRESS];

    public function __construct(private readonly WorkOrderService $workOrders) {}

    /**
     * Cards are written by hand and padded ("77770001@"), so numbers are compared
     * on letters and digits only. An open order wins over a closed one.
     */
    public function find(string $number): ?WorkOrder
    {
        $exact = WorkOrder::query()->where('order_no', trim($number))->first();
        if ($exact) {
            return $exact;
        }

        $needle = CardResolver::normalize($number);
        if ($needle === '') {
            return null;
        }

        return WorkOrder::query()
            ->get(['id', 'order_no', 'status'])
            ->filter(fn (WorkOrder $order) => CardResolver::normalize($order->order_no) === $needle)
            ->sortBy(fn (WorkOrder $order) => in_array($order->status, WorkOrder::TERMINAL_STATUSES, true))
            ->first()
            ?->fresh();
    }

    /** Why this order cannot be started at this workstation, or null. */
    public function blocker(WorkOrder $order, Workstation $workstation): ?string
    {
        if (in_array($order->status, WorkOrder::TERMINAL_STATUSES, true)) {
            return __('This work order is already closed.');
        }

        if (! in_array($order->status, self::STARTABLE, true)) {
            return __('This work order is on hold. Release it in OpenMES first.');
        }

        if ($order->line_id && $order->line_id !== $workstation->line_id) {
            return __('This work order is planned for another line.');
        }

        if (! empty($order->process_snapshot['steps']) || $order->batches()->whereHas('steps')->exists()) {
            return __('Work orders with routing steps cannot be started from a card yet.');
        }

        return $order->plannedStartBlocker();
    }

    /**
     * Status: started (new batch), resumed (parked batch) or already_running.
     *
     * @return array{status: string, batch: Batch, order: WorkOrder}
     */
    public function start(WorkOrder $order, Workstation $workstation, Carbon $at): array
    {
        return DB::transaction(function () use ($order, $workstation, $at) {
            $order = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $running = $this->runningBatches($workstation)->first();
            if ($running?->work_order_id === $order->id) {
                return ['status' => 'already_running', 'batch' => $running, 'order' => $order];
            }

            // One order per machine.
            $this->runningBatches($workstation)->each->update(['status' => Batch::STATUS_PENDING]);

            $batch = Batch::forWorkstation($workstation->id)
                ->where('work_order_id', $order->id)
                ->where('status', Batch::STATUS_PENDING)
                ->latest('id')
                ->first();
            $status = $batch?->started_at ? 'resumed' : 'started';

            if (! $batch) {
                $remaining = (float) $order->planned_qty - (float) $order->produced_qty;
                $batch = $this->workOrders->createBatch($order, $remaining > 0 ? $remaining : (float) $order->planned_qty, $workstation->id);
            }

            $batch->update([
                'status' => Batch::STATUS_IN_PROGRESS,
                'started_at' => $batch->started_at ?? $at,
            ]);

            $changes = [];
            if ($order->status !== WorkOrder::STATUS_IN_PROGRESS) {
                $changes['status'] = WorkOrder::STATUS_IN_PROGRESS;
            }
            // The machine counts this order now; an order left on the default
            // (operator) would have every machine count ignored by the core.
            // Default here too: a config cache built while the module was off has no jkcontrol keys.
            $source = config('jkcontrol.order_counting_source', WorkOrder::COUNTING_MACHINE);
            if (in_array($source, WorkOrder::COUNTING_SOURCES, true)
                && in_array($order->counting_source, [null, WorkOrder::COUNTING_OPERATOR], true)) {
                $changes['counting_source'] = $source;
            }
            if ($changes) {
                $order->update($changes);
            }

            return ['status' => $status, 'batch' => $batch->fresh(), 'order' => $order->fresh()];
        });
    }

    /**
     * The machine is making an order that cannot run in OpenMES (unknown, closed,
     * on hold…): park whatever ran there, so the machine's counts stop landing on
     * the previous order and stay as machine events only.
     *
     * @return int batches parked
     */
    public function park(Workstation $workstation): int
    {
        return DB::transaction(function () use ($workstation) {
            $running = $this->runningBatches($workstation);
            $running->each->update(['status' => Batch::STATUS_PENDING]);

            return $running->count();
        });
    }

    /** Running card-started batches at the workstation, newest first. */
    private function runningBatches(Workstation $workstation)
    {
        return Batch::forWorkstation($workstation->id)
            ->where('status', Batch::STATUS_IN_PROGRESS)
            ->whereDoesntHave('steps')
            ->orderByDesc('id')
            ->get();
    }
}
