<?php
$file = __DIR__ . '/api/index.php';
$content = file_get_contents($file);

if ($content === false) {
    die("Failed to read file\n");
}

$normalized = str_replace("\r\n", "\n", $content);

// Replacement 1: get_sections
$old1 = <<<'PHP'
    elseif ($action === 'get_sections') {
        $program = $_GET['program'] ?? '';
        $year = $_GET['year'] ?? '';
        $section = $_GET['section'] ?? '';
        $ay = $_GET['ay'] ?? '';
        
        $query = "SELECT DISTINCT course_program, year_level, section, academic_year, 
                  COUNT(*) as count FROM section_student WHERE 1=1";
        $params = [];
        $types = '';
        
        if ($program) { $query .= " AND course_program = ?"; $params[] = $program; $types .= 's'; }
        if ($year) { $query .= " AND year_level = ?"; $params[] = $year; $types .= 'i'; }
        if ($section) { $query .= " AND section = ?"; $params[] = $section; $types .= 's'; }
        if ($ay) { $query .= " AND academic_year = ?"; $params[] = $ay; $types .= 's'; }
        
        $query .= " GROUP BY course_program, year_level, section, academic_year ORDER BY academic_year DESC";
        
        if ($params) {
            $stmt = $db->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } else {
            $result = $db->query($query)->fetch_all(MYSQLI_ASSOC);
        }
        echo ResponseAPI::success($result);
    }
PHP;

$new1 = <<<'PHP'
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
PHP;

// Replacement 2: get_section_students
$old2 = <<<'PHP'
    elseif ($action === 'get_section_students') {
        $program = $_GET['program'] ?? '';
        $year = intval($_GET['year'] ?? 0);
        $section = $_GET['section'] ?? '';
        $ay = $_GET['ay'] ?? '';
        
        $query = "SELECT * FROM section_student WHERE 1=1";
        $params = [];
        $types = '';
        
        if ($program) { $query .= " AND course_program = ?"; $params[] = $program; $types .= 's'; }
        if ($year) { $query .= " AND year_level = ?"; $params[] = $year; $types .= 'i'; }
        if ($section) { $query .= " AND section = ?"; $params[] = $section; $types .= 's'; }
        if ($ay) { $query .= " AND academic_year = ?"; $params[] = $ay; $types .= 's'; }
        
        $query .= " ORDER BY last_name";
        
        if ($params) {
            $stmt = $db->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } else {
            $result = $db->query($query)->fetch_all(MYSQLI_ASSOC);
        }
        echo ResponseAPI::success($result);
    }
PHP;

$new2 = <<<'PHP'
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
PHP;

// Replacement 3: add_section_student
$old3 = <<<'PHP'
    elseif ($action === 'add_section_student') {
        $data = json_decode(file_get_contents("php://input"), true);
        $stmt = $db->prepare("INSERT INTO section_student 
            (course_program, year_level, section, academic_year, student_no, last_name, first_name, middle_initial) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sissssss", 
            $data['course_program'], $data['year_level'], $data['section'], $data['academic_year'],
            $data['student_no'], $data['last_name'], $data['first_name'], $data['middle_initial']);
        echo $stmt->execute() ? ResponseAPI::success(['id' => $db->insert_id], "Student added", 201) 
            : ResponseAPI::error("Failed to add student: " . $db->error);
    }
PHP;

$new3 = <<<'PHP'
    elseif ($action === 'add_section_student') {
        $data = json_decode(file_get_contents("php://input"), true);
        
        $program = $db->real_escape_string($data['course_program']);
        $year = intval($data['year_level']);
        $section = $db->real_escape_string($data['section']);
        $ay = $db->real_escape_string($data['academic_year']);
        
        // Find matching classes for this section
        $classes = $db->query("SELECT id FROM class_section 
            WHERE course_program = '$program' AND year_level = $year AND section = '$section' AND academic_year = '$ay'")->fetch_all(MYSQLI_ASSOC);
        
        if (empty($classes)) {
            echo ResponseAPI::error("No matching classes found for this section. Please create a class first.");
            exit;
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
PHP;

$replaced = 0;
foreach (['old1' => $new1, 'old2' => $new2, 'old3' => $new3] as $oldVar => $newVar) {
    $old = ${$oldVar};
    $oldNorm = str_replace("\r\n", "\n", $old);
    if (strpos($normalized, $oldNorm) !== false) {
        $normalized = str_replace($oldNorm, $newVar, $normalized);
        $replaced++;
        echo "Replaced $oldVar\n";
    } else {
        echo "NOT FOUND: $oldVar\n";
    }
}

if ($replaced > 0) {
    file_put_contents($file, str_replace("\n", "\r\n", $normalized));
    echo "Successfully replaced $replaced endpoint(s)\n";
} else {
    echo "No replacements made - endpoints not found\n";
}
?>
