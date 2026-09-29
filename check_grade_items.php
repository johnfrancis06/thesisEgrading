<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

// Check grade_item for midterm Quizzes category (id 113)
$result = $db->query("SELECT * FROM grade_item WHERE grade_category_id = 113");
while ($row = $result->fetch_assoc()) {
    print_r($row);
}

// Check grade_item for final Quizzes category (id 109)
echo "\n---\n";
$result2 = $db->query("SELECT * FROM grade_item WHERE grade_category_id = 109");
while ($row = $result2->fetch_assoc()) {
    print_r($row);
}