<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

// Check the current state
$configId = 17;

$result = $db->query("SELECT * FROM grade_category WHERE config_id = $configId");
echo "Categories with config_id=$configId:\n";
while ($row = $result->fetch_assoc()) {
    print_r($row);
}

$result2 = $db->query("SELECT * FROM grade_item WHERE item_config_id IN (SELECT id FROM grade_item_config WHERE category_config_id = $configId)");
echo "\nItems with config from this category:\n";
while ($row = $result2->fetch_assoc()) {
    print_r($row);
}