<?php

namespace Modules\JkControl\Tests\Feature;

use App\Models\Batch;
use App\Models\Line;
use App\Models\QualityCheck;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Workstation;
use Illuminate\Support\Carbon;
use Modules\JkControl\Providers\JkControlServiceProvider;
use Tests\Support\ModuleTestCase;

class WorkstationQualityCheckApiTest extends ModuleTestCase
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
        app()->setLocale('en');
        parent::tearDown();
    }

    /** An order started at the press by its card, as the connector does. */
    private function runningOrder(string $number): WorkOrder
    {
        $order = WorkOrder::factory()->create([
            'order_no' => $number,
            'line_id' => $this->line->id,
            'process_snapshot' => null,
            'planned_qty' => 1000,
            'produced_qty' => 0,
            'status' => WorkOrder::STATUS_PENDING,
        ]);

        $this->actingAs($this->connector, 'sanctum')
            ->postJson("/api/v1/jk/workstations/{$this->press->id}/work-order", ['order_no' => $number])
            ->assertCreated();

        return $order;
    }

    private function card(array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->connector, 'sanctum')
            ->postJson("/api/v1/jk/workstations/{$this->press->id}/quality-check", $payload);
    }

    public function test_approval_becomes_a_quality_check_on_the_running_order(): void
    {
        $order = $this->runningOrder('500101');
        $batch = Batch::where('work_order_id', $order->id)->sole();

        $this->card(['passed' => true, 'occurred_at' => '2026-10-19T08:14:26-03:00'])
            ->assertCreated()
            ->assertJsonPath('status', 'recorded')
            ->assertJsonPath('data.batch_id', $batch->id)
            ->assertJsonPath('data.order_no', '500101')
            ->assertJsonPath('data.all_passed', true);

        $check = QualityCheck::sole();
        $this->assertSame($batch->id, $check->batch_id);
        $this->assertTrue((bool) $check->all_passed);
        $this->assertNull($check->checked_by, 'the card does not say who read it');
        $this->assertTrue($check->checked_at->eq(Carbon::parse('2026-10-19T08:14:26-03:00')));
        $this->assertSame('Approved at the machine with the quality card.', $check->notes);
    }

    public function test_rejection_is_recorded_as_not_passed(): void
    {
        $this->runningOrder('500101');

        $this->card(['passed' => false])->assertCreated()->assertJsonPath('data.all_passed', false);

        $this->assertSame('Rejected at the machine with the quality card.', QualityCheck::sole()->notes);
    }

    public function test_the_same_reading_twice_is_recorded_once(): void
    {
        $this->runningOrder('500101');
        $reading = ['passed' => true, 'occurred_at' => '2026-10-19T08:14:26-03:00'];

        $this->card($reading)->assertCreated();
        $this->card($reading)->assertOk()->assertJsonPath('status', 'already_recorded');

        $this->assertSame(1, QualityCheck::count());
    }

    public function test_without_a_running_order_it_is_refused(): void
    {
        $this->card(['passed' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['workstation' => 'No work order is running at this workstation.']);
    }

    public function test_messages_follow_the_system_language(): void
    {
        app()->setLocale('pt_BR');

        $this->card(['passed' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['workstation' => 'Nenhuma ordem de produção está rodando neste posto.']);
    }

    public function test_passed_is_required(): void
    {
        $this->runningOrder('500101');

        $this->card([])->assertUnprocessable()->assertJsonValidationErrors(['passed']);
    }

    public function test_requires_a_supervisor_or_admin(): void
    {
        $this->postJson("/api/v1/jk/workstations/{$this->press->id}/quality-check", ['passed' => true])
            ->assertUnauthorized();

        $operator = User::factory()->create();
        $operator->assignRole('Operator');
        $this->card(['passed' => true], $operator)->assertForbidden();
    }
}
