-- ---------------------------------------------------------------------------
-- Human marking, and a result a medical university can issue — the kmu-assess half
--
-- Every schema change this feature makes on the exam database, and nothing else. It is
-- the SQL of the two migrations below, written out so it can be run by hand where
-- `php artisan migrate` cannot be:
--
--   2026_10_06_000101_add_confirmation_to_marking
--   2026_10_06_000102_create_result_components
--
-- Run it on the exam database (production: u778590962_kmu_assess).
-- It is safe to run twice.
--
-- Running it by hand does not record the migrations in Laravel's `migrations` table.
-- Where artisan is available, use it instead and ignore this file.
--
-- The kmu-cms half adds the one new privilege and should be run too, in either order.
-- ---------------------------------------------------------------------------


-- 1. A computer's mark on typed text is a suggestion ------------------------
--
-- Short answers and cloze blanks are scored by matching what the candidate typed against
-- a list of accepted answers. That is fine for a fixed value and wrong for a medical
-- answer, where spelling, word order and abbreviations all vary and a correct answer is
-- marked zero for none of the reasons that matter.
--
-- So the mark is still computed on submission, but it no longer counts until an examiner
-- confirms it. Which types need confirming is a column rather than a list in code, so
-- the university can change its mind about a type without waiting for a release.

SET @add_requires_confirmation = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE `qb_question_types`
            ADD COLUMN `requires_confirmation` TINYINT(1) NOT NULL DEFAULT 0
            COMMENT ''auto-marked, but an examiner must confirm the mark before it counts''
            AFTER `is_manually_marked`',
        'DO 0'
    )
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'qb_question_types'
      AND column_name = 'requires_confirmation'
);

PREPARE stmt FROM @add_requires_confirmation;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `qb_question_types` SET `requires_confirmation` = 1
WHERE `code` IN ('short_answer', 'cloze');

-- An ALTER does not disturb trg_mrk_item_marks_no_update: that trigger is per row, and
-- no row is written here.

SET @add_is_provisional = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE `mrk_item_marks`
            ADD COLUMN `is_provisional` TINYINT(1) NOT NULL DEFAULT 0
            COMMENT ''an auto mark awaiting an examiner''''s confirmation; never final on its own''
            AFTER `marks_awarded`',
        'DO 0'
    )
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'mrk_item_marks'
      AND column_name = 'is_provisional'
);

PREPARE stmt FROM @add_is_provisional;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- 2. The parts a professional result is made of -----------------------------
--
-- A PMC subject result is not one number. It is the written theory paper, the practical
-- or OSPE, the structured viva, and the internal assessment — and the rule is not only
-- 50% overall but 50% in theory and in practical separately, neither making up for the
-- other.
--
-- This module runs the computer-based paper and nothing else, so the paper becomes one
-- component among several and the rest are entered by the department. Every weight is a
-- row here rather than a constant anywhere, because KMU's blueprint differs from subject
-- to subject and changes without asking us.
--
-- An examination with no components behaves exactly as it did before: the paper is the
-- result. Nothing already in use changes under anybody.

CREATE TABLE IF NOT EXISTS `exm_result_components` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `examination_id` bigint unsigned NOT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'theory, ospe, viva, internal — the department''s own names',
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `max_marks` decimal(6,2) NOT NULL,
  `group` enum('theory','practical') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'which half of the subject this counts towards, for the separate pass rule',
  `min_pass_percentage` decimal(5,2) DEFAULT NULL COMMENT 'a bar this component''s group must clear on its own; null means it only adds to the total',
  `source` enum('cbt','entered') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'cbt is this system''s own paper and is never typed in',
  `sort_order` smallint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_exm_result_components` (`examination_id`,`code`),
  KEY `exm_result_components_examination_id_sort_order_index` (`examination_id`,`sort_order`),
  CONSTRAINT `exm_result_components_examination_id_foreign` FOREIGN KEY (`examination_id`) REFERENCES `exm_examinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One mark per candidate per component. Correcting it is an update, recorded in the
-- audit chain — unlike an item mark, which is a sealed examiner decision and never
-- changes.
CREATE TABLE IF NOT EXISTS `exm_component_marks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `component_id` bigint unsigned NOT NULL,
  `candidate_id` bigint unsigned NOT NULL,
  `marks` decimal(6,2) NOT NULL,
  `entered_by` bigint unsigned NOT NULL,
  `entered_at` datetime(3) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_exm_component_marks` (`component_id`,`candidate_id`),
  KEY `exm_component_marks_candidate_id_index` (`candidate_id`),
  CONSTRAINT `exm_component_marks_candidate_id_foreign` FOREIGN KEY (`candidate_id`) REFERENCES `cand_candidates` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `exm_component_marks_component_id_foreign` FOREIGN KEY (`component_id`) REFERENCES `exm_result_components` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 3. Nothing else -----------------------------------------------------------
--
-- Re-marking an item the computer settled needs no schema change at all: mrk_item_marks
-- is append-only, so an examiner's mark is a second row beside the machine's, and the
-- application already prefers it.
--
-- Results already recorded carry no grade and no components until they are recompiled,
-- which happens the next time a results screen is opened for that examination.
