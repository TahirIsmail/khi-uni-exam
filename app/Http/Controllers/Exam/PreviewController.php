<?php

namespace App\Http\Controllers\Exam;

use App\Domain\Delivery\Queries\AttemptData;
use App\Domain\Exam\Models\Examination;
use App\Domain\Marking\Support\ObjectiveItemScorer;
use App\Domain\Paper\Enums\PaperStatus;
use App\Domain\Paper\Models\Paper;
use App\Domain\Paper\Models\PaperItem;
use App\Domain\Paper\Queries\PaperData;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Preview as candidate": the exam screen exactly as a candidate sits it, for the staff who set the
 * paper to check it before the day. The paper candidates are given (the published one), or the
 * newest version before that. Nothing is saved: no attempt, no answers, no proctoring.
 *
 * Finishing the preview shows the staff member — never a candidate — what the candidate would see
 * once they submit, and how the answers just given would be marked: the same scorer the real
 * marking uses (ObjectiveItemScorer), the same negative marking as CompileResult.
 */
class PreviewController extends ExamAreaController
{
    public function show(Request $request, Examination $exam, AttemptData $data, PaperData $papers): Response
    {
        $paper = $this->paperFor($request, $exam, $papers);

        return Inertia::render('sit/Exam', [
            'examination' => [
                'id' => $exam->id,
                'title' => $exam->title,
                'instructions' => $exam->instructions,
            ],
            ...$data->preview($paper, (int) $exam->duration_minutes),
            'preview' => [
                'backUrl' => route('conduct.candidates', $exam, false),
                'checkUrl' => route('conduct.preview.check', $exam, false),
                'paper' => 'Paper version '.$paper->version_no.' · '.ucfirst($paper->status->value),
                'published' => $paper->status === PaperStatus::Published,
            ],
        ]);
    }

    /** The marking of a finished preview. Shown, never stored. */
    public function check(Request $request, Examination $exam, PaperData $papers, ObjectiveItemScorer $scorer): Response
    {
        $paper = $this->paperFor($request, $exam, $papers);
        $input = $request->validate([
            'answers' => ['nullable', 'array', 'max:500'],
            'answers.*' => ['nullable', 'array'],
        ]);
        /** @var array<int|string, array<string, mixed>|null> $given */
        $given = $input['answers'] ?? [];

        $items = PaperItem::query()->where('paper_id', $paper->id)->orderBy('position')->get();
        $types = QuestionType::query()->whereIn('id', $items->pluck('question_type_id'))->get()->keyBy('id');
        $versions = QuestionVersion::query()->whereIn('id', $items->pluck('version_id'))
            ->with(['question:id,public_ref', 'options', 'items.answers', 'answers'])->get()->keyBy('id');

        $rows = [];
        $raw = 0.0;
        $deduction = 0.0;
        $awaiting = 0;
        foreach ($items as $item) {
            $type = $types->get($item->question_type_id);
            $version = $versions->get($item->version_id);
            if (! $type instanceof QuestionType || ! $version instanceof QuestionVersion) {
                continue;
            }

            $payload = array_filter($given[$item->id] ?? [], fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
            $max = (float) $item->marks;
            $options = $version->options->where('item_id', null)->sortBy('sort_order');

            $row = [
                'number' => $item->position,
                'reference' => $version->question->public_ref.' v'.$version->version_no,
                'type' => $type->name,
                'max' => $max,
                'awarded' => null,
                'outcome' => 'examiner',
                'given' => $type->has_options && ! $type->has_items
                    ? $options->whereIn('id', array_map('intval', (array) ($payload['selected'] ?? [])))->pluck('label')->values()->all()
                    : null,
                'key' => $type->has_options && ! $type->has_items
                    ? $options->where('is_correct', true)->pluck('label')->values()->all()
                    : null,
            ];

            if ($type->is_manually_marked) {
                // An essay: an examiner marks it after the exam.
                $row['outcome'] = $payload === [] ? 'blank' : 'examiner';
                $awaiting += $payload === [] ? 0 : 1;
            } else {
                $marks = round($scorer->score($type, $version, $payload, $max), 2);
                $row['awarded'] = $marks;
                $row['outcome'] = match (true) {
                    $payload === [] => 'blank',
                    $marks >= $max => 'correct',
                    $marks > 0 => 'partly',
                    default => 'wrong',
                };
                // Typed text is matched, then confirmed by an examiner (AutoMarkAttempt).
                if ($type->requires_confirmation && $payload !== []) {
                    $row['outcome'] = 'suggested';
                }
                $raw += $marks;
                if ($exam->negative_marking && $marks == 0.0 && $payload !== []) {
                    $deduction += (float) $exam->negative_fraction * $max;
                }
            }

            $rows[] = $row;
        }

        $total = max(0.0, $raw - $deduction);
        $percentage = $exam->total_marks > 0 ? $total / $exam->total_marks * 100 : 0.0;

        return Inertia::render('exams/PreviewResult', [
            'examination' => [
                'id' => $exam->id,
                'title' => $exam->title,
                'totalMarks' => (float) $exam->total_marks,
                'passPercentage' => (float) $exam->pass_percentage,
                'negativeMarking' => (bool) $exam->negative_marking,
            ],
            'rows' => $rows,
            'totals' => [
                'raw' => round($raw, 2),
                'deduction' => round($deduction, 2),
                'total' => round($total, 2),
                'percentage' => round($percentage, 2),
                'passes' => $percentage >= (float) $exam->pass_percentage,
                // Essays still to be marked by an examiner: the result is not final without them.
                'awaiting' => $awaiting,
            ],
        ]);
    }

    /** A refreshed result page has nothing to show; back to the preview. */
    public function again(Examination $exam): RedirectResponse
    {
        return to_route('conduct.preview', $exam);
    }

    private function paperFor(Request $request, Examination $exam, PaperData $papers): Paper
    {
        $this->guard($request, $exam);
        // Reading the questions, not just counting them, is for whoever builds, approves or finalises papers.
        abort_unless($papers->mayRead($request->user('web'), $exam), 403, 'You cannot read the questions of this paper.');

        return Paper::query()->where('examination_id', $exam->id)->where('status', PaperStatus::Published)->latest('version_no')->first()
            ?? Paper::query()->where('examination_id', $exam->id)->latest('version_no')->first()
            ?? abort(404, 'This examination has no paper yet.');
    }
}
