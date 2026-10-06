<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\ActiveBranch;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Switching the campus (branch) the user is working in. Only their own campuses are accepted.
 */
class BranchController extends Controller
{
    public function update(Request $request, ActiveBranch $activeBranch): RedirectResponse
    {
        $input = $request->validate(['branch_id' => ['required', 'integer', 'min:1', 'max:4294967295']]);

        if (! $activeBranch->switchTo($request->user('web'), (int) $input['branch_id'])) {
            abort(403, 'You do not work in that campus.');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Campus changed to :name.', ['name' => $activeBranch->name($request->user('web'))])]);

        return back();
    }
}
