<?php

namespace Modules\JkControl\Http\Requests;

use App\Models\DowntimeReason;
use App\Models\ProductionDowntime;
use App\Models\Workstation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;
use Modules\JkControl\Services\DowntimeCauseService;

/**
 * The cause of a stop, keyed in at the machine as a reason code.
 *
 * Mirrors the core's ClassifyDowntimeRequest: the reason must be real, active
 * and not an AUTO-* placeholder (swapping one placeholder for another would
 * clear "needs a cause" without anyone deciding anything).
 *
 * Role is enforced by the route group (Admin | Supervisor).
 */
class ClassifyWorkstationDowntimeRequest extends FormRequest
{
    private ?DowntimeReason $resolvedReason = null;

    private ?ProductionDowntime $resolvedStop = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string', 'max:64'],
            'occurred_at' => ['nullable', 'date'],
            // Card of the person who handled the stop (e.g. the mold change): noted on it.
            'worker_card' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->resolvedReason = $this->findReason((string) $this->input('reason_code'));

            if (! $this->resolvedReason) {
                $validator->errors()->add('reason_code', __('No active downtime reason has this code.'));

                return;
            }

            $workstation = $this->route('workstation');

            if (! $workstation instanceof Workstation) {
                return;
            }

            $this->resolvedStop = app(DowntimeCauseService::class)->findStop($workstation, $this->occurredAt());

            if (! $this->resolvedStop) {
                $validator->errors()->add('occurred_at', __('No stop found at this workstation for that time.'));
            }
        });
    }

    /**
     * Reason codes are matched as typed on the machine. A code defined for the
     * caller's tenant wins over a shared one with the same code.
     */
    private function findReason(string $code): ?DowntimeReason
    {
        $code = trim($code);

        if ($code === '' || str_starts_with(strtoupper($code), 'AUTO-')) {
            return null;
        }

        $tenantId = $this->user()?->tenant_id;

        return DowntimeReason::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('tenant_id')->when($tenantId, fn ($q) => $q->orWhere('tenant_id', $tenantId)))
            ->get()
            ->sortByDesc(fn (DowntimeReason $reason) => $reason->tenant_id !== null)
            ->first();
    }

    public function reason(): DowntimeReason
    {
        return $this->resolvedReason;
    }

    public function stop(): ProductionDowntime
    {
        return $this->resolvedStop;
    }

    /**
     * In the application's timezone: Eloquent stores a date's wall-clock
     * without converting it, so a UTC "…Z" timestamp would land hours off.
     */
    public function occurredAt(): Carbon
    {
        return $this->date('occurred_at')?->setTimezone(config('app.timezone')) ?? now();
    }
}
