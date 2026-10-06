<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * An entry test given to a whole batch at once: candidates may start from starts_at until closes_at
 * (and nobody's time runs past it), and every candidate signs in with the same exam PIN instead of a
 * PIN of their own handed out at check-in. Both are optional: without them an examination works as
 * before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exm_examinations', function (Blueprint $table) {
            $table->dateTime('closes_at')->nullable()->after('starts_at')->comment('no candidate starts, or writes, after this');
            $table->text('shared_pin')->nullable()->after('closes_at')->comment('encrypted; one PIN for every candidate, no check-in needed');
        });
    }

    public function down(): void
    {
        Schema::table('exm_examinations', function (Blueprint $table) {
            $table->dropColumn(['closes_at', 'shared_pin']);
        });
    }
};
