<?php

namespace Modules\JkControl\Tests\Feature;

use App\Models\DowntimeReason;
use App\Models\Line;
use App\Models\MachineConnection;
use App\Models\MachineEvent;
use App\Models\MachineTag;
use App\Models\ProductionDowntime;
use App\Models\Shift;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationState;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\JkControl\Models\IdealCycle;
use Modules\JkControl\Models\MachineOeeSnapshot;
use Modules\JkControl\Providers\JkControlServiceProvider;
use Modules\JkControl\Services\MachineOeeReport;
use Tests\Support\ModuleTestCase;

class MachineOeeReportTest extends ModuleTestCase
{
    protected string $module = 'JkControl';

    protected string $provider = JkControlServiceProvider::class;

    protected string $probeTable = 'jk_machine_oee_snapshots';

    private User $supervisor;

    private Line $line;

    private Workstation $press;

    private MachineConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-21 10:00:00'); // a Wednesday: Mon 19 and Tue 20 are over

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('Supervisor');

        $this->line = Line::factory()->create();
        $this->press = Workstation::factory()->create(['line_id' => $this->line->id, 'code' => 'NIT-07']);
        $this->connection = MachineConnection::create(['name' => 'inj07', 'protocol' => MachineConnection::PROTOCOL_REST, 'line_id' => $this->line->id, 'is_active' => true]);
        foreach ([['inj07/parada', MachineTag::SIGNAL_STATE, ['value_map' => ['0' => 'RUNNING', '1' => 'STOPPED']], 'NIT-07 parada'],
            ['inj07/injecao_hora', MachineTag::SIGNAL_GOOD_COUNT, ['scale' => 1], 'NIT-07 injecao_hora'],
            ['inj07/ciclo', MachineTag::SIGNAL_TELEMETRY, ['scale' => 0.001], 'Ciclo (s)']] as [$address, $signal, $transform, $name]) {
            MachineTag::create([
                'machine_connection_id' => $this->connection->id, 'workstation_id' => $this->press->id, 'address' => $address,
                'name' => $name, 'signal_type' => $signal, 'data_type' => 'int32', 'transform' => $transform, 'is_active' => true,
            ]);
        }
        IdealCycle::create(['workstation_id' => $this->press->id, 'cycle_seconds' => 20, 'source' => 'manual']);

        // Two 8 h days of the same machine: 06:00–14:00 running, 100 cycles of ~24 s.
        foreach (['2026-10-19', '2026-10-20'] as $day) {
            WorkstationState::create(['workstation_id' => $this->press->id, 'state' => WorkstationState::RUNNING, 'started_at' => "{$day} 06:00:00", 'ended_at' => "{$day} 14:00:00"]);
            $this->event($day.' 10:00:00', MachineEvent::TYPE_COUNTER, ['kind' => 'good', 'value' => 1, 'delta' => 1200]);
            foreach ([23.5, 24.0, 24.0, 24.5, 90.0] as $i => $seconds) {
                $this->event($day.' 08:0'.$i.':00', MachineEvent::TYPE_TELEMETRY, ['Ciclo (s)' => $seconds]);
            }
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function event(string $at, string $type, array $payload): void
    {
        MachineEvent::create(['workstation_id' => $this->press->id, 'machine_connection_id' => $this->connection->id, 'event_type' => $type, 'event_timestamp' => $at, 'payload' => $payload]);
    }

    private function build(string $granularity): array
    {
        return app(MachineOeeReport::class)->build(collect([$this->press]), Carbon::parse('2026-10-19'), Carbon::parse('2026-10-21'), $granularity);
    }

    public function test_days_that_are_over_are_kept_and_weeks_merge_them(): void
    {
        $days = $this->build('day');

        $this->assertSame(['2026-10-19', '2026-10-20', '2026-10-21'], array_column($days['rows'], 'period'));
        $this->assertSame(2, MachineOeeSnapshot::count(), 'today is still running: not kept');
        $this->assertEquals(1200, $days['rows'][0]['produced']);
        $this->assertSame(24.0, $days['rows'][0]['typical_cycle_seconds']);
        $this->assertSame(1, $days['rows'][0]['slow_cycles'], '90 s is above 1.5 × 20 s');

        $week = $this->build('week');
        $this->assertSame(['2026-W43'], array_column($week['rows'], 'period'));
        $this->assertEquals(2400, $week['rows'][0]['produced']);
        $this->assertSame(2, $week['rows'][0]['slow_cycles']);
        $this->assertSame(10, $week['rows'][0]['cycle_samples']);
        // 2 × 1 200 cycles × 20 s ÷ (2 × 8 h running) = 83.3 %
        $this->assertSame(83.3, $week['summary'][0]['performance']);
        $this->assertSame(2, MachineOeeSnapshot::count(), 'the week reused the kept days');
    }

    public function test_months_merge_days_too(): void
    {
        $month = $this->build('month');

        $this->assertSame(['2026-10'], array_column($month['rows'], 'period'));
        $this->assertEquals(2400, $month['rows'][0]['produced']);
    }

    public function test_kept_days_are_measured_again_when_what_they_depend_on_changes(): void
    {
        $this->build('day');
        $this->assertSame(2, MachineOeeSnapshot::count());

        IdealCycle::where('workstation_id', $this->press->id)->update(['cycle_seconds' => 22]); // query update: no event
        IdealCycle::first()->update(['cycle_seconds' => 24]);
        $this->assertSame(0, MachineOeeSnapshot::count(), 'a new ideal cycle forgets everything');

        $this->build('day');
        ProductionDowntime::create([
            'line_id' => $this->line->id, 'workstation_id' => $this->press->id, 'needs_reason' => false,
            'downtime_reason_id' => DowntimeReason::factory()->create(['code' => 'X', 'kind' => 'unplanned'])->id,
            'started_at' => '2026-10-20 07:00:00', 'ended_at' => '2026-10-20 07:10:00',
        ]);
        $this->assertSame(['2026-10-19'], MachineOeeSnapshot::pluck('period_start')->map->toDateString()->all(), 'a stop forgets its own day only');

        Shift::create(['name' => 'Manhã', 'code' => 'M', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'line_id' => $this->line->id, 'is_active' => true]);
        $this->assertSame(0, MachineOeeSnapshot::count(), 'a shift change forgets everything');
    }

    public function test_by_shift_lists_each_shift_of_the_line(): void
    {
        $this->assertTrue($this->build('shift')['without_shift_buckets']);

        Shift::create(['name' => 'Manhã', 'code' => 'M', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'line_id' => $this->line->id, 'is_active' => true]);
        $report = $this->build('shift');

        $this->assertFalse($report['without_shift_buckets']);
        $this->assertSame(['Manhã', 'Manhã', 'Manhã'], array_column($report['rows'], 'shift'));
        $this->assertSame(100.0, $report['rows'][0]['availability']);
        $this->assertSame(480, $report['rows'][0]['planned_minutes']);
    }

    public function test_the_report_page_and_its_filters(): void
    {
        $this->actingAs($this->supervisor)
            ->get('/jkcontrol/oee/report?date_from=2026-10-19&date_to=2026-10-21&granularity=week')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('jkcontrol/MachineOeeReport', false)
                ->where('filters.granularity', 'week')
                ->where('filters.date_from', '2026-10-19')
                ->has('rows', 1)
                ->has('summary', 1)
                ->where('summary.0.workstation.code', 'NIT-07'));
    }

    public function test_dates_default_to_the_last_week_and_never_reach_the_future(): void
    {
        $this->actingAs($this->supervisor)
            ->get('/jkcontrol/oee/report?date_to=2027-01-01&granularity=forever')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.date_to', '2026-10-21')
                ->where('filters.date_from', '2026-10-15')
                ->where('filters.granularity', 'day'));
    }

    public function test_the_cycles_of_one_day_with_its_stops(): void
    {
        ProductionDowntime::create([
            'line_id' => $this->line->id, 'workstation_id' => $this->press->id, 'needs_reason' => false,
            'downtime_reason_id' => DowntimeReason::factory()->create(['code' => 'changeover', 'name' => 'Changeover / Setup', 'kind' => 'changeover'])->id,
            'started_at' => '2026-10-20 12:00:00', 'ended_at' => '2026-10-20 12:30:00',
        ]);

        $this->actingAs($this->supervisor)
            ->getJson("/jkcontrol/oee/cycles?workstation_id={$this->press->id}&date=2026-10-20")
            ->assertOk()
            ->assertJsonCount(5, 'points')
            ->assertJsonPath('points.0.1', 23.5)
            ->assertJsonPath('ideal_cycle_seconds', 20)
            ->assertJsonPath('typical_cycle_seconds', 24)
            ->assertJsonPath('stops.0.kind', 'changeover');
    }

    public function test_recalculate_forgets_the_range(): void
    {
        $this->build('day');

        $this->actingAs($this->supervisor)
            ->post('/jkcontrol/oee/recalculate', ['date_from' => '2026-10-20', 'date_to' => '2026-10-20'])
            ->assertRedirect();

        $this->assertSame(['2026-10-19'], MachineOeeSnapshot::pluck('period_start')->map->toDateString()->all());
    }

    public function test_requires_a_supervisor_or_admin(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('Operator');

        $this->actingAs($operator)->get('/jkcontrol/oee/report')->assertForbidden();
        $this->actingAs($operator)->getJson("/jkcontrol/oee/cycles?workstation_id={$this->press->id}&date=2026-10-20")->assertForbidden();
    }
}
