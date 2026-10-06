<?php

namespace App\Domain\QuestionBank\Validation;

use App\Support\Html\QuestionHtml;

/**
 * Checks a question against the rules of its type (blueprint 10.2) and against the item-writing
 * checklist (10.3). Errors stop a draft from being submitted for review; warnings are advice the
 * author sees in the editor and can accept.
 *
 * A draft can always be saved, so the editor shows the same lists while it is being written.
 */
final class QuestionValidator
{
    /**
     * @return array{errors: array<string, list<string>>, warnings: list<string>}
     */
    public function check(QuestionContent $content): array
    {
        $errors = [];
        $type = $content->type;

        // --- the question itself ------------------------------------------------------------
        $stemLength = mb_strlen(QuestionHtml::toText($content->stem));
        $min = (int) config('qbank.stem.min_length');
        $max = (int) config('qbank.stem.max_length');
        if ($stemLength < $min) {
            $errors['stem'][] = "The question text must be at least {$min} characters.";
        }
        if ($stemLength > $max) {
            $errors['stem'][] = "The question text must be at most {$max} characters ({$stemLength} now).";
        }

        $marksMax = (float) config('qbank.marks.max');
        if ($content->marks <= 0) {
            $errors['marks'][] = 'Marks must be more than zero.';
        }
        if ($content->marks > $marksMax) {
            $errors['marks'][] = "Marks must be at most {$marksMax}.";
        }
        if ($content->negativeMarks < 0) {
            $errors['negative_marks'][] = 'Negative marks cannot be less than zero.';
        }
        if ($content->negativeMarks > $content->marks) {
            $errors['negative_marks'][] = 'Negative marks cannot be more than the marks for the question.';
        }
        if ($content->negativeMarks > 0 && ! $type->supports_negative_marks) {
            $errors['negative_marks'][] = "{$type->name} questions cannot have negative marks.";
        }

        // --- where it is filed and how it is judged -------------------------------------------
        // KMU files every question under an examination type, and judges each one's level of
        // thinking and difficulty before it can go for review.
        if ($content->examTypeId === null) {
            $errors['exam_type_id'][] = 'Choose the examination type (Annual, Supplementary, Regular or Retake).';
        }
        if ($content->cognitiveLevelId === null) {
            $errors['cognitive_level_id'][] = 'Choose the cognitive level (Recall, Understanding, Application or Analysis).';
        }
        if ($content->difficultyLevelId === null) {
            $errors['difficulty_level_id'][] = 'Choose the difficulty level (Easy, Moderate or Difficult).';
        }

        // --- options ------------------------------------------------------------------------
        $optionCount = count($content->options);
        if ($type->has_options) {
            if ($optionCount < $type->options_min) {
                $errors['options'][] = "{$type->name} needs at least {$type->options_min} options.";
            }
            if ($optionCount > $type->options_max) {
                $errors['options'][] = "{$type->name} allows at most {$type->options_max} options.";
            }
            foreach ($content->options as $index => $option) {
                if (QuestionHtml::toText($option['body']) === '') {
                    $errors['options.'.$index][] = 'This option is empty.';
                }
            }

            $texts = array_map(fn (array $option): string => mb_strtolower(QuestionHtml::toText($option['body'])), $content->options);
            $duplicates = array_values(array_unique(array_diff_assoc($texts, array_unique($texts))));
            foreach ($duplicates as $duplicate) {
                if ($duplicate !== '') {
                    $errors['options'][] = "Two options say the same thing: \"{$duplicate}\".";
                }
            }

            $correct = count(array_filter($content->options, fn (array $option): bool => (bool) $option['is_correct']));
            if ($correct < $type->correct_min) {
                $errors['options'][] = $type->correct_min === 1
                    ? 'Mark the correct option.'
                    : "Mark at least {$type->correct_min} correct options.";
            }
            if ($type->correct_max !== null && $correct > $type->correct_max) {
                $errors['options'][] = $type->correct_max === 1
                    ? 'Only one option can be correct for this type.'
                    : "At most {$type->correct_max} options can be correct.";
            }
        } elseif ($optionCount > 0) {
            $errors['options'][] = "{$type->name} questions do not have options.";
        }

        // --- sub-parts ----------------------------------------------------------------------
        $itemCount = count($content->items);
        if ($type->has_items) {
            if ($itemCount < $type->items_min) {
                $errors['items'][] = "{$type->name} needs at least {$type->items_min} parts.";
            }
            if ($itemCount > $type->items_max) {
                $errors['items'][] = "{$type->name} allows at most {$type->items_max} parts.";
            }

            $optionLabels = array_map(fn (array $option): string => (string) $option['label'], $content->options);
            foreach ($content->items as $index => $item) {
                $key = 'items.'.$index;
                if (QuestionHtml::toText($item['body']) === '') {
                    $errors[$key][] = 'This part is empty.';
                }

                match ($type->item_answer->value) {
                    'boolean' => $item['is_true'] === null ? $errors[$key][] = 'Choose true or false for this statement.' : null,
                    'option' => match (true) {
                        $item['correct_option_label'] === null => $errors[$key][] = 'Choose the matching answer for this part.',
                        ! in_array($item['correct_option_label'], $optionLabels, true) => $errors[$key][] = 'The matching answer is not one of the options.',
                        default => null,
                    },
                    'text' => count(array_filter($content->answers, fn (array $answer): bool => $answer['item_index'] === $index)) === 0
                        ? $errors[$key][] = 'Add at least one accepted answer for this blank.'
                        : null,
                    default => null,
                };
            }

            if ($type->item_answer->value === 'position') {
                $orders = array_map(fn (array $item): int => (int) $item['sort_order'], $content->items);
                if (count(array_unique($orders)) !== $itemCount) {
                    $errors['items'][] = 'Each part needs its own position in the correct order.';
                }
            }
        } elseif ($itemCount > 0) {
            $errors['items'][] = "{$type->name} questions do not have parts.";
        }

        // --- typed answers ------------------------------------------------------------------
        if ($type->has_accepted_answers) {
            if ($content->answers === []) {
                $errors['answers'][] = 'Add at least one accepted answer.';
            }
            foreach ($content->answers as $index => $answer) {
                $key = 'answers.'.$index;
                if ($answer['marks_fraction'] <= 0 || $answer['marks_fraction'] > 1) {
                    $errors[$key][] = 'The share of the marks must be between 0 and 1.';
                }
                if ($answer['match_mode'] === 'numeric' || $type->has_numeric_answer) {
                    if ($answer['numeric_value'] === null) {
                        $errors[$key][] = 'Enter the number that is accepted.';
                    }
                    if ($answer['tolerance'] !== null && $answer['tolerance'] < 0) {
                        $errors[$key][] = 'The tolerance cannot be negative.';
                    }
                } elseif ($answer['answer_text'] === null) {
                    $errors[$key][] = 'Enter the accepted answer.';
                }
                if ($answer['match_mode'] === 'regex' && $answer['answer_text'] !== null && @preg_match('/'.str_replace('/', '\/', $answer['answer_text']).'/u', '') === false) {
                    $errors[$key][] = 'This pattern is not valid.';
                }
            }
        } elseif ($content->answers !== []) {
            $errors['answers'][] = "{$type->name} questions do not have typed answers.";
        }

        // --- rubric -------------------------------------------------------------------------
        if ($content->rubric !== [] && ! $type->supports_rubric) {
            $errors['rubric'][] = "{$type->name} questions do not have a rubric.";
        }
        foreach ($content->rubric as $index => $criterion) {
            if (trim($criterion['criterion']) === '') {
                $errors['rubric.'.$index][] = 'Describe what is being marked.';
            }
            if ($criterion['max_marks'] <= 0) {
                $errors['rubric.'.$index][] = 'Marks for this line must be more than zero.';
            }
        }

        // --- references ---------------------------------------------------------------------
        foreach ($content->references as $index => $reference) {
            if (trim($reference['citation']) === '') {
                $errors['references.'.$index][] = 'Enter the reference.';
            }
            if ($reference['url'] !== null && filter_var($reference['url'], FILTER_VALIDATE_URL) === false) {
                $errors['references.'.$index][] = 'This link is not a valid address.';
            }
        }

        return ['errors' => $errors, 'warnings' => $this->warnings($content)];
    }

    public function isSubmittable(QuestionContent $content): bool
    {
        return $this->check($content)['errors'] === [];
    }

    /**
     * The item-writing checklist: things that usually weaken a question but are not mistakes.
     *
     * @return list<string>
     */
    private function warnings(QuestionContent $content): array
    {
        $warnings = [];
        $checklist = (array) config('qbank.checklist');
        $optionTexts = array_map(fn (array $option): string => QuestionHtml::toText($option['body']), $content->options);
        $leadIn = mb_strtolower((string) $content->leadIn.' '.QuestionHtml::toText($content->stem));

        if (($checklist['flag_all_of_the_above'] ?? false) === true) {
            foreach ($optionTexts as $text) {
                if (preg_match('/\b(all|none) of the above\b/i', $text) === 1) {
                    $warnings[] = 'Options such as "all of the above" or "none of the above" usually test test-taking skill, not knowledge.';

                    break;
                }
            }
        }

        if (($checklist['flag_negative_lead_in'] ?? false) === true && preg_match('/\b(not|except|false)\b/', $leadIn) === 1) {
            $warnings[] = 'The question asks for what is NOT true. Positive wording is easier to answer correctly for the right reason.';
        }

        if (($checklist['flag_longest_option_is_key'] ?? false) === true && count($optionTexts) > 1) {
            $lengths = array_map('mb_strlen', $optionTexts);
            $longest = max($lengths);
            $correctIndexes = array_keys(array_filter($content->options, fn (array $option): bool => (bool) $option['is_correct']));
            foreach ($correctIndexes as $index) {
                if ($lengths[$index] === $longest && $longest > (int) (array_sum($lengths) / count($lengths) * 1.5)) {
                    $warnings[] = 'The correct option is noticeably longer than the others, which is a clue. Make the options a similar length.';

                    break;
                }
            }
        }

        if (($checklist['flag_absolute_terms'] ?? false) === true) {
            foreach ($optionTexts as $text) {
                if (preg_match('/\b(always|never|all|every|no|none)\b/i', $text) === 1) {
                    $warnings[] = 'Words such as "always" or "never" in the options are a cue: candidates learn that absolute options are usually wrong.';

                    break;
                }
            }
        }

        if (($checklist['flag_missing_explanation'] ?? false) === true && $content->explanation === null) {
            $warnings[] = 'There is no explanation. Reviewers and, later, candidates gain from knowing why the answer is right.';
        }

        return array_values(array_unique($warnings));
    }
}
