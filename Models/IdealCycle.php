<?php

namespace Modules\JkControl\Models;

use App\Models\Concerns\HasTenant;
use App\Models\ProductType;
use App\Models\Workstation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ideal seconds per machine cycle (one injection, whatever the cavities) for a
 * product, a machine, or a product on one machine.
 */
class IdealCycle extends Model
{
    use HasTenant;

    public const SOURCES = ['manual', 'spreadsheet', 'erp'];

    protected $table = 'jk_ideal_cycles';

    protected $fillable = [
        'product_type_id',
        'workstation_id',
        'cycle_seconds',
        'source',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'cycle_seconds' => 'decimal:3',
        ];
    }

    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }
}
