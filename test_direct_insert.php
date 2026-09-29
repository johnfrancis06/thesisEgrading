<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

// Check if grade_category 113 exists
$result = $db->query("SELECT * FROM grade_category WHERE id = 113");
echo "Category 113:\n";
print_r($result->fetch_assoc());

// Try direct insert
$sql = "INSERT INTO grade_item (grade_category_id, label, max_score, sort_order, item_config_id) VALUES (113, 'Test Quiz', 50, 5, 17)";
if ($db->query($sql)) {
    echo "Direct insert successful, ID: " . $db->insert_id . "\n";
} else {
    echo "Direct insert failed: " . $db->error . "\n";
}