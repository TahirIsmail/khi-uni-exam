-- ---------------------------------------------------------------------------
-- Marking and results — the kmu-assess half
--
-- Every schema change this feature makes on the exam database, and nothing else.
-- It is the SQL of the three migrations below, written out so it can be run by hand
-- where `php artisan migrate` cannot be:
--
--   2026_10_05_000101_add_teaching_and_credit_hour_views
--   2026_10_05_000102_create_grade_scales
--   2026_10_05_000103_add_grade_to_results
--
-- Run it on the exam database (production: u778590962_kmu_assess).
-- It is safe to run twice.
--
-- BEFORE YOU RUN IT, two things:
--
--   1. Run the kmu-cms half first (that repository, docs/sql/marking-and-results.sql).
--      Section 1 below reads a column it adds.
--   2. Replace every __CMS_DATABASE__ in this file with the name of the CMS database
--      (production: u778590962_kmu_cms). The file will not run until you do, which is
--      deliberate — a view pointing at the wrong database fails silently later.
--
-- Running it by hand does not record the migrations in Laravel's `migrations` table.
-- Where artisan is available, use it instead and ignore this file.
-- ---------------------------------------------------------------------------


-- 1. Two read-only views over the CMS ----------------------------------------
--
-- v_cms_teaching_assignments is who teaches which programme in which intake — the CMS
-- screen Academics → Assign Program Teacher. The join to `classes` is what supplies the
-- campus: class_teacher carries no branch_id of its own, and without it a teacher could
-- be read across campuses.
--
-- v_cms_courses already existed; it is replaced here only to add credit_hours, which a
-- semester GPA cannot be worked out without.

CREATE OR REPLACE SQL SECURITY DEFINER VIEW `v_cms_teaching_assignments` AS
SELECT ct.staff_id,
       ct.class_id   AS programme_id,
       ct.session_id AS intake_id,
       ct.section_id,
       c.branch_id
FROM `__CMS_DATABASE__`.class_teacher ct
JOIN `__CMS_DATABASE__`.classes c ON c.id = ct.class_id;

CREATE OR REPLACE SQL SECURITY DEFINER VIEW `v_cms_courses` AS
SELECT co.id,
       c.branch_id,
       co.course_code,
       co.title,
       co.class_id AS programme_id,
       co.professional_id,
       co.term_id,
       co.course_kind,
       co.credit_hours,
       co.status,
       co.valid_from,
       co.valid_to,
       co.supersedes_course_id
FROM `__CMS_DATABASE__`.acad_courses co
JOIN `__CMS_DATABASE__`.classes c ON c.id = co.class_id;

-- The reader account is granted one view at a time, so a new view is invisible to the
-- application until this runs. Without it Marking fails with
-- "SELECT command denied ... for table 'v_cms_teaching_assignments'".
-- `php artisan cms:reader-sql` prints the full set of grants; this is the one that is new.

GRANT SELECT ON `u778590962_kmu_assess`.`v_cms_teaching_assignments` TO 'kmu_cms_reader'@'localhost';
FLUSH PRIVILEGES;


-- 2. The grading scales ------------------------------------------------------
--
-- How a percentage becomes a grade, which is not one answer but two, because KMU runs
-- two kinds of programme:
--
--   - annual (MBBS, BDS) is marked the way PMC asks: 50% passes, a high mark is a
--     distinction, and there are no grade points, because an annual professional result
--     is not a GPA;
--   - semester (DPT) follows HEC's 4.00 scale, where each grade carries the grade point
--     a GPA is worked out from, and D at 50% is the lowest pass.
--
-- A table rather than a setting: a grading scale is the registrar's, it differs per
-- calendar type, and it is read to print a result. There is no screen for it in this
-- phase — the university edits these rows directly.

CREATE TABLE IF NOT EXISTS `exm_grade_scales` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `calendar_type` enum('annual','semester') COLLATE utf8mb4_unicode_ci NOT NULL,
  `min_percentage` decimal(5,2) NOT NULL,
  `grade` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `grade_point` decimal(3,2) DEFAULT NULL COMMENT 'Semester scales only; an annual grade has none',
  `remark` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` smallint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exm_grade_scales_calendar_type_min_percentage_unique` (`calendar_type`,`min_percentage`),
  KEY `exm_grade_scales_calendar_type_sort_order_index` (`calendar_type`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INSERT IGNORE against the unique key, so re-running leaves an edited scale alone.
INSERT IGNORE INTO `exm_grade_scales`
    (`calendar_type`, `min_percentage`, `grade`, `grade_point`, `remark`, `sort_order`, `created_at`, `updated_at`)
VALUES
    ('semester', 85.00, 'A',  4.00, 'Excellent',    1,  NOW(), NOW()),
    ('semester', 80.00, 'A-', 3.70, 'Excellent',    2,  NOW(), NOW()),
    ('semester', 75.00, 'B+', 3.30, 'Very good',    3,  NOW(), NOW()),
    ('semester', 71.00, 'B',  3.00, 'Good',         4,  NOW(), NOW()),
    ('semester', 68.00, 'B-', 2.70, 'Good',         5,  NOW(), NOW()),
    ('semester', 64.00, 'C+', 2.30, 'Satisfactory', 6,  NOW(), NOW()),
    ('semester', 61.00, 'C',  2.00, 'Satisfactory', 7,  NOW(), NOW()),
    ('semester', 58.00, 'C-', 1.70, 'Pass',         8,  NOW(), NOW()),
    ('semester', 54.00, 'D+', 1.30, 'Pass',         9,  NOW(), NOW()),
    ('semester', 50.00, 'D',  1.00, 'Pass',         10, NOW(), NOW()),
    ('semester',  0.00, 'F',  0.00, 'Fail',         11, NOW(), NOW()),
    ('annual',   85.00, 'A',  NULL, 'Distinction',  1,  NOW(), NOW()),
    ('annual',   70.00, 'B',  NULL, 'Pass',         2,  NOW(), NOW()),
    ('annual',   60.00, 'C',  NULL, 'Pass',         3,  NOW(), NOW()),
    ('annual',   50.00, 'D',  NULL, 'Pass',         4,  NOW(), NOW()),
    ('annual',    0.00, 'F',  NULL, 'Fail',         5,  NOW(), NOW());


-- 3. The grade on a result ---------------------------------------------------
--
-- Kept beside the percentage it came from. Like everything else on exm_results these are
-- recomputed rather than frozen — the row is a cache of what the marks say, and
-- App\Domain\Results\Actions\CompileResult writes them whenever it runs.
--
-- grade_point stays null for annual programmes, which have grades but no grade points.

SET @add_grade_columns = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE `exm_results`
            ADD COLUMN `grade` VARCHAR(5) NULL DEFAULT NULL AFTER `percentage`,
            ADD COLUMN `grade_point` DECIMAL(3,2) NULL DEFAULT NULL AFTER `grade`,
            ADD COLUMN `grade_remark` VARCHAR(40) NULL DEFAULT NULL AFTER `grade_point`',
        'DO 0'
    )
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'exm_results'
      AND column_name = 'grade'
);

PREPARE stmt FROM @add_grade_columns;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- 4. Nothing else -------------------------------------------------------------
--
-- The result sheets, the detailed marks certificate and the CSV export all read tables
-- that already exist. The teacher scoping on Marking reads the view in section 1 and
-- nothing more.
--
-- Existing results carry no grade until they are recompiled, which happens the next time
-- a results screen is opened for that examination.
