<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

// Add status column to student table if not exists
$sql = "ALTER TABLE student ADD COLUMN status ENUM('active','dropped') DEFAULT 'active' AFTER student_no";
if ($db->query($sql)) {
    echo "Status column added successfully\n";
} else {
    echo "Error (may already exist): " . $db->error . "\n";
}