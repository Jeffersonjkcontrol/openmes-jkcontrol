<?php

namespace Modules\JkControl\Tests\Feature;

use App\Models\DowntimeReason;
use App\Models\Line;
use App\Models\ProductionDowntime;
use App\Models\User;
use App\Models\Workstation;
use Illuminate\Support\Carbon;
use Modules\JkControl\Providers\JkControlServiceProvider;
use Tests\Support\ModuleTestCase;

class WorkstationDowntimeReasonApiTest extends ModuleTestCase
{
    protected string $module = 'JkControl';

    protected string $provider = JkControlServiceProvider::class;

    protected string $probeTable = 'jk_operator_sessions';

    private User $connector;

    private Workstation $press;

    private DowntimeReason $autoStop;

    private DowntimeReason $noMaterial;

    private DowntimeReason $toolChange;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-19 10:00:00');

        $this->connector = User::factory()->create();
        $this->connector->assignRole('Supervisor');

        $line = Line::factory()->create();
        $this->press = Workstation::factory()->create(['line_id' => $line->id]);

        $this->autoStop = DowntimeReason::factory()->create(['code' => 'AUTO-STOP', 'name' => 'Machine stopped (auto)']);
        $this->noMaterial = DowntimeReason::factory()->create(['code' => '12', 'name' => 'Falta de material']);
        $this->toolChange = DowntimeReason::factory()->create(['code' => '15', 'name' => 'Troca de ferramenta']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A stop as the core's WorkstationStateMachine opens it. */
    private function autoStop(string $startedAt, ?string $endedAt = null): ProductionDowntime
    {
        return ProductionDowntime::create([
            'line_id' => $this->press->line_id,
            'workstation_id' => $this->press->id,
            'downtime_reason_id' => $this->autoStop->id,
            'needs_reason' => true,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
        ]);
    }

    private function classify(array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->connector, 'sanctum')
            ->postJson("/api/v1/jk/workstations/{$this->press->id}/downtime-reason", $payload);
    }

    public function test_gives_the_running_stop_its_cause(): void
    {
        $stop = $this->autoStop('2026-10-19 09:50:00');

        $this->classify(['reason_code' => '12'])
            ->assertOk()
            ->assertJsonPath('status', 'classified')
            ->assertJsonPath('data.downtime_id', $stop->id)
            ->assertJsonPath('data.reason.code', '12');

        $stop->refresh();
        $this->assertSame($this->noMaterial->id, $stop->downtime_reason_id);
        $this->assertFalse($stop->needs_reason);
        $this->assertSame($this->connector->id, $stop->classified_by_id);
        $this->assertNotNull($stop->classified_at);
    }

    public function test_cause_typed_after_restarting_goes_to_the_stop_that_just_ended(): void
    {
        $stop = $this->autoStop('2026-10-19 09:30:00', '2026-10-19 09:45:00');

        $this->classify(['reason_code' => '12', 'occurred_at' => '2026-10-19 09:55:00'])
            ->assertOk()
            ->assertJsonPath('data.downtime_id', $stop->id);
    }

    public function test_stop_that_ended_outside_the_grace_window_is_not_matched(): void
    {
        $this->autoStop('2026-10-19 07:00:00', '2026-10-19 07:30:00');

        $this->classify(['reason_code' => '12'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('occurred_at');
    }

    public function test_the_stop_running_at_the_reported_moment_wins_over_newer_ones(): void
    {
        $earlier = $this->autoStop('2026-10-19 08:00:00', '2026-10-19 08:20:00');
        $this->autoStop('2026-10-19 09:50:00');

        $this->classify(['reason_code' => '12', 'occurred_at' => '2026-10-19 08:10:00'])
            ->assertOk()
            ->assertJsonPath('data.downtime_id', $earlier->id);
    }

    public function test_a_cause_decided_by_a_person_is_not_overwritten(): void
    {
        $supervisor = User::factory()->create();
        $stop = $this->autoStop('2026-10-19 09:50:00');
        $stop->classify($this->toolChange, $supervisor);

        $this->classify(['reason_code' => '12'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason_code', '15');

        $this->assertSame($this->toolChange->id, $stop->fresh()->downtime_reason_id);
    }

    public function test_the_machine_can_correct_a_cause_it_set_itself(): void
    {
        $stop = $this->autoStop('2026-10-19 09:50:00');

        $this->classify(['reason_code' => '12'])->assertOk();
        $this->classify(['reason_code' => '15'])->assertOk()->assertJsonPath('data.reason.code', '15');

        $this->assertSame($this->toolChange->id, $stop->fresh()->downtime_reason_id);
    }

    public function test_placeholder_codes_are_not_a_cause(): void
    {
        $this->autoStop('2026-10-19 09:50:00');

        $this->classify(['reason_code' => 'AUTO-STOP'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason_code');
    }

    public function test_unknown_and_inactive_codes_are_rejected(): void
    {
        $this->autoStop('2026-10-19 09:50:00');
        $this->toolChange->update(['is_active' => false]);

        $this->classify(['reason_code' => '99'])->assertStatus(422)->assertJsonValidationErrors('reason_code');
        $this->classify(['reason_code' => '15'])->assertStatus(422)->assertJsonValidationErrors('reason_code');
        $this->classify([])->assertStatus(422)->assertJsonValidationErrors('reason_code');
    }

    public function test_unknown_workstation_is_not_found(): void
    {
        $this->actingAs($this->connector, 'sanctum')
            ->postJson('/api/v1/jk/workstations/999999/downtime-reason', ['reason_code' => '12'])
            ->assertNotFound();
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->postJson("/api/v1/jk/workstations/{$this->press->id}/downtime-reason", ['reason_code' => '12'])
            ->assertUnauthorized();
    }

    public function test_operator_role_is_forbidden(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('Operator');
        $this->autoStop('2026-10-19 09:50:00');

        $this->classify(['reason_code' => '12'], $operator)->assertForbidden();
    }
}
