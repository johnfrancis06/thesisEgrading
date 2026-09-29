<?php
// Test the API response structure
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

$categoryId = 113;
$stmt = $db->prepare("SELECT * FROM grade_item WHERE grade_category_id = ? ORDER BY sort_order");
$stmt->bind_param("i", $categoryId);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo json_encode([
    'success' => true,
    'data' => $items
], JSON_PRETTY_PRINT);