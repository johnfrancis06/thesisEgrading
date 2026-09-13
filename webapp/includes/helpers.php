<?php

class GradingHelper {
    // Transmutation formula: (raw/max)*50+50
    public static function transmute($rawScore, $maxScore) {
        if ($maxScore <= 0) return 0;
        return ($rawScore / $maxScore) * 50 + 50;
    }
    
    // Grade point lookup table (Philippine 1.00-5.00 scale)
    public static function getGradePoint($score) {
        $score = floatval($score);
        if ($score < 75) return 5.00;
        if ($score < 78) return 3.00;
        if ($score < 81) return 2.75;
        if ($score < 84) return 2.50;
        if ($score < 87) return 2.25;
        if ($score < 90) return 2.00;
        if ($score < 93) return 1.75;
        if ($score < 96) return 1.50;
        if ($score < 99) return 1.25;
        return 1.00;
    }
    
    // Get grade point from database grade_scale table
    public static function getGradePointFromDB($db, $score) {
        $score = floatval($score);
        $stmt = $db->prepare("SELECT grade_point FROM grade_scale WHERE min_score <= ? ORDER BY min_score DESC LIMIT 1");
        $stmt->bind_param("d", $score);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ? floatval($result['grade_point']) : self::getGradePoint($score);
    }
    
    // Calculate component score using transmutation formula
    // rawTotal = sum of item scores for this component
    // maxTotal = perfect score (highest possible total) for this component
    public static function calculateComponentScore($rawTotal, $maxTotal) {
        if ($maxTotal <= 0) return 0;
        return self::transmute($rawTotal, $maxTotal);
    }
    
    // Calculate period grade (weighted average of 4 components)
    // Components: Class Participation (20%), Problem Set (20%), Quizzes (30%), Periodical Exam (30%)
    public static function calculatePeriodGrade($componentScores) {
        $weights = [
            'class_participation' => 0.20,
            'problem_set' => 0.20,
            'quizzes' => 0.30,
            'periodical_exam' => 0.30
        ];
        
        $weightedSum = 0;
        foreach ($componentScores as $component => $score) {
            if (isset($weights[$component])) {
                $weightedSum += $score * $weights[$component];
            }
        }
        return round($weightedSum, 2);
    }
    
    // Calculate overall final grade
    // Overall = (Final Period Grade × 0.6) + (Midterm Period Grade × 0.4)
    public static function calculateOverallGrade($midtermGrade, $finalGrade) {
        return round(($finalGrade * 0.6) + ($midtermGrade * 0.4), 0);
    }
    
    // Determine remarks
    public static function getRemarks($gradePoint, $hasScores = true) {
        if ($gradePoint == 5.00) return 'Failed';
        if (!$hasScores) return 'INC';
        return 'Passed';
    }
    
    // Get perfect scores for all 4 components for a class/period
    public static function getComponentPerfectScores($db, $classId, $period) {
        $stmt = $db->prepare("SELECT component_type, perfect_score FROM grade_component_perfect_score WHERE class_section_id = ? AND period = ?");
        $stmt->bind_param("is", $classId, $period);
        $stmt->execute();
        $result = $stmt->get_result();
        $scores = [];
        while ($row = $result->fetch_assoc()) {
            $scores[$row['component_type']] = floatval($row['perfect_score']);
        }
        return $scores;
    }
    
    // Save/update perfect score for a component
    public static function saveComponentPerfectScore($db, $classId, $period, $componentType, $perfectScore) {
        $stmt = $db->prepare("INSERT INTO grade_component_perfect_score (class_section_id, period, component_type, perfect_score) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE perfect_score = VALUES(perfect_score)");
        $stmt->bind_param("issd", $classId, $period, $componentType, $perfectScore);
        return $stmt->execute();
    }
    
    // Calculate all grades for a student in a class
    public static function calculateStudentGrades($db, $classId, $studentId) {
        // Get all raw scores for this student in this class
        $stmt = $db->prepare("
            SELECT gi.id, gi.label, gi.max_score, gi.grade_category_id, 
                   gc.name as category_name, gc.period, gc.weight_percent,
                   gs.raw_score
            FROM grade_item gi
            JOIN grade_category gc ON gi.grade_category_id = gc.id
            LEFT JOIN grade_score gs ON gs.grade_item_id = gi.id AND gs.student_id = ?
            WHERE gc.class_section_id = ?
            ORDER BY gc.period, gc.sort_order, gi.sort_order
        ");
        $stmt->bind_param("ii", $studentId, $classId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        // Organize by period and component
        $periodData = ['midterm' => [], 'final' => []];
        
        while ($row = $result->fetch_assoc()) {
            $period = $row['period'];
            $categoryName = strtolower($row['category_name']);
            
            // Map category name to component type
            $componentType = self::mapCategoryToComponent($categoryName);
            if (!$componentType) continue;
            
            if (!isset($periodData[$period][$componentType])) {
                $periodData[$period][$componentType] = [
                    'items' => [],
                    'raw_total' => 0,
                    'max_total' => 0
                ];
            }
            
            $rawScore = $row['raw_score'] !== null ? floatval($row['raw_score']) : 0;
            $maxScore = floatval($row['max_score']);
            
            $periodData[$period][$componentType]['items'][] = [
                'item_id' => $row['id'],
                'label' => $row['label'],
                'raw_score' => $rawScore,
                'max_score' => $maxScore
            ];
            
            $periodData[$period][$componentType]['raw_total'] += $rawScore;
            $periodData[$period][$componentType]['max_total'] += $maxScore;
        }
        
        // Get perfect scores for this class/period
        $perfectScores = [];
        foreach (['midterm', 'final'] as $period) {
            $perfectScores[$period] = self::getComponentPerfectScores($db, $classId, $period);
        }
        
        // Calculate component scores using perfect scores
        $componentScores = ['midterm' => [], 'final' => []];
        foreach (['midterm', 'final'] as $period) {
            foreach ($periodData[$period] as $component => $data) {
                $perfectScore = $perfectScores[$period][$component] ?? $data['max_total'];
                $componentScores[$period][$component] = self::calculateComponentScore($data['raw_total'], $perfectScore);
            }
            
            // Ensure all 4 components exist
            foreach (['class_participation', 'problem_set', 'quizzes', 'periodical_exam'] as $comp) {
                if (!isset($componentScores[$period][$comp])) {
                    $componentScores[$period][$comp] = 0;
                }
            }
        }
        
        // Calculate period grades
        $midtermGrade = self::calculatePeriodGrade($componentScores['midterm']);
        $finalGrade = self::calculatePeriodGrade($componentScores['final']);
        $overallGrade = self::calculateOverallGrade($midtermGrade, $finalGrade);
        
        // Get grade points
        $midtermGradePoint = self::getGradePointFromDB($db, $midtermGrade);
        $finalGradePoint = self::getGradePointFromDB($db, $finalGrade);
        $overallGradePoint = self::getGradePointFromDB($db, $overallGrade);
        
        // Check if has scores
        $hasMidtermScores = array_sum($componentScores['midterm']) > 0;
        $hasFinalScores = array_sum($componentScores['final']) > 0;
        
        return [
            'midterm' => [
                'grade' => $midtermGrade,
                'grade_point' => $midtermGradePoint,
                'remarks' => self::getRemarks($midtermGradePoint, $hasMidtermScores),
                'components' => $componentScores['midterm']
            ],
            'final' => [
                'grade' => $finalGrade,
                'grade_point' => $finalGradePoint,
                'remarks' => self::getRemarks($finalGradePoint, $hasFinalScores),
                'components' => $componentScores['final']
            ],
            'overall' => [
                'grade' => $overallGrade,
                'grade_point' => $overallGradePoint,
                'remarks' => self::getRemarks($overallGradePoint, $hasMidtermScores || $hasFinalScores)
            ],
            'component_scores' => $componentScores
        ];
    }
    
    // Map category name to component type
    private static function mapCategoryToComponent($categoryName) {
        $name = strtolower($categoryName);
        if (strpos($name, 'standing') !== false || strpos($name, 'participation') !== false || strpos($name, 'class standing') !== false) {
            return 'class_participation';
        }
        if (strpos($name, 'problem') !== false || strpos($name, 'problem set') !== false) {
            return 'problem_set';
        }
        if (strpos($name, 'quiz') !== false) {
            return 'quizzes';
        }
        if (strpos($name, 'exam') !== false || strpos($name, 'periodical') !== false) {
            return 'periodical_exam';
        }
        return null;
    }
}

class AttendanceHelper {
    public static function getStudentAttendanceRate($studentId, $classId, $lateCountsAsPresent = false) {
        global $db;
        
        $sql = "SELECT 
            COUNT(*) as total_sessions,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count,
            SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused_count
            FROM attendance_record ar
            JOIN attendance_session ase ON ar.attendance_session_id = ase.id
            WHERE ar.student_id = ? AND ase.class_section_id = ?";
        
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ii", $studentId, $classId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        $total = intval($result['total_sessions']);
        $present = intval($result['present_count']);
        $late = intval($result['late_count']);
        
        if ($total <= 0) {
            return [
                'total_sessions' => 0,
                'present_count' => 0,
                'absent_count' => 0,
                'late_count' => 0,
                'excused_count' => 0,
                'attendance_rate' => 0
            ];
        }
        
        $effectivePresent = $present + ($lateCountsAsPresent ? $late : 0);
        $rate = round(($effectivePresent / $total) * 100, 1);
        
        return [
            'total_sessions' => $total,
            'present_count' => $present,
            'absent_count' => intval($result['absent_count']),
            'late_count' => $late,
            'excused_count' => intval($result['excused_count']),
            'attendance_rate' => $rate
        ];
    }
    
    public static function getSessionSummary($sessionId) {
        global $db;
        
        $sql = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
            SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused
            FROM attendance_record
            WHERE attendance_session_id = ?";
        
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $sessionId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}

class Validator {
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }
    
    public static function validateCategoryWeights($categories) {
        $total = array_sum(array_column($categories, 'weight_percent'));
        return $total == 100;
    }
    
    public static function sanitizeInput($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

class ResponseAPI {
    public static function success($data = [], $message = "Success", $code = 200) {
        http_response_code($code);
        return json_encode(['success' => true, 'message' => $message, 'data' => $data]);
    }
    
    public static function error($message = "Error", $code = 400) {
        http_response_code($code);
        return json_encode(['success' => false, 'message' => $message]);
    }
}

function logAudit($db, $faculty_id, $action, $table_name, $record_id, $old_value = null, $new_value = null) {
    $stmt = $db->prepare("INSERT INTO audit_log (faculty_id, action, table_name, record_id, old_value, new_value) 
        VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ississ", $faculty_id, $action, $table_name, $record_id, $old_value, $new_value);
    $stmt->execute();
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
?>
