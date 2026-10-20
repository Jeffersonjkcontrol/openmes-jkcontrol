<?php

namespace Modules\JkControl\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Line;
use App\Models\MachineTag;
use App\Models\ProductType;
use App\Models\Workstation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\JkControl\Models\IdealCycle;
use Modules\JkControl\Services\MachineOeeService;

class MachineOeeController extends Controller
{
    public const PERIODS = ['shift', 'today', 'yesterday'];

    public function __construct(private readonly MachineOeeService $oee) {}

    /**
     * GET /jkcontrol/oee?line_id=&period=shift|today|yesterday
     *
     * One card per machine that reports to OpenMES (has a state or counter tag).
     */
    public function index(Request $request): Response
    {
        $period = in_array($request->query('period'), self::PERIODS, true) ? $request->query('period') : 'shift';
        $lineId = $request->integer('line_id') ?: null;

        $connected = self::connectedMachines();

        $machines = $connected
            ->when($lineId, fn ($all) => $all->where('line_id', $lineId))
            ->map(fn (Workstation $workstation) => $this->oee->forPeriod($workstation, ...$this->range($workstation, $period)))
            ->values();

        return Inertia::render('jkcontrol/MachineOee', [
            'machines' => $machines,
            'filters' => ['line_id' => $lineId, 'period' => $period],
            'lines' => Line::whereIn('id', $connected->pluck('line_id')->filter()->unique())->orderBy('name')->get(['id', 'name']),
            'idealCycles' => IdealCycle::with(['productType:id,code,name', 'workstation:id,code,name'])
                ->orderBy('workstation_id')->orderBy('product_type_id')->get()
                ->map(fn (IdealCycle $row) => [
                    'id' => $row->id,
                    'product' => $row->productType ? "{$row->productType->code} — {$row->productType->name}" : null,
                    'machine' => $row->workstation?->code,
                    'cycle_seconds' => (float) $row->cycle_seconds,
                    'source' => $row->source,
                ]),
            'products' => ProductType::orderBy('code')->get(['id', 'code', 'name']),
            'workstations' => $connected->map(fn (Workstation $w) => ['id' => $w->id, 'code' => $w->code, 'name' => $w->name])->values(),
        ]);
    }

    /**
     * Machines that report to OpenMES (a state or counter tag), on active lines.
     *
     * @return \Illuminate\Support\Collection<int, Workstation>
     */
    public static function connectedMachines(): \Illuminate\Support\Collection
    {
        return Workstation::query()
            ->whereIn('id', MachineTag::where('is_active', true)
                ->whereIn('signal_type', [MachineTag::SIGNAL_STATE, MachineTag::SIGNAL_GOOD_COUNT])
                ->whereNotNull('workstation_id')
                ->select('workstation_id'))
            // A line switched off in Structure → Lines takes its machines off the screen.
            ->where(fn ($q) => $q->whereNull('line_id')->orWhereHas('line', fn ($line) => $line->where('is_active', true)))
            ->orderBy('code')
            ->get();
    }

    /** @return array{0: \Carbon\CarbonInterface, 1: \Carbon\CarbonInterface} */
    private function range(Workstation $workstation, string $period): array
    {
        return match ($period) {
            'today' => [now()->startOfDay(), now()],
            'yesterday' => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            default => $this->oee->currentShift($workstation),
        };
    }
}
