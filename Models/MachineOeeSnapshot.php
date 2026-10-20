<?php

namespace Modules\JkControl\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * MachineOeeService::measure() of a period that is over. Derived data: safe to
 * delete at any time, it is measured again on the next report.
 */
class MachineOeeSnapshot extends Model
{
    protected $table = 'jk_machine_oee_snapshots';

    protected $fillable = ['workstation_id', 'kind', 'period_start', 'period_end', 'data'];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'data' => 'array',
        ];
    }

    /** Periods that share any moment with [from, to]. */
    public function scopeTouching(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->where('period_start', '<', $to)->where('period_end', '>', $from);
    }
}
