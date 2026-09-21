<?php

namespace App\Http\Requests\Exam;

use App\Domain\Exam\ExaminationInput;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The shape of what the examination form sends. Whether the course is in this campus, the
 * examination fits the programme and the user may set examinations there is checked by the action.
 */
class SaveExaminationRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:200'],
            'course_id' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'exam_type_id' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'intake_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            // Entered as a date and time in the examination time zone, e.g. 2026-10-05T09:00.
            'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'duration_minutes' => ['required', 'integer', 'min:'.(int) config('exam.duration_minutes.min'), 'max:'.(int) config('exam.duration_minutes.max')],
            'total_marks' => ['required', 'numeric', 'gt:0', 'max:'.(float) config('exam.total_marks.max')],
            'pass_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'negative_marking' => ['boolean'],
            'negative_fraction' => ['nullable', 'numeric', 'gt:0', 'max:1'],
            'instructions' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'duration_minutes.min' => 'An examination lasts at least :min minutes.',
            'duration_minutes.max' => 'An examination lasts at most :max minutes.',
            'total_marks.gt' => 'The total marks have to be more than 0.',
            'negative_fraction.gt' => 'Say how much of the marks a wrong answer loses (for example 0.25), or leave it empty to use each question\'s own negative marks.',
            'negative_fraction.max' => 'A wrong answer cannot lose more than all of the marks (1).',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $start = $this->input('starts_at');
            if (is_string($start) && $start !== '' && ! $validator->errors()->has('starts_at') && $this->startsAt() === null) {
                $validator->errors()->add('starts_at', 'That is not a date and time.');
            }
        });
    }

    public function examinationInput(): ExaminationInput
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();
        $instructions = isset($data['instructions']) ? trim((string) $data['instructions']) : '';

        return new ExaminationInput(
            title: isset($data['title']) ? trim((string) $data['title']) : null,
            courseId: (int) $data['course_id'],
            examTypeId: (int) $data['exam_type_id'],
            intakeId: isset($data['intake_id']) ? (int) $data['intake_id'] : null,
            startsAt: $this->startsAt(),
            durationMinutes: (int) $data['duration_minutes'],
            totalMarks: round((float) $data['total_marks'], 2),
            passPercentage: round((float) $data['pass_percentage'], 2),
            negativeMarking: (bool) ($data['negative_marking'] ?? false),
            negativeFraction: isset($data['negative_fraction']) ? round((float) $data['negative_fraction'], 3) : null,
            instructions: $instructions === '' ? null : $instructions,
        );
    }

    /** The start as entered in the examination time zone, turned into UTC for storing. */
    private function startsAt(): ?CarbonImmutable
    {
        $value = $this->input('starts_at');
        if (! is_string($value) || $value === '') {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $value, (string) config('exam.timezone'));

        return $parsed?->utc();
    }
}
