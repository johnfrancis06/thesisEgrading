<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

// Check grade_category_config columns
$result = $db->query("SHOW COLUMNS FROM grade_category_config");
while ($row = $result->fetch_assoc()) {
    print_r($row);
}

// Check grade_category_template columns
$result2 = $db->query("SHOW COLUMNS FROM grade_category_template");
while ($row = $result2->fetch_assoc()) {
    print_r($row);
}