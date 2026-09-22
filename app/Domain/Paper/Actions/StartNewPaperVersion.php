<?php

namespace App\Domain\Paper\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Exam\Models\Examination;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\Paper\Enums\PaperStatus;
use App\Domain\Paper\Models\Paper;
use App\Domain\Paper\Models\PaperItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Correcting a paper once it is finalised or published means a new version, never an edit
 * (blueprint 8.2's rule, applied to papers): the old one is kept exactly as it was, and a fresh
 * draft starts from its items — locked, so "fill the gaps" and a fresh draw leave them alone unless
 * the setter chooses to change them.
 */
final class StartNewPaperVersion
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $user, Examination $examination, Paper $paper): Paper
    {
        $target = new ScopeTarget($examination->branch_id, $examination->programme_id, $examination->professional_id, $examination->course_id);
        if (! $this->access->allows($user, 'exam.unlock_version', $target)) {
            throw new AuthorizationException('You cannot create a new version of this paper.');
        }
        if (! in_array($paper->status, [PaperStatus::Finalised, PaperStatus::Published], true)) {
            throw ValidationException::withMessages(['paper' => 'Only a finalised or published paper can be given a new version.']);
        }

        return DB::transaction(function () use ($user, $examination, $paper): Paper {
            Examination::query()->whereKey($examination->id)->lockForUpdate()->firstOrFail();
            if (Paper::query()->where('examination_id', $examination->id)->where('version_no', '>', $paper->version_no)->exists()) {
                throw ValidationException::withMessages(['paper' => 'A later version already exists.']);
            }

            $next = Paper::query()->create([
                'examination_id' => $examination->id,
                'version_no' => $paper->version_no + 1,
                'status' => PaperStatus::Draft,
                'shuffle_questions' => $paper->shuffle_questions,
                'shuffle_options' => $paper->shuffle_options,
                'created_by' => $user->id,
            ]);

            foreach (PaperItem::query()->where('paper_id', $paper->id)->orderBy('position')->get() as $item) {
                PaperItem::query()->create([
                    'paper_id' => $next->id,
                    'position' => $item->position,
                    'question_id' => $item->question_id,
                    'version_id' => $item->version_id,
                    'question_type_id' => $item->question_type_id,
                    'row_node_id' => $item->row_node_id,
                    'section_name' => $item->section_name,
                    'marks' => $item->marks,
                    'is_locked' => true,
                    'source' => 'manual',
                    'picked_by' => $user->id,
                ]);
            }

            $this->audit->record('paper.version_started', 'paper', $next->id, null, [
                'examination' => $examination->public_ref,
                'version_no' => $next->version_no,
                'from_version' => $paper->version_no,
            ], null, $user, $examination->branch_id);

            return $next;
        });
    }
}
