<?php

namespace Modules\JkControl;

use App\Enums\CustomFieldType;
use App\Models\CustomFieldDefinition;

/**
 * Run by OpenMES when the module is enabled (ModuleManager::runInstaller).
 *
 * Creates the worker custom field that holds the RFID card UID, so it shows up
 * on the worker form. Left in place on uninstall: the card numbers typed into
 * it are the factory's data, not the module's.
 */
class Installer
{
    public function install(): void
    {
        if (config('jkcontrol.card_source', 'custom_field') !== 'custom_field') {
            return;
        }

        $key = config('jkcontrol.card_custom_field', 'rfid_card');

        $exists = CustomFieldDefinition::withTrashed()
            ->where('entity_type', 'worker')
            ->where('key', $key)
            ->exists();

        if ($exists) {
            return;
        }

        CustomFieldDefinition::create([
            'entity_type' => 'worker',
            'key' => $key,
            'label' => 'RFID card',
            'type' => CustomFieldType::Text->value,
            'required' => false,
            'position' => ((int) CustomFieldDefinition::where('entity_type', 'worker')->max('position')) + 1,
            'is_active' => true,
        ]);
    }

    public function uninstall(): void
    {
        // Intentionally empty — see class comment.
    }
}
