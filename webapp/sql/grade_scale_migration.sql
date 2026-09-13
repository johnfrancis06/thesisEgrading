-- Grade Scale / Transmutation Table (matches GE-104 spec)
CREATE TABLE IF NOT EXISTS grade_scale (
    id INT PRIMARY KEY AUTO_INCREMENT,
    min_score DECIMAL(5,2) NOT NULL,
    grade_point DECIMAL(3,2) NOT NULL,
    UNIQUE KEY unique_min_score (min_score)
);

-- Seed grade_scale with transmutation table
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

-- Component perfect scores (per class per period)
-- Stores the "highest_possible_total" for each component per class/period
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
    INDEX idx_class_period (class_section_id, period)
);

-- Update class_section to ensure midterm/final weights
ALTER TABLE class_section 
ADD COLUMN IF NOT EXISTS midterm_weight DECIMAL(3,2) DEFAULT 0.40,
ADD COLUMN IF NOT EXISTS final_weight DECIMAL(3,2) DEFAULT 0.60;