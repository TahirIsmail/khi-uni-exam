<?php

namespace App\Http\Controllers\Exam;

use App\Domain\Exam\Queries\ExaminationList;
use App\Domain\Identity\ActiveBranch;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Conduct Exam" in the CMS's Exams menu: which examination to register candidates for, allocate
 * seats in, or check candidates in to — and, alongside it, the campus's centres and rooms.
 */
class ConductController extends Controller
{
    public function __construct(private readonly ActiveBranch $activeBranch) {}

    public function index(Request $request, ExaminationList $list): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $branchId = $this->activeBranch->id($request->user('web')) ?? abort(403, 'You do not work in any campus.');

        return Inertia::render('exams/conduct/Index', [
            'examinations' => $list->paginate($request->user('web'), $branchId, $filters),
            'filters' => ['search' => $filters['search'] ?? ''],
            'canManageCentres' => $request->user('web')->can('centre.manage') || $request->user('web')->can('centre.view'),
        ]);
    }
}
