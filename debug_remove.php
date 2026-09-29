<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

// Debug: manually run the removeSyncedConfigRows logic
$configId = 17;
$idList = "17";

echo "Step 1: Get scored item IDs\n";
$result = $db->query("SELECT gi.id FROM grade_item gi
    INNER JOIN grade_category gc ON gc.id = gi.grade_category_id
    INNER JOIN grade_score gs ON gs.grade_item_id = gi.id
    WHERE gc.config_id IN ($idList)");

$scoredItemIds = [];
while ($row = $result->fetch_assoc()) {
    $scoredItemIds[] = $row['id'];
}
echo "Scored item IDs: " . json_encode($scoredItemIds) . "\n";

echo "Step 2: Orphan items with scores\n";
if (!empty($scoredItemIds)) {
    $placeholders = implode(',', array_fill(0, count($scoredItemIds), '?'));
    $stmt = $db->prepare("UPDATE grade_item SET item_config_id = NULL WHERE id IN ($placeholders)");
    $types = str_repeat('i', count($scoredItemIds));
    $stmt->bind_param($types, ...$scoredItemIds);
    $stmt->execute();
    echo "Affected rows: " . $stmt->affected_rows . "\n";
}

echo "Step 3: Delete items WITHOUT scores\n";
$result3 = $db->query("DELETE gi FROM grade_item gi
    INNER JOIN grade_category gc ON gc.id = gi.grade_category_id
    LEFT JOIN grade_score gs ON gs.grade_item_id = gi.id
    WHERE gc.config_id IN ($idList) AND gs.id IS NULL");
echo "Deleted items without scores: " . $db->affected_rows . "\n";

echo "Step 4: Delete categories\n";
$result4 = $db->query("DELETE FROM grade_category WHERE config_id IN ($idList)");
echo "Deleted categories: " . $db->affected_rows . "\n";

// Check state
echo "\n--- After ---\n";
$result5 = $db->query("SELECT * FROM grade_category WHERE config_id = $configId");
echo "Categories with config_id=$configId: " . $result5->num_rows . "\n";

$result6 = $db->query("SELECT * FROM grade_item WHERE id = 266");
echo "Grade item 266: ";
if ($row = $result6->fetch_assoc()) {
    print_r($row);
} else {
    echo "DELETED\n";
}

$result7 = $db->query("SELECT * FROM grade_score WHERE grade_item_id = 266 AND student_id = 213");
echo "Grade score for item 266, student 213: ";
if ($row = $result7->fetch_assoc()) {
    echo $row['raw_score'] . "\n";
} else {
    echo "MISSING\n";
}