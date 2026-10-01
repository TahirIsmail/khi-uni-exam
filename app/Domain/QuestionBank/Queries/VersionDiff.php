<?php

namespace App\Domain\QuestionBank\Queries;

use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Support\TextDiff;
use App\Support\Cms\CmsAcademic;
use App\Support\Html\QuestionHtml;

/**
 * Two versions of a question side by side (blueprint 12): what the text, the options, the key, the
 * marks and the taxonomy were, and what they became. Reviewers read this instead of two full pages.
 */
final class VersionDiff
{
    public function __construct(private readonly CmsAcademic $academic) {}

    /**
     * @return array<string, mixed>
     */
    public function between(QuestionVersion $from, QuestionVersion $to): array
    {
        $from->loadMissing(['type', 'options', 'items', 'answers', 'references']);
        $to->loadMissing(['type', 'options', 'items', 'answers', 'references']);

        return [
            'from' => $this->side($from),
            'to' => $this->side($to),
            'text' => [
                'vignette' => $this->text($from->vignette, $to->vignette),
                'stem' => $this->text($from->stem, $to->stem),
                'leadIn' => $this->text($from->lead_in, $to->lead_in),
                'explanation' => $this->text($from->explanation, $to->explanation),
            ],
            'options' => $this->options($from, $to),
            'facts' => $this->facts($from, $to),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function side(QuestionVersion $version): array
    {
        return [
            'id' => $version->id,
            'versionNo' => $version->version_no,
            'status' => $version->status->value,
            'statusLabel' => $version->kmuStatus(),
            'type' => $version->type->name,
            'updatedAt' => $version->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{changed: bool, parts: list<array{type: string, text: string}>}
     */
    private function text(?string $before, ?string $after): array
    {
        $before = QuestionHtml::toText($before);
        $after = QuestionHtml::toText($after);

        return [
            'changed' => TextDiff::changed($before, $after),
            'parts' => TextDiff::words($before, $after),
        ];
    }

    /**
     * Options are matched by their letter, so a changed answer key or a reworded option is obvious.
     *
     * @return list<array<string, mixed>>
     */
    private function options(QuestionVersion $from, QuestionVersion $to): array
    {
        $before = $from->options->keyBy('label');
        $after = $to->options->keyBy('label');
        $labels = $before->keys()->merge($after->keys())->unique()->sort()->values();

        return array_values($labels->map(function (string $label) use ($before, $after): array {
            $old = $before->get($label);
            $new = $after->get($label);

            return [
                'label' => $label,
                'state' => match (true) {
                    $old === null => 'added',
                    $new === null => 'removed',
                    default => 'kept',
                },
                'text' => $this->text($old?->body, $new?->body),
                'wasCorrect' => $old?->is_correct,
                'isCorrect' => $new?->is_correct,
                'keyChanged' => $old !== null && $new !== null && $old->is_correct !== $new->is_correct,
            ];
        })->all());
    }

    /**
     * @return list<array{label: string, before: string, after: string, changed: bool}>
     */
    private function facts(QuestionVersion $from, QuestionVersion $to): array
    {
        $rows = [
            ['Type', $from->type->name, $to->type->name],
            ['Examination', $this->examTypeName($from->exam_type_id), $this->examTypeName($to->exam_type_id)],
            ['Marks', (string) $from->marks, (string) $to->marks],
            ['Negative marks', (string) $from->negative_marks, (string) $to->negative_marks],
            ['Course', $this->academic->courseLabel($from->course_id) ?? '—', $this->academic->courseLabel($to->course_id) ?? '—'],
            ['Academic Session', $this->intakeName($from), $this->intakeName($to)],
            ['Subject / Topic', $this->nodeName($from->node_id), $this->nodeName($to->node_id)],
            ['Parts', (string) $from->items->count(), (string) $to->items->count()],
            ['Accepted answers', (string) $from->answers->count(), (string) $to->answers->count()],
            ['References', (string) $from->references->count(), (string) $to->references->count()],
        ];

        return array_map(fn (array $row): array => [
            'label' => $row[0],
            'before' => $row[1],
            'after' => $row[2],
            'changed' => $row[1] !== $row[2],
        ], $rows);
    }

    private function examTypeName(?int $examTypeId): string
    {
        foreach ($this->academic->examTypes() as $examType) {
            if ($examType['id'] === $examTypeId) {
                return $examType['name'];
            }
        }

        return '—';
    }

    private function nodeName(?int $nodeId): string
    {
        return $this->academic->nodeName($nodeId) ?? ('#'.$nodeId);
    }

    private function intakeName(QuestionVersion $version): string
    {
        if ($version->intake_id === null) {
            return '—';
        }
        foreach ($this->academic->intakes($version->branch_id) as $intake) {
            if ($intake['id'] === $version->intake_id) {
                return $intake['name'];
            }
        }

        return '#'.$version->intake_id;
    }
}
