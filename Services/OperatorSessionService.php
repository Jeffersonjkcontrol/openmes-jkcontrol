<?php

namespace Modules\JkControl\Services;

use App\Models\EmployeeActivity;
use App\Models\User;
use App\Models\Worker;
use App\Models\Workstation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\JkControl\Models\OperatorSession;

/**
 * Turns card reads at a machine into operator sessions, and closed sessions
 * into employee activities (the core's tachograph timeline).
 *
 * Every call is safe to repeat: the connector re-sends events after a network
 * failure, so a second "login" for the same worker at the same machine returns
 * the session that is already open, and a "logout" with nothing open is a no-op.
 */
class OperatorSessionService
{
    public const LOGGED_IN = 'logged_in';

    public const ALREADY_LOGGED_IN = 'already_logged_in';

    public const LOGGED_OUT = 'logged_out';

    public const NO_OPEN_SESSION = 'no_open_session';

    /**
     * @return array{0: OperatorSession, 1: string}
     */
    public function login(Worker $worker, Workstation $workstation, Carbon $at, ?User $by = null): array
    {
        return DB::transaction(function () use ($worker, $workstation, $at, $by) {
            $open = OperatorSession::open()->where('worker_id', $worker->id)->latest('started_at')->first();

            if ($open && (int) $open->workstation_id === (int) $workstation->id) {
                return [$open, self::ALREADY_LOGGED_IN];
            }

            // The worker walked to another machine without logging out.
            if ($open) {
                $this->close($open, $at, $by);
            }

            if (config('jkcontrol.exclusive_workstation', true)) {
                OperatorSession::open()
                    ->where('workstation_id', $workstation->id)
                    ->get()
                    ->each(fn (OperatorSession $other) => $this->close($other, $at, $by));
            }

            $session = OperatorSession::create([
                'worker_id' => $worker->id,
                'workstation_id' => $workstation->id,
                'started_at' => $at,
            ]);

            return [$session, self::LOGGED_IN];
        });
    }

    /**
     * Logging out closes the worker's open session wherever it is: the card
     * was read, so the worker is no longer where the session says.
     *
     * @return array{0: OperatorSession|null, 1: string}
     */
    public function logout(Worker $worker, Carbon $at, ?User $by = null): array
    {
        return DB::transaction(function () use ($worker, $at, $by) {
            $open = OperatorSession::open()->where('worker_id', $worker->id)->latest('started_at')->first();

            if (! $open) {
                return [null, self::NO_OPEN_SESSION];
            }

            $this->close($open, $at, $by);

            return [$open, self::LOGGED_OUT];
        });
    }

    /**
     * Close a session and record the time on the core timeline. A session with
     * no duration (clock skew, double read) is closed without an activity:
     * employee activities must end after they start.
     */
    public function close(OperatorSession $session, Carbon $at, ?User $by = null): void
    {
        $endedAt = $at->greaterThan($session->started_at) ? $at : $session->started_at->copy();

        $activityId = null;

        if ($endedAt->greaterThan($session->started_at)) {
            $workstation = $session->workstation;

            $activityId = EmployeeActivity::create([
                'worker_id' => $session->worker_id,
                'type' => config('jkcontrol.activity_type', 'work'),
                'label' => $workstation?->name,
                'starts_at' => $session->started_at,
                'ends_at' => $endedAt,
                'line_id' => $workstation?->line_id,
                'notes' => 'RFID session at '.($workstation?->code ?? 'workstation #'.$session->workstation_id),
                'created_by_id' => $by?->id,
            ])->id;
        }

        $session->update([
            'ended_at' => $endedAt,
            'employee_activity_id' => $activityId,
        ]);
    }
}
