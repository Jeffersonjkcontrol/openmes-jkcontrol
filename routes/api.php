<?php

use Illuminate\Support\Facades\Route;
use Modules\JkControl\Http\Controllers\OperatorEventController;
use Modules\JkControl\Http\Controllers\WorkstationDowntimeReasonController;

/*
| Machine-to-machine endpoints called by the jkcontrol → OpenMES connector.
| The connector signs in as a dedicated Supervisor account.
*/
Route::prefix('api/v1/jk')
    ->middleware(['api', 'auth:sanctum', 'role:Admin|Supervisor'])
    ->group(function () {
        Route::post('/operator-events', [OperatorEventController::class, 'store'])
            ->name('jkcontrol.operator-events.store');

        Route::post('/workstations/{workstation}/downtime-reason', [WorkstationDowntimeReasonController::class, 'store'])
            ->whereNumber('workstation')
            ->name('jkcontrol.workstations.downtime-reason');
    });
