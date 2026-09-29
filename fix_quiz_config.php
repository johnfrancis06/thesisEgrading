<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

// Update grade_item_config for quiz items by joining with template
$sql = "UPDATE grade_item_config gic
JOIN grade_category_config gcc ON gic.category_config_id = gcc.id
JOIN grade_category_template gct ON gcc.template_id = gct.id
SET gic.max_score = 100
WHERE gct.name LIKE '%Quiz%'";

if ($db->query($sql)) {
    echo "Updated " . $db->affected_rows . " grade_item_config quiz items to max_score = 100\n";
} else {
    echo "Error: " . $db->error . "\n";
}

// Also update by custom_name
$sql2 = "UPDATE grade_item_config gic
JOIN grade_category_config gcc ON gic.category_config_id = gcc.id
SET gic.max_score = 100
WHERE gcc.custom_name LIKE '%Quiz%'";

if ($db->query($sql2)) {
    echo "Updated " . $db->affected_rows . " grade_item_config quiz items (by custom_name) to max_score = 100\n";
} else {
    echo "Error: " . $db->error . "\n";
}

// Verify
$result = $db->query("SELECT gic.*, gcc.custom_name, gcc.period, gct.name as template_name
    FROM grade_item_config gic
    JOIN grade_category_config gcc ON gic.category_config_id = gcc.id
    LEFT JOIN grade_category_template gct ON gcc.template_id = gct.id
    WHERE gcc.custom_name LIKE '%Quiz%' OR gct.name LIKE '%Quiz%'");
while ($row = $result->fetch_assoc()) {
    print_r($row);
}