<?php

namespace Modules\JkControl\Http\Requests;

use App\Models\Batch;
use App\Models\Workstation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;
use Modules\JkControl\Services\QualityCardService;

/**
 * A quality card read at a machine. There must be an order running at the
 * workstation: the check belongs to its batch.
 *
 * Role is enforced by the route group (Admin | Supervisor).
 */
class StoreWorkstationQualityCheckRequest extends FormRequest
{
    private ?Batch $resolvedBatch = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'passed' => ['required', 'boolean'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $workstation = $this->route('workstation');
            if (! $workstation instanceof Workstation) {
                return;
            }

            $this->resolvedBatch = app(QualityCardService::class)->runningBatch($workstation);

            if (! $this->resolvedBatch) {
                $validator->errors()->add('workstation', __('No work order is running at this workstation.'));
            }
        });
    }

    public function batch(): Batch
    {
        return $this->resolvedBatch;
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
