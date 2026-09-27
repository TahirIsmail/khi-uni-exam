<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * How a percentage becomes a grade, which is not one answer but two, because KMU runs two kinds of
 * programme:
 *
 * - annual (MBBS, BDS) is marked out of marks the way PMC asks: 50% passes, and a high mark is a
 *   distinction. There are no grade points, because an annual professional result is not a GPA;
 * - semester (DPT) follows HEC's 4.00 scale, where each grade carries the grade point a GPA is
 *   worked out from.
 *
 * A table rather than a config value: a grading scale is the registrar's, it differs per calendar
 * type, and it is read to print a result. The rows seeded here are the standard scales; the
 * university edits them directly, as there is no screen for them in this phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exm_grade_scales', function (Blueprint $table): void {
            $table->id();
            $table->enum('calendar_type', ['annual', 'semester']);
            $table->decimal('min_percentage', 5, 2);
            $table->string('grade', 5);
            $table->decimal('grade_point', 3, 2)->nullable()->comment('Semester scales only; an annual grade has none');
            $table->string('remark', 40);
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();

            $table->unique(['calendar_type', 'min_percentage']);
            $table->index(['calendar_type', 'sort_order']);
        });

        $now = now();
        $rows = [];
        $sort = 0;

        // HEC's 4.00 scale, as KMU's semester programmes use it: D at 50% is the lowest pass.
        foreach ([
            ['A', 85, 4.00, 'Excellent'],
            ['A-', 80, 3.70, 'Excellent'],
            ['B+', 75, 3.30, 'Very good'],
            ['B', 71, 3.00, 'Good'],
            ['B-', 68, 2.70, 'Good'],
            ['C+', 64, 2.30, 'Satisfactory'],
            ['C', 61, 2.00, 'Satisfactory'],
            ['C-', 58, 1.70, 'Pass'],
            ['D+', 54, 1.30, 'Pass'],
            ['D', 50, 1.00, 'Pass'],
            ['F', 0, 0.00, 'Fail'],
        ] as [$grade, $min, $point, $remark]) {
            $rows[] = [
                'calendar_type' => 'semester', 'min_percentage' => $min, 'grade' => $grade,
                'grade_point' => $point, 'remark' => $remark, 'sort_order' => ++$sort,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        $sort = 0;
        foreach ([
            ['A', 85, 'Distinction'],
            ['B', 70, 'Pass'],
            ['C', 60, 'Pass'],
            ['D', 50, 'Pass'],
            ['F', 0, 'Fail'],
        ] as [$grade, $min, $remark]) {
            $rows[] = [
                'calendar_type' => 'annual', 'min_percentage' => $min, 'grade' => $grade,
                'grade_point' => null, 'remark' => $remark, 'sort_order' => ++$sort,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        DB::table('exm_grade_scales')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('exm_grade_scales');
    }
};
