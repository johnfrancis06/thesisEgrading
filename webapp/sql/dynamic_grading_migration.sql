-- Dynamic Grade Category Structure (replaces fixed 4-component system)
-- Allows teachers to create custom categories with variable items per class/period

-- Grade Categories (e.g., "Class Participation", "Problem Set", "Quizzes", "Projects", "Periodical Exam")
CREATE TABLE IF NOT EXISTS grade_category_template (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    default_weight DECIMAL(5,2) DEFAULT 0,
    default_max_score DECIMAL(10,2) DEFAULT 100,
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Default templates (GE-104 standard)
INSERT INTO grade_category_template (name, description, default_weight, default_max_score, sort_order) VALUES
('Class Participation', 'Class participation activities (recitation, attendance, behavior)', 20, 100, 1),
('Problem Set', 'Problem sets and assignments', 20, 50, 2),
('Quizzes', 'Short quizzes and assessments', 30, 100, 3),
('Periodical Exam', 'Major periodical examination', 30, 100, 4)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Class-specific category configuration (per class per period)
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
    INDEX idx_class_period (class_section_id, period)
);

-- Grade items within each category config (e.g., CP1, CP2, PS1, Q1, Q2, Exam)
CREATE TABLE IF NOT EXISTS grade_item_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_config_id INT NOT NULL,
    label VARCHAR(50) NOT NULL,  -- e.g., "CP1", "CP2", "PS1", "Q1", "Q2"
    max_score DECIMAL(10,2) NOT NULL DEFAULT 0,
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_config_id) REFERENCES grade_category_config(id) ON DELETE CASCADE,
    INDEX idx_category_config (category_config_id)
);

-- Link to existing grade_category and grade_item tables for actual scores
-- grade_category now references grade_category_config
-- grade_item now references grade_item_config

-- Add config references to existing tables
ALTER TABLE grade_category 
ADD COLUMN IF NOT EXISTS config_id INT,
ADD COLUMN IF NOT EXISTS period ENUM('midterm', 'final') DEFAULT 'midterm',
ADD FOREIGN KEY (config_id) REFERENCES grade_category_config(id);

ALTER TABLE grade_item 
ADD COLUMN IF NOT EXISTS item_config_id INT,
ADD FOREIGN KEY (item_config_id) REFERENCES grade_item_config(id);

-- Index for performance
CREATE INDEX idx_grade_category_config ON grade_category(config_id);
CREATE INDEX idx_grade_item_config ON grade_item(item_config_id);