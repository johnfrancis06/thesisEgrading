<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

// First, re-add test data
$itemId = 266;
$studentId = 213;
$score = 95;
$sql = "INSERT INTO grade_score (grade_item_id, student_id, raw_score) VALUES ($itemId, $studentId, $score) 
    ON DUPLICATE KEY UPDATE raw_score = $score";
$db->query($sql);

// Ensure grade_item has grade_category_id
$db->query("UPDATE grade_item SET grade_category_id = 113 WHERE id = $itemId");
$db->query("UPDATE grade_category SET config_id = 17 WHERE id = 113");

// Now call the actual removeSyncedConfigRows function
// require_once 'C:\xampp\htdocs\thesisEgrading\webapp\api\index.php';

// We can't easily call it directly, let me just manually run the queries
$configId = 17;
$idList = "17";

// Step 1: Get scored item IDs
$result = $db->query("SELECT gi.id FROM grade_item gi
    INNER JOIN grade_category gc ON gc.id = gi.grade_category_id
    INNER JOIN grade_score gs ON gs.grade_item_id = gi.id
    WHERE gc.config_id IN ($idList)");

$scoredItemIds = [];
while ($row = $result->fetch_assoc()) {
    $scoredItemIds[] = $row['id'];
}
echo "Scored item IDs: " . json_encode($scoredItemIds) . "\n";

// Step 2: Orphan items with scores (remove item_config_id)
if (!empty($scoredItemIds)) {
    $placeholders = implode(',', array_fill(0, count($scoredItemIds), '?'));
    $stmt = $db->prepare("UPDATE grade_item SET item_config_id = NULL WHERE id IN ($placeholders)");
    $types = str_repeat('i', count($scoredItemIds));
    $stmt->bind_param($types, ...$scoredItemIds);
    $stmt->execute();
    echo "Step 2 - Orphaned items: " . $stmt->affected_rows . "\n";
}

// Step 3: Delete items WITHOUT scores
$result3 = $db->query("DELETE gi FROM grade_item gi
    INNER JOIN grade_category gc ON gc.id = gi.grade_category_id
    LEFT JOIN grade_score gs ON gs.grade_item_id = gi.id
    WHERE gc.config_id IN ($idList) AND gs.id IS NULL");
echo "Step 3 - Deleted items without scores: " . $db->affected_rows . "\n";

// Step 4: Clear grade_category_id for items with scores
if (!empty($scoredItemIds)) {
    $placeholders = implode(',', array_fill(0, count($scoredItemIds), '?'));
    $stmt = $db->prepare("UPDATE grade_item SET grade_category_id = NULL WHERE id IN ($placeholders)");
    $types = str_repeat('i', count($scoredItemIds));
    $stmt->bind_param($types, ...$scoredItemIds);
    $stmt->execute();
    echo "Step 4 - Cleared grade_category_id: " . $stmt->affected_rows . "\n";
}

// Step 5: Delete categories
$result5 = $db->query("DELETE FROM grade_category WHERE config_id IN ($idList)");
echo "Step 5 - Deleted categories: " . $db->affected_rows . "\n";

// Check state
echo "\n--- After ---\n";
$result6 = $db->query("SELECT * FROM grade_category WHERE config_id = $configId");
echo "Categories with config_id=$configId: " . $result6->num_rows . "\n";

$result7 = $db->query("SELECT * FROM grade_item WHERE id = $itemId");
echo "Grade item $itemId: ";
if ($row = $result7->fetch_assoc()) {
    print_r($row);
} else {
    echo "DELETED\n";
}

$result8 = $db->query("SELECT * FROM grade_score WHERE grade_item_id = $itemId AND student_id = $studentId");
echo "Grade score for item $itemId, student $studentId: ";
if ($row = $result8->fetch_assoc()) {
    echo $row['raw_score'] . "\n";
} else {
    echo "MISSING\n";
}