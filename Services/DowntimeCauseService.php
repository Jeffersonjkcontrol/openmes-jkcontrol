<?php

namespace Modules\JkControl\Services;

use App\Models\DowntimeReason;
use App\Models\ProductionDowntime;
use App\Models\User;
use App\Models\Workstation;
use Illuminate\Support\Carbon;

/**
 * Gives a machine stop the cause the operator keyed in at the machine.
 *
 * The stop itself is opened automatically by the core when the machine state
 * goes to STOPPED/FAULT/WAITING (WorkstationStateMachine), with an AUTO-* placeholder
 * reason and needs_reason = true. This service only fills in the cause, through
 * the core's own ProductionDowntime::classify().
 */
class DowntimeCauseService
{
    /**
     * The stop a cause entered at $at refers to: the one running at that
     * moment, or — when the operator restarted first and typed the cause
     * afterwards — the last stop that ended within the grace window and still
     * has no cause.
     */
    public function findStop(Workstation $workstation, Carbon $at): ?ProductionDowntime
    {
        $running = ProductionDowntime::query()
            ->where('workstation_id', $workstation->id)
            ->where('started_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $at))
            ->latest('started_at')
            ->first();

        if ($running) {
            return $running;
        }

        $grace = (int) config('jkcontrol.reason_grace_minutes', 30);

        return ProductionDowntime::query()
            ->where('workstation_id', $workstation->id)
            ->where('needs_reason', true)
            ->whereNotNull('ended_at')
            ->whereBetween('ended_at', [$at->copy()->subMinutes($grace), $at])
            ->latest('ended_at')
            ->first();
    }

    /**
     * A cause decided by a person (in the Shift Monitor, or given when the stop
     * was reported by hand) is never overwritten by the machine. The machine
     * may only fill a placeholder, or correct a cause it set itself.
     */
    public function canClassify(ProductionDowntime $stop, User $by): bool
    {
        return $stop->needs_reason || (int) $stop->classified_by_id === (int) $by->id;
    }

    public function classify(ProductionDowntime $stop, DowntimeReason $reason, User $by, ?string $workerCard = null): ProductionDowntime
    {
        $stop->classify($reason, $by, $this->notesWithCard($stop->notes, $workerCard));

        return $stop->fresh('reason');
    }

    /**
     * Who handled the stop, from the card read at the machine (e.g. the mold
     * change): noted once on the stop, with the worker's name when the card is
     * known. Stops have no worker column, so the note is where people see it.
     */
    private function notesWithCard(?string $notes, ?string $card): ?string
    {
        $card = trim((string) $card);
        if ($card === '') {
            return $notes;
        }

        $worker = app(CardResolver::class)->findWorker($card);
        $line = $worker
            ? __('Card :card read at the machine: :name.', ['card' => $card, 'name' => $worker->name])
            : __('Card :card read at the machine (no worker has this card).', ['card' => $card]);

        if ($notes !== null && str_contains($notes, $line)) {
            return $notes;
        }

        return $notes === null || $notes === '' ? $line : $notes."\n".$line;
    }
}
