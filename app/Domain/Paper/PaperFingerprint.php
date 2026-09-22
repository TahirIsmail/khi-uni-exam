<?php

namespace App\Domain\Paper;

use App\Domain\Paper\Models\Paper;
use Illuminate\Support\Facades\DB;

/**
 * A SHA-256 of a paper as it stands: its items — the exact version of each question, its marks and
 * its position — and how it is presented, in a fixed order. Taken when the paper is finalised, so
 * anyone can show it has not changed since. A question version cannot itself be edited once it is in
 * use (blueprint 8.2), so this is a fingerprint of the *paper's* choices — which questions, in what
 * order, worth what — not of the question text, which is already immutable on its own.
 */
final class PaperFingerprint
{
    public function of(Paper $paper): string
    {
        $items = DB::table('exm_paper_items')
            ->where('paper_id', $paper->id)
            ->orderBy('position')
            ->get(['position', 'question_id', 'version_id', 'marks'])
            ->map(fn ($row): array => [
                'position' => (int) $row->position,
                'question_id' => (int) $row->question_id,
                'version_id' => (int) $row->version_id,
                'marks' => number_format((float) $row->marks, 2, '.', ''),
            ])
            ->all();

        return hash('sha256', (string) json_encode([
            'examination_id' => $paper->examination_id,
            'version_no' => $paper->version_no,
            'shuffle_questions' => $paper->shuffle_questions,
            'shuffle_options' => $paper->shuffle_options,
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
