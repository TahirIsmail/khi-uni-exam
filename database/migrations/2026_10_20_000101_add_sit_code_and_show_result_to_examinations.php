<?php

use App\Domain\Exam\Models\Examination;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Candidates open an examination at /sit/{code}: a short random code, not the examination's number,
 * so nobody reaches another examination by changing a digit. And an examination may show each
 * candidate their score, and whether they passed, the moment they submit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exm_examinations', function (Blueprint $table) {
            $table->char('sit_code', 8)->nullable()->after('public_ref')->comment('/sit/{code}: random, not guessable from the id');
            $table->boolean('show_result')->default(false)->after('shared_pin')->comment('candidates see their score and pass/fail on submitting');
        });

        foreach (DB::table('exm_examinations')->whereNull('sit_code')->pluck('id') as $id) {
            DB::table('exm_examinations')->where('id', $id)->update(['sit_code' => Examination::newSitCode()]);
        }

        Schema::table('exm_examinations', function (Blueprint $table) {
            $table->unique('sit_code');
        });
    }

    public function down(): void
    {
        Schema::table('exm_examinations', function (Blueprint $table) {
            $table->dropUnique(['sit_code']);
            $table->dropColumn(['sit_code', 'show_result']);
        });
    }
};
