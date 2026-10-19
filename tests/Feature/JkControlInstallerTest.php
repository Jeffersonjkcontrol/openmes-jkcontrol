<?php

namespace Modules\JkControl\Tests\Feature;

use App\Models\CustomFieldDefinition;
use Modules\JkControl\Installer;
use Modules\JkControl\Providers\JkControlServiceProvider;
use Tests\Support\ModuleTestCase;

class JkControlInstallerTest extends ModuleTestCase
{
    protected string $module = 'JkControl';

    protected string $provider = JkControlServiceProvider::class;

    protected string $probeTable = 'jk_operator_sessions';

    public function test_install_creates_the_card_field_on_workers_once(): void
    {
        app(Installer::class)->install();
        app(Installer::class)->install();

        $fields = CustomFieldDefinition::where('entity_type', 'worker')->where('key', 'rfid_card')->get();

        $this->assertCount(1, $fields);
        $this->assertTrue($fields->first()->is_active);
    }

    public function test_install_respects_a_field_an_admin_deleted(): void
    {
        app(Installer::class)->install();
        CustomFieldDefinition::where('key', 'rfid_card')->first()->delete();

        app(Installer::class)->install();

        $this->assertSame(0, CustomFieldDefinition::where('key', 'rfid_card')->count());
    }

    public function test_install_adds_nothing_when_cards_live_in_the_worker_code(): void
    {
        config(['jkcontrol.card_source' => 'code']);

        app(Installer::class)->install();

        $this->assertSame(0, CustomFieldDefinition::where('entity_type', 'worker')->count());
    }
}
