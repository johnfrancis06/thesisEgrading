<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

$itemId = 266;

// Test step 4 directly
echo "Step 4: Clear grade_category_id\n";
$stmt = $db->prepare("UPDATE grade_item SET grade_category_id = NULL WHERE id = ?");
$stmt->bind_param("i", $itemId);
if ($stmt->execute()) {
    echo "Success: " . $stmt->affected_rows . " rows\n";
} else {
    echo "Error: " . $stmt->error . "\n";
}

echo "\nStep 5: Delete category\n";
$configId = 17;
if ($db->query("DELETE FROM grade_category WHERE config_id = $configId")) {
    echo "Success: " . $db->affected_rows . " rows\n";
} else {
    echo "Error: " . $db->error . "\n";
}

// Check state
echo "\n--- After ---\n";
$result = $db->query("SELECT * FROM grade_category WHERE config_id = $configId");
echo "Categories with config_id=$configId: " . $result->num_rows . "\n";

$result2 = $db->query("SELECT * FROM grade_item WHERE id = $itemId");
echo "Grade item $itemId: ";
if ($row = $result2->fetch_assoc()) {
    print_r($row);
} else {
    echo "DELETED\n";
}