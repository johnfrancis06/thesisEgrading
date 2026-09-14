ALTER TABLE section_student ADD COLUMN faculty_id INT NULL AFTER id;
ALTER TABLE section_student ADD INDEX idx_section_student_faculty (faculty_id);
ALTER TABLE section_student ADD CONSTRAINT fk_section_student_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE;