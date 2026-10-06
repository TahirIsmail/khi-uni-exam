<?php

namespace App\Domain\QuestionBank\Import;

use App\Domain\QuestionBank\Models\CognitiveLevel;
use App\Domain\QuestionBank\Models\DifficultyLevel;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\Tag;
use App\Domain\QuestionBank\Validation\QuestionContent;
use App\Support\Cms\CmsAcademic;
use Illuminate\Support\Str;

/**
 * Turns one line of a spreadsheet into a question, in the shape the editor and the validator use
 * (blueprint 13.2). Everything a person typed is matched loosely — a type, course, topic, level or
 * discipline may be given by its code or by its name — and what cannot be understood is reported
 * as an error against that line instead of being guessed.
 *
 * KMU's format (the downloadable template) names only the examination type, Academic Year, subject,
 * program ("discipline" column, e.g. BDS), question, lead-in, options and answer; the module or
 * course is chosen on the import screen. A subject is matched by name under that module or course:
 * MBBS needs it, while a BDS or DPT question without a matching topic is filed on the course itself.
 *
 * Column shapes:
 *   options    A) first | B) second      (or just: first | second, or one option per line)
 *   correct    A   ·   A,C   ·   true
 *   answers    aspirin | paracetamol     ·   7.35 ± 0.02 mmol/L
 *   items      statement = true | statement = false   ·   prompt -> B   ·   step one | step two
 *   references Harrison, 21st ed p. 1875 | BMJ 2024
 *   tags       ECG, cardiology
 *
 * @phpstan-import-type OptionInput from QuestionContent
 * @phpstan-import-type ItemInput from QuestionContent
 * @phpstan-import-type AnswerInput from QuestionContent
 * @phpstan-import-type ReferenceInput from QuestionContent
 */
final class RowParser
{
    public function __construct(private readonly CmsAcademic $academic) {}

    /**
     * @param  array<string, string>  $row
     * @param  array{course_id?: int|null, node_id?: int|null, type_id?: int|null, exam_type_id?: int|null, intake_id?: int|null}  $defaults
     * @return array{content: QuestionContent|null, errors: array<string, list<string>>, warnings: list<string>}
     */
    public function parse(array $row, int $branchId, array $defaults = []): array
    {
        $errors = [];
        $warnings = [];
        $value = fn (string $key): string => trim($row[$key] ?? '');

        // --- the type: a question with options is a single best answer unless it says otherwise -
        $type = $this->findType($value('type')) ?? (isset($defaults['type_id']) ? QuestionType::query()->find($defaults['type_id']) : null);
        if ($type === null && $value('type') === '' && $value('options') !== '') {
            $type = $this->findType('sba');
        }
        if ($type === null) {
            $errors['type'][] = $value('type') === ''
                ? 'No type of question given, and no default chosen for the file.'
                : 'There is no question type called "'.$value('type').'".';

            return ['content' => null, 'errors' => $errors, 'warnings' => []];
        }

        // --- where it belongs -------------------------------------------------------------
        $courseId = $this->findCourse($value('course'), $branchId) ?? ($defaults['course_id'] ?? null);
        $course = $courseId === null ? null : $this->academic->placeOfCourse((int) $courseId);
        if ($course === null) {
            $errors['course'][] = $value('course') === ''
                ? 'Choose the module or course on the import screen (the file does not name one).'
                : 'There is no active course "'.$value('course').'" in this campus.';
        }
        $modular = $course !== null && $this->academic->isModular($course['programme_id']);

        // KMU's "discipline" column names the program (MBBS, BDS, DPT); elsewhere it is a discipline.
        $disciplineId = null;
        $programGiven = $value('program');
        if ($programGiven === '' && $value('discipline') !== '' && $this->findProgramme($value('discipline'), $branchId) !== null) {
            $programGiven = $value('discipline');
        } elseif ($value('discipline') !== '') {
            $disciplineId = $this->findDiscipline($value('discipline'));
            if ($disciplineId === null) {
                $errors['discipline'][] = 'There is no discipline "'.$value('discipline').'".';
            }
        }
        if ($programGiven !== '' && $course !== null) {
            $programme = $this->findProgramme($programGiven, $branchId);
            if ($programme === null) {
                $errors['program'][] = 'There is no program "'.$programGiven.'" in this campus.';
            } elseif ($programme['id'] !== $course['programme_id']) {
                $errors['program'][] = 'This line is for '.$programme['name'].', but the module or course chosen belongs to another program.';
            }
        }

        // The subject or topic: by name under the course, else the file's default. A question with
        // none is filed on the module or course as a whole; an MBBS subject that is named must exist.
        $nodeId = null;
        if ($course !== null) {
            $placeGiven = $value('topic') !== '' ? $value('topic') : $value('subject');
            $nodeId = $this->findTopic($placeGiven, (int) $courseId) ?? ($placeGiven === '' ? ($defaults['node_id'] ?? null) : null);

            if ($nodeId === null && $modular && $placeGiven !== '') {
                $errors['subject'][] = $course['label'].' has no subject "'.$placeGiven.'". Add it in Program Structure, or check the spelling.';
            } elseif ($nodeId === null && $value('topic') !== '') {
                $errors['topic'][] = 'The course has no topic "'.$value('topic').'" that takes questions.';
            } elseif ($nodeId === null && $placeGiven !== '') {
                $disciplineId ??= $this->findDiscipline($placeGiven);
                $warnings[] = 'No topic "'.$placeGiven.'" in '.$course['label'].': it is filed on the course as a whole.';
            }
        }

        // The Academic Session: "2026" finds the campus's 2026 session; empty means the user's own.
        $intakeId = $defaults['intake_id'] ?? null;
        if ($value('session') !== '') {
            $intakeId = $this->findIntake($value('session'), $branchId);
            if ($intakeId === null) {
                $errors['session'][] = 'There is no Academic Session "'.$value('session').'" in this campus.';
            }
        }

        // Annual, Supplementary, Regular or Retake — by name or code, or the file's default.
        $examTypeId = $defaults['exam_type_id'] ?? null;
        if ($value('exam_type') !== '') {
            $examTypeId = $this->findExamType($value('exam_type'));
            if ($examTypeId === null) {
                $errors['exam_type'][] = 'There is no examination type "'.$value('exam_type').'" (Annual, Supplementary, Regular or Retake).';
            }
        }

        $cognitiveId = null;
        if ($value('cognitive') !== '') {
            $cognitiveId = $this->findLevel(CognitiveLevel::class, $value('cognitive'));
            if ($cognitiveId === null) {
                $errors['cognitive'][] = 'There is no level of thinking "'.$value('cognitive').'".';
            }
        }

        $difficultyId = null;
        if ($value('difficulty') !== '') {
            $difficultyId = $this->findLevel(DifficultyLevel::class, $value('difficulty'));
            if ($difficultyId === null) {
                $errors['difficulty'][] = 'There is no difficulty "'.$value('difficulty').'".';
            }
        }

        // --- marks ------------------------------------------------------------------------
        $marks = $value('marks') === '' ? 1.0 : (float) str_replace(',', '.', $value('marks'));
        $negative = $value('negative_marks') === '' ? 0.0 : (float) str_replace(',', '.', $value('negative_marks'));
        if ($value('marks') !== '' && ! is_numeric(str_replace(',', '.', $value('marks')))) {
            $errors['marks'][] = '"'.$value('marks').'" is not a number.';
        }

        // --- options, parts and answers ---------------------------------------------------
        $options = $this->options($value('options'), $value('correct'), $type, $errors);
        $items = $this->items($value('items'), $type, $options, $errors);
        $answers = $this->answers($value('answers'), $type, $items, $errors);

        if ($errors !== []) {
            return ['content' => null, 'errors' => $errors, 'warnings' => $warnings];
        }

        return [
            'content' => new QuestionContent(
                type: $type,
                courseId: (int) $courseId,
                nodeId: $nodeId === null ? null : (int) $nodeId,
                vignette: $this->html($value('vignette')),
                stem: (string) $this->html($value('stem')),
                leadIn: $value('lead_in') === '' ? null : $value('lead_in'),
                explanation: $this->html($value('explanation')),
                settings: $type->default_settings,
                marks: $marks,
                negativeMarks: $negative,
                disciplineId: $disciplineId,
                cognitiveLevelId: $cognitiveId,
                difficultyLevelId: $difficultyId,
                options: $options,
                items: $items,
                answers: $answers,
                rubric: [],
                references: $this->references($value('references')),
                tagIds: $this->tagIds($value('tags'), $branchId),
                examTypeId: $examTypeId,
                intakeId: $intakeId,
            ),
            'errors' => [],
            'warnings' => $warnings,
        ];
    }

    /** Plain text from a spreadsheet becomes a paragraph; anything unsafe is removed on save. */
    private function html(string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        return str_contains($text, '<') ? $text : '<p>'.e($text).'</p>';
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return list<OptionInput>
     */
    private function options(string $optionsCell, string $correctCell, QuestionType $type, array &$errors): array
    {
        $pieces = $this->pieces($optionsCell);

        if (! $type->has_options) {
            if ($pieces !== []) {
                $errors['options'][] = $type->name.' questions do not have options.';
            }

            return [];
        }

        if ($pieces === [] && $type->code === 'true_false') {
            $pieces = ['True', 'False'];
        }

        $correct = array_map(fn (string $piece): string => mb_strtoupper(trim($piece)), $this->pieces($correctCell, [',', '|', ';']));
        if ($type->code === 'true_false' && $correct !== []) {
            $correct = array_map(fn (string $piece): string => match (mb_strtolower($piece)) {
                'true', 't', 'yes', '1' => 'A',
                'false', 'f', 'no', '0' => 'B',
                default => $piece,
            }, $correct);
        }

        $options = [];
        foreach ($pieces as $index => $piece) {
            // "A) text" or "A. text" names its own letter; otherwise letters follow the order.
            $label = QuestionContent::letter($index);
            $body = $piece;
            if (preg_match('/^([A-Za-z])\s*[).:-]\s*(.+)$/u', $piece, $matched) === 1) {
                $label = mb_strtoupper($matched[1]);
                $body = trim($matched[2]);
            }

            $options[] = [
                'label' => $label,
                'body' => (string) $this->html($body),
                'is_correct' => in_array($label, $correct, true) || in_array(mb_strtoupper($body), $correct, true),
                'weight' => null,
                'feedback' => null,
                'sort_order' => $index + 1,
                'is_position_locked' => $type->code === 'true_false',
                'item_index' => null,
            ];
        }

        $labels = array_column($options, 'label');
        foreach ($correct as $letter) {
            if ($letter !== '' && ! in_array($letter, $labels, true) && ! in_array($letter, array_map(fn (array $option): string => mb_strtoupper(strip_tags($option['body'])), $options), true)) {
                $errors['correct'][] = 'The answer "'.$letter.'" is not one of the options.';
            }
        }

        return $options;
    }

    /**
     * @param  list<OptionInput>  $options
     * @param  array<string, list<string>>  $errors
     * @return list<ItemInput>
     */
    private function items(string $cell, QuestionType $type, array $options, array &$errors): array
    {
        $pieces = $this->pieces($cell);

        if (! $type->has_items) {
            if ($pieces !== []) {
                $errors['items'][] = $type->name.' questions do not have parts.';
            }

            return [];
        }

        $items = [];
        foreach ($pieces as $index => $piece) {
            $body = $piece;
            $isTrue = null;
            $optionLabel = null;

            if ($type->item_answer->value === 'boolean' && preg_match('/^(.*?)\s*=\s*(true|false|t|f|yes|no)\s*$/iu', $piece, $matched) === 1) {
                $body = trim($matched[1]);
                $isTrue = in_array(mb_strtolower($matched[2]), ['true', 't', 'yes'], true);
            } elseif (in_array($type->item_answer->value, ['option', 'text'], true) && preg_match('/^(.*?)\s*(?:->|=>|:)\s*(.+)$/u', $piece, $matched) === 1) {
                $body = trim($matched[1]);
                $answer = trim($matched[2]);
                if ($type->item_answer->value === 'option') {
                    $optionLabel = mb_strtoupper($answer);
                    if (! in_array($optionLabel, array_column($options, 'label'), true)) {
                        $errors['items'][] = 'Part "'.$body.'" points at "'.$answer.'", which is not one of the options.';
                    }
                }
            }

            if ($type->item_answer->value === 'boolean' && $isTrue === null) {
                $errors['items'][] = 'Statement "'.$body.'" does not say whether it is true or false (use "statement = true").';
            }
            if ($type->item_answer->value === 'option' && $optionLabel === null) {
                $errors['items'][] = 'Part "'.$body.'" does not say which option matches it (use "part -> B").';
            }

            $items[] = [
                'body' => (string) $this->html($body),
                'is_true' => $isTrue,
                'correct_option_label' => $optionLabel,
                'marks_fraction' => null,
                'feedback' => null,
                'sort_order' => $index + 1,
                'settings' => null,
            ];
        }

        return $items;
    }

    /**
     * @param  list<ItemInput>  $items
     * @param  array<string, list<string>>  $errors
     * @return list<AnswerInput>
     */
    private function answers(string $cell, QuestionType $type, array $items, array &$errors): array
    {
        $pieces = $this->pieces($cell);

        if (! $type->has_accepted_answers) {
            if ($pieces !== []) {
                $errors['answers'][] = $type->name.' questions do not have typed answers.';
            }

            return [];
        }

        $answers = [];
        foreach ($pieces as $index => $piece) {
            // Numbers may carry a tolerance and a unit: "7.35 ± 0.02 mmol/L" or "7.35 +- 0.02".
            if ($type->has_numeric_answer) {
                if (preg_match('/^\s*(-?[\d.,]+)\s*(?:±|\+\/-|\+-)?\s*([\d.,]+)?\s*([^\d\s].*)?$/u', $piece, $matched) !== 1) {
                    $errors['answers'][] = '"'.$piece.'" is not a number with an optional tolerance and unit.';

                    continue;
                }
                $answers[] = [
                    'item_index' => null,
                    'match_mode' => 'numeric',
                    'answer_text' => null,
                    'case_sensitive' => false,
                    'numeric_value' => (float) str_replace(',', '.', $matched[1]),
                    'tolerance' => isset($matched[2]) && $matched[2] !== '' ? (float) str_replace(',', '.', $matched[2]) : 0.0,
                    'tolerance_type' => 'absolute',
                    'unit' => isset($matched[3]) && trim($matched[3]) !== '' ? trim($matched[3]) : null,
                    'marks_fraction' => 1.0,
                    'feedback' => null,
                    'sort_order' => $index + 1,
                ];

                continue;
            }

            $answers[] = [
                'item_index' => $items === [] ? null : min($index, count($items) - 1),
                'match_mode' => 'exact',
                'answer_text' => $piece,
                'case_sensitive' => false,
                'numeric_value' => null,
                'tolerance' => null,
                'tolerance_type' => 'absolute',
                'unit' => null,
                'marks_fraction' => 1.0,
                'feedback' => null,
                'sort_order' => $index + 1,
            ];
        }

        return $answers;
    }

    /**
     * @return list<ReferenceInput>
     */
    private function references(string $cell): array
    {
        $references = [];
        foreach ($this->pieces($cell) as $index => $piece) {
            $isUrl = filter_var($piece, FILTER_VALIDATE_URL) !== false;
            $references[] = [
                'kind' => $isUrl ? 'url' : 'book',
                'citation' => mb_substr($piece, 0, 500),
                'locator' => null,
                'url' => $isUrl ? $piece : null,
                'sort_order' => $index + 1,
            ];
        }

        return $references;
    }

    /**
     * Tags named in the file are created if the campus does not have them yet.
     *
     * @return list<int>
     */
    private function tagIds(string $cell, int $branchId): array
    {
        $ids = [];
        foreach ($this->pieces($cell, [',', '|', ';']) as $name) {
            $slug = mb_substr(Str::slug($name) ?: mb_strtolower($name), 0, 60);
            $tag = Tag::query()->where(['branch_id' => $branchId, 'slug' => $slug])->first();
            $ids[] = (int) ($tag->id ?? 0);
        }

        return array_values(array_filter($ids));
    }

    /**
     * @param  list<string>  $separators
     * @return list<string>
     */
    private function pieces(string $cell, array $separators = ['|', ';', "\n"]): array
    {
        if (trim($cell) === '') {
            return [];
        }

        $normalised = str_replace($separators, '|', $cell);

        return array_values(array_filter(array_map('trim', explode('|', $normalised)), fn (string $piece): bool => $piece !== ''));
    }

    private function findType(string $given): ?QuestionType
    {
        if (trim($given) === '') {
            return null;
        }

        $needle = mb_strtolower(str_replace([' ', '-'], '_', trim($given)));

        return QuestionType::query()->where('is_active', true)->get()->first(
            fn (QuestionType $type): bool => $type->code === $needle
                || mb_strtolower($type->name) === mb_strtolower(trim($given))
                || in_array($needle, self::typeAliases($type->code), true),
        );
    }

    /**
     * @return list<string>
     */
    private static function typeAliases(string $code): array
    {
        return match ($code) {
            'single_best_answer' => ['sba', 'mcq', 'single_best', 'single_choice', 'best_answer'],
            'multiple_response' => ['mrq', 'multiple_choice', 'multi_select', 'multiple_answers'],
            'true_false' => ['tf', 'true/false', 'truefalse'],
            'multiple_true_false' => ['mtf', 'multi_true_false'],
            'extended_matching' => ['emq', 'extended_matching_question'],
            'short_answer' => ['saq', 'short'],
            'essay' => ['long_answer', 'leq', 'written'],
            'ordering' => ['order', 'sequence'],
            'cloze' => ['fill_in_the_blanks', 'blanks'],
            'image_labelling' => ['labelling', 'label_the_image'],
            default => [],
        };
    }

    private function findCourse(string $given, int $branchId): ?int
    {
        if (trim($given) === '') {
            return null;
        }

        $needle = mb_strtolower(trim($given));
        foreach ($this->academic->courses($branchId, inUseOnly: true) as $course) {
            if (mb_strtolower($course['code']) === $needle || mb_strtolower($course['title']) === $needle) {
                return $course['id'];
            }
        }

        return null;
    }

    private function findTopic(string $given, int $courseId): ?int
    {
        if (trim($given) === '') {
            return null;
        }

        $needle = mb_strtolower(trim($given));
        foreach ($this->academic->curriculum($courseId) as $node) {
            if (! $node['allows_questions']) {
                continue;
            }
            if (mb_strtolower((string) $node['code']) === $needle || mb_strtolower($node['name']) === $needle) {
                return $node['id'];
            }
        }

        return null;
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function findProgramme(string $given, int $branchId): ?array
    {
        $needle = mb_strtolower(trim($given));
        foreach ($this->academic->programmes($branchId) as $programme) {
            if (mb_strtolower($programme['code']) === $needle || mb_strtolower($programme['name']) === $needle) {
                return ['id' => $programme['id'], 'name' => $programme['name']];
            }
        }

        return null;
    }

    /** "2026 Intake" by its name, or by its year: "2026". */
    private function findIntake(string $given, int $branchId): ?int
    {
        $needle = mb_strtolower(trim($given));
        $intakes = $this->academic->intakes($branchId);
        foreach ($intakes as $intake) {
            if (mb_strtolower($intake['name']) === $needle) {
                return $intake['id'];
            }
        }
        if (preg_match('/^\d{4}$/', $needle) === 1) {
            foreach ($intakes as $intake) {
                if (preg_match('/\b'.$needle.'\b/', $intake['name']) === 1) {
                    return $intake['id'];
                }
            }
        }

        return null;
    }

    private function findExamType(string $given): ?int
    {
        $needle = mb_strtolower(trim($given));
        foreach ($this->academic->examTypes() as $examType) {
            if (mb_strtolower($examType['code']) === $needle || mb_strtolower($examType['name']) === $needle) {
                return $examType['id'];
            }
        }

        return null;
    }

    private function findDiscipline(string $given): ?int
    {
        $needle = mb_strtolower(trim($given));
        foreach ($this->academic->disciplines() as $discipline) {
            if (mb_strtolower($discipline['code']) === $needle || mb_strtolower($discipline['name']) === $needle) {
                return $discipline['id'];
            }
        }

        return null;
    }

    /**
     * @param  class-string<CognitiveLevel|DifficultyLevel>  $model
     */
    private function findLevel(string $model, string $given): ?int
    {
        $needle = mb_strtolower(trim($given));

        $row = $model::query()->where('is_active', true)->get()->first(
            fn (object $level): bool => mb_strtolower((string) $level->code) === $needle
                || mb_strtolower((string) $level->name) === $needle
                || str_starts_with(mb_strtolower((string) $level->name), $needle),
        );

        return $row?->id;
    }
}
