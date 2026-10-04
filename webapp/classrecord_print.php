<?php
/**
 * CLASS RECORD print view.
 *
 * Opens the class record for one class in the browser at its exact printed size,
 * with a small toolbar offering Print and Export to PDF. The sheet itself is
 * rendered by includes/classrecord_template.php, the same builder the PDF export
 * uses, so the two cannot drift apart.
 *
 * Usage: classrecord_print.php?id=CLASS_ID
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gradesheet_data.php';
require_once __DIR__ . '/includes/classrecord_data.php';
require_once __DIR__ . '/includes/classrecord_template.php';

$auth = new Auth();
$auth->requireLogin();

$classId = intval($_GET['id'] ?? 0);
$db = Database::getInstance()->getConnection();

$owner = $db->prepare("SELECT faculty_id FROM class_section WHERE id = ?");
$owner->bind_param('i', $classId);
$owner->execute();
$ownerRow = $owner->get_result()->fetch_assoc();
if (!$ownerRow) {
    http_response_code(404);
    exit('Class not found.');
}
if (intval($ownerRow['faculty_id']) !== intval($auth->getFacultyId())) {
    http_response_code(403);
    exit('You are not authorised to view this class record.');
}

$data = classrecord_load($db, $classId, $_SESSION['faculty_name'] ?? '');
if (!$data) {
    http_response_code(404);
    exit('Class not found.');
}

header('Content-Type: text/html; charset=UTF-8');
echo classrecord_document($data, 'Class Record - ' . ($data['class']['code'] ?? ''));