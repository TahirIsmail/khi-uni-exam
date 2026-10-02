-- Structure (no data) of the kmu-cms tables that the v_cms_* views read.
-- Regenerate when those tables change (sch_settings is trimmed by hand):
--   mysqldump -uroot --no-data --skip-triggers --skip-comments --compact --set-gtid-purged=OFF kmu-cms <tables> | sed -E 's/ AUTO_INCREMENT=[0-9]+//'
SET FOREIGN_KEY_CHECKS = 0;
CREATE TABLE `staff` (
  `id` int NOT NULL AUTO_INCREMENT,
  `branch_id` int DEFAULT NULL,
  `employee_id` varchar(200) NOT NULL,
  `line_manager` int NOT NULL,
  `lang_id` int NOT NULL,
  `department` int DEFAULT '0',
  `designation` int DEFAULT '0',
  `qualification` varchar(200) NOT NULL,
  `work_exp` varchar(200) NOT NULL,
  `name` varchar(200) NOT NULL,
  `surname` varchar(200) NOT NULL,
  `father_name` varchar(200) NOT NULL,
  `cnic` varchar(200) NOT NULL,
  `contact_no` varchar(200) NOT NULL,
  `emergency_contact_no` varchar(200) NOT NULL,
  `email` varchar(200) NOT NULL,
  `primary_email` varchar(100) DEFAULT NULL,
  `hiring_application_id` int DEFAULT NULL,
  `dob` date NOT NULL,
  `marital_status` varchar(100) NOT NULL,
  `date_of_joining` date NOT NULL,
  `date_of_leaving` date DEFAULT NULL,
  `local_address` varchar(300) NOT NULL,
  `permanent_address` varchar(200) NOT NULL,
  `note` varchar(200) NOT NULL,
  `image` varchar(200) DEFAULT NULL,
  `password` varchar(250) NOT NULL,
  `gender` varchar(50) NOT NULL,
  `account_title` varchar(200) NOT NULL,
  `bank_account_no` varchar(200) NOT NULL,
  `bank_name` varchar(200) NOT NULL,
  `ifsc_code` varchar(200) NOT NULL,
  `bank_branch` varchar(100) NOT NULL,
  `payscale` varchar(200) DEFAULT NULL,
  `basic_salary` varchar(200) NOT NULL,
  `gross_salary` decimal(10,2) DEFAULT NULL,
  `fuel_allowance` decimal(10,2) DEFAULT NULL,
  `epf_no` varchar(200) DEFAULT NULL,
  `contract_type` varchar(100) DEFAULT NULL,
  `shift` varchar(100) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `facebook` varchar(200) DEFAULT NULL,
  `twitter` varchar(200) DEFAULT NULL,
  `linkedin` varchar(200) DEFAULT NULL,
  `instagram` varchar(200) DEFAULT NULL,
  `resume` varchar(200) DEFAULT NULL,
  `joining_letter` varchar(200) DEFAULT NULL,
  `resignation_letter` varchar(200) DEFAULT NULL,
  `other_document_name` varchar(200) DEFAULT NULL,
  `other_document_file` varchar(200) DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `is_active` int NOT NULL,
  `verification_code` varchar(100) NOT NULL,
  `disable_at` date DEFAULT NULL,
  `inactive_reason` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_id` (`employee_id`),
  KEY `idx_hiring_application` (`hiring_application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
CREATE TABLE `staff_roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `role_id` int DEFAULT NULL,
  `staff_id` int DEFAULT NULL,
  `is_active` int DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_at` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `role_id` (`role_id`),
  KEY `staff_id` (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
CREATE TABLE `roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `dashboard_view` varchar(255) DEFAULT 'admission_department' COMMENT 'Dashboard file name to load for this role',
  `slug` varchar(150) DEFAULT NULL,
  `is_active` int DEFAULT '0',
  `is_system` int NOT NULL DEFAULT '0',
  `is_superadmin` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_at` date DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
CREATE TABLE `staff_accessible_branches` (
  `id` int NOT NULL AUTO_INCREMENT,
  `staff_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_staff_branch` (`staff_id`,`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `branches` (
  `id` int NOT NULL AUTO_INCREMENT,
  `branch_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `branch_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `principal_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `established_date` date DEFAULT NULL,
  `branch_logo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'active',
  `has_student_types` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `admission_email_body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `admission_contact_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `admission_default_conditions` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `admission_sender_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `branch_code` (`branch_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `branch_id` int DEFAULT NULL,
  `academic_year_id` int DEFAULT NULL,
  `session` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sessions_branch` (`branch_id`),
  KEY `idx_sessions_academic_year` (`academic_year_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `subjects` (
  `id` int NOT NULL AUTO_INCREMENT,
  `branch_id` int DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `program` int DEFAULT NULL,
  `code` varchar(100) NOT NULL,
  `type` varchar(100) NOT NULL,
  `is_active` varchar(255) DEFAULT 'no',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_subjects_branch_id` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
CREATE TABLE `classes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `branch_id` int DEFAULT NULL,
  `education_type_id` int DEFAULT NULL,
  `class` varchar(60) DEFAULT NULL,
  `is_active` varchar(255) DEFAULT 'no',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_classes_branch_class` (`branch_id`,`class`),
  KEY `idx_classes_branch_id` (`branch_id`),
  KEY `idx_classes_education_type` (`education_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
CREATE TABLE `sections` (
  `id` int NOT NULL AUTO_INCREMENT,
  `branch_id` int DEFAULT NULL,
  `education_type_id` int DEFAULT NULL,
  `section` varchar(60) DEFAULT NULL,
  `is_active` varchar(255) DEFAULT 'no',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sections_branch_type_section` (`branch_id`,`education_type_id`,`section`),
  KEY `idx_sections_branch_id` (`branch_id`),
  KEY `idx_sections_education_type` (`education_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
CREATE TABLE `class_teacher` (
  `id` int NOT NULL AUTO_INCREMENT,
  `class_id` int NOT NULL,
  `staff_id` int NOT NULL,
  `section_id` int NOT NULL,
  `session_id` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_class_teacher_staff` (`staff_id`),
  KEY `idx_class_teacher_class_session` (`class_id`,`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
CREATE TABLE `acad_level_types` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'module, course, discipline, topic, subtopic, ...',
  `name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acad_level_types_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `acad_programme_profiles` (
  `class_id` int NOT NULL COMMENT 'Programme = classes.id',
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Short programme code, e.g. MBBS',
  `calendar_type` enum('annual','semester') COLLATE utf8mb4_unicode_ci NOT NULL,
  `structure_type` enum('modular','subject') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'subject' COMMENT 'modular = module > subject (MBBS); subject = courses directly (BDS, DPT)',
  `duration_years` tinyint unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0 = switched off: kept, but nothing new is filed under it',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`class_id`),
  UNIQUE KEY `uq_acad_programme_profiles_code` (`code`),
  CONSTRAINT `fk_acad_programme_profiles_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_acad_programme_profiles_duration` CHECK ((`duration_years` between 1 and 10))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `acad_professionals` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int NOT NULL,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g. PROF-1',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g. First Professional',
  `sequence` tinyint unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acad_professionals_class_code` (`class_id`,`code`),
  UNIQUE KEY `uq_acad_professionals_class_sequence` (`class_id`,`sequence`),
  UNIQUE KEY `uq_acad_professionals_class_name` (`class_id`,`name`),
  UNIQUE KEY `uq_acad_professionals_id_class` (`id`,`class_id`),
  CONSTRAINT `fk_acad_professionals_class` FOREIGN KEY (`class_id`) REFERENCES `acad_programme_profiles` (`class_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `acad_professional_terms` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `professional_id` int unsigned NOT NULL,
  `section_id` int NOT NULL COMMENT 'Term name from the existing semesters table (sections)',
  `sequence` tinyint unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acad_professional_terms_prof_section` (`professional_id`,`section_id`),
  UNIQUE KEY `uq_acad_professional_terms_prof_sequence` (`professional_id`,`sequence`),
  UNIQUE KEY `uq_acad_professional_terms_id_prof` (`id`,`professional_id`),
  KEY `fk_acad_professional_terms_section` (`section_id`),
  CONSTRAINT `fk_acad_professional_terms_professional` FOREIGN KEY (`professional_id`) REFERENCES `acad_professionals` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_acad_professional_terms_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `acad_level_templates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `class_id` int NOT NULL,
  `depth` tinyint unsigned NOT NULL COMMENT '1 = directly under the course',
  `level_type_id` tinyint unsigned NOT NULL,
  `allow_questions` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Questions may be attached at this level',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acad_level_templates_class_depth` (`class_id`,`depth`),
  UNIQUE KEY `uq_acad_level_templates_class_type` (`class_id`,`level_type_id`),
  KEY `fk_acad_level_templates_type` (`level_type_id`),
  CONSTRAINT `fk_acad_level_templates_class` FOREIGN KEY (`class_id`) REFERENCES `acad_programme_profiles` (`class_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_acad_level_templates_type` FOREIGN KEY (`level_type_id`) REFERENCES `acad_level_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_acad_level_templates_depth` CHECK ((`depth` between 1 and 6))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `acad_disciplines` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g. ANA',
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g. Anatomy',
  `subject_id` int DEFAULT NULL COMMENT 'Optional link to the timetable subject',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acad_disciplines_code` (`code`),
  UNIQUE KEY `uq_acad_disciplines_name` (`name`),
  KEY `fk_acad_disciplines_subject` (`subject_id`),
  CONSTRAINT `fk_acad_disciplines_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `acad_courses` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `course_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Course ID, unique across all programmes, never reused',
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `class_id` int NOT NULL,
  `professional_id` int unsigned NOT NULL,
  `term_id` int unsigned DEFAULT NULL COMMENT 'Only for semester programmes',
  `course_kind` enum('module','course') COLLATE utf8mb4_unicode_ci NOT NULL,
  `credit_hours` decimal(4,1) DEFAULT NULL COMMENT 'Semester programmes only; a GPA cannot be worked out without it',
  `subject_id` int DEFAULT NULL COMMENT 'Optional link to the timetable subject',
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` enum('active','inactive','retired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `supersedes_course_id` int unsigned DEFAULT NULL COMMENT 'Course this one replaces after a Course ID change',
  `created_by` int DEFAULT NULL COMMENT 'staff.id',
  `updated_by` int DEFAULT NULL COMMENT 'staff.id',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acad_courses_course_code` (`course_code`),
  KEY `idx_acad_courses_class_prof_status` (`class_id`,`professional_id`,`status`),
  KEY `idx_acad_courses_term` (`term_id`),
  KEY `fk_acad_courses_professional_in_class` (`professional_id`,`class_id`),
  KEY `fk_acad_courses_term_in_professional` (`term_id`,`professional_id`),
  KEY `fk_acad_courses_subject` (`subject_id`),
  KEY `fk_acad_courses_supersedes` (`supersedes_course_id`),
  CONSTRAINT `fk_acad_courses_class` FOREIGN KEY (`class_id`) REFERENCES `acad_programme_profiles` (`class_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_acad_courses_professional_in_class` FOREIGN KEY (`professional_id`, `class_id`) REFERENCES `acad_professionals` (`id`, `class_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_acad_courses_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_acad_courses_supersedes` FOREIGN KEY (`supersedes_course_id`) REFERENCES `acad_courses` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_acad_courses_term_in_professional` FOREIGN KEY (`term_id`, `professional_id`) REFERENCES `acad_professional_terms` (`id`, `professional_id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_acad_courses_code_format` CHECK (regexp_like(`course_code`,_utf8mb4'^[A-Za-z0-9][A-Za-z0-9-]{2,19}$')),
  CONSTRAINT `chk_acad_courses_validity` CHECK (((`valid_to` is null) or (`valid_from` is null) or (`valid_to` >= `valid_from`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `acad_curriculum_nodes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `course_id` int unsigned NOT NULL,
  `parent_id` int unsigned DEFAULT NULL,
  `parent_key` int unsigned GENERATED ALWAYS AS (ifnull(`parent_id`,0)) STORED COMMENT 'Lets the sibling-name key cover top-level nodes',
  `level_type_id` tinyint unsigned NOT NULL,
  `discipline_id` int unsigned DEFAULT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Ancestor ids, e.g. /12/48/, for subtree queries',
  `depth` tinyint unsigned NOT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acad_curriculum_nodes_id_course` (`id`,`course_id`),
  UNIQUE KEY `uq_acad_curriculum_nodes_sibling_name` (`course_id`,`parent_key`,`name`),
  KEY `idx_acad_curriculum_nodes_path` (`path`),
  KEY `idx_acad_curriculum_nodes_discipline` (`discipline_id`),
  KEY `fk_acad_curriculum_nodes_parent_in_course` (`parent_id`,`course_id`),
  KEY `fk_acad_curriculum_nodes_level_type` (`level_type_id`),
  CONSTRAINT `fk_acad_curriculum_nodes_course` FOREIGN KEY (`course_id`) REFERENCES `acad_courses` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_acad_curriculum_nodes_discipline` FOREIGN KEY (`discipline_id`) REFERENCES `acad_disciplines` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_acad_curriculum_nodes_level_type` FOREIGN KEY (`level_type_id`) REFERENCES `acad_level_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_acad_curriculum_nodes_parent_in_course` FOREIGN KEY (`parent_id`, `course_id`) REFERENCES `acad_curriculum_nodes` (`id`, `course_id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_acad_curriculum_nodes_depth` CHECK ((`depth` between 1 and 6))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `acad_exam_types` (
  `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `calendar_type` enum('annual','semester','any') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'any',
  `is_resit` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` tinyint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acad_exam_types_code` (`code`),
  UNIQUE KEY `uq_acad_exam_types_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `permission_group` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `short_code` varchar(100) NOT NULL,
  `is_active` int DEFAULT '0',
  `system` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
CREATE TABLE `permission_category` (
  `id` int NOT NULL AUTO_INCREMENT,
  `perm_group_id` int DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `short_code` varchar(100) DEFAULT NULL,
  `enable_view` int DEFAULT '0',
  `enable_add` int DEFAULT '0',
  `enable_edit` int DEFAULT '0',
  `enable_delete` int DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
CREATE TABLE `roles_permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `role_id` int DEFAULT NULL,
  `perm_cat_id` int DEFAULT NULL,
  `can_view` int DEFAULT NULL,
  `can_add` int DEFAULT NULL,
  `can_edit` int DEFAULT NULL,
  `can_delete` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
CREATE TABLE `acad_staff_exam_scopes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `staff_id` int NOT NULL,
  `scope_type` enum('programme','professional','course') COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope_id` int unsigned NOT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acad_staff_exam_scopes` (`staff_id`,`scope_type`,`scope_id`),
  KEY `idx_acad_staff_exam_scopes_target` (`scope_type`,`scope_id`),
  CONSTRAINT `fk_acad_staff_exam_scopes_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- sch_settings has ~150 columns in kmu-cms; only the ones read here are kept.
CREATE TABLE `sch_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `kmu_assess_mfa_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `kmu_assess_reviews_required` tinyint unsigned NOT NULL DEFAULT '1',
  `kmu_assess_review_days` tinyint unsigned NOT NULL DEFAULT '7',
  `kmu_assess_auto_activate` tinyint(1) NOT NULL DEFAULT '1',
  `kmu_assess_reviewer_accept_stores` tinyint(1) NOT NULL DEFAULT '1',
  `kmu_assess_academic_review` tinyint(1) NOT NULL DEFAULT '1',
  `kmu_assess_reviewer_anonymous` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
-- The two fixed lookups kmu-cms seeds in migration 20260917_0004, seeded here for the same reason
-- production seeds them: they are a closed list, not test data. Without them every test that needs
-- one inserts it again — a rolled-back transaction gives the row back but never the auto-increment
-- id, and both tables key on a tinyint, so a long run would exhaust 255 and start failing.
INSERT INTO `acad_level_types` (`code`, `name`) VALUES
  ('module', 'Module'),
  ('course', 'Course'),
  ('discipline', 'Discipline / Subject'),
  ('topic', 'Topic'),
  ('subtopic', 'Subtopic');

INSERT INTO `acad_exam_types` (`code`, `name`, `calendar_type`, `is_resit`, `sort_order`, `is_active`) VALUES
  ('annual', 'Annual', 'annual', 0, 1, 1),
  ('supplementary', 'Supplementary', 'annual', 1, 2, 1),
  ('regular', 'Regular', 'semester', 0, 3, 1),
  ('retake', 'Retake', 'semester', 1, 4, 1);

SET FOREIGN_KEY_CHECKS = 1;
