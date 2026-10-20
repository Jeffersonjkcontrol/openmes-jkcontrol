<?php

use Illuminate\Support\Facades\Route;
use Modules\JkControl\Http\Controllers\IdealCycleController;
use Modules\JkControl\Http\Controllers\MachineOeeController;
use Modules\JkControl\Http\Controllers\MachineOeeReportController;

/*
| Screens of the module: machine OEE (live and report) and the ideal cycles it
| is measured against.
*/
Route::prefix('jkcontrol')
    ->name('jkcontrol.')
    ->middleware(['web', 'auth', 'role:Admin|Supervisor'])
    ->group(function () {
        Route::get('/oee', [MachineOeeController::class, 'index'])->name('oee');

        Route::get('/oee/report', [MachineOeeReportController::class, 'index'])->name('oee.report');
        Route::get('/oee/cycles', [MachineOeeReportController::class, 'cycles'])->name('oee.cycles');
        Route::post('/oee/recalculate', [MachineOeeReportController::class, 'recalculate'])->name('oee.recalculate');

        Route::post('/ideal-cycles', [IdealCycleController::class, 'store'])->name('ideal-cycles.store');
        Route::delete('/ideal-cycles/{idealCycle}', [IdealCycleController::class, 'destroy'])
            ->whereNumber('idealCycle')
            ->name('ideal-cycles.destroy');
    });
