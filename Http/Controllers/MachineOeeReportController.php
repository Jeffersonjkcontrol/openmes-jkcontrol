<?php

namespace Modules\JkControl\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Line;
use App\Models\ProductionDowntime;
use App\Models\Workstation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Modules\JkControl\Services\MachineOeeReport;
use Modules\JkControl\Services\MachineOeeService;

class MachineOeeReportController extends Controller
{
    public function __construct(
        private readonly MachineOeeReport $report,
        private readonly MachineOeeService $oee,
    ) {}

    /**
     * GET /jkcontrol/oee/report?line_id=&workstation_id=&date_from=&date_to=&granularity=day|week|month|shift
     */
    public function index(Request $request): Response
    {
        [$from, $to] = $this->dates($request);
        $granularity = in_array($request->query('granularity'), MachineOeeReport::GRANULARITIES, true) ? $request->query('granularity') : 'day';
        $lineId = $request->integer('line_id') ?: null;
        $workstationId = $request->integer('workstation_id') ?: null;

        $connected = MachineOeeController::connectedMachines();
        $selected = $connected
            ->when($lineId, fn ($all) => $all->where('line_id', $lineId))
            ->when($workstationId, fn ($all) => $all->where('id', $workstationId))
            ->values();

        return Inertia::render('jkcontrol/MachineOeeReport', [
            ...$this->report->build($selected, $from, $to, $granularity),
            'filters' => [
                'line_id' => $lineId,
                'workstation_id' => $workstationId,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'granularity' => $granularity,
            ],
            'lines' => Line::whereIn('id', $connected->pluck('line_id')->filter()->unique())->orderBy('name')->get(['id', 'name']),
            'workstations' => $connected->map(fn (Workstation $w) => ['id' => $w->id, 'code' => $w->code, 'line_id' => $w->line_id])->values(),
            'slowFactor' => MachineOeeService::SLOW_CYCLE_FACTOR,
        ]);
    }

    /**
     * GET /jkcontrol/oee/cycles?workstation_id=&date=  — every cycle of one machine on one day,
     * with its stops, for the cycle chart.
     */
    public function cycles(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workstation_id' => ['required', 'integer', 'exists:workstations,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);
        $workstation = Workstation::findOrFail($data['workstation_id']);
        $from = Carbon::parse($data['date'])->startOfDay();
        $to = $from->copy()->addDay()->min(now());

        $stops = ProductionDowntime::with('reason:id,code,name,kind')
            ->where('workstation_id', $workstation->id)
            ->where('started_at', '<', $to)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $from))
            ->orderBy('started_at')
            ->get()
            ->map(fn (ProductionDowntime $stop) => [
                'from' => $stop->started_at->copy()->max($from)->toIso8601String(),
                'to' => ($stop->ended_at ?? $to)->copy()->min($to)->toIso8601String(),
                'reason' => $stop->reason?->name,
                'kind' => $stop->reason?->kind instanceof \BackedEnum ? $stop->reason->kind->value : $stop->reason?->kind,
            ]);

        $measure = $this->report->measure($workstation, $from, $to, 'day');

        return response()->json([
            'workstation' => $this->oee->describe($workstation),
            'date' => $from->toDateString(),
            'ideal_cycle_seconds' => $measure['ideal_cycle_seconds'],
            'typical_cycle_seconds' => $measure['cycles']['median'],
            'points' => $this->oee->cyclePoints($workstation, $from, $to),
            'stops' => $stops,
        ]);
    }

    /** POST /jkcontrol/oee/recalculate — measure the kept periods of the range again. */
    public function recalculate(Request $request): RedirectResponse
    {
        [$from, $to] = $this->dates($request);
        MachineOeeReport::forget($request->integer('workstation_id') ?: null, $from->copy()->startOfDay(), $to->copy()->addDay()->startOfDay());

        return back()->with('success', __('The report will be measured again.'));
    }

    /** @return array{0: Carbon, 1: Carbon} default: the last 7 days, at most MAX_DAYS */
    private function dates(Request $request): array
    {
        $parse = fn (?string $value) => $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? Carbon::parse($value) : null;
        $to = ($parse($request->input('date_to')) ?? today())->min(today());
        $from = $parse($request->input('date_from')) ?? $to->copy()->subDays(6);
        if ($from->gt($to)) {
            $from = $to->copy();
        }
        if ($from->diffInDays($to) >= MachineOeeReport::MAX_DAYS) {
            $from = $to->copy()->subDays(MachineOeeReport::MAX_DAYS - 1);
        }

        return [$from->startOfDay(), $to->startOfDay()];
    }
}
