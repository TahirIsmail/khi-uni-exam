<?php

namespace App\Http\Requests\QuestionBank;

use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Validation\QuestionContent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The shape of what the editor sends. Sizes and types are checked here; the rules of the question
 * type (how many options, what must be marked correct, ...) are checked by QuestionValidator.
 */
class SaveQuestionRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'question_type_id' => ['required', 'integer', Rule::exists('qb_question_types', 'id')->where('is_active', true)],
            'course_id' => ['required', 'integer', 'min:1', 'max:4294967295'],
            // Empty: the course as a whole (BDS, DPT). An MBBS question names a subject (CmsAcademic::placeOf).
            'node_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'vignette' => ['nullable', 'string', 'max:20000'],
            'stem' => ['required', 'string', 'max:20000'],
            'lead_in' => ['nullable', 'string', 'max:500'],
            'explanation' => ['nullable', 'string', 'max:20000'],
            'settings' => ['nullable', 'array'],
            'marks' => ['required', 'numeric', 'min:0', 'max:9999'],
            'negative_marks' => ['required', 'numeric', 'min:0', 'max:9999'],
            'discipline_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'exam_type_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            // The Academic Session; when it is not sent, the one the user is working in.
            'intake_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'cognitive_level_id' => ['nullable', 'integer', Rule::exists('qb_cognitive_levels', 'id')],
            'difficulty_level_id' => ['nullable', 'integer', Rule::exists('qb_difficulty_levels', 'id')],

            'options' => ['array', 'max:30'],
            'options.*.label' => ['required', 'string', 'max:4'],
            'options.*.body' => ['required', 'string', 'max:5000'],
            'options.*.is_correct' => ['boolean'],
            'options.*.weight' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'options.*.feedback' => ['nullable', 'string', 'max:500'],
            'options.*.sort_order' => ['required', 'integer', 'min:1', 'max:100'],
            'options.*.is_position_locked' => ['boolean'],
            'options.*.item_index' => ['nullable', 'integer', 'min:0', 'max:29'],

            'items' => ['array', 'max:30'],
            'items.*.body' => ['required', 'string', 'max:5000'],
            'items.*.is_true' => ['nullable', 'boolean'],
            'items.*.correct_option_label' => ['nullable', 'string', 'max:4'],
            'items.*.marks_fraction' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'items.*.feedback' => ['nullable', 'string', 'max:500'],
            'items.*.sort_order' => ['required', 'integer', 'min:1', 'max:100'],
            'items.*.settings' => ['nullable', 'array'],

            'answers' => ['array', 'max:50'],
            'answers.*.item_index' => ['nullable', 'integer', 'min:0', 'max:29'],
            'answers.*.match_mode' => ['required', Rule::in(['exact', 'contains', 'regex', 'numeric'])],
            'answers.*.answer_text' => ['nullable', 'string', 'max:255'],
            'answers.*.case_sensitive' => ['boolean'],
            'answers.*.numeric_value' => ['nullable', 'numeric'],
            'answers.*.tolerance' => ['nullable', 'numeric', 'min:0'],
            'answers.*.tolerance_type' => ['required', Rule::in(['absolute', 'relative'])],
            'answers.*.unit' => ['nullable', 'string', 'max:30'],
            'answers.*.marks_fraction' => ['required', 'numeric', 'min:0', 'max:1'],
            'answers.*.feedback' => ['nullable', 'string', 'max:500'],
            'answers.*.sort_order' => ['required', 'integer', 'min:1', 'max:100'],

            'rubric' => ['array', 'max:20'],
            'rubric.*.criterion' => ['required', 'string', 'max:255'],
            'rubric.*.max_marks' => ['required', 'numeric', 'min:0', 'max:9999'],
            'rubric.*.guidance' => ['nullable', 'string', 'max:2000'],
            'rubric.*.sort_order' => ['required', 'integer', 'min:1', 'max:100'],

            'references' => ['array', 'max:10'],
            'references.*.kind' => ['required', Rule::in(['book', 'journal', 'guideline', 'url', 'other'])],
            'references.*.citation' => ['required', 'string', 'max:500'],
            'references.*.locator' => ['nullable', 'string', 'max:100'],
            'references.*.url' => ['nullable', 'string', 'max:500', 'url'],
            'references.*.sort_order' => ['required', 'integer', 'min:1', 'max:100'],

            'tag_ids' => ['array', 'max:20'],
            'tag_ids.*' => ['integer', Rule::exists('qb_tags', 'id')],
        ];
    }

    /**
     * References are optional: a reference line the author added but left empty is dropped rather
     * than refused, so it never stops a draft being saved or sent for review.
     */
    protected function prepareForValidation(): void
    {
        $references = $this->input('references');
        if (is_array($references)) {
            $this->merge(['references' => array_values(array_filter(
                $references,
                fn (mixed $reference): bool => is_array($reference) && trim((string) ($reference['citation'] ?? '')) !== '',
            ))]);
        }
    }

    /**
     * Plain wording, because the editor shows these beside the fields while the author types.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'question_type_id.required' => 'Choose the type of question.',
            'course_id.required' => 'Choose the course.',
            'stem.required' => 'Write the question.',
            'marks.required' => 'Enter the marks.',
        ];
    }

    public function content(): QuestionContent
    {
        /** @var QuestionType $type */
        $type = QuestionType::query()->findOrFail($this->validated('question_type_id'));

        return QuestionContent::fromInput($this->validated(), $type);
    }
}
