<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

// Check grade_item for class 12
$result = $db->query("SELECT gi.*, gc.name as category, gc.period
    FROM grade_item gi
    JOIN grade_category gc ON gi.grade_category_id = gc.id
    WHERE gc.class_section_id = 12 AND gc.name LIKE '%Quiz%'");
while ($row = $result->fetch_assoc()) {
    print_r($row);
}