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
        $class = $db->query("SELECT year_level, course_program, section, academic_year FROM class_section WHERE id = $classId")->fetch_assoc();
        if (!$class) {
            echo ResponseAPI::error("Class not found");
            exit;
        }

        // Validate year level matches
        if ($class['year_level'] != $yearLevel) {
            echo ResponseAPI::error("This subject is for Year {$class['year_level']} students only. Cannot assign Year $yearLevel Section $section students.");
            exit;
        }

        // Get all students from this section using student table (match year + section only)
        $stmt = $db->prepare("SELECT DISTINCT s.student_no, s.last_name, s.first_name, s.middle_initial
            FROM student s
            JOIN class_section cs ON s.class_section_id = cs.id
            WHERE cs.faculty_id = ? AND cs.year_level = ? AND cs.section = ?");
        $stmt->bind_param("isi", $faculty_id, $yearLevel, $section);
        $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

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
        
        // Get all students from this section using student table (match year + section only)
        $stmt = $db->prepare("SELECT DISTINCT s.student_no, s.last_name, s.first_name, s.middle_initial
            FROM student s
            JOIN class_section cs ON s.class_section_id = cs.id
            WHERE cs.faculty_id = ? AND cs.year_level = ? AND cs.section = ?");
        $stmt->bind_param("isi", $faculty_id, $yearLevel, $section);
        $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
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
        $stmt->bind_param("iisiiis", $data['subject_id'], $faculty_id, $data['course_program'], 
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
                            $stmt3->bind_param("isii", $categoryId, $label, 10, $i - 1);
                            $stmt3->execute();
                        }
                    } elseif ($cat[1] === 'Problem Set') {
                        for ($i = 1; $i <= 3; $i++) {
                            $stmt3 = $db->prepare("INSERT INTO grade_item (grade_category_id, label, max_score, sort_order) VALUES (?, ?, ?, ?)");
                            $label = "Problem Set " . $i;
                            $stmt3->bind_param("isii", $categoryId, $label, 20, $i - 1);
                            $stmt3->execute();
                        }
                    } else {
                        $stmt3 = $db->prepare("INSERT INTO grade_item (grade_category_id, label, max_score, sort_order) VALUES (?, ?, ?, ?)");
                        $stmt3->bind_param("isii", $categoryId, $cat[1], 100, 0);
                        $stmt3->execute();
                    }
                }
            }
            echo ResponseAPI::success(['id' => $classId], "Class created", 201);
        } else {
            echo ResponseAPI::error("Failed to create class");
        }
    }
    elseif ($action === 'create_class_with_students') {
        $data = json_decode(file_get_contents("php://input"), true);
        
        $stmt = $db->prepare("INSERT INTO class_section 
            (subject_id, faculty_id, course_program, year_level, section, semester, academic_year) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iisiiis", $data['subject_id'], $faculty_id, $data['course_program'], 
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
                            $stmt3->bind_param("isii", $categoryId, $label, 10, $i - 1);
                            $stmt3->execute();
                        }
                    } elseif ($cat[1] === 'Problem Set') {
                        for ($i = 1; $i <= 3; $i++) {
                            $stmt3 = $db->prepare("INSERT INTO grade_item (grade_category_id, label, max_score, sort_order) VALUES (?, ?, ?, ?)");
                            $label = "Problem Set " . $i;
                            $stmt3->bind_param("isii", $categoryId, $label, 20, $i - 1);
                            $stmt3->execute();
                        }
                    } else {
                        $stmt3 = $db->prepare("INSERT INTO grade_item (grade_category_id, label, max_score, sort_order) VALUES (?, ?, ?, ?)");
                        $stmt3->bind_param("isii", $categoryId, $cat[1], 100, 0);
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
            echo ResponseAPI::error("Failed to create class");
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
        $itemId = intval($data['item_id']);
        $studentId = intval($data['student_id']);
        $rawScore = round(floatval($data['raw_score'] ?? 0));
        
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
            $stmt3->bind_param("isdi", $categoryId, $name, $maxScore, 0);
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
        $sort = 0;
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
    elseif ($action === 'create_subject') {
        $data = json_decode(file_get_contents("php://input"), true);
        $stmt = $db->prepare("INSERT INTO subject (code, title, default_units) VALUES (?, ?, ?)");
        $units = intval($data['default_units'] ?? 3);
        $stmt->bind_param("ssi", $data['code'], $data['title'], $units);
        echo $stmt->execute() ? ResponseAPI::success(['id' => $db->insert_id], "Subject created", 201) 
            : ResponseAPI::error("Failed to create subject");
    }
    elseif ($action === 'update_subject') {
        $data = json_decode(file_get_contents("php://input"), true);
        $id = intval($data['id']);
        $units = intval($data['default_units'] ?? 3);
        $stmt = $db->prepare("UPDATE subject SET code = ?, title = ?, default_units = ? WHERE id = ?");
        $stmt->bind_param("ssii", $data['code'], $data['title'], $units, $id);
        echo $stmt->execute() ? ResponseAPI::success([], "Subject updated") 
            : ResponseAPI::error("Failed to update subject");
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
        $db->query("DELETE FROM attendance_record WHERE attendance_session_id IN (SELECT id FROM attendance_session WHERE class_section_id = $classId))");
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
            $stmt->bind_param("iisssis", $subjectId, $faculty_id, $program, $year, $section, $semester, $ay);
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
            GROUP BY cs.id ORDER BY cs.created_at DESC LIMIT 10");
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
            GROUP BY cs.id ORDER BY cs.created_at DESC LIMIT 8");
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
    else {
        echo ResponseAPI::error("Invalid action", 404);
    }
} catch (Exception $e) {
    ob_clean();
    echo ResponseAPI::error($e->getMessage(), 500);
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

?>

