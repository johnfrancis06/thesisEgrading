<?php

// Rows per A4 grading sheet. Measured against the letterhead: the header image,
// course info block and 99mm footer image leave 121mm of the 297mm sheet for the
// table and each row is 6.35mm, so 17 fits exactly. 15 keeps headroom so long
// student names that wrap onto a second line cannot spill onto a new sheet.
if (!defined('GRADING_SHEET_STUDENTS_PER_PAGE')) {
    define('GRADING_SHEET_STUDENTS_PER_PAGE', 15);
}

class GradingHelper {
    // Equivalent score is the raw score expressed as a percentage of the perfect score.
    public static function transmute($rawScore, $maxScore) {
        if ($maxScore <= 0) return 0;
        return ($rawScore / $maxScore) * 100;
    }
    
    // Grade point lookuphhhh table (Philippine 1.00-5.00 scale)
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
    
    // Calculate period grade (weighted average of components that have scores)
    // Only weights components that have at least one scored item
    public static function calculatePeriodGrade($componentScores, $componentDetails, $weights = null) {
        $weights = $weights ?: [
            'class_participation' => 0.20,
            'problem_set' => 0.20,
            'quizzes' => 0.30,
            'periodical_exam' => 0.30
        ];
        
        $weightedSum = 0;
        $totalWeight = 0;
        
        foreach ($componentScores as $component => $score) {
            if (!isset($weights[$component])) continue;
            
            // Check if this component has any scored items
            $details = $componentDetails[$component] ?? null;
            $hasScores = false;
            if ($details && isset($details['items'])) {
                foreach ($details['items'] as $item) {
                    if (($item['has_score'] ?? false) && $item['raw_score'] !== null) {
                        $hasScores = true;
                        break;
                    }
                }
            }
            
            if ($hasScores) {
                $weightedSum += $score * $weights[$component];
                $totalWeight += $weights[$component];
            }
        }
        
        // Normalize by total weight of completed components
        if ($totalWeight > 0) {
            return round($weightedSum / $totalWeight, 2);
        }
        
        return 0;
    }
    
    // Calculate overall final grade
    // If both midterm and final are complete: Overall = (Final × finalWeight) + (Midterm × midtermWeight)
    // If only one period complete: use that period's grade
    public static function calculateOverallGrade($midtermGrade, $finalGrade, $midtermComplete = false, $finalComplete = false, $periodWeights = null) {
        $midtermShare = $periodWeights['midterm'] ?? 0.4;
        $finalShare = $periodWeights['final'] ?? 0.6;

        // Normalise the shares, falling back to 40/60 when they are unset.
        $totalShare = $midtermShare + $finalShare;
        if ($totalShare <= 0) {
            $midtermShare = 0.4;
            $finalShare = 0.6;
        } else {
            $midtermShare = $midtermShare / $totalShare;
            $finalShare = $finalShare / $totalShare;
        }

        if ($midtermComplete && $finalComplete) {
            return round(($finalGrade * $finalShare) + ($midtermGrade * $midtermShare), 0);
        } elseif ($midtermComplete) {
            return round($midtermGrade, 0);
        } elseif ($finalComplete) {
            return round($finalGrade, 0);
        }
        return 0;
    }

    // Read the midterm/final weights configured for a class
    public static function getClassPeriodWeights($db, $classId) {
        $stmt = $db->prepare("SELECT midterm_weight, final_weight FROM class_section WHERE id = ?");
        $stmt->bind_param("i", $classId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return [
            'midterm' => $row ? floatval($row['midterm_weight']) : 0.4,
            'final' => $row ? floatval($row['final_weight']) : 0.6
        ];
    }
    
    // Determine remarks based on grade point and completeness
    // DRP = Dropped student
    // INC = Incomplete requirements
    // Failed = Complete but below 75%
    // Passed = Complete and 75% or above
    public static function getRemarks($gradePoint, $isComplete = true, $isDropped = false) {
        if ($isDropped) return 'DRP';
        if (!$isComplete) return 'INC';
        if ($gradePoint == 5.00) return 'Failed';
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
    
    // Returns the category scope a class must be graded against.
    // A class that has grade_category_config rows is graded from those synced
    // categories only. Legacy grade_category rows left behind by the migration
    // (config_id IS NULL) duplicate the same components, so counting them would
    // inflate max_total, double-count weights and mark every student incomplete.
    private static function getCategoryScope($db, $classId) {
        $check = $db->prepare("SELECT id FROM grade_category_config WHERE class_section_id = ? LIMIT 1");
        $check->bind_param("i", $classId);
        $check->execute();

        if ($check->get_result()->fetch_assoc()) {
            return "gc.config_id IN (SELECT id FROM grade_category_config WHERE class_section_id = ? AND period = gc.period)";
        }

        return "1 = 1";
    }

    // Calculate all grades for a student in a class
    public static function calculateStudentGrades($db, $classId, $studentId) {
        // Get all raw scores for this student in this class
        $scope = self::getCategoryScope($db, $classId);
        $sql = "
            SELECT gi.id, gi.label, gi.max_score, gi.grade_category_id,
                   gc.name as category_name, gc.period, gc.weight_percent,
                   gs.raw_score
            FROM grade_item gi
            JOIN grade_category gc ON gi.grade_category_id = gc.id
            LEFT JOIN grade_score gs ON gs.grade_item_id = gi.id AND gs.student_id = ?
            WHERE gc.class_section_id = ?
              AND $scope
            ORDER BY gc.period, gc.sort_order, gi.sort_order
        ";

        $stmt = $db->prepare($sql);
        if ($scope !== "1 = 1") {
            $stmt->bind_param("iii", $studentId, $classId, $classId);
        } else {
            $stmt->bind_param("ii", $studentId, $classId);
        }
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
            
            $hasScore = $row['raw_score'] !== null;
            $rawScore = $hasScore ? floatval($row['raw_score']) : 0;
            $maxScore = floatval($row['max_score']);
            
            $periodData[$period][$componentType]['items'][] = [
                'item_id' => $row['id'],
                'label' => $row['label'],
                'raw_score' => $rawScore,
                'max_score' => $maxScore,
                'has_score' => $hasScore  // Track if score was explicitly set
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
        
        // Use the configured category weights for each period.
        $periodWeights = ['midterm' => [], 'final' => []];
        $weightSql = "SELECT period, name, weight_percent FROM grade_category
                      WHERE class_section_id = ? AND config_id IS NOT NULL";
        $weightStmt = $db->prepare($weightSql);
        $weightStmt->bind_param("i", $classId);
        $weightStmt->execute();
        foreach ($weightStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $weightRow) {
            $component = self::mapCategoryToComponent(strtolower($weightRow['name']));
            if ($component && isset($periodWeights[$weightRow['period']])) {
                $periodWeights[$weightRow['period']][$component] = floatval($weightRow['weight_percent']) / 100;
            }
        }

        // Categories that exist without a synced config still carry their own weight.
        $legacyStmt = $db->prepare("SELECT period, name, weight_percent FROM grade_category
                                    WHERE class_section_id = ? AND config_id IS NULL");
        $legacyStmt->bind_param("i", $classId);
        $legacyStmt->execute();
        foreach ($legacyStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $weightRow) {
            $component = self::mapCategoryToComponent(strtolower($weightRow['name']));
            if ($component && isset($periodWeights[$weightRow['period']])
                && !isset($periodWeights[$weightRow['period']][$component])) {
                $periodWeights[$weightRow['period']][$component] = floatval($weightRow['weight_percent']) / 100;
            }
        }

        // Period weights for the overall grade come from the class, not hardcoded values.
        $classWeights = self::getClassPeriodWeights($db, $classId);

        // Get completeness for each period
        $completeness = self::checkCompleteness($periodData, $perfectScores);
        $overallCompleteness = self::getOverallCompleteness($completeness);
        
        // Check if student is dropped
        $stmt = $db->prepare("SELECT status FROM student WHERE id = ?");
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $studentStatus = $stmt->get_result()->fetch_assoc();
        $isDropped = ($studentStatus && $studentStatus['status'] === 'dropped');

        // Calculate period grades using completeness
        $midtermComplete = $overallCompleteness['midterm']['complete'];
        $finalComplete = $overallCompleteness['final']['complete'];
        
        $midtermGrade = self::calculatePeriodGrade($componentScores['midterm'], $periodData['midterm'], $periodWeights['midterm']);
        $finalGrade = self::calculatePeriodGrade($componentScores['final'], $periodData['final'], $periodWeights['final']);
        $overallGrade = self::calculateOverallGrade($midtermGrade, $finalGrade, $midtermComplete, $finalComplete, $classWeights);
        
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
                'remarks' => self::getRemarks($midtermGradePoint, $midtermComplete, $isDropped),
                'components' => $componentScores['midterm'],
                'component_details' => $periodData['midterm'],
                'complete' => $midtermComplete
            ],
            'final' => [
                'grade' => $finalGrade,
                'grade_point' => $finalGradePoint,
                'remarks' => self::getRemarks($finalGradePoint, $finalComplete, $isDropped),
                'components' => $componentScores['final'],
                'component_details' => $periodData['final'],
                'complete' => $finalComplete
            ],
            'overall' => [
                'grade' => $overallGrade,
                'grade_point' => $overallGradePoint,
                'remarks' => self::getRemarks($overallGradePoint, $overallCompleteness['overall'], $isDropped),
                'complete' => $overallCompleteness['overall']
            ],
            'component_scores' => $componentScores,
            'completeness' => $completeness,
            'is_dropped' => $isDropped
        ];
    }
    
    // Check completeness of grades for each component
    public static function checkCompleteness($periodData, $perfectScores) {
        $completeness = ['midterm' => [], 'final' => []];
        $requiredComponents = ['class_participation', 'problem_set', 'quizzes', 'periodical_exam'];
        
        foreach (['midterm', 'final'] as $period) {
            foreach ($requiredComponents as $component) {
                $data = $periodData[$period][$component] ?? null;
                
                if (!$data) {
                    $completeness[$period][$component] = [
                        'complete' => false,
                        'missing_items' => ['No items configured'],
                        'configured' => false
                    ];
                    continue;
                }
                
                $items = $data['items'] ?? [];
                $missingItems = [];
                $hasAnyScore = false;
                
                foreach ($items as $item) {
                    // Use has_score flag to determine if score was explicitly entered
                    if (($item['has_score'] ?? false) && $item['raw_score'] !== null) {
                        $hasAnyScore = true;
                    } else {
                        $missingItems[] = $item['label'] ?? 'Unnamed item';
                    }
                }
                
                $completeness[$period][$component] = [
                    'complete' => count($missingItems) === 0 && count($items) > 0,
                    'missing_items' => $missingItems,
                    'configured' => count($items) > 0,
                    'total_items' => count($items),
                    'scored_items' => count($items) - count($missingItems),
                    'has_any_score' => $hasAnyScore
                ];
            }
        }
        
        return $completeness;
    }
    
    // Get overall completeness summary for a student
    public static function getOverallCompleteness($completeness) {
        $summary = [
            'midterm' => ['complete' => true, 'missing_components' => []],
            'final' => ['complete' => true, 'missing_components' => []],
            'overall' => true
        ];
        
        foreach (['midterm', 'final'] as $period) {
            foreach ($completeness[$period] as $component => $data) {
                if ($data['configured'] && !$data['complete']) {
                    $summary[$period]['complete'] = false;
                    $summary[$period]['missing_components'][] = [
                        'component' => $component,
                        'missing_items' => $data['missing_items'],
                        'scored' => $data['scored_items'],
                        'total' => $data['total_items']
                    ];
                }
            }
        }
        
        $summary['overall'] = $summary['midterm']['complete'] && $summary['final']['complete'];
        
        return $summary;
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
        // Keep custom category names addressable by the dynamic grading sheet.
        $customKey = preg_replace('/[^a-z0-9]+/', '_', $name);
        return trim($customKey, '_') ?: null;
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
