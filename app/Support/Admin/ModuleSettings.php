<?php

namespace App\Support\Admin;

/**
 * The module settings (kmu-cms "Exam Module Settings", in sch_settings) and the campuses (branches)
 * of the admin database. App\Support\Cms\CmsSettings reads the same settings through the views.
 */
final class ModuleSettings
{
    /** Form field => sch_settings column. */
    public const COLUMNS = [
        'mfa' => 'kmu_assess_mfa_enabled',
        'reviews_required' => 'kmu_assess_reviews_required',
        'review_days' => 'kmu_assess_review_days',
        'auto_activate' => 'kmu_assess_auto_activate',
        'accept_stores' => 'kmu_assess_reviewer_accept_stores',
        'academic_review' => 'kmu_assess_academic_review',
        'anonymous' => 'kmu_assess_reviewer_anonymous',
        'device_approval' => 'kmu_assess_device_approval',
    ];

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $row = AdminTables::query('sch_settings')->orderBy('id')->first();

        return [
            'branches' => AdminTables::query('branches')->orderBy('id')->get(['id', 'branch_name', 'branch_code', 'status'])
                ->map(fn ($b) => ['id' => (int) $b->id, 'name' => $b->branch_name, 'code' => $b->branch_code, 'isActive' => $b->status === 'active'])->values()->all(),
            'settings' => [
                'mfa' => (bool) ($row->kmu_assess_mfa_enabled ?? false),
                'reviewsRequired' => (int) ($row->kmu_assess_reviews_required ?? 1),
                'reviewDays' => (int) ($row->kmu_assess_review_days ?? 7),
                'autoActivate' => (bool) ($row->kmu_assess_auto_activate ?? true),
                'acceptStores' => (bool) ($row->kmu_assess_reviewer_accept_stores ?? true),
                'academicReview' => (bool) ($row->kmu_assess_academic_review ?? false),
                'anonymous' => (bool) ($row->kmu_assess_reviewer_anonymous ?? false),
                'deviceApproval' => (bool) ($row->kmu_assess_device_approval ?? false),
            ],
            'counts' => [
                'staff' => AdminTables::query('staff')->count(),
                'roles' => AdminTables::query('roles')->count(),
                'programmes' => AdminTables::query('acad_programme_profiles')->count(),
                'courses' => AdminTables::query('acad_courses')->count(),
                'intakes' => AdminTables::query('sessions')->count(),
            ],
        ];
    }

    /** @param array<string, int|bool> $values form field => value */
    public function save(array $values): void
    {
        $row = [];
        foreach (self::COLUMNS as $field => $column) {
            $row[$column] = (int) ($values[$field] ?? 0);
        }
        $id = AdminTables::query('sch_settings')->orderBy('id')->value('id');
        $id === null ? AdminTables::query('sch_settings')->insert($row) : AdminTables::query('sch_settings')->where('id', $id)->update($row);
    }

    /** @param array{branch_name: string, branch_code: string, status: string} $data */
    public function saveBranch(?int $branch, array $data): void
    {
        if ($branch === null) {
            AdminTables::query('branches')->insert($data);

            return;
        }
        abort_unless(AdminTables::query('branches')->where('id', $branch)->exists(), 404);
        AdminTables::query('branches')->where('id', $branch)->update($data);
    }
}
