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

// Test get_component_perfect_scores
$classId = 5;
$period = 'midterm';
$action = 'get_component_perfect_scores';

ob_start();

try {
    if ($action === 'get_component_perfect_scores') {
        $stmt = $db->prepare("SELECT component_type, perfect_score FROM grade_component_perfect_score WHERE class_section_id = ? AND period = ?");
        $stmt->bind_param("is", $classId, $period);
        $stmt->execute();
        $result = $stmt->get_result();
        $scores = [];
        while ($row = $result->fetch_assoc()) {
            $scores[$row['component_type']] = floatval($row['perfect_score']);
        }
        echo json_encode(['success' => true, 'data' => $scores]);
    }
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$output = ob_get_clean();
echo "Output: $output\n";