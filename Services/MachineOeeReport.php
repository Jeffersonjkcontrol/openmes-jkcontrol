<?php

namespace Modules\JkControl\Services;

use App\Models\Shift;
use App\Models\Workstation;
use App\Support\ShiftWindow;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\JkControl\Models\MachineOeeSnapshot;

/**
 * Machine OEE over a date range, by day, week, month or shift.
 *
 * Days (and shifts) are measured once they are over and kept as snapshots;
 * weeks and months are merged from days. The last hour is always measured
 * live: the connector may still deliver late readings for it.
 */
class MachineOeeReport
{
    public const GRANULARITIES = ['day', 'week', 'month', 'shift'];

    public const SETTLE_MINUTES = 60;

    public const MAX_DAYS = 366;

    public function __construct(private readonly MachineOeeService $oee) {}

    /**
     * @param  Collection<int, Workstation>  $workstations
     * @return array{rows: list<array<string, mixed>>, summary: list<array<string, mixed>>, without_shift_buckets: bool}
     */
    public function build(Collection $workstations, CarbonInterface $dateFrom, CarbonInterface $dateTo, string $granularity): array
    {
        $rows = [];
        $summary = [];
        $withoutShiftBuckets = false;

        foreach ($workstations as $workstation) {
            if ($granularity === 'shift') {
                $buckets = $this->shiftMeasures($workstation, $dateFrom, $dateTo);
                $withoutShiftBuckets = $withoutShiftBuckets || $buckets === [];
                $parts = array_column($buckets, 'measure');
            } else {
                $days = $this->dayMeasures($workstation, $dateFrom, $dateTo);
                $parts = array_column($days, 'measure');
                $buckets = $granularity === 'day' ? $days : $this->group($days, $granularity);
            }

            foreach ($buckets as $bucket) {
                $rows[] = [
                    'workstation' => $this->oee->describe($workstation),
                    'period' => $bucket['key'],
                    'shift' => $bucket['shift'] ?? null,
                    ...$this->oee->summarize($bucket['measure']),
                ];
            }

            if ($parts !== []) {
                $summary[] = ['workstation' => $this->oee->describe($workstation), ...$this->oee->summarize($this->oee->merge($parts))];
            }
        }

        return ['rows' => $rows, 'summary' => $summary, 'without_shift_buckets' => $withoutShiftBuckets];
    }

    /** One measure per calendar day, today up to now. */
    private function dayMeasures(Workstation $workstation, CarbonInterface $dateFrom, CarbonInterface $dateTo): array
    {
        $days = [];
        $end = $dateTo->copy()->addDay()->startOfDay()->min(now());
        for ($day = $dateFrom->copy()->startOfDay(); $day->lt($end); $day = $day->copy()->addDay()) {
            $days[] = [
                'key' => $day->toDateString(),
                'measure' => $this->measure($workstation, $day, $day->copy()->addDay()->min($end), 'day'),
            ];
        }

        return $days;
    }

    /** Days merged into ISO weeks ("2026-W43") or months ("2026-10"). */
    private function group(array $days, string $granularity): array
    {
        $groups = [];
        foreach ($days as $day) {
            $date = \Illuminate\Support\Carbon::parse($day['key']);
            $key = $granularity === 'week' ? $date->format('o-\WW') : $date->format('Y-m');
            $groups[$key][] = $day['measure'];
        }

        return array_map(
            fn (string $key, array $measures) => ['key' => $key, 'measure' => $this->oee->merge($measures)],
            array_keys($groups),
            array_values($groups),
        );
    }

    /** Each shift occurrence of the machine's line inside the range; none when the line has no shift. */
    private function shiftMeasures(Workstation $workstation, CarbonInterface $dateFrom, CarbonInterface $dateTo): array
    {
        $shifts = Shift::where('is_active', true)
            ->where(fn ($q) => $q->where('line_id', $workstation->line_id)->orWhereNull('line_id'))
            ->orderBy('sort_order')
            ->get();

        $from = $dateFrom->copy()->startOfDay();
        $to = $dateTo->copy()->addDay()->startOfDay()->min(now());
        $buckets = [];
        for ($day = $from->copy(); $day->lt($to); $day = $day->copy()->addDay()) {
            foreach ($shifts as $shift) {
                $days = $shift->days_of_week;
                if (is_array($days) && $days !== [] && ! in_array($day->isoWeekday(), array_map('intval', $days), true)) {
                    continue;
                }
                $window = ShiftWindow::startingOn($shift, $day);
                if ($window->start->gte($to)) {
                    continue;
                }
                $buckets[] = [
                    'key' => $window->start->toIso8601String(),
                    'shift' => $shift->name,
                    'measure' => $this->measure($workstation, $window->start->copy(), $window->end->copy()->min($to), 'shift'),
                ];
            }
        }
        usort($buckets, fn ($a, $b) => $a['key'] <=> $b['key']);

        return $buckets;
    }

    /** From the snapshot when the period is over, measured (and kept) otherwise. */
    public function measure(Workstation $workstation, CarbonInterface $from, CarbonInterface $to, string $kind): array
    {
        if ($to->gt(now()->subMinutes(self::SETTLE_MINUTES))) {
            return $this->oee->measure($workstation, $from, $to);
        }

        $keys = ['workstation_id' => $workstation->id, 'kind' => $kind, 'period_start' => $from, 'period_end' => $to];
        $snapshot = MachineOeeSnapshot::where($keys)->first();
        if ($snapshot) {
            return $snapshot->data;
        }

        $data = $this->oee->measure($workstation, $from, $to);
        MachineOeeSnapshot::create([...$keys, 'data' => $data]);

        return $data;
    }

    /** Forget kept periods, so they are measured again (after late data, or on request). */
    public static function forget(?int $workstationId = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null): int
    {
        return MachineOeeSnapshot::query()
            ->when($workstationId, fn ($q) => $q->where('workstation_id', $workstationId))
            ->when($from && $to, fn ($q) => $q->touching($from, $to))
            ->delete();
    }
}
