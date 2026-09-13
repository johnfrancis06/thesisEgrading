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

// Test get_category_configs
$classId = 5;
$period = 'midterm';
$action = 'get_category_configs';

ob_start();

try {
    if ($action === 'get_category_configs') {
        $stmt = $db->prepare("
            SELECT gcc.*, gct.name as template_name, gct.description
            FROM grade_category_config gcc
            LEFT JOIN grade_category_template gct ON gcc.template_id = gct.id
            WHERE gcc.class_section_id = ? AND gcc.period = ?
            ORDER BY gcc.sort_order
        ");
        $stmt->bind_param("is", $classId, $period);
        $stmt->execute();
        $configs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Get items for each config
        foreach ($configs as &$config) {
            $itemsStmt = $db->prepare("
                SELECT * FROM grade_item_config 
                WHERE category_config_id = ? AND is_active = TRUE
                ORDER BY sort_order
            ");
            $itemsStmt->bind_param("i", $config['id']);
            $itemsStmt->execute();
            $config['items'] = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        
        echo json_encode(['success' => true, 'data' => $configs]);
    }
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$output = ob_get_clean();
echo "Output: $output\n";