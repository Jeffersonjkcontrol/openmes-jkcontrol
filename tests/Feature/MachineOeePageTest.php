<?php

namespace Modules\JkControl\Tests\Feature;

use App\Import\ImportRegistry;
use App\Models\Line;
use App\Models\MachineConnection;
use App\Models\MachineTag;
use App\Models\ProductType;
use App\Models\User;
use App\Models\Workstation;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\JkControl\Models\IdealCycle;
use Modules\JkControl\Providers\JkControlServiceProvider;
use Tests\Support\ModuleTestCase;

class MachineOeePageTest extends ModuleTestCase
{
    protected string $module = 'JkControl';

    protected string $provider = JkControlServiceProvider::class;

    protected string $probeTable = 'jk_ideal_cycles';

    private User $supervisor;

    private Workstation $press;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->supervisor = User::factory()->create();
        $this->supervisor->assignRole('Supervisor');

        $line = Line::factory()->create();
        $this->press = Workstation::factory()->create(['line_id' => $line->id, 'code' => 'NIT-07']);
        Workstation::factory()->create(['line_id' => $line->id, 'code' => 'BENCH-1']); // no machine tags

        $connection = MachineConnection::create(['name' => 'inj07', 'protocol' => MachineConnection::PROTOCOL_REST, 'line_id' => $line->id, 'is_active' => true]);
        MachineTag::create([
            'machine_connection_id' => $connection->id, 'workstation_id' => $this->press->id, 'address' => 'inj07/parada',
            'name' => 'NIT-07 parada', 'signal_type' => MachineTag::SIGNAL_STATE, 'data_type' => 'int32',
            'transform' => ['value_map' => ['0' => 'RUNNING', '1' => 'STOPPED']], 'is_active' => true,
        ]);
        ProductType::factory()->create(['code' => '89-0001']);
    }

    public function test_the_page_shows_one_card_per_machine_that_reports(): void
    {
        $this->actingAs($this->supervisor)
            ->get('/jkcontrol/oee?period=today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('jkcontrol/MachineOee', false)
                ->has('machines', 1)
                ->where('machines.0.workstation.code', 'NIT-07')
                ->where('filters.period', 'today')
                ->has('workstations', 1)
                ->has('products', 1));
    }

    public function test_machines_of_a_line_switched_off_are_not_shown(): void
    {
        $this->press->line->update(['is_active' => false]);

        $this->actingAs($this->supervisor)
            ->get('/jkcontrol/oee?period=today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('machines', 0)->has('lines', 0));
    }

    public function test_an_unknown_period_falls_back_to_the_current_shift(): void
    {
        $this->actingAs($this->supervisor)
            ->get('/jkcontrol/oee?period=forever')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.period', 'shift'));
    }

    public function test_an_ideal_cycle_typed_in_is_saved_updated_and_removed(): void
    {
        $this->actingAs($this->supervisor)
            ->post('/jkcontrol/ideal-cycles', ['workstation_id' => $this->press->id, 'cycle_seconds' => '24.96'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->actingAs($this->supervisor)
            ->post('/jkcontrol/ideal-cycles', ['workstation_id' => $this->press->id, 'cycle_seconds' => 25]);

        $row = IdealCycle::sole();
        $this->assertSame('25.000', $row->cycle_seconds);
        $this->assertSame('manual', $row->source);
        $this->assertNull($row->product_type_id);

        $this->actingAs($this->supervisor)->delete("/jkcontrol/ideal-cycles/{$row->id}")->assertRedirect();
        $this->assertSame(0, IdealCycle::count());
    }

    public function test_an_ideal_cycle_needs_a_product_or_a_machine_and_seconds(): void
    {
        $this->actingAs($this->supervisor)
            ->post('/jkcontrol/ideal-cycles', ['cycle_seconds' => 0])
            ->assertSessionHasErrors(['product_type_id', 'workstation_id', 'cycle_seconds']);
    }

    public function test_the_spreadsheet_import_knows_ideal_cycles(): void
    {
        $importer = app(ImportRegistry::class)->all()['jk_ideal_cycles'] ?? null;
        $this->assertNotNull($importer, 'registered through the import.entities filter');

        $result = $importer->import([
            ['product_code' => '89-0001', 'workstation_code' => '', 'cycle_seconds' => '24,96'],
            ['product_code' => '89-0001', 'workstation_code' => 'NIT-07', 'cycle_seconds' => '26.5'],
            ['product_code' => 'NOPE', 'workstation_code' => '', 'cycle_seconds' => '20'],
            ['product_code' => '', 'workstation_code' => 'NIT-07', 'cycle_seconds' => 'fast'],
        ], []);

        $this->assertSame(2, $result['imported']);
        $this->assertSame([3, 4], array_column($result['errors'], 'row'));
        $this->assertSame('24.960', IdealCycle::whereNull('workstation_id')->sole()->cycle_seconds);
        $this->assertSame('spreadsheet', IdealCycle::where('workstation_id', $this->press->id)->sole()->source);
    }

    public function test_the_erp_sends_ideal_cycles_through_the_api(): void
    {
        $rows = [['product_code' => '89-0001', 'cycle_seconds' => 24.96]];

        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson('/api/v1/jk/ideal-cycles', ['rows' => $rows])
            ->assertOk()
            ->assertJsonPath('data.imported', 1);
        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson('/api/v1/jk/ideal-cycles', ['rows' => [...$rows, ['workstation_code' => 'NOPE', 'cycle_seconds' => 10]]])
            ->assertStatus(207)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.errors.0.row', 2);

        $this->assertSame('erp', IdealCycle::sole()->source);
    }

    public function test_requires_a_supervisor_or_admin(): void
    {
        $this->get('/jkcontrol/oee')->assertRedirect();

        $operator = User::factory()->create();
        $operator->assignRole('Operator');
        $this->actingAs($operator)->get('/jkcontrol/oee')->assertForbidden();
    }
}
