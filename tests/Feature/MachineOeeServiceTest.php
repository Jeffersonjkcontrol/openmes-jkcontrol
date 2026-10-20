<?php

namespace Modules\JkControl\Tests\Feature;

use App\Models\Batch;
use App\Models\DowntimeReason;
use App\Models\Line;
use App\Models\MachineConnection;
use App\Models\MachineEvent;
use App\Models\MachineTag;
use App\Models\ProcessTemplate;
use App\Models\ProductionDowntime;
use App\Models\ProductType;
use App\Models\ScrapEntry;
use App\Models\Shift;
use App\Models\WorkOrder;
use App\Models\Workstation;
use App\Models\WorkstationState;
use Illuminate\Support\Carbon;
use Modules\JkControl\Models\IdealCycle;
use Modules\JkControl\Providers\JkControlServiceProvider;
use Modules\JkControl\Services\IdealCycleService;
use Modules\JkControl\Services\MachineOeeService;
use Tests\Support\ModuleTestCase;

class MachineOeeServiceTest extends ModuleTestCase
{
    protected string $module = 'JkControl';

    protected string $provider = JkControlServiceProvider::class;

    protected string $probeTable = 'jk_ideal_cycles';

    private Line $line;

    private Workstation $press;

    private MachineConnection $connection;

    private ProductType $product;

    private WorkOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 14:00:00');

        $this->line = Line::factory()->create();
        $this->press = Workstation::factory()->create(['line_id' => $this->line->id, 'code' => 'NIT-07']);
        $this->connection = MachineConnection::create([
            'name' => 'inj07 (teste)', 'protocol' => MachineConnection::PROTOCOL_REST, 'line_id' => $this->line->id, 'is_active' => true,
        ]);
        $this->tag('inj07/injecao_hora', MachineTag::SIGNAL_GOOD_COUNT, ['scale' => 1], 'NIT-07 injecao_hora');
        $this->tag('inj07/ciclo', MachineTag::SIGNAL_TELEMETRY, ['scale' => 0.001], 'Ciclo (s)');

        $this->product = ProductType::factory()->create(['code' => '89-0001']);
        $this->order = WorkOrder::factory()->create([
            'order_no' => '500101', 'line_id' => $this->line->id, 'product_type_id' => $this->product->id, 'process_snapshot' => null,
        ]);
        Batch::factory()->create(['work_order_id' => $this->order->id, 'workstation_id' => $this->press->id, 'status' => Batch::STATUS_IN_PROGRESS, 'started_at' => '2026-10-20 05:00:00']);

        Shift::create(['name' => 'Manhã', 'code' => 'M', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'line_id' => $this->line->id, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tag(string $address, string $signal, array $transform, string $name): void
    {
        MachineTag::create([
            'machine_connection_id' => $this->connection->id, 'workstation_id' => $this->press->id, 'address' => $address,
            'name' => $name, 'signal_type' => $signal, 'data_type' => 'int32', 'transform' => $transform, 'is_active' => true,
        ]);
    }

    private function state(string $state, string $from, ?string $to): void
    {
        WorkstationState::create(['workstation_id' => $this->press->id, 'state' => $state, 'started_at' => "2026-10-20 {$from}", 'ended_at' => $to ? "2026-10-20 {$to}" : null]);
    }

    private function stop(string $kind, string $from, string $to): void
    {
        ProductionDowntime::create([
            'line_id' => $this->line->id, 'workstation_id' => $this->press->id, 'needs_reason' => false,
            'downtime_reason_id' => DowntimeReason::factory()->create(['code' => "{$kind}-{$from}", 'kind' => $kind])->id,
            'started_at' => "2026-10-20 {$from}", 'ended_at' => "2026-10-20 {$to}",
        ]);
    }

    private function counted(string $at, float $pieces): void
    {
        MachineEvent::create([
            'workstation_id' => $this->press->id, 'machine_connection_id' => $this->connection->id, 'event_type' => MachineEvent::TYPE_COUNTER,
            'event_timestamp' => "2026-10-20 {$at}", 'payload' => ['kind' => 'good', 'value' => $pieces, 'delta' => $pieces],
        ]);
    }

    private function cycle(string $at, float $seconds): void
    {
        MachineEvent::create([
            'workstation_id' => $this->press->id, 'machine_connection_id' => $this->connection->id, 'event_type' => MachineEvent::TYPE_TELEMETRY,
            'event_timestamp' => "2026-10-20 {$at}", 'payload' => ['Ciclo (s)' => $seconds],
        ]);
    }

    /** The worked example: 8 h shift, 30 min lunch, 30 min breakdown, 24 s ideal, 980 pieces, 10 scrapped. */
    private function workedExample(): void
    {
        $this->state(WorkstationState::RUNNING, '05:00:00', '09:00:00');
        $this->state(WorkstationState::STOPPED, '09:00:00', '09:30:00');
        $this->state(WorkstationState::RUNNING, '09:30:00', '11:00:00');
        $this->state(WorkstationState::STOPPED, '11:00:00', '11:30:00');
        $this->state(WorkstationState::RUNNING, '11:30:00', null);
        $this->stop('unplanned', '09:00:00', '09:30:00');
        $this->stop('planned', '11:00:00', '11:30:00');

        $this->counted('05:30:00', 50); // before the shift: not planned time
        $this->counted('08:00:00', 400);
        $this->counted('12:00:00', 580);
        $this->cycle('08:00:00', 24.5);
        $this->cycle('12:00:00', 25.5);
        $this->cycle('12:01:00', 25.0);
        $this->cycle('12:02:00', 25.0);
        $this->cycle('11:31:00', 262.3); // first cycle after lunch: not the machine's pace

        ScrapEntry::factory()->create(['work_order_id' => $this->order->id, 'quantity' => 10, 'reported_at' => '2026-10-20 12:30:00']);
        IdealCycle::create(['product_type_id' => $this->product->id, 'cycle_seconds' => 24, 'source' => 'manual']);
    }

    private function oee(string $from = '05:00:00'): array
    {
        return app(MachineOeeService::class)->forPeriod($this->press, Carbon::parse("2026-10-20 {$from}"), Carbon::parse('2026-10-20 14:00:00'));
    }

    public function test_the_worked_example(): void
    {
        $this->workedExample();

        $oee = $this->oee();

        $this->assertSame(480, $oee['planned_minutes']);
        $this->assertSame(30, $oee['planned_stop_minutes']);
        $this->assertSame(420, $oee['running_minutes']);
        $this->assertSame(30, $oee['unplanned_stop_minutes']);
        $this->assertEquals(980, $oee['produced']);
        $this->assertEquals(10, $oee['rejected']);
        $this->assertSame(24.0, $oee['ideal_cycle_seconds']);
        $this->assertSame('product', $oee['ideal_cycle_source']);
        $this->assertSame(25.0, $oee['typical_cycle_seconds']);
        $this->assertSame(24.5, $oee['cycle_p10_seconds']);
        $this->assertSame(262.3, $oee['cycle_p90_seconds']);
        $this->assertSame(5, $oee['cycle_samples']);
        $this->assertSame(1, $oee['slow_cycles'], 'only the 262 s cycle after lunch is above 1.5 × 24 s');
        $this->assertSame(93.3, $oee['availability']);
        $this->assertSame(93.3, $oee['performance']);
        $this->assertSame(99.0, $oee['quality']);
        $this->assertSame(86.2, $oee['oee']);
        $this->assertSame('500101', $oee['order_no']);
        $this->assertFalse($oee['without_shifts']);
    }

    public function test_periods_merge_into_the_same_result(): void
    {
        $this->workedExample();
        $oee = app(MachineOeeService::class);
        $from = Carbon::parse('2026-10-20 05:00:00');

        $whole = $oee->summarize($oee->measure($this->press, $from, Carbon::parse('2026-10-20 14:00:00')));
        $merged = $oee->summarize($oee->merge([
            $oee->measure($this->press, $from, Carbon::parse('2026-10-20 10:00:00')),
            $oee->measure($this->press, Carbon::parse('2026-10-20 10:00:00'), Carbon::parse('2026-10-20 14:00:00')),
        ]));

        foreach (['planned_minutes', 'running_minutes', 'produced', 'rejected', 'availability', 'performance', 'quality', 'oee', 'slow_cycles', 'cycle_samples'] as $key) {
            $this->assertEquals($whole[$key], $merged[$key], $key);
        }
        $this->assertSame(25.0, $merged['typical_cycle_seconds'], 'from the histogram, tenths of a second');
    }

    public function test_a_mold_change_is_an_availability_loss(): void
    {
        $this->state(WorkstationState::RUNNING, '06:00:00', '10:00:00');
        $this->state(WorkstationState::STOPPED, '10:00:00', '11:00:00');
        $this->state(WorkstationState::RUNNING, '11:00:00', null);
        $this->stop('changeover', '10:00:00', '11:00:00');

        $oee = $this->oee('06:00:00');

        $this->assertSame(0, $oee['planned_stop_minutes']);
        $this->assertSame(60, $oee['changeover_minutes']);
        $this->assertSame(87.5, $oee['availability']);
    }

    public function test_a_line_without_shifts_is_planned_for_the_whole_period(): void
    {
        Shift::query()->delete();
        $this->state(WorkstationState::RUNNING, '05:00:00', null);

        $oee = $this->oee();

        $this->assertTrue($oee['without_shifts']);
        $this->assertSame(540, $oee['planned_minutes']);
        $this->assertSame(100.0, $oee['availability']);
    }

    public function test_without_an_ideal_cycle_there_is_no_performance_nor_oee(): void
    {
        $this->state(WorkstationState::RUNNING, '06:00:00', null);
        $this->counted('08:00:00', 100);

        $oee = $this->oee('06:00:00');

        $this->assertSame(100.0, $oee['availability']);
        $this->assertSame(100.0, $oee['quality']);
        $this->assertNull($oee['performance']);
        $this->assertNull($oee['oee']);
    }

    public function test_performance_counts_machine_cycles_not_pieces(): void
    {
        MachineTag::where('address', 'inj07/injecao_hora')->update(['transform' => ['scale' => 2]]);
        $this->state(WorkstationState::RUNNING, '06:00:00', null);
        $this->counted('08:00:00', 1200); // 600 injections of 2 cavities
        IdealCycle::create(['workstation_id' => $this->press->id, 'cycle_seconds' => 40, 'source' => 'manual']);

        $oee = $this->oee('06:00:00');

        $this->assertSame(2, $oee['cavities']);
        $this->assertSame('machine', $oee['ideal_cycle_source']);
        $this->assertSame(83.3, $oee['performance']); // 600 × 40 s ÷ 8 h
    }

    public function test_the_most_specific_ideal_cycle_wins(): void
    {
        $cycles = app(IdealCycleService::class);
        $other = Workstation::factory()->create(['line_id' => $this->line->id]);

        ProcessTemplate::factory()->create(['product_type_id' => $this->product->id, 'ideal_cycle_minutes' => 0.5, 'is_active' => true]);
        $this->assertSame(['seconds' => 60.0, 'source' => 'process_template'], $cycles->resolve($this->press, $this->product->id, 2));

        IdealCycle::create(['workstation_id' => $this->press->id, 'cycle_seconds' => 30, 'source' => 'manual']);
        $this->assertSame(['seconds' => 30.0, 'source' => 'machine'], $cycles->resolve($this->press, null));

        IdealCycle::create(['product_type_id' => $this->product->id, 'cycle_seconds' => 26, 'source' => 'erp']);
        $this->assertSame(['seconds' => 26.0, 'source' => 'product'], $cycles->resolve($this->press, $this->product->id));

        IdealCycle::create(['product_type_id' => $this->product->id, 'workstation_id' => $this->press->id, 'cycle_seconds' => 24, 'source' => 'spreadsheet']);
        $this->assertSame(['seconds' => 24.0, 'source' => 'product_machine'], $cycles->resolve($this->press, $this->product->id));
        $this->assertSame(['seconds' => 26.0, 'source' => 'product'], $cycles->resolve($other, $this->product->id));
    }
}
