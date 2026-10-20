<?php

use Illuminate\Support\Facades\Route;
use Modules\JkControl\Http\Controllers\IdealCycleImportController;
use Modules\JkControl\Http\Controllers\OperatorEventController;
use Modules\JkControl\Http\Controllers\WorkstationDowntimeReasonController;
use Modules\JkControl\Http\Controllers\WorkstationQualityCheckController;
use Modules\JkControl\Http\Controllers\WorkstationWorkOrderController;

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

        Route::post('/workstations/{workstation}/work-order', [WorkstationWorkOrderController::class, 'store'])
            ->whereNumber('workstation')
            ->name('jkcontrol.workstations.work-order');

        Route::post('/workstations/{workstation}/quality-check', [WorkstationQualityCheckController::class, 'store'])
            ->whereNumber('workstation')
            ->name('jkcontrol.workstations.quality-check');

        // Ideal cycles for machine OEE, sent by the ERP (same rows as the spreadsheet import).
        Route::post('/ideal-cycles', [IdealCycleImportController::class, 'store'])
            ->name('jkcontrol.ideal-cycles.import');
    });
