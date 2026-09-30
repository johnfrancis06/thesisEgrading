-- Report editor settings and per-cell overrides for the GRADE SHEET preview.
--
-- One key/value table serves both purposes:
--   * report metadata and signature block  -> field_key = 'course_number', 'dean', ...
--   * edited student table cells            -> field_key = 'cell:12:midterm_rating'
-- Every row is scoped to a class via class_section_id.

CREATE TABLE IF NOT EXISTS `report_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_section_id` int(11) NOT NULL,
  `field_key` varchar(191) NOT NULL,
  `field_value` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_report_settings_class_field` (`class_section_id`, `field_key`),
  KEY `idx_report_settings_class` (`class_section_id`),
  CONSTRAINT `report_settings_ibfk_1` FOREIGN KEY (`class_section_id`)
    REFERENCES `class_section` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
