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

// Get recent classes for quick access
$recentClasses = $db->query("SELECT cs.*, s.code, s.title FROM class_section cs 
    JOIN subject s ON cs.subject_id = s.id 
    WHERE cs.faculty_id = $faculty_id 
    ORDER BY cs.created_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

// Get grade distribution for quick stats
$gradeDist = $db->query("SELECT 
    COUNT(CASE WHEN gs.raw_score >= 90 THEN 1 END) as gradeA,
    COUNT(CASE WHEN gs.raw_score >= 80 AND gs.raw_score < 90 THEN 1 END) as gradeB,
    COUNT(CASE WHEN gs.raw_score >= 70 AND gs.raw_score < 80 THEN 1 END) as gradeC,
    COUNT(CASE WHEN gs.raw_score >= 60 AND gs.raw_score < 70 THEN 1 END) as gradeD,
    COUNT(CASE WHEN gs.raw_score < 60 THEN 1 END) as gradeF
    FROM grade_score gs
    JOIN grade_item gi ON gs.grade_item_id = gi.id
    JOIN grade_category gc ON gi.grade_category_id = gc.id
    JOIN class_section cs ON gc.class_section_id = cs.id
    WHERE cs.faculty_id = $faculty_id")->fetch_assoc();

// Get attendance overview
$attOverview = $db->query("SELECT 
    ROUND((COUNT(CASE WHEN ar.status = 'present' THEN 1 END) / COUNT(*) * 100), 1) as overall_attendance
    FROM attendance_record ar
    JOIN attendance_session ase ON ar.attendance_session_id = ase.id
    JOIN class_section cs ON ase.class_section_id = cs.id
    WHERE cs.faculty_id = $faculty_id")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= APP_NAME ?></title>
    <link href="assets/vendor/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css?v=6">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="main-content">
        <!-- Modern Topbar -->
        <header class="topbar-modern">
            <div class="topbar-left">
                <button class="mobile-toggle" id="mobileToggle" aria-label="Toggle navigation">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title-block">
                    <h1 class="page-title">Dashboard</h1>
                    <span class="page-subtitle">Welcome back, <?= htmlspecialchars($_SESSION['faculty_name'] ?? 'Faculty') ?></span>
                </div>
            </div>
            <div class="topbar-right">
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
            <section class="dashboard-brief" aria-label="Dashboard overview">
                <div>
                    <span class="dashboard-kicker"><i class="bi bi-grid-1x2-fill"></i> Faculty workspace</span>
                    <h2>Keep your classes moving.</h2>
                    <p>Review teaching activity, update grades, and stay ahead of attendance in one place.</p>
                </div>
                <div class="dashboard-brief-meta">
                    <span class="brief-date"><i class="bi bi-calendar3"></i> <?= date('F j, Y') ?></span>
                    <a href="index.php?page=classes" class="btn brief-action"><i class="bi bi-arrow-right"></i> Open classes</a>
                </div>
            </section>

            <!-- Stats Grid -->
            <section class="stats-grid" aria-label="Key Statistics">
                <article class="stat-card stat-primary" onclick="window.location.href='index.php?page=classes'">
                    <div class="stat-icon-wrapper">
                        <i class="bi bi-mortarboard"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= $stats['total_classes'] ?? 0 ?></div>
                        <div class="stat-label">Classes</div>
                        <div class="stat-trend"><i class="bi bi-arrow-up-right"></i> Active</div>
                    </div>
                </article>
                
                <article class="stat-card stat-success" onclick="window.location.href='index.php?page=subjects'">
                    <div class="stat-icon-wrapper">
                        <i class="bi bi-book"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= $stats['total_subjects'] ?? 0 ?></div>
                        <div class="stat-label">Subjects</div>
                        <div class="stat-trend"><i class="bi bi-bookmark"></i> Teaching</div>
                    </div>
                </article>
                
                <article class="stat-card stat-info" onclick="window.location.href='index.php?page=students'">
                    <div class="stat-icon-wrapper">
                        <i class="bi bi-people"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= $stats['total_students'] ?? 0 ?></div>
                        <div class="stat-label">Students</div>
                        <div class="stat-trend"><i class="bi bi-person-plus"></i> Enrolled</div>
                    </div>
                </article>
                
                <article class="stat-card stat-warning">
                    <div class="stat-icon-wrapper">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= $gradeDist ? ($gradeDist['gradeA'] + $gradeDist['gradeB'] + $gradeDist['gradeC'] + $gradeDist['gradeD'] + $gradeDist['gradeF']) : 0 ?></div>
                        <div class="stat-label">Grades Recorded</div>
                        <div class="stat-trend"><i class="bi bi-pencil-square"></i> Grading</div>
                    </div>
                </article>
                
                <article class="stat-card stat-danger">
                    <div class="stat-icon-wrapper">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= $attOverview['overall_attendance'] ?? 0 ?>%</div>
                        <div class="stat-label">Attendance Rate</div>
                        <div class="stat-trend"><i class="bi bi-clock"></i> Overall</div>
                    </div>
                </article>
                
                <article class="stat-card stat-purple">
                    <div class="stat-icon-wrapper">
                        <i class="bi bi-award"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= count($departments) ?></div>
                        <div class="stat-label">Departments</div>
                        <div class="stat-trend"><i class="bi bi-building"></i> Programs</div>
                    </div>
                </article>
            </section>
            
            <!-- Quick Actions & Recent Classes -->
            <div class="dashboard-grid">
                <section class="dashboard-card quick-actions-card">
                    <header class="card-header-modern">
                        <h2><i class="bi bi-lightning-charge"></i> Quick Actions</h2>
                    </header>
                    <div class="card-body-modern">
                        <div class="action-grid">
                            <a href="index.php?page=grading" class="action-btn action-primary">
                                <i class="bi bi-pencil-square"></i>
                                <span>Grade Students</span>
                            </a>
                            <a href="index.php?page=attendance" class="action-btn action-success">
                                <i class="bi bi-calendar-check"></i>
                                <span>Take Attendance</span>
                            </a>
                            <a href="index.php?page=classes" class="action-btn action-info">
                                <i class="bi bi-plus-circle"></i>
                                <span>New Class</span>
                            </a>
                            <a href="index.php?page=reports" class="action-btn action-warning">
                                <i class="bi bi-file-earmark-arrow-down"></i>
                                <span>Generate Reports</span>
                            </a>
                            <a href="index.php?page=section-enrollment" class="action-btn action-secondary">
                                <i class="bi bi-person-plus"></i>
                                <span>Enroll Students</span>
                            </a>
                            <a href="index.php?page=subjects" class="action-btn action-purple">
                                <i class="bi bi-book"></i>
                                <span>Manage Subjects</span>
                            </a>
                        </div>
                    </div>
                </section>
                
                <section class="dashboard-card recent-classes-card">
                    <header class="card-header-modern d-flex justify-content-between align-items-center">
                        <h2><i class="bi bi-clock-history"></i> Recent Classes</h2>
                        <a href="index.php?page=classes" class="btn btn-sm btn-ghost">View All</a>
                    </header>
                    <div class="card-body-modern p-0">
                        <?php if (!empty($recentClasses)): ?>
                        <div class="table-responsive">
                            <table class="table table-modern">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Section</th>
                                        <th>Semester</th>
                                        <th>AY</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentClasses as $cls): ?>
                                    <tr>
                                        <td>
                                            <div class="class-info">
                                                <span class="class-code"><?= htmlspecialchars($cls['code']) ?></span>
                                                <span class="class-title"><?= htmlspecialchars($cls['title']) ?></span>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($cls['course_program']) ?> Yr<?= $cls['year_level'] ?>-<?= htmlspecialchars($cls['section']) ?></td>
                                        <td><span class="badge bg-light text-dark"><?= $cls['semester'] == 1 ? '1st' : '2nd' ?> Sem</span></td>
                                        <td><?= htmlspecialchars($cls['academic_year']) ?></td>
                                        <td>
                                            <div class="action-btns">
                                                <a href="index.php?page=grading&id=<?= $cls['id'] ?>" class="btn-icon btn-primary" title="Grade"><i class="bi bi-pencil-square"></i></a>
                                                <a href="index.php?page=attendance&id=<?= $cls['id'] ?>" class="btn-icon btn-success" title="Attendance"><i class="bi bi-calendar-check"></i></a>
                                                <a href="index.php?page=reports&id=<?= $cls['id'] ?>" class="btn-icon btn-info" title="Reports"><i class="bi bi-file-earmark-arrow-down"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="empty-state-modern">
                            <i class="bi bi-folder2-open"></i>
                            <p>No classes yet</p>
                            <a href="index.php?page=classes" class="btn btn-primary mt-2">Create Your First Class</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
            
            <!-- Charts Row -->
            <section class="charts-grid">
                <article class="dashboard-card chart-card">
                    <header class="card-header-modern">
                        <h2><i class="bi bi-pie-chart"></i> Grade Distribution</h2>
                    </header>
                    <div class="card-body-modern">
                        <div class="chart-wrapper" style="height: 280px; position: relative;">
                            <canvas id="gradeChart"></canvas>
                        </div>
                    </div>
                </article>
                
                <article class="dashboard-card chart-card">
                    <header class="card-header-modern">
                        <h2><i class="bi bi-bar-chart"></i> Class Averages</h2>
                    </header>
                    <div class="card-body-modern">
                        <div class="chart-wrapper" style="height: 280px; position: relative;">
                            <canvas id="avgChart"></canvas>
                        </div>
                    </div>
                </article>
            </section>
            
            <section class="charts-grid">
                <article class="dashboard-card chart-card">
                    <header class="card-header-modern">
                        <h2><i class="bi bi-graph-up"></i> Attendance by Class</h2>
                    </header>
                    <div class="card-body-modern">
                        <div class="chart-wrapper" style="height: 280px; position: relative;">
                            <canvas id="attendanceChart"></canvas>
                        </div>
                    </div>
                </article>
                
                <article class="dashboard-card chart-card">
                    <header class="card-header-modern">
                        <h2><i class="bi bi-activity"></i> Daily Attendance Trend</h2>
                    </header>
                    <div class="card-body-modern">
                        <div class="chart-wrapper" style="height: 280px; position: relative;">
                            <canvas id="dailyAttendanceChart"></canvas>
                        </div>
                    </div>
                </article>
            </section>
            
            <!-- Department Overview -->
            <?php if (!empty($departments)): ?>
            <section class="dashboard-card departments-card">
                <header class="card-header-modern">
                    <h2><i class="bi bi-building"></i> Enrollment by Department</h2>
                </header>
                <div class="card-body-modern">
                    <div class="departments-grid">
                        <?php foreach ($departments as $dept): ?>
                        <article class="dept-card">
                            <div class="dept-icon">
                                <i class="bi bi-building"></i>
                            </div>
                            <div class="dept-info">
                                <h3><?= htmlspecialchars($dept['course_program']) ?></h3>
                                <p class="text-muted">Department / Program</p>
                            </div>
                            <div class="dept-stats">
                                <div class="stat-item">
                                    <span class="stat-num text-primary"><?= $dept['subject_count'] ?></span>
                                    <span class="stat-label">Subjects</span>
                                </div>
                                <div class="stat-divider"></div>
                                <div class="stat-item">
                                    <span class="stat-num text-success"><?= $dept['student_count'] ?></span>
                                    <span class="stat-label">Students</span>
                                </div>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>
        </main>
    </div>
    
    <script src="assets/vendor/bootstrap.bundle.min.js"></script>
    <script src="assets/vendor/chart.umd.min.js"></script>
    <script>
        // Ensure Chart is available globally
        if (typeof Chart !== 'undefined' && Chart.defaults) {
            console.log('Chart.js loaded:', Chart.version);
        } else if (typeof Chart === 'undefined') {
            console.error('Chart.js not loaded');
        }
        
        document.getElementById('mobileToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        });
        
        // Load attendance review table (keep existing for now)
        async function loadAttendanceReview() {
            const table = document.getElementById('attendanceReviewTable');
            if (!table) return; // Table removed from new design
            
            const resp = await fetch('api/index.php?action=get_dashboard_attendance_review');
            const data = await resp.json();
            
            if (!data.success) return;
            const rows = data.data;
            const tbody = table.querySelector('tbody');
            
            if (!tbody || rows.length === 0) return;
        }
        
        // Chart loading functions (same as before)
        async function loadGradeChart() {
            const resp = await fetch('api/index.php?action=get_dashboard_grades');
            const data = await resp.json();
            if (!data.success || !data.data) return;
            
            const d = data.data;
            const ctx = document.getElementById('gradeChart');
            if (!ctx) return;
            
            if (window.gradeChartInstance) window.gradeChartInstance.destroy();
            window.gradeChartInstance = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['A (90-100)', 'B (80-89)', 'C (70-79)', 'D (60-69)', 'F (<60)'],
                    datasets: [{
                        data: [d.gradeA || 0, d.gradeB || 0, d.gradeC || 0, d.gradeD || 0, d.gradeF || 0],
                        backgroundColor: ['#10b981', '#0ea5e9', '#f59e0b', '#ef4444', '#94a3b8'],
                        borderWidth: 0,
                        borderRadius: 8,
                        spacing: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '65%',
                    plugins: { 
                        legend: { 
                            position: 'bottom', 
                            labels: { 
                                padding: 20, 
                                font: { size: 12, family: "'Inter', sans-serif" },
                                usePointStyle: true,
                                pointStyle: 'circle'
                            } 
                        } 
                    }
                }
            });
        }
        
        async function loadAvgChart() {
            const resp = await fetch('api/index.php?action=get_dashboard_averages');
            const data = await resp.json();
            if (!data.success || !data.data) return;
            
            const labels = data.data.map(d => `${d.code} ${d.course_program} ${d.year_level}-${d.section}`);
            const values = data.data.map(d => parseFloat(d.average || 0));
            const ctx = document.getElementById('avgChart');
            if (!ctx) return;
            
            if (window.avgChartInstance) window.avgChartInstance.destroy();
            window.avgChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Average Score',
                        data: values,
                        backgroundColor: 'rgba(14, 165, 233, 0.8)',
                        borderColor: '#0ea5e9',
                        borderWidth: 0,
                        borderRadius: 6,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    indexAxis: 'y',
                    plugins: { legend: { display: false } },
                    scales: { 
                        x: { 
                            beginAtZero: true, 
                            max: 100,
                            grid: { color: 'rgba(0,0,0,0.05)' }
                        },
                        y: { grid: { display: false } }
                    }
                }
            });
        }
        
        async function loadAttendanceChart() {
            const resp = await fetch('api/index.php?action=get_dashboard_attendance');
            const data = await resp.json();
            if (!data.success || !data.data) return;
            
            const labels = data.data.map(d => `${d.code} ${d.course_program} ${d.year_level}-${d.section}`);
            const values = data.data.map(d => parseFloat(d.attendance_rate || 0));
            const ctx = document.getElementById('attendanceChart');
            if (!ctx) return;
            
            if (window.attendanceChartInstance) window.attendanceChartInstance.destroy();
            window.attendanceChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Attendance Rate %',
                        data: values,
                        backgroundColor: 'rgba(16, 185, 129, 0.8)',
                        borderColor: '#10b981',
                        borderWidth: 0,
                        borderRadius: 6,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    indexAxis: 'y',
                    plugins: { legend: { display: false } },
                    scales: { 
                        x: { 
                            beginAtZero: true, 
                            max: 100,
                            grid: { color: 'rgba(0,0,0,0.05)' }
                        },
                        y: { grid: { display: false } }
                    }
                }
            });
        }
        
        async function loadDailyAttendanceChart() {
            const resp = await fetch('api/index.php?action=get_dashboard_attendance_daily');
            const data = await resp.json();
            if (!data.success || !data.data) return;
            
            const grouped = {};
            data.data.forEach(d => {
                if (!grouped[d.attendance_date]) grouped[d.attendance_date] = 0;
                grouped[d.attendance_date] += d.students_present || 0;
            });
            
            const labels = Object.keys(grouped).sort();
            const values = labels.map(d => grouped[d]);
            const ctx = document.getElementById('dailyAttendanceChart');
            if (!ctx) return;
            
            if (window.dailyChartInstance) window.dailyChartInstance.destroy();
            window.dailyChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Students Present',
                        data: values,
                        borderColor: '#0ea5e9',
                        backgroundColor: 'rgba(14, 165, 233, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        pointBackgroundColor: '#0ea5e9',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: { legend: { display: false } },
                    scales: { 
                        y: { 
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.05)' }
                        },
                        x: { grid: { display: false } }
                    },
                    interaction: { intersect: false, mode: 'index' }
                }
            });
        }
        
        async function initDashboard() {
            await loadAttendanceReview();
            await loadGradeChart();
            await loadAvgChart();
            await loadAttendanceChart();
            await loadDailyAttendanceChart();
        }
        
        initDashboard();
    </script>
    <script src="assets/js/main.js"></script>
</body>
</html>