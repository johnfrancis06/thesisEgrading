<?php
$db = Database::getInstance()->getConnection();
$faculty_id = $auth->getFacultyId();
$classId = intval($_GET['id'] ?? 0);

if ($classId > 0) {
    $class = $db->query("SELECT cs.*, s.code, s.title FROM class_section cs 
        JOIN subject s ON cs.subject_id = s.id WHERE cs.id = $classId AND cs.faculty_id = $faculty_id")->fetch_assoc();

    if (!$class) {
        die("Class not found");
    }
} else {
    $classes = $db->query("SELECT cs.*, s.code, s.title FROM class_section cs 
        JOIN subject s ON cs.subject_id = s.id WHERE cs.faculty_id = $faculty_id ORDER BY cs.academic_year DESC, cs.semester DESC")->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $classId > 0 ? 'Monthly Attendance Sheet - ' . APP_NAME : 'Select a Class - ' . APP_NAME ?></title>
    <link href="assets/vendor/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css?v=8">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .sheet-title {
            font-family: 'Playfair Display', Georgia, serif;
            font-variant: small-caps;
            letter-spacing: 0.08em;
        }
        .sheet-body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>
<body class="sheet-body">
    <?php include 'includes/header.php'; ?>
    
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <button class="mobile-toggle" id="mobileToggle">
                    <i class="bi bi-list"></i>
                </button>
                <h1 class="topbar-title"><?= $classId > 0 ? 'Attendance Sheet' : 'Select a Class' ?></h1>
            </div>
            <div class="topbar-right">
                <?php if ($classId > 0): ?>
                <button class="btn btn-secondary me-2" onclick="showAttendanceConfig()">
                    <i class="bi bi-gear"></i> Settings
                </button>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="content-area">
            <?php if ($classId > 0): ?>
            <div class="attendance-sheet fade-in">
                <!-- Header -->
                <div class="sheet-header">
                    <div class="sheet-logo">
                        <span class="logo-badge">Template <span class="logo-hub">HUB</span></span>
                    </div>
                    <div class="sheet-title-block">
                        <h1 class="sheet-title">Monthly Attendance Sheet for Students</h1>
                        <p class="sheet-school-info"><em>School Name – School Address – Contact No – Email</em></p>
                    </div>
                    <div class="sheet-spacer"></div>
                </div>
                
                <!-- Info Bar -->
                <div class="sheet-info-bar">
                    <div class="info-left">
                        <div class="info-item">NUMBER OF STUDENTS PRESENT: <strong id="totalPresent">0</strong></div>
                        <div class="info-item">NUMBER OF STUDENTS ABSENT: <strong id="totalAbsent">0</strong></div>
                    </div>
                    <div class="info-divider"></div>
                    <div class="info-right">
                        <div class="info-item">Month/Year: <strong id="infoMonthYear"><?= date('F Y') ?></strong></div>
                        <div class="info-item">Class/Grade & Section: <strong><?= htmlspecialchars($class['course_program']) ?> Yr<?= $class['year_level'] ?>-<?= htmlspecialchars($class['section']) ?></strong></div>
                        <div class="info-item">Teacher: <strong><?= htmlspecialchars($_SESSION['faculty_name'] ?? '') ?></strong></div>
                    </div>
                </div>
                
                <!-- Controls -->
                <div class="sheet-controls">
                    <div class="controls-left">
                        <select class="form-select form-select-sm" id="yearFilter" onchange="changeAttendanceMonth()">
                            <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                                <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                        <select class="form-select form-select-sm" id="monthFilter" onchange="changeAttendanceMonth()">
                            <?php
                            $months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                            foreach ($months as $idx => $m): 
                                $val = $idx + 1;
                            ?>
                                <option value="<?= $val ?>" <?= $val == date('n') ? 'selected' : '' ?>><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="controls-right">
                        <button class="btn btn-sm btn-outline-primary" onclick="showAttendanceConfig()">
                            <i class="bi bi-gear"></i> Settings
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                            <i class="bi bi-printer"></i> Print
                        </button>
                    </div>
                </div>
                
                <!-- Attendance Grid -->
                <div class="sheet-grid-wrapper">
                    <table class="sheet-grid" id="attendanceGrid">
                        <thead id="attendanceGridHead">
                            <tr>
                                <th class="col-student">STUDENT NAME</th>
                                <th class="col-roll">ROLL NO.</th>
                            </tr>
                        </thead>
                        <tbody id="attendanceGridBody">
                            <tr><td colspan="32" class="text-center py-3">Loading...</td></tr>
                        </tbody>
                        <tfoot id="attendanceGridFoot">
                            <tr class="legend-row">
                                <td colspan="32">
                                    <strong>Legend:</strong> 
                                    <span class="legend-item present">P = Present</span>
                                    <span class="legend-item absent">A = Absent</span>
                                    <span class="legend-item late">L = Late</span>
                                    <span class="legend-item excused">E = Excused</span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <div class="page-header fade-in">
                <div class="page-header-left">
                    <div class="page-header-icon">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">Select a Class</h1>
                        <p class="text-muted mb-0">Choose a class to generate monthly attendance sheet</p>
                    </div>
                </div>
            </div>
            
            <div class="row g-3 fade-in">
                <?php foreach ($classes as $cls): ?>
                <div class="col-md-6 col-lg-4">
                    <a href="index.php?page=attendance&id=<?= $cls['id'] ?>" class="card card-hover text-decoration-none h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="card-title mb-0 text-dark"><?= htmlspecialchars($cls['code']) ?></h5>
                                <span class="badge badge-primary"><?= htmlspecialchars($cls['academic_year']) ?></span>
                            </div>
                            <p class="text-muted small mb-2"><?= htmlspecialchars($cls['course_program']) ?> Yr<?= $cls['year_level'] ?>-<?= htmlspecialchars($cls['section']) ?></p>
                            <div class="d-flex gap-2">
                                <span class="badge badge-info"><?= $cls['semester'] == 1 ? '1st Semester' : '2nd Semester' ?></span>
                            </div>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div id="configModalWrapper"></div>
    <div id="statusMenu" class="status-menu"></div>
    
    <script src="assets/vendor/bootstrap.bundle.min.js"></script>
    <script src="assets/js/attendance.js"></script>
    <script>
        document.getElementById('mobileToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        });
        <?php if ($classId > 0): ?>
        loadAttendanceGrid(<?= $classId ?>, <?= date('Y') ?>, <?= date('n') ?>);
        <?php endif; ?>
    </script>
</body>
</html>
