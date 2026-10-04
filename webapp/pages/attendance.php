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
    <link rel="stylesheet" href="assets/css/style.css?v=17">
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
        <header class="topbar-modern">
            <div class="topbar-left">
                <button class="mobile-toggle" id="mobileToggle" aria-label="Toggle navigation">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title-block">
                    <h1 class="page-title"><?= $classId > 0 ? 'Attendance Sheet' : 'Select a Class' ?></h1>
                    <span class="page-subtitle"><?= $classId > 0 ? htmlspecialchars($class['course_program']) . ' Yr' . $class['year_level'] . '-' . htmlspecialchars($class['section']) . ' - ' . $class['academic_year'] : 'Choose a class to view attendance' ?></span>
                </div>
            </div>
            <div class="topbar-right">
                <nav class="topbar-nav">
                    <a href="index.php?page=dashboard" class="nav-link <?= ($_GET['page'] ?? '') === 'dashboard' ? 'active' : '' ?>">
                        <i class="bi bi-speedometer2 me-1"></i> Dashboard
                    </a>
                    <a href="index.php?page=classes" class="nav-link <?= ($_GET['page'] ?? '') === 'classes' ? 'active' : '' ?>">
                        <i class="bi bi-people me-1"></i> Classes
                    </a>
                </nav>
                <?php if ($classId > 0): ?>
                <button class="btn btn-secondary me-2" onclick="showAttendanceConfig()">
                    <i class="bi bi-gear"></i> Settings
                </button>
                <?php endif; ?>
                <div class="user-menu">
                    <div class="user-avatar"><?= strtoupper(substr($_SESSION['faculty_name'] ?? 'U', 0, 2)) ?></div>
                    <div class="user-info d-none d-md-block">
                        <span class="user-name"><?= htmlspecialchars($_SESSION['faculty_name'] ?? 'User') ?></span>
                        <span class="user-role">Faculty</span>
                    </div>
                </div>
            </div>
        </header>
        
        <main class="content-area-modern">
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
                        <button class="btn btn-sm btn-outline-danger" onclick="showAbsentChecker()">
                            <i class="bi bi-exclamation-triangle"></i> Absent Checker
                        </button>
                        <button class="btn btn-sm btn-outline-primary" onclick="showAttendanceConfig()">
                            <i class="bi bi-gear"></i> Settings
                        </button>
                        <button class="btn btn-sm btn-outline-dark" onclick="showClassRecord()" title="Open the class record form for printing">
                            <i class="bi bi-file-earmark-text"></i> Class Record
                        </button>
                    </div>
                </div>
                
                <!-- Grid plus the per-month overview panel -->
                <div class="attendance-layout">
                    <!-- Attendance Grid -->
                    <div class="sheet-grid-wrapper">
                    <table class="sheet-grid" id="attendanceGrid">
                        <thead id="attendanceGridHead">
                            <tr>
                                <th class="col-student">STUDENT NAME</th>
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
                                    <span class="legend-item unmarked">– = Unmarked</span>
                                    <span class="legend-item" style="color:#9f1239;">&#9632; = Holiday</span>
                                    <span class="legend-item" style="color:#5b21b6;">&#9632; = Seminar</span>
                                    <span class="legend-item text-muted">Mark only the absentees, then press that day's <strong>Save</strong> &mdash; everyone left unmarked is marked present.</span>
                                    <span class="legend-item text-muted">Click a date heading to mark it a holiday or seminar.</span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                    </div>

                    <!-- Month overview -->
                    <aside class="month-overview" id="monthOverview" aria-label="Month overview">
                        <div class="month-overview-head">
                            <h6 class="month-overview-title">
                                <i class="bi bi-bar-chart-line me-1"></i>Month Overview
                            </h6>
                            <button type="button" class="month-overview-toggle" id="monthOverviewToggle"
                                    aria-expanded="true" aria-controls="monthOverviewBody"
                                    title="Minimise the overview to free up space">
                                <i class="bi bi-chevron-right"></i>
                            </button>
                        </div>

                        <!-- Compact rail shown while minimised -->
                        <div class="month-overview-mini" id="monthOverviewMini" aria-hidden="true">
                            <div class="mini-stat">
                                <i class="bi bi-pie-chart"></i>
                                <span class="mini-value" id="ovMiniPercent">0%</span>
                                <span class="mini-label">Attend</span>
                            </div>
                            <div class="mini-stat">
                                <i class="bi bi-calendar-check"></i>
                                <span class="mini-value" id="ovMiniMeets">0</span>
                                <span class="mini-label">Meets</span>
                            </div>
                            <div class="mini-stat">
                                <i class="bi bi-hand-thumbs-up"></i>
                                <span class="mini-value" id="ovMiniAttended">0</span>
                                <span class="mini-label">Attended</span>
                            </div>
                            <div class="mini-stat">
                                <i class="bi bi-check2-square"></i>
                                <span class="mini-value" id="ovMiniSaved">0</span>
                                <span class="mini-label">Saved</span>
                            </div>
                        </div>

                        <div id="monthOverviewBody">
                        <div class="month-overview-stat">
                            <span class="stat-label">Total Meets</span>
                            <span class="stat-value" id="ovTotalMeets">0</span>
                        </div>

                        <div class="month-overview-stat">
                            <span class="stat-label">Days Attended</span>
                            <span class="stat-value" id="ovDaysRecorded">0</span>
                        </div>

                        <div class="month-overview-stat">
                            <span class="stat-label">Attendance %</span>
                            <span class="stat-value" id="ovPercent">0%</span>
                        </div>

                        <div class="month-overview-bar" role="img" aria-label="Attendance breakdown">
                            <span class="seg present" id="ovSegPresent"></span>
                            <span class="seg late" id="ovSegLate"></span>
                            <span class="seg absent" id="ovSegAbsent"></span>
                            <span class="seg excused" id="ovSegExcused"></span>
                        </div>

                        <ul class="month-overview-breakdown">
                            <li><span class="key present"></span> Present <strong id="ovCountPresent">0</strong></li>
                            <li><span class="key late"></span> Late <strong id="ovCountLate">0</strong></li>
                            <li><span class="key absent"></span> Absent <strong id="ovCountAbsent">0</strong></li>
                            <li><span class="key excused"></span> Excused <strong id="ovCountExcused">0</strong></li>
                            <li><span class="key unmarked"></span> Unmarked <strong id="ovCountUnmarked">0</strong></li>
                        </ul>

                        <div class="month-overview-stat is-secondary">
                            <span class="stat-label">Days Saved</span>
                            <span class="stat-value" id="ovDaysSaved">0</span>
                        </div>
                        <p class="month-overview-hint">Total Meets counts the days already marked. Attendance % and each student's figure use those same days.</p>
                        </div>
                    </aside>
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
        </main>
    </div>
    
    <div id="configModalWrapper"></div>
    <div id="statusMenu" class="status-menu"></div>
    
    <script src="assets/vendor/bootstrap.bundle.min.js"></script>
    <script src="assets/js/attendance.js?v=11"></script>
    <script>
        document.getElementById('mobileToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        });
        <?php if ($classId > 0): ?>
        document.getElementById('monthOverviewToggle')
            ?.addEventListener('click', function() { toggleMonthOverview(); });
        restoreMonthOverviewState();
        loadAttendanceGrid(<?= $classId ?>, <?= date('Y') ?>, <?= date('n') ?>);
        <?php endif; ?>
    </script>
</body>
</html>
