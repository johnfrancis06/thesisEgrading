<?php
require_once 'C:/xampp/htdocs/thesisEgrading/webapp/config/db.php';
$db = Database::getInstance()->getConnection();

// Run migration
$sql = file_get_contents('C:/xampp/htdocs/thesisEgrading/webapp/sql/dynamic_grading_migration.sql');
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