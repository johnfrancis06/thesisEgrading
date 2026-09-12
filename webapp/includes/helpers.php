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
    
    // Calculate category score
    public static function getCategoryScore($items) {
        $rawTotal = 0;
        $maxTotal = 0;
        
        foreach ($items as $item) {
            $rawTotal += floatval($item['raw_score'] ?? 0);
            $maxTotal += floatval($item['max_score'] ?? 0);
        }
        
        if ($maxTotal <= 0) return 0;
        return self::transmute($rawTotal, $maxTotal);
    }
    
    // Calculate period grade
    public static function getPeriodGrade($categories) {
        $weighted = 0;
        $totalWeight = 0;
        
        foreach ($categories as $cat) {
            $weight = floatval($cat['weight_percent'] ?? 0) / 100;
            $weighted += ($cat['score'] ?? 0) * $weight;
            $totalWeight += $weight;
        }
        
        return $totalWeight > 0 ? round($weighted / $totalWeight, 2) : 0;
    }
    
    // Calculate overall grade
    public static function getOverallGrade($midtermGrade, $finalGrade, $mtWeight = 0.4, $fnWeight = 0.6) {
        return round(($midtermGrade * $mtWeight) + ($finalGrade * $fnWeight), 0);
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
?>
