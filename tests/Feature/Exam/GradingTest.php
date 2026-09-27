<?php

use App\Domain\Results\Support\GradeScales;
use Illuminate\Support\Facades\DB;

/**
 * How a percentage becomes a grade. Two scales, because KMU runs two kinds of programme: an annual
 * MBBS or BDS result is marks and a distinction, a semester DPT result carries the grade point its
 * GPA is worked out from.
 */
beforeEach(function () {
    $this->scales = app(GradeScales::class);
    $this->scales->forget();
});

test('the semester scale awards HEC grade points, and D at 50 is its lowest pass', function () {
    expect($this->scales->award('semester', 100.0))->toMatchArray(['grade' => 'A', 'point' => 4.00])
        ->and($this->scales->award('semester', 85.0))->toMatchArray(['grade' => 'A', 'point' => 4.00])
        ->and($this->scales->award('semester', 84.99))->toMatchArray(['grade' => 'A-', 'point' => 3.70])
        ->and($this->scales->award('semester', 71.0))->toMatchArray(['grade' => 'B', 'point' => 3.00])
        ->and($this->scales->award('semester', 50.0))->toMatchArray(['grade' => 'D', 'point' => 1.00])
        ->and($this->scales->award('semester', 49.99))->toMatchArray(['grade' => 'F', 'point' => 0.00]);
});

test('the annual scale awards a distinction and a fail, and never a grade point', function () {
    expect($this->scales->award('annual', 90.0))->toMatchArray(['grade' => 'A', 'remark' => 'Distinction'])
        ->and($this->scales->award('annual', 85.0))->toMatchArray(['grade' => 'A', 'remark' => 'Distinction'])
        ->and($this->scales->award('annual', 84.99)['remark'])->toBe('Pass')
        ->and($this->scales->award('annual', 50.0)['remark'])->toBe('Pass')
        ->and($this->scales->award('annual', 49.99))->toMatchArray(['grade' => 'F', 'remark' => 'Fail']);

    foreach ([90.0, 70.0, 50.0, 10.0] as $percentage) {
        expect($this->scales->award('annual', $percentage)['point'])->toBeNull();
    }
});

test('every band of a scale is reachable, so no grade is unawardable', function () {
    foreach (['annual', 'semester'] as $calendarType) {
        $bands = DB::table('exm_grade_scales')->where('calendar_type', $calendarType)->pluck('min_percentage');

        $awarded = $bands->map(fn (mixed $min): string => $this->scales->award($calendarType, (float) $min)['grade'])->unique();

        expect($awarded)->toHaveCount($bands->count());
    }
});

test('a scale nobody has set up awards nothing rather than guessing', function () {
    DB::table('exm_grade_scales')->where('calendar_type', 'annual')->delete();
    $this->scales->forget();

    expect($this->scales->isEmpty('annual'))->toBeTrue()
        ->and($this->scales->award('annual', 75.0))->toBeNull()
        // The other scale is untouched: they are set up separately.
        ->and($this->scales->isEmpty('semester'))->toBeFalse();
});
