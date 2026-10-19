<?php

return [

    /*
    | Where a worker's RFID card UID is stored.
    |   custom_field — the worker custom field named below (created by the module installer)
    |   code         — the worker's own code
    */
    'card_source' => env('JKCONTROL_CARD_SOURCE', 'custom_field'),

    'card_custom_field' => env('JKCONTROL_CARD_CUSTOM_FIELD', 'rfid_card'),

    /*
    | One operator per machine: a login at a workstation ends any other open
    | session there. Turn off for machines run by a crew.
    */
    'exclusive_workstation' => (bool) env('JKCONTROL_EXCLUSIVE_WORKSTATION', true),

    /*
    | A cause entered shortly after the machine restarted still belongs to the
    | stop that just ended (operators often key it in after restarting).
    */
    'reason_grace_minutes' => (int) env('JKCONTROL_REASON_GRACE_MINUTES', 30),

    /*
    | Employee activity type written when an operator session closes.
    | Must be one of App\Models\EmployeeActivity::TYPES.
    */
    'activity_type' => 'work',

];
