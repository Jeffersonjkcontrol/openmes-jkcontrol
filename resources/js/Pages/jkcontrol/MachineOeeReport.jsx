import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import Chart from 'chart.js/auto';
import AppLayout from '../../../../../../resources/js/layouts/AppLayout';
import AppDatePicker from '../../../../../../resources/js/components/AppDatePicker';
import AppDataTable from '../../../../../../resources/js/components/AppDataTable';
import OeeGauge, { oeeTextClass } from '../../../../../../resources/js/components/OeeGauge';
import { __, formatDate, formatNumber, formatTime } from '../../../../../../resources/js/lib/i18n';

// Machine OEE report (JkControl module): OEE and machine cycles per machine
// over a date range, by day, week, month or shift. Sums come from
// MachineOeeReport (days kept once over, weeks/months merged from days).

const GRANULARITIES = [
    ['day', 'Daily'],
    ['week', 'Weekly'],
    ['month', 'Monthly'],
    ['shift', 'By shift'],
];

const PALETTE = ['#2563eb', '#16a34a', '#d97706', '#dc2626', '#7c3aed', '#0891b2', '#db2777', '#65a30d'];
const GRID = 'rgba(128,128,128,0.18)';
const TICKS = 'rgba(128,128,128,0.95)';

const pct = (v) => (v == null ? '—' : `${formatNumber(v, { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`);
const sec = (v) => (v == null ? '—' : `${formatNumber(v, { maximumFractionDigits: 2 })} s`);

function periodLabel(row, granularity) {
    if (granularity === 'week') return __('Week :n', { n: row.period.split('W')[1] });
    if (granularity === 'month') return formatDate(`${row.period}-01`, { month: 'short', year: 'numeric' });
    if (granularity === 'shift') return `${formatDate(row.period, { day: '2-digit', month: '2-digit' })} ${row.shift ?? ''}`;
    return formatDate(row.period, { day: '2-digit', month: '2-digit', year: 'numeric' });
}

export default function MachineOeeReport({ rows, summary, without_shift_buckets: withoutShiftBuckets, filters, lines, workstations, slowFactor }) {
    const [detail, setDetail] = useState(null);
    const apply = (changes) => router.get('/jkcontrol/oee/report', { ...filters, ...changes }, { preserveScroll: true });
    const machineOptions = workstations.filter((w) => !filters.line_id || w.line_id === Number(filters.line_id));

    // Same colour for a machine in every chart.
    const colorOf = useMemo(() => {
        const ids = [...new Set(rows.map((r) => r.workstation.id))];
        return (id) => PALETTE[ids.indexOf(id) % PALETTE.length];
    }, [rows]);

    const periods = useMemo(() => [...new Set(rows.map((r) => r.period))].sort(), [rows]);
    const labels = useMemo(
        () => periods.map((p) => periodLabel(rows.find((r) => r.period === p), filters.granularity)),
        [periods, rows, filters.granularity],
    );
    // Rebuilt only when the data changes, so opening the cycle detail does not redraw the charts.
    const [oeeDatasets, cycleDatasets] = useMemo(() => {
        const series = (pick) => summary.map((s) => {
            const byPeriod = new Map(rows.filter((r) => r.workstation.id === s.workstation.id).map((r) => [r.period, r]));
            return { machine: s.workstation, data: periods.map((p) => (byPeriod.has(p) ? pick(byPeriod.get(p)) : null)) };
        });

        const oeeDatasets = series((r) => r.oee).map(({ machine, data }) => ({
            label: machine.code, data, borderColor: colorOf(machine.id), backgroundColor: colorOf(machine.id), tension: 0.25, spanGaps: false,
        }));
        const cycleDatasets = [
            ...series((r) => r.typical_cycle_seconds).map(({ machine, data }) => ({
                label: `${machine.code} · ${__('Typical cycle')}`, data, borderColor: colorOf(machine.id), backgroundColor: colorOf(machine.id), tension: 0.25,
            })),
            ...series((r) => r.ideal_cycle_seconds)
                .filter(({ data }) => data.some((v) => v != null))
                .map(({ machine, data }) => ({
                    label: `${machine.code} · ${__('Ideal cycle')}`, data, borderColor: colorOf(machine.id), borderDash: [6, 4], pointRadius: 0, tension: 0,
                })),
        ];
        return [oeeDatasets, cycleDatasets];
    }, [summary, rows, periods, colorOf]);

    const loadCycles = async (row) => {
        setDetail({ loading: true, title: `${row.workstation.code} · ${periodLabel(row, 'day')}` });
        const params = new URLSearchParams({ workstation_id: row.workstation.id, date: row.period });
        const res = await fetch(`/jkcontrol/oee/cycles?${params}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        setDetail(res.ok ? { ...(await res.json()), title: `${row.workstation.code} · ${periodLabel(row, 'day')}` } : { error: true });
    };

    const columns = useMemo(() => [
        { id: 'period', accessorKey: 'period', header: __('Period'), cell: ({ row }) => <span className="font-mono">{periodLabel(row.original, filters.granularity)}</span> },
        { id: 'machine', accessorFn: (r) => r.workstation.code, header: __('Machine'), cell: ({ row }) => <span className="font-medium">{row.original.workstation.code}</span> },
        { id: 'planned', accessorFn: (r) => r.planned_minutes - r.planned_stop_minutes, header: __('Planned'), meta: { align: 'right' }, cell: ({ getValue }) => <span className="font-mono">{getValue()} min</span> },
        { id: 'running', accessorKey: 'running_minutes', header: __('Running'), meta: { align: 'right' }, cell: ({ getValue }) => <span className="font-mono">{getValue()} min</span> },
        { id: 'stops', accessorKey: 'unplanned_stop_minutes', header: __('Stops'), meta: { align: 'right' }, cell: ({ getValue }) => <span className="font-mono">{getValue()} min</span> },
        { id: 'changeovers', accessorKey: 'changeover_minutes', header: __('Mold changes'), meta: { align: 'right' }, cell: ({ getValue }) => <span className="font-mono">{getValue()} min</span> },
        { id: 'produced', accessorKey: 'produced', header: __('Pieces'), meta: { align: 'right' }, cell: ({ getValue }) => <span className="font-mono">{formatNumber(getValue())}</span> },
        { id: 'rejected', accessorKey: 'rejected', header: __('Scrap'), meta: { align: 'right' }, cell: ({ getValue }) => <span className="font-mono">{getValue() > 0 ? formatNumber(getValue()) : '—'}</span> },
        { id: 'availability', accessorKey: 'availability', header: __('A%'), meta: { align: 'right' }, cell: ({ getValue }) => pct(getValue()) },
        { id: 'performance', accessorKey: 'performance', header: __('P%'), meta: { align: 'right' }, cell: ({ getValue }) => pct(getValue()) },
        { id: 'quality', accessorKey: 'quality', header: __('Q%'), meta: { align: 'right' }, cell: ({ getValue }) => pct(getValue()) },
        { id: 'oee', accessorKey: 'oee', header: 'OEE%', meta: { align: 'right' }, cell: ({ getValue }) => <span className={`font-bold ${oeeTextClass(getValue())}`}>{pct(getValue())}</span> },
        { id: 'typical', accessorKey: 'typical_cycle_seconds', header: __('Typical cycle'), meta: { align: 'right' }, cell: ({ getValue }) => <span className="font-mono">{sec(getValue())}</span> },
        { id: 'ideal', accessorKey: 'ideal_cycle_seconds', header: __('Ideal cycle'), meta: { align: 'right' }, cell: ({ getValue }) => <span className="font-mono">{sec(getValue())}</span> },
        {
            id: 'band', accessorKey: 'cycle_p10_seconds', header: __('Cycle band'), meta: { align: 'right' },
            cell: ({ row }) => <span className="font-mono">{row.original.cycle_p10_seconds == null ? '—' : `${sec(row.original.cycle_p10_seconds)} – ${sec(row.original.cycle_p90_seconds)}`}</span>,
        },
        { id: 'slow', accessorKey: 'slow_cycles', header: __('Slow cycles'), meta: { align: 'right' }, cell: ({ getValue }) => <span className="font-mono">{getValue() == null ? '—' : formatNumber(getValue())}</span> },
        ...(filters.granularity === 'day' ? [{
            id: 'cycles', header: '', enableSorting: false,
            cell: ({ row }) => <button type="button" onClick={() => loadCycles(row.original)} className="text-xs text-om-accent hover:underline print:hidden">{__('Show cycles')}</button>,
        }] : []),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    ], [filters.granularity]);

    return (
        <div className="max-w-7xl mx-auto">
            <Head title={__('Machine OEE report')} />

            <div className="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div>
                    <h1 className="text-[26px] font-semibold tracking-[-0.02em] text-om-ink">{__('Machine OEE report')}</h1>
                    <p className="text-sm text-om-muted">{__('OEE and cycles of each machine, from what the machine reports.')}</p>
                </div>
                <div className="flex gap-2 print:hidden">
                    <Link href="/jkcontrol/oee" className="rounded-om border border-om-line px-3 py-2 text-sm">{__('Live view')}</Link>
                    <button type="button" onClick={() => router.post('/jkcontrol/oee/recalculate', filters, { preserveScroll: true })} className="rounded-om border border-om-line px-3 py-2 text-sm">
                        {__('Recalculate')}
                    </button>
                    <button type="button" onClick={() => window.print()} className="rounded-om bg-om-accent text-white px-3 py-2 text-sm">{__('Print')}</button>
                </div>
            </div>

            <div className="bg-om-card border border-om-line rounded-om p-4 mb-6 flex flex-wrap gap-3 items-end print:hidden">
                <Field label={__('Line')}>
                    <select className="rounded-om border border-om-line bg-om-card text-sm px-3 py-2" value={filters.line_id ?? ''} onChange={(e) => apply({ line_id: e.target.value || null, workstation_id: null })}>
                        <option value="">{__('All lines')}</option>
                        {lines.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
                    </select>
                </Field>
                <Field label={__('Machine')}>
                    <select className="rounded-om border border-om-line bg-om-card text-sm px-3 py-2" value={filters.workstation_id ?? ''} onChange={(e) => apply({ workstation_id: e.target.value || null })}>
                        <option value="">{__('All machines')}</option>
                        {machineOptions.map((w) => <option key={w.id} value={w.id}>{w.code}</option>)}
                    </select>
                </Field>
                <Field label={__('From')}><AppDatePicker value={filters.date_from} onChange={(iso) => iso && apply({ date_from: iso })} /></Field>
                <Field label={__('To')}><AppDatePicker value={filters.date_to} onChange={(iso) => iso && apply({ date_to: iso })} /></Field>
                <div className="flex rounded-om border border-om-line overflow-hidden ml-auto">
                    {GRANULARITIES.map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => apply({ granularity: key })}
                            className={`px-3 py-2 text-sm ${filters.granularity === key ? 'bg-om-ink text-om-card' : 'text-om-muted'}`}
                        >
                            {__(label)}
                        </button>
                    ))}
                </div>
            </div>

            {withoutShiftBuckets && (
                <p className="mb-4 text-sm text-om-downtime">{__('Some lines have no shifts, so they have nothing to break down by shift. Shifts are set in Admin → Shifts.')}</p>
            )}

            {summary.length === 0 ? (
                <p className="text-om-muted text-sm">{__('No machine reports to OpenMES yet.')}</p>
            ) : (
                <>
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                        {summary.map((s) => <SummaryCard key={s.workstation.id} s={s} color={colorOf(s.workstation.id)} />)}
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
                        <Panel title={__('OEE Trend')}>
                            <LineChart labels={labels} datasets={oeeDatasets} yMax={100} unit="%" />
                        </Panel>
                        <Panel title={__('Cycle trend')} hint={__('Typical cycle (median) per period; dashed: ideal cycle.')}>
                            <LineChart labels={labels} datasets={cycleDatasets} unit=" s" />
                        </Panel>
                    </div>

                    {detail && <CycleDetail detail={detail} slowFactor={slowFactor} onClose={() => setDetail(null)} />}

                    <Panel title={__('Details')}>
                        <AppDataTable data={rows} columns={columns} searchable={false} bodyMaxHeight="none" />
                    </Panel>
                </>
            )}
        </div>
    );
}

function Field({ label, children }) {
    return (
        <div>
            <div className="block text-xs font-medium text-om-muted mb-1">{label}</div>
            {children}
        </div>
    );
}

function Panel({ title, hint, children }) {
    return (
        <div className="bg-om-card border border-om-line rounded-om p-5 mb-6 break-inside-avoid">
            <h2 className="text-[14px] font-semibold text-om-ink">{title}</h2>
            {hint && <p className="text-xs text-om-muted">{hint}</p>}
            <div className="mt-3">{children}</div>
        </div>
    );
}

function SummaryCard({ s, color }) {
    return (
        <div className="bg-om-card border border-om-line rounded-om p-4 break-inside-avoid">
            <div className="flex items-center gap-2">
                <span className="inline-block w-2.5 h-2.5 rounded-full" style={{ background: color }} />
                <p className="text-[15px] font-semibold text-om-ink">{s.workstation.code}</p>
            </div>
            <div className="flex justify-center my-2"><OeeGauge value={s.oee} size={130} /></div>
            <div className="grid grid-cols-3 text-center text-xs">
                <div><p className="text-om-muted uppercase text-[10px]">{__('Availability')}</p><p className="font-semibold">{pct(s.availability)}</p></div>
                <div><p className="text-om-muted uppercase text-[10px]">{__('Performance')}</p><p className="font-semibold">{pct(s.performance)}</p></div>
                <div><p className="text-om-muted uppercase text-[10px]">{__('Quality')}</p><p className="font-semibold">{pct(s.quality)}</p></div>
            </div>
            <dl className="mt-3 pt-2 border-t border-om-line grid grid-cols-2 gap-x-2 gap-y-0.5 text-xs">
                <dt className="text-om-muted">{__('Pieces')}</dt><dd className="text-right font-mono">{formatNumber(s.produced)}</dd>
                <dt className="text-om-muted">{__('Typical cycle')}</dt><dd className="text-right font-mono">{sec(s.typical_cycle_seconds)}</dd>
                <dt className="text-om-muted">{__('Ideal cycle')}</dt><dd className="text-right font-mono">{sec(s.ideal_cycle_seconds)}</dd>
                <dt className="text-om-muted">{__('Slow cycles')}</dt><dd className="text-right font-mono">{s.slow_cycles == null ? '—' : formatNumber(s.slow_cycles)}</dd>
            </dl>
        </div>
    );
}

function LineChart({ labels, datasets, yMax, unit }) {
    const canvas = useRef(null);
    useEffect(() => {
        const chart = new Chart(canvas.current, {
            type: 'line',
            data: { labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { labels: { color: TICKS, boxWidth: 12 } },
                    tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${c.parsed.y == null ? '—' : formatNumber(c.parsed.y, { maximumFractionDigits: 1 })}${unit}` } },
                },
                scales: {
                    x: { ticks: { color: TICKS }, grid: { color: GRID } },
                    y: { beginAtZero: true, ...(yMax ? { max: yMax } : {}), ticks: { color: TICKS, callback: (v) => `${v}${unit}` }, grid: { color: GRID } },
                },
            },
        });
        return () => chart.destroy();
    }, [labels, datasets, yMax, unit]);

    return <div className="h-64"><canvas ref={canvas} /></div>;
}

// Shades the stops behind the cycle points.
const stopsPlugin = {
    id: 'jkStops',
    beforeDatasetsDraw(chart, _args, options) {
        const { ctx, chartArea, scales } = chart;
        ctx.save();
        for (const stop of options.stops ?? []) {
            const x1 = Math.max(chartArea.left, scales.x.getPixelForValue(Date.parse(stop.from)));
            const x2 = Math.min(chartArea.right, scales.x.getPixelForValue(Date.parse(stop.to)));
            if (x2 <= x1) continue;
            ctx.fillStyle = stop.kind === 'changeover' ? 'rgba(201,130,30,0.18)' : stop.kind === 'planned' ? 'rgba(128,128,128,0.15)' : 'rgba(214,68,47,0.15)';
            ctx.fillRect(x1, chartArea.top, x2 - x1, chartArea.bottom - chartArea.top);
        }
        ctx.restore();
    },
};

function CycleDetail({ detail, slowFactor, onClose }) {
    const canvas = useRef(null);
    const points = detail.points ?? [];
    const ideal = detail.ideal_cycle_seconds;
    const typical = detail.typical_cycle_seconds;
    const cap = Math.ceil(Math.max(typical ?? 0, ideal ?? 0) * 3) || null;
    const offChart = cap ? points.filter(([, s]) => s > cap).length : 0;
    const slow = ideal ? points.filter(([, s]) => s > ideal * slowFactor).length : null;

    useEffect(() => {
        if (!canvas.current || detail.loading || detail.error) return undefined;
        const dayStart = Date.parse(`${detail.date}T00:00:00`);
        const xy = points.map(([t, s]) => ({ x: Date.parse(t), y: s }));
        const flat = (y) => [{ x: dayStart, y }, { x: dayStart + 86_400_000, y }];
        const chart = new Chart(canvas.current, {
            type: 'scatter',
            data: {
                datasets: [
                    { label: __('Cycle'), data: xy, pointRadius: 2, backgroundColor: '#2563eb' },
                    ...(ideal ? [{ type: 'line', label: __('Ideal cycle'), data: flat(ideal), borderColor: '#1C9A55', borderDash: [6, 4], pointRadius: 0 }] : []),
                    ...(ideal ? [{ type: 'line', label: __('Slow above'), data: flat(ideal * slowFactor), borderColor: '#D6442F', borderDash: [2, 4], pointRadius: 0 }] : []),
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    jkStops: { stops: detail.stops },
                    legend: { labels: { color: TICKS, boxWidth: 12 } },
                    tooltip: { callbacks: { label: (c) => `${formatTime(new Date(c.parsed.x))} · ${formatNumber(c.parsed.y, { maximumFractionDigits: 2 })} s` } },
                },
                scales: {
                    x: { type: 'linear', min: dayStart, max: dayStart + 86_400_000, ticks: { color: TICKS, stepSize: 3_600_000, callback: (v) => formatTime(new Date(v)) }, grid: { color: GRID } },
                    y: { beginAtZero: true, ...(cap ? { max: cap } : {}), ticks: { color: TICKS, callback: (v) => `${v} s` }, grid: { color: GRID } },
                },
            },
            plugins: [stopsPlugin],
        });
        return () => chart.destroy();
    }, [detail]);

    return (
        <Panel title={__('Cycles of :title', { title: detail.title ?? '' })} hint={__('Each point is one cycle reported by the machine; shaded: stops (red), mold changes (amber), planned stops (gray).')}>
            <div className="flex justify-end -mt-8 mb-2 print:hidden">
                <button type="button" onClick={onClose} className="text-xs text-om-muted hover:underline">{__('Close')}</button>
            </div>
            {detail.loading && <p className="text-sm text-om-muted">{__('Loading…')}</p>}
            {detail.error && <p className="text-sm text-om-blocked">{__('Could not load the cycles.')}</p>}
            {!detail.loading && !detail.error && (
                <>
                    <div className="h-72"><canvas ref={canvas} /></div>
                    <p className="mt-2 text-xs text-om-muted">
                        {__(':n cycles', { n: formatNumber(points.length) })}
                        {' · '}{__('Typical cycle')}: {sec(typical)}
                        {' · '}{__('Ideal cycle')}: {sec(ideal)}
                        {slow != null && <>{' · '}{__('Slow cycles')}: {formatNumber(slow)}</>}
                        {offChart > 0 && <>{' · '}{__(':n cycles above :s s are off the chart.', { n: offChart, s: cap })}</>}
                    </p>
                </>
            )}
        </Panel>
    );
}

MachineOeeReport.layout = (page) => <AppLayout>{page}</AppLayout>;
