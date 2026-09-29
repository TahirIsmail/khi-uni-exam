<?php

namespace App\Domain\Identity\Authorization;

/**
 * The permission catalogue (architecture blueprint, section 6.1).
 *
 * Code checks these permission codes, never role names. They are granted in kmu-cms, in
 * Roles → Assign Permission → "Question Bank & Exams": each code below is one checkbox there
 * (permission category short code + View/Add/Edit/Delete). Several codes may share a checkbox.
 * The categories are created by kmu-cms migration 20260917_0006; `php artisan cms:check-permissions`
 * confirms that every checkbox used here exists.
 */
final class Permissions
{
    /**
     * @var array<string, array<string, array{0: string, 1: string, 2: 'view'|'add'|'edit'|'delete'}>>
     *                                                                                                 group => [code => [description, CMS category short code, CMS checkbox]]
     */
    public const CATALOGUE = [
        'Question bank' => [
            'qbank.question.view' => ['View questions', 'qbank_questions', 'view'],
            'qbank.question.create' => ['Create questions', 'qbank_questions', 'add'],
            'qbank.question.edit_own' => ['Edit own draft questions', 'qbank_questions', 'add'],
            'qbank.question.submit' => ['Submit questions for review', 'qbank_questions', 'add'],
            'qbank.question.edit_any' => ['Edit any draft question', 'qbank_questions', 'edit'],
            'qbank.question.archive' => ['Archive questions', 'qbank_questions', 'delete'],
            'qbank.question.restore' => ['Restore archived questions', 'qbank_questions', 'delete'],
            'qbank.question.export' => ['Export questions (including answer keys)', 'qbank_questions_export', 'view'],
        ],
        'Review' => [
            'qbank.review.perform' => ['Review questions (department / subject level)', 'qbank_review', 'view'],
            'qbank.review.academic' => ['Review questions (QBank / academic level)', 'qbank_review_academic', 'view'],
            'qbank.review.assign' => ['Assign reviewers', 'qbank_review_assign', 'view'],
            'qbank.prehoc.record' => ['Record the cognitive and difficulty level', 'qbank_prehoc', 'view'],
            'qbank.question.approve' => ['Approve questions', 'qbank_approve', 'view'],
        ],
        'Import' => [
            'qbank.import.run' => ['Upload and validate question imports', 'qbank_import', 'view'],
            'qbank.import.commit' => ['Commit question imports', 'qbank_import', 'add'],
        ],
        'Blueprint' => [
            'exam.blueprint.view' => ['View blueprints', 'exam_blueprints', 'view'],
            'exam.blueprint.manage' => ['Create and edit blueprints', 'exam_blueprints', 'edit'],
            'exam.blueprint.approve' => ['Approve blueprints', 'exam_blueprints_approve', 'view'],
        ],
        'Examinations' => [
            'exam.view' => ['View examinations and papers', 'exam_papers', 'view'],
            'exam.create' => ['Create examinations and papers', 'exam_papers', 'add'],
            'exam.select_questions' => ['Select questions for papers', 'exam_papers', 'edit'],
            'exam.submit' => ['Submit papers for approval', 'exam_papers', 'edit'],
            'exam.approve' => ['Approve papers', 'exam_papers_approve', 'view'],
            'exam.finalise' => ['Finalise papers', 'exam_papers_finalise', 'view'],
            'exam.publish' => ['Publish papers to delivery', 'exam_papers_publish', 'view'],
            'exam.unlock_version' => ['Create a new version of a finalised paper', 'exam_papers_unlock', 'view'],
        ],
        'Candidates' => [
            'candidate.view' => ['View candidates', 'exam_candidates', 'view'],
            'candidate.manage' => ['Register and manage candidates', 'exam_candidates', 'edit'],
            'candidate.checkin' => ['Check candidates in', 'exam_checkin', 'view'],
            'candidate.extra_time' => ['Grant extra time', 'exam_extra_time', 'view'],
        ],
        'Centres' => [
            'centre.view' => ['View centres, rooms, seats and devices', 'exam_centres', 'view'],
            'centre.manage' => ['Manage centres, rooms, seats and devices', 'exam_centres', 'edit'],
            'centre.allocate' => ['Allocate candidates and staff', 'exam_allocation', 'view'],
        ],
        'Delivery' => [
            'delivery.monitor' => ['Monitor live examinations', 'exam_monitor', 'view'],
            'delivery.session_control' => ['Pause, unlock or end exam sessions', 'exam_session_control', 'view'],
        ],
        'Proctoring' => [
            'proctor.events.view' => ['View proctoring events', 'proctor_events', 'view'],
            'proctor.evidence.view' => ['View proctoring evidence', 'proctor_evidence', 'view'],
            'proctor.evidence.export' => ['Export proctoring evidence', 'proctor_evidence_export', 'view'],
            'proctor.review.decide' => ['Decide proctoring review cases', 'proctor_decisions', 'view'],
        ],
        'Marking' => [
            'marking.assign' => ['Assign examiners', 'exam_marking_assign', 'view'],
            'marking.mark' => ['Mark manually-marked items', 'exam_marking', 'view'],
            'marking.adjudicate' => ['Adjudicate disagreements between examiners', 'exam_marking_adjudicate', 'view'],
        ],
        'Results' => [
            'result.view' => ['View results', 'exam_results', 'view'],
            'result.rescore' => ['Re-key or remove items and rescore', 'exam_results_rescore', 'view'],
            'result.approve' => ['Approve results', 'exam_results_approve', 'view'],
            'result.publish' => ['Publish results', 'exam_results_publish', 'view'],
            'result.components' => ['Enter practical, viva and internal assessment marks', 'exam_result_components', 'view'],
        ],
        'Analytics' => [
            'analytics.view' => ['View item analysis', 'exam_item_analysis', 'view'],
            'analytics.run' => ['Run analysis', 'exam_item_analysis', 'add'],
            'analytics.decision.record' => ['Record post-hoc decisions', 'exam_item_analysis', 'edit'],
            'analytics.thresholds.manage' => ['Manage analysis thresholds', 'exam_item_analysis_thresholds', 'view'],
        ],
        'Reports' => [
            'report.view' => ['View reports', 'exam_reports', 'view'],
            'report.export' => ['Export reports', 'exam_reports_export', 'view'],
        ],
    ];

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        $codes = [];
        foreach (self::CATALOGUE as $permissions) {
            foreach (array_keys($permissions) as $code) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public static function exists(string $code): bool
    {
        return in_array($code, self::codes(), true);
    }

    /**
     * Codes granted by ticked CMS checkboxes.
     *
     * @param  array<string, array{view: bool, add: bool, edit: bool, delete: bool}>  $ticked  category short code => checkboxes
     * @return list<string>
     */
    public static function fromCmsGrants(array $ticked): array
    {
        $codes = [];
        foreach (self::CATALOGUE as $permissions) {
            foreach ($permissions as $code => [, $category, $checkbox]) {
                if ($ticked[$category][$checkbox] ?? false) {
                    $codes[] = $code;
                }
            }
        }

        return $codes;
    }

    /**
     * Every CMS checkbox the catalogue relies on.
     *
     * @return array<string, list<'view'|'add'|'edit'|'delete'>> category short code => checkboxes
     */
    public static function cmsCheckboxes(): array
    {
        $needed = [];
        foreach (self::CATALOGUE as $permissions) {
            foreach ($permissions as [, $category, $checkbox]) {
                $needed[$category] ??= [];
                if (! in_array($checkbox, $needed[$category], true)) {
                    $needed[$category][] = $checkbox;
                }
            }
        }

        return $needed;
    }
}
