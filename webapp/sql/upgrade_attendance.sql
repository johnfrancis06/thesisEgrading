-- Attendance Module Upgrade
-- Run this if you already have the egrading database and want to add the new attendance features

-- Add attendance_late_counts_present to class_section
ALTER TABLE class_section 
    ADD COLUMN IF NOT EXISTS attendance_late_counts_present TINYINT(1) DEFAULT 0 
    AFTER final_weight;

-- Add attendance_weekdays to class_section
ALTER TABLE class_section 
    ADD COLUMN IF NOT EXISTS attendance_weekdays VARCHAR(50) DEFAULT '1,2,3,4,5' 
    AFTER attendance_late_counts_present;

-- Add audit_log table if it doesn't exist
CREATE TABLE IF NOT EXISTS audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    faculty_id INT,
    action VARCHAR(100),
    table_name VARCHAR(100),
    record_id INT,
    old_value JSON,
    new_value JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (faculty_id) REFERENCES faculty(id),
    INDEX idx_created (created_at)
);
