<?php

namespace Modules\JkControl\Http\Requests;

use App\Models\Worker;
use App\Models\Workstation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\JkControl\Services\CardResolver;

/**
 * A card read at a machine: who (card_uid), where (workstation_id), what
 * (login | logout) and when (occurred_at, the device's own timestamp — events
 * buffered while the device was offline keep their real time).
 *
 * Role is enforced by the route group (Admin | Supervisor).
 */
class StoreOperatorEventRequest extends FormRequest
{
    private ?Worker $resolvedWorker = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'card_uid' => ['required', 'string', 'max:64'],
            'action' => ['required', Rule::in(['login', 'logout'])],
            'workstation_id' => [
                'required',
                'integer',
                Rule::exists('workstations', 'id')->whereNull('deleted_at'),
            ],
            'occurred_at' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('card_uid')) {
                return;
            }

            $this->resolvedWorker = app(CardResolver::class)->findWorker((string) $this->input('card_uid'));

            if (! $this->resolvedWorker) {
                $validator->errors()->add('card_uid', __('No active worker has this card.'));
            }
        });
    }

    public function worker(): Worker
    {
        return $this->resolvedWorker;
    }

    public function workstation(): Workstation
    {
        return Workstation::findOrFail($this->integer('workstation_id'));
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
