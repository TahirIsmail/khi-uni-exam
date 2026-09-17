<?php

namespace App\Domain\Identity\Authorization;

/**
 * The permission catalogue (architecture blueprint, section 6.1).
 *
 * Code checks permissions, never role names. CMS roles are granted permissions in
 * sec_role_permissions; where a user may act is limited by sec_user_scopes.
 * Run `php artisan permissions:sync` after changing this list.
 */
final class Permissions
{
    /**
     * @var array<string, array<string, string>> group => [code => description]
     */
    public const CATALOGUE = [
        'Question bank' => [
            'qbank.question.view' => 'View questions',
            'qbank.question.create' => 'Create questions',
            'qbank.question.edit_own' => 'Edit own draft questions',
            'qbank.question.edit_any' => 'Edit any draft question',
            'qbank.question.submit' => 'Submit questions for review',
            'qbank.question.archive' => 'Archive questions',
            'qbank.question.restore' => 'Restore archived questions',
            'qbank.question.export' => 'Export questions (including answer keys)',
        ],
        'Review' => [
            'qbank.review.perform' => 'Review questions',
            'qbank.prehoc.record' => 'Record pre-hoc assessment',
            'qbank.question.approve' => 'Approve questions',
            'qbank.review.assign' => 'Assign reviewers',
        ],
        'Import' => [
            'qbank.import.run' => 'Upload and validate question imports',
            'qbank.import.commit' => 'Commit question imports',
        ],
        'Blueprint' => [
            'exam.blueprint.view' => 'View blueprints',
            'exam.blueprint.manage' => 'Create and edit blueprints',
            'exam.blueprint.approve' => 'Approve blueprints',
        ],
        'Examinations' => [
            'exam.view' => 'View examinations and papers',
            'exam.create' => 'Create examinations and papers',
            'exam.select_questions' => 'Select questions for papers',
            'exam.submit' => 'Submit papers for approval',
            'exam.approve' => 'Approve papers',
            'exam.finalise' => 'Finalise papers',
            'exam.publish' => 'Publish papers to delivery',
            'exam.unlock_version' => 'Create a new version of a finalised paper',
        ],
        'Candidates' => [
            'candidate.manage' => 'Register and manage candidates',
            'candidate.checkin' => 'Check candidates in',
            'candidate.extra_time' => 'Grant extra time',
        ],
        'Centres' => [
            'centre.manage' => 'Manage centres, rooms, seats and devices',
            'centre.allocate' => 'Allocate candidates and staff',
        ],
        'Delivery' => [
            'delivery.monitor' => 'Monitor live examinations',
            'delivery.session_control' => 'Pause, unlock or terminate exam sessions',
        ],
        'Proctoring' => [
            'proctor.events.view' => 'View proctoring events',
            'proctor.evidence.view' => 'View proctoring evidence',
            'proctor.review.decide' => 'Decide proctoring review cases',
            'proctor.evidence.export' => 'Export proctoring evidence',
        ],
        'Results' => [
            'result.view' => 'View results',
            'result.rescore' => 'Re-key or remove items and rescore',
            'result.approve' => 'Approve results',
            'result.publish' => 'Publish results',
        ],
        'Analytics' => [
            'analytics.view' => 'View item analysis',
            'analytics.run' => 'Run analysis',
            'analytics.decision.record' => 'Record post-hoc decisions',
            'analytics.thresholds.manage' => 'Manage analysis thresholds',
        ],
        'Reports' => [
            'report.view' => 'View reports',
            'report.export' => 'Export reports',
        ],
        'Administration' => [
            'admin.users.manage' => 'Manage user scopes',
            'admin.roles.manage' => 'Grant permissions to roles',
            'audit.view' => 'View the audit log',
            'audit.export' => 'Export the audit log',
        ],
    ];

    /**
     * Holding any of these requires multi-factor authentication (blueprint 6.2).
     *
     * @var list<string>
     */
    public const PRIVILEGED = [
        'admin.users.manage',
        'admin.roles.manage',
        'audit.view',
        'audit.export',
        'qbank.question.approve',
        'qbank.question.export',
        'exam.approve',
        'exam.finalise',
        'exam.publish',
        'exam.unlock_version',
        'delivery.session_control',
        'proctor.evidence.view',
        'proctor.evidence.export',
        'proctor.review.decide',
        'result.rescore',
        'result.approve',
        'result.publish',
    ];

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::CATALOGUE)));
    }

    public static function exists(string $code): bool
    {
        return in_array($code, self::codes(), true);
    }
}
