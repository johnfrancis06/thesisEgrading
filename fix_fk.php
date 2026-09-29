<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

// Alter FK to allow ON DELETE SET NULL
// First check if we can alter the column to allow NULL
$sql = "ALTER TABLE grade_item MODIFY grade_category_id INT(11) DEFAULT NULL";
if ($db->query($sql)) {
    echo "Column modified to allow NULL\n";
} else {
    echo "Error modifying column: " . $db->error . "\n";
}

// Drop and re-add FK with ON DELETE SET NULL
$sql2 = "ALTER TABLE grade_item DROP FOREIGN KEY grade_item_ibfk_1";
if ($db->query($sql2)) {
    echo "FK dropped\n";
} else {
    echo "Error dropping FK: " . $db->error . "\n";
}

$sql3 = "ALTER TABLE grade_item 
    ADD CONSTRAINT grade_item_ibfk_1 
    FOREIGN KEY (grade_category_id) REFERENCES grade_category(id) 
    ON DELETE SET NULL";
if ($db->query($sql3)) {
    echo "FK added with ON DELETE SET NULL\n";
} else {
    echo "Error adding FK: " . $db->error . "\n";
}