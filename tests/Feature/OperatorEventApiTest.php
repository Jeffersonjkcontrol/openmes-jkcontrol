<?php

namespace Modules\JkControl\Tests\Feature;

use App\Models\EmployeeActivity;
use App\Models\Line;
use App\Models\User;
use App\Models\Worker;
use App\Models\Workstation;
use App\Support\TimezoneRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\JkControl\Models\OperatorSession;
use Modules\JkControl\Providers\JkControlServiceProvider;
use Tests\Support\ModuleTestCase;

class OperatorEventApiTest extends ModuleTestCase
{
    protected string $module = 'JkControl';

    protected string $provider = JkControlServiceProvider::class;

    protected string $probeTable = 'jk_operator_sessions';

    private User $connector;

    private Line $line;

    private Workstation $press;

    private Workstation $lathe;

    private Worker $ana;

    private Worker $bruno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->connector = User::factory()->create();
        $this->connector->assignRole('Supervisor');

        $this->line = Line::factory()->create();
        $this->press = Workstation::factory()->create(['line_id' => $this->line->id, 'code' => 'INJ-01', 'name' => 'Injetora 01']);
        $this->lathe = Workstation::factory()->create(['line_id' => $this->line->id, 'code' => 'TOR-01', 'name' => 'Torno 01']);

        $this->ana = Worker::factory()->create(['custom_fields' => ['rfid_card' => '04:A1:B2:C3']]);
        $this->bruno = Worker::factory()->create(['custom_fields' => ['rfid_card' => '0499AABB']]);
    }

    private function event(array $payload)
    {
        return $this->actingAs($this->connector, 'sanctum')->postJson('/api/v1/jk/operator-events', $payload);
    }

    public function test_login_opens_a_session(): void
    {
        $this->event(['card_uid' => '04:A1:B2:C3', 'action' => 'login', 'workstation_id' => $this->press->id])
            ->assertCreated()
            ->assertJsonPath('status', 'logged_in')
            ->assertJsonPath('data.worker.id', $this->ana->id)
            ->assertJsonPath('data.session.workstation_id', $this->press->id)
            ->assertJsonPath('data.session.ended_at', null);

        $this->assertSame(1, OperatorSession::open()->where('worker_id', $this->ana->id)->count());
    }

    public function test_card_matches_whatever_format_the_reader_prints(): void
    {
        $this->event(['card_uid' => '04a1b2c3', 'action' => 'login', 'workstation_id' => $this->press->id])
            ->assertCreated()
            ->assertJsonPath('data.worker.id', $this->ana->id);

        $this->event(['card_uid' => '04 99 aa bb', 'action' => 'login', 'workstation_id' => $this->lathe->id])
            ->assertCreated()
            ->assertJsonPath('data.worker.id', $this->bruno->id);
    }

    public function test_card_can_be_the_worker_code_instead(): void
    {
        config(['jkcontrol.card_source' => 'code']);
        $carla = Worker::factory()->create(['code' => 'E5F6A7B8']);

        $this->event(['card_uid' => 'e5:f6:a7:b8', 'action' => 'login', 'workstation_id' => $this->press->id])
            ->assertCreated()
            ->assertJsonPath('data.worker.id', $carla->id);
    }

    public function test_repeated_login_is_idempotent(): void
    {
        $payload = ['card_uid' => '04A1B2C3', 'action' => 'login', 'workstation_id' => $this->press->id];

        $this->event($payload)->assertCreated();
        $this->event($payload)->assertOk()->assertJsonPath('status', 'already_logged_in');

        $this->assertSame(1, OperatorSession::where('worker_id', $this->ana->id)->count());
    }

    public function test_logout_closes_the_session_into_an_employee_activity(): void
    {
        $this->event(['card_uid' => '04A1B2C3', 'action' => 'login', 'workstation_id' => $this->press->id, 'occurred_at' => '2026-10-19 08:00:00']);

        $this->event(['card_uid' => '04A1B2C3', 'action' => 'logout', 'workstation_id' => $this->press->id, 'occurred_at' => '2026-10-19 10:30:00'])
            ->assertOk()
            ->assertJsonPath('status', 'logged_out');

        $session = OperatorSession::where('worker_id', $this->ana->id)->sole();
        $activity = EmployeeActivity::findOrFail($session->employee_activity_id);

        $this->assertSame('work', $activity->type);
        $this->assertSame($this->line->id, $activity->line_id);
        $this->assertSame('Injetora 01', $activity->label);
        $this->assertSame('2026-10-19 08:00:00', $activity->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-19 10:30:00', $activity->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->connector->id, $activity->created_by_id);
    }

    public function test_logout_with_nothing_open_is_a_no_op(): void
    {
        $this->event(['card_uid' => '04A1B2C3', 'action' => 'logout', 'workstation_id' => $this->press->id])
            ->assertOk()
            ->assertJsonPath('status', 'no_open_session')
            ->assertJsonPath('data.session', null);

        $this->assertSame(0, EmployeeActivity::count());
    }

    public function test_login_at_another_machine_closes_the_previous_session(): void
    {
        $this->event(['card_uid' => '04A1B2C3', 'action' => 'login', 'workstation_id' => $this->press->id, 'occurred_at' => '2026-10-19 08:00:00']);
        $this->event(['card_uid' => '04A1B2C3', 'action' => 'login', 'workstation_id' => $this->lathe->id, 'occurred_at' => '2026-10-19 09:00:00'])
            ->assertCreated();

        $sessions = OperatorSession::where('worker_id', $this->ana->id)->orderBy('started_at')->get();

        $this->assertCount(2, $sessions);
        $this->assertSame('2026-10-19 09:00:00', $sessions[0]->ended_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($sessions[0]->employee_activity_id);
        $this->assertNull($sessions[1]->ended_at);
    }

    public function test_login_takes_over_the_machine_from_the_previous_operator(): void
    {
        $this->event(['card_uid' => '04A1B2C3', 'action' => 'login', 'workstation_id' => $this->press->id, 'occurred_at' => '2026-10-19 06:00:00']);
        $this->event(['card_uid' => '0499AABB', 'action' => 'login', 'workstation_id' => $this->press->id, 'occurred_at' => '2026-10-19 14:00:00']);

        $this->assertEquals([$this->bruno->id], OperatorSession::open()->where('workstation_id', $this->press->id)->pluck('worker_id')->all());
        $this->assertSame(1, EmployeeActivity::where('worker_id', $this->ana->id)->count());
    }

    public function test_crew_machines_keep_every_operator_when_not_exclusive(): void
    {
        config(['jkcontrol.exclusive_workstation' => false]);

        $this->event(['card_uid' => '04A1B2C3', 'action' => 'login', 'workstation_id' => $this->press->id]);
        $this->event(['card_uid' => '0499AABB', 'action' => 'login', 'workstation_id' => $this->press->id]);

        $this->assertSame(2, OperatorSession::open()->where('workstation_id', $this->press->id)->count());
    }

    public function test_a_session_without_duration_closes_without_an_activity(): void
    {
        $this->event(['card_uid' => '04A1B2C3', 'action' => 'login', 'workstation_id' => $this->press->id, 'occurred_at' => '2026-10-19 08:00:00']);
        // Device clock went backwards between the two reads.
        $this->event(['card_uid' => '04A1B2C3', 'action' => 'logout', 'workstation_id' => $this->press->id, 'occurred_at' => '2026-10-19 07:59:00'])
            ->assertOk()
            ->assertJsonPath('status', 'logged_out');

        $session = OperatorSession::where('worker_id', $this->ana->id)->sole();
        $this->assertNotNull($session->ended_at);
        $this->assertNull($session->employee_activity_id);
        $this->assertSame(0, EmployeeActivity::count());
    }

    public function test_device_timestamps_are_stored_in_the_plant_timezone(): void
    {
        $previousDefault = date_default_timezone_get();
        $previousConfig = config('app.timezone');

        // The plant timezone lives in Settings (system_settings), and the
        // ApplyPlantTimezone middleware applies it on every request.
        TimezoneRegistry::save('America/Sao_Paulo');

        try {
            $this->event(['card_uid' => '04A1B2C3', 'action' => 'login', 'workstation_id' => $this->press->id, 'occurred_at' => '2026-10-19T11:00:00Z'])
                ->assertCreated();

            $this->assertSame('2026-10-19 08:00:00', (string) DB::table('jk_operator_sessions')->value('started_at'));
        } finally {
            // The registry caches per process; leave nothing behind for the next test.
            TimezoneRegistry::flush();
            config(['app.timezone' => $previousConfig]);
            date_default_timezone_set($previousDefault);
        }
    }

    public function test_unknown_card_is_rejected(): void
    {
        $this->event(['card_uid' => 'DEADBEEF', 'action' => 'login', 'workstation_id' => $this->press->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('card_uid');
    }

    public function test_inactive_worker_card_is_rejected(): void
    {
        $this->ana->update(['is_active' => false]);

        $this->event(['card_uid' => '04A1B2C3', 'action' => 'login', 'workstation_id' => $this->press->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('card_uid');
    }

    public function test_payload_is_validated(): void
    {
        $this->event([])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['card_uid', 'action', 'workstation_id']);

        $this->event(['card_uid' => '04A1B2C3', 'action' => 'enter', 'workstation_id' => 999999, 'occurred_at' => 'yesterday-ish'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['action', 'workstation_id', 'occurred_at']);
    }

    public function test_deleted_workstation_is_rejected(): void
    {
        $this->press->delete();

        $this->event(['card_uid' => '04A1B2C3', 'action' => 'login', 'workstation_id' => $this->press->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('workstation_id');
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->postJson('/api/v1/jk/operator-events', ['card_uid' => '04A1B2C3', 'action' => 'login', 'workstation_id' => $this->press->id])
            ->assertUnauthorized();
    }

    public function test_operator_role_is_forbidden(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('Operator');

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/jk/operator-events', ['card_uid' => '04A1B2C3', 'action' => 'login', 'workstation_id' => $this->press->id])
            ->assertForbidden();
    }

    public function test_event_without_timestamp_uses_the_server_clock(): void
    {
        Carbon::setTestNow('2026-10-19 12:00:00');

        try {
            $this->event(['card_uid' => '04A1B2C3', 'action' => 'login', 'workstation_id' => $this->press->id])
                ->assertCreated()
                ->assertJsonPath('data.session.started_at', Carbon::parse('2026-10-19 12:00:00')->toIso8601String());
        } finally {
            Carbon::setTestNow();
        }
    }
}
