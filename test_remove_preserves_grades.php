<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
$db = Database::getInstance()->getConnection();

// Test: Add student grade, then run removeSyncedConfigRows, verify it's preserved

$studentId = 213;
$classId = 12;
$itemId = 266; // Q1 in midterm Quizzes
$configId = 17; // midterm Quizzes config

// Add a student grade
$score = 90;
$sql = "INSERT INTO grade_score (grade_item_id, student_id, raw_score) VALUES ($itemId, $studentId, $score) 
    ON DUPLICATE KEY UPDATE raw_score = $score";
$db->query($sql);

echo "Added grade: item=$itemId, student=$studentId, score=$score\n";

$result = $db->query("SELECT * FROM grade_score WHERE grade_item_id = $itemId AND student_id = $studentId");
echo "Grade before remove: " . ($result->num_rows ? "EXISTS (score: " . $result->fetch_assoc()['raw_score'] . ")" : "MISSING") . "\n";

// Run removeSyncedConfigRows
function removeSyncedConfigRows($db, $configIds) {
    $configIds = array_values(array_filter(array_map('intval', $configIds)));
    if (empty($configIds)) {
        return;
    }

    $idList = implode(',', $configIds);

    // PRESERVE student scores - only delete grade_items with NO scores
    $db->query("DELETE gi FROM grade_item gi
        INNER JOIN grade_category gc ON gc.id = gi.grade_category_id
        LEFT JOIN grade_score gs ON gs.grade_item_id = gi.id
        WHERE gc.config_id IN ($idList) AND gs.id IS NULL");
    $db->query("DELETE FROM grade_category WHERE config_id IN ($idList)");
}

removeSyncedConfigRows($db, [$configId]);

echo "removeSyncedConfigRows completed.\n";

// Verify grade still exists
$result2 = $db->query("SELECT * FROM grade_score WHERE grade_item_id = $itemId AND student_id = $studentId");
echo "Grade after remove: " . ($result2->num_rows ? "EXISTS (score: " . $result2->fetch_assoc()['raw_score'] . ")" : "MISSING") . "\n";

// Check if item still exists
$result3 = $db->query("SELECT * FROM grade_item WHERE id = $itemId");
echo "Grade item after remove: " . ($result3->num_rows ? "EXISTS" : "DELETED") . "\n";

// Check category
$result4 = $db->query("SELECT * FROM grade_category WHERE config_id = $configId");
echo "Grade category after remove: " . ($result4->num_rows ? "EXISTS" : "DELETED") . "\n";