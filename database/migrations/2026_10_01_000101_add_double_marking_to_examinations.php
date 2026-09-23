<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Whether an examination's manually-marked items need two independent examiners (exam phase,
 * step 20), or just one. Not part of exm_examinations' frozen column set (migration
 * 2026_09_26_000102): it is an operational marking choice, not part of the blueprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exm_examinations', function (Blueprint $table) {
            $table->boolean('require_double_marking')->default(false)->after('negative_fraction');
        });
    }

    public function down(): void
    {
        Schema::table('exm_examinations', function (Blueprint $table) {
            $table->dropColumn('require_double_marking');
        });
    }
};
