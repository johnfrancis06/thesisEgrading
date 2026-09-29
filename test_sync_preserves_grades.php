<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

// Test: Add a student grade, then sync, verify it's preserved

$studentId = 213;
$classId = 12;
$itemId = 266; // Q1 in midterm Quizzes

// Add a student grade
$score = 85;
$sql = "INSERT INTO grade_score (grade_item_id, student_id, raw_score) VALUES ($itemId, $studentId, $score) 
    ON DUPLICATE KEY UPDATE raw_score = $score";
$db->query($sql);

echo "Added grade: item=$itemId, student=$studentId, score=$score\n";

// Verify grade exists
$result = $db->query("SELECT * FROM grade_score WHERE grade_item_id = $itemId AND student_id = $studentId");
echo "Grade before sync: " . ($result->num_rows ? "EXISTS (score: " . $result->fetch_assoc()['raw_score'] . ")" : "MISSING") . "\n";

// Now run sync for midterm
function syncGradeTables($db, $classId, $period) {
    $stmt = $db->prepare("SELECT * FROM grade_category_config WHERE class_section_id = ? AND period = ? ORDER BY sort_order");
    $stmt->bind_param("is", $classId, $period);
    $stmt->execute();
    $configs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($configs as $config) {
        $catStmt = $db->prepare("SELECT id FROM grade_category WHERE config_id = ?");
        $catStmt->bind_param("i", $config['id']);
        $catStmt->execute();
        $catResult = $catStmt->get_result()->fetch_assoc();
        
        $categoryName = $config['custom_name'] ?: ($config['template_name'] ?? 'Category');
        
        if ($catResult) {
            $db->query("UPDATE grade_category SET name = '{$db->real_escape_string($categoryName)}', weight_percent = {$config['weight_percent']} WHERE id = {$catResult['id']}");
            $categoryId = $catResult['id'];
        } else {
            $db->query("INSERT INTO grade_category (class_section_id, period, name, weight_percent, config_id, sort_order) VALUES ($classId, '$period', '{$db->real_escape_string($categoryName)}', {$config['weight_percent']}, {$config['id']}, {$config['sort_order']})");
            $categoryId = $db->insert_id;
        }
        
        $itemsStmt = $db->prepare("SELECT * FROM grade_item_config WHERE category_config_id = ? AND is_active = TRUE ORDER BY sort_order");
        $itemsStmt->bind_param("i", $config['id']);
        $itemsStmt->execute();
        $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($items as $item) {
            $itemStmt = $db->prepare("SELECT id FROM grade_item WHERE item_config_id = ?");
            $itemStmt->bind_param("i", $item['id']);
            $itemStmt->execute();
            $itemResult = $itemStmt->get_result()->fetch_assoc();
            
            if ($itemResult) {
                $db->query("UPDATE grade_item SET label = '{$db->real_escape_string($item['label'])}', max_score = {$item['max_score']} WHERE id = {$itemResult['id']}");
            } else {
                $db->query("INSERT INTO grade_item (grade_category_id, label, max_score, item_config_id, sort_order) VALUES ($categoryId, '{$db->real_escape_string($item['label'])}', {$item['max_score']}, {$item['id']}, {$item['sort_order']})");
            }
        }
        
        $existingItemConfigIds = array_column($items, 'id');
        if (!empty($existingItemConfigIds)) {
            $placeholders = implode(',', array_fill(0, count($existingItemConfigIds), '?'));
            $types = str_repeat('i', count($existingItemConfigIds));
            $params = array_merge([$categoryId], $existingItemConfigIds);
            $stmt = $db->prepare("DELETE gi FROM grade_item gi 
                LEFT JOIN grade_score gs ON gs.grade_item_id = gi.id 
                WHERE gi.grade_category_id = ? 
                AND gi.item_config_id NOT IN ($placeholders) 
                AND gs.id IS NULL");
            $paramStr = "i" . $types;
            $stmt->bind_param($paramStr, ...$params);
            $stmt->execute();
        } else {
            $db->query("DELETE gi FROM grade_item gi 
                LEFT JOIN grade_score gs ON gs.grade_item_id = gi.id 
                WHERE gi.grade_category_id = $categoryId AND gs.id IS NULL");
        }
    }
    
    $existingConfigIds = array_column($configs, 'id');
    if (!empty($existingConfigIds)) {
        $placeholders = implode(',', array_fill(0, count($existingConfigIds), '?'));
        $types = str_repeat('i', count($existingConfigIds));
        $params = array_merge([$classId, $period], $existingConfigIds);
        $stmt = $db->prepare("DELETE FROM grade_category WHERE class_section_id = ? AND period = ? AND config_id NOT IN ($placeholders)");
        $paramStr = "is" . $types;
        $stmt->bind_param($paramStr, ...$params);
        $stmt->execute();
    } else {
        $stmt = $db->prepare("DELETE FROM grade_category WHERE class_section_id = ? AND period = ?");
        $stmt->bind_param("is", $classId, $period);
        $stmt->execute();
    }
}

syncGradeTables($db, $classId, 'midterm');

echo "Sync completed.\n";

// Verify grade still exists
$result2 = $db->query("SELECT * FROM grade_score WHERE grade_item_id = $itemId AND student_id = $studentId");
echo "Grade after sync: " . ($result2->num_rows ? "EXISTS (score: " . $result2->fetch_assoc()['raw_score'] . ")" : "MISSING") . "\n";

// Also check if item still exists
$result3 = $db->query("SELECT * FROM grade_item WHERE id = $itemId");
echo "Grade item after sync: " . ($result3->num_rows ? "EXISTS" : "DELETED") . "\n";