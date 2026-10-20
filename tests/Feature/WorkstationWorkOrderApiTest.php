<?php

namespace Modules\JkControl\Tests\Feature;

use App\Models\Batch;
use App\Models\Line;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Workstation;
use App\Services\WorkOrder\MachineProductionService;
use Illuminate\Support\Carbon;
use Modules\JkControl\Providers\JkControlServiceProvider;
use Tests\Support\ModuleTestCase;

class WorkstationWorkOrderApiTest extends ModuleTestCase
{
    protected string $module = 'JkControl';

    protected string $provider = JkControlServiceProvider::class;

    protected string $probeTable = 'jk_operator_sessions';

    private User $connector;

    private Line $line;

    private Workstation $press;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-19 10:00:00');

        $this->connector = User::factory()->create();
        $this->connector->assignRole('Supervisor');

        $this->line = Line::factory()->create();
        $this->press = Workstation::factory()->create(['line_id' => $this->line->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A whole-batch order as a spreadsheet import leaves it: pending, no routing, operator-counted. */
    private function order(string $number, array $attributes = []): WorkOrder
    {
        return WorkOrder::factory()->create([
            'order_no' => $number,
            'line_id' => $this->line->id,
            'process_snapshot' => null,
            'planned_qty' => 1000,
            'produced_qty' => 0,
            'status' => WorkOrder::STATUS_PENDING,
            'counting_source' => WorkOrder::COUNTING_OPERATOR,
            ...$attributes,
        ]);
    }

    private function card(string $number, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->connector, 'sanctum')
            ->postJson("/api/v1/jk/workstations/{$this->press->id}/work-order", ['order_no' => $number, ...$extra]);
    }

    private function activeOrder(): ?WorkOrder
    {
        return app(MachineProductionService::class)->resolveActiveWorkOrder($this->press);
    }

    public function test_card_starts_the_order_at_the_machine(): void
    {
        $order = $this->order('500101');

        $this->card('500101', ['occurred_at' => '2026-10-19T08:00:00-03:00'])
            ->assertCreated()
            ->assertJsonPath('status', 'started')
            ->assertJsonPath('data.order_no', '500101')
            ->assertJsonPath('data.work_order_status', WorkOrder::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.counting_source', WorkOrder::COUNTING_MACHINE);

        $batch = Batch::where('work_order_id', $order->id)->sole();
        $this->assertSame(Batch::STATUS_IN_PROGRESS, $batch->status);
        $this->assertSame($this->press->id, $batch->workstation_id);
        $this->assertSame(1000.0, (float) $batch->target_qty);
        $this->assertTrue($batch->started_at->eq(Carbon::parse('2026-10-19T08:00:00-03:00')));
        $this->assertSame($order->id, $this->activeOrder()?->id);
    }

    public function test_machine_counts_go_to_the_order_whose_card_was_read(): void
    {
        $first = $this->order('500102');
        $second = $this->order('500103');
        $production = app(MachineProductionService::class);

        $this->card('500102')->assertCreated();
        $production->recordGoodCount($this->activeOrder(), 30);
        $this->card('500103')->assertCreated();
        $production->recordGoodCount($this->activeOrder(), 12);

        $this->assertSame(30.0, (float) $first->fresh()->produced_qty);
        $this->assertSame(12.0, (float) $second->fresh()->produced_qty);
    }

    public function test_a_new_card_parks_the_previous_order_and_its_card_resumes_it(): void
    {
        $first = $this->order('500102');
        $second = $this->order('500103');

        $this->card('500102')->assertCreated();
        $firstBatch = Batch::where('work_order_id', $first->id)->sole();
        $this->card('500103')->assertCreated();

        $this->assertSame(Batch::STATUS_PENDING, $firstBatch->fresh()->status);
        $this->assertSame($second->id, $this->activeOrder()?->id);

        $this->card('500102')
            ->assertOk()
            ->assertJsonPath('status', 'resumed')
            ->assertJsonPath('data.batch_id', $firstBatch->id);

        $this->assertSame($first->id, $this->activeOrder()?->id);
        $this->assertSame(Batch::STATUS_PENDING, Batch::where('work_order_id', $second->id)->sole()->status);
        $this->assertSame(1, Batch::where('work_order_id', $first->id)->count());
    }

    public function test_reading_the_running_order_again_is_safe(): void
    {
        $order = $this->order('500101');

        $this->card('500101')->assertCreated();
        $this->card('500101')->assertOk()->assertJsonPath('status', 'already_running');

        $this->assertSame(1, Batch::where('work_order_id', $order->id)->count());
    }

    public function test_card_number_is_matched_on_letters_and_digits(): void
    {
        $order = $this->order('77770001');

        $this->card('77770001@')->assertCreated()->assertJsonPath('data.work_order_id', $order->id);
    }

    public function test_a_counting_source_chosen_by_the_planner_is_kept(): void
    {
        $this->order('500101', ['counting_source' => WorkOrder::COUNTING_BOTH]);

        $this->card('500101')->assertCreated()->assertJsonPath('data.counting_source', WorkOrder::COUNTING_BOTH);
    }

    public function test_an_accepted_order_starts_too(): void
    {
        $this->order('500101', ['status' => WorkOrder::STATUS_ACCEPTED]);

        $this->card('500101')->assertCreated()->assertJsonPath('data.work_order_status', WorkOrder::STATUS_IN_PROGRESS);
    }

    public function test_unknown_order_is_refused(): void
    {
        $this->card('555')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order_no' => 'No work order has this number.']);
    }

    public function test_closed_or_held_orders_are_refused(): void
    {
        $this->order('1', ['status' => WorkOrder::STATUS_DONE]);
        $this->order('2', ['status' => WorkOrder::STATUS_PAUSED]);

        $this->card('1')->assertUnprocessable()->assertJsonValidationErrors(['order_no' => 'already closed']);
        $this->card('2')->assertUnprocessable()->assertJsonValidationErrors(['order_no' => 'on hold']);
        $this->assertSame(0, Batch::count());
    }

    public function test_order_planned_for_another_line_is_refused(): void
    {
        $this->order('500101', ['line_id' => Line::factory()->create()->id]);

        $this->card('500101')->assertUnprocessable()->assertJsonValidationErrors(['order_no' => 'another line']);
    }

    public function test_order_with_routing_steps_is_refused(): void
    {
        WorkOrder::factory()->create(['order_no' => '500101', 'line_id' => $this->line->id]);

        $this->card('500101')->assertUnprocessable()->assertJsonValidationErrors(['order_no' => 'routing steps']);
    }

    public function test_order_planned_to_start_later_is_refused(): void
    {
        $this->order('500101', ['planned_start_at' => now()->addDay()]);

        $this->card('500101')->assertUnprocessable()->assertJsonValidationErrors(['order_no']);
    }

    public function test_order_number_is_required(): void
    {
        $this->actingAs($this->connector, 'sanctum')
            ->postJson("/api/v1/jk/workstations/{$this->press->id}/work-order", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order_no']);
    }

    public function test_requires_a_supervisor_or_admin(): void
    {
        $this->order('500101');

        $this->postJson("/api/v1/jk/workstations/{$this->press->id}/work-order", ['order_no' => '500101'])
            ->assertUnauthorized();

        $operator = User::factory()->create();
        $operator->assignRole('Operator');
        $this->card('500101', [], $operator)->assertForbidden();
    }
}
