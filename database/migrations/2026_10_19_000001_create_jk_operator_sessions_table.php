<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is logged in at which machine, from RFID card reads.
 *
 * employee_activities needs both ends of an interval (ends_at is NOT NULL), so
 * an open session lives here and becomes an employee activity when it closes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jk_operator_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workstation_id')->constrained()->cascadeOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->foreignId('employee_activity_id')->nullable()->constrained('employee_activities')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index(['worker_id', 'ended_at']);
            $table->index(['workstation_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jk_operator_sessions');
    }
};
