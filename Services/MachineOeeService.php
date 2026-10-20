<?php

namespace Modules\JkControl\Services;

use App\Enums\DowntimeKind;
use App\Models\Batch;
use App\Models\MachineEvent;
use App\Models\MachineTag;
use App\Models\ProductionDowntime;
use App\Models\ScrapEntry;
use App\Models\Shift;
use App\Models\Workstation;
use App\Models\WorkstationState;
use App\Support\ShiftWindow;
use Carbon\CarbonInterface;

/**
 * OEE of one machine over a period, from what the machine itself reports.
 *
 * The core's OEE is per line and counts only completed batches; a press that
 * runs one order for days, counted by the machine, never shows up there. Here:
 *
 *   planned      = the line's shifts inside the period (the whole period when
 *                  the line has none), minus planned stops (kind "planned")
 *   availability = time RUNNING ÷ planned. Unplanned stops and changeovers
 *                  (mold changes) are losses.
 *   performance  = machine cycles × ideal seconds per cycle ÷ time RUNNING,
 *                  with cycles = pieces counted ÷ cavities
 *   quality      = (pieces − rejects − scrap entered in OpenMES) ÷ pieces
 *   oee          = availability × performance × quality
 *
 * measure() returns the raw sums of a period, so longer periods (weeks,
 * months) are merge()d from days instead of being measured again, and
 * summarize() turns sums into the percentages people read.
 */
class MachineOeeService
{
    /** A cycle slower than this many ideal cycles counts as slow (a short stop that never became a stop). */
    public const SLOW_CYCLE_FACTOR = 1.5;

    /** Cycle histogram bin, in tenths of a second: lets days merge into weeks and still give percentiles. */
    private const BIN_PER_SECOND = 10;

    public function __construct(private readonly IdealCycleService $cycles) {}

    /** The current shift of the machine's line, up to now (the core's fallback window when the line has none). */
    public function currentShift(Workstation $workstation): array
    {
        $window = ShiftWindow::current($workstation->line_id);

        return [$window->start->copy(), $window->end->copy()->min(now())];
    }

    /**
     * Summary of one period, plus what the machine is doing now (live screen).
     *
     * @return array<string, mixed>
     */
    public function forPeriod(Workstation $workstation, CarbonInterface $from, CarbonInterface $to): array
    {
        $measure = $this->measure($workstation, $from, $to);

        return [
            'workstation' => $this->describe($workstation),
            'state' => WorkstationState::where('workstation_id', $workstation->id)->whereNull('ended_at')->latest('started_at')->value('state'),
            ...$this->summarize($measure),
        ];
    }

    /** @return array{id: int, code: string, name: string, line_id: ?int} */
    public function describe(Workstation $workstation): array
    {
        return ['id' => $workstation->id, 'code' => $workstation->code, 'name' => $workstation->name, 'line_id' => $workstation->line_id];
    }

    /**
     * Raw sums of one period (half-open: [from, to)).
     *
     * @return array<string, mixed>
     */
    public function measure(Workstation $workstation, CarbonInterface $from, CarbonInterface $to): array
    {
        $to = $to->copy()->min(now());
        if ($to->lt($from)) {
            $to = $from->copy();
        }
        [$windows, $withoutShifts] = $this->plannedWindows($workstation, $from, $to);

        $running = $this->runningSeconds($workstation, $from, $to, $windows);
        ['good' => $good, 'reject' => $reject] = $this->counted($workstation, $from, $to, $windows);
        $produced = $good + $reject;
        $cavities = $this->cavities($workstation);

        $order = Batch::forWorkstation($workstation->id)
            ->whereNotNull('started_at')
            ->where('started_at', '<', $to)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first()?->workOrder;
        $ideal = $this->cycles->resolve($workstation, $order?->product_type_id, $cavities);

        return [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'without_shifts' => $withoutShifts,
            'order_no' => $order?->order_no,
            'planned_seconds' => $this->total($windows),
            'planned_stop_seconds' => $this->stopSeconds($workstation, $from, $to, $windows, [DowntimeKind::Planned->value]),
            'running_seconds' => $running,
            'unplanned_stop_seconds' => $this->stopSeconds($workstation, $from, $to, $windows, [DowntimeKind::Unplanned->value]),
            'changeover_seconds' => $this->stopSeconds($workstation, $from, $to, $windows, [DowntimeKind::Changeover->value]),
            'produced' => $produced,
            'bad' => $reject + $this->scrap($workstation, $from, $to, $windows),
            'cavities' => $cavities,
            'ideal_cycle_seconds' => $ideal['seconds'] ?? null,
            'ideal_cycle_source' => $ideal['source'] ?? null,
            // Performance pieces, kept apart so periods merge correctly when some lack an ideal cycle.
            'ideal_output_seconds' => $ideal ? ($produced / $cavities) * $ideal['seconds'] : null,
            'ideal_running_seconds' => $ideal ? $running : 0,
            'cycles' => $this->cycleStats($this->cycleSamples($workstation, $from, $to, $windows), $ideal['seconds'] ?? null),
        ];
    }

    /**
     * Several periods of one machine as one (a week from its days).
     *
     * @param  list<array<string, mixed>>  $measures
     * @return array<string, mixed>
     */
    public function merge(array $measures): array
    {
        if ($measures === []) {
            throw new \InvalidArgumentException('Nothing to merge.');
        }

        $sum = fn (string $key) => array_sum(array_column($measures, $key));
        $ideals = array_values(array_unique(array_filter(array_column($measures, 'ideal_cycle_seconds'), fn ($v) => $v !== null)));
        $outputs = array_filter(array_column($measures, 'ideal_output_seconds'), fn ($v) => $v !== null);
        $last = end($measures);

        $histogram = [];
        $slow = null;
        foreach ($measures as $m) {
            foreach ($m['cycles']['histogram'] as $bin => $count) {
                $histogram[$bin] = ($histogram[$bin] ?? 0) + $count;
            }
            if ($m['cycles']['slow'] !== null) {
                $slow = ($slow ?? 0) + $m['cycles']['slow'];
            }
        }
        ksort($histogram);

        return [
            'from' => $measures[0]['from'],
            'to' => $last['to'],
            'without_shifts' => in_array(true, array_column($measures, 'without_shifts'), true),
            'order_no' => $last['order_no'],
            'planned_seconds' => $sum('planned_seconds'),
            'planned_stop_seconds' => $sum('planned_stop_seconds'),
            'running_seconds' => $sum('running_seconds'),
            'unplanned_stop_seconds' => $sum('unplanned_stop_seconds'),
            'changeover_seconds' => $sum('changeover_seconds'),
            'produced' => $sum('produced'),
            'bad' => $sum('bad'),
            'cavities' => $last['cavities'],
            // One ideal for the whole period, or none when it changed along the way.
            'ideal_cycle_seconds' => count($ideals) === 1 ? $ideals[0] : null,
            'ideal_cycle_source' => count($ideals) === 1 ? $last['ideal_cycle_source'] : (count($ideals) > 1 ? 'mixed' : null),
            'ideal_output_seconds' => $outputs === [] ? null : array_sum($outputs),
            'ideal_running_seconds' => $sum('ideal_running_seconds'),
            'cycles' => [
                'samples' => array_sum($histogram),
                'p10' => $this->histogramPercentile($histogram, 0.10),
                'median' => $this->histogramPercentile($histogram, 0.50),
                'p90' => $this->histogramPercentile($histogram, 0.90),
                'slow' => $slow,
                'histogram' => $histogram,
            ],
        ];
    }

    /**
     * Percentages and minutes people read.
     *
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    public function summarize(array $m): array
    {
        $available = $m['planned_seconds'] - $m['planned_stop_seconds'];
        $availability = $available > 0 ? min(100, $m['running_seconds'] / $available * 100) : null;
        $performance = ($m['ideal_output_seconds'] !== null && $m['ideal_running_seconds'] > 0)
            ? $m['ideal_output_seconds'] / $m['ideal_running_seconds'] * 100
            : null;
        $quality = $m['produced'] > 0 ? max(0, ($m['produced'] - $m['bad']) / $m['produced'] * 100) : null;
        $oee = ($availability !== null && $performance !== null && $quality !== null)
            ? $availability * min(100, $performance) * $quality / 10000
            : null;

        return [
            'from' => $m['from'],
            'to' => $m['to'],
            'without_shifts' => $m['without_shifts'],
            'order_no' => $m['order_no'],
            'planned_minutes' => $this->minutes($m['planned_seconds']),
            'planned_stop_minutes' => $this->minutes($m['planned_stop_seconds']),
            'running_minutes' => $this->minutes($m['running_seconds']),
            'unplanned_stop_minutes' => $this->minutes($m['unplanned_stop_seconds']),
            'changeover_minutes' => $this->minutes($m['changeover_seconds']),
            'produced' => $m['produced'],
            'rejected' => $m['bad'],
            'cavities' => $m['cavities'],
            'ideal_cycle_seconds' => $m['ideal_cycle_seconds'] !== null ? round($m['ideal_cycle_seconds'], 2) : null,
            'ideal_cycle_source' => $m['ideal_cycle_source'],
            'typical_cycle_seconds' => $m['cycles']['median'],
            'cycle_p10_seconds' => $m['cycles']['p10'],
            'cycle_p90_seconds' => $m['cycles']['p90'],
            'cycle_samples' => $m['cycles']['samples'],
            'slow_cycles' => $m['cycles']['slow'],
            'availability' => $this->pct($availability),
            'performance' => $this->pct($performance),
            'quality' => $this->pct($quality),
            'oee' => $this->pct($oee),
        ];
    }

    /**
     * Each cycle the machine reported in the period, for the cycle chart.
     *
     * @return list<array{0: string, 1: float}>
     */
    public function cyclePoints(Workstation $workstation, CarbonInterface $from, CarbonInterface $to): array
    {
        $tag = $this->cycleTag($workstation);
        if (! $tag) {
            return [];
        }

        return MachineEvent::where('workstation_id', $workstation->id)
            ->where('event_type', MachineEvent::TYPE_TELEMETRY)
            ->where('event_timestamp', '>=', $from)
            ->where('event_timestamp', '<', $to)
            ->orderBy('event_timestamp')
            ->get(['event_timestamp', 'payload'])
            ->map(fn (MachineEvent $event) => [$event->event_timestamp->toIso8601String(), $event->payload[$tag->name] ?? null])
            ->filter(fn ($point) => is_numeric($point[1]) && $point[1] > 0)
            ->map(fn ($point) => [$point[0], round((float) $point[1], 2)])
            ->values()
            ->all();
    }

    /**
     * Shift occurrences that touch [from, to], clipped and merged. A line with
     * no active shift (its own or plant-wide) is planned for the whole period.
     *
     * @return array{0: list<array{0: CarbonInterface, 1: CarbonInterface}>, 1: bool}
     */
    private function plannedWindows(Workstation $workstation, CarbonInterface $from, CarbonInterface $to): array
    {
        $shifts = Shift::where('is_active', true)
            ->where(fn ($q) => $q->where('line_id', $workstation->line_id)->orWhereNull('line_id'))
            ->get();

        if ($shifts->isEmpty()) {
            return [[[$from->copy(), $to->copy()]], true];
        }

        $windows = [];
        for ($day = $from->copy()->subDay()->startOfDay(); $day->lte($to); $day->addDay()) {
            foreach ($shifts as $shift) {
                $days = $shift->days_of_week;
                if (is_array($days) && $days !== [] && ! in_array($day->isoWeekday(), array_map('intval', $days), true)) {
                    continue;
                }
                $occurrence = ShiftWindow::startingOn($shift, $day);
                $start = $occurrence->start->copy()->max($from);
                $end = $occurrence->end->copy()->min($to);
                if ($end->gt($start)) {
                    $windows[] = [$start, $end];
                }
            }
        }

        usort($windows, fn ($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($windows as [$start, $end]) {
            $last = array_key_last($merged);
            if ($last !== null && $start->lte($merged[$last][1])) {
                $merged[$last][1] = $merged[$last][1]->copy()->max($end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return [$merged, false];
    }

    private function runningSeconds(Workstation $workstation, CarbonInterface $from, CarbonInterface $to, array $windows): int
    {
        return WorkstationState::where('workstation_id', $workstation->id)
            ->where('state', WorkstationState::RUNNING)
            ->where('started_at', '<', $to)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $from))
            ->get(['started_at', 'ended_at'])
            ->sum(fn ($s) => $this->overlap($s->started_at, $s->ended_at ?? $to, $windows));
    }

    /** @param  list<string>  $kinds */
    private function stopSeconds(Workstation $workstation, CarbonInterface $from, CarbonInterface $to, array $windows, array $kinds): int
    {
        return ProductionDowntime::where('workstation_id', $workstation->id)
            ->where('started_at', '<', $to)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $from))
            ->whereHas('reason', fn ($q) => $q->whereIn('kind', $kinds))
            ->get(['started_at', 'ended_at'])
            ->sum(fn ($d) => $this->overlap($d->started_at, $d->ended_at ?? $to, $windows));
    }

    /** Pieces from the machine's counter events (tag scale already applied: cycles × cavities). */
    private function counted(Workstation $workstation, CarbonInterface $from, CarbonInterface $to, array $windows): array
    {
        $totals = ['good' => 0.0, 'reject' => 0.0];
        MachineEvent::where('workstation_id', $workstation->id)
            ->where('event_type', MachineEvent::TYPE_COUNTER)
            ->where('event_timestamp', '>=', $from)
            ->where('event_timestamp', '<', $to)
            ->get(['event_timestamp', 'payload'])
            ->each(function (MachineEvent $event) use (&$totals, $windows) {
                if (! $this->inside($event->event_timestamp, $windows)) {
                    return;
                }
                $kind = ($event->payload['kind'] ?? 'good') === 'reject' ? 'reject' : 'good';
                $totals[$kind] += (float) ($event->payload['delta'] ?? 0);
            });

        return $totals;
    }

    /** Scrap entered in OpenMES against the orders this machine ran. */
    private function scrap(Workstation $workstation, CarbonInterface $from, CarbonInterface $to, array $windows): float
    {
        $orderIds = Batch::forWorkstation($workstation->id)->pluck('work_order_id')->unique();
        if ($orderIds->isEmpty()) {
            return 0.0;
        }

        return (float) ScrapEntry::whereIn('work_order_id', $orderIds)
            ->where('reported_at', '>=', $from)
            ->where('reported_at', '<', $to)
            ->get(['reported_at', 'quantity'])
            ->filter(fn ($entry) => $this->inside($entry->reported_at, $windows))
            ->sum('quantity');
    }

    /** Pieces per machine cycle: the scale of the machine's good-count tag. */
    private function cavities(Workstation $workstation): int
    {
        $scale = MachineTag::where('workstation_id', $workstation->id)
            ->where('signal_type', MachineTag::SIGNAL_GOOD_COUNT)
            ->where('is_active', true)
            ->get()
            ->map(fn (MachineTag $tag) => (float) ($tag->transform['scale'] ?? 1))
            ->first();

        return max(1, (int) round($scale ?? 1));
    }

    /** The machine's cycle telemetry: a tag whose address ends in "/ciclo" or "/cycle". */
    private function cycleTag(Workstation $workstation): ?MachineTag
    {
        return MachineTag::where('workstation_id', $workstation->id)
            ->where('signal_type', MachineTag::SIGNAL_TELEMETRY)
            ->where(fn ($q) => $q->where('address', 'like', '%/ciclo')->orWhere('address', 'like', '%/cycle'))
            ->first();
    }

    /** @return list<float> seconds of each cycle reported inside the planned windows */
    private function cycleSamples(Workstation $workstation, CarbonInterface $from, CarbonInterface $to, array $windows): array
    {
        $tag = $this->cycleTag($workstation);
        if (! $tag) {
            return [];
        }

        return MachineEvent::where('workstation_id', $workstation->id)
            ->where('event_type', MachineEvent::TYPE_TELEMETRY)
            ->where('event_timestamp', '>=', $from)
            ->where('event_timestamp', '<', $to)
            ->get(['event_timestamp', 'payload'])
            ->filter(fn (MachineEvent $event) => $this->inside($event->event_timestamp, $windows))
            ->map(fn (MachineEvent $event) => $event->payload[$tag->name] ?? null)
            ->filter(fn ($value) => is_numeric($value) && $value > 0)
            ->map(fn ($value) => (float) $value)
            ->values()
            ->all();
    }

    /**
     * Typical cycle (median — not the mean: the first cycle after every stop
     * runs for minutes), the band 80 % of cycles fall in, slow cycles, and a
     * histogram so periods can be merged.
     *
     * @param  list<float>  $samples
     */
    private function cycleStats(array $samples, ?float $ideal): array
    {
        sort($samples);
        $n = count($samples);
        $histogram = [];
        foreach ($samples as $value) {
            $bin = (int) round($value * self::BIN_PER_SECOND);
            $histogram[$bin] = ($histogram[$bin] ?? 0) + 1;
        }

        $rank = fn (float $p) => $n === 0 ? null : round($samples[max(0, (int) ceil($p * $n) - 1)], 2);
        $median = $n === 0 ? null : round($n % 2 ? $samples[intdiv($n, 2)] : ($samples[$n / 2 - 1] + $samples[$n / 2]) / 2, 2);

        return [
            'samples' => $n,
            'p10' => $rank(0.10),
            'median' => $median,
            'p90' => $rank(0.90),
            'slow' => $ideal ? count(array_filter($samples, fn ($v) => $v > $ideal * self::SLOW_CYCLE_FACTOR)) : null,
            'histogram' => $histogram,
        ];
    }

    /** @param  array<int, int>  $histogram  bin (tenths of a second) => count, sorted */
    private function histogramPercentile(array $histogram, float $p): ?float
    {
        $n = array_sum($histogram);
        if ($n === 0) {
            return null;
        }

        $target = max(1, (int) ceil($p * $n));
        $seen = 0;
        foreach ($histogram as $bin => $count) {
            $seen += $count;
            if ($seen >= $target) {
                return round($bin / self::BIN_PER_SECOND, 2);
            }
        }

        return round(array_key_last($histogram) / self::BIN_PER_SECOND, 2);
    }

    /** Seconds of [start, end] that fall inside the planned windows. */
    private function overlap(CarbonInterface $start, CarbonInterface $end, array $windows): int
    {
        $seconds = 0;
        foreach ($windows as [$wStart, $wEnd]) {
            $a = $start->copy()->max($wStart);
            $b = $end->copy()->min($wEnd);
            if ($b->gt($a)) {
                $seconds += (int) $a->diffInSeconds($b);
            }
        }

        return $seconds;
    }

    /** Half-open, so a reading at midnight belongs to one day only. */
    private function inside(CarbonInterface $at, array $windows): bool
    {
        foreach ($windows as [$start, $end]) {
            if ($at->gte($start) && $at->lt($end)) {
                return true;
            }
        }

        return false;
    }

    private function total(array $windows): int
    {
        return array_sum(array_map(fn ($w) => (int) $w[0]->diffInSeconds($w[1]), $windows));
    }

    private function minutes(int|float $seconds): int
    {
        return (int) round($seconds / 60);
    }

    private function pct(?float $value): ?float
    {
        return $value === null ? null : round($value, 1);
    }
}
