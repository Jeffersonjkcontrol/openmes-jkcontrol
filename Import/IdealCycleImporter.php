<?php

namespace Modules\JkControl\Import;

use App\Import\AbstractEntityImporter;
use Modules\JkControl\Services\IdealCycleService;

/**
 * Admin → Import: ideal cycles for machine OEE, one row per product, machine,
 * or product on one machine. Registered through the "import.entities" filter.
 */
class IdealCycleImporter extends AbstractEntityImporter
{
    public function __construct(private readonly IdealCycleService $cycles) {}

    public function key(): string
    {
        return 'jk_ideal_cycles';
    }

    public function label(): string
    {
        return __('Ideal cycles (machine OEE)');
    }

    public function description(): string
    {
        return __('Ideal seconds per machine cycle for a product, a machine, or a product on one machine. Existing rows are updated.');
    }

    public function fields(): array
    {
        return [
            'product_code' => ['label' => __('Product code'), 'required' => false, 'type' => 'text', 'description' => __('Empty for a machine-wide ideal cycle.'), 'aliases' => ['product', 'produto', 'código do produto', 'codigo do produto', 'sku']],
            'workstation_code' => ['label' => __('Machine code'), 'required' => false, 'type' => 'text', 'description' => __('Empty for the product on any machine.'), 'aliases' => ['machine', 'máquina', 'maquina', 'posto', 'workstation']],
            'cycle_seconds' => ['label' => __('Ideal cycle (s)'), 'required' => true, 'type' => 'text', 'description' => __('Seconds per machine cycle (one injection), e.g. 24.96.'), 'aliases' => ['ciclo ideal', 'ciclo', 'segundos', 'ideal cycle', 'cycle']],
        ];
    }

    public function options(): array
    {
        return [];
    }

    public function optionRules(): array
    {
        return [];
    }

    public function sample(): array
    {
        return [
            'headers' => ['product_code', 'workstation_code', 'cycle_seconds'],
            'rows' => [
                ['89-0001', '', '24.96'],
                ['89-0001', 'NIT-27', '26.5'],
                ['', 'NIT-09', '17'],
            ],
        ];
    }

    public function import(array $rows, array $options): array
    {
        return $this->cycles->upsertRows($rows, 'spreadsheet');
    }
}
