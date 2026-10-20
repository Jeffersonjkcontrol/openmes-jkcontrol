<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ideal machine cycle (seconds per cycle, e.g. one injection) for machine OEE.
 *
 * The core keeps an ideal cycle per piece on the process template, but has no
 * screen, import or API to fill it, and an injection mold runs at a different
 * pace on each press. Rows here are per product, per machine, or per product on
 * one machine; the most specific one wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jk_ideal_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_type_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('workstation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('cycle_seconds', 10, 3);
            $table->string('source', 20)->default('manual');
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_type_id', 'workstation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jk_ideal_cycles');
    }
};
