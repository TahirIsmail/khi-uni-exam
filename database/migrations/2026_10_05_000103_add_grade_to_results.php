<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The grade a result was awarded, kept beside the percentage it came from. Like everything else on
 * exm_results this is recomputed rather than frozen — the row is a cache of what the marks say, and
 * App\Domain\Results\Actions\CompileResult writes it whenever it runs.
 *
 * grade_point is null for annual programmes, which have grades but no grade points.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exm_results', function (Blueprint $table): void {
            $table->string('grade', 5)->nullable()->after('percentage');
            $table->decimal('grade_point', 3, 2)->nullable()->after('grade');
            $table->string('grade_remark', 40)->nullable()->after('grade_point');
        });
    }

    public function down(): void
    {
        Schema::table('exm_results', function (Blueprint $table): void {
            $table->dropColumn(['grade', 'grade_point', 'grade_remark']);
        });
    }
};
