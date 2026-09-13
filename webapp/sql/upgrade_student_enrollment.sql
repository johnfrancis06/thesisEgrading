-- Allow the same student to be enrolled in multiple classes.
-- Run once on existing databases.
ALTER TABLE student DROP INDEX student_no;
ALTER TABLE student ADD UNIQUE KEY unique_student_class (class_section_id, student_no);