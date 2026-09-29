<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

// Check grade_category table for class 12
$result = $db->query("SELECT * FROM grade_category WHERE class_section_id = 12");
while ($row = $result->fetch_assoc()) {
    print_r($row);
}