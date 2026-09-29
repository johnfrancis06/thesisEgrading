<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

// Check all quiz-related items in grade_item_config
$result = $db->query("SELECT gic.*, gcc.custom_name, gcc.period, gct.name as template_name
    FROM grade_item_config gic
    JOIN grade_category_config gcc ON gic.category_config_id = gcc.id
    LEFT JOIN grade_category_template gct ON gcc.template_id = gct.id
    WHERE gic.label LIKE '%Quiz%' OR gct.name LIKE '%Quiz%' OR gcc.custom_name LIKE '%Quiz%'");
while ($row = $result->fetch_assoc()) {
    print_r($row);
}