<?php

namespace App\Http\Controllers\Exam;

use App\Domain\Analytics\Actions\RecordPosthocDecision;
use App\Domain\Analytics\Actions\RunAnalysis;
use App\Domain\Analytics\Queries\ExamStatistics;
use App\Domain\Analytics\Queries\ItemAnalysis;
use App\Domain\Analytics\Queries\Reliability;
use App\Domain\Analytics\Queries\TosCompliance;
use App\Domain\Exam\Models\Examination;
use App\Domain\Identity\ActiveBranch;
use App\Domain\QuestionBank\Models\PosthocDecision;
use App\Domain\QuestionBank\Models\PosthocDecisionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Http\Controllers\Controller;
use App\Support\Cms\CmsAcademic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Post-hoc analysis (exam phase, step 22): item statistics, reliability, and compliance with the
 * table of specification, for one examination — and the decision that goes back to the question
 * bank once there are statistics to decide from.
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly ActiveBranch $activeBranch) {}

    public function show(Request $request, Examination $exam, ItemAnalysis $itemAnalysis, Reliability $reliability, TosCompliance $tos, ExamStatistics $statistics, CmsAcademic $academic): Response
    {
        $this->guard($request, $exam);
        $user = $request->user('web');

        return Inertia::render('results/Analysis', [
            'examination' => [
                'id' => $exam->id,
                'reference' => $exam->public_ref,
                'title' => $exam->title,
                'course' => $academic->courseLabel($exam->course_id),
            ],
            'statistics' => $statistics->forExamination($exam),
            'items' => $itemAnalysis->forExamination($exam),
            'decisions' => $this->decisions($exam),
            'thresholds' => config('exam.analytics'),
            'reliability' => $reliability->forExamination($exam),
            'tos' => $tos->forExamination($exam),
            'decisionTypes' => PosthocDecisionType::query()->where('is_active', true)->orderBy('sort_order')
                ->get(['code', 'name', 'description'])->values()->all(),
            'can' => [
                'run' => $user->can('analytics.run'),
                'decide' => $user->can('analytics.decision.record'),
            ],
        ]);
    }

    public function run(Request $request, Examination $exam, RunAnalysis $run): RedirectResponse
    {
        $this->guard($request, $exam);
        $run($request->user('web'), $exam);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Analysis run.')]);

        return to_route('results.analysis', $exam);
    }

    public function decide(Request $request, Examination $exam, QuestionVersion $version, RecordPosthocDecision $decide): RedirectResponse
    {
        $this->guard($request, $exam);

        $input = $request->validate([
            'decision' => ['required', 'string', 'max:30'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $decide($request->user('web'), $version, $exam, $input['decision'], $input['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Decision recorded.')]);

        return to_route('results.analysis', $exam);
    }

    /**
     * The item analysis as KMU's record (Course ID, Exam, Question No., Students, Difficulty Index,
     * Discrimination Index, Option A… with the key marked, Status/Decision), for Excel.
     */
    public function export(Request $request, Examination $exam, ItemAnalysis $itemAnalysis, ExamStatistics $statistics, CmsAcademic $academic): StreamedResponse
    {
        $this->guard($request, $exam);

        $items = $itemAnalysis->forExamination($exam);
        $decisions = $this->decisions($exam);
        $course = (string) $academic->courseLabel($exam->course_id);
        $labels = collect($items)->flatMap(fn (array $item): array => array_column($item['options'] ?? [], 'label'))->unique()->sort()->values()->all();
        $stats = $statistics->forExamination($exam);
        $percent = fn (?float $share): string => $share === null ? '' : (string) round($share * 100).'%';

        $rows = [
            ['Examination', $exam->title],
            ['Students', (string) $stats['students'], 'Total marks', (string) $stats['totalMarks'], 'Mean', (string) $stats['mean'], 'Median', (string) $stats['median'],
                'Standard deviation', (string) $stats['sd'], 'Minimum', (string) $stats['min'], 'Maximum', (string) $stats['max'],
                'Pass %', (string) $stats['passPercent'], 'Fail %', (string) $stats['failPercent']],
            [],
            ['Course ID', 'Exam', 'Question No.', 'Students', 'Difficulty Index', 'Difficulty', 'Discrimination Index', 'Discrimination',
                ...array_map(fn (string $label): string => 'Option '.$label, $labels),
                'Non-functional distractors', 'Distractor efficiency', 'Possibly defective options', 'Status/Decision'],
        ];
        foreach ($items as $item) {
            $options = collect($item['options'] ?? [])->keyBy('label');
            $analysis = $item['distractorAnalysis'];
            $rows[] = [
                $course,
                $exam->title,
                (string) $item['position'],
                (string) $item['candidates'],
                $item['observedP'] === null ? '' : (string) $item['observedP'],
                (string) ($item['difficultyBand']['label'] ?? ''),
                $item['discrimination'] === null ? '' : (string) $item['discrimination'],
                (string) ($item['discriminationBand']['label'] ?? ''),
                ...array_map(fn (string $label): string => $options->has($label)
                    ? $percent($options[$label]['share']).($options[$label]['correct'] ? ' (correct)' : '')
                    : '', $labels),
                $analysis === null ? '' : implode(', ', $analysis['nonFunctional']),
                $analysis === null || $analysis['efficiency'] === null ? '' : $analysis['efficiency'].'%',
                $analysis === null ? '' : implode(', ', array_unique([...$analysis['defective'], ...$analysis['possibleMiskey']])),
                $decisions[$item['versionId']]['name'] ?? '',
            ];
        }

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads the names right
            foreach ($rows as $row) {
                fputcsv($out, $row, escape: '');
            }
            fclose($out);
        }, 'item-analysis-'.$exam->public_ref.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The latest post-hoc decision on each question of this examination.
     *
     * @return array<int, array{code: string, name: string}> version id => decision
     */
    private function decisions(Examination $exam): array
    {
        $decisions = [];
        foreach (PosthocDecision::query()->where('exam_id', $exam->id)->with('decisionType:id,code,name')->orderBy('decided_at')->orderBy('id')->get() as $decision) {
            $decisions[$decision->version_id] = ['code' => (string) $decision->decisionType?->code, 'name' => (string) $decision->decisionType?->name];
        }

        return $decisions;
    }

    private function branchId(Request $request): int
    {
        return $this->activeBranch->id($request->user('web')) ?? abort(403, 'You do not work in any campus.');
    }

    private function guard(Request $request, Examination $examination): void
    {
        abort_unless($examination->branch_id === $this->branchId($request), 404);
        $user = $request->user('web');
        abort_unless($user->can('analytics.view') || $user->can('analytics.run') || $user->can('analytics.decision.record'), 403, 'You cannot open analysis for this course.');
    }
}
