<?php

namespace App\Http\Controllers\Sit;

use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Exam\Models\Examination;
use App\Domain\Marking\Queries\AttemptScore;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The read-only screen a candidate sees once their attempt is submitted. */
class SubmittedController extends Controller
{
    public function show(Request $request, Examination $exam, AttemptScore $score): Response
    {
        // The score, and whether it passes, when the examination shows it on submitting.
        $attempt = $exam->show_result ? CandidateExam::query()
            ->where('examination_id', $exam->id)
            ->where('candidate_id', $request->user('candidate')?->getAuthIdentifier())
            ->where('status', AttemptStatus::Submitted)
            ->first() : null;

        return Inertia::render('sit/Submitted', [
            'examination' => ['title' => $exam->title],
            'result' => $attempt === null ? null : $score->of($attempt),
        ]);
    }
}
