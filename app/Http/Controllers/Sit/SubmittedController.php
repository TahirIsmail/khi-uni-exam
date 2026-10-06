<?php

namespace App\Http\Controllers\Sit;

use App\Domain\Exam\Models\Examination;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The read-only screen a candidate sees once their attempt is submitted. */
class SubmittedController extends Controller
{
    public function show(Request $request, Examination $exam): Response
    {
        return Inertia::render('sit/Submitted', [
            'examination' => ['title' => $exam->title],
        ]);
    }
}
