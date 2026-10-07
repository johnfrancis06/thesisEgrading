<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../includes/helpers.php';
require_once '../includes/auth.php';
require_once '../includes/gradesheet_data.php';
require_once '../includes/gradesheet_template.php';
require_once '../includes/classrecord_data.php';

$auth->requireLogin();
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$db = Database::getInstance()->getConnection();
$faculty_id = $auth->getFacultyId();

function bindDynamicParams($stmt, $types, $params) {
    $bindArgs = [$types];
    foreach ($params as $index => $value) {
        $params[$index] = $value;
        $bindArgs[] = &$params[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindArgs);
}

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

function createDefaultGradingSheets($db, $classId, $faculty_id) {
    $templateId = getGradingTemplateClassId($db, $faculty_id);
    if ($templateId) {
        cloneGradingSheets($db, $classId, $templateId);
        return true;
    }

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

/** The valid values for attendance_session.session_type. */
function attendanceSessionTypes() {
    return array('regular', 'holiday', 'seminar');
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
        } catch (\Exception | \Error $e) {
            $db->rollback();
            echo ResponseAPI::error("Enrollment failed: " . $e->getMessage());
        }
    }
    elseif ($action === 'create_class') {
        try {
            $data = json_decode(file_get_contents("php://input"), true);
            if (!$data) {
                echo ResponseAPI::error("Invalid or missing request body");
                exit;
            }
            $db->begin_transaction();
            $stmt = $db->prepare("INSERT INTO class_section 
                (subject_id, faculty_id, course_program, year_level, section, semester, academic_year) 
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iisisis", $data['subject_id'], $faculty_id, $data['course_program'], 
                $data['year_level'], $data['section'], $data['semester'], $data['academic_year']);
            
            if ($stmt->execute()) {
                $classId = $db->insert_id;
                createDefaultGradingSheets($db, $classId, $faculty_id);

                $inlineStudents = $data['students'] ?? [];
                if (!is_array($inlineStudents)) {
                    $inlineStudents = json_decode($inlineStudents, true) ?? [];
                }
                $added = 0;
                if (!empty($inlineStudents)) {
                    foreach ($inlineStudents as $s) {
                        $lname = $db->real_escape_string($s['last_name'] ?? '');
                        $fname = $db->real_escape_string($s['first_name'] ?? '');
                        $mi = $db->real_escape_string($s['middle_initial'] ?? '');
                        $sno = $db->real_escape_string($s['student_no'] ?? '');
                        if (!$sno || !$lname || !$fname) continue;
                        $db->query("INSERT IGNORE INTO student (class_section_id, last_name, first_name, middle_initial, student_no)
                            VALUES ($classId, '$lname', '$fname', '$mi', '$sno')");
                        $added++;
                    }
                }
                $db->commit();
                $message = "Class created" . ($added > 0 ? " with $added student(s)" : "");
                ob_clean();
                echo ResponseAPI::success(['id' => $classId, 'added' => $added], $message, 201);
            } else {
                $db->rollback();
                echo ResponseAPI::error("Failed to create class: " . $stmt->error);
            }
        } catch (\Exception | \Error $e) {
            if ($db->in_transaction) $db->rollback();
            ob_clean();
            echo ResponseAPI::error("Failed to create class: " . $e->getMessage(), 500);
        }
    }
    elseif ($action === 'create_class_with_students') {
        try {
            $data = json_decode(file_get_contents("php://input"), true);

            if (!$data) {
                echo ResponseAPI::error("Invalid or missing request body");
                exit;
            }

            $db->begin_transaction();

            $stmt = $db->prepare("INSERT INTO class_section
                (subject_id, faculty_id, course_program, year_level, section, semester, academic_year)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iisisis", $data['subject_id'], $faculty_id, $data['course_program'],
                $data['year_level'], $data['section'], $data['semester'], $data['academic_year']);

            if ($stmt->execute()) {
                $classId = $db->insert_id;
                createDefaultGradingSheets($db, $classId, $faculty_id);

                $program = $db->real_escape_string($data['course_program']);
                $year = intval($data['year_level']);
                $section = $db->real_escape_string($data['section']);
                $ay = $db->real_escape_string($data['academic_year']);

                $rosterStudents = $db->query("SELECT * FROM section_student
                    WHERE faculty_id = $faculty_id AND course_program = '$program' AND year_level = $year AND section = '$section' AND academic_year = '$ay'")->fetch_all(MYSQLI_ASSOC);

                foreach ($rosterStudents as $s) {
                    $db->query("INSERT IGNORE INTO student (class_section_id, last_name, first_name, middle_initial, student_no)
                        VALUES ($classId, '{$s['last_name']}', '{$s['first_name']}', '{$s['middle_initial']}', '{$s['student_no']}')");
                }

                $inlineStudents = $data['students'] ?? [];
                if (!is_array($inlineStudents)) {
                    $inlineStudents = json_decode($inlineStudents, true) ?? [];
                }
                $addedInline = 0;
                if (!empty($inlineStudents)) {
                    foreach ($inlineStudents as $s) {
                        $lname = $db->real_escape_string($s['last_name'] ?? '');
                        $fname = $db->real_escape_string($s['first_name'] ?? '');
                        $mi = $db->real_escape_string($s['middle_initial'] ?? '');
                        $sno = $db->real_escape_string($s['student_no'] ?? '');
                        if (!$sno || !$lname || !$fname) continue;
                        $db->query("INSERT IGNORE INTO student (class_section_id, last_name, first_name, middle_initial, student_no)
                            VALUES ($classId, '$lname', '$fname', '$mi', '$sno')");
                        $addedInline++;
                    }
                }

                $db->commit();
                $totalStudents = count($rosterStudents) + $addedInline;
                ob_clean();
                echo ResponseAPI::success(['id' => $classId, 'roster_students' => count($rosterStudents), 'inline_students' => $addedInline], "Class created with $totalStudents students", 201);
            } else {
                $db->rollback();
                echo ResponseAPI::error("Failed to create class: " . $stmt->error);
            }
        } catch (\Exception | \Error $e) {
            if ($db->in_transaction) $db->rollback();
            ob_clean();
            echo ResponseAPI::error("Failed to create class: " . $e->getMessage(), 500);
        }
    }
    elseif ($action === 'get_student_filter_options') {
        // Filter options must come from the same source that produces the student
        // rows. Building them from section_student leaves faculty without enrollment
        // records with permanently empty Year/Section dropdowns.
        $optionsStmt = $db->prepare("
            SELECT DISTINCT cs.course_program, cs.year_level, cs.section, cs.academic_year,
                   sub.code AS subject_code, sub.title AS subject_title
            FROM class_section cs
            JOIN subject sub ON cs.subject_id = sub.id
            JOIN student st ON st.class_section_id = cs.id
            WHERE cs.faculty_id = ?
            ORDER BY cs.year_level, cs.section, sub.code
        ");
        $optionsStmt->bind_param("i", $faculty_id);
        $optionsStmt->execute();
        echo ResponseAPI::success($optionsStmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }
    elseif ($action === 'get_students') {
        $classId = intval($_GET['class_id'] ?? 0);
        $yearLevel = intval($_GET['year_level'] ?? 0);
        $section = $_GET['section'] ?? '';
        $subject = $_GET['subject'] ?? '';
        
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
            
            if ($yearLevel > 0) {
                $query .= " AND cs.year_level = ?";
                $params[] = $yearLevel;
                $types .= "i";
            }
            if (!empty($section)) {
                $query .= " AND cs.section = ?";
                $params[] = $section;
                $types .= "s";
            }
            if (!empty($subject)) {
                $query .= " AND sub.code = ?";
                $params[] = $subject;
                $types .= "s";
            }
            
            $query .= " ORDER BY cs.year_level, cs.section, cs.academic_year DESC, s.last_name, s.first_name";
            $stmt = $db->prepare($query);
            bindDynamicParams($stmt, $types, $params);
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
    elseif ($action === 'clear_grade') {
        $data = json_decode(file_get_contents("php://input"), true);
        $itemId = intval($data['item_id'] ?? $data['grade_item_id'] ?? 0);
        $studentId = intval($data['student_id'] ?? 0);

        if ($itemId <= 0 || $studentId <= 0) {
            echo ResponseAPI::error("Invalid grade item or student");
            exit;
        }

        $stmt = $db->prepare("DELETE FROM grade_score WHERE grade_item_id = ? AND student_id = ?");
        $stmt->bind_param("ii", $itemId, $studentId);
        echo $stmt->execute() ? ResponseAPI::success([], "Grade cleared")
            : ResponseAPI::error("Failed to clear grade");
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
        
        // Get category config_id from grade_category
        $catResult = $db->query("SELECT config_id FROM grade_category WHERE id = {$data['category_id']}")->fetch_assoc();
        $configId = $catResult ? intval($catResult['config_id']) : null;
        
        $sort = intval($data['sort_order'] ?? 0);
        $configItemId = null;
        
        // First create entry in grade_item_config if category has config_id
        if ($configId) {
            $stmt2 = $db->prepare("INSERT INTO grade_item_config (category_config_id, label, max_score, sort_order, is_active) VALUES (?, ?, ?, ?, 1)");
            $stmt2->bind_param("isdi", $configId, $data['label'], $data['max_score'], $sort);
            if ($stmt2->execute()) {
                $configItemId = $db->insert_id;
            }
        }
        
        // Then insert into grade_item with the config_item_id
        $stmt = $db->prepare("INSERT INTO grade_item (grade_category_id, label, max_score, sort_order, item_config_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("isdii", $data['category_id'], $data['label'], $data['max_score'], $sort, $configItemId);
        $success = $stmt->execute();
        $itemId = $db->insert_id;
        
        echo $success ? ResponseAPI::success(['id' => $itemId, 'config_item_id' => $configItemId], "Item added") 
            : ResponseAPI::error("Failed to add item");
    }
    elseif ($action === 'delete_grade_item') {
        $data = json_decode(file_get_contents("php://input"), true);
        $itemId = intval($data['id'] ?? 0);
        if ($itemId > 0) {
            // Get item_config_id before deleting
            $itemConfigResult = $db->query("SELECT item_config_id FROM grade_item WHERE id = $itemId")->fetch_assoc();
            $configItemId = $itemConfigResult ? intval($itemConfigResult['item_config_id']) : null;
            
            $db->query("DELETE FROM grade_score WHERE grade_item_id = $itemId");
            $db->query("DELETE FROM grade_item WHERE id = $itemId");
            
            // Also delete from grade_item_config
            if ($configItemId) {
                $db->query("DELETE FROM grade_item_config WHERE id = $configItemId");
            }
            
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
            // Update grade_item
            $stmt = $db->prepare("UPDATE grade_item SET label = ?, max_score = ?, sort_order = ? WHERE id = ?");
            $stmt->bind_param("sdii", $data['label'], $data['max_score'], $data['sort_order'], $id);
            $success = $stmt->execute();
            
            // Also update grade_item_config if item_config_id exists (so changes persist through sync)
            $itemConfigResult = $db->query("SELECT item_config_id FROM grade_item WHERE id = $id")->fetch_assoc();
            if ($success && $itemConfigResult && $itemConfigResult['item_config_id']) {
                $configId = intval($itemConfigResult['item_config_id']);
                $stmt2 = $db->prepare("UPDATE grade_item_config SET label = ?, max_score = ?, sort_order = ? WHERE id = ?");
                $stmt2->bind_param("sdii", $data['label'], $data['max_score'], $data['sort_order'], $configId);
                $stmt2->execute();
            }
            
            echo $success ? ResponseAPI::success([], "Item updated") 
                : ResponseAPI::error("Failed to update item");
        } else {
            echo ResponseAPI::error("Invalid item ID");
        }
    }
    elseif ($action === 'bulk_update_max_score') {
        $data = json_decode(file_get_contents("php://input"), true);
        $classId = intval($data['class_id'] ?? 0);
        $period = $data['period'] ?? '';
        $component = $data['component'] ?? '';
        $maxScore = floatval($data['max_score'] ?? 0);
        
        if (!$classId || !$period || !$component || $maxScore <= 0) {
            echo ResponseAPI::error("Missing required parameters");
            exit;
        }
        
        $stmt = $db->prepare("
            UPDATE grade_item gi
            JOIN grade_category gc ON gi.grade_category_id = gc.id
            SET gi.max_score = ?
            WHERE gc.class_section_id = ? AND gc.period = ? AND gc.name = ?
        ");
        $stmt->bind_param("diss", $maxScore, $classId, $period, $component);
        $stmt->execute();
        
        echo ResponseAPI::success(['affected_rows' => $stmt->affected_rows], "Max scores updated");
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
        } catch (\Exception | \Error $e) {
            ob_clean();
            echo ResponseAPI::error("Failed to load subjects: " . $e->getMessage(), 500);
        }
    }
    elseif ($action === 'delete_subject') {
        $data = json_decode(file_get_contents("php://input"), true);
        $subjectId = intval($data['id'] ?? 0);
        if ($subjectId > 0) {
            // Check if subject is used in any class
            $check = $db->prepare("SELECT COUNT(*) as count FROM class_section WHERE subject_id = ?");
            $check->bind_param("i", $subjectId);
            $check->execute();
            $result = $check->get_result()->fetch_assoc();
            
            if ($result['count'] > 0) {
                echo ResponseAPI::error("Cannot delete subject: it is used in {$result['count']} class(es). Please delete those classes first.");
                exit;
            }
            
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
            
            $stmt = $db->prepare("INSERT INTO attendance_session (class_section_id, date, session_type, label) VALUES (?, ?, 'regular', ?)");
            $label = date('l, F j', strtotime($date));
            $stmt->bind_param("iss", $classId, $date, $label);

            if ($stmt->execute()) {
                $sessionId = $db->insert_id;
                $students = $db->query("SELECT id FROM student WHERE class_section_id = $classId")->fetch_all(MYSQLI_ASSOC);
                foreach ($students as $s) {
                    $stmt2 = $db->prepare("INSERT INTO attendance_record (attendance_session_id, student_id, status) VALUES (?, ?, '')");
                    $stmt2->bind_param("is", $sessionId, $s['id']);
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
        $classId = intval($data['class_id'] ?? 0);
        $date = $data['date'] ?? '';
        $label = $data['label'] ?? '';
        $requestedType = $data['session_type'] ?? 'regular';
        $sessionType = in_array($requestedType, attendanceSessionTypes(), true) ? $requestedType : 'regular';

        $stmt = $db->prepare("INSERT INTO attendance_session (class_section_id, date, session_type, label) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $classId, $date, $sessionType, $label);

        if ($stmt->execute()) {
            $sessionId = $db->insert_id;
            // Every student starts unmarked; the teacher sets the statuses.
            $students = $db->query("SELECT id FROM student WHERE class_section_id = $classId")->fetch_all(MYSQLI_ASSOC);
            foreach ($students as $s) {
                $stmt2 = $db->prepare("INSERT INTO attendance_record (attendance_session_id, student_id, status) VALUES (?, ?, '')");
                $stmt2->bind_param("is", $sessionId, $s['id']);
                $stmt2->execute();
            }
            echo ResponseAPI::success(['id' => $sessionId], "Session created", 201);
        } else {
            echo ResponseAPI::error("Failed to create session");
        }
    }
    elseif ($action === 'set_attendance_day_type') {
        // Marks one date as regular / holiday / seminar, and stores the holiday or
        // seminar name in attendance_session.label.
        $data = json_decode(file_get_contents("php://input"), true);
        $classId = intval($data['class_id'] ?? 0);
        $date = $data['date'] ?? '';
        $type = $data['session_type'] ?? 'regular';
        $label = trim((string)($data['label'] ?? ''));

        if ($classId <= 0 || !$date) {
            echo ResponseAPI::error("Class and date are required");
            exit;
        }
        if (!in_array($type, attendanceSessionTypes(), true)) {
            echo ResponseAPI::error("Unknown day type");
            exit;
        }

        $columnCheck = $db->query("SHOW COLUMNS FROM attendance_session LIKE 'session_type'");
        if (!$columnCheck || $columnCheck->num_rows === 0) {
            echo ResponseAPI::error("session_type column not yet migrated");
            exit;
        }

        // The date must belong to this faculty's class.
        $owner = $db->prepare("SELECT faculty_id FROM class_section WHERE id = ?");
        $owner->bind_param("i", $classId);
        $owner->execute();
        $ownerRow = $owner->get_result()->fetch_assoc();
        if (!$ownerRow) {
            echo ResponseAPI::error("Class not found");
            exit;
        }
        if (intval($ownerRow['faculty_id']) !== intval($faculty_id)) {
            echo ResponseAPI::error("Not authorised for this class");
            exit;
        }

        $existing = $db->prepare("SELECT id, label, session_type FROM attendance_session WHERE class_section_id = ? AND date = ?");
        $existing->bind_param("is", $classId, $date);
        $existing->execute();
        $session = $existing->get_result()->fetch_assoc();

        // A regular day carries no label, so clearing the type also clears the name.
        $effectiveLabel = ($type === 'regular') ? '' : $label;

        // The type change and the attendance cleanup land in one
        // transaction, so a failed delete can never leave a locked column
        // with marks still in it.
        $db->begin_transaction();
        try {
            if ($session) {
                $update = $db->prepare("UPDATE attendance_session SET session_type = ?, label = ? WHERE id = ?");
                $update->bind_param("ssi", $type, $effectiveLabel, $session['id']);
                if (!$update->execute()) {
                    throw new RuntimeException("Failed to update day");
                }
                $sessionId = $session['id'];
            } else {
                $insert = $db->prepare("INSERT INTO attendance_session (class_section_id, date, session_type, label) VALUES (?, ?, ?, ?)");
                $insert->bind_param("isss", $classId, $date, $type, $effectiveLabel);
                if (!$insert->execute()) {
                    throw new RuntimeException("Failed to create day");
                }
                $sessionId = $db->insert_id;
            }

            if ($type === 'regular') {
                // A brand new regular day starts with one empty row per
                // student. Changing a locked day back to regular starts
                // empty too, so no rows are recreated here.
                if (!$session) {
                    $students = $db->query("SELECT id FROM student WHERE class_section_id = $classId")->fetch_all(MYSQLI_ASSOC);
                    foreach ($students as $s) {
                        $stmt = $db->prepare("INSERT INTO attendance_record (attendance_session_id, student_id, status) VALUES (?, ?, '')");
                        $stmt->bind_param("is", $sessionId, $s['id']);
                        $stmt->execute();
                    }
                }
            } else {
                // A holiday or seminar carries no attendance, so anything
                // already recorded for the date is removed together with
                // the type change.
                $delete = $db->prepare("DELETE FROM attendance_record WHERE attendance_session_id = ?");
                $delete->bind_param("i", $sessionId);
                if (!$delete->execute()) {
                    throw new RuntimeException("Failed to clear day attendance");
                }
            }

            logAudit($db, $faculty_id, 'set_attendance_day_type', 'attendance_session', $sessionId,
                json_encode(['session_type' => $session['session_type'] ?? 'regular', 'label' => $session['label'] ?? '']),
                json_encode(['session_type' => $type, 'label' => $effectiveLabel])
            );

            $db->commit();
        } catch (RuntimeException $e) {
            $db->rollback();
            echo ResponseAPI::error($e->getMessage());
            exit;
        }

        echo ResponseAPI::success([
            'id' => $sessionId,
            'date' => $date,
            'session_type' => $type,
            'label' => $effectiveLabel,
        ], "Day updated");
    }
    elseif ($action === 'complete_attendance_day') {
        // Finishes one attendance day. The teacher only marks the absentees while
        // the day is open; saving it turns every student still left unmarked into
        // present, then locks the day as complete.
        $data = json_decode(file_get_contents("php://input"), true);
        $sessionId = intval($data['session_id'] ?? 0);
        $classId = intval($data['class_id'] ?? 0);
        $date = $data['date'] ?? '';

        if ($sessionId <= 0 && ($classId <= 0 || !$date)) {
            echo ResponseAPI::error("Session or class and date are required");
            exit;
        }

        if ($sessionId <= 0) {
            $lookup = $db->prepare("SELECT id FROM attendance_session WHERE class_section_id = ? AND date = ?");
            $lookup->bind_param("is", $classId, $date);
            $lookup->execute();
            $found = $lookup->get_result()->fetch_assoc();
            if (!$found) {
                echo ResponseAPI::error("No attendance session on that date");
                exit;
            }
            $sessionId = intval($found['id']);
        }

        $owner = $db->prepare("SELECT ase.class_section_id, cs.faculty_id
                               FROM attendance_session ase
                               JOIN class_section cs ON cs.id = ase.class_section_id
                               WHERE ase.id = ?");
        $owner->bind_param("i", $sessionId);
        $owner->execute();
        $ownerRow = $owner->get_result()->fetch_assoc();
        if (!$ownerRow) {
            echo ResponseAPI::error("Session not found");
            exit;
        }
        if (intval($ownerRow['faculty_id']) !== intval($faculty_id)) {
            echo ResponseAPI::error("Not authorised for this class");
            exit;
        }

        $sessionClassId = intval($ownerRow['class_section_id']);

        // A holiday or seminar column is locked and holds no
        // attendance, so it can never be completed.
        $dayTypeStmt = $db->prepare("SELECT session_type FROM attendance_session WHERE id = ?");
        $dayTypeStmt->bind_param("i", $sessionId);
        $dayTypeStmt->execute();
        $dayTypeRow = $dayTypeStmt->get_result()->fetch_assoc();
        $dayType = $dayTypeRow['session_type'] ?? 'regular';
        if ($dayType === 'holiday' || $dayType === 'seminar') {
            echo ResponseAPI::error("A " . $dayType . " day cannot be saved", 403);
            exit;
        }

        // A student added after the day was first created has no record yet, so
        // the day would silently omit them. Create the missing rows as present.
        $insertMissing = $db->prepare("INSERT INTO attendance_record (attendance_session_id, student_id, status)
                                        SELECT ?, st.id, 'present'
                                        FROM student st
                                        WHERE st.class_section_id = ?
                                          AND st.id NOT IN (SELECT student_id FROM attendance_record
                                                             WHERE attendance_session_id = ?)");
        $insertMissing->bind_param("iii", $sessionId, $sessionClassId, $sessionId);
        $insertMissing->execute();
        $inserted = $insertMissing->affected_rows;

        // Anything the teacher never touched counts as present.
        $fill = $db->prepare("UPDATE attendance_record SET status = 'present'
                              WHERE attendance_session_id = ? AND (status IS NULL OR status = '')");
        $fill->bind_param("i", $sessionId);
        $fill->execute();
        $filled = $fill->affected_rows;

        $mark = $db->prepare("UPDATE attendance_session SET is_completed = 1, completed_at = NOW() WHERE id = ?");
        $mark->bind_param("i", $sessionId);
        if (!$mark->execute()) {
            echo ResponseAPI::error("Failed to complete the day");
            exit;
        }

        logAudit($db, $faculty_id, 'complete_attendance_day', 'attendance_session', $sessionId,
            null, json_encode(['marked_present' => $filled, 'added_students' => $inserted])
        );

        echo ResponseAPI::success([
            'session_id' => $sessionId,
            'marked_present' => $filled,
            'added_students' => $inserted,
        ], $filled > 0 ? "Day saved, $filled student(s) marked present" : "Day saved");
    }
    elseif ($action === 'save_attendance') {
        $data = json_decode(file_get_contents("php://input"), true);
        $recordId = intval($data['record_id'] ?? 0);
        $status = $data['status'] ?? '';
        $remarks = $data['remarks'] ?? '';
        $sessionId = intval($data['session_id'] ?? 0);
        $studentId = intval($data['student_id'] ?? 0);

        // A holiday or seminar column is locked: it holds no
        // attendance, so any write to it is rejected outright.
        $checkSessionId = 0;
        if ($recordId > 0) {
            $checkRow = $db->query("SELECT attendance_session_id FROM attendance_record WHERE id = $recordId")->fetch_assoc();
            $checkSessionId = $checkRow ? intval($checkRow['attendance_session_id']) : 0;
        } elseif ($sessionId > 0) {
            $checkSessionId = $sessionId;
        }
        if ($checkSessionId > 0) {
            $dayTypeStmt = $db->prepare("SELECT session_type FROM attendance_session WHERE id = ?");
            $dayTypeStmt->bind_param("i", $checkSessionId);
            $dayTypeStmt->execute();
            $dayTypeRow = $dayTypeStmt->get_result()->fetch_assoc();
            $dayType = $dayTypeRow['session_type'] ?? 'regular';
            if ($dayType === 'holiday' || $dayType === 'seminar') {
                echo ResponseAPI::error("A " . $dayType . " day cannot be edited", 403);
                exit;
            }
        }

        // Editing any cell reopens its day, so the teacher is reminded to save it
        // again before the remaining blanks become present.
        function reopenAttendanceDay($db, $sessionId) {
            if ($sessionId <= 0) return;
            $stmt = $db->prepare("UPDATE attendance_session
                                   SET is_completed = 0, completed_at = NULL
                                   WHERE id = ? AND is_completed = 1");
            $stmt->bind_param("i", $sessionId);
            $stmt->execute();
        }

        if ($recordId > 0) {
            $oldRecord = $db->query("SELECT ar.*, ase.class_section_id FROM attendance_record ar 
                JOIN attendance_session ase ON ar.attendance_session_id = ase.id 
                WHERE ar.id = $recordId")->fetch_assoc();
            
            $stmt = $db->prepare("UPDATE attendance_record SET status = ?, remarks = ? WHERE id = ?");
            $stmt->bind_param("ssi", $status, $remarks, $recordId);
            $stmt->execute();

            if ($stmt->execute() && $oldRecord) {
                reopenAttendanceDay($db, intval($oldRecord['attendance_session_id']));
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

            reopenAttendanceDay($db, $sessionId);
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
        echo ResponseAPI::success([
            'attendance_late_counts_present' => $lateCounts ? 1 : 0,
        ]);
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
                echo ResponseAPI::success([
                    'attendance_late_counts_present' => $lateCounts,
                ], "Configuration updated");
            } else {
                echo ResponseAPI::error("Failed to update configuration");
            }
        } else {
            echo ResponseAPI::success([
                'attendance_late_counts_present' => 0,
            ], "Configuration updated (column not yet migrated)");
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
    elseif ($action === 'get_absent_report') {
        $classId = intval($_GET['class_id']);
        $year = intval($_GET['year'] ?? date('Y'));
        $month = intval($_GET['month'] ?? date('n'));
        $view = $_GET['view'] ?? 'month'; // 'week' or 'month'
        
        $weekdays = getAttendanceWeekdays($db, $classId);
        
        // Get students
        $students = $db->query("SELECT * FROM student WHERE class_section_id = $classId ORDER BY last_name")->fetch_all(MYSQLI_ASSOC);
        
        // Get sessions for the period
        if ($view === 'week') {
            // Get current week dates
            $startOfWeek = date('Y-m-d', strtotime("monday this week"));
            $endOfWeek = date('Y-m-d', strtotime("sunday this week"));
            $sessions = $db->query("SELECT * FROM attendance_session WHERE class_section_id = $classId AND date BETWEEN '$startOfWeek' AND '$endOfWeek' ORDER BY date")->fetch_all(MYSQLI_ASSOC);
        } else {
            // Get month sessions
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
            $startDate = sprintf('%04d-%02d-01', $year, $month);
            $endDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
            $sessions = $db->query("SELECT * FROM attendance_session WHERE class_section_id = $classId AND date BETWEEN '$startDate' AND '$endDate' ORDER BY date")->fetch_all(MYSQLI_ASSOC);
        }
        
        // Filter sessions by class weekdays. Holidays and seminars are
        // locked and carry no attendance, so they never count towards a
        // student's totals.
        $classSessionDates = [];
        foreach ($sessions as $s) {
            $jsDay = (int)date('N', strtotime($s['date']));
            if (!in_array($jsDay, $weekdays)) {
                continue;
            }
            if (($s['session_type'] ?? 'regular') !== 'regular') {
                continue;
            }
            $classSessionDates[] = $s['date'];
        }
        
        if (empty($classSessionDates)) {
            echo ResponseAPI::success(['report' => [], 'summary' => []]);
            return;
        }
        
        $placeholders = implode(',', array_fill(0, count($classSessionDates), '?'));
        
        // Get attendance records for these sessions
        $sessionIdsStmt = $db->prepare("SELECT id FROM attendance_session WHERE class_section_id = ? AND date IN ($placeholders)");
        $params = array_merge([$classId], $classSessionDates);
        $types = 'i' . str_repeat('s', count($classSessionDates));
        $sessionIdsStmt->bind_param($types, ...$params);
        $sessionIdsStmt->execute();
        $sessionIds = $sessionIdsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $sessionIds = array_column($sessionIds, 'id');
        
        if (empty($sessionIds)) {
            echo ResponseAPI::success(['report' => [], 'summary' => []]);
            return;
        }
        
        $sidPlaceholders = implode(',', array_fill(0, count($sessionIds), '?'));
        $stmt = $db->prepare("SELECT ar.*, s.last_name, s.first_name, s.student_no, ase.date 
            FROM attendance_record ar
            JOIN student s ON ar.student_id = s.id
            JOIN attendance_session ase ON ar.attendance_session_id = ase.id
            WHERE ar.attendance_session_id IN ($sidPlaceholders) AND s.class_section_id = ?
            ORDER BY s.last_name, ase.date");
        $bindParams = array_merge($sessionIds, [$classId]);
        $bindTypes = str_repeat('i', count($sessionIds)) . 'i';
        $stmt->bind_param($bindTypes, ...$bindParams);
        $stmt->execute();
        $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Build report per student
        $report = [];
        foreach ($students as $student) {
            $studentRecords = array_filter($records, fn($r) => $r['student_id'] == $student['id']);
            
            $absentDates = [];
            $consecutiveAbsent = 0;
            $maxConsecutive = 0;
            $totalAbsent = 0;
            $totalPresent = 0;
            $totalLate = 0;
            $totalExcused = 0;
            
            // Sort records by date
            usort($studentRecords, fn($a, $b) => strcmp($a['date'], $b['date']));
            
            foreach ($studentRecords as $r) {
                if ($r['status'] === 'absent') {
                    $absentDates[] = $r['date'];
                    $totalAbsent++;
                    $consecutiveAbsent++;
                    $maxConsecutive = max($maxConsecutive, $consecutiveAbsent);
                } else {
                    $consecutiveAbsent = 0;
                    if ($r['status'] === 'present') $totalPresent++;
                    else if ($r['status'] === 'late') $totalLate++;
                    else if ($r['status'] === 'excused') $totalExcused++;
                }
            }
            
            $shouldDrop = $maxConsecutive >= 3;
            
            $report[] = [
                'student_id' => $student['id'],
                'student_no' => $student['student_no'],
                'name' => $student['last_name'] . ', ' . $student['first_name'],
                'total_sessions' => count($classSessionDates),
                'present' => $totalPresent,
                'absent' => $totalAbsent,
                'late' => $totalLate,
                'excused' => $totalExcused,
                'absent_dates' => $absentDates,
                'max_consecutive_absent' => $maxConsecutive,
                'should_drop' => $shouldDrop
            ];
        }
        
        // Summary
        $summary = [
            'total_students' => count($students),
            'at_risk' => count(array_filter($report, fn($r) => $r['should_drop'])),
            'total_sessions' => count($classSessionDates),
            'period' => $view === 'week' ? 'This Week' : date('F Y', strtotime("$year-$month-01"))
        ];
        
        echo ResponseAPI::success(['report' => $report, 'summary' => $summary]);
    }
    elseif ($action === 'drop_student') {
        $data = json_decode(file_get_contents("php://input"), true);
        $studentId = intval($data['student_id']);
        $classId = intval($data['class_id']);
        $reason = $data['reason'] ?? 'Excessive absences (3+ consecutive)';
        
        // Add status column if not exists
        $colCheck = $db->query("SHOW COLUMNS FROM student LIKE 'status'");
        if ($colCheck && $colCheck->num_rows === 0) {
            $db->query("ALTER TABLE student ADD COLUMN status ENUM('active','dropped') DEFAULT 'active' AFTER student_no");
        }
        
        $stmt = $db->prepare("UPDATE student SET status = 'dropped' WHERE id = ? AND class_section_id = ?");
        $stmt->bind_param("ii", $studentId, $classId);
        
        if ($stmt->execute()) {
            logAudit($db, $faculty_id, 'drop_student', 'student', $studentId,
                null, json_encode(['reason' => $reason]));
            echo ResponseAPI::success([], "Student dropped");
        } else {
            echo ResponseAPI::error("Failed to drop student");
        }
    }
    elseif ($action === 'reinstate_student') {
        $data = json_decode(file_get_contents("php://input"), true);
        $studentId = intval($data['student_id']);
        $classId = intval($data['class_id']);
        
        $stmt = $db->prepare("UPDATE student SET status = 'active' WHERE id = ? AND class_section_id = ?");
        $stmt->bind_param("ii", $studentId, $classId);
        
        if ($stmt->execute()) {
            logAudit($db, $faculty_id, 'reinstate_student', 'student', $studentId,
                null, json_encode(['reason' => 'Manually reinstated']));
            echo ResponseAPI::success([], "Student reinstated");
        } else {
            echo ResponseAPI::error("Failed to reinstate student");
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
        
        $query = "SELECT ss.course_program, ss.year_level, ss.section, ss.academic_year,
              COUNT(DISTINCT ss.student_no) as count
              FROM section_student ss
              WHERE ss.faculty_id = ?
              GROUP BY ss.course_program, ss.year_level, ss.section, ss.academic_year
                  ORDER BY ss.academic_year DESC";
        
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
        $sections = $db->query("SELECT DISTINCT ss.course_program, ss.year_level, ss.section, ss.academic_year,
            COUNT(DISTINCT ss.student_no) as count
            FROM section_student ss
            WHERE ss.faculty_id = $faculty_id
            GROUP BY ss.course_program, ss.year_level, ss.section, ss.academic_year
            ORDER BY ss.year_level ASC, ss.section ASC")->fetch_all(MYSQLI_ASSOC);
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
        bindDynamicParams($stmt, $types, array_merge([$faculty_id], $params));
        $stmt->execute();
        echo ResponseAPI::success($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }
    elseif ($action === 'get_section_students') {
        $program = $_GET['program'] ?? '';
        $year = intval($_GET['year'] ?? 0);
        $section = $_GET['section'] ?? '';
        $ay = $_GET['ay'] ?? '';
        
        $query = "SELECT ss.id, ss.student_no, ss.last_name, ss.first_name, ss.middle_initial,
              ss.course_program, ss.year_level, ss.section, ss.academic_year,
              (SELECT cs.subject_id FROM student s JOIN class_section cs ON s.class_section_id = cs.id 
               WHERE s.class_section_id IN (SELECT id FROM class_section WHERE faculty_id = $faculty_id 
               AND course_program = ss.course_program AND year_level = ss.year_level 
               AND section = ss.section AND academic_year = ss.academic_year) 
               AND s.student_no = ss.student_no LIMIT 1) as subject_id,
              (SELECT cs.semester FROM student s JOIN class_section cs ON s.class_section_id = cs.id 
               WHERE s.class_section_id IN (SELECT id FROM class_section WHERE faculty_id = $faculty_id 
               AND course_program = ss.course_program AND year_level = ss.year_level 
               AND section = ss.section AND academic_year = ss.academic_year) 
               AND s.student_no = ss.student_no LIMIT 1) as semester
              FROM section_student ss
              WHERE ss.faculty_id = ?";
        $params = [];
        $types = 'i';
        $params[] = $faculty_id;
        
        if ($program) { $query .= " AND ss.course_program = ?"; $params[] = $program; $types .= 's'; }
        if ($year) { $query .= " AND ss.year_level = ?"; $params[] = $year; $types .= 'i'; }
        if ($section) { $query .= " AND ss.section = ?"; $params[] = $section; $types .= 's'; }
        if ($ay) { $query .= " AND ss.academic_year = ?"; $params[] = $ay; $types .= 's'; }
        
        $query .= " ORDER BY ss.last_name";
        
        $stmt = $db->prepare($query);
        bindDynamicParams($stmt, $types, $params);
        $stmt->execute();
        echo ResponseAPI::success($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }
    elseif ($action === 'add_section_student') {
        $data = json_decode(file_get_contents("php://input"), true);

        $required = ['course_program', 'year_level', 'section', 'academic_year', 'student_no', 'last_name', 'first_name'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
                echo ResponseAPI::error("Missing required field: $field");
                exit;
            }
        }
        
        $program = $db->real_escape_string($data['course_program']);
        $year = intval($data['year_level']);
        $section = $db->real_escape_string($data['section']);
        $ay = $db->real_escape_string($data['academic_year']);
        $subjectId = intval($data['subject_id'] ?? 0);
        $semester  = intval($data['semester'] ?? 0);

        $roster = $db->prepare("INSERT INTO section_student
            (faculty_id, course_program, year_level, section, academic_year, last_name, first_name, middle_initial, student_no)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE last_name = VALUES(last_name), first_name = VALUES(first_name), middle_initial = VALUES(middle_initial)");
        $roster->bind_param("isissssss", $faculty_id, $program, $year, $section, $ay, $data['last_name'], $data['first_name'], $data['middle_initial'], $data['student_no']);
        if (!$roster->execute()) {
            echo ResponseAPI::error("Failed to save section roster: " . $roster->error, 500);
            exit;
        }
        
        // Find matching classes for this section, narrowing by subject + semester
        // when provided so different subjects can have separate class rosters.
        $classWhere = "faculty_id = $faculty_id AND course_program = '$program' AND year_level = $year AND section = '$section' AND academic_year = '$ay'";
        if ($subjectId > 0) {
            $classWhere .= " AND subject_id = $subjectId";
        }
        if ($semester > 0) {
            $classWhere .= " AND semester = $semester";
        }
        $classes = $db->query("SELECT id FROM class_section WHERE $classWhere")->fetch_all(MYSQLI_ASSOC);
        
        // Auto-create a class when none exists yet and a subject + semester are provided
        $createdClassId = null;
        if (empty($classes) && $subjectId > 0 && $semester > 0) {
            $insertClass = $db->prepare("INSERT INTO class_section 
                (subject_id, faculty_id, course_program, year_level, section, semester, academic_year) 
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insertClass->bind_param("iisisis", $subjectId, $faculty_id, $program, $year, $section, $semester, $ay);
            if ($insertClass->execute()) {
                $createdClassId = $db->insert_id;
                createDefaultGradingSheets($db, $createdClassId, $faculty_id);
                $classes[] = ['id' => $createdClassId];
            }
        }
        
        $enrolledCount = 0;
        $skippedCount = 0;
        foreach ($classes as $class) {
            // Check if student already exists in this class
            $check = $db->prepare("SELECT id FROM student WHERE class_section_id = ? AND student_no = ?");
            $check->bind_param("is", $class['id'], $data['student_no']);
            $check->execute();
            if ($check->get_result()->fetch_assoc()) {
                $skippedCount++;
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
        
        $message = $enrolledCount > 0
            ? "Student enrolled in $enrolledCount class(es)"
            : (empty($classes)
                ? "Student saved to the section roster; no subject class is currently assigned"
                : "Student already enrolled; no duplicate was created");
        if ($skippedCount > 0) {
            $message .= " ($skippedCount existing class enrollment(s) skipped)";
        }
        echo ResponseAPI::success([
            'enrolled_in_classes' => $enrolledCount,
            'skipped_existing' => $skippedCount,
            'created_class' => $createdClassId
        ], $message, $enrolledCount > 0 ? 201 : 200);
    }
    elseif ($action === 'remove_section_student') {
        $data = json_decode(file_get_contents("php://input"), true);
        $id = intval($data['id']);
        
        $stmt = $db->prepare("SELECT student_no, course_program, year_level, section, academic_year
            FROM section_student WHERE id = ? AND faculty_id = ?");
        $stmt->bind_param("ii", $id, $faculty_id);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        
        if (!$current) {
            echo ResponseAPI::error("Student not found");
            exit;
        }
        
        $delete = $db->prepare("DELETE FROM section_student WHERE id = ? AND faculty_id = ?");
        $delete->bind_param("ii", $id, $faculty_id);
        $delete->execute();

        $deleteCopies = $db->prepare("DELETE s FROM student s
            JOIN class_section cs ON s.class_section_id = cs.id
            WHERE s.student_no = ? AND cs.faculty_id = ? AND cs.course_program = ?
            AND cs.year_level = ? AND cs.section = ? AND cs.academic_year = ?");
        $deleteCopies->bind_param("sisiss", $current['student_no'], $faculty_id, $current['course_program'], $current['year_level'], $current['section'], $current['academic_year']);
        $deleteCopies->execute();
        
        echo ResponseAPI::success([], "Student removed");
    }
    elseif ($action === 'update_section_student') {
        $data = json_decode(file_get_contents("php://input"), true);
        $id = intval($data['id']);
        $subjectId = intval($data['subject_id'] ?? 0);
        $semester  = intval($data['semester'] ?? 0);
        
        $stmt = $db->prepare("SELECT student_no, course_program, year_level, section, academic_year
            FROM section_student WHERE id = ? AND faculty_id = ?");
        $stmt->bind_param("ii", $id, $faculty_id);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        
        if (!$current) {
            echo ResponseAPI::error("Student not found");
            exit;
        }

        $oldStudentNo = $current['student_no'];
        $newStudentNo = $data['student_no'] ?? $oldStudentNo;

        $stmt = $db->prepare("UPDATE section_student SET last_name = ?, first_name = ?, middle_initial = ?, student_no = ?
            WHERE id = ? AND faculty_id = ?");
        $stmt->bind_param("ssssii", $data['last_name'], $data['first_name'], $data['middle_initial'], $newStudentNo, $id, $faculty_id);
        if (!$stmt->execute()) {
            echo ResponseAPI::error("Failed to update student: " . $stmt->error);
            exit;
        }

        // Sync updated info to matching class rosters.
        // When student_no changed, search by the old value; otherwise use the new one.
        $searchStudentNo = ($newStudentNo !== $oldStudentNo) ? $oldStudentNo : $newStudentNo;

        $program = $db->real_escape_string($data['course_program'] ?? $current['course_program']);
        $year    = intval($data['year_level'] ?? $current['year_level']);
        $section = $db->real_escape_string($data['section'] ?? $current['section']);
        $ay      = $db->real_escape_string($data['academic_year'] ?? $current['academic_year']);

        $classWhere = "faculty_id = $faculty_id AND course_program = '$program' AND year_level = $year AND section = '$section' AND academic_year = '$ay'";
        if ($subjectId > 0) {
            $classWhere .= " AND subject_id = $subjectId";
        }
        if ($semester > 0) {
            $classWhere .= " AND semester = $semester";
        }
        $classes = $db->query("SELECT id FROM class_section WHERE $classWhere")->fetch_all(MYSQLI_ASSOC);

        $createdClassId = null;
        if (empty($classes) && $subjectId > 0 && $semester > 0) {
            $insertClass = $db->prepare("INSERT INTO class_section 
                (subject_id, faculty_id, course_program, year_level, section, semester, academic_year) 
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insertClass->bind_param("iisisis", $subjectId, $faculty_id, $program, $year, $section, $semester, $ay);
            if ($insertClass->execute()) {
                $createdClassId = $db->insert_id;
                createDefaultGradingSheets($db, $createdClassId, $faculty_id);
                $classes[] = ['id' => $createdClassId];
            }
        }

        $syncCount = 0;
        foreach ($classes as $class) {
            // Check if student already exists in this class
            $check = $db->prepare("SELECT id FROM student WHERE class_section_id = ? AND student_no = ?");
            $check->bind_param("is", $class['id'], $searchStudentNo);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();

            if ($existing) {
                $update = $db->prepare("UPDATE student SET last_name = ?, first_name = ?, middle_initial = ?, student_no = ? WHERE id = ?");
                $update->bind_param("sssssi", $data['last_name'], $data['first_name'], $data['middle_initial'], $newStudentNo, $existing['id']);
                if ($update->execute()) {
                    $syncCount++;
                }
            } else {
                // Student not yet in this class — add them
                $insert = $db->prepare("INSERT INTO student (class_section_id, last_name, first_name, middle_initial, student_no) 
                    VALUES (?, ?, ?, ?, ?)");
                $insert->bind_param("issss", $class['id'], $data['last_name'], $data['first_name'], $data['middle_initial'], $newStudentNo);
                if ($insert->execute()) {
                    $syncCount++;
                }
            }
        }

        $message = "Student updated" . ($syncCount > 0 ? " ($syncCount class roster(s) synced)" : "");
        echo ResponseAPI::success(['synced_classes' => $syncCount, 'created_class' => $createdClassId], $message);
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

        // An optional year/month limits the export to the sheet on screen, so the
        // download matches what the teacher is looking at.
        $year = intval($_GET['year'] ?? 0);
        $month = intval($_GET['month'] ?? 0);
        if ($year > 0 && $month > 0) {
            $stmt = $db->prepare("SELECT * FROM attendance_session
                                  WHERE class_section_id = ? AND YEAR(date) = ? AND MONTH(date) = ?
                                  ORDER BY date ASC");
            $stmt->bind_param("iii", $classId, $year, $month);
            $stmt->execute();
            $sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } else {
            $sessions = $db->query("SELECT * FROM attendance_session WHERE class_section_id = $classId ORDER BY date ASC")->fetch_all(MYSQLI_ASSOC);
        }

        if ($format === 'xlsx' || $format === 'excel') {
            generateAttendanceExcel($db, $class, $students, $sessions, $year, $month);
        } elseif ($format === 'doc' || $format === 'word') {
            generateAttendanceDoc($db, $class, $students, $sessions);
        } else {
            generateAttendancePdf($db, $class, $students, $sessions);
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
    // Rebuildable GRADE SHEET preview + editor persistence.
    elseif ($action === 'render_gradesheet') {
        $classId = intval($_GET['class_id'] ?? 0);
        $facultyName = $_SESSION['faculty_name'] ?? '';

        $sheetData = gradesheet_load($db, $classId, $facultyName);
        if (!$sheetData) {
            header('Content-Type: text/plain; charset=UTF-8');
            http_response_code(404);
            echo 'Class not found.';
            exit;
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo gradesheet_render($sheetData);
    }
    elseif ($action === 'save_report_settings') {
        $payload = json_decode(file_get_contents("php://input"), true);
        $classId = intval($payload['class_id'] ?? $_POST['class_id'] ?? $_GET['class_id'] ?? 0);
        $fields = $payload['fields'] ?? [];

        if ($classId <= 0 || !is_array($fields) || empty($fields)) {
            echo ResponseAPI::error("Invalid class or no fields supplied");
            exit;
        }

        // Only the class owner may change a class report.
        $owner = $db->prepare("SELECT faculty_id FROM class_section WHERE id = ?");        $owner->bind_param('i', $classId);
        $owner->execute();
        $ownerRow = $owner->get_result()->fetch_assoc();
        if (!$ownerRow) {
            echo ResponseAPI::error("Class not found");
            exit;
        }
        if (intval($ownerRow['faculty_id']) !== intval($faculty_id)) {
            echo ResponseAPI::error("Not authorised for this class");
            exit;
        }

        $upsert = $db->prepare("INSERT INTO report_settings
                                (class_section_id, field_key, field_value, updated_by)
                                VALUES (?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE
                                    field_value = VALUES(field_value),
                                    updated_by = VALUES(updated_by),
                                    updated_at = CURRENT_TIMESTAMP");

        // Metadata keys are a fixed allowlist; cell keys must look like cell:<id>:<column>.
        $metaAllowlist = array_keys(gradesheet_defaults([
            'code' => '', 'title' => '', 'course_program' => '',
            'year_level' => '', 'section' => '', 'academic_year' => '', 'semester' => 1,
        ]));

        // Static fallback to ensure footer fields are always allowed even if the
        // dynamic call misses any (e.g. function signature changes).
        $metaAllowlist = array_merge($metaAllowlist, [
            'certification',
            'facilitator_name',
            'program_chair',
            'dean',
            'registrar',
            'date_received',
            'note',
        ]);
        $metaAllowlist = array_unique($metaAllowlist);

        $saved = 0;
        $skipped = 0;
        foreach ($fields as $key => $value) {
            $key = trim((string)$key);
            $isMeta = in_array($key, $metaAllowlist, true);
            $isCell = (bool)preg_match('/^cell:\d+:[a-z_]+$/', $key);
            if (!$isMeta && !$isCell) {
                $skipped++;
                continue;
            }
            $value = is_scalar($value) ? trim((string)$value) : '';
            $value = mb_substr($value, 0, 2000);
            $upsert->bind_param('issi', $classId, $key, $value, $faculty_id);
            if ($upsert->execute()) {
                $saved++;
            }
        }

        echo ResponseAPI::success(
            ['saved' => $saved, 'skipped' => $skipped],
            $saved > 0 ? 'Report changes saved' : 'Nothing to save'
        );
    }
    // CLASS RECORD (print attendance): read the sheet the print and PDF views render.
    elseif ($action === 'get_class_record') {
        $classId = intval($_GET['class_id'] ?? $_POST['class_id'] ?? 0);

        $owner = $db->prepare("SELECT faculty_id FROM class_section WHERE id = ?");
        $owner->bind_param('i', $classId);
        $owner->execute();
        $ownerRow = $owner->get_result()->fetch_assoc();
        if (!$ownerRow) {
            echo ResponseAPI::error('Class not found');
            exit;
        }
        if (intval($ownerRow['faculty_id']) !== intval($faculty_id)) {
            echo ResponseAPI::error('Not authorised for this class');
            exit;
        }

        $data = classrecord_load($db, $classId, $_SESSION['faculty_name'] ?? '');
        if (!$data) {
            echo ResponseAPI::error('Class not found');
            exit;
        }

        // Only the values the dialog needs: the editable fields and a quick
        // summary so the split date can be explained before it is changed.
        $periods = [];
        foreach ($data['periods'] as $period) {
            $periods[] = [
                'label'      => $period['label'],
                'dates'      => count($period['columns']),
                'countable'  => $period['countable'],
                'first'      => $period['columns'][0]['date'] ?? '',
                'last'       => $period['columns'] ? $period['columns'][count($period['columns']) - 1]['date'] : '',
            ];
        }

        echo ResponseAPI::success([
            'meta'         => $data['meta'],
            'periods'      => $periods,
            'split_date'   => $data['split_date'],
            'term_year'    => $data['term_year'],
            'student_count' => count($data['rows']),
            'fields'       => array_keys($data['meta']),
        ]);
    }
    // CLASS RECORD settings: header wording, footer names, paper and period split.
    elseif ($action === 'save_class_record_settings') {
        $classId = intval($_POST['class_id'] ?? $_GET['class_id'] ?? 0);
        $raw = file_get_contents("php://input");
        $payload = json_decode($raw, true);
        // An undecodable body must be an error, not an empty save: the dialog
        // would report success and every field would silently be discarded.
        if (!is_array($payload)) {
            echo ResponseAPI::error('Could not read the settings: malformed request body');
            exit;
        }
        $fields = $payload['fields'] ?? [];
        if (!is_array($fields) || empty($fields)) {
            echo ResponseAPI::error('No settings were supplied');
            exit;
        }

        $owner = $db->prepare("SELECT faculty_id FROM class_section WHERE id = ?");
        $owner->bind_param('i', $classId);
        $owner->execute();
        $ownerRow = $owner->get_result()->fetch_assoc();
        if (!$ownerRow) {
            echo ResponseAPI::error('Class not found');
            exit;
        }
        if (intval($ownerRow['faculty_id']) !== intval($faculty_id)) {
            echo ResponseAPI::error('Not authorised for this class');
            exit;
        }

        // Fixed allowlist: every printable header/footer field plus the layout
        // switches. Nothing else may be written under a report_settings key.
        $allowlist = [
            'course_number', 'course_title', 'semester_term', 'course_and_year',
            'class_record_note',
            'facilitator_name', 'program_chair', 'satellite_director',
            'cr_paper', 'cr_term_year', 'cr_period_split', 'cr_from_month',
            'cr_to_month', 'cr_rotate_dates',
        ];

        $saved = 0;
        foreach ($fields as $key => $value) {
            $key = trim((string)$key);
            if (!in_array($key, $allowlist, true)) {
                continue;
            }
            $value = is_scalar($value) ? trim((string)$value) : '';
            $value = mb_substr($value, 0, 2000);

            if ($key === 'cr_paper') {
                $value = strtolower($value) === 'a4' ? 'a4' : 'legal';
            } elseif ($key === 'cr_term_year') {
                // 0 keeps every year, which is only sensible for a class with one.
                $value = max(0, intval($value));
            } elseif ($key === 'cr_from_month' || $key === 'cr_to_month') {
                // Only a real YYYY-MM is kept; this value is reused as a SQL date
                // bound, so it has to be validated rather than stored as typed.
                $value = classrecord_normalize_month($value);
            } elseif ($key === 'cr_period_split') {
                // Only a real date is accepted; anything else would silently move
                // every column into one period.
                $value = ($value !== '' && strtotime($value)) ? date('Y-m-d', strtotime($value)) : '';
            }
            classrecord_save_setting($db, $classId, $key, $value, $faculty_id);
            $saved++;
        }

        // A new term year or split date has to be pushed onto the sessions
        // themselves, otherwise the columns keep the periods of the old scope.
        $termYear = 0;
        $readSetting = function ($key) use ($db, $classId) {
            $stmt = $db->prepare("SELECT field_value FROM report_settings
                                  WHERE class_section_id = ? AND field_key = ?");
            $stmt->bind_param('is', $classId, $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            return $row['field_value'] ?? '';
        };
        if (array_key_exists('cr_term_year', $fields)) {
            $termYear = max(0, intval($readSetting('cr_term_year')));
        }
        if (array_key_exists('cr_term_year', $fields) && $termYear === 0) {
            // "Every year" has no single split, so clear the saved one and let the
            // loader work it out from the full range.
            classrecord_save_setting($db, $classId, 'cr_period_split', '');
        }
        if (array_key_exists('cr_period_split', $fields)
            || array_key_exists('cr_term_year', $fields)
            || array_key_exists('cr_from_month', $fields)
            || array_key_exists('cr_to_month', $fields)) {
            $split = $readSetting('cr_period_split');
            if ($split !== '') {
                // The period and term months narrow the split the same way they
                // narrow the printed columns, and so do the class's meeting days, so
                // a block boundary is never drawn outside the printed range.
                $scope = classrecord_year_filter_sql($termYear)
                    . classrecord_month_filter_sql(
                        classrecord_normalize_month($readSetting('cr_from_month')),
                        classrecord_normalize_month($readSetting('cr_to_month'))
                    )
                    . classrecord_weekday_filter_sql(
                        classrecord_scheduled_weekdays($db, $classId)
                    );
                classrecord_assign_periods($db, $classId, $split, $scope);
            }
        }

        echo ResponseAPI::success(['saved' => $saved], 'Class record settings saved');
    }
    elseif ($action === 'save_grading_sheet_template') {
        $classId = intval($_POST['class_id'] ?? $_GET['class_id'] ?? 0);
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!$classId) {
            echo ResponseAPI::error("Invalid class ID");
            exit;
        }
        
        $class = $db->query("SELECT cs.*, s.code, s.title FROM class_section cs JOIN subject s ON cs.subject_id = s.id WHERE cs.id = $classId")->fetch_assoc();
        if (!$class) {
            echo ResponseAPI::error("Class not found");
            exit;
        }
        
        // Get or create template
        $stmt = $db->prepare("SELECT * FROM grading_sheet_template WHERE class_section_id = ?");
        $stmt->bind_param("i", $classId);
        $stmt->execute();
        $template = $stmt->get_result()->fetch_assoc();
        
        $academicYear = $data['academic_year'] ?? $class['academic_year'];
        $semester = $data['semester'] ?? $class['semester'];
        $courseNumber = $data['course_number'] ?? $class['code'];
        $courseTitle = $data['course_title'] ?? $class['title'];
        $courseYearSection = $data['course_year_section'] ?? $class['course_program'] . ' ' . $class['year_level'] . '-' . $class['section'];
        $studentsPerPage = intval($data['students_per_page'] ?? 20);
        $pageSize = $data['page_size'] ?? 'A4';
        $marginTop = intval($data['margin_top'] ?? 15);
        $marginBottom = intval($data['margin_bottom'] ?? 15);
        $marginLeft = intval($data['margin_left'] ?? 15);
        $marginRight = intval($data['margin_right'] ?? 15);
        
        if ($template) {
            $stmt = $db->prepare("UPDATE grading_sheet_template SET 
                academic_year = ?, semester = ?, course_number = ?, course_title = ?, course_year_section = ?,
                students_per_page = ?, page_size = ?, margin_top = ?, margin_bottom = ?, margin_left = ?, margin_right = ?
                WHERE id = ?");
            $stmt->bind_param("sssssiiiiiii", $academicYear, $semester, $courseNumber, $courseTitle, $courseYearSection,
                $studentsPerPage, $pageSize, $marginTop, $marginBottom, $marginLeft, $marginRight, $template['id']);
            $stmt->execute();
            $templateId = $template['id'];
        } else {
            $stmt = $db->prepare("INSERT INTO grading_sheet_template 
                (class_section_id, academic_year, semester, course_number, course_title, course_year_section,
                students_per_page, page_size, margin_top, margin_bottom, margin_left, margin_right)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssssiiiiii", $classId, $academicYear, $semester, $courseNumber, $courseTitle, $courseYearSection,
                $studentsPerPage, $pageSize, $marginTop, $marginBottom, $marginLeft, $marginRight);
            $stmt->execute();
            $templateId = $db->insert_id;
        }
        
        // Generate items from student data
        $students = $db->query("SELECT * FROM student WHERE class_section_id = $classId ORDER BY last_name")->fetch_all(MYSQLI_ASSOC);
        $gradesData = [];
        foreach ($students as $student) {
            $grades = GradingHelper::calculateStudentGrades($db, $classId, $student['id']);
            $gradesData[] = array_merge($student, $grades);
        }
        
        // Clear existing items
        $db->query("DELETE FROM grading_sheet_items WHERE template_id = $templateId");
        
        // Insert new items
        $stmt = $db->prepare("INSERT INTO grading_sheet_items 
            (template_id, student_id, student_number, last_name, first_name, middle_initial,
            midterm_rating, midterm_remarks, numerical_rating, final_grade, unit_credit, remarks, page_number, row_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $studentsPerPage = intval($data['students_per_page'] ?? 20);
        foreach ($gradesData as $i => $student) {
            $pageNum = floor($i / $studentsPerPage) + 1;
            $rowOrder = $i % $studentsPerPage;
            
            $name = trim(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_initial'] ?? '') . '.');
            $midtermRating = round($student['midterm']['grade'] ?? 0);
            $midtermRemarks = $student['midterm']['remarks'] ?? 'INC';
            $finalRating = round($student['final']['grade'] ?? 0);
            $finalGrade = (string)($student['overall']['grade_point'] ?? 'INC');
            $remarks = $student['overall']['remarks'] ?? 'INC';
            
            $stmt->bind_param("iiisssssisii", $templateId, $student['id'], $i + 1, $student['last_name'], $student['first_name'],
                $student['middle_initial'], $midtermRating ?: 'INC', $midtermRemarks, $finalRating ?: 'INC',
                $finalGrade, 3, $remarks, $pageNum, $rowOrder);
            $stmt->execute();
        }
        
        echo ResponseAPI::success(['template_id' => $templateId], "Grading sheet template saved");
    }
    elseif ($action === 'get_grading_sheet_template') {
        $classId = intval($_GET['class_id'] ?? 0);
        
        $stmt = $db->prepare("SELECT * FROM grading_sheet_template WHERE class_section_id = ?");
        $stmt->bind_param("i", $classId);
        $stmt->execute();
        $template = $stmt->get_result()->fetch_assoc();
        
        if (!$template) {
            echo ResponseAPI::error("Template not found");
            exit;
        }
        
        // Get items
        $items = $db->query("SELECT * FROM grading_sheet_items WHERE template_id = {$template['id']} ORDER BY page_number, row_order")->fetch_all(MYSQLI_ASSOC);
        $template['items'] = $items;
        
        echo ResponseAPI::success($template);
    }
    elseif ($action === 'export_grading_sheet') {
        $classId = intval($_GET['class_id'] ?? 0);
        $format = $_GET['format'] ?? 'pdf';
        $templateId = intval($_GET['template_id'] ?? 0);
        
        if (!$templateId) {
            $stmt = $db->prepare("SELECT id FROM grading_sheet_template WHERE class_section_id = ?");
            $stmt->bind_param("i", $classId);
            $stmt->execute();
            $template = $stmt->get_result()->fetch_assoc();
            if (!$template) {
                echo ResponseAPI::error("Template not found. Generate template first.");
                exit;
            }
            $templateId = $template['id'];
        }
        
        // Log export
        $facultyId = $auth->getFacultyId();
        $fileName = "GradingSheet_{$classId}_{$format}_" . date('Ymd_His');
        $stmt = $db->prepare("INSERT INTO grading_sheet_exports (template_id, export_format, exported_by, file_name, student_count) VALUES (?, ?, ?, ?, ?)");
        $studentCount = $db->query("SELECT COUNT(*) as c FROM grading_sheet_items WHERE template_id = $templateId")->fetch_assoc()['c'];
        $stmt->bind_param("isisi", $templateId, $format, $facultyId, $fileName, $studentCount);
        $stmt->execute();
        
        if ($format === 'csv') {
            exportGradingSheetCsv($templateId);
        } elseif ($format === 'xlsx') {
            exportGradingSheetXlsx($templateId);
        } elseif ($format === 'doc') {
            exportGradingSheetDoc($templateId);
        } else {
            exportGradingSheetPdf($templateId);
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
            $config['perfect_score'] = array_sum(array_map(function ($item) {
                return floatval($item['max_score']);
            }, $config['items']));
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
        
        // The real work is in saveCategoryConfigs() so it can be exercised without a
        // logged-in HTTP request.
        try {
            saveCategoryConfigs($db, $classId, $period, is_array($configs) ? $configs : []);
        } catch (\RuntimeException $e) {
            echo ResponseAPI::error($e->getMessage(), 500);
            exit;
        }

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
} catch (\Exception | \Error $e) {
    ob_clean();
    echo ResponseAPI::error($e->getMessage(), 500);
}

/**
 * Saves the category manager's payload as an in-place upsert.
 *
 * This used to delete every config for the class/period and re-insert it.
 * Re-inserting hands out new grade_category_config, grade_item_config and
 * grade_item ids on every save, and grade_score points at grade_item - so each
 * save stranded every score already entered. removeSyncedConfigRows() "kept"
 * those scores by nulling grade_category_id, but the grading table reads through
 * that column, so the teacher simply saw an empty table.
 *
 * Matching each incoming config to its existing row by id and updating in place
 * keeps every id, and therefore every grade_score, valid. Adding a category or
 * editing a weight now costs nothing that was already recorded.
 *
 * @throws \RuntimeException on a write failure, so the caller can report it.
 */
function saveCategoryConfigs($db, $classId, $period, array $configs) {
    $classId = intval($classId);
    $period = ($period === 'final') ? 'final' : 'midterm';

    $existingStmt = $db->prepare("SELECT id FROM grade_category_config WHERE class_section_id = ? AND period = ?");
    $existingStmt->bind_param("is", $classId, $period);
    $existingStmt->execute();
    $existingConfigs = $existingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $existingIds = array_map('intval', array_column($existingConfigs, 'id'));
    $existingSet = array_flip($existingIds);

    $keptConfigIds = [];

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
        $itemCount = intval($config['item_count'] ?? 1);
        $isVisible = isset($config['is_visible']) ? (bool)$config['is_visible'] : true;
        $items = isset($config['items']) && is_array($config['items']) ? $config['items'] : [];
        $perfectScore = array_sum(array_map(function ($item) {
            return floatval($item['max_score'] ?? 0);
        }, $items));

        // Only reuse an id that really belongs to this class and period, so a stale
        // or hand-edited payload cannot retarget another class's row.
        $incomingId = intval($config['id'] ?? 0);
        $configId = ($incomingId > 0 && isset($existingSet[$incomingId])) ? $incomingId : 0;

        if ($configId > 0) {
            $stmt = $db->prepare("
                UPDATE grade_category_config
                SET template_id = ?, custom_name = ?, weight_percent = ?,
                    perfect_score = ?, item_count = ?, sort_order = ?, is_visible = ?
                WHERE id = ? AND class_section_id = ? AND period = ?
            ");
            // Types follow the placeholder order: template_id, custom_name,
            // weight_percent, perfect_score, item_count, sort_order, is_visible,
            // then id, class_section_id, period.
            $stmt->bind_param("ssddiiiiis", $templateId, $customName, $weight,
                $perfectScore, $itemCount, $sortOrder, $isVisible, $configId, $classId, $period);
        } else {
            $stmt = $db->prepare("
                INSERT INTO grade_category_config (class_section_id, period, template_id, custom_name, weight_percent, perfect_score, item_count, sort_order, is_visible)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isisddiii", $classId, $period, $templateId, $customName, $weight, $perfectScore, $itemCount, $sortOrder, $isVisible);
        }

        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to save category: ' . $stmt->error);
        }
        if ($configId === 0) {
            $configId = intval($db->insert_id);
        }

        $keptConfigIds[] = $configId;
        syncCategoryItems($db, $configId, $items);
        $sortOrder++;
    }

    // Configs the teacher deleted outright. Still the old helper: it detaches scored
    // items rather than deleting them, and clears grade_category.config_id so the row
    // can go despite that FK having no cascade.
    $removedConfigIds = array_values(array_diff($existingIds, $keptConfigIds));
    if (!empty($removedConfigIds)) {
        removeSyncedConfigRows($db, $removedConfigIds);
        $removeList = implode(',', array_map('intval', $removedConfigIds));
        $db->query("DELETE FROM grade_category_config WHERE id IN ($removeList)");
    }

    syncGradeTables($db, $classId, $period);
}

/**
 * Reconcile one config's items against the incoming list, keeping the id of every
 * item that survives so its grade_item - and therefore its grade_score - stays put.
 *
 * Items are matched by label because the category dialog only sends label and
 * max_score. An item the teacher removed is deleted, unless it holds recorded
 * scores: grade_item.item_config_id has no ON DELETE, so the row has to be
 * detached first. Detaching keeps the scores in the database but takes the item
 * out of the grading table, which is the intended result of deleting an item.
 */
function syncCategoryItems($db, $configId, array $items) {
    $itemStmt = $db->prepare("SELECT id, label FROM grade_item_config WHERE category_config_id = ? ORDER BY sort_order");
    $itemStmt->bind_param("i", $configId);
    $itemStmt->execute();

    // Keyed by trimmed label so " Quiz 1 " matches the stored "Quiz 1".
    $existingByLabel = [];
    foreach ($itemStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $existingByLabel[trim($row['label'])] = intval($row['id']);
    }

    $keptItemIds = [];
    $itemSort = 0;
    foreach ($items as $item) {
        $label = trim($item['label'] ?? '');
        if ($label === '') {
            continue;
        }
        $maxScore = floatval($item['max_score'] ?? 0);

        if (isset($existingByLabel[$label])) {
            $itemConfigId = $existingByLabel[$label];
            $upd = $db->prepare("UPDATE grade_item_config SET label = ?, max_score = ?, sort_order = ? WHERE id = ? AND category_config_id = ?");
            $upd->bind_param("sdiii", $label, $maxScore, $itemSort, $itemConfigId, $configId);
            $upd->execute();
        } else {
            $ins = $db->prepare("INSERT INTO grade_item_config (category_config_id, label, max_score, sort_order) VALUES (?, ?, ?, ?)");
            $ins->bind_param("isdi", $configId, $label, $maxScore, $itemSort);
            if (!$ins->execute()) {
                throw new \RuntimeException('Failed to save item: ' . $ins->error);
            }
            $itemConfigId = intval($db->insert_id);
        }

        $keptItemIds[] = $itemConfigId;
        $itemSort++;
    }

    // Items the teacher removed from this category.
    $removedItemIds = array_values(array_diff(array_values($existingByLabel), $keptItemIds));
    foreach ($removedItemIds as $removedId) {
        // Clear the restrict FK, preferring to keep the row when scores depend on it.
        $db->query("UPDATE grade_item SET item_config_id = NULL
                    WHERE item_config_id = $removedId
                      AND id IN (SELECT DISTINCT grade_item_id FROM grade_score)");
        $db->query("DELETE FROM grade_item WHERE item_config_id = $removedId
                    AND id NOT IN (SELECT grade_item_id FROM grade_score)");
        $db->query("DELETE FROM grade_item_config WHERE id = $removedId AND category_config_id = $configId");
    }
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
        
        // Remove items that no longer exist in config - but PRESERVE items with student scores
        $existingItemConfigIds = array_column($items, 'id');
        if (!empty($existingItemConfigIds)) {
            $placeholders = implode(',', array_fill(0, count($existingItemConfigIds), '?'));
            $types = str_repeat('i', count($existingItemConfigIds));
            $params = array_merge([$categoryId], $existingItemConfigIds);
            // Only delete items that have NO student scores
            $stmt = $db->prepare("DELETE gi FROM grade_item gi 
                LEFT JOIN grade_score gs ON gs.grade_item_id = gi.id 
                WHERE gi.grade_category_id = ? 
                AND gi.item_config_id NOT IN ($placeholders) 
                AND gs.id IS NULL");
            bindDynamicParams($stmt, "i$types", $params);
            $stmt->execute();
        } else {
            // Delete all items in category that have NO student scores
            $db->query("DELETE gi FROM grade_item gi 
                LEFT JOIN grade_score gs ON gs.grade_item_id = gi.id 
                WHERE gi.grade_category_id = $categoryId AND gs.id IS NULL");
        }
    }
    
    // Remove categories that no longer exist in config
    $existingConfigIds = array_column($configs, 'id');
    removeUnsyncedCategories($db, $classId, $period, $existingConfigIds);
}

// Drop grade_category rows that are not backed by the active config set, keeping
// the same "preserve recorded scores" rule already used for grade items.
// `config_id NOT IN (...)` never matches legacy rows because SQL NULL comparison
// yields NULL, so orphaned migration rows used to survive every sync and get
// double counted alongside the synced categories.
function removeUnsyncedCategories($db, $classId, $period, $configIds) {
    $configIds = array_values(array_filter(array_map('intval', $configIds)));

    $scopeSql = "gc.class_section_id = ? AND gc.period = ?";
    $scopeParams = [$classId, $period];
    $scopeTypes = 'is';

    if (!empty($configIds)) {
        $placeholders = implode(',', array_fill(0, count($configIds), '?'));
        $scopeSql .= " AND (gc.config_id IS NULL OR gc.config_id NOT IN ($placeholders))";
        $scopeParams = array_merge($scopeParams, $configIds);
        $scopeTypes .= str_repeat('i', count($configIds));
    }

    // Collect the categories about to be removed.
    $findStmt = $db->prepare("SELECT gc.id FROM grade_category gc WHERE $scopeSql");
    bindDynamicParams($findStmt, $scopeTypes, $scopeParams);
    $findStmt->execute();
    $categoryIds = array_column($findStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');

    if (empty($categoryIds)) {
        return;
    }

    $categoryList = implode(',', array_map('intval', $categoryIds));

    // Detach items that already carry student scores so nothing is lost.
    $db->query("UPDATE grade_item SET item_config_id = NULL, grade_category_id = NULL
        WHERE grade_category_id IN ($categoryList)
        AND id IN (SELECT DISTINCT grade_item_id FROM grade_score)");

    // The rest of the items go with their category.
    $db->query("DELETE FROM grade_item WHERE grade_category_id IN ($categoryList)");

    $db->query("DELETE FROM grade_category WHERE id IN ($categoryList)");
}

function removeSyncedConfigRows($db, $configIds) {
    $configIds = array_values(array_filter(array_map('intval', $configIds)));
    if (empty($configIds)) {
        return;
    }

    $idList = implode(',', $configIds);

    // Get grade_item IDs that have scores (to preserve)
    $result = $db->query("SELECT gi.id FROM grade_item gi
        INNER JOIN grade_category gc ON gc.id = gi.grade_category_id
        INNER JOIN grade_score gs ON gs.grade_item_id = gi.id
        WHERE gc.config_id IN ($idList)");
    $scoredItemIds = [];
    while ($row = $result->fetch_assoc()) {
        $scoredItemIds[] = $row['id'];
    }

    // For items with scores: orphan them (remove config link)
    if (!empty($scoredItemIds)) {
        $placeholders = implode(',', array_fill(0, count($scoredItemIds), '?'));
        $stmt = $db->prepare("UPDATE grade_item SET item_config_id = NULL WHERE id IN ($placeholders)");
        $types = str_repeat('i', count($scoredItemIds));
        $stmt->bind_param($types, ...$scoredItemIds);
        $stmt->execute();
    }

    // Delete grade_items WITHOUT scores
    $db->query("DELETE gi FROM grade_item gi
        INNER JOIN grade_category gc ON gc.id = gi.grade_category_id
        LEFT JOIN grade_score gs ON gs.grade_item_id = gi.id
        WHERE gc.config_id IN ($idList) AND gs.id IS NULL");

    // For remaining items with scores: clear grade_category_id to allow category deletion
    if (!empty($scoredItemIds)) {
        $placeholders = implode(',', array_fill(0, count($scoredItemIds), '?'));
        $stmt = $db->prepare("UPDATE grade_item SET grade_category_id = NULL WHERE id IN ($placeholders)");
        $types = str_repeat('i', count($scoredItemIds));
        $stmt->bind_param($types, ...$scoredItemIds);
        $stmt->execute();
    }

    // Now delete grade_categories (FK constraints cleared)
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

function generateAttendanceCSV($db, $class, $students, $sessions) {
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
            $status = $record ? $record['status'] : '';
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

// Excel column letters for a zero-based column index: 0 = A, 26 = AA.
function excelColumnName(int $index) {
    $name = '';
    $index++;
    while ($index > 0) {
        $remainder = ($index - 1) % 26;
        $name = chr(65 + $remainder) . $name;
        $index = intdiv($index - $remainder, 26);
    }
    return $name;
}

// One worksheet cell as inline XML, so no shared-string table is needed.
function excelCell(string $reference, $value, int $style = 0) {
    $styleAttr = $style > 0 ? ' s="' . $style . '"' : '';
    if ($value === null || $value === '') {
        return '<c r="' . $reference . '"' . $styleAttr . '/>';
    }
    $text = htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    return '<c r="' . $reference . '"' . $styleAttr . ' t="inlineStr"><is><t xml:space="preserve">'
        . $text . '</t></is></c>';
}

// Style table for the attendance workbook. Index order matters: each cellXf below
// is referred to by number from the sheet builder.
//  0 plain  1 title  2 subtitle  3 header center  4 header left  5 name cell
//  6 present  7 absent  8 late  9 excused  10 blank mark  11 holiday banner
//  12 seminar banner  13 totals header  14 attendance cell  15 legend
function excelStylesXml() {
    $fonts = [
        '<font><sz val="11"/><name val="Calibri"/></font>',
        '<font><b/><sz val="11"/><name val="Calibri"/></font>',
        '<font><b/><sz val="14"/><name val="Calibri"/></font>',
        '<font><sz val="10"/><name val="Calibri"/></font>',
        '<font><b/><sz val="8"/><name val="Calibri"/></font>',
        '<font><sz val="8"/><name val="Calibri"/></font>',
        '<font><b/><sz val="9"/><name val="Calibri"/></font>',
        '<font><b/><color rgb="FF881337"/><sz val="9"/><name val="Calibri"/></font>',
        '<font><b/><color rgb="FF4C1D95"/><sz val="9"/><name val="Calibri"/></font>',
    ];
    $fills = ['none', 'gray125', 'D9D9D9', 'FECDD3', 'DDD6FE', 'DCFCE7', 'FEE2E2', 'FEF3C7', 'DBEAFE', 'F1F5F9'];
    $fillXml = '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>';
    foreach (array_slice($fills, 2) as $color) {
        $fillXml .= '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $color
            . '"/><bgColor indexed="64"/></patternFill></fill>';
    }
    $thin = '<left style="thin"><color rgb="FF000000"/></left><right style="thin"><color rgb="FF000000"/></right>'
        . '<top style="thin"><color rgb="FF000000"/></top><bottom style="thin"><color rgb="FF000000"/></bottom>';

    // fill, font, border, alignment flags per cellXf
    $xfs = [
        [0, 0, 0, ''],                                   // 0 plain
        [0, 2, 0, 'horizontal="center"'],               // 1 title
        [0, 3, 0, 'horizontal="center"'],               // 2 subtitle
        [2, 4, 1, 'horizontal="center" vertical="center" wrapText="1"'],   // 3 header center
        [2, 4, 1, 'horizontal="left" vertical="center" wrapText="1"'],     // 4 header left
        [0, 5, 1, 'horizontal="left"'],                 // 5 name cell
        [5, 4, 1, 'horizontal="center"'],               // 6 present
        [6, 4, 1, 'horizontal="center"'],               // 7 absent
        [7, 4, 1, 'horizontal="center"'],               // 8 late
        [8, 4, 1, 'horizontal="center"'],               // 9 excused
        [0, 4, 1, 'horizontal="center"'],               // 10 blank mark
        [3, 7, 1, 'horizontal="center" vertical="center" wrapText="1"'],    // 11 holiday banner
        [4, 8, 1, 'horizontal="center" vertical="center" wrapText="1"'],    // 12 seminar banner
        [2, 4, 1, 'horizontal="center" vertical="center" wrapText="1"'],    // 13 totals header
        [9, 4, 1, 'horizontal="center" vertical="center" wrapText="1"'],    // 14 attendance cell
        [0, 5, 0, 'horizontal="left"'],                 // 15 legend
    ];
    $xfXml = '';
    foreach ($xfs as $xf) {
        list($fill, $font, $border, $align) = $xf;
        $xfXml .= '<xf numFmtId="0" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0"'
            . ' applyFont="1"' . ($fill > 0 ? ' applyFill="1"' : '')
            . ($border > 0 ? ' applyBorder="1"' : '')
            . ($align !== '' ? ' applyAlignment="1"><alignment ' . $align . '/></xf>' : '/>');
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
        . '<fills count="' . (count($fills)) . '">' . $fillXml . '</fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border>' . $thin . '<diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="' . count($xfs) . '">' . $xfXml . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

/**
 * Real .xlsx attendance sheet, written straight to OOXML.
 *
 * PhpWord 0.18 no longer ships an Excel writer, so the workbook is assembled by
 * hand: it mirrors the printed sheet rather than the CSV, with a merged holiday or
 * seminar banner row, P/A/L/E marks, and each student's own attendance figure.
 */
function generateAttendanceExcel($db, $class, $students, $sessions, $year = 0, $month = 0) {
    if (!class_exists('ZipArchive')) {
        echo ResponseAPI::error('Excel export unavailable: the PHP zip extension is not enabled');
        return;
    }

    $monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];

    $code = $class['code'] ?? '';
    $program = trim(($class['course_program'] ?? '') . ' ' . ($class['year_level'] ?? '')
        . '-' . ($class['section'] ?? ''));
    $period = ($year > 0 && $month > 0) ? $monthNames[$month - 1] . ' ' . $year : 'All months';

    $dayCount = count($sessions);
    $lastCol = excelColumnName($dayCount + 1);   // student name + days + attendance
    $merges = [];
    $rows = [];
    $rowNum = 0;

    $addRow = function (array $cells, ?int $height = null) use (&$rows, &$rowNum) {
        $rowNum++;
        $body = '';
        foreach ($cells as $colIndex => $cell) {
            $body .= excelCell(excelColumnName($colIndex) . $rowNum, $cell[0], $cell[1] ?? 0);
        }
        $attrs = $height ? ' ht="' . $height . '" customHeight="1"' : '';
        $rows[] = '<row r="' . $rowNum . '"' . $attrs . '>' . $body . '</row>';
    };

    // --- Title block ---
    $addRow([['MONTHLY ATTENDANCE SHEET', 1]], 22);
    $merges[] = 'A1:' . $lastCol . '1';
    $addRow([['Course: ' . $code . '   |   Class: ' . $program
        . '   |   AY: ' . ($class['academic_year'] ?? '')
        . '   |   Month: ' . $period, 2]], 16);
    $merges[] = 'A2:' . $lastCol . '2';

    // Which day columns are holiday or seminar, so their names can be merged into
    // a banner row that spans the highlighted block.
    $markedTypes = [];
    $markedLabels = [];
    foreach ($sessions as $i => $session) {
        $type = $session['session_type'] ?? 'regular';
        $markedTypes[$i] = ($type !== 'regular') ? $type : '';
        $markedLabels[$i] = trim((string)($session['label'] ?? ''));
    }
    $hasBanner = in_array('holiday', $markedTypes, true) || in_array('seminar', $markedTypes, true);

    if ($hasBanner) {
        $bannerRow = $rowNum + 1;   // the banner is the next row to be written
        $banner = [['STUDENT NAME', 4]];
        $col = 0;
        while ($col < $dayCount) {
            $type = $markedTypes[$col];
            if ($type === '') {
                $banner[] = ['', 10];
                $col++;
                continue;
            }
            $start = $col;
            while ($col < $dayCount && $markedTypes[$col] === $type) $col++;
            $names = [];
            for ($k = $start; $k < $col; $k++) {
                if ($markedLabels[$k] !== '') $names[] = $markedLabels[$k];
            }
            $text = $names ? implode(' • ', $names) : ($type === 'holiday' ? 'Holiday' : 'Seminar');
            $banner[] = [$text, $type === 'holiday' ? 11 : 12];
            if ($col - $start > 1) {
                $merges[] = excelColumnName($start + 1) . $bannerRow . ':' . excelColumnName($col) . $bannerRow;
            }
        }
        $banner[] = ['', 10];
        $addRow($banner, 20);
    }

    // --- Day header row ---
    $header = [['STUDENT NAME', 4]];
    foreach ($sessions as $session) {
        $header[] = [(string)date('j', strtotime($session['date'])), 3];
    }
    $header[] = ['ATTEND.', 3];
    $addRow($header, 22);
    $headerRow = $rowNum;

    // --- Marks use the same letters as the printed sheet ---
    $marks = ['present' => 'P', 'absent' => 'A', 'late' => 'L', 'excused' => 'E'];
    $markStyle = ['present' => 6, 'absent' => 7, 'late' => 8, 'excused' => 9];

    $attendance = $db->query("SELECT r.student_id, r.attendance_session_id, r.status
        FROM attendance_record r
        JOIN attendance_session a ON a.id = r.attendance_session_id
        WHERE a.class_section_id = " . intval($class['id'] ?? 0))->fetch_all(MYSQLI_ASSOC);
    $lookup = [];
    foreach ($attendance as $record) {
        $lookup[$record['student_id'] . ':' . $record['attendance_session_id']] = (string)$record['status'];
    }
    if (count($lookup) === 0) {
        // Fallback to per-cell lookups if the batch query did not resolve.
        foreach ($sessions as $session) {
            $rowsForSession = $db->query("SELECT student_id, status FROM attendance_record
                WHERE attendance_session_id = " . intval($session['id']))->fetch_all(MYSQLI_ASSOC);
            foreach ($rowsForSession as $record) {
                $lookup[$record['student_id'] . ':' . $session['id']] = (string)$record['status'];
            }
        }
    }

    foreach ($students as $student) {
        $counts = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
        $row = [[$student['last_name'] . ', ' . $student['first_name'], 5]];
        foreach ($sessions as $session) {
            $status = $lookup[$student['id'] . ':' . $session['id']] ?? '';
            if (isset($marks[$status])) {
                $counts[$status]++;
            }
            $row[] = [$marks[$status] ?? '', $status !== '' ? ($markStyle[$status] ?? 10) : 10];
        }
        $attended = $counts['present'] + $counts['late'];
        $rate = $dayCount > 0 ? round(($attended / $dayCount) * 100, 1) : 0;
        $row[] = [$dayCount > 0 ? $attended . '/' . $dayCount . "\n" . $rate . '%' : '-', 14];
        $addRow($row);
    }

    // --- Column totals ---
    $totals = [['TOTALS', 4]];
    foreach ($sessions as $index => $session) {
        $columnTotals = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
        foreach ($students as $student) {
            $status = $lookup[$student['id'] . ':' . $session['id']] ?? '';
            if (isset($columnTotals[$status])) $columnTotals[$status]++;
        }
        $best = '';
        $bestCount = -1;
        foreach ($columnTotals as $status => $count) {
            if ($count > $bestCount) { $best = $status; $bestCount = $count; }
        }
        $totals[] = [($bestCount > 0 ? $marks[$best] : '') . ' ' . $bestCount, 13];
    }
    $totals[] = ['', 13];
    $addRow($totals, 18);

    // --- Legend ---
    $addRow([['Legend:  P = Present   A = Absent   L = Late   E = Excused   (blank) = Unmarked', 15]]);
    $addRow([['Saving a day on the Attendance page marks every student left blank as present.', 15]]);

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $headerRow
        . '" topLeftCell="A' . ($headerRow + 1) . '" activePane="bottomLeft" state="frozen"/>'
        . '<selection pane="bottomLeft" activeCell="A' . ($headerRow + 1)
        . '" sqref="A' . ($headerRow + 1) . '"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . '<cols><col min="1" max="1" width="34" customWidth="1"/>';
    for ($i = 0; $i < $dayCount; $i++) {
        $sheet .= '<col min="' . ($i + 2) . '" max="' . ($i + 2) . '" width="4.5" customWidth="1"/>';
    }
    $sheet .= '<col min="' . ($dayCount + 2) . '" max="' . ($dayCount + 2)
        . '" width="11" customWidth="1"/></cols>'
        . '<sheetData>' . implode('', $rows) . '</sheetData>';
    if ($merges) {
        $sheet .= '<mergeCells count="' . count($merges) . '">';
        foreach ($merges as $merge) $sheet .= '<mergeCell ref="' . $merge . '"/>';
        $sheet .= '</mergeCells>';
    }
    $sheet .= '</worksheet>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Attendance" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
        . ' Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
        . ' Target="styles.xml"/></Relationships>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
        . ' Target="xl/workbook.xml"/></Relationships>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml"'
        . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml"'
        . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml"'
        . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'attendance_export_' . uniqid('', true) . '.xlsx';
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        echo ResponseAPI::error('Excel export failed: could not create the workbook file');
        return;
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', excelStylesXml());
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    $safeCode = preg_replace('/[^a-z0-9_-]+/i', '_', $code ?: 'report');
    $filename = 'Attendance_' . $safeCode
        . ($year > 0 && $month > 0 ? '_' . $year . '-' . sprintf('%02d', $month) : '') . '.xlsx';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: private, max-age=0');
    readfile($file);
    unlink($file);
    exit;
}

// Printable A4 attendance report. Every session becomes a column, so this uses
// landscape to keep the columns legible; a portrait sheet would squeeze them.
// The PDF and Word versions share this builder so they cannot drift apart.
function attendanceSheetHtml($db, $class, $students, $sessions) {
    $code = htmlspecialchars($class['code'] ?? '', ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars($class['title'] ?? '', ENT_QUOTES, 'UTF-8');
    $program = htmlspecialchars($class['course_program'] ?? '', ENT_QUOTES, 'UTF-8');
    $year = htmlspecialchars($class['year_level'] ?? '', ENT_QUOTES, 'UTF-8');
    $section = htmlspecialchars($class['section'] ?? '', ENT_QUOTES, 'UTF-8');
    $academicYear = htmlspecialchars($class['academic_year'] ?? '', ENT_QUOTES, 'UTF-8');
    $semester = ((int)($class['semester'] ?? 1) === 2) ? '2nd' : '1st';

    $logoPath = __DIR__ . '/../assets/images/capsu.jpg';
    $footerPath = __DIR__ . '/../assets/images/footer.png';
    $logoSrc = file_exists($logoPath)
        ? '/thesisEgrading/webapp/assets/images/capsu.jpg?v=' . filemtime($logoPath)
        : '';
    $footerSrc = file_exists($footerPath)
        ? '/thesisEgrading/webapp/assets/images/footer.png?v=' . filemtime($footerPath)
        : '';

    $rowsPerPage = 25;
    $totalPages = max(1, (int)ceil(count($students) / $rowsPerPage));

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Attendance - ' . $code . '</title><style>
        @page { size: A4 landscape; margin: 0; }
        body { font-family: Arial, sans-serif; font-size: 9pt; color: #000; margin: 0; }
        .page {
            width: 297mm; min-height: 210mm; margin: 0 auto 12mm; padding: 12mm;
            box-sizing: border-box; background: #fff; box-shadow: 0 1px 6px rgba(0,0,0,.25);
            display: flex; flex-direction: column; overflow: hidden;
            break-after: page; page-break-after: always;
        }
        .page > * { flex: 0 0 auto; }
        .page:last-child { break-after: auto; page-break-after: auto; }
        @media print { .page { margin: 0; box-shadow: none; } }
        .header-image { display: block; height: 30mm; width: auto; max-width: 100%; margin: 0 auto 8px; }
        .course-info { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 10pt; }
        .course-info-left, .course-info-right { display: flex; flex-direction: column; gap: 2px; }
        .course-info-right { text-align: right; }
        .course-info strong { font-weight: bold; }
        .attendance { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .attendance th, .attendance td { border: 1px solid #000; padding: 2px 3px; text-align: center; font-size: 8pt; }
        .attendance th { background: #d9d9d9; font-weight: bold; }
        .attendance .name { text-align: left; }
        .footer-image { width: 100%; height: auto; margin-top: auto; padding-top: 12px; display: block; break-inside: avoid; page-break-inside: avoid; }
    </style></head><body>';

    for ($page = 0; $page < $totalPages; $page++) {
        $html .= '<div class="page">';
        if ($logoSrc) {
            $html .= '<img class="header-image" src="' . $logoSrc . '" alt="University Header">';
        }
        $html .= '<div class="course-info">';
        $html .= '<div class="course-info-left"><span><strong>Course Number:</strong> ' . $code . '</span><span><strong>Course Title:</strong> ' . $title . '</span></div>';
        $html .= '<div class="course-info-right"><span><strong>' . $semester . ' Semester/Semester AY ' . $academicYear . '</strong></span><span><strong>Course and Year:</strong> ' . $program . ' ' . $year . '-' . $section . '</span></div>';
        $html .= '</div>';

        $html .= '<table class="attendance"><thead><tr>';
        $html .= '<th style="width:8%">Student No</th><th style="width:16%">Last Name</th><th style="width:12%">First Name</th>';
        foreach ($sessions as $session) {
            $label = htmlspecialchars($session['label'] ?? '', ENT_QUOTES, 'UTF-8');
            $html .= '<th>' . htmlspecialchars($session['date'], ENT_QUOTES, 'UTF-8')
                . ($label !== '' ? '<br><small>' . $label . '</small>' : '') . '</th>';
        }
        $html .= '<th>Present</th><th>Absent</th><th>Late</th><th>Excused</th><th>Sessions</th><th>Rate %</th>';
        $html .= '</tr></thead><tbody>';

        $startIdx = $page * $rowsPerPage;
        $endIdx = min($startIdx + $rowsPerPage, count($students));
        for ($i = $startIdx; $i < $endIdx; $i++) {
            $student = $students[$i];
            $present = 0; $absent = 0; $late = 0; $excused = 0;

            $html .= '<tr><td>' . htmlspecialchars($student['student_no'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td class="name">' . htmlspecialchars($student['last_name'], ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td class="name">' . htmlspecialchars($student['first_name'], ENT_QUOTES, 'UTF-8') . '</td>';

            foreach ($sessions as $session) {
                $record = $db->query("SELECT status FROM attendance_record
                    WHERE attendance_session_id = " . intval($session['id']) . "
                    AND student_id = " . intval($student['id']))->fetch_assoc();
                $status = $record ? $record['status'] : '';
                if ($status === 'present') $present++;
                elseif ($status === 'absent') $absent++;
                elseif ($status === 'late') $late++;
                elseif ($status === 'excused') $excused++;

                $html .= '<td>' . ($status !== '' ? htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') : '') . '</td>';
            }

            $total = count($sessions);
            $rate = $total > 0 ? round((($present + $late) / $total) * 100, 1) : 0;
            $html .= '<td>' . $present . '</td><td>' . $absent . '</td><td>' . $late . '</td>';
            $html .= '<td>' . $excused . '</td><td>' . $total . '</td><td>' . $rate . '</td></tr>';
        }
        $html .= '</tbody></table>';

        if ($footerSrc) {
            $html .= '<img class="footer-image" src="' . $footerSrc . '" alt="Footer">';
        }
        $html .= '</div>';
    }

    return $html . '</body></html>';
}

// Printable attendance report: browser print-to-PDF.
function generateAttendancePdf($db, $class, $students, $sessions) {
    header('Content-Type: text/html; charset=UTF-8');
    $html = attendanceSheetHtml($db, $class, $students, $sessions);
    echo str_replace('</body>', '<script>window.onload = function() { window.print(); };</script></body>', $html);
}

// Downloadable Word attendance report (Word HTML, matching the grading sheet DOC export).
function generateAttendanceDoc($db, $class, $students, $sessions) {
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="Attendance_'
        . preg_replace('/[^a-z0-9_-]+/i', '_', $class['code'] ?? 'report') . '.doc"');
    echo attendanceSheetHtml($db, $class, $students, $sessions);
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
    $code = htmlspecialchars($class['code'] ?? '', ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars($class['title'] ?? '', ENT_QUOTES, 'UTF-8');
    $program = htmlspecialchars($class['course_program'] ?? '', ENT_QUOTES, 'UTF-8');
    $year = htmlspecialchars($class['year_level'] ?? '', ENT_QUOTES, 'UTF-8');
    $section = htmlspecialchars($class['section'] ?? '', ENT_QUOTES, 'UTF-8');
    $academicYear = htmlspecialchars($class['academic_year'] ?? '', ENT_QUOTES, 'UTF-8');
    $semester = ((int)($class['semester'] ?? 1) === 2) ? '2nd' : '1st';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="GradingSheet_' . preg_replace('/[^a-z0-9_-]+/i', '_', $class['code']) . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    // Header rows
    fputcsv($output, ['Course Number:', $code]);
    fputcsv($output, ['Course Title:', $title]);
    fputcsv($output, ['Semester/AY:', $semester . ' Semester/Semester AY ' . $academicYear]);
    fputcsv($output, ['Course and Year:', $program . ' ' . $year . '-' . $section]);
    fputcsv($output, []); // empty row

    // Table headers
    fputcsv($output, ['No.', 'Name of Students (Last, First, MI)', 'Numerical Rating', 'Final Grade', 'Unit Credit', 'Remarks']);

    foreach ($gradesData as $i => $student) {
        $name = trim(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_initial'] ?? '') . '.');
        $midtermRating = round($student['midterm']['grade'] ?? 0);
        $midtermRemarks = $student['midterm']['remarks'] ?? 'INC';
        $finalRating = round($student['final']['grade'] ?? 0);
        $finalGrade = (string)($student['overall']['grade_point'] ?? 'INC');
        $remarks = $student['overall']['remarks'] ?? 'INC';

        fputcsv($output, [
            $i + 1,
            $name,
            $midtermRating ?: 'INC',
            $midtermRemarks,
            $finalRating ?: 'INC',
            $finalGrade,
            3,
            $remarks
        ]);
    }
    fclose($output);
}

// Shared A4 stylesheet for the grading sheet preview and the printable export.
// Each .page is a real 210mm x 297mm sheet so the on-screen preview matches what
// the printer produces. Kept in one place so the two never drift apart.
function gradingSheetPageCss() {
    return '
        @page { size: A4 portrait; margin: 0; }
        body { font-family: Arial, sans-serif; font-size: 10pt; color: #000; margin: 0; }
        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto 12mm;
            padding: 15mm;
            box-sizing: border-box;
            background: #fff;
            box-shadow: 0 1px 6px rgba(0, 0, 0, 0.25);
            display: flex;
            flex-direction: column;
            /* A sheet that fits stays exactly A4 and can never split, so the
               footer image cannot be pushed onto a second page by sub-pixel
               rounding. If content ever exceeds the sheet the box grows instead
               of clipping, so nothing is silently lost. */
            overflow: hidden;
            break-after: page;
            page-break-after: always;
        }
        .page > * { flex: 0 0 auto; }
        .page:last-child { break-after: auto; page-break-after: auto; }
        /* Screen styling is scoped to the preview container so it never bleeds
           into the surrounding application page. */
        @media screen {
            #classRecordPreview { background: #e9ecef; padding: 16mm 12px; overflow-x: auto; }
        }
        @media print {
            #classRecordPreview { background: #fff; padding: 0; overflow: visible; }
            .page { margin: 0; box-shadow: none; }
        }
        .header-image { display: block; height: 30mm; width: auto; max-width: 100%; margin: 0 auto 12px; }
        .course-info { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 11px; }
        .course-info-left { display: flex; flex-direction: column; gap: 2px; }
        .course-info-right { display: flex; flex-direction: column; gap: 2px; text-align: right; }
        .course-info strong { font-weight: bold; }
        .grade-sheet { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .grade-sheet th, .grade-sheet td { border: 1px solid #000; padding: 4px 5px; text-align: center; vertical-align: middle; }
        .grade-sheet th { background: #d9d9d9; font-weight: bold; }
        .grade-sheet th:nth-child(1) { width: 5%; }.grade-sheet th:nth-child(2) { width: 39%; }
        .grade-sheet th:nth-child(3), .grade-sheet th:nth-child(4), .grade-sheet th:nth-child(5), .grade-sheet th:nth-child(6) { width: 14%; }
        .grade-sheet td.name { text-align: left; }
        /* auto margin pins the footer to the bottom of its own sheet. */
        .footer-image { width: 100%; max-width: 100%; height: auto; margin-top: auto; padding-top: 20px; display: block; break-inside: avoid; page-break-inside: avoid; }
    ';
}

function generateGradingSheetPdf($class, $gradesData) {
    $html = gradingSheetHtml($class, $gradesData, false);
    // Add auto-print script for browser print-to-PDF
    $html = str_replace('</body>', '<script>window.onload = function() { window.print(); };</script></body>', $html);
    echo $html;
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

    $logoPath = __DIR__ . '/../assets/images/capsu.jpg';
    $footerPath = __DIR__ . '/../assets/images/footer.png';
    $logoPublicUrl = '';
    $footerPublicUrl = '';
    if (file_exists($logoPath)) {
        $logoPublicUrl = '/thesisEgrading/webapp/assets/images/capsu.jpg?v=' . filemtime($logoPath);
    }
    if (file_exists($footerPath)) {
        $footerPublicUrl = '/thesisEgrading/webapp/assets/images/footer.png?v=' . filemtime($footerPath);
    }

    $studentsPerPage = GRADING_SHEET_STUDENTS_PER_PAGE;
    $totalStudents = count($gradesData);
    $totalPages = ceil($totalStudents / $studentsPerPage);

    // Use public URL for reliable rendering in browser print-to-PDF
    $logoSrc = $logoPublicUrl;
    $footerSrc = $footerPublicUrl;

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Grade Sheet - ' . $code . '</title><style>'
        . gradingSheetPageCss()
        . '</style></head><body>';

    for ($page = 0; $page < $totalPages; $page++) {
        $html .= '<div class="page">';

        if ($logoSrc) {
            $html .= '<img class="header-image" src="' . $logoSrc . '" alt="University Header">';
        }
        $html .= '<div class="course-info">';
        $html .= '<div class="course-info-left"><span><strong>Course Number:</strong> ' . $code . '</span><span><strong>Course Title:</strong> ' . $title . '</span></div>';
        $html .= '<div class="course-info-right"><span><strong>' . $semester . ' Semester/Semester AY ' . $academicYear . '</strong></span><span><strong>Course and Year:</strong> ' . $program . ' ' . $year . '-' . $section . '</span></div>';
        $html .= '</div>';

        $html .= '<table class="grade-sheet"><thead><tr><th>No.</th><th>Name of Students<br>(Last, First, MI)</th><th>Numerical<br>Rating</th><th>Final<br>Grade</th><th>Unit<br>Credit</th><th>Remarks</th></tr></thead><tbody>';

        $startIdx = $page * $studentsPerPage;
        $endIdx = min($startIdx + $studentsPerPage, $totalStudents);
        for ($i = $startIdx; $i < $endIdx; $i++) {
            $student = $gradesData[$i];
            $name = htmlspecialchars(trim(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_initial'] ?? '') . '.'), ENT_QUOTES, 'UTF-8');
            // Overall unrounded score, so the sheet prints 87.3 rather than 87,
            // and the grade point converted from that exact value.
            $overallRaw = floatval($student['overall']['grade_raw'] ?? 0);
            $isComplete = !empty($student['overall']['complete']);
            $finalRating = $overallRaw > 0 ? gradesheet_format_score($overallRaw) : '';
            $finalGrade = !$isComplete ? 'INC'
                : ($overallRaw > 0 ? number_format(GradingHelper::getGradePoint($overallRaw), 2) : 'INC');
            $unitCredit = $isComplete ? 3 : '';
            $remarks = htmlspecialchars(gradesheet_remarks_text($student['overall']['remarks'] ?? 'INC'), ENT_QUOTES, 'UTF-8');
            $html .= '<tr><td>' . ($i + 1) . '</td><td class="name">' . $name . '</td><td>' . ($finalRating ?: 'INC') . '</td><td>' . $finalGrade . '</td><td>' . $unitCredit . '</td><td>' . $remarks . '</td></tr>';
        }
        $html .= '</tbody></table>';
        if ($footerSrc) {
            $html .= '<img class="footer-image" src="' . $footerSrc . '" alt="Footer">';
        }
        $html .= '</div>';
    }
    return $html . '</body></html>';
}

function gradingSheetExcel($class, $gradesData) {
    $code = htmlspecialchars($class['code'] ?? '', ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars($class['title'] ?? '', ENT_QUOTES, 'UTF-8');
    $program = htmlspecialchars($class['course_program'] ?? '', ENT_QUOTES, 'UTF-8');
    $year = htmlspecialchars($class['year_level'] ?? '', ENT_QUOTES, 'UTF-8');
    $section = htmlspecialchars($class['section'] ?? '', ENT_QUOTES, 'UTF-8');
    $academicYear = htmlspecialchars($class['academic_year'] ?? '', ENT_QUOTES, 'UTF-8');
    $semester = ((int)($class['semester'] ?? 1) === 2) ? '2nd' : '1st';

    $logoPath = __DIR__ . '/../assets/images/capsu.jpg';
    $footerPath = __DIR__ . '/../assets/images/footer.png';
    $logoPublicUrl = '';
    $footerPublicUrl = '';
    if (file_exists($logoPath)) {
        $logoPublicUrl = '/thesisEgrading/webapp/assets/images/capsu.jpg?v=' . filemtime($logoPath);
    }
    if (file_exists($footerPath)) {
        $footerPublicUrl = '/thesisEgrading/webapp/assets/images/footer.png?v=' . filemtime($footerPath);
    }

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Grade Sheet - ' . $code . '</title><style>
        body { font-family: Arial, sans-serif; font-size: 11px; color: #000; }
        .course-info { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 12px; }
        .course-info-left { display: flex; flex-direction: column; gap: 2px; }
        .course-info-right { display: flex; flex-direction: column; gap: 2px; text-align: right; }
        .course-info strong { font-weight: bold; }
        .grade-sheet { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .grade-sheet th, .grade-sheet td { border: 1px solid #000; padding: 6px 8px; text-align: center; vertical-align: middle; }
        .grade-sheet th { background: #d9d9d9; font-weight: bold; }
        .grade-sheet th:nth-child(1) { width: 5%; }.grade-sheet th:nth-child(2) { width: 39%; }
        .grade-sheet th:nth-child(3), .grade-sheet th:nth-child(4), .grade-sheet th:nth-child(5), .grade-sheet th:nth-child(6) { width: 14%; }
        .grade-sheet td.name { text-align: left; }
        .header-image { display: block; height: 30mm; width: auto; max-width: 100%; margin: 0 auto 12px; }
        .footer-image { width: 100%; max-width: 100%; height: auto; margin-top: 20px; display: block; }
    </style></head><body>';

    if ($logoPublicUrl) {
        $html .= '<img class="header-image" src="' . $logoPublicUrl . '" alt="University Header">';
    }
    $html .= '<div class="course-info">';
    $html .= '<div class="course-info-left"><span><strong>Course Number:</strong> ' . $code . '</span><span><strong>Course Title:</strong> ' . $title . '</span></div>';
    $html .= '<div class="course-info-right"><span><strong>' . $semester . ' Semester/Semester AY ' . $academicYear . '</strong></span><span><strong>Course and Year:</strong> ' . $program . ' ' . $year . '-' . $section . '</span></div>';
    $html .= '</div>';

    $html .= '<table class="grade-sheet"><thead><tr><th>No.</th><th>Name of Students<br>(Last, First, MI)</th><th>Numerical<br>Rating</th><th>Final<br>Grade</th><th>Unit<br>Credit</th><th>Remarks</th></tr></thead><tbody>';

    foreach ($gradesData as $i => $student) {
        $name = htmlspecialchars(trim(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_initial'] ?? '') . '.'), ENT_QUOTES, 'UTF-8');
        // Overall unrounded score, so the sheet prints 87.3 rather than 87,
        // and the grade point converted from that exact value.
        $overallRaw = floatval($student['overall']['grade_raw'] ?? 0);
        $isComplete = !empty($student['overall']['complete']);
        $finalRating = $overallRaw > 0 ? gradesheet_format_score($overallRaw) : '';
        $finalGrade = !$isComplete ? 'INC'
            : ($overallRaw > 0 ? number_format(GradingHelper::getGradePoint($overallRaw), 2) : 'INC');
        $unitCredit = $isComplete ? 3 : '';
        $remarks = htmlspecialchars(gradesheet_remarks_text($student['overall']['remarks'] ?? 'INC'), ENT_QUOTES, 'UTF-8');
        $html .= '<tr><td>' . ($i + 1) . '</td><td class="name">' . $name . '</td><td>' . ($finalRating ?: 'INC') . '</td><td>' . $finalGrade . '</td><td>' . $unitCredit . '</td><td>' . $remarks . '</td></tr>';
    }
    if ($footerPublicUrl) {
        $html .= '<img class="footer-image" src="' . $footerPublicUrl . '" alt="Footer">';
    }
    return $html . '</tbody></table></body></html>';
}

// Export functions using saved template
function exportGradingSheetCsv($templateId) {
    global $db;
    
    $stmt = $db->prepare("SELECT * FROM grading_sheet_template WHERE id = ?");
    $stmt->bind_param("i", $templateId);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();
    
    $items = $db->query("SELECT * FROM grading_sheet_items WHERE template_id = $templateId ORDER BY page_number, row_order")->fetch_all(MYSQLI_ASSOC);
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="GradingSheet_' . preg_replace('/[^a-z0-9_-]+/i', '_', $template['course_number']) . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    
    // Header rows
    fputcsv($output, ['Course Number:', $template['course_number']]);
    fputcsv($output, ['Course Title:', $template['course_title']]);
    fputcsv($output, ['Semester/AY:', $template['semester'] . ' Semester/Semester AY ' . $template['academic_year']]);
    fputcsv($output, ['Course and Year:', $template['course_year_section']]);
    fputcsv($output, []); // empty row
    
    // Table headers
    fputcsv($output, ['No.', 'Name of Students (Last, First, MI)', 'Numerical Rating', 'Final Grade', 'Unit Credit', 'Remarks']);
    
    foreach ($items as $i => $item) {
        $name = trim(($item['last_name'] ?? '') . ', ' . ($item['first_name'] ?? '') . ' ' . ($item['middle_initial'] ?? '') . '.');
        fputcsv($output, [
            $item['student_number'] ?? $i + 1,
            $name,
            $item['numerical_rating'],
            $item['final_grade'],
            $item['unit_credit'] ?? 3,
            $item['remarks']
        ]);
    }
    fclose($output);
}

function exportGradingSheetXlsx($templateId) {
    global $db;
    
    // Simple HTML-based Excel export (compatible without PhpSpreadsheet)
    $stmt = $db->prepare("SELECT * FROM grading_sheet_template WHERE id = ?");
    $stmt->bind_param("i", $templateId);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();
    
    $items = $db->query("SELECT * FROM grading_sheet_items WHERE template_id = $templateId ORDER BY page_number, row_order")->fetch_all(MYSQLI_ASSOC);
    
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="GradingSheet_' . preg_replace('/[^a-z0-9_-]+/i', '_', $template['course_number']) . '.xls"');
    
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Grade Sheet</title><style>
        body { font-family: Arial, sans-serif; font-size: 11px; color: #000; }
        .course-info { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 12px; }
        .course-info-left { display: flex; flex-direction: column; gap: 2px; }
        .course-info-right { display: flex; flex-direction: column; gap: 2px; text-align: right; }
        .course-info strong { font-weight: bold; }
        .grade-sheet { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .grade-sheet th, .grade-sheet td { border: 1px solid #000; padding: 6px 8px; text-align: center; vertical-align: middle; }
        .grade-sheet th { background: #d9d9d9; font-weight: bold; }
        .grade-sheet th:nth-child(1) { width: 5%; }.grade-sheet th:nth-child(2) { width: 39%; }
        .grade-sheet th:nth-child(3), .grade-sheet th:nth-child(4), .grade-sheet th:nth-child(5), .grade-sheet th:nth-child(6) { width: 14%; }
        .grade-sheet td.name { text-align: left; }
    </style></head><body>';
    
    $html .= '<div class="course-info">';
    $html .= '<div class="course-info-left"><span><strong>Course Number:</strong> ' . htmlspecialchars($template['course_number']) . '</span><span><strong>Course Title:</strong> ' . htmlspecialchars($template['course_title']) . '</span></div>';
    $html .= '<div class="course-info-right"><span><strong>' . htmlspecialchars($template['semester']) . ' Semester/Semester AY ' . htmlspecialchars($template['academic_year']) . '</strong></span><span><strong>Course and Year:</strong> ' . htmlspecialchars($template['course_year_section']) . '</span></div>';
    $html .= '</div>';
    
    $html .= '<table class="grade-sheet"><thead><tr><th>No.</th><th>Name of Students<br>(Last, First, MI)</th><th>Numerical<br>Rating</th><th>Final<br>Grade</th><th>Unit<br>Credit</th><th>Remarks</th></tr></thead><tbody>';
    
    foreach ($items as $item) {
        $name = htmlspecialchars(trim(($item['last_name'] ?? '') . ', ' . ($item['first_name'] ?? '') . ' ' . ($item['middle_initial'] ?? '') . '.'), ENT_QUOTES, 'UTF-8');
        $html .= '<tr><td>' . ($item['student_number'] ?? '') . '</td><td class="name">' . $name . '</td><td>' . htmlspecialchars($item['numerical_rating']) . '</td><td>' . htmlspecialchars($item['final_grade']) . '</td><td>' . ($item['unit_credit'] ?? 3) . '</td><td>' . htmlspecialchars($item['remarks']) . '</td></tr>';
    }
    echo $html . '</tbody></table></body></html>';
}

function exportGradingSheetDoc($templateId) {
    global $db;
    
    $stmt = $db->prepare("SELECT * FROM grading_sheet_template WHERE id = ?");
    $stmt->bind_param("i", $templateId);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();
    
    $items = $db->query("SELECT * FROM grading_sheet_items WHERE template_id = $templateId ORDER BY page_number, row_order")->fetch_all(MYSQLI_ASSOC);
    
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="GradingSheet_' . preg_replace('/[^a-z0-9_-]+/i', '_', $template['course_number']) . '.doc"');
    
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Grade Sheet</title><style>
        body { font-family: Arial, sans-serif; font-size: 11px; color: #000; }
        .course-info { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 12px; }
        .course-info-left { display: flex; flex-direction: column; gap: 2px; }
        .course-info-right { display: flex; flex-direction: column; gap: 2px; text-align: right; }
        .course-info strong { font-weight: bold; }
        .grade-sheet { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .grade-sheet th, .grade-sheet td { border: 1px solid #000; padding: 6px 8px; text-align: center; vertical-align: middle; }
        .grade-sheet th { background: #d9d9d9; font-weight: bold; }
        .grade-sheet th:nth-child(1) { width: 5%; }.grade-sheet th:nth-child(2) { width: 39%; }
        .grade-sheet th:nth-child(3), .grade-sheet th:nth-child(4), .grade-sheet th:nth-child(5), .grade-sheet th:nth-child(6) { width: 14%; }
        .grade-sheet td.name { text-align: left; }
    </style></head><body>';
    
    $html .= '<div class="course-info">';
    $html .= '<div class="course-info-left"><span><strong>Course Number:</strong> ' . htmlspecialchars($template['course_number']) . '</span><span><strong>Course Title:</strong> ' . htmlspecialchars($template['course_title']) . '</span></div>';
    $html .= '<div class="course-info-right"><span><strong>' . htmlspecialchars($template['semester']) . ' Semester/Semester AY ' . htmlspecialchars($template['academic_year']) . '</strong></span><span><strong>Course and Year:</strong> ' . htmlspecialchars($template['course_year_section']) . '</span></div>';
    $html .= '</div>';
    
    $html .= '<table class="grade-sheet"><thead><tr><th>No.</th><th>Name of Students<br>(Last, First, MI)</th><th>Numerical<br>Rating</th><th>Final<br>Grade</th><th>Unit<br>Credit</th><th>Remarks</th></tr></thead><tbody>';
    
    foreach ($items as $item) {
        $name = htmlspecialchars(trim(($item['last_name'] ?? '') . ', ' . ($item['first_name'] ?? '') . ' ' . ($item['middle_initial'] ?? '') . '.'), ENT_QUOTES, 'UTF-8');
        $html .= '<tr><td>' . ($item['student_number'] ?? '') . '</td><td class="name">' . $name . '</td><td>' . htmlspecialchars($item['numerical_rating']) . '</td><td>' . htmlspecialchars($item['final_grade']) . '</td><td>' . ($item['unit_credit'] ?? 3) . '</td><td>' . htmlspecialchars($item['remarks']) . '</td></tr>';
    }
    echo $html . '</tbody></table></body></html>';
}

function exportGradingSheetPdf($templateId) {
    global $db;
    
    $stmt = $db->prepare("SELECT * FROM grading_sheet_template WHERE id = ?");
    $stmt->bind_param("i", $templateId);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();
    
    $items = $db->query("SELECT * FROM grading_sheet_items WHERE template_id = $templateId ORDER BY page_number, row_order")->fetch_all(MYSQLI_ASSOC);
    
    $logoPath = __DIR__ . '/../assets/images/capsu.jpg';
    $footerPath = __DIR__ . '/../assets/images/footer.png';
    $logoPublicUrl = '';
    $footerPublicUrl = '';
    if (file_exists($logoPath)) {
        $logoPublicUrl = '/thesisEgrading/webapp/assets/images/capsu.jpg?v=' . filemtime($logoPath);
    }
    if (file_exists($footerPath)) {
        $footerPublicUrl = '/thesisEgrading/webapp/assets/images/footer.png?v=' . filemtime($footerPath);
    }
    
    $studentsPerPage = intval($template['students_per_page'] ?? 0) ?: GRADING_SHEET_STUDENTS_PER_PAGE;
    $totalItems = count($items);
    $totalPages = ceil($totalItems / $studentsPerPage);
    
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Grade Sheet - ' . htmlspecialchars($template['course_number']) . '</title><style>'
        . gradingSheetPageCss()
        . '</style></head><body>';
    
    for ($page = 0; $page < $totalPages; $page++) {
        $html .= '<div class="page">';
        
        if ($logoPublicUrl) {
            $html .= '<img class="header-image" src="' . $logoPublicUrl . '" alt="University Header">';
        }
        $html .= '<div class="course-info">';
        $html .= '<div class="course-info-left"><span><strong>Course Number:</strong> ' . htmlspecialchars($template['course_number']) . '</span><span><strong>Course Title:</strong> ' . htmlspecialchars($template['course_title']) . '</span></div>';
        $html .= '<div class="course-info-right"><span><strong>' . htmlspecialchars($template['semester']) . ' Semester/Semester AY ' . htmlspecialchars($template['academic_year']) . '</strong></span><span><strong>Course and Year:</strong> ' . htmlspecialchars($template['course_year_section']) . '</span></div>';
        $html .= '</div>';
        
        $html .= '<table class="grade-sheet"><thead><tr><th>No.</th><th>Name of Students<br>(Last, First, MI)</th><th>Numerical<br>Rating</th><th>Final<br>Grade</th><th>Unit<br>Credit</th><th>Remarks</th></tr></thead><tbody>';
        
        $startIdx = $page * $studentsPerPage;
        $endIdx = min($startIdx + $studentsPerPage, $totalItems);
        for ($i = $startIdx; $i < $endIdx; $i++) {
            $item = $items[$i];
            $name = htmlspecialchars(trim(($item['last_name'] ?? '') . ', ' . ($item['first_name'] ?? '') . ' ' . ($item['middle_initial'] ?? '') . '.'), ENT_QUOTES, 'UTF-8');
            $html .= '<tr><td>' . ($item['student_number'] ?? $i + 1) . '</td><td class="name">' . $name . '</td><td>' . htmlspecialchars($item['numerical_rating']) . '</td><td>' . htmlspecialchars($item['final_grade']) . '</td><td>' . ($item['unit_credit'] ?? 3) . '</td><td>' . htmlspecialchars($item['remarks']) . '</td></tr>';
        }
        $html .= '</tbody></table>';
        if ($footerPublicUrl) {
            $html .= '<img class="footer-image" src="' . $footerPublicUrl . '" alt="Footer">';
        }
        $html .= '</div>';
    }
    
    // Add auto-print script
    $html = str_replace('</body>', '<script>window.onload = function() { window.print(); };</script></body>', $html);
    echo $html;
}
?>

