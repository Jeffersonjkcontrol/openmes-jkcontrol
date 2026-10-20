<?php

namespace Modules\JkControl\Http\Requests;

use App\Models\WorkOrder;
use App\Models\Workstation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;
use Modules\JkControl\Services\WorkOrderCardService;

/**
 * A work-order card read at a machine. The order must already exist in OpenMES
 * (created by planning, a spreadsheet import or the ERP API); a card never
 * creates one.
 *
 * when_blocked: "reject" (default) answers 422 when the order cannot start;
 * "park" takes whatever ran at the machine off it instead (see
 * WorkOrderCardService::park), for devices that report the order with every
 * cycle and so know the machine has moved on.
 *
 * Role is enforced by the route group (Admin | Supervisor).
 */
class StartWorkstationWorkOrderRequest extends FormRequest
{
    private ?WorkOrder $resolvedOrder = null;

    private ?string $blockReason = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_no' => ['required', 'string', 'max:64'],
            'occurred_at' => ['nullable', 'date'],
            'when_blocked' => ['nullable', 'in:reject,park'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $cards = app(WorkOrderCardService::class);
            $this->resolvedOrder = $cards->find((string) $this->input('order_no'));
            $workstation = $this->route('workstation');

            $reason = match (true) {
                ! $this->resolvedOrder => __('No work order has this number.'),
                $workstation instanceof Workstation => $cards->blocker($this->resolvedOrder, $workstation),
                default => null,
            };

            if ($reason === null) {
                return;
            }

            if ($this->parksWhenBlocked()) {
                $this->blockReason = $reason;

                return;
            }

            $validator->errors()->add('order_no', $reason);
        });
    }

    public function parksWhenBlocked(): bool
    {
        return $this->input('when_blocked') === 'park';
    }

    /** Why the order cannot start, when the caller asked to park instead of a 422. */
    public function blockReason(): ?string
    {
        return $this->blockReason;
    }

    public function workOrder(): WorkOrder
    {
        return $this->resolvedOrder;
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
