<?php
require_once 'C:/xampp/htdocs/thesisEgrading/webapp/config/db.php';
require_once 'C:/xampp/htdocs/thesisEgrading/webapp/includes/helpers.php';

$db = Database::getInstance()->getConnection();

// Test the get_class_grades query
$classId = 5;
$faculty_id = 1;

$stmt = $db->prepare('SELECT cs.*, s.code, s.title FROM class_section cs JOIN subject s ON cs.subject_id = s.id WHERE cs.id = ? AND cs.faculty_id = ?');
$stmt->bind_param('ii', $classId, $faculty_id);
$stmt->execute();
$class = $stmt->get_result()->fetch_assoc();
var_dump($class);

$students = $db->query('SELECT * FROM student WHERE class_section_id = 5 ORDER BY last_name')->fetch_all(MYSQLI_ASSOC);
var_dump($students);

if (!empty($students)) {
    $grades = GradingHelper::calculateStudentGrades($db, 5, $students[0]['id']);
    var_dump($grades);
}