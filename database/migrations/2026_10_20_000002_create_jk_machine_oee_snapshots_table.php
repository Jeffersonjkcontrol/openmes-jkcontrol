<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Machine OEE sums of periods that are over (a day, a shift), so a month-long
 * report does not measure every day again. Rows are dropped when something
 * they depend on changes: ideal cycles, shifts, stop causes, scrap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jk_machine_oee_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workstation_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->json('data');
            $table->timestamps();

            $table->unique(['workstation_id', 'kind', 'period_start', 'period_end'], 'jk_oee_snapshots_period_unique');
            $table->index(['period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jk_machine_oee_snapshots');
    }
};
