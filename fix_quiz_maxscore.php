<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

// Update all quiz items to max_score = 100 for class 12
$sql = "UPDATE grade_item gi
JOIN grade_category gc ON gi.grade_category_id = gc.id
SET gi.max_score = 100
WHERE gc.class_section_id = 12 AND gc.name LIKE '%Quiz%'";

if ($db->query($sql)) {
    echo "Updated " . $db->affected_rows . " quiz items to max_score = 100\n";
} else {
    echo "Error: " . $db->error . "\n";
}

// Verify
$result = $db->query("SELECT gi.id, gi.label, gi.max_score, gc.name as category 
    FROM grade_item gi JOIN grade_category gc ON gi.grade_category_id = gc.id 
    WHERE gc.class_section_id = 12 AND gc.name LIKE '%Quiz%'");
while ($row = $result->fetch_assoc()) {
    print_r($row);
}