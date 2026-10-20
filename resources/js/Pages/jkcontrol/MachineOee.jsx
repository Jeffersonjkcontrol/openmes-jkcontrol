import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '../../../../../../resources/js/layouts/AppLayout';
import OeeGauge, { oeeColor } from '../../../../../../resources/js/components/OeeGauge';
import { __, formatNumber, formatTime } from '../../../../../../resources/js/lib/i18n';

// Machine OEE (JkControl module): one gauge per machine that reports to OpenMES,
// computed by MachineOeeService from the machine's own states and counts, plus
// the ideal cycles it is measured against.

const PERIODS = [
    ['shift', 'Current shift'],
    ['today', 'Today'],
    ['yesterday', 'Yesterday'],
];

const BAR_CLASS = { green: 'bg-om-running', yellow: 'bg-om-downtime', red: 'bg-om-blocked', gray: 'bg-om-line2' };

const STATE_LABEL = { RUNNING: 'Running', STOPPED: 'Stopped', FAULT: 'Fault', IDLE: 'Idle', WAITING: 'Waiting' };

const pct = (v) => (v == null ? '—' : `${formatNumber(v, { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`);
const seconds = (v) => (v == null ? '—' : `${formatNumber(v, { maximumFractionDigits: 2 })} s`);

export default function MachineOee({ machines, filters, lines, idealCycles, products, workstations }) {
    const go = (changes) => router.get('/jkcontrol/oee', { ...filters, ...changes }, { preserveScroll: true, preserveState: true });

    return (
        <div className="max-w-7xl mx-auto">
            <Head title={__('Machine OEE')} />

            <div className="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div>
                    <h1 className="text-[26px] font-semibold tracking-[-0.02em] text-om-ink">{__('Machine OEE')}</h1>
                    <p className="text-sm text-om-muted">{__('Availability × performance × quality, from what each machine reports.')}</p>
                </div>
                <div className="flex gap-2">
                    <select
                        className="rounded-om border border-om-line bg-om-card text-sm px-3 py-2"
                        value={filters.line_id ?? ''}
                        onChange={(e) => go({ line_id: e.target.value || null })}
                    >
                        <option value="">{__('All lines')}</option>
                        {lines.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
                    </select>
                    <select
                        className="rounded-om border border-om-line bg-om-card text-sm px-3 py-2"
                        value={filters.period}
                        onChange={(e) => go({ period: e.target.value })}
                    >
                        {PERIODS.map(([key, label]) => <option key={key} value={key}>{__(label)}</option>)}
                    </select>
                </div>
            </div>

            {machines.length === 0 ? (
                <p className="text-om-muted text-sm">{__('No machine reports to OpenMES yet.')}</p>
            ) : (
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-10">
                    {machines.map((m) => <MachineCard key={m.workstation.id} m={m} />)}
                </div>
            )}

            <IdealCycles rows={idealCycles} products={products} workstations={workstations} />
        </div>
    );
}

function MachineCard({ m }) {
    const hints = [];
    if (m.without_shifts) hints.push(__('No shifts on this line: the whole period counts as planned.'));
    if (m.ideal_cycle_seconds == null) hints.push(__('No ideal cycle for this machine or product: performance and OEE need one.'));
    if (m.performance != null && m.performance > 100) hints.push(__('Faster than the ideal cycle: check the ideal cycle.'));

    return (
        <div className="bg-om-card border border-om-line rounded-om p-5">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="text-[15px] font-semibold text-om-ink truncate">{m.workstation.code}</p>
                    <p className="text-xs text-om-muted truncate">{m.workstation.name}</p>
                </div>
                {m.state && <span className="text-[11px] font-mono px-2 py-0.5 rounded-om-sm border border-om-line text-om-muted">{__(STATE_LABEL[m.state] ?? m.state)}</span>}
            </div>
            <p className="text-xs text-om-muted mt-1">
                {m.order_no ? `${__('Order')} ${m.order_no}` : __('No order running')}
                {' · '}
                {formatTime(m.from)}–{formatTime(m.to)}
            </p>

            <div className="flex justify-center my-3">
                <OeeGauge value={m.oee} size={150} />
            </div>

            <Bar label={__('Availability')} value={m.availability} />
            <Bar label={__('Performance')} value={m.performance} />
            <Bar label={__('Quality')} value={m.quality} />

            <dl className="mt-4 pt-3 border-t border-om-line grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                <dt className="text-om-muted">{__('Running')}</dt>
                <dd className="text-right font-mono">{m.running_minutes} / {m.planned_minutes - m.planned_stop_minutes} min</dd>
                <dt className="text-om-muted">{__('Stops')}</dt>
                <dd className="text-right font-mono">{m.unplanned_stop_minutes} min</dd>
                <dt className="text-om-muted">{__('Mold changes')}</dt>
                <dd className="text-right font-mono">{m.changeover_minutes} min</dd>
                <dt className="text-om-muted">{__('Pieces')}</dt>
                <dd className="text-right font-mono">{formatNumber(m.produced)} · {__('scrap')} {formatNumber(m.rejected)}</dd>
                <dt className="text-om-muted">{__('Typical cycle')}</dt>
                <dd className="text-right font-mono">{seconds(m.typical_cycle_seconds)}</dd>
                <dt className="text-om-muted">{__('Ideal cycle')}</dt>
                <dd className="text-right font-mono">{seconds(m.ideal_cycle_seconds)}</dd>
            </dl>

            {hints.map((h) => <p key={h} className="mt-2 text-[11.5px] text-om-downtime">{h}</p>)}
        </div>
    );
}

function Bar({ label, value }) {
    return (
        <div className="flex items-center gap-2 text-xs mt-1.5">
            <span className="w-28 text-om-muted">{label}</span>
            <div className="flex-1 h-1.5 rounded-full bg-om-line overflow-hidden">
                <div className={`h-full ${BAR_CLASS[oeeColor(value)]}`} style={{ width: `${Math.min(100, value ?? 0)}%` }} />
            </div>
            <span className="w-14 text-right font-mono">{pct(value)}</span>
        </div>
    );
}

function IdealCycles({ rows, products, workstations }) {
    const form = useForm({ product_type_id: '', workstation_id: '', cycle_seconds: '' });
    const submit = (e) => {
        e.preventDefault();
        form.post('/jkcontrol/ideal-cycles', { preserveScroll: true, onSuccess: () => form.reset() });
    };
    const remove = (id) => router.delete(`/jkcontrol/ideal-cycles/${id}`, { preserveScroll: true });
    const sourceLabel = { manual: __('Manual'), spreadsheet: __('Spreadsheet'), erp: __('ERP') };

    return (
        <div className="bg-om-card border border-om-line rounded-om p-5">
            <h2 className="text-[14px] font-semibold text-om-ink">{__('Ideal cycles')}</h2>
            <p className="text-xs text-om-muted mb-4">
                {__('Seconds per machine cycle (one injection). The most specific row wins: product on the machine, then the product, then the machine. Also in Admin → Import and from the ERP.')}
            </p>

            <form onSubmit={submit} className="flex flex-wrap gap-2 items-start mb-4">
                <select className="rounded-om border border-om-line bg-om-card text-sm px-3 py-2" value={form.data.product_type_id} onChange={(e) => form.setData('product_type_id', e.target.value)}>
                    <option value="">{__('Any product')}</option>
                    {products.map((p) => <option key={p.id} value={p.id}>{p.code} — {p.name}</option>)}
                </select>
                <select className="rounded-om border border-om-line bg-om-card text-sm px-3 py-2" value={form.data.workstation_id} onChange={(e) => form.setData('workstation_id', e.target.value)}>
                    <option value="">{__('Any machine')}</option>
                    {workstations.map((w) => <option key={w.id} value={w.id}>{w.code}</option>)}
                </select>
                <input
                    className="rounded-om border border-om-line bg-om-card text-sm px-3 py-2 w-36"
                    inputMode="decimal"
                    placeholder="24,96"
                    value={form.data.cycle_seconds}
                    onChange={(e) => form.setData('cycle_seconds', e.target.value.replace(',', '.'))}
                />
                <button type="submit" disabled={form.processing} className="rounded-om bg-om-accent text-white text-sm px-4 py-2">
                    {__('Save')}
                </button>
                {Object.values(form.errors).map((err) => <p key={err} className="w-full text-xs text-om-blocked">{err}</p>)}
            </form>

            {rows.length === 0 ? (
                <p className="text-sm text-om-muted">{__('No ideal cycle yet.')}</p>
            ) : (
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-left text-xs text-om-muted border-b border-om-line">
                            <th className="py-2">{__('Product')}</th>
                            <th>{__('Machine')}</th>
                            <th className="text-right">{__('Ideal cycle')}</th>
                            <th>{__('Source')}</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.id} className="border-b border-om-line last:border-0">
                                <td className="py-2">{r.product ?? <span className="text-om-faint">{__('Any product')}</span>}</td>
                                <td>{r.machine ?? <span className="text-om-faint">{__('Any machine')}</span>}</td>
                                <td className="text-right font-mono">{seconds(r.cycle_seconds)}</td>
                                <td className="text-om-muted">{sourceLabel[r.source] ?? r.source}</td>
                                <td className="text-right">
                                    <button type="button" onClick={() => remove(r.id)} className="text-xs text-om-blocked hover:underline">{__('Remove')}</button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
}

MachineOee.layout = (page) => <AppLayout>{page}</AppLayout>;
