<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

// Check the current state
$configId = 17;

$result = $db->query("SELECT * FROM grade_category WHERE config_id = $configId");
echo "Categories with config_id=$configId: " . $result->num_rows . "\n";

$result2 = $db->query("SELECT * FROM grade_item WHERE item_config_id = 26");
echo "Grade items with item_config_id=26: " . $result2->num_rows . "\n";
if ($row = $result2->fetch_assoc()) {
    print_r($row);
}

$result3 = $db->query("SELECT * FROM grade_score WHERE grade_item_id = 266 AND student_id = 213");
echo "Grade score for item 266, student 213: ";
if ($row = $result3->fetch_assoc()) {
    echo $row['raw_score'] . "\n";
} else {
    echo "MISSING\n";
}