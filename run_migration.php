<?php
require_once 'C:/xampp/htdocs/thesisEgrading/webapp/config/db.php';
$db = Database::getInstance()->getConnection();

// Initialize the database from the single canonical schema.
$sql = file_get_contents('C:/xampp/htdocs/thesisEgrading/webapp/sql/schema.sql');
$statements = array_filter(array_map('trim', explode(';', $sql)));
foreach ($statements as $stmt) {
    if ($stmt) {
        try {
            $db->query($stmt);
            echo 'OK: ' . substr($stmt, 0, 60) . "...\n";
        } catch (Exception $e) {
            echo 'ERROR: ' . $e->getMessage() . "\n";
        }
    }
}
echo "Migration complete\n";