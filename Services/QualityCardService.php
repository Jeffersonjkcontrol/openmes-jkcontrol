<?php

namespace Modules\JkControl\Services;

use App\Models\Batch;
use App\Models\BatchStep;
use App\Models\QualityCheck;
use App\Models\Workstation;
use Illuminate\Support\Carbon;

/**
 * The quality card read at a machine: the quality inspector approves (or
 * rejects) what the machine is making now.
 *
 * It becomes a core quality check on the batch running at the workstation —
 * the same batch the machine's counts go to. The card says nothing about who
 * read it, so the check has no checker; the note says it came from the card.
 */
class QualityCardService
{
    /** The batch running at the workstation, as the core resolves it for machine counts. */
    public function runningBatch(Workstation $workstation): ?Batch
    {
        $step = BatchStep::where('workstation_id', $workstation->id)
            ->where('status', BatchStep::STATUS_IN_PROGRESS)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        if ($step?->batch) {
            return $step->batch;
        }

        return Batch::forWorkstation($workstation->id)
            ->where('status', Batch::STATUS_IN_PROGRESS)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Status: recorded, or already_recorded when the same reading comes again
     * (the connector retries after a timeout).
     *
     * @return array{status: string, check: QualityCheck}
     */
    public function record(Batch $batch, bool $passed, Carbon $at): array
    {
        $existing = QualityCheck::where('batch_id', $batch->id)->where('checked_at', $at)->first();
        if ($existing) {
            return ['status' => 'already_recorded', 'check' => $existing];
        }

        $check = QualityCheck::create([
            'batch_id' => $batch->id,
            'checked_at' => $at,
            'all_passed' => $passed,
            'notes' => $passed
                ? __('Approved at the machine with the quality card.')
                : __('Rejected at the machine with the quality card.'),
        ]);

        return ['status' => 'recorded', 'check' => $check];
    }
}
