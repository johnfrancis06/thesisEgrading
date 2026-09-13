<?php
require_once 'C:/xampp/htdocs/thesisEgrading/webapp/config/db.php';
$db = Database::getInstance()->getConnection();

// Initialize default category configs for all existing classes
$classes = $db->query("SELECT id FROM class_section")->fetch_all(MYSQLI_ASSOC);

foreach ($classes as $class) {
    $classId = $class['id'];
    
    foreach (['midterm', 'final'] as $period) {
        // Check if config already exists
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM grade_category_config WHERE class_section_id = ? AND period = ?");
        $stmt->bind_param("is", $classId, $period);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result['cnt'] == 0) {
            // Get template IDs
            $templates = $db->query("SELECT id, name, default_weight, default_max_score, sort_order FROM grade_category_template WHERE is_active = TRUE ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
            
            $sortOrder = 0;
            foreach ($templates as $template) {
$stmt = $db->prepare("
                INSERT INTO grade_category_config (class_section_id, period, template_id, custom_name, weight_percent, perfect_score, item_count, sort_order, is_visible)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $customName = $template['name'];
            $weight = $template['default_weight'];
            $perfectScore = $template['default_max_score'];
            $itemCount = ($template['name'] === 'Class Participation' && $period === 'final') ? 4 : (($template['name'] === 'Class Participation' && $period === 'midterm') ? 2 : 1);
            $isVisible = true;
            
            $stmt->bind_param("isssddiii", $classId, $period, $template['id'], $customName, $weight, $perfectScore, $itemCount, $sortOrder, $isVisible);
                $stmt->execute();
                $configId = $db->insert_id;
                
                // Create default items
                $itemLabels = [];
                if ($template['name'] === 'Class Participation') {
                    $itemLabels = $period === 'final' ? ['CP1', 'CP2', 'CP3', 'CP4'] : ['CP1', 'CP2'];
                } elseif ($template['name'] === 'Problem Set') {
                    $itemLabels = ['PS1'];
                } elseif ($template['name'] === 'Quizzes') {
                    $itemLabels = ['Q1', 'Q2'];
                } elseif ($template['name'] === 'Periodical Exam') {
                    $itemLabels = ['Exam'];
                }
                
                $itemSort = 0;
                $maxPerItem = count($itemLabels) > 0 ? round($perfectScore / count($itemLabels), 2) : $perfectScore;
                foreach ($itemLabels as $label) {
                    $itemStmt = $db->prepare("
                        INSERT INTO grade_item_config (category_config_id, label, max_score, sort_order)
                        VALUES (?, ?, ?, ?)
                    ");
                    $itemStmt->bind_param("isdi", $configId, $label, $maxPerItem, $itemSort);
                    $itemStmt->execute();
                    $itemSort++;
                }
                
                $sortOrder++;
            }
            
            // Sync to grade_category and grade_item
            syncGradeTables($db, $classId, $period);
            echo "Initialized $period categories for class $classId\n";
        }
    }
}

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
            $stmt = $db->prepare("DELETE FROM grade_item WHERE grade_category_id = ? AND item_config_id NOT IN ($placeholders)");
            $stmt->bind_param("i$types", ...$params);
            $stmt->execute();
        } else {
            $db->query("DELETE FROM grade_item WHERE grade_category_id = $categoryId");
        }
    }
    
    $existingConfigIds = array_column($configs, 'id');
    if (!empty($existingConfigIds)) {
        $placeholders = implode(',', array_fill(0, count($existingConfigIds), '?'));
        $types = str_repeat('i', count($existingConfigIds));
        $params = array_merge([$classId, $period], $existingConfigIds);
        $stmt = $db->prepare("DELETE FROM grade_category WHERE class_section_id = ? AND period = ? AND config_id NOT IN ($placeholders)");
        $stmt->bind_param("is$types", ...$params);
        $stmt->execute();
    } else {
        $stmt = $db->prepare("DELETE FROM grade_category WHERE class_section_id = ? AND period = ?");
        $stmt->bind_param("is", $classId, $period);
        $stmt->execute();
    }
}

echo "Initialization complete\n";