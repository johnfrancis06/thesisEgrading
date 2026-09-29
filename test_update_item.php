<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

// Test update_grade_item API
$data = [
    'id' => 266, // Q1 item
    'label' => 'Q1 Updated',
    'max_score' => 100,
    'sort_order' => 0
];

// First check current state
$result = $db->query("SELECT * FROM grade_item WHERE id = 266");
$item = $result->fetch_assoc();
echo "Before update:\n";
print_r($item);

$result2 = $db->query("SELECT * FROM grade_item_config WHERE id = 26");
$config = $result2->fetch_assoc();
print_r($config);

// Now update
$stmt = $db->prepare("UPDATE grade_item SET label = ?, max_score = ?, sort_order = ? WHERE id = ?");
$stmt->bind_param("sdii", $data['label'], $data['max_score'], $data['sort_order'], $data['id']);
$stmt->execute();

// Also update grade_item_config
if ($item['item_config_id']) {
    $configId = intval($item['item_config_id']);
    $stmt2 = $db->prepare("UPDATE grade_item_config SET label = ?, max_score = ?, sort_order = ? WHERE id = ?");
    $stmt2->bind_param("sdii", $data['label'], $data['max_score'], $data['sort_order'], $configId);
    $stmt2->execute();
}

// Check after update
$result3 = $db->query("SELECT * FROM grade_item WHERE id = 266");
$item3 = $result3->fetch_assoc();
echo "\nAfter update:\n";
print_r($item3);

$result4 = $db->query("SELECT * FROM grade_item_config WHERE id = 26");
$config4 = $result4->fetch_assoc();
print_r($config4);