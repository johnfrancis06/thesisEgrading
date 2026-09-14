<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/helpers.php';
require_once '../includes/auth.php';

$auth->requireLogin();
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$db = Database::getInstance()->getConnection();
$faculty_id = $auth->getFacultyId();

function getGradingTemplateClassId($db, $faculty_id) {
    $result = $db->query("SELECT cs.id FROM class_section cs 
        JOIN subject s ON cs.subject_id = s.id 
        WHERE s.code = 'ML' AND cs.faculty_id = $faculty_id 
        ORDER BY cs.created_at ASC LIMIT 1");
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc()['id'];
    }
    return null;
}

function cloneGradingSheets($db, $newClassId, $templateClassId) {
    if (!$templateClassId) return false;
    
    $categories = $db->query("SELECT * FROM grade_category 
        WHERE class_section_id = $templateClassId ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
    
    foreach ($categories as $cat) {
        $stmt = $db->prepare("INSERT INTO grade_category (class_section_id, period, name, weight_percent, sort_order) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issdi", $newClassId, $cat['period'], $cat['name'], $cat['weight_percent'], $cat['sort_order']);
        $stmt->execute();
        $newCategoryId = $db->insert_id;
        
        $items = $db->query("SELECT * FROM grade_item WHERE grade_category_id = {$cat['id']} ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
        foreach ($items as $item) {
            $stmt2 = $db->prepare("INSERT INTO grade_item (grade_category_id, label, max_score, sort_order) VALUES (?, ?, ?, ?)");
            $stmt2->bind_param("isdi", $newCategoryId, $item['label'], $item['max_score'], $item['sort_order']);
            $stmt2->execute();
        }
    }
    return true;
}

function getAttendanceLateCountsPresent($db, $classId) {
    $columnCheck = $db->query("SHOW COLUMNS FROM class_section LIKE 'attendance_late_counts_present'");
    if ($columnCheck && $columnCheck->num_rows > 0) {
        $result = $db->query("SELECT attendance_late_counts_present FROM class_section WHERE id = " . intval($classId))->fetch_assoc();
        return $result ? (bool)$result['attendance_late_counts_present'] : false;
    }
    return false;
}

function getAttendanceWeekdays($db, $classId) {
    $columnCheck = $db->query("SHOW COLUMNS FROM class_section LIKE 'attendance_weekdays'");
    if ($columnCheck && $columnCheck->num_rows > 0) {
        $result = $db->query("SELECT attendance_weekdays FROM class_section WHERE id = " . intval($classId))->fetch_assoc();
        if ($result && !empty($result['attendance_weekdays'])) {
            return array_map('intval', explode(',', $result['attendance_weekdays']));
        }
    }
    return [1, 2, 3, 4, 5];
}

function updateAttendanceWeekdays($db, $classId, $weekdays) {
    $columnCheck = $db->query("SHOW COLUMNS FROM class_section LIKE 'attendance_weekdays'");
    if ($columnCheck && $columnCheck->num_rows > 0) {
        $weekdaysStr = implode(',', array_map('intval', $weekdays));
        $db->query("UPDATE class_section SET attendance_weekdays = '" . $db->real_escape_string($weekdaysStr) . "' WHERE id = " . intval($classId));
        return true;
    }
    return false;
}

try {
    if ($action === 'get_classes') {
        $stmt = $db->prepare("SELECT cs.*, s.code, s.title FROM class_section cs 
            JOIN subject s ON cs.subject_id = s.id 
            WHERE cs.faculty_id = ? ORDER BY cs.academic_year DESC, cs.semester DESC");
        $stmt->bind_param("i", $faculty_id);
        $stmt->execute();
        echo ResponseAPI::success($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }
    elseif ($action === 'check_class_students') {
        $classId = intval($_GET['class_id']);
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM student WHERE class_section_id = ?");
        $stmt->bind_param("i", $classId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        // Get class info for year level validation
        $classStmt = $db->prepare("SELECT year_level, section, course_program FROM class_section WHERE id = ?");
        $classStmt->bind_param("i", $classId);
        $classStmt->execute();
        $classInfo = $classStmt->get_result()->fetch_assoc();
        
        echo ResponseAPI::success([
            'has_students' => $result['count'] > 0, 
            'count' => $result['count'],
            'class_year_level' => $classInfo['year_level'] ?? null,
            'class_section' => $classInfo['section'] ?? null,
            'class_program' => $classInfo['course_program'] ?? null
        ]);
    }
    elseif ($action === 'add_student_to_class') {
        $data = json_decode(file_get_contents("php://input"), true);
        $classId = intval($data['class_id']);
        $sectionStudentId = intval($data['section_student_id']);
        
        $ss = $db->query("SELECT * FROM section_student WHERE id = $sectionStudentId")->fetch_assoc();
        
        if (!$ss) {
            echo ResponseAPI::error("Section student not found");
            exit;
        }
        
        $check = $db->prepare("SELECT id FROM student WHERE class_section_id = ? AND student_no = ?");
        $check->bind_param("is", $classId, $ss['student_no']);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            echo ResponseAPI::error("Student already enrolled in this class");
            exit;
        }
        
        $stmt = $db->prepare("INSERT INTO student (class_section_id, last_name, first_name, middle_initial, student_no) 
            VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $classId, $ss['last_name'], $ss['first_name'], $ss['middle_initial'], $ss['student_no']);
        
        echo $stmt->execute() ? ResponseAPI::success(['id' => $db->insert_id], "Student added to class") 
            : ResponseAPI::error("Failed to add student: " . $db->error);
    }

    elseif ($action === 'add_section_to_class') {
        $data = json_decode(file_get_contents("php://input"), true);
        $classId = intval($data['class_id']);
        $yearLevel = intval($data['year_level']);
        $section = $db->real_escape_string($data['section']);

        // Get class info to validate year level
        $class = $db->query("SELECT year_level, course_program, section, academic_year FROM class_section WHERE id = $classId AND faculty_id = $faculty_id")->fetch_assoc();
        if (!$class) {
            echo ResponseAPI::error("Class not found");
            exit;
        }

        // Validate year level matches
        if ($class['year_level'] != $yearLevel) {
            echo ResponseAPI::error("This subject is for Year {$class['year_level']} students only. Cannot assign Year $yearLevel Section $section students.");
            exit;
        }

        // Use the canonical enrollment roster so the same student can be copied
        // into every subject class in the section.
        $program = $db->real_escape_string($data['course_program'] ?? $class['course_program']);
        $academicYear = $db->real_escape_string($class['academic_year']);
        $stmt = $db->prepare("SELECT DISTINCT student_no, last_name, first_name, middle_initial
            FROM section_student
            WHERE course_program = ? AND year_level = ? AND section = ? AND academic_year = ?");
        $stmt->bind_param("siss", $program, $yearLevel, $section, $academicYear);
        $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Support older installations whose students were never copied to section_student.
        if (empty($students)) {
            $stmt = $db->prepare("SELECT DISTINCT s.student_no, s.last_name, s.first_name, s.middle_initial
                FROM student s JOIN class_section cs ON s.class_section_id = cs.id
                WHERE cs.faculty_id = ? AND cs.course_program = ? AND cs.year_level = ? AND cs.section = ? AND cs.academic_year = ?");
            $stmt->bind_param("isiss", $faculty_id, $program, $yearLevel, $section, $academicYear);
            $stmt->execute();
            $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }

        if (empty($students)) {
            echo ResponseAPI::error("No students found in this section");
            exit;
        }

        $addedCount = 0;
        $skippedCount = 0;

        foreach ($students as $s) {
            // Check if student already exists in class
            $check = $db->prepare("SELECT id FROM student WHERE class_section_id = ? AND student_no = ?");
            $check->bind_param("is", $classId, $s['student_no']);
            $check->execute();
            if ($check->get_result()->fetch_assoc()) {
                $skippedCount++;
                continue;
            }

            // Add student to class
            $stmt = $db->prepare("INSERT INTO student (class_section_id, last_name, first_name, middle_initial, student_no)
                VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issss", $classId, $s['last_name'], $s['first_name'], $s['middle_initial'], $s['student_no']);
            if ($stmt->execute()) {
                $addedCount++;
            }
        }

        $message = "Added $addedCount students to class";
        if ($skippedCount > 0) {
            $message .= " ($skippedCount already enrolled)";
        }
        echo ResponseAPI::success(['added' => $addedCount, 'skipped' => $skippedCount], $message);
    }
    elseif ($action === 'add_section_to_multiple_classes') {
        $data = json_decode(file_get_contents("php://input"), true);
        $program = $db->real_escape_string($data['program'] ?? '');
        $yearLevel = intval($data['year_level'] ?? 0);
        $section = $db->real_escape_string($data['section'] ?? '');
        $academicYear = $db->real_escape_string($data['academic_year'] ?? '');
        $classIds = $data['class_ids'] ?? [];
        
        if (!$program || !$yearLevel || !$section || !$academicYear || empty($classIds)) {
            echo ResponseAPI::error("Please fill all fields and select at least one subject");
            exit;
        }
        
        // Read from the shared section roster so one section can be enrolled
        // in multiple subject classes.
        $stmt = $db->prepare("SELECT DISTINCT student_no, last_name, first_name, middle_initial
            FROM section_student
            WHERE course_program = ? AND year_level = ? AND section = ? AND academic_year = ?");
        $stmt->bind_param("siss", $program, $yearLevel, $section, $academicYear);
        $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        if (empty($students)) {
            $stmt = $db->prepare("SELECT DISTINCT s.student_no, s.last_name, s.first_name, s.middle_initial
                FROM student s JOIN class_section cs ON s.class_section_id = cs.id
                WHERE cs.faculty_id = ? AND cs.course_program = ? AND cs.year_level = ? AND cs.section = ? AND cs.academic_year = ?");
            $stmt->bind_param("isiss", $faculty_id, $program, $yearLevel, $section, $academicYear);
            $stmt->execute();
            $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        
        if (empty($students)) {
            echo ResponseAPI::error("No students found in this section");
            exit;
        }
        
        $db->begin_transaction();
        $results = [];
        
        try {
            foreach ($classIds as $classId) {
                $classId = intval($classId);
                
                // Verify class exists and belongs to faculty
                $classStmt = $db->prepare("SELECT cs.*, s.code, s.title 
                    FROM class_section cs 
                    JOIN subject s ON cs.subject_id = s.id 
                    WHERE cs.id = ? AND cs.faculty_id = ?");
                $classStmt->bind_param("ii", $classId, $faculty_id);
                $classStmt->execute();
                $class = $classStmt->get_result()->fetch_assoc();
                
                if (!$class) {
                    $results[] = [
                        'class_id' => $classId,
                        'code' => 'Unknown',
                        'subject_title' => 'Unknown',
                        'added' => 0,
                        'skipped' => 0,
                        'error' => 'Class not found or access denied'
                    ];
                    continue;
                }
                
                // Verify year level matches
                if ($class['year_level'] != $yearLevel) {
                    $results[] = [
                        'class_id' => $classId,
                        'code' => $class['code'],
                        'subject_title' => $class['title'],
                        'added' => 0,
                        'skipped' => 0,
                        'error' => "Year mismatch: class is for Year {$class['year_level']}, section is Year $yearLevel"
                    ];
                    continue;
                }
                
                $added = 0;
                $skipped = 0;
                
                foreach ($students as $s) {
                    // Check if student already exists in this class
                    $check = $db->prepare("SELECT id FROM student WHERE class_section_id = ? AND student_no = ?");
                    $check->bind_param("is", $classId, $s['student_no']);
                    $check->execute();
                    if ($check->get_result()->fetch_assoc()) {
                        $skipped++;
                        continue;
                    }
                    
                    // Add student to class
                    $insert = $db->prepare("INSERT INTO student (class_section_id, last_name, first_name, middle_initial, student_no) 
                        VALUES (?, ?, ?, ?, ?)");
                    $insert->bind_param("issss", $classId, $s['last_name'], $s['first_name'], $s['middle_initial'], $s['student_no']);
                    if ($insert->execute()) {
                        $added++;
                    } else {
                        $skipped++;
                    }
                }
                
                $results[] = [
                    'class_id' => $classId,
                    'code' => $class['code'],
                    'subject_title' => $class['title'],
                    'added' => $added,
                    'skipped' => $skipped,
                    'error' => null
                ];
            }
            
            $db->commit();
            
            $totalAdded = array_sum(array_column($results, 'added'));
            $totalSkipped = array_sum(array_column($results, 'skipped'));
            $message = "Enrolled $totalAdded students across " . count($classIds) . " subjects";
            if ($totalSkipped > 0) {
                $message .= " ($totalSkipped already enrolled)";
            }
            
            echo ResponseAPI::success(['results' => $results, 'total_added' => $totalAdded, 'total_skipped' => $totalSkipped], $message);
        } catch (Exception $e) {
            $db->rollback();
            echo ResponseAPI::error("Enrollment failed: " . $e->getMessage());
        }
    }
    elseif ($action === 'create_class') {
        $data = json_decode(file_get_contents("php://input"), true);
        $stmt = $db->prepare("INSERT INTO class_section 
            (subject_id, faculty_id, course_program, year_level, section, semester, academic_year) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iisisis", $data['subject_id'], $faculty_id, $data['course_program'], 
            $data['year_level'], $data['section'], $data['semester'], $data['academic_year']);
        
        if ($stmt->execute()) {
            $classId = $db->insert_id;
            $templateId = getGradingTemplateClassId($db, $faculty_id);
            if ($templateId) {
                cloneGradingSheets($db, $classId, $templateId);
            } else {
                $categories = [
                    ['midterm', 'Class Standing', 20],
                    ['midterm', 'Problem Set', 20],
                    ['midterm', 'Quizzes', 30],
                    ['midterm', 'Exam', 30],
                    ['final', 'Class Standing', 20],
                    ['final', 'Problem Set', 20],
                    ['final', 'Quizzes', 30],
                    ['final', 'Exam', 30]
                ];
                foreach ($categories as $cat) {
                    $stmt2 = $db->prepare("INSERT INTO grade_category (class_section_id, period, name, weight_percent) VALUES (?, ?, ?, ?)");
                    $stmt2->bind_param("issi", $classId, $cat[0], $cat[1], $cat[2]);
                    $stmt2->execute();
                    $categoryId = $db->insert_id;
                    
                    if ($cat[1] === 'Quizzes') {
                        for ($i = 1; $i <= 5; $i++) {
                            $stmt3 = $db->prepare("INSERT INTO grade_item (grade_category_id, label, max_score, sort_order) VALUES (?, ?, ?, ?)");
                            $label = "Quiz " . $i;
                            $maxScore = 10;
                            $sortOrder = $i - 1;
                            $stmt3->bind_param("isii", $categoryId, $label, $maxScore, $sortOrder);
                            $stmt3->execute();
                        }
                    } elseif ($cat[1] === 'Problem Set') {
                        for ($i = 1; $i <= 3; $i++) {
                            $stmt3 = $db->prepare("INSERT INTO grade_item (grade_category_id, label, max_score, sort_order) VALUES (?, ?, ?, ?)");
                            $label = "Problem Set " . $i;
                            $maxScore = 20;
                            $sortOrder = $i - 1;
                            $stmt3->bind_param("isii", $categoryId, $label, $maxScore, $sortOrder);
                            $stmt3->execute();
                        }
                    } else {
                        $stmt3 = $db->prepare("INSERT INTO grade_item (grade_category_id, label, max_score, sort_order) VALUES (?, ?, ?, ?)");
                        $maxScore = 100;
                        $sortOrder = 0;
                        $stmt3->bind_param("isii", $categoryId, $cat[1], $maxScore, $sortOrder);
                        $stmt3->execute();
                    }
                }
            }
            echo ResponseAPI::success(['id' => $classId], "Class created", 201);
        } else {
            echo ResponseAPI::error("Failed to create class: " . $stmt->error);
        }
    }
    elseif ($action === 'create_class_with_students') {
        $data = json_decode(file_get_contents("php://input"), true);
        
        $stmt = $db->prepare("INSERT INTO class_section 
            (subject_id, faculty_id, course_program, year_level, section, semester, academic_year) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iisisis", $data['subject_id'], $faculty_id, $data['course_program'], 
            $data['year_level'], $data['section'], $data['semester'], $data['academic_year']);
        
        if ($stmt->execute()) {
            $classId = $db->insert_id;
            $templateId = getGradingTemplateClassId($db, $faculty_id);
            if ($templateId) {
                cloneGradingSheets($db, $classId, $templateId);
            } else {
                $categories = [
                    ['midterm', 'Class Standing', 20],
                    ['midterm', 'Problem Set', 20],
                    ['midterm', 'Quizzes', 30],
                    ['midterm', 'Exam', 30],
                    ['final', 'Class Standing', 20],
                    ['final', 'Problem Set', 20],
                    ['final', 'Quizzes', 30],
                    ['final', 'Exam', 30]
                ];
                foreach ($categories as $cat) {
                    $stmt2 = $db->prepare("INSERT INTO grade_category (class_section_id, period, name, weight_percent) VALUES (?, ?, ?, ?)");
                    $stmt2->bind_param("issi", $classId, $cat[0], $cat[1], $cat[2]);
                    $stmt2->execute();
                    $categoryId = $db->insert_id;
                    
                    if ($cat[1] === 'Quizzes') {
                        for ($i = 1; $i <= 5; $i++) {
                            $stmt3 = $db->prepare("INSERT INTO grade_item (grade_category_id, label, max_score, sort_order) VALUES (?, ?, ?, ?)");
                            $label = "Quiz " . $i;
                            $maxScore = 10;
                            $sortOrder = $i - 1;
                            $stmt3->bind_param("isii", $categoryId, $label, $maxScore, $sortOrder);
                            $stmt3->execute();
                        }
                    } elseif ($cat[1] === 'Problem Set') {
                        for ($i = 1; $i <= 3; $i++) {
                            $stmt3 = $db->prepare("INSERT INTO grade_item (grade_category_id, label, max_score, sort_order) VALUES (?, ?, ?, ?)");
                            $label = "Problem Set " . $i;
                            $maxScore = 20;
                            $sortOrder = $i - 1;
                            $stmt3->bind_param("isii", $categoryId, $label, $maxScore, $sortOrder);
                            $stmt3->execute();
                        }
                    } else {
                        $stmt3 = $db->prepare("INSERT INTO grade_item (grade_category_id, label, max_score, sort_order) VALUES (?, ?, ?, ?)");
                        $maxScore = 100;
                        $sortOrder = 0;
                        $stmt3->bind_param("isii", $categoryId, $cat[1], $maxScore, $sortOrder);
                        $stmt3->execute();
                    }
                }
            }
            
            $program = $db->real_escape_string($data['course_program']);
            $year = intval($data['year_level']);
            $section = $db->real_escape_string($data['section']);
            $ay = $db->real_escape_string($data['academic_year']);
            
            $students = $db->query("SELECT * FROM section_student 
                WHERE course_program = '$program' AND year_level = $year AND section = '$section' AND academic_year = '$ay'")->fetch_all(MYSQLI_ASSOC);
            
            foreach ($students as $s) {
                $db->query("INSERT INTO student (class_section_id, last_name, first_name, middle_initial, student_no) 
                    VALUES ($classId, '{$s['last_name']}', '{$s['first_name']}', '{$s['middle_initial']}', '{$s['student_no']}')");
            }
            
            echo ResponseAPI::success(['id' => $classId], "Class created with " . count($students) . " students", 201);
        } else {
            echo ResponseAPI::error("Failed to create class: " . $stmt->error);
        }
    }
    elseif ($action === 'get_students') {
        $classId = intval($_GET['class_id'] ?? 0);
        $yearLevel = $_GET['year_level'] ?? '';
        $section = $_GET['section'] ?? '';
        
        if ($classId > 0) {
            $stmt = $db->prepare("SELECT * FROM student WHERE class_section_id = ? ORDER BY last_name");
            $stmt->bind_param("i", $classId);
        } else {
            $query = "SELECT s.*, cs.course_program, cs.year_level, cs.section, cs.academic_year, sub.code, sub.title 
                      FROM student s 
                      JOIN class_section cs ON s.class_section_id = cs.id 
                      JOIN subject sub ON cs.subject_id = sub.id 
                      WHERE cs.faculty_id = ?";
            $params = [$faculty_id];
            $types = "i";
            
            if (!empty($yearLevel)) {
                $query .= " AND cs.year_level = ?";
                $params[] = $yearLevel;
                $types .= "i";
            }
            if (!empty($section)) {
                $query .= " AND cs.section = ?";
                $params[] = $section;
                $types .= "s";
            }
            
            $query .= " ORDER BY cs.academic_year DESC, cs.year_level, cs.section, s.last_name";
            $stmt = $db->prepare($query);
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        echo ResponseAPI::success($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }
    elseif ($action === 'add_student') {
        $data = json_decode(file_get_contents("php://input"), true);
        $stmt = $db->prepare("INSERT INTO student (class_section_id, last_name, first_name, middle_initial, student_no) 
            VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $data['class_id'], $data['last_name'], $data['first_name'], 
            $data['middle_initial'], $data['student_no']);
        
        echo $stmt->execute() ? ResponseAPI::success(['id' => $db->insert_id], "Student added", 201) 
            : ResponseAPI::error("Failed to add student");
    }
    elseif ($action === 'get_grading_sheet') {
        $classId = intval($_GET['class_id']);
        $period = $_GET['period'] ?? 'midterm';
        
        $students = $db->query("SELECT * FROM student WHERE class_section_id = $classId ORDER BY last_name")->fetch_all(MYSQLI_ASSOC);
        
        $categories = $db->query("SELECT * FROM grade_category 
            WHERE class_section_id = $classId AND period = '" . $db->real_escape_string($period) . "' 
            ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
        
        $result = ['students' => $students, 'categories' => []];
        foreach ($categories as $cat) {
            $items = $db->query("SELECT * FROM grade_item WHERE grade_category_id = {$cat['id']} ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
            $cat['items'] = $items;
            $result['categories'][] = $cat;
        }
        
        echo ResponseAPI::success($result);
    }
    elseif ($action === 'get_item_scores') {
        $itemId = intval($_GET['item_id']);
        $stmt = $db->prepare("SELECT gs.*, s.last_name, s.first_name, s.student_no 
            FROM grade_score gs 
            JOIN student s ON gs.student_id = s.id 
            WHERE gs.grade_item_id = ? ORDER BY s.last_name");
        $stmt->bind_param("i", $itemId);
        $stmt->execute();
        echo ResponseAPI::success($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }
    elseif ($action === 'save_grade') {
        $data = json_decode(file_get_contents("php://input"), true);
        $itemId = intval($data['item_id'] ?? $data['grade_item_id'] ?? 0);
        $studentId = intval($data['student_id']);
        $rawScore = round(floatval($data['raw_score'] ?? 0));

        if ($itemId <= 0 || $studentId <= 0) {
            echo ResponseAPI::error("Invalid grade item or student");
            exit;
        }
        
        $maxResult = $db->query("SELECT max_score FROM grade_item WHERE id = $itemId")->fetch_assoc();
        $maxScore = $maxResult ? floatval($maxResult['max_score']) : 100;
        $rawScore = max(0, min($rawScore, $maxScore));
        
        $stmt = $db->prepare("INSERT INTO grade_score (grade_item_id, student_id, raw_score) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE raw_score = VALUES(raw_score)");
        $stmt->bind_param("iid", $itemId, $studentId, $rawScore);
        echo $stmt->execute() ? ResponseAPI::success([], "Grade saved") 
            : ResponseAPI::error("Failed to save grade");
    }
    elseif ($action === 'add_category') {
        $data = json_decode(file_get_contents("php://input"), true);
        
        $classId = intval($data['class_id'] ?? 0);
        $period = $data['period'] ?? 'midterm';
        $name = trim($data['name'] ?? '');
        $weight = floatval($data['weight'] ?? 0);
        $maxScore = floatval($data['max_score'] ?? 100);
        $sort = 0;
        
        if (!$classId || !$name || $weight <= 0) {
            echo ResponseAPI::error("Invalid input: class_id, name, and weight are required");
            exit;
        }
        
        $stmt = $db->prepare("INSERT INTO grade_category (class_section_id, period, name, weight_percent, sort_order) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issdi", $classId, $period, $name, $weight, $sort);
        
        if (!$stmt->execute()) {
            echo ResponseAPI::error("Failed to add category: " . $db->error);
            exit;
        }
        
        $categoryId = $db->insert_id;
        
        if ($categoryId) {
            $stmt3 = $db->prepare("INSERT INTO grade_item (grade_category_id, label, max_score, sort_order) VALUES (?, ?, ?, ?)");
            $sortOrder = 0;
            $stmt3->bind_param("isdi", $categoryId, $name, $maxScore, $sortOrder);
            $stmt3->execute();
        }
        
        echo ResponseAPI::success(['id' => $categoryId], "Category added");
    }
    elseif ($action === 'update_category') {
        $data = json_decode(file_get_contents("php://input"), true);
        $stmt = $db->prepare("UPDATE grade_category SET name = ?, period = ?, weight_percent = ? WHERE id = ?");
        $stmt->bind_param("ssdi", $data['name'], $data['period'], $data['weight'], $data['id']);
        echo $stmt->execute() ? ResponseAPI::success([], "Category updated") 
            : ResponseAPI::error("Failed to update category");
    }
    elseif ($action === 'delete_category') {
        $data = json_decode(file_get_contents("php://input"), true);
        $categoryId = intval($data['id'] ?? 0);
        if ($categoryId > 0) {
            $db->query("DELETE FROM grade_score WHERE grade_item_id IN (SELECT id FROM grade_item WHERE grade_category_id = $categoryId)");
            $db->query("DELETE FROM grade_item WHERE grade_category_id = $categoryId");
            $db->query("DELETE FROM grade_category WHERE id = $categoryId");
            echo ResponseAPI::success([], "Category deleted");
        } else {
            echo ResponseAPI::error("Invalid category ID");
        }
    }
    elseif ($action === 'get_categories_by_period') {
        $classId = intval($_GET['class_id']);
        $period = $_GET['period'] ?? 'midterm';
        $stmt = $db->prepare("SELECT * FROM grade_category 
            WHERE class_section_id = ? AND period = ? ORDER BY sort_order");
        $stmt->bind_param("is", $classId, $period);
        $stmt->execute();
        echo ResponseAPI::success($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }
    elseif ($action === 'add_grade_item') {
        $data = json_decode(file_get_contents("php://input"), true);
        $stmt = $db->prepare("INSERT INTO grade_item (grade_category_id, label, max_score, sort_order) VALUES (?, ?, ?, ?)");
        $sort = intval($data['sort_order'] ?? 0);
        $stmt->bind_param("isdi", $data['category_id'], $data['label'], $data['max_score'], $sort);
        echo $stmt->execute() ? ResponseAPI::success(['id' => $db->insert_id], "Item added") 
            : ResponseAPI::error("Failed to add item");
    }
    elseif ($action === 'delete_grade_item') {
        $data = json_decode(file_get_contents("php://input"), true);
        $itemId = intval($data['id'] ?? 0);
        if ($itemId > 0) {
            $db->query("DELETE FROM grade_score WHERE grade_item_id = $itemId");
            $db->query("DELETE FROM grade_item WHERE id = $itemId");
            echo ResponseAPI::success([], "Item deleted");
        } else {
            echo ResponseAPI::error("Invalid item ID");
        }
    }
    elseif ($action === 'get_grade_items_by_category') {
        $categoryId = intval($_GET['category_id'] ?? 0);
        if ($categoryId > 0) {
            $stmt = $db->prepare("SELECT * FROM grade_item WHERE grade_category_id = ? ORDER BY sort_order");
            $stmt->bind_param("i", $categoryId);
            $stmt->execute();
            echo ResponseAPI::success($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        } else {
            echo ResponseAPI::error("Invalid category ID");
        }
    }
    elseif ($action === 'update_grade_item') {
        $data = json_decode(file_get_contents("php://input"), true);
        $id = intval($data['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE grade_item SET label = ?, max_score = ?, sort_order = ? WHERE id = ?");
            $stmt->bind_param("sdii", $data['label'], $data['max_score'], $data['sort_order'], $id);
            echo $stmt->execute() ? ResponseAPI::success([], "Item updated") 
                : ResponseAPI::error("Failed to update item");
        } else {
            echo ResponseAPI::error("Invalid item ID");
        }
    }
    elseif ($action === 'create_subject') {
        $data = json_decode(file_get_contents("php://input"), true);
        $code = trim($data['code'] ?? '');
        $title = trim($data['title'] ?? '');
        if ($code === '' || $title === '') {
            echo ResponseAPI::error("Subject code and title are required");
            exit;
        }
        $stmt = $db->prepare("INSERT INTO subject (code, title, default_units) VALUES (?, ?, ?)");
        $units = intval($data['default_units'] ?? 3);
        $stmt->bind_param("ssi", $code, $title, $units);
        echo $stmt->execute() ? ResponseAPI::success(['id' => $db->insert_id], "Subject created", 201) 
            : ResponseAPI::error("Failed to create subject: " . $stmt->error);
    }
    elseif ($action === 'update_subject') {
        $data = json_decode(file_get_contents("php://input"), true);
        $id = intval($data['id']);
        $units = intval($data['default_units'] ?? 3);
        $stmt = $db->prepare("UPDATE subject SET code = ?, title = ?, default_units = ? WHERE id = ?");
        $stmt->bind_param("ssii", $data['code'], $data['title'], $units, $id);
        echo $stmt->execute() ? ResponseAPI::success([], "Subject updated") 
            : ResponseAPI::error("Failed to update subject: " . $stmt->error);
    }
    elseif ($action === 'get_subjects') {
        try {
            $tableCheck = $db->query("SHOW TABLES LIKE 'subject'");
            if ($tableCheck->num_rows === 0) {
                throw new Exception("Subject table does not exist. Please import the database schema.");
            }
            $result = $db->query("SELECT * FROM subject ORDER BY code");
            if (!$result) {
                throw new Exception("Query failed: " . $db->error);
            }
            ob_clean();
            echo ResponseAPI::success($result->fetch_all(MYSQLI_ASSOC));
        } catch (Exception $e) {
            ob_clean();
            echo ResponseAPI::error("Failed to load subjects: " . $e->getMessage(), 500);
        }
    }
    elseif ($action === 'delete_subject') {
        $data = json_decode(file_get_contents("php://input"), true);
        $subjectId = intval($data['id'] ?? 0);
        if ($subjectId > 0) {
            $db->query("DELETE FROM subject WHERE id = $subjectId");
            echo ResponseAPI::success([], "Subject deleted");
        } else {
            echo ResponseAPI::error("Invalid subject ID");
        }
    }
    elseif ($action === 'delete_class') {
        $data = json_decode(file_get_contents("php://input"), true);
        $classId = intval($data['id'] ?? 0);
        
        if ($classId <= 0) {
            echo ResponseAPI::error("Invalid class ID");
            exit;
        }
        
        $stmt = $db->prepare("SELECT id FROM class_section WHERE id = ? AND faculty_id = ?");
        $stmt->bind_param("ii", $classId, $faculty_id);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            echo ResponseAPI::error("Unauthorized");
            exit;
        }
        
        $db->query("DELETE FROM grade_score WHERE grade_item_id IN (SELECT id FROM grade_item WHERE grade_category_id IN (SELECT id FROM grade_category WHERE class_section_id = $classId))");
        $db->query("DELETE FROM grade_item WHERE grade_category_id IN (SELECT id FROM grade_category WHERE class_section_id = $classId)");
        $db->query("DELETE FROM grade_category WHERE class_section_id = $classId");
        $db->query("DELETE FROM student WHERE class_section_id = $classId");
        $db->query("DELETE FROM attendance_record WHERE attendance_session_id IN (SELECT id FROM attendance_session WHERE class_section_id = $classId)");
        $db->query("DELETE FROM attendance_session WHERE class_section_id = $classId");
        $db->query("DELETE FROM class_section WHERE id = $classId");
        echo ResponseAPI::success([], "Class deleted");
    }
    elseif ($action === 'get_attendance_sessions') {
        $classId = intval($_GET['class_id']);
        $stmt = $db->prepare("SELECT * FROM attendance_session WHERE class_section_id = ? ORDER BY date DESC");
        $stmt->bind_param("i", $classId);
        $stmt->execute();
        echo ResponseAPI::success($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }
    elseif ($action === 'get_attendance_records') {
        $sessionId = intval($_GET['session_id']);
        $stmt = $db->prepare("SELECT ar.*, s.last_name, s.first_name, s.student_no 
            FROM attendance_record ar 
            JOIN student s ON ar.student_id = s.id 
            WHERE ar.attendance_session_id = ? ORDER BY s.last_name");
        $stmt->bind_param("i", $sessionId);
        $stmt->execute();
        echo ResponseAPI::success($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }
    elseif ($action === 'get_student_attendance_history') {
        $studentId = intval($_GET['student_id']);
        $classId = intval($_GET['class_id']);
        $stmt = $db->prepare("SELECT ase.date, ase.label, ar.status, ar.remarks 
            FROM attendance_record ar 
            JOIN attendance_session ase ON ar.attendance_session_id = ase.id 
            WHERE ar.student_id = ? AND ase.class_section_id = ? 
            ORDER BY ase.date DESC");
        $stmt->bind_param("ii", $studentId, $classId);
        $stmt->execute();
        echo ResponseAPI::success($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }
    elseif ($action === 'auto_create_sessions') {
        $data = json_decode(file_get_contents("php://input"), true);
        $classId = intval($data['class_id']);
        $year = intval($data['year']);
        $month = intval($data['month']);
        $weekdays = $data['weekdays'] ?? [1, 2, 3, 4, 5];
        
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $created = 0;
        $existing = 0;
        
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $jsDay = (int)date('N', strtotime($date));
            
            if (!in_array($jsDay, $weekdays)) continue;
            
            $exists = $db->query("SELECT id FROM attendance_session WHERE class_section_id = $classId AND date = '$date'")->fetch_assoc();
            if ($exists) {
                $existing++;
                continue;
            }
            
            $stmt = $db->prepare("INSERT INTO attendance_session (class_section_id, date, label) VALUES (?, ?, ?)");
            $label = date('l, F j', strtotime($date));
            $stmt->bind_param("iss", $classId, $date, $label);
            
            if ($stmt->execute()) {
                $sessionId = $db->insert_id;
                $students = $db->query("SELECT id FROM student WHERE class_section_id = $classId")->fetch_all(MYSQLI_ASSOC);
                foreach ($students as $s) {
                    $stmt2 = $db->prepare("INSERT INTO attendance_record (attendance_session_id, student_id, status) VALUES (?, ?, 'absent')");
                    $stmt2->bind_param("ii", $sessionId, $s['id']);
                    $stmt2->execute();
                }
                $created++;
            }
        }
        
        echo ResponseAPI::success(['created' => $created, 'existing' => $existing], "Sessions processed");
    }
    elseif ($action === 'get_attendance_for_grading') {
        $classId = intval($_GET['class_id']);
        $lateCountsAsPresent = getAttendanceLateCountsPresent($db, $classId);
        
        $students = $db->query("SELECT id FROM student WHERE class_section_id = $classId")->fetch_all(MYSQLI_ASSOC);
        $result = [];
        
        foreach ($students as $student) {
            $stats = AttendanceHelper::getStudentAttendanceRate($student['id'], $classId, $lateCountsAsPresent);
            $result[] = [
                'student_id' => $student['id'],
                'attendance_rate' => $stats['attendance_rate'],
                'total_sessions' => $stats['total_sessions'],
                'present_count' => $stats['present_count'],
                'late_count' => $stats['late_count']
            ];
        }
        
        echo ResponseAPI::success($result);
    }
    elseif ($action === 'create_attendance_session') {
        $data = json_decode(file_get_contents("php://input"), true);
        $stmt = $db->prepare("INSERT INTO attendance_session (class_section_id, date, label) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $data['class_id'], $data['date'], $data['label']);
        
        if ($stmt->execute()) {
            $sessionId = $db->insert_id;
            $students = $db->query("SELECT id FROM student WHERE class_section_id = {$data['class_id']}")->fetch_all(MYSQLI_ASSOC);
            foreach ($students as $s) {
                $stmt2 = $db->prepare("INSERT INTO attendance_record (attendance_session_id, student_id, status) VALUES (?, ?, 'absent')");
                $stmt2->bind_param("ii", $sessionId, $s['id']);
                $stmt2->execute();
            }
            echo ResponseAPI::success(['id' => $sessionId], "Session created", 201);
        } else {
            echo ResponseAPI::error("Failed to create session");
        }
    }
    elseif ($action === 'save_attendance') {
        $data = json_decode(file_get_contents("php://input"), true);
        $recordId = intval($data['record_id'] ?? 0);
        $status = $data['status'] ?? 'absent';
        $remarks = $data['remarks'] ?? '';
        $sessionId = intval($data['session_id'] ?? 0);
        $studentId = intval($data['student_id'] ?? 0);
        
        if ($recordId > 0) {
            $oldRecord = $db->query("SELECT ar.*, ase.class_section_id FROM attendance_record ar 
                JOIN attendance_session ase ON ar.attendance_session_id = ase.id 
                WHERE ar.id = $recordId")->fetch_assoc();
            
            $stmt = $db->prepare("UPDATE attendance_record SET status = ?, remarks = ? WHERE id = ?");
            $stmt->bind_param("ssi", $status, $remarks, $recordId);
            $stmt->execute();
            
            if ($stmt->execute() && $oldRecord) {
                logAudit($db, $faculty_id, 'update_attendance', 'attendance_record', $recordId, 
                    json_encode(['status' => $oldRecord['status']]), 
                    json_encode(['status' => $status, 'remarks' => $remarks])
                );
            }
            
            echo ResponseAPI::success([], "Attendance saved");
        } elseif ($sessionId > 0 && $studentId > 0) {
            $stmt = $db->prepare("INSERT INTO attendance_record (attendance_session_id, student_id, status, remarks) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiss", $sessionId, $studentId, $status, $remarks);
            $stmt->execute();
            $newRecordId = $db->insert_id;
            
            logAudit($db, $faculty_id, 'create_attendance', 'attendance_record', $newRecordId,
                null, json_encode(['status' => $status, 'remarks' => $remarks])
            );
            
            echo ResponseAPI::success(['id' => $newRecordId], "Attendance created");
        } else {
            echo ResponseAPI::error("Invalid request");
        }
    }
    elseif ($action === 'update_attendance_session') {
        $data = json_decode(file_get_contents("php://input"), true);
        $sessionId = intval($data['id'] ?? 0);
        $date = $data['date'] ?? '';
        $label = $data['label'] ?? '';
        
        if (!$sessionId || !$date) {
            echo ResponseAPI::error("Session ID and date are required");
            exit;
        }
        
        $oldSession = $db->query("SELECT * FROM attendance_session WHERE id = $sessionId")->fetch_assoc();
        
        $stmt = $db->prepare("UPDATE attendance_session SET date = ?, label = ? WHERE id = ?");
        $stmt->bind_param("ssi", $date, $label, $sessionId);
        
        if ($stmt->execute() && $oldSession) {
            logAudit($db, $faculty_id, 'update_session', 'attendance_session', $sessionId,
                json_encode($oldSession),
                json_encode(['date' => $date, 'label' => $label])
            );
        }
        
        echo $stmt->execute() ? ResponseAPI::success([], "Session updated") 
            : ResponseAPI::error("Failed to update session");
    }
    elseif ($action === 'delete_attendance_session') {
        $data = json_decode(file_get_contents("php://input"), true);
        $sessionId = intval($data['id'] ?? 0);
        
        if (!$sessionId) {
            echo ResponseAPI::error("Invalid session ID");
            exit;
        }
        
        $oldSession = $db->query("SELECT * FROM attendance_session WHERE id = $sessionId")->fetch_assoc();
        
        $db->query("DELETE FROM attendance_record WHERE attendance_session_id = $sessionId");
        $db->query("DELETE FROM attendance_session WHERE id = $sessionId");
        
        if ($oldSession) {
            logAudit($db, $faculty_id, 'delete_session', 'attendance_session', $sessionId,
                json_encode($oldSession), null
            );
        }
        
        echo ResponseAPI::success([], "Session deleted");
    }
    elseif ($action === 'get_attendance_summary') {
        $classId = intval($_GET['class_id']);
        
        $students = $db->query("SELECT * FROM student WHERE class_section_id = $classId ORDER BY last_name")->fetch_all(MYSQLI_ASSOC);
        $lateCountsAsPresent = getAttendanceLateCountsPresent($db, $classId);
        
        $summary = [];
        foreach ($students as $student) {
            $stats = AttendanceHelper::getStudentAttendanceRate($student['id'], $classId, $lateCountsAsPresent);
            $summary[] = array_merge($student, $stats);
        }
        
        echo ResponseAPI::success($summary);
    }
    elseif ($action === 'get_attendance_config') {
        $classId = intval($_GET['class_id']);
        $lateCounts = getAttendanceLateCountsPresent($db, $classId);
        echo ResponseAPI::success(['attendance_late_counts_present' => $lateCounts ? 1 : 0]);
    }
    elseif ($action === 'update_attendance_config') {
        $data = json_decode(file_get_contents("php://input"), true);
        $classId = intval($data['class_id']);
        $lateCounts = isset($data['attendance_late_counts_present']) ? ($data['attendance_late_counts_present'] ? 1 : 0) : 0;
        
        $columnCheck = $db->query("SHOW COLUMNS FROM class_section LIKE 'attendance_late_counts_present'");
        if ($columnCheck && $columnCheck->num_rows > 0) {
            $stmt = $db->prepare("UPDATE class_section SET attendance_late_counts_present = ? WHERE id = ?");
            $stmt->bind_param("ii", $lateCounts, $classId);
            
            if ($stmt->execute()) {
                logAudit($db, $faculty_id, 'update_attendance_config', 'class_section', $classId,
                    null, json_encode(['attendance_late_counts_present' => $lateCounts])
                );
                echo ResponseAPI::success(['attendance_late_counts_present' => $lateCounts], "Configuration updated");
            } else {
                echo ResponseAPI::error("Failed to update configuration");
            }
        } else {
            echo ResponseAPI::success(['attendance_late_counts_present' => 0], "Configuration updated (column not yet migrated)");
        }
    }
    elseif ($action === 'get_attendance_weekdays') {
        $classId = intval($_GET['class_id']);
        $weekdays = getAttendanceWeekdays($db, $classId);
        echo ResponseAPI::success(['weekdays' => $weekdays]);
    }
    elseif ($action === 'update_attendance_weekdays') {
        $data = json_decode(file_get_contents("php://input"), true);
        $classId = intval($data['class_id']);
        $weekdays = $data['weekdays'] ?? [1, 2, 3, 4, 5];
        
        if (updateAttendanceWeekdays($db, $classId, $weekdays)) {
            logAudit($db, $faculty_id, 'update_attendance_weekdays', 'class_section', $classId,
                null, json_encode(['attendance_weekdays' => implode(',', $weekdays)])
            );
            echo ResponseAPI::success(['weekdays' => $weekdays], "Weekdays updated");
        } else {
            echo ResponseAPI::success(['weekdays' => [1, 2, 3, 4, 5]], "Weekdays updated (column not yet migrated)");
        }
    }
    elseif ($action === 'log_audit') {
        $data = json_decode(file_get_contents("php://input"), true);
        logAudit($db, $faculty_id, $data['action'] ?? 'unknown', $data['table_name'] ?? '', 
            intval($data['record_id'] ?? 0), $data['old_value'] ?? null, $data['new_value'] ?? null);
        echo ResponseAPI::success([], "Audit logged");
    }
    elseif ($action === 'get_sections') {
        $program = $_GET['program'] ?? '';
        $year = $_GET['year'] ?? '';
        $section = $_GET['section'] ?? '';
        $ay = $_GET['ay'] ?? '';
        
        $query = "SELECT cs.course_program, cs.year_level, cs.section, cs.academic_year,
                  COUNT(DISTINCT s.student_no) as count
                  FROM class_section cs
                  LEFT JOIN student s ON cs.id = s.class_section_id
                  WHERE cs.faculty_id = ?
                  GROUP BY cs.course_program, cs.year_level, cs.section, cs.academic_year
                  ORDER BY cs.academic_year DESC";
        
        $stmt = $db->prepare($query);
        $stmt->bind_param("i", $faculty_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        if ($program || $year || $section || $ay) {
            $result = array_values(array_filter($result, function($row) use ($program, $year, $section, $ay) {
                if ($program && $row['course_program'] != $program) return false;
                if ($year && $row['year_level'] != $year) return false;
                if ($section && $row['section'] != $section) return false;
                if ($ay && $row['academic_year'] != $ay) return false;
                return true;
            }));
        }
        
        echo ResponseAPI::success($result);
    }
    elseif ($action === 'get_enrolled_sections') {
        $sections = $db->query("SELECT DISTINCT cs.course_program, cs.year_level, cs.section, cs.academic_year, 
            COUNT(DISTINCT s.student_no) as count
            FROM class_section cs
            LEFT JOIN student s ON cs.id = s.class_section_id
            WHERE cs.faculty_id = $faculty_id
            GROUP BY cs.course_program, cs.year_level, cs.section, cs.academic_year
            ORDER BY cs.year_level ASC, cs.section ASC")->fetch_all(MYSQLI_ASSOC);
        echo ResponseAPI::success($sections);
    }
    elseif ($action === 'get_compatible_classes') {
        $program = $_GET['program'] ?? '';
        $year = intval($_GET['year'] ?? 0);
        $section = $_GET['section'] ?? '';
        $ay = $_GET['ay'] ?? '';
        $semester = intval($_GET['semester'] ?? 0);
        
        $query = "SELECT cs.*, s.code, s.title, s.default_units,
            (SELECT COUNT(*) FROM student WHERE class_section_id = cs.id) as student_count
            FROM class_section cs 
            JOIN subject s ON cs.subject_id = s.id 
            WHERE cs.faculty_id = ?";
        $params = [];
        $types = 'i';
        
        if ($program) { $query .= " AND cs.course_program = ?"; $params[] = $program; $types .= 's'; }
        if ($year) { $query .= " AND cs.year_level = ?"; $params[] = $year; $types .= 'i'; }
        if ($section) { $query .= " AND cs.section = ?"; $params[] = $section; $types .= 's'; }
        if ($ay) { $query .= " AND cs.academic_year = ?"; $params[] = $ay; $types .= 's'; }
        if ($semester) { $query .= " AND cs.semester = ?"; $params[] = $semester; $types .= 'i'; }
        
        $query .= " ORDER BY cs.academic_year DESC, cs.semester DESC";
        
        $stmt = $db->prepare($query);
        $stmt->bind_param($types, ...array_merge([$faculty_id], $params));
        $stmt->execute();
        echo ResponseAPI::success($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }
    elseif ($action === 'get_section_students') {
        $program = $_GET['program'] ?? '';
        $year = intval($_GET['year'] ?? 0);
        $section = $_GET['section'] ?? '';
        $ay = $_GET['ay'] ?? '';
        
        $query = "SELECT MIN(s.id) as id, s.student_no, s.last_name, s.first_name, s.middle_initial,
                  cs.course_program, cs.year_level, cs.section, cs.academic_year
                  FROM student s
                  JOIN class_section cs ON s.class_section_id = cs.id
                  WHERE cs.faculty_id = ?";
        $params = [];
        $types = 'i';
        $params[] = $faculty_id;
        
        if ($program) { $query .= " AND cs.course_program = ?"; $params[] = $program; $types .= 's'; }
        if ($year) { $query .= " AND cs.year_level = ?"; $params[] = $year; $types .= 'i'; }
        if ($section) { $query .= " AND cs.section = ?"; $params[] = $section; $types .= 's'; }
        if ($ay) { $query .= " AND cs.academic_year = ?"; $params[] = $ay; $types .= 's'; }
        
        $query .= " GROUP BY s.student_no ORDER BY s.last_name";
        
        $stmt = $db->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        echo ResponseAPI::success($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }
    elseif ($action === 'add_section_student') {
        $data = json_decode(file_get_contents("php://input"), true);
        
        $program = $db->real_escape_string($data['course_program']);
        $year = intval($data['year_level']);
        $section = $db->real_escape_string($data['section']);
        $ay = $db->real_escape_string($data['academic_year']);
        
        // Find matching classes for this section
        $classes = $db->query("SELECT id FROM class_section 
            WHERE course_program = '$program' AND year_level = $year AND section = '$section' AND academic_year = '$ay'")->fetch_all(MYSQLI_ASSOC);
        
        // If no matching class exists, create a default one
        if (empty($classes)) {
            // Get or create a default subject (use INSERT IGNORE to prevent duplicates)
            $defaultSubject = $db->query("SELECT id FROM subject WHERE code = 'GEN' LIMIT 1")->fetch_assoc();
            if (!$defaultSubject) {
                $db->query("INSERT IGNORE INTO subject (code, title, default_units) VALUES ('GEN', 'General Enrollment', 3)");
                $defaultSubject = $db->query("SELECT id FROM subject WHERE code = 'GEN' LIMIT 1")->fetch_assoc();
            }
            $subjectId = $defaultSubject ? $defaultSubject['id'] : null;
            if (!$subjectId) {
                echo ResponseAPI::error("Failed to get or create default subject");
                exit;
            }
            
            // Create the class
            $stmt = $db->prepare("INSERT INTO class_section 
                (subject_id, faculty_id, course_program, year_level, section, semester, academic_year) 
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $semester = 1;
            $stmt->bind_param("iisisis", $subjectId, $faculty_id, $program, $year, $section, $semester, $ay);
            if ($stmt->execute()) {
                $classId = $db->insert_id;
                $classes = [['id' => $classId]];
                
                // Create default grade categories
                $categories = [
                    ['midterm', 'Class Standing', 20],
                    ['midterm', 'Quizzes', 30],
                    ['midterm', 'Exam', 50],
                    ['final', 'Class Standing', 20],
                    ['final', 'Quizzes', 30],
                    ['final', 'Exam', 50]
                ];
                foreach ($categories as $cat) {
                    $stmt2 = $db->prepare("INSERT INTO grade_category (class_section_id, period, name, weight_percent) VALUES (?, ?, ?, ?)");
                    $stmt2->bind_param("issi", $classId, $cat[0], $cat[1], $cat[2]);
                    $stmt2->execute();
                }
            } else {
                echo ResponseAPI::error("Failed to create class: " . $db->error);
                exit;
            }
        }
        
        $enrolledCount = 0;
        foreach ($classes as $class) {
            // Check if student already exists in this class
            $check = $db->prepare("SELECT id FROM student WHERE class_section_id = ? AND student_no = ?");
            $check->bind_param("is", $class['id'], $data['student_no']);
            $check->execute();
            if ($check->get_result()->fetch_assoc()) {
                continue;
            }
            
            // Insert directly into student table
            $insert = $db->prepare("INSERT INTO student (class_section_id, last_name, first_name, middle_initial, student_no) 
                VALUES (?, ?, ?, ?, ?)");
            $insert->bind_param("issss", $class['id'], $data['last_name'], $data['first_name'], $data['middle_initial'], $data['student_no']);
            if ($insert->execute()) {
                $enrolledCount++;
            } else {
                echo ResponseAPI::error("Failed to enroll student: " . $insert->error);
                exit;
            }
        }
        
        if ($enrolledCount > 0) {
            echo ResponseAPI::success(['enrolled_in_classes' => $enrolledCount], "Student enrolled in $enrolledCount class(es)", 201);
        } else {
            echo ResponseAPI::error("Student already enrolled in all matching classes");
        }
    }
    elseif ($action === 'remove_section_student') {
        $data = json_decode(file_get_contents("php://input"), true);
        $id = intval($data['id']);
        
        $stmt = $db->prepare("SELECT s.student_no, cs.course_program, cs.year_level, cs.section, cs.academic_year 
            FROM student s 
            JOIN class_section cs ON s.class_section_id = cs.id 
            WHERE s.id = ? AND cs.faculty_id = ?");
        $stmt->bind_param("ii", $id, $faculty_id);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        
        if (!$current) {
            echo ResponseAPI::error("Student not found");
            exit;
        }
        
        $program = $db->real_escape_string($current['course_program']);
        $year = intval($current['year_level']);
        $section = $db->real_escape_string($current['section']);
        $ay = $db->real_escape_string($current['academic_year']);
        
        $db->query("DELETE s FROM student s
            JOIN class_section cs ON s.class_section_id = cs.id
            WHERE s.student_no = '{$current['student_no']}'
            AND cs.course_program = '$program' AND cs.year_level = $year AND cs.section = '$section' AND cs.academic_year = '$ay'");
        
        echo ResponseAPI::success([], "Student removed");
    }
    elseif ($action === 'update_section_student') {
        $data = json_decode(file_get_contents("php://input"), true);
        $id = intval($data['id']);
        
        $stmt = $db->prepare("SELECT s.student_no, cs.course_program, cs.year_level, cs.section, cs.academic_year 
            FROM student s 
            JOIN class_section cs ON s.class_section_id = cs.id 
            WHERE s.id = ? AND cs.faculty_id = ?");
        $stmt->bind_param("ii", $id, $faculty_id);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        
        if (!$current) {
            echo ResponseAPI::error("Student not found");
            exit;
        }
        
        $program = $db->real_escape_string($data['course_program']);
        $year = intval($data['year_level']);
        $section = $db->real_escape_string($data['section']);
        $ay = $db->real_escape_string($data['academic_year']);
        
        $stmt = $db->prepare("UPDATE student SET last_name = ?, first_name = ?, middle_initial = ?, student_no = ? 
            WHERE id = ?");
        $stmt->bind_param("ssssi", $data['last_name'], $data['first_name'], $data['middle_initial'], $data['student_no'], $id);
        $stmt->execute();
        
        $oldStudentNo = $db->real_escape_string($current['student_no']);
        $db->query("UPDATE student s 
            JOIN class_section cs ON s.class_section_id = cs.id 
            SET s.student_no = '{$data['student_no']}', s.last_name = '{$data['last_name']}', 
                s.first_name = '{$data['first_name']}', s.middle_initial = '{$data['middle_initial']}'
            WHERE cs.course_program = '$program' AND cs.year_level = $year AND cs.section = '$section' 
                AND cs.academic_year = '$ay' AND s.student_no = '$oldStudentNo'");
        
        echo ResponseAPI::success([], "Student updated");
    }
    elseif ($action === 'update_class_weights') {
        $data = json_decode(file_get_contents("php://input"), true);
        $classId = intval($data['class_id']);
        $midtermWeight = floatval($data['midterm_weight']) / 100;
        $finalWeight = floatval($data['final_weight']) / 100;
        
        $verify_stmt = $db->prepare("SELECT id FROM class_section WHERE id = ? AND faculty_id = ?");
        $verify_stmt->bind_param("ii", $classId, $faculty_id);
        $verify_stmt->execute();
        if (!$verify_stmt->get_result()->fetch_assoc()) {
            echo ResponseAPI::error("Unauthorized");
            exit;
        }
        
        $stmt = $db->prepare("UPDATE class_section SET midterm_weight = ?, final_weight = ? WHERE id = ?");
        $stmt->bind_param("ddi", $midtermWeight, $finalWeight);
        echo $stmt->execute() ? ResponseAPI::success([], "Weights updated") 
            : ResponseAPI::error("Failed to update weights");
    }
    // Dashboard Statistics
    elseif ($action === 'get_dashboard_grades') {
        $result = $db->query("SELECT 
            COUNT(CASE WHEN gs.raw_score >= 90 THEN 1 END) as gradeA,
            COUNT(CASE WHEN gs.raw_score >= 80 AND gs.raw_score < 90 THEN 1 END) as gradeB,
            COUNT(CASE WHEN gs.raw_score >= 70 AND gs.raw_score < 80 THEN 1 END) as gradeC,
            COUNT(CASE WHEN gs.raw_score >= 60 AND gs.raw_score < 70 THEN 1 END) as gradeD,
            COUNT(CASE WHEN gs.raw_score < 60 THEN 1 END) as gradeF
            FROM grade_score gs
            JOIN grade_item gi ON gs.grade_item_id = gi.id
            JOIN grade_category gc ON gi.grade_category_id = gc.id
            JOIN class_section cs ON gc.class_section_id = cs.id
            WHERE cs.faculty_id = $faculty_id");
        echo ResponseAPI::success($result->fetch_assoc());
    }
    elseif ($action === 'get_dashboard_averages') {
        $result = $db->query("SELECT 
            s.code, cs.course_program, cs.year_level, cs.section,
            ROUND(AVG(gs.raw_score), 2) as average
            FROM grade_score gs
            JOIN grade_item gi ON gs.grade_item_id = gi.id
            JOIN grade_category gc ON gi.grade_category_id = gc.id
            JOIN class_section cs ON gc.class_section_id = cs.id
            JOIN subject s ON cs.subject_id = s.id
            WHERE cs.faculty_id = $faculty_id
            GROUP BY cs.id, s.code, cs.course_program, cs.year_level, cs.section ORDER BY cs.created_at DESC LIMIT 10");
        $data = $result->fetch_all(MYSQLI_ASSOC);
        foreach ($data as &$row) { $row['class_name'] = $row['code']; }
        echo ResponseAPI::success($data);
    }
    elseif ($action === 'get_dashboard_attendance') {
        $result = $db->query("SELECT 
            s.code, cs.course_program, cs.year_level, cs.section,
            ROUND((COUNT(CASE WHEN ar.status = 'present' THEN 1 END) / COUNT(*) * 100), 1) as attendance_rate
            FROM attendance_record ar
            JOIN attendance_session ase ON ar.attendance_session_id = ase.id
            JOIN class_section cs ON ase.class_section_id = cs.id
            JOIN subject s ON cs.subject_id = s.id
            WHERE cs.faculty_id = $faculty_id
            GROUP BY cs.id, s.code, cs.course_program, cs.year_level, cs.section ORDER BY cs.created_at DESC LIMIT 8");
        $data = $result->fetch_all(MYSQLI_ASSOC);
        foreach ($data as &$row) { $row['class_name'] = $row['code']; }
        echo ResponseAPI::success($data);
    }
    elseif ($action === 'get_dashboard_attendance_daily') {
        $result = $db->query("SELECT 
            DATE(ase.date) as attendance_date,
            s.code as subject_code,
            cs.course_program as department,
            COUNT(DISTINCT ar.student_id) as students_present
            FROM attendance_record ar
            JOIN attendance_session ase ON ar.attendance_session_id = ase.id
            JOIN class_section cs ON ase.class_section_id = cs.id
            JOIN subject s ON cs.subject_id = s.id
            WHERE cs.faculty_id = $faculty_id
            AND ar.status = 'present'
            GROUP BY DATE(ase.date), cs.id
            ORDER BY DATE(ase.date) ASC
            LIMIT 30");
        echo ResponseAPI::success($result->fetch_all(MYSQLI_ASSOC));
    }
    elseif ($action === 'get_dashboard_attendance_review') {
        $result = $db->query("SELECT 
            s.code, s.title, cs.course_program, cs.year_level, cs.section,
            ase.date, ase.label,
            COUNT(DISTINCT ar.student_id) as total_students,
            COUNT(DISTINCT CASE WHEN ar.status = 'present' THEN ar.student_id END) as present_students,
            ROUND((COUNT(DISTINCT CASE WHEN ar.status = 'present' THEN ar.student_id END) / COUNT(DISTINCT ar.student_id) * 100), 1) as attendance_rate
            FROM attendance_record ar
            JOIN attendance_session ase ON ar.attendance_session_id = ase.id
            JOIN class_section cs ON ase.class_section_id = cs.id
            JOIN subject s ON cs.subject_id = s.id
            WHERE cs.faculty_id = $faculty_id
            GROUP BY ase.id
            ORDER BY ase.date DESC, cs.created_at DESC
            LIMIT 15");
        echo ResponseAPI::success($result->fetch_all(MYSQLI_ASSOC));
    }
    elseif ($action === 'get_dashboard_performance') {
        $r1 = $db->query("SELECT ROUND(AVG(raw_score), 2) as overall_average
            FROM grade_score gs
            JOIN grade_item gi ON gs.grade_item_id = gi.id
            JOIN grade_category gc ON gi.grade_category_id = gc.id
            JOIN class_section cs ON gc.class_section_id = cs.id
            WHERE cs.faculty_id = $faculty_id");
        $r2 = $db->query("SELECT ROUND(COUNT(CASE WHEN ar.status = 'present' THEN 1 END) / COUNT(*) * 100, 1) as overall_attendance
            FROM attendance_record ar
            JOIN attendance_session ase ON ar.attendance_session_id = ase.id
            JOIN class_section cs ON ase.class_section_id = cs.id
            WHERE cs.faculty_id = $faculty_id");
        $r3 = $db->query("SELECT COUNT(DISTINCT cs.id) as high_performance_count
            FROM class_section cs
            WHERE cs.faculty_id = $faculty_id
            AND (SELECT ROUND(AVG(gs.raw_score), 2)
                FROM grade_score gs
                JOIN grade_item gi ON gs.grade_item_id = gi.id
                JOIN grade_category gc ON gi.grade_category_id = gc.id
                WHERE gc.class_section_id = cs.id) >= 75");
        $r4 = $db->query("SELECT COUNT(CASE WHEN gs.raw_score >= 90 THEN 1 END) as grade_a_count
            FROM grade_score gs
            JOIN grade_item gi ON gs.grade_item_id = gi.id
            JOIN grade_category gc ON gi.grade_category_id = gc.id
            JOIN class_section cs ON gc.class_section_id = cs.id
            WHERE cs.faculty_id = $faculty_id");
        $data = array_merge($r1->fetch_assoc() ?: [], $r2->fetch_assoc() ?: [], $r3->fetch_assoc() ?: [], $r4->fetch_assoc() ?: []);
        echo ResponseAPI::success($data);
    }
    elseif ($action === 'generate_report') {
        $classId = intval($_GET['class_id']);
        $format = $_GET['format'] ?? 'pdf';
        $class = $db->query("SELECT cs.*, s.code, s.title FROM class_section cs 
            JOIN subject s ON cs.subject_id = s.id WHERE cs.id = $classId")->fetch_assoc();
        $students = $db->query("SELECT * FROM student WHERE class_section_id = $classId ORDER BY last_name")->fetch_all(MYSQLI_ASSOC);
        
        if ($format === 'xlsx' || $format === 'excel') {
            generateExcelReport($class, $students);
        } else {
            generatePdfEGrading($class, $students);
        }
    }
    elseif ($action === 'export_egrading') {
        $classId = intval($_GET['class_id']);
        $format = $_GET['format'] ?? 'pdf';
        $class = $db->query("SELECT cs.*, s.code, s.title FROM class_section cs 
            JOIN subject s ON cs.subject_id = s.id WHERE cs.id = $classId")->fetch_assoc();
        $students = $db->query("SELECT * FROM student WHERE class_section_id = $classId ORDER BY last_name")->fetch_all(MYSQLI_ASSOC);
        
        if ($format === 'xlsx' || $format === 'excel') {
            generateExcelReport($class, $students);
        } else {
            generatePdfEGrading($class, $students);
        }
    }
    elseif ($action === 'export_attendance') {
        $classId = intval($_GET['class_id']);
        $format = $_GET['format'] ?? 'csv';
        $class = $db->query("SELECT cs.*, s.code, s.title FROM class_section cs 
            JOIN subject s ON cs.subject_id = s.id WHERE cs.id = $classId")->fetch_assoc();
        $students = $db->query("SELECT * FROM student WHERE class_section_id = $classId ORDER BY last_name")->fetch_all(MYSQLI_ASSOC);
        $sessions = $db->query("SELECT * FROM attendance_session WHERE class_section_id = $classId ORDER BY date ASC")->fetch_all(MYSQLI_ASSOC);
        
        if ($format === 'xlsx' || $format === 'excel') {
            generateAttendanceExcel($class, $students, $sessions);
        } else {
            generateAttendanceCSV($class, $students, $sessions);
        }
    }
    // GE-104 Grading System Endpoints
    elseif ($action === 'get_component_perfect_scores') {
        $classId = intval($_GET['class_id'] ?? 0);
        $period = $_GET['period'] ?? 'midterm';
        
        $stmt = $db->prepare("SELECT component_type, perfect_score FROM grade_component_perfect_score WHERE class_section_id = ? AND period = ?");
        $stmt->bind_param("is", $classId, $period);
        $stmt->execute();
        $result = $stmt->get_result();
        $scores = [];
        while ($row = $result->fetch_assoc()) {
            $scores[$row['component_type']] = floatval($row['perfect_score']);
        }
        echo ResponseAPI::success($scores);
    }
    elseif ($action === 'save_component_perfect_scores') {
        $data = json_decode(file_get_contents("php://input"), true);
        $classId = intval($data['class_id'] ?? 0);
        $period = $data['period'] ?? 'midterm';
        $scores = $data['scores'] ?? [];
        
        if (!$classId) {
            echo ResponseAPI::error("Invalid class ID");
            exit;
        }
        
        $components = ['class_participation', 'problem_set', 'quizzes', 'periodical_exam'];
        foreach ($components as $component) {
            $perfectScore = floatval($scores[$component] ?? 0);
            $stmt = $db->prepare("INSERT INTO grade_component_perfect_score (class_section_id, period, component_type, perfect_score) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE perfect_score = VALUES(perfect_score)");
            $stmt->bind_param("issd", $classId, $period, $component, $perfectScore);
            $stmt->execute();
        }
        echo ResponseAPI::success([], "Perfect scores saved");
    }
    elseif ($action === 'get_class_grades') {
        $classId = intval($_GET['class_id'] ?? 0);
        
        $stmt = $db->prepare("SELECT cs.*, s.code, s.title FROM class_section cs JOIN subject s ON cs.subject_id = s.id WHERE cs.id = ? AND cs.faculty_id = ?");
        $stmt->bind_param("ii", $classId, $faculty_id);
        $stmt->execute();
        $class = $stmt->get_result()->fetch_assoc();
        
        if (!$class) {
            echo ResponseAPI::error("Class not found");
            exit;
        }
        
        $students = $db->query("SELECT * FROM student WHERE class_section_id = $classId ORDER BY last_name")->fetch_all(MYSQLI_ASSOC);
        
        $gradesData = [];
        foreach ($students as $student) {
            $grades = GradingHelper::calculateStudentGrades($db, $classId, $student['id']);
            $gradesData[] = array_merge($student, $grades);
        }
        
        echo ResponseAPI::success([
            'class' => $class,
            'grades' => $gradesData
        ]);
    }
    elseif ($action === 'get_student_grade_report') {
        $classId = intval($_GET['class_id'] ?? 0);
        $studentId = intval($_GET['student_id'] ?? 0);
        
        $grades = GradingHelper::calculateStudentGrades($db, $classId, $studentId);
        
        // Get student info
        $stmt = $db->prepare("SELECT * FROM student WHERE id = ?");
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        
        echo ResponseAPI::success(array_merge($student ?? [], $grades));
    }
    elseif ($action === 'generate_class_record') {
        $classId = intval($_GET['class_id'] ?? 0);
        $format = $_GET['format'] ?? 'pdf';
        
        $class = $db->query("SELECT cs.*, s.code, s.title FROM class_section cs JOIN subject s ON cs.subject_id = s.id WHERE cs.id = $classId")->fetch_assoc();
        $students = $db->query("SELECT * FROM student WHERE class_section_id = $classId ORDER BY last_name")->fetch_all(MYSQLI_ASSOC);
        
        $gradesData = [];
        foreach ($students as $student) {
            $grades = GradingHelper::calculateStudentGrades($db, $classId, $student['id']);
            $gradesData[] = array_merge($student, $grades);
        }
        
        if ($format === 'xlsx' || $format === 'excel') {
            generateClassRecordExcel($class, $gradesData);
        } else {
            generateClassRecordPdf($class, $gradesData);
        }
    }
    elseif ($action === 'generate_egrading_report') {
        $classId = intval($_GET['class_id'] ?? 0);
        $format = $_GET['format'] ?? 'pdf';
        
        $class = $db->query("SELECT cs.*, s.code, s.title FROM class_section cs JOIN subject s ON cs.subject_id = s.id WHERE cs.id = $classId")->fetch_assoc();
        $students = $db->query("SELECT * FROM student WHERE class_section_id = $classId ORDER BY last_name")->fetch_all(MYSQLI_ASSOC);
        
        $gradesData = [];
        foreach ($students as $student) {
            $grades = GradingHelper::calculateStudentGrades($db, $classId, $student['id']);
            $gradesData[] = array_merge($student, $grades);
        }
        
        if ($format === 'xlsx' || $format === 'excel') {
            generateEGradingExcel($class, $gradesData);
        } else {
            generateEGradingPdf($class, $gradesData);
        }
    }
    elseif ($action === 'generate_grading_sheet_report') {
        $classId = intval($_GET['class_id'] ?? 0);
        $format = $_GET['format'] ?? 'pdf';
        
        $class = $db->query("SELECT cs.*, s.code, s.title FROM class_section cs JOIN subject s ON cs.subject_id = s.id WHERE cs.id = $classId")->fetch_assoc();
        $students = $db->query("SELECT * FROM student WHERE class_section_id = $classId ORDER BY last_name")->fetch_all(MYSQLI_ASSOC);
        
        $gradesData = [];
        foreach ($students as $student) {
            $grades = GradingHelper::calculateStudentGrades($db, $classId, $student['id']);
            $gradesData[] = array_merge($student, $grades);
        }
        
        if ($format === 'xlsx' || $format === 'excel') {
            generateGradingSheetExcel($class, $gradesData);
        } elseif ($format === 'doc' || $format === 'word') {
            generateGradingSheetDoc($class, $gradesData);
        } else {
            generateGradingSheetPdf($class, $gradesData);
        }
    }
    // Dynamic Grade Category CRUD Endpoints
    elseif ($action === 'get_grade_templates') {
        $result = $db->query("SELECT * FROM grade_category_template WHERE is_active = TRUE ORDER BY sort_order");
        echo ResponseAPI::success($result->fetch_all(MYSQLI_ASSOC));
    }
    elseif ($action === 'get_category_configs') {
        $classId = intval($_GET['class_id'] ?? 0);
        $period = $_GET['period'] ?? 'midterm';
        
        $stmt = $db->prepare("
            SELECT gcc.*, gct.name as template_name, gct.description
            FROM grade_category_config gcc
            LEFT JOIN grade_category_template gct ON gcc.template_id = gct.id
            WHERE gcc.class_section_id = ? AND gcc.period = ?
            ORDER BY gcc.sort_order
        ");
        $stmt->bind_param("is", $classId, $period);
        $stmt->execute();
        $configs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Get items for each config
        foreach ($configs as &$config) {
            $itemsStmt = $db->prepare("
                SELECT * FROM grade_item_config 
                WHERE category_config_id = ? AND is_active = TRUE
                ORDER BY sort_order
            ");
            $itemsStmt->bind_param("i", $config['id']);
            $itemsStmt->execute();
            $config['items'] = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        
        echo ResponseAPI::success($configs);
    }
    elseif ($action === 'save_category_config') {
        $data = json_decode(file_get_contents("php://input"), true);
        $classId = intval($data['class_id'] ?? 0);
        $period = $data['period'] ?? 'midterm';
        $configs = $data['configs'] ?? [];
        
        if (!$classId) {
            echo ResponseAPI::error("Invalid class ID");
            exit;
        }
        
        // Remove synced rows before replacing configs because grade_category.config_id
        // references grade_category_config.id without ON DELETE CASCADE.
        $existingStmt = $db->prepare("SELECT id FROM grade_category_config WHERE class_section_id = ? AND period = ?");
        $existingStmt->bind_param("is", $classId, $period);
        $existingStmt->execute();
        $existingConfigs = $existingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        removeSyncedConfigRows($db, array_column($existingConfigs, 'id'));

        // Delete existing configs for this class/period (full replace)
        $stmt = $db->prepare("DELETE FROM grade_category_config WHERE class_section_id = ? AND period = ?");
        $stmt->bind_param("is", $classId, $period);
        $stmt->execute();
        
        // Insert new configs
        $sortOrder = 0;
        foreach ($configs as $config) {
            $templateId = isset($config['template_id']) && intval($config['template_id']) > 0
                ? intval($config['template_id'])
                : null;
            if ($templateId !== null) {
                $templateCheck = $db->prepare("SELECT id FROM grade_category_template WHERE id = ? AND is_active = TRUE");
                $templateCheck->bind_param("i", $templateId);
                $templateCheck->execute();
                if (!$templateCheck->get_result()->fetch_assoc()) {
                    $templateId = null;
                }
            }
            $customName = trim($config['custom_name'] ?? '');
            $weight = floatval($config['weight_percent'] ?? 0);
            $perfectScore = floatval($config['perfect_score'] ?? 0);
            $itemCount = intval($config['item_count'] ?? 1);
            $isVisible = isset($config['is_visible']) ? (bool)$config['is_visible'] : true;
            $items = $config['items'] ?? [];
            
            $stmt = $db->prepare("
                INSERT INTO grade_category_config (class_section_id, period, template_id, custom_name, weight_percent, perfect_score, item_count, sort_order, is_visible)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isisddiii", $classId, $period, $templateId, $customName, $weight, $perfectScore, $itemCount, $sortOrder, $isVisible);
            if (!$stmt->execute()) {
                echo ResponseAPI::error("Failed to save category: " . $stmt->error, 500);
                exit;
            }
            
            $configId = $db->insert_id;
            
            // Insert items
            $itemSort = 0;
            foreach ($items as $item) {
                $label = trim($item['label'] ?? '');
                $maxScore = floatval($item['max_score'] ?? 0);
                if ($label) {
                    $itemStmt = $db->prepare("
                        INSERT INTO grade_item_config (category_config_id, label, max_score, sort_order)
                        VALUES (?, ?, ?, ?)
                    ");
                    $itemStmt->bind_param("isdi", $configId, $label, $maxScore, $itemSort);
                    $itemStmt->execute();
                    $itemSort++;
                }
            }
            
            $sortOrder++;
        }
        
        // Sync to grade_category and grade_item tables
        syncGradeTables($db, $classId, $period);
        
        echo ResponseAPI::success([], "Grade categories saved");
    }
    elseif ($action === 'delete_category_config') {
        $configId = intval($_GET['config_id'] ?? 0);
        $classId = intval($_GET['class_id'] ?? 0);
        $period = $_GET['period'] ?? 'midterm';
        
        removeSyncedConfigRows($db, [$configId]);

        $stmt = $db->prepare("DELETE FROM grade_category_config WHERE id = ? AND class_section_id = ? AND period = ?");
        $stmt->bind_param("iii", $configId, $classId, $period);
        $stmt->execute();
        
        // Sync to grade_category and grade_item tables
        syncGradeTables($db, $classId, $period);
        
        echo ResponseAPI::success([], "Category deleted");
    }
    elseif ($action === 'update_category_config_weight') {
        $data = json_decode(file_get_contents('php://input'), true);
        $configId = intval($data['config_id'] ?? 0);
        $classId = intval($data['class_id'] ?? 0);
        $period = $data['period'] ?? 'midterm';
        $weight = floatval($data['weight_percent'] ?? -1);

        if (!$configId || !$classId || $weight < 0 || $weight > 100) {
            echo ResponseAPI::error('Invalid category weight');
            exit;
        }

        $stmt = $db->prepare('UPDATE grade_category_config SET weight_percent = ? WHERE id = ? AND class_section_id = ? AND period = ?');
        $stmt->bind_param('diis', $weight, $configId, $classId, $period);
        if (!$stmt->execute()) {
            echo ResponseAPI::error('Unable to update category weight');
            exit;
        }

        syncGradeTables($db, $classId, $period);
        echo ResponseAPI::success([], 'Category weight updated');
    }
    elseif ($action === 'sync_grade_tables') {
        $classId = intval($_GET['class_id'] ?? 0);
        $period = $_GET['period'] ?? 'midterm';
        
        syncGradeTables($db, $classId, $period);
        
        echo ResponseAPI::success([], "Tables synced");
    }
    else {
        echo ResponseAPI::error("Invalid action", 404);
    }
} catch (Exception $e) {
    ob_clean();
    echo ResponseAPI::error($e->getMessage(), 500);
}

// Helper function to sync grade_category and grade_item from configs
function syncGradeTables($db, $classId, $period) {
    // Get configs
    $stmt = $db->prepare("SELECT * FROM grade_category_config WHERE class_section_id = ? AND period = ? ORDER BY sort_order");
    $stmt->bind_param("is", $classId, $period);
    $stmt->execute();
    $configs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // For each config, ensure grade_category exists
    foreach ($configs as $config) {
        $catStmt = $db->prepare("SELECT id FROM grade_category WHERE config_id = ?");
        $catStmt->bind_param("i", $config['id']);
        $catStmt->execute();
        $catResult = $catStmt->get_result()->fetch_assoc();
        
        $categoryName = $config['custom_name'] ?: ($config['template_name'] ?? 'Category');
        
        if ($catResult) {
            $db->query("UPDATE grade_category SET name = '{$db->real_escape_string($categoryName)}', weight_percent = {$config['weight_percent']} WHERE id = {$catResult['id']}");
            $categoryId = $catResult['id'];
        } else {
            $db->query("INSERT INTO grade_category (class_section_id, period, name, weight_percent, config_id, sort_order) VALUES ($classId, '$period', '{$db->real_escape_string($categoryName)}', {$config['weight_percent']}, {$config['id']}, {$config['sort_order']})");
            $categoryId = $db->insert_id;
        }
        
        // Get items for this config
        $itemsStmt = $db->prepare("SELECT * FROM grade_item_config WHERE category_config_id = ? AND is_active = TRUE ORDER BY sort_order");
        $itemsStmt->bind_param("i", $config['id']);
        $itemsStmt->execute();
        $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Sync items
        foreach ($items as $item) {
            $itemStmt = $db->prepare("SELECT id FROM grade_item WHERE item_config_id = ?");
            $itemStmt->bind_param("i", $item['id']);
            $itemStmt->execute();
            $itemResult = $itemStmt->get_result()->fetch_assoc();
            
            if ($itemResult) {
                $db->query("UPDATE grade_item SET label = '{$db->real_escape_string($item['label'])}', max_score = {$item['max_score']} WHERE id = {$itemResult['id']}");
            } else {
                $db->query("INSERT INTO grade_item (grade_category_id, label, max_score, item_config_id, sort_order) VALUES ($categoryId, '{$db->real_escape_string($item['label'])}', {$item['max_score']}, {$item['id']}, {$item['sort_order']})");
            }
        }
        
        // Remove items that no longer exist in config
        $existingItemConfigIds = array_column($items, 'id');
        if (!empty($existingItemConfigIds)) {
            $placeholders = implode(',', array_fill(0, count($existingItemConfigIds), '?'));
            $types = str_repeat('i', count($existingItemConfigIds));
            $params = array_merge([$categoryId], $existingItemConfigIds);
            $stmt = $db->prepare("DELETE FROM grade_item WHERE grade_category_id = ? AND item_config_id NOT IN ($placeholders)");
            $stmt->bind_param("i$types", ...$params);
            $stmt->execute();
        } else {
            $db->query("DELETE FROM grade_item WHERE grade_category_id = $categoryId");
        }
    }
    
    // Remove categories that no longer exist in config
    $existingConfigIds = array_column($configs, 'id');
    if (!empty($existingConfigIds)) {
        $placeholders = implode(',', array_fill(0, count($existingConfigIds), '?'));
        $types = str_repeat('i', count($existingConfigIds));
        $params = array_merge([$classId, $period], $existingConfigIds);
        $stmt = $db->prepare("DELETE FROM grade_category WHERE class_section_id = ? AND period = ? AND config_id NOT IN ($placeholders)");
        $stmt->bind_param("is$types", ...$params);
        $stmt->execute();
    } else {
        $stmt = $db->prepare("DELETE FROM grade_category WHERE class_section_id = ? AND period = ?");
        $stmt->bind_param("is", $classId, $period);
        $stmt->execute();
    }
}

function removeSyncedConfigRows($db, $configIds) {
    $configIds = array_values(array_filter(array_map('intval', $configIds)));
    if (empty($configIds)) {
        return;
    }

    $idList = implode(',', $configIds);

    // Scores depend on grade_item, which depends on grade_category.
    $db->query("DELETE gs FROM grade_score gs
        INNER JOIN grade_item gi ON gi.id = gs.grade_item_id
        INNER JOIN grade_category gc ON gc.id = gi.grade_category_id
        WHERE gc.config_id IN ($idList)");
    $db->query("DELETE gi FROM grade_item gi
        INNER JOIN grade_category gc ON gc.id = gi.grade_category_id
        WHERE gc.config_id IN ($idList)");
    $db->query("DELETE FROM grade_category WHERE config_id IN ($idList)");
}

function generateExcelReport($class, $students) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="ClassRecord_' . $class['code'] . '.csv"');
    
    $fp = fopen('php://output', 'w');
    fprintf($fp, "\xEF\xBB\xBF");
    
    fputcsv($fp, ['CLASS RECORD']);
    fputcsv($fp, ['Course: ' . $class['code'] . ' - ' . $class['title']]);
    fputcsv($fp, ['Class: ' . $class['course_program'] . ' ' . $class['year_level'] . '-' . $class['section']]);
    fputcsv($fp, ['AY: ' . $class['academic_year']]);
    fputcsv($fp, []);
    
    $headers = ['Student No', 'Last Name', 'First Name', 'Midterm', 'Final', 'Overall', 'Grade Point', 'Remarks'];
    fputcsv($fp, $headers);
    
    foreach ($students as $student) {
        fputcsv($fp, [
            $student['student_no'],
            $student['last_name'],
            $student['first_name'],
            '',
            '',
            '',
            '',
            ''
        ]);
    }
}

function generatePdfEGrading($class, $students) {
    echo '<html><head><meta charset="utf-8"><title>E-Grading</title></head><body>';
    echo '<h2>E-GRADING SUBMISSION</h2>';
    echo '</body></html>';
}

function generateAttendanceCSV($class, $students, $sessions) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="Attendance_' . $class['code'] . '.csv"');
    
    $fp = fopen('php://output', 'w');
    fprintf($fp, "\xEF\xBB\xBF");
    
    fputcsv($fp, ['ATTENDANCE REPORT']);
    fputcsv($fp, ['Course: ' . $class['code'] . ' - ' . $class['title']]);
    fputcsv($fp, ['Class: ' . $class['course_program'] . ' ' . $class['year_level'] . '-' . $class['section']]);
    fputcsv($fp, ['AY: ' . $class['academic_year']]);
    fputcsv($fp, []);
    
    $headers = ['Student No', 'Last Name', 'First Name'];
    foreach ($sessions as $session) {
        $headers[] = $session['date'] . ($session['label'] ? ' (' . $session['label'] . ')' : '');
    }
    $headers[] = 'Present';
    $headers[] = 'Absent';
    $headers[] = 'Late';
    $headers[] = 'Excused';
    $headers[] = 'Total Sessions';
    $headers[] = 'Attendance Rate %';
    fputcsv($fp, $headers);
    
    foreach ($students as $student) {
        $row = [$student['student_no'], $student['last_name'], $student['first_name']];
        $present = 0; $absent = 0; $late = 0; $excused = 0;
        
        foreach ($sessions as $session) {
            $record = $db->query("SELECT status FROM attendance_record 
                WHERE attendance_session_id = {$session['id']} AND student_id = {$student['id']}")->fetch_assoc();
            $status = $record ? $record['status'] : 'absent';
            $row[] = ucfirst($status);
            
            if ($status === 'present') $present++;
            elseif ($status === 'absent') $absent++;
            elseif ($status === 'late') $late++;
            elseif ($status === 'excused') $excused++;
        }
        
        $total = count($sessions);
        $rate = $total > 0 ? round((($present + $late) / $total) * 100, 1) : 0;
        
        $row[] = $present;
        $row[] = $absent;
        $row[] = $late;
        $row[] = $excused;
        $row[] = $total;
        $row[] = $rate . '%';
        
        fputcsv($fp, $row);
    }
}

function generateAttendanceExcel($class, $students, $sessions) {
    generateAttendanceCSV($class, $students, $sessions);
}

// ==========================================
// GE-104 Report Generation Functions
// ==========================================

function generateClassRecordExcel($class, $gradesData) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="ClassRecord_' . $class['code'] . '.csv"');
    
    $fp = fopen('php://output', 'w');
    fprintf($fp, "\xEF\xBB\xBF");
    
    fputcsv($fp, ['CLASS RECORD']);
    fputcsv($fp, ['Course: ' . $class['code'] . ' - ' . $class['title']]);
    fputcsv($fp, ['Class: ' . $class['course_program'] . ' ' . $class['year_level'] . '-' . $class['section']]);
    fputcsv($fp, ['AY: ' . $class['academic_year']]);
    fputcsv($fp, []);
    
    // Midterm Detailed
    fputcsv($fp, ['MIDTERM PERIOD']);
    $midHeaders = ['No.', 'Student No', 'Last Name', 'First Name', 'MI',
        'CP1', 'CP2', 'CP3', 'CP4', 'CP Total', 'CP Highest', 'CP Equiv.',
        'PS1', 'PS Total', 'PS Highest', 'PS Equiv.',
        'Q1', 'Q2', 'Quiz Total', 'Quiz Highest', 'Quiz Equiv.',
        'Exam', 'Exam Highest', 'Exam Equiv.',
        'Midterm Grade'];
    fputcsv($fp, $midHeaders);
    
    foreach ($gradesData as $i => $student) {
        $mt = $student['midterm']['components'] ?? [];
        $cp = $mt['class_participation'] ?? [];
        $ps = $mt['problem_set'] ?? [];
        $quiz = $mt['quizzes'] ?? [];
        $exam = $mt['periodical_exam'] ?? [];
        
        $cpItems = $cp['items'] ?? [];
        $psItems = $ps['items'] ?? [];
        $quizItems = $quiz['items'] ?? [];
        $examItems = $exam['items'] ?? [];
        
        $cpTotal = $cp['raw_total'] ?? 0;
        $cpMax = $cp['max_total'] ?? 0;
        $psTotal = $ps['raw_total'] ?? 0;
        $psMax = $ps['max_total'] ?? 0;
        $quizTotal = $quiz['raw_total'] ?? 0;
        $quizMax = $quiz['max_total'] ?? 0;
        $examTotal = $exam['raw_total'] ?? 0;
        $examMax = $exam['max_total'] ?? 0;
        
        $cpHighest = $cp['perfect_score'] ?? $cpMax;
        $psHighest = $ps['perfect_score'] ?? $psMax;
        $quizHighest = $quiz['perfect_score'] ?? $quizMax;
        $examHighest = $exam['perfect_score'] ?? $examMax;
        
        $cpEquiv = GradingHelper::calculateComponentScore($cpTotal, $cpHighest);
        $psEquiv = GradingHelper::calculateComponentScore($psTotal, $psHighest);
        $quizEquiv = GradingHelper::calculateComponentScore($quizTotal, $quizHighest);
        $examEquiv = GradingHelper::calculateComponentScore($examTotal, $examHighest);
        
        fputcsv($fp, [
            $i + 1,
            $student['student_no'],
            $student['last_name'],
            $student['first_name'],
            $student['middle_initial'],
            $cpItems[0]['raw_score'] ?? '',
            $cpItems[1]['raw_score'] ?? '',
            $cpItems[2]['raw_score'] ?? '',
            $cpItems[3]['raw_score'] ?? '',
            $cpTotal,
            $cpHighest,
            round($cpEquiv, 2),
            $psItems[0]['raw_score'] ?? '',
            $psTotal,
            $psHighest,
            round($psEquiv, 2),
            $quizItems[0]['raw_score'] ?? '',
            $quizItems[1]['raw_score'] ?? '',
            $quizTotal,
            $quizHighest,
            round($quizEquiv, 2),
            $examItems[0]['raw_score'] ?? '',
            $examHighest,
            round($examEquiv, 2),
            $student['midterm']['grade']
        ]);
    }
    
    fputcsv($fp, []);
    
    // Final Detailed
    fputcsv($fp, ['FINAL PERIOD']);
    $finHeaders = ['No.', 'Student No', 'Last Name', 'First Name', 'MI',
        'CP1', 'CP2', 'CP3', 'CP4', 'CP Total', 'CP Highest', 'CP Equiv.',
        'PS1', 'PS Total', 'PS Highest', 'PS Equiv.',
        'Q1', 'Q2', 'Quiz Total', 'Quiz Highest', 'Quiz Equiv.',
        'Exam', 'Exam Highest', 'Exam Equiv.',
        'Final Grade'];
    fputcsv($fp, $finHeaders);
    
    foreach ($gradesData as $i => $student) {
        $fn = $student['final']['components'] ?? [];
        $cp = $fn['class_participation'] ?? [];
        $ps = $fn['problem_set'] ?? [];
        $quiz = $fn['quizzes'] ?? [];
        $exam = $fn['periodical_exam'] ?? [];
        
        $cpItems = $cp['items'] ?? [];
        $psItems = $ps['items'] ?? [];
        $quizItems = $quiz['items'] ?? [];
        $examItems = $exam['items'] ?? [];
        
        $cpTotal = $cp['raw_total'] ?? 0;
        $cpMax = $cp['max_total'] ?? 0;
        $psTotal = $ps['raw_total'] ?? 0;
        $psMax = $ps['max_total'] ?? 0;
        $quizTotal = $quiz['raw_total'] ?? 0;
        $quizMax = $quiz['max_total'] ?? 0;
        $examTotal = $exam['raw_total'] ?? 0;
        $examMax = $exam['max_total'] ?? 0;
        
        $cpHighest = $cp['perfect_score'] ?? $cpMax;
        $psHighest = $ps['perfect_score'] ?? $psMax;
        $quizHighest = $quiz['perfect_score'] ?? $quizMax;
        $examHighest = $exam['perfect_score'] ?? $examMax;
        
        $cpEquiv = GradingHelper::calculateComponentScore($cpTotal, $cpHighest);
        $psEquiv = GradingHelper::calculateComponentScore($psTotal, $psHighest);
        $quizEquiv = GradingHelper::calculateComponentScore($quizTotal, $quizHighest);
        $examEquiv = GradingHelper::calculateComponentScore($examTotal, $examHighest);
        
        fputcsv($fp, [
            $i + 1,
            $student['student_no'],
            $student['last_name'],
            $student['first_name'],
            $student['middle_initial'],
            $cpItems[0]['raw_score'] ?? '',
            $cpItems[1]['raw_score'] ?? '',
            $cpItems[2]['raw_score'] ?? '',
            $cpItems[3]['raw_score'] ?? '',
            $cpTotal,
            $cpHighest,
            round($cpEquiv, 2),
            $psItems[0]['raw_score'] ?? '',
            $psTotal,
            $psHighest,
            round($psEquiv, 2),
            $quizItems[0]['raw_score'] ?? '',
            $quizItems[1]['raw_score'] ?? '',
            $quizTotal,
            $quizHighest,
            round($quizEquiv, 2),
            $examItems[0]['raw_score'] ?? '',
            $examHighest,
            round($examEquiv, 2),
            $student['final']['grade']
        ]);
    }
}

function generateClassRecordPdf($class, $gradesData) {
    echo '<html><head><meta charset="utf-8"><title>Class Record - ' . $class['code'] . '</title>';
    echo '<style>
        body { font-family: Arial, sans-serif; font-size: 11px; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 30px; }
        th, td { border: 1px solid #000; padding: 4px; text-align: center; font-size: 10px; }
        th { background: #f0f0f0; font-weight: bold; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h2 { margin: 5px 0; }
        .section-title { background: #e0e0e0; font-weight: bold; }
        .sub-header { background: #f5f5f5; font-size: 9px; }
    </style></head><body>';
    
    echo '<div class="header">';
    echo '<h2>CLASS RECORD</h2>';
    echo '<p>' . $class['code'] . ' - ' . $class['title'] . '</p>';
    echo '<p>' . $class['course_program'] . ' ' . $class['year_level'] . '-' . $class['section'] . ' | AY ' . $class['academic_year'] . '</p>';
    echo '</div>';
    
    // Midterm
    echo '<table>';
    echo '<tr class="section-title"><th colspan="24">MIDTERM PERIOD</th></tr>';
    echo '<tr class="sub-header"><th rowspan="2">No.</th><th rowspan="2">Student No</th><th rowspan="2">Last Name</th><th rowspan="2">First Name</th><th rowspan="2">MI</th>';
    echo '<th colspan="4">Class Participation</th><th>Total</th><th>Highest</th><th>Equiv.</th>';
    echo '<th>PS1</th><th>Total</th><th>Highest</th><th>Equiv.</th>';
    echo '<th>Q1</th><th>Q2</th><th>Total</th><th>Highest</th><th>Equiv.</th>';
    echo '<th>Exam</th><th>Highest</th><th>Equiv.</th>';
    echo '<th rowspan="2">Midterm Grade</th></tr>';
    echo '<tr class="sub-header"><th>CP1</th><th>CP2</th><th>CP3</th><th>CP4</th></tr>';
    
    foreach ($gradesData as $i => $student) {
        $mt = $student['midterm']['components'] ?? [];
        $cp = $mt['class_participation'] ?? [];
        $ps = $mt['problem_set'] ?? [];
        $quiz = $mt['quizzes'] ?? [];
        $exam = $mt['periodical_exam'] ?? [];
        
        $cpItems = $cp['items'] ?? [];
        $psItems = $ps['items'] ?? [];
        $quizItems = $quiz['items'] ?? [];
        $examItems = $exam['items'] ?? [];
        
        echo '<tr>';
        echo '<td>' . ($i + 1) . '</td>';
        echo '<td>' . $student['student_no'] . '</td>';
        echo '<td>' . $student['last_name'] . '</td>';
        echo '<td>' . $student['first_name'] . '</td>';
        echo '<td>' . $student['middle_initial'] . '</td>';
        
        for ($j = 0; $j < 4; $j++) {
            echo '<td>' . ($cpItems[$j]['raw_score'] ?? '') . '</td>';
        }
        $cpTotal = $cp['raw_total'] ?? 0;
        $cpHighest = $cp['perfect_score'] ?? ($cp['max_total'] ?? 0);
        $cpEquiv = GradingHelper::calculateComponentScore($cpTotal, $cpHighest);
        echo '<td>' . $cpTotal . '</td><td>' . $cpHighest . '</td><td>' . round($cpEquiv, 2) . '</td>';
        
        echo '<td>' . ($psItems[0]['raw_score'] ?? '') . '</td>';
        $psTotal = $ps['raw_total'] ?? 0;
        $psHighest = $ps['perfect_score'] ?? ($ps['max_total'] ?? 0);
        $psEquiv = GradingHelper::calculateComponentScore($psTotal, $psHighest);
        echo '<td>' . $psTotal . '</td><td>' . $psHighest . '</td><td>' . round($psEquiv, 2) . '</td>';
        
        echo '<td>' . ($quizItems[0]['raw_score'] ?? '') . '</td>';
        echo '<td>' . ($quizItems[1]['raw_score'] ?? '') . '</td>';
        $quizTotal = $quiz['raw_total'] ?? 0;
        $quizHighest = $quiz['perfect_score'] ?? ($quiz['max_total'] ?? 0);
        $quizEquiv = GradingHelper::calculateComponentScore($quizTotal, $quizHighest);
        echo '<td>' . $quizTotal . '</td><td>' . $quizHighest . '</td><td>' . round($quizEquiv, 2) . '</td>';
        
        echo '<td>' . ($examItems[0]['raw_score'] ?? '') . '</td>';
        $examHighest = $exam['perfect_score'] ?? ($exam['max_total'] ?? 0);
        $examEquiv = GradingHelper::calculateComponentScore($examTotal ?? 0, $examHighest);
        echo '<td>' . $examHighest . '</td><td>' . round($examEquiv, 2) . '</td>';
        
        echo '<td><strong>' . $student['midterm']['grade'] . '</strong></td>';
        echo '</tr>';
    }
    echo '</table>';
    
    // Final
    echo '<table>';
    echo '<tr class="section-title"><th colspan="24">FINAL PERIOD</th></tr>';
    echo '<tr class="sub-header"><th rowspan="2">No.</th><th rowspan="2">Student No</th><th rowspan="2">Last Name</th><th rowspan="2">First Name</th><th rowspan="2">MI</th>';
    echo '<th colspan="4">Class Participation</th><th>Total</th><th>Highest</th><th>Equiv.</th>';
    echo '<th>PS1</th><th>Total</th><th>Highest</th><th>Equiv.</th>';
    echo '<th>Q1</th><th>Q2</th><th>Total</th><th>Highest</th><th>Equiv.</th>';
    echo '<th>Exam</th><th>Highest</th><th>Equiv.</th>';
    echo '<th rowspan="2">Final Grade</th></tr>';
    echo '<tr class="sub-header"><th>CP1</th><th>CP2</th><th>CP3</th><th>CP4</th></tr>';
    
    foreach ($gradesData as $i => $student) {
        $fn = $student['final']['components'] ?? [];
        $cp = $fn['class_participation'] ?? [];
        $ps = $fn['problem_set'] ?? [];
        $quiz = $fn['quizzes'] ?? [];
        $exam = $fn['periodical_exam'] ?? [];
        
        $cpItems = $cp['items'] ?? [];
        $psItems = $ps['items'] ?? [];
        $quizItems = $quiz['items'] ?? [];
        $examItems = $exam['items'] ?? [];
        
        echo '<tr>';
        echo '<td>' . ($i + 1) . '</td>';
        echo '<td>' . $student['student_no'] . '</td>';
        echo '<td>' . $student['last_name'] . '</td>';
        echo '<td>' . $student['first_name'] . '</td>';
        echo '<td>' . $student['middle_initial'] . '</td>';
        
        for ($j = 0; $j < 4; $j++) {
            echo '<td>' . ($cpItems[$j]['raw_score'] ?? '') . '</td>';
        }
        $cpTotal = $cp['raw_total'] ?? 0;
        $cpHighest = $cp['perfect_score'] ?? ($cp['max_total'] ?? 0);
        $cpEquiv = GradingHelper::calculateComponentScore($cpTotal, $cpHighest);
        echo '<td>' . $cpTotal . '</td><td>' . $cpHighest . '</td><td>' . round($cpEquiv, 2) . '</td>';
        
        echo '<td>' . ($psItems[0]['raw_score'] ?? '') . '</td>';
        $psTotal = $ps['raw_total'] ?? 0;
        $psHighest = $ps['perfect_score'] ?? ($ps['max_total'] ?? 0);
        $psEquiv = GradingHelper::calculateComponentScore($psTotal, $psHighest);
        echo '<td>' . $psTotal . '</td><td>' . $psHighest . '</td><td>' . round($psEquiv, 2) . '</td>';
        
        echo '<td>' . ($quizItems[0]['raw_score'] ?? '') . '</td>';
        echo '<td>' . ($quizItems[1]['raw_score'] ?? '') . '</td>';
        $quizTotal = $quiz['raw_total'] ?? 0;
        $quizHighest = $quiz['perfect_score'] ?? ($quiz['max_total'] ?? 0);
        $quizEquiv = GradingHelper::calculateComponentScore($quizTotal, $quizHighest);
        echo '<td>' . $quizTotal . '</td><td>' . $quizHighest . '</td><td>' . round($quizEquiv, 2) . '</td>';
        
        echo '<td>' . ($examItems[0]['raw_score'] ?? '') . '</td>';
        $examHighest = $exam['perfect_score'] ?? ($exam['max_total'] ?? 0);
        $examEquiv = GradingHelper::calculateComponentScore($examTotal ?? 0, $examHighest);
        echo '<td>' . $examHighest . '</td><td>' . round($examEquiv, 2) . '</td>';
        
        echo '<td><strong>' . $student['final']['grade'] . '</strong></td>';
        echo '</tr>';
    }
    echo '</table>';
    echo '</body></html>';
}

function generateEGradingExcel($class, $gradesData) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="EGrading_' . $class['code'] . '.csv"');
    
    $fp = fopen('php://output', 'w');
    fprintf($fp, "\xEF\xBB\xBF");
    
    fputcsv($fp, ['E-GRADING SUBMISSION']);
    fputcsv($fp, ['Course: ' . $class['code'] . ' - ' . $class['title']]);
    fputcsv($fp, ['Class: ' . $class['course_program'] . ' ' . $class['year_level'] . '-' . $class['section']]);
    fputcsv($fp, ['AY: ' . $class['academic_year']]);
    fputcsv($fp, []);
    
    $headers = ['No.', 'Student No', 'Name (Last, First MI)', 'Midterm Rating', 'Midterm Remarks', 'Final Numerical Rating', 'Final Grade', 'Unit Credit', 'Remarks'];
    fputcsv($fp, $headers);
    
    foreach ($gradesData as $i => $student) {
        $name = $student['last_name'] . ', ' . $student['first_name'] . ' ' . $student['middle_initial'] . '.';
        $midtermRating = round($student['midterm']['grade']);
        $midtermRemarks = $student['midterm']['remarks'];
        $finalRating = round($student['final']['grade']);
        $finalGrade = $student['overall']['grade_point'];
        $unitCredit = 3; // Default
        $remarks = $student['overall']['remarks'];
        
        fputcsv($fp, [
            $i + 1,
            $student['student_no'],
            $name,
            $midtermRating,
            $midtermRemarks,
            $finalRating,
            $finalGrade,
            $unitCredit,
            $remarks
        ]);
    }
}

function generateEGradingPdf($class, $gradesData) {
    echo '<html><head><meta charset="utf-8"><title>E-Grading - ' . $class['code'] . '</title>';
    echo '<style>
        body { font-family: Arial, sans-serif; font-size: 11px; margin: 20px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #000; padding: 6px; text-align: center; font-size: 10px; }
        th { background: #f0f0f0; font-weight: bold; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h2 { margin: 5px 0; }
    </style></head><body>';
    
    echo '<div class="header">';
    echo '<h2>E-GRADING SUBMISSION</h2>';
    echo '<p>' . $class['code'] . ' - ' . $class['title'] . '</p>';
    echo '<p>' . $class['course_program'] . ' ' . $class['year_level'] . '-' . $class['section'] . ' | AY ' . $class['academic_year'] . '</p>';
    echo '</div>';
    
    echo '<table>';
    echo '<tr><th>No.</th><th>Student No</th><th>Name (Last, First MI)</th><th>Midterm Rating</th><th>Midterm Remarks</th><th>Final Numerical Rating</th><th>Final Grade</th><th>Unit Credit</th><th>Remarks</th></tr>';
    
    foreach ($gradesData as $i => $student) {
        $name = $student['last_name'] . ', ' . $student['first_name'] . ' ' . $student['middle_initial'] . '.';
        $midtermRating = round($student['midterm']['grade']);
        $midtermRemarks = $student['midterm']['remarks'];
        $finalRating = round($student['final']['grade']);
        $finalGrade = $student['overall']['grade_point'];
        $unitCredit = 3;
        $remarks = $student['overall']['remarks'];
        
        echo '<tr>';
        echo '<td>' . ($i + 1) . '</td>';
        echo '<td>' . $student['student_no'] . '</td>';
        echo '<td style="text-align:left;">' . $name . '</td>';
        echo '<td>' . $midtermRating . '</td>';
        echo '<td>' . $midtermRemarks . '</td>';
        echo '<td>' . $finalRating . '</td>';
        echo '<td>' . $finalGrade . '</td>';
        echo '<td>' . $unitCredit . '</td>';
        echo '<td>' . $remarks . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    echo '</body></html>';
}

function generateGradingSheetExcel($class, $gradesData) {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="GradingSheet_' . preg_replace('/[^a-z0-9_-]+/i', '_', $class['code']) . '.xls"');
    echo gradingSheetHtml($class, $gradesData, true);
}

function generateGradingSheetPdf($class, $gradesData) {
    echo gradingSheetHtml($class, $gradesData, false);
}

function generateGradingSheetDoc($class, $gradesData) {
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="GradingSheet_' . preg_replace('/[^a-z0-9_-]+/i', '_', $class['code']) . '.doc"');
    echo gradingSheetHtml($class, $gradesData, false);
}

function gradingSheetHtml($class, $gradesData, $excelMode = false) {
    $code = htmlspecialchars($class['code'] ?? '', ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars($class['title'] ?? '', ENT_QUOTES, 'UTF-8');
    $program = htmlspecialchars($class['course_program'] ?? '', ENT_QUOTES, 'UTF-8');
    $year = htmlspecialchars($class['year_level'] ?? '', ENT_QUOTES, 'UTF-8');
    $section = htmlspecialchars($class['section'] ?? '', ENT_QUOTES, 'UTF-8');
    $academicYear = htmlspecialchars($class['academic_year'] ?? '', ENT_QUOTES, 'UTF-8');
    $semester = ((int)($class['semester'] ?? 1) === 2) ? '2nd' : '1st';

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Grade Sheet - ' . $code . '</title><style>
        @page { size: landscape; margin: 10mm; }
        body { font-family: Arial, sans-serif; font-size: 10px; color: #000; margin: 12px; }
        .document-header { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .document-header td { border: 1px solid #555; padding: 5px; vertical-align: middle; }
        .logo-box { width: 80px; height: 70px; text-align: center; font-size: 9px; font-weight: bold; }
        .document-title { text-align: center; font-size: 16px; font-weight: bold; }
        .meta { margin: 4px 0; font-weight: bold; }
        .grade-sheet { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .grade-sheet th, .grade-sheet td { border: 1px solid #000; padding: 4px 5px; text-align: center; vertical-align: middle; }
        .grade-sheet th { background: #d9d9d9; font-weight: bold; }
        .grade-sheet th:nth-child(1) { width: 5%; }.grade-sheet th:nth-child(2) { width: 34%; }
        .grade-sheet th:nth-child(3), .grade-sheet th:nth-child(4), .grade-sheet th:nth-child(5), .grade-sheet th:nth-child(6), .grade-sheet th:nth-child(7) { width: 10%; }
        .grade-sheet th:nth-child(8) { width: 8%; }.grade-sheet td.name { text-align: left; }
    </style></head><body>';
    $html .= '<table class="document-header"><tr><td class="logo-box">CAPIZ STATE UNIVERSITY</td><td>Document Type:<br><b>FORM</b><br><br>Document Title:<br><b>GRADE SHEET</b></td><td>Document Code: <b>REG-F12</b><br>Revision No.: <b>00</b><br>Effective Date: <b>June 25, 2018</b></td></tr></table>';
    $html .= '<div class="meta">Course Number: ' . $code . '</div><div class="meta">Course Title: ' . $title . '</div>';
    $html .= '<div class="meta">' . $semester . ' Semester/Semester AY ' . $academicYear . '</div><div class="meta">Course and Year: ' . $program . ' ' . $year . '-' . $section . '</div>';
    $html .= '<table class="grade-sheet"><thead><tr><th>No.</th><th>Name of Students<br>(Last, First, MI)</th><th>Midterm<br>Rating</th><th>Midterm<br>Remarks</th><th>Numerical<br>Rating</th><th>Final<br>Grade</th><th>Unit<br>Credit</th><th>Remarks</th></tr></thead><tbody>';

    foreach ($gradesData as $i => $student) {
        $name = htmlspecialchars(trim(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_initial'] ?? '') . '.'), ENT_QUOTES, 'UTF-8');
        $midtermRating = round($student['midterm']['grade'] ?? 0);
        $midtermRemarks = htmlspecialchars($student['midterm']['remarks'] ?? 'INC', ENT_QUOTES, 'UTF-8');
        $finalRating = round($student['final']['grade'] ?? 0);
        $finalGrade = htmlspecialchars((string)($student['overall']['grade_point'] ?? 'INC'), ENT_QUOTES, 'UTF-8');
        $remarks = htmlspecialchars($student['overall']['remarks'] ?? 'INC', ENT_QUOTES, 'UTF-8');
        $html .= '<tr><td>' . ($i + 1) . '</td><td class="name">' . $name . '</td><td>' . ($midtermRating ?: 'INC') . '</td><td>' . $midtermRemarks . '</td><td>' . ($finalRating ?: 'INC') . '</td><td>' . $finalGrade . '</td><td>3</td><td>' . $remarks . '</td></tr>';
    }
    return $html . '</tbody></table></body></html>';
}
?>

