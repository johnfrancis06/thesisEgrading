<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

// Simulate add_grade_item API
$data = [
    'category_id' => 113,
    'label' => 'Test Quiz',
    'max_score' => 50,
    'sort_order' => 5
];

// Get category config_id from grade_category
$catResult = $db->query("SELECT config_id FROM grade_category WHERE id = 113")->fetch_assoc();
$configId = $catResult ? intval($catResult['config_id']) : null;

$sort = intval($data['sort_order'] ?? 0);
$configItemId = null;

// First create entry in grade_item_config if category has config_id
if ($configId) {
    $stmt2 = $db->prepare("INSERT INTO grade_item_config (category_config_id, label, max_score, sort_order, is_active) VALUES (?, ?, ?, ?, 1)");
    $stmt2->bind_param("isdi", $configId, $data['label'], $data['max_score'], $sort);
    if ($stmt2->execute()) {
        $configItemId = $db->insert_id;
        echo "Config item ID: $configItemId\n";
    }
}

// Then insert into grade_item with the config_item_id
$stmt = $db->prepare("INSERT INTO grade_item (grade_category_id, label, max_score, sort_order, item_config_id) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("isdii", $data['category_id'], $data['label'], $data['max_score'], $sort, $configItemId);
$success = $stmt->execute();
$itemId = $db->insert_id;

echo "Success: " . ($success ? "Yes" : "No") . "\n";
echo "Item ID: $itemId\n";

// Verify
if ($itemId) {
    $result = $db->query("SELECT * FROM grade_item WHERE id = $itemId");
    echo "\nGrade item:\n";
    print_r($result->fetch_assoc());
}