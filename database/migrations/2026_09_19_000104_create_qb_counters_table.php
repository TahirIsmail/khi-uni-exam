<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Counters for human references such as Q-2026-000123. One row per counter, taken with a row lock,
 * so two authors saving at the same moment never get the same number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qb_counters', function (Blueprint $table) {
            $table->string('name', 40)->primary();
            $table->unsignedBigInteger('value')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qb_counters');
    }
};
