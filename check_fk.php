<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

// Check FK constraints
$result = $db->query("SHOW CREATE TABLE grade_item");
$row = $result->fetch_assoc();
echo $row['Create Table'] . "\n";

$result2 = $db->query("SHOW CREATE TABLE grade_category");
$row2 = $result2->fetch_assoc();
echo $row2['Create Table'] . "\n";