<?php

namespace Modules\JkControl\Services;

use App\Models\ProcessTemplate;
use App\Models\ProductType;
use App\Models\Workstation;
use Illuminate\Support\Facades\DB;
use Modules\JkControl\Models\IdealCycle;

/**
 * Where the ideal cycle of a machine comes from, and how rows get in (by hand,
 * by spreadsheet or from the ERP — all three end up in upsertRows()).
 */
class IdealCycleService
{
    /**
     * Ideal seconds per machine cycle for this machine making this product.
     * The most specific row wins: product on this machine, then the product,
     * then the machine. Last, the core process template's ideal minutes per
     * piece, times the cavities.
     *
     * @return array{seconds: float, source: string}|null
     */
    public function resolve(Workstation $workstation, ?int $productTypeId, int $cavities = 1): ?array
    {
        $rows = IdealCycle::query()
            ->where(fn ($q) => $q->where('workstation_id', $workstation->id)->orWhereNull('workstation_id'))
            ->where(fn ($q) => $q->when($productTypeId, fn ($q) => $q->where('product_type_id', $productTypeId))->orWhereNull('product_type_id'))
            ->get();

        $pick = fn (?int $product, ?int $machine) => $rows->first(
            fn (IdealCycle $row) => $row->product_type_id === $product && $row->workstation_id === $machine,
        );

        $match = ($productTypeId ? $pick($productTypeId, $workstation->id) : null)
            ?? ($productTypeId ? $pick($productTypeId, null) : null)
            ?? $pick(null, $workstation->id);

        if ($match) {
            $scope = match (true) {
                $match->product_type_id && $match->workstation_id => 'product_machine',
                (bool) $match->product_type_id => 'product',
                default => 'machine',
            };

            return ['seconds' => (float) $match->cycle_seconds, 'source' => $scope];
        }

        if ($productTypeId) {
            $minutes = ProcessTemplate::where('product_type_id', $productTypeId)
                ->where('is_active', true)
                ->whereNotNull('ideal_cycle_minutes')
                ->orderByDesc('version')
                ->value('ideal_cycle_minutes');

            if ($minutes > 0) {
                return ['seconds' => (float) $minutes * 60 * max(1, $cavities), 'source' => 'process_template'];
            }
        }

        return null;
    }

    /**
     * Rows of {product_code?, workstation_code?, cycle_seconds}: create or update
     * by (product, machine). At least one of the two codes is required.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{imported: int, updated: int, skipped: int, errors: list<array{row: int, message: string}>}
     */
    public function upsertRows(array $rows, string $source): array
    {
        $result = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        foreach (array_values($rows) as $i => $row) {
            $line = $i + 1;
            $productCode = trim((string) ($row['product_code'] ?? ''));
            $machineCode = trim((string) ($row['workstation_code'] ?? ''));
            $seconds = $this->seconds($row['cycle_seconds'] ?? null);

            if ($productCode === '' && $machineCode === '') {
                $result['errors'][] = ['row' => $line, 'message' => __('Give a product code, a machine code or both.')];

                continue;
            }
            if ($seconds === null) {
                $result['errors'][] = ['row' => $line, 'message' => __('The ideal cycle must be a number of seconds above zero.')];

                continue;
            }

            $product = $productCode === '' ? null : ProductType::where('code', $productCode)->first();
            if ($productCode !== '' && ! $product) {
                $result['errors'][] = ['row' => $line, 'message' => __('No product has the code :code.', ['code' => $productCode])];

                continue;
            }
            $machine = $machineCode === '' ? null : Workstation::where('code', $machineCode)->first();
            if ($machineCode !== '' && ! $machine) {
                $result['errors'][] = ['row' => $line, 'message' => __('No machine has the code :code.', ['code' => $machineCode])];

                continue;
            }

            $existed = $this->save($product?->id, $machine?->id, $seconds, $source)->wasRecentlyCreated === false;
            $result[$existed ? 'updated' : 'imported']++;
        }

        return $result;
    }

    public function save(?int $productTypeId, ?int $workstationId, float $seconds, string $source): IdealCycle
    {
        return DB::transaction(fn () => IdealCycle::updateOrCreate(
            ['product_type_id' => $productTypeId, 'workstation_id' => $workstationId],
            ['cycle_seconds' => $seconds, 'source' => $source],
        ));
    }

    /** "24,96", "24.96" or 24.96 → 24.96; empty, zero or text → null. */
    private function seconds(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }
}
