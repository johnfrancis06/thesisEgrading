<?php
$db = Database::getInstance()->getConnection();
$faculty_id = $auth->getFacultyId();

$stats = $db->query("SELECT 
    COUNT(DISTINCT cs.id) as total_classes,
    COUNT(DISTINCT s.id) as total_students,
    COUNT(DISTINCT sub.id) as total_subjects
    FROM class_section cs
    LEFT JOIN student s ON cs.id = s.class_section_id
    LEFT JOIN subject sub ON cs.subject_id = sub.id
    WHERE cs.faculty_id = $faculty_id")->fetch_assoc();

$departments = $db->query("SELECT DISTINCT cs.course_program,
    COUNT(DISTINCT cs.id) as subject_count,
    COUNT(DISTINCT s.id) as student_count
    FROM class_section cs
    LEFT JOIN student s ON cs.id = s.class_section_id
    WHERE cs.faculty_id = $faculty_id
    GROUP BY cs.course_program
    ORDER BY cs.course_program ASC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= APP_NAME ?></title>
    <link href="assets/vendor/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css?v=3">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <button class="mobile-toggle" id="mobileToggle">
                    <i class="bi bi-list"></i>
                </button>
                <h1 class="topbar-title">Dashboard</h1>
            </div>
            <div class="topbar-right">
                <span class="topbar-user">
                    <div class="topbar-avatar"><?= strtoupper(substr($_SESSION['faculty_name'] ?? 'U', 0, 2)) ?></div>
                    <?= htmlspecialchars($_SESSION['faculty_name'] ?? 'User') ?>
                </span>
            </div>
        </div>
        
        <div class="content-area">
            <!-- Page Header -->
            <div class="page-header fade-in">
                <div class="page-header-left">
                    <div class="page-header-icon">
                        <i class="bi bi-speedometer2"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">Dashboard</h1>
                        <p class="text-muted mb-0">Welcome back, <?= htmlspecialchars($_SESSION['faculty_name'] ?? 'Faculty') ?>!</p>
                    </div>
                </div>
            </div>
            
            <!-- Stats Row -->
            <div class="row g-3 mb-4 fade-in">
                <div class="col-md-4">
                    <a href="index.php?page=classes" class="text-decoration-none">
                        <div class="card stat-card card-hover">
                            <div class="stat-icon cyan">
                                <i class="bi bi-mortarboard"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?= $stats['total_classes'] ?? 0 ?></h3>
                                <p>Classes</p>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="index.php?page=subjects" class="text-decoration-none">
                        <div class="card stat-card card-hover">
                            <div class="stat-icon blue">
                                <i class="bi bi-book"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?= $stats['total_subjects'] ?? 0 ?></h3>
                                <p>Total Subjects</p>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="index.php?page=students" class="text-decoration-none">
                        <div class="card stat-card card-hover">
                            <div class="stat-icon green">
                                <i class="bi bi-people"></i>
                            </div>
                            <div class="stat-info">
                                <h3><?= $stats['total_students'] ?? 0 ?></h3>
                                <p>Total Students Enrolled</p>
                            </div>
                        </div>
                    </a>
                </div>
            </div>
            
            <!-- Department Slideshow Carousel -->
            <?php if (!empty($departments)): ?>
            <div class="row g-3 mb-4 fade-in">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <span><i class="bi bi-building me-2"></i>Enrollment by Department</span>
                        </div>
                        <div class="card-body">
                            <div id="departmentCarousel" class="carousel slide" data-bs-ride="carousel" data-bs-interval="4000">
                                <div class="carousel-inner">
                                    <?php foreach ($departments as $index => $dept): 
                                        $active = $index === 0 ? 'active' : '';
                                        $program = htmlspecialchars($dept['course_program']);
                                        $subjectCount = $dept['subject_count'];
                                        $studentCount = $dept['student_count'];
                                    ?>
                                    <div class="carousel-item <?= $active ?>">
                                        <div class="d-flex align-items-center justify-content-center py-4">
                                            <div class="text-center">
                                                <div class="stat-icon cyan mb-3 mx-auto" style="width:64px;height:64px;font-size:2rem;">
                                                    <i class="bi bi-building"></i>
                                                </div>
                                                <h3 class="mb-1"><?= $program ?></h3>
                                                <p class="text-muted mb-2">Department / Program</p>
                                                <div class="d-flex justify-content-center gap-4 mt-3">
                                                    <div class="text-center">
                                                        <h4 class="mb-0 text-cyan"><?= $subjectCount ?></h4>
                                                        <small class="text-muted">Subjects</small>
                                                    </div>
                                                    <div class="text-center">
                                                        <h4 class="mb-0 text-green"><?= $studentCount ?></h4>
                                                        <small class="text-muted">Students Enrolled</small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (count($departments) > 1): ?>
                                <button class="carousel-control-prev" type="button" data-bs-target="#departmentCarousel" data-bs-slide="prev">
                                    <span class="carousel-control-prev-icon"></span>
                                </button>
                                <button class="carousel-control-next" type="button" data-bs-target="#departmentCarousel" data-bs-slide="next">
                                    <span class="carousel-control-next-icon"></span>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Attendance Review Table -->
            <div class="card fade-in mb-4">
                <div class="card-header">
                    <span><i class="bi bi-calendar-check me-2"></i>Recent Attendance Sessions</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="attendanceReviewTable">
                            <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>Section</th>
                                    <th>Date</th>
                                    <th>Label</th>
                                    <th>Total</th>
                                    <th>Present</th>
                                    <th>Absent</th>
                                    <th>Late</th>
                                    <th>Excused</th>
                                    <th>Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="10" class="text-center py-3">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Charts Row -->
            <div class="row g-3 mb-4 fade-in">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <span><i class="bi bi-bar-chart me-2"></i>Grade Distribution</span>
                        </div>
                        <div class="card-body">
                            <canvas id="gradeChart" height="250"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <span><i class="bi bi-graph-up me-2"></i>Class Averages</span>
                        </div>
                        <div class="card-body">
                            <canvas id="avgChart" height="250"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Attendance Chart -->
            <div class="row g-3 mb-4 fade-in">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <span><i class="bi bi-calendar-check me-2"></i>Attendance by Class</span>
                        </div>
                        <div class="card-body">
                            <canvas id="attendanceChart" height="250"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <span><i class="bi bi-calendar-event me-2"></i>Daily Attendance</span>
                        </div>
                        <div class="card-body">
                            <canvas id="dailyAttendanceChart" height="250"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            </div>
        </div>
    </div>
    
    <script src="assets/vendor/bootstrap.bundle.min.js"></script>
    <script src="assets/vendor/chart.min.js"></script>
    <script>
        document.getElementById('mobileToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        });
        
        function loadAttendanceReview() {
            fetch('api/index.php?action=get_dashboard_attendance_review')
                .then(r => r.json())
                .then(data => {
                    if (!data.success) return;
                    const rows = data.data;
                    const tbody = document.querySelector('#attendanceReviewTable tbody');
                    
                    if (rows.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="10" class="text-center py-3">No attendance sessions yet</td></tr>';
                        return;
                    }
                    
                    tbody.innerHTML = rows.map(r => {
                        const rateBadge = r.attendance_rate >= 90 ? 'bg-success' : 
                                         r.attendance_rate >= 75 ? 'bg-warning' : 'bg-danger';
                        return `
                        <tr>
                            <td><?= htmlspecialchars($r['code']) ?></td>
                            <td><?= htmlspecialchars($r['course_program']) ?> Yr<?= $r['year_level'] ?>-<?= htmlspecialchars($r['section']) ?></td>
                            <td><?= htmlspecialchars($r['date']) ?></td>
                            <td><?= htmlspecialchars($r['label'] || '-') ?></td>
                            <td><?= $r['total_students'] ?></td>
                            <td><?= $r['present_students'] ?></td>
                            <td><?= $r['total_students'] - $r['present_students'] ?></td>
                            <td>-</td>
                            <td>-</td>
                            <td><span class="badge ${rateBadge}"><?= $r['attendance_rate'] ?>%</span></td>
                        </tr>
                    `}).join('');
                });
        }
        
        loadAttendanceReview();
    </script>
    <script src="assets/js/main.js"></script>
</body>
</html>
