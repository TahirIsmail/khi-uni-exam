<?php

namespace App\Http\Controllers\QuestionBank\Concerns;

use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Queries\ListNeighbours;
use App\Http\Controllers\QuestionBank\QuestionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * A question opened from the question list carries the list's filters as ?list=…, so its page can
 * offer the previous and next question of that list. Filters that do not check out are ignored.
 */
trait FollowsTheList
{
    /**
     * @return array<string, mixed>|null
     */
    private function listNeighbours(Request $request, QuestionVersion $version, bool $history = false): ?array
    {
        $listQuery = $request->query('list');
        if (! is_string($listQuery) || $listQuery === '' || strlen($listQuery) > 2000) {
            return null;
        }

        parse_str($listQuery, $raw);
        $validator = Validator::make($raw, QuestionController::filterRules());
        if ($validator->fails()) {
            return null;
        }

        $filters = $validator->validated();
        if (($filters['status'] ?? null) === 'all') {
            unset($filters['status']);
        }
        foreach (['mine', 'duplicates', 'archived'] as $flag) {
            $filters[$flag] = filter_var($raw[$flag] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return app(ListNeighbours::class)->around($request->user('web'), $this->branchId($request), $filters, $listQuery, $version, $history);
    }
}
