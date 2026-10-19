<?php

namespace Modules\JkControl\Models;

use App\Models\Concerns\HasTenant;
use App\Models\EmployeeActivity;
use App\Models\Worker;
use App\Models\Workstation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operator logged in at a workstation by card. Written only by the module
 * (never edited by users), so it carries no soft-delete.
 */
class OperatorSession extends Model
{
    use HasTenant;

    protected $table = 'jk_operator_sessions';

    protected $fillable = [
        'worker_id',
        'workstation_id',
        'started_at',
        'ended_at',
        'employee_activity_id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    public function employeeActivity(): BelongsTo
    {
        return $this->belongsTo(EmployeeActivity::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }
}
