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
 * Role is enforced by the route group (Admin | Supervisor).
 */
class StartWorkstationWorkOrderRequest extends FormRequest
{
    private ?WorkOrder $resolvedOrder = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_no' => ['required', 'string', 'max:64'],
            'occurred_at' => ['nullable', 'date'],
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

            if (! $this->resolvedOrder) {
                $validator->errors()->add('order_no', __('No work order has this number.'));

                return;
            }

            $workstation = $this->route('workstation');

            if ($workstation instanceof Workstation && ($blocker = $cards->blocker($this->resolvedOrder, $workstation))) {
                $validator->errors()->add('order_no', $blocker);
            }
        });
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
