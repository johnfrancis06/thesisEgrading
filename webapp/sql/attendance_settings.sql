-- Attendance settings: per-date holiday/seminar marking.
--
-- Run once against the `egrading` database. The statement is guarded so the
-- file can be re-run safely.

-- Marks a single date as a class meeting, a holiday, or a seminar. The holiday or
-- seminar name itself is stored in the existing attendance_session.label column.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'attendance_session'
      AND COLUMN_NAME  = 'session_type'
);

SET @sql := IF(@col_exists > 0,
    'SELECT 1',
    'ALTER TABLE attendance_session
        ADD COLUMN session_type ENUM(''regular'',''holiday'',''seminar'')
            NOT NULL DEFAULT ''regular'' AFTER date');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Marks an attendance day as finished. The teacher only marks the absentees, then
-- presses Save on that day: every student still left unmarked becomes present.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'attendance_session'
      AND COLUMN_NAME  = 'is_completed'
);

SET @sql := IF(@col_exists > 0,
    'SELECT 1',
    'ALTER TABLE attendance_session
        ADD COLUMN is_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER session_type,
        ADD COLUMN completed_at DATETIME NULL AFTER is_completed');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Splits a class into the two periods printed on the class record: the first
-- Attendance Sheet block and the second. Which dates belong to which period is
-- decided by the "2nd period starts" date in the Class Record dialog, which
-- rewrites this column; it only has to exist.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'attendance_session'
      AND COLUMN_NAME  = 'period'
);

SET @sql := IF(@col_exists > 0,
    'SELECT 1',
    'ALTER TABLE attendance_session
        ADD COLUMN period TINYINT(1) NOT NULL DEFAULT 1 AFTER session_type,
        ADD INDEX idx_class_period (class_section_id, period, date)');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;