<?php
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\config\db.php';
require_once 'C:\xampp\htdocs\thesisEgrading\webapp\includes\helpers.php';
$db = Database::getInstance()->getConnection();

// Simulate the get_class_grades API call for class 12
$classId = 12;

$class = $db->query("SELECT cs.*, s.code, s.title FROM class_section cs JOIN subject s ON cs.subject_id = s.id WHERE cs.id = $classId")->fetch_assoc();

$students = $db->query("SELECT * FROM student WHERE class_section_id = $classId ORDER BY last_name")->fetch_all(MYSQLI_ASSOC);

$gradesData = [];
foreach ($students as $student) {
    $grades = GradingHelper::calculateStudentGrades($db, $classId, $student['id']);
    $gradesData[] = array_merge($student, $grades);
}

echo json_encode([
    'class' => $class,
    'grades' => $gradesData
], JSON_PRETTY_PRINT);