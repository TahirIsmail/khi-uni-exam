<?php

namespace App\Console\Commands;

use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\Review;
use App\Domain\QuestionBank\Review\ApproveVersion;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Run once after kmu-cms switches to one reviewer per question (2026-10-03): a question already in
 * review whose reviewers all accepted it, but which was waiting for a second level that is no longer
 * asked for, is stored in the QBank now — as if its last reviewer had just accepted it. Questions
 * where the reviewers disagreed, or a required checklist item failed, stay with the approving
 * authority. Safe to run again.
 */
#[Signature('qbank:store-accepted')]
#[Description('Store in the QBank the questions in review that their reviewers have already accepted')]
final class StoreAcceptedQuestions extends Command
{
    public function handle(ApproveVersion $approve): int
    {
        $stored = 0;

        QuestionVersion::query()
            ->where('status', VersionStatus::UnderReview)
            ->orderBy('id')
            ->each(function (QuestionVersion $version) use ($approve, &$stored): void {
                $last = Review::query()
                    ->where('version_id', $version->id)
                    ->where('round', $version->review_round)
                    ->orderByDesc('id')
                    ->first();
                $reviewer = $last === null ? null : User::query()->find($last->reviewer_id);

                if ($reviewer instanceof User && $approve->acceptedByReviewers($reviewer, $version) !== null) {
                    $stored++;
                }
            });

        $this->info("Stored {$stored} accepted question(s) in the QBank.");

        return self::SUCCESS;
    }
}
