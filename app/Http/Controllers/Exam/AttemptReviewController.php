<?php

namespace App\Http\Controllers\Exam;

use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Delivery\Queries\AttemptReview;
use App\Domain\Exam\Models\Examination;
use App\Domain\Exam\Queries\ExaminationData;
use App\Domain\Identity\ActiveBranch;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One candidate's answers, question by question, with the key: for whoever monitors, marks or sees
 * the results of the examination.
 */
class AttemptReviewController extends Controller
{
    public function __construct(private readonly ActiveBranch $activeBranch) {}

    public function show(Request $request, Examination $exam, CandidateExam $attempt, AttemptReview $review, ExaminationData $examinations): Response
    {
        abort_unless($exam->branch_id === ($this->activeBranch->id($request->user('web')) ?? abort(403, 'You do not work in any campus.')), 404);
        abort_unless($attempt->examination_id === $exam->id, 404);
        $user = $request->user('web');
        abort_unless($user->can('result.view') || $user->can('delivery.monitor') || $user->can('marking.mark') || $user->can('marking.adjudicate'), 403, 'You cannot see candidates\' answers for this course.');

        return Inertia::render('exams/conduct/AttemptReview', [
            'examination' => $examinations->detail($exam),
            ...$review->for($attempt),
        ]);
    }
}
