<?php

namespace App\Support\Cms;

/**
 * The read-only views this app uses to read kmu-cms data. They live in this app's database and
 * select from the CMS database on the same MySQL server, so the CMS reader user only needs SELECT
 * on these views (see `php artisan cms:reader-sql`).
 *
 * Used by the create_cms_views migration and by the reader-user command, so both always agree.
 */
final class CmsViews
{
    /**
     * @return array<string, string> view name => SELECT statement
     */
    public static function definitions(string $sourceDatabase): array
    {
        $db = '`'.str_replace('`', '``', $sourceDatabase).'`';

        return [
            'v_cms_staff' => "SELECT s.id, s.employee_id, s.name, s.surname, s.email, s.is_active, s.branch_id FROM {$db}.staff s",

            'v_cms_staff_roles' => "SELECT sr.staff_id, sr.role_id, r.name AS role_name, r.is_superadmin
                FROM {$db}.staff_roles sr JOIN {$db}.roles r ON r.id = sr.role_id",

            'v_cms_roles' => "SELECT r.id, r.name, r.is_superadmin FROM {$db}.roles r",

            // Ticked checkboxes of the "Question Bank & Exams" permission group (Roles → Assign Permission).
            'v_cms_role_permissions' => "SELECT rp.role_id, pc.short_code AS category, rp.can_view, rp.can_add, rp.can_edit, rp.can_delete
                FROM {$db}.roles_permissions rp
                JOIN {$db}.permission_category pc ON pc.id = rp.perm_cat_id
                JOIN {$db}.permission_group pg ON pg.id = pc.perm_group_id AND pg.short_code = 'qbank_exams'",

            'v_cms_permission_categories' => "SELECT pc.short_code AS category, pc.name, pc.enable_view, pc.enable_add, pc.enable_edit, pc.enable_delete
                FROM {$db}.permission_category pc
                JOIN {$db}.permission_group pg ON pg.id = pc.perm_group_id AND pg.short_code = 'qbank_exams'",

            // Exam access limits (CMS Question Bank & Exams → Exam Access).
            'v_cms_staff_exam_scopes' => "SELECT x.staff_id, x.scope_type, x.scope_id FROM {$db}.acad_staff_exam_scopes x",

            // Who teaches what (CMS Academics → Assign Program Teacher). The campus comes from the
            // programme: class_teacher carries none of its own, and without it a programme of the
            // same name on another campus would be read as the same one.
            'v_cms_teaching_assignments' => "SELECT ct.staff_id, ct.class_id AS programme_id, ct.session_id AS intake_id,
                       ct.section_id, c.branch_id
                FROM {$db}.class_teacher ct JOIN {$db}.classes c ON c.id = ct.class_id",

            'v_cms_exam_settings' => "SELECT s.kmu_assess_mfa_enabled, s.kmu_assess_reviews_required, s.kmu_assess_review_days,
                       s.kmu_assess_auto_activate, s.kmu_assess_reviewer_anonymous, s.kmu_assess_reviewer_accept_stores, s.kmu_assess_academic_review
                FROM {$db}.sch_settings s ORDER BY s.id LIMIT 1",

            // Extra branches a staff member may work in (CMS Settings → Staff); inactive branches excluded.
            'v_cms_staff_branches' => "SELECT sb.staff_id, sb.branch_id
                FROM {$db}.staff_accessible_branches sb JOIN {$db}.branches b ON b.id = sb.branch_id AND b.status = 'active'",

            'v_cms_branches' => "SELECT b.id, b.branch_name, b.branch_code, b.status FROM {$db}.branches b",

            'v_cms_intakes' => "SELECT s.id, s.branch_id, s.academic_year_id, s.session AS name, s.start_date, s.end_date FROM {$db}.sessions s",

            'v_cms_programmes' => "SELECT c.id, c.branch_id, c.education_type_id, c.class AS name, p.code, p.calendar_type, p.structure_type, p.duration_years, p.is_active
                FROM {$db}.classes c JOIN {$db}.acad_programme_profiles p ON p.class_id = c.id",

            'v_cms_professionals' => "SELECT pr.id, c.branch_id, pr.class_id AS programme_id, pr.code, pr.name, pr.sequence, pr.is_active
                FROM {$db}.acad_professionals pr JOIN {$db}.classes c ON c.id = pr.class_id",

            // Semesters are numbered straight through a programme (First Professional: I, II; Second:
            // III, IV ...) in the order of its years and their terms, as KMU names them. section_name is the
            // campus semester row the term links to (attendance and the timetable use it).
            'v_cms_professional_terms' => "SELECT t.id, t.professional_id, t.section_id, s.section AS section_name,
                    ROW_NUMBER() OVER (PARTITION BY pr.class_id ORDER BY pr.sequence, t.sequence) AS semester_no,
                    CONCAT('Semester ', ELT(ROW_NUMBER() OVER (PARTITION BY pr.class_id ORDER BY pr.sequence, t.sequence),
                        'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X',
                        'XI', 'XII', 'XIII', 'XIV', 'XV', 'XVI', 'XVII', 'XVIII', 'XIX', 'XX')) AS name,
                    t.sequence, t.is_active
                FROM {$db}.acad_professional_terms t
                JOIN {$db}.acad_professionals pr ON pr.id = t.professional_id
                JOIN {$db}.sections s ON s.id = t.section_id",

            'v_cms_level_templates' => "SELECT lt.class_id AS programme_id, lt.depth, ty.code AS level_code, ty.name AS level_name, lt.allow_questions
                FROM {$db}.acad_level_templates lt JOIN {$db}.acad_level_types ty ON ty.id = lt.level_type_id",

            'v_cms_disciplines' => "SELECT d.id, d.code, d.name, d.is_active FROM {$db}.acad_disciplines d",

            'v_cms_courses' => "SELECT co.id, c.branch_id, co.course_code, co.title, co.class_id AS programme_id, co.professional_id, co.term_id, co.course_kind,
                    co.credit_hours, co.status, co.valid_from, co.valid_to, co.supersedes_course_id
                FROM {$db}.acad_courses co JOIN {$db}.classes c ON c.id = co.class_id",

            'v_cms_curriculum_nodes' => "SELECT n.id, n.course_id, n.parent_id, ty.code AS level_code, n.discipline_id, n.code, n.name, n.path,
                    n.depth, n.sort_order, n.is_active, COALESCE(lt.allow_questions, 0) AS allow_questions
                FROM {$db}.acad_curriculum_nodes n
                JOIN {$db}.acad_level_types ty ON ty.id = n.level_type_id
                JOIN {$db}.acad_courses co ON co.id = n.course_id
                LEFT JOIN {$db}.acad_level_templates lt ON lt.class_id = co.class_id AND lt.depth = n.depth",

            'v_cms_exam_types' => "SELECT e.id, e.code, e.name, e.calendar_type, e.is_resit, e.sort_order, e.is_active FROM {$db}.acad_exam_types e",
        ];
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::definitions('kmu-cms'));
    }
}
