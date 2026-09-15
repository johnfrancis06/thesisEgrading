CREATE TABLE IF NOT EXISTS faculty (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('faculty', 'admin') DEFAULT 'faculty',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email)
);

CREATE TABLE IF NOT EXISTS subject (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(50) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    default_units INT DEFAULT 3,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS class_section (
    id INT PRIMARY KEY AUTO_INCREMENT,
    subject_id INT NOT NULL,
    faculty_id INT NOT NULL,
    course_program VARCHAR(100),
    year_level INT,
    section VARCHAR(50),
    semester INT,
    academic_year VARCHAR(9),
    midterm_weight DECIMAL(3,2) DEFAULT 0.40,
    final_weight DECIMAL(3,2) DEFAULT 0.60,
    attendance_late_counts_present TINYINT(1) DEFAULT 0,
    attendance_weekdays VARCHAR(50) DEFAULT '1,2,3,4,5',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subject_id) REFERENCES subject(id),
    FOREIGN KEY (faculty_id) REFERENCES faculty(id),
    INDEX idx_faculty (faculty_id)
);

CREATE TABLE IF NOT EXISTS student (
    id INT PRIMARY KEY AUTO_INCREMENT,
    class_section_id INT NOT NULL,
    last_name VARCHAR(100),
    first_name VARCHAR(100),
    middle_initial VARCHAR(5),
    student_no VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_section_id) REFERENCES class_section(id),
    INDEX idx_class (class_section_id),
    UNIQUE KEY unique_student_class (class_section_id, student_no)
);

CREATE TABLE IF NOT EXISTS grade_category (
    id INT PRIMARY KEY AUTO_INCREMENT,
    class_section_id INT NOT NULL,
    period ENUM('midterm', 'final') NOT NULL,
    name VARCHAR(100),
    weight_percent DECIMAL(5,2),
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_section_id) REFERENCES class_section(id),
    INDEX idx_class_period (class_section_id, period)
);

CREATE TABLE IF NOT EXISTS grade_item (
    id INT PRIMARY KEY AUTO_INCREMENT,
    grade_category_id INT NOT NULL,
    label VARCHAR(100),
    max_score DECIMAL(10,2),
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (grade_category_id) REFERENCES grade_category(id),
    INDEX idx_category (grade_category_id)
);

CREATE TABLE IF NOT EXISTS grade_score (
    id INT PRIMARY KEY AUTO_INCREMENT,
    grade_item_id INT NOT NULL,
    student_id INT NOT NULL,
    raw_score DECIMAL(10,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (grade_item_id) REFERENCES grade_item(id),
    FOREIGN KEY (student_id) REFERENCES student(id),
    UNIQUE KEY unique_score (grade_item_id, student_id),
    INDEX idx_student (student_id)
);

CREATE TABLE IF NOT EXISTS manual_remark (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    class_section_id INT NOT NULL,
    period ENUM('midterm', 'final', 'overall'),
    text VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES student(id),
    FOREIGN KEY (class_section_id) REFERENCES class_section(id),
    UNIQUE KEY unique_remark (student_id, class_section_id, period)
);

CREATE TABLE IF NOT EXISTS attendance_session (
    id INT PRIMARY KEY AUTO_INCREMENT,
    class_section_id INT NOT NULL,
    date DATE NOT NULL,
    label VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_section_id) REFERENCES class_section(id),
    INDEX idx_class_date (class_section_id, date)
);

CREATE TABLE IF NOT EXISTS attendance_record (
    id INT PRIMARY KEY AUTO_INCREMENT,
    attendance_session_id INT NOT NULL,
    student_id INT NOT NULL,
    status ENUM('present', 'absent', 'late', 'excused') DEFAULT 'absent',
    remarks VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (attendance_session_id) REFERENCES attendance_session(id),
    FOREIGN KEY (student_id) REFERENCES student(id),
    UNIQUE KEY unique_attendance (attendance_session_id, student_id),
    INDEX idx_student (student_id)
);

CREATE TABLE IF NOT EXISTS section_student (
    id INT PRIMARY KEY AUTO_INCREMENT,
    faculty_id INT NOT NULL,
    course_program VARCHAR(100),
    year_level INT,
    section VARCHAR(50),
    academic_year VARCHAR(9),
    last_name VARCHAR(100),
    first_name VARCHAR(100),
    middle_initial VARCHAR(5),
    student_no VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
    INDEX idx_section (year_level, section, academic_year),
    INDEX idx_student_no (student_no),
    UNIQUE KEY unique_faculty_section_student (faculty_id, course_program, year_level, section, academic_year, student_no)
);

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

CREATE TABLE IF NOT EXISTS grade_category_template (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    default_weight DECIMAL(5,2) DEFAULT 0,
    default_max_score DECIMAL(10,2) DEFAULT 100,
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS grade_category_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    class_section_id INT NOT NULL,
    period ENUM('midterm', 'final') NOT NULL,
    template_id INT,
    custom_name VARCHAR(100),
    weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    perfect_score DECIMAL(10,2) NOT NULL DEFAULT 0,
    item_count INT DEFAULT 1,
    sort_order INT DEFAULT 0,
    is_visible BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (class_section_id) REFERENCES class_section(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES grade_category_template(id),
    UNIQUE KEY unique_class_period_config (class_section_id, period, template_id),
    INDEX idx_class_period_config (class_section_id, period)
);

CREATE TABLE IF NOT EXISTS grade_item_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_config_id INT NOT NULL,
    label VARCHAR(50) NOT NULL,
    max_score DECIMAL(10,2) NOT NULL DEFAULT 0,
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_config_id) REFERENCES grade_category_config(id) ON DELETE CASCADE,
    INDEX idx_category_config (category_config_id)
);

ALTER TABLE grade_category
    ADD COLUMN IF NOT EXISTS config_id INT,
    ADD COLUMN IF NOT EXISTS period ENUM('midterm', 'final') DEFAULT 'midterm',
    ADD CONSTRAINT fk_grade_category_config FOREIGN KEY (config_id) REFERENCES grade_category_config(id);

ALTER TABLE grade_item
    ADD COLUMN IF NOT EXISTS item_config_id INT,
    ADD CONSTRAINT fk_grade_item_config FOREIGN KEY (item_config_id) REFERENCES grade_item_config(id);

CREATE INDEX idx_grade_category_config_id ON grade_category(config_id);
CREATE INDEX idx_grade_item_config_id ON grade_item(item_config_id);

CREATE TABLE IF NOT EXISTS grade_scale (
    id INT PRIMARY KEY AUTO_INCREMENT,
    min_score DECIMAL(5,2) NOT NULL UNIQUE,
    grade_point DECIMAL(3,2) NOT NULL
);

CREATE TABLE IF NOT EXISTS grade_component_perfect_score (
    id INT PRIMARY KEY AUTO_INCREMENT,
    class_section_id INT NOT NULL,
    period ENUM('midterm', 'final') NOT NULL,
    component_type ENUM('class_participation', 'problem_set', 'quizzes', 'periodical_exam') NOT NULL,
    perfect_score DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (class_section_id) REFERENCES class_section(id),
    UNIQUE KEY unique_class_period_component (class_section_id, period, component_type),
    INDEX idx_component_class_period (class_section_id, period)
);

INSERT INTO grade_category_template
    (name, description, default_weight, default_max_score, sort_order)
VALUES
    ('Class Participation', 'Class participation activities', 20, 100, 1),
    ('Problem Set', 'Problem sets and assignments', 20, 50, 2),
    ('Quizzes', 'Short quizzes and assessments', 30, 100, 3),
    ('Periodical Exam', 'Major periodical examination', 30, 100, 4)
ON DUPLICATE KEY UPDATE
    description = VALUES(description),
    default_weight = VALUES(default_weight),
    default_max_score = VALUES(default_max_score),
    sort_order = VALUES(sort_order);

INSERT INTO grade_scale (min_score, grade_point) VALUES
    (0, 5.00),
    (75, 3.00),
    (78, 2.75),
    (81, 2.50),
    (84, 2.25),
    (87, 2.00),
    (90, 1.75),
    (93, 1.50),
    (96, 1.25),
    (99, 1.00)
ON DUPLICATE KEY UPDATE grade_point = VALUES(grade_point);
