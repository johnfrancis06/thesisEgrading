<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

// Test delete_grade_item - delete the item we just created (id 477)
$itemId = 477;

// Get item_config_id before deleting
$itemConfigResult = $db->query("SELECT item_config_id FROM grade_item WHERE id = $itemId")->fetch_assoc();
$configItemId = $itemConfigResult ? intval($itemConfigResult['item_config_id']) : null;

echo "Config item ID to delete: " . ($configItemId ?? 'NULL') . "\n";

$db->query("DELETE FROM grade_score WHERE grade_item_id = $itemId");
$db->query("DELETE FROM grade_item WHERE id = $itemId");

if ($configItemId) {
    $db->query("DELETE FROM grade_item_config WHERE id = $configItemId");
}

echo "Deleted successfully\n";

// Verify
$result = $db->query("SELECT * FROM grade_item WHERE id = $itemId");
echo "Grade item after delete: " . ($result->num_rows ? "EXISTS" : "DELETED") . "\n";

if ($configItemId) {
    $result2 = $db->query("SELECT * FROM grade_item_config WHERE id = $configItemId");
    echo "Grade item config after delete: " . ($result2->num_rows ? "EXISTS" : "DELETED") . "\n";
}