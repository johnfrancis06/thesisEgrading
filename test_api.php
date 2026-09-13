<?php
session_start();
$_SESSION['faculty_id'] = 1;
$_SESSION['faculty_name'] = 'Admin';
$_SESSION['faculty_email'] = 'admin@test.com';
$_SESSION['faculty_role'] = 'faculty';

require_once 'C:/xampp/htdocs/thesisEgrading/webapp/config/db.php';
require_once 'C:/xampp/htdocs/thesisEgrading/webapp/includes/auth.php';
require_once 'C:/xampp/htdocs/thesisEgrading/webapp/includes/helpers.php';

$auth = new Auth();
$faculty_id = $auth->getFacultyId();
$db = Database::getInstance()->getConnection();

echo "Faculty ID: $faculty_id\n";

// Test get_class_grades
$classId = 5;
$action = 'get_class_grades';

ob_start();

try {
    if ($action === 'get_class_grades') {
        $stmt = $db->prepare("SELECT cs.*, s.code, s.title FROM class_section cs JOIN subject s ON cs.subject_id = s.id WHERE cs.id = ? AND cs.faculty_id = ?");
        $stmt->bind_param("ii", $classId, $faculty_id);
        $stmt->execute();
        $class = $stmt->get_result()->fetch_assoc();
        
        if (!$class) {
            echo json_encode(['success' => false, 'message' => "Class not found"]);
            exit;
        }
        
        $students = $db->query("SELECT * FROM student WHERE class_section_id = $classId ORDER BY last_name")->fetch_all(MYSQLI_ASSOC);
        
        $gradesData = [];
        foreach ($students as $student) {
            $grades = GradingHelper::calculateStudentGrades($db, $classId, $student['id']);
            $gradesData[] = array_merge($student, $grades);
        }
        
        echo json_encode(['success' => true, 'data' => [
            'class' => $class,
            'grades' => $gradesData
        ]]);
    }
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$output = ob_get_clean();
echo "Output: $output\n";