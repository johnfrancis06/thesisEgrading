<?php
$db = Database::getInstance()->getConnection();
$faculty_id = $auth->getFacultyId();
$classId = intval($_GET['id'] ?? 0);

$classes = [];
if ($classId <= 0) {
    $classes = $db->query("SELECT cs.*, s.code, s.title FROM class_section cs 
        JOIN subject s ON cs.subject_id = s.id WHERE cs.faculty_id = $faculty_id 
        ORDER BY cs.academic_year DESC, cs.semester DESC")->fetch_all(MYSQLI_ASSOC);
} else {
    $class = $db->query("SELECT cs.*, s.code, s.title FROM class_section cs 
        JOIN subject s ON cs.subject_id = s.id WHERE cs.id = $classId")->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $classId <= 0 ? 'Select a Class' : 'Reports - ' . htmlspecialchars($class['code']) ?> - <?= APP_NAME ?></title>
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
                <h1 class="topbar-title"><?= $classId <= 0 ? 'Reports' : 'Reports' ?></h1>
            </div>
        </div>
        
        <div class="content-area">
            <?php if ($classId <= 0): ?>
                <div class="page-header fade-in">
                    <div class="page-header-left">
                        <div class="page-header-icon">
                            <i class="bi bi-folder2-open"></i>
                        </div>
                        <div>
                            <h1 class="mb-0">Select a Class</h1>
                            <p class="text-muted mb-0">Choose a class to generate reports</p>
                        </div>
                    </div>
                </div>
                
                <?php if (empty($classes)): ?>
                    <div class="card fade-in">
                        <div class="card-body text-center py-5">
                            <div class="empty-state">
                                <i class="bi bi-inbox"></i>
                                <p>No classes found. Create a class first to generate reports.</p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($classes as $cs): ?>
                            <div class="col-md-6 col-lg-4">
                                <a href="index.php?page=reports&id=<?= $cs['id'] ?>" class="card card-hover text-decoration-none">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <span class="badge badge-primary"><?= htmlspecialchars($cs['code']) ?></span>
                                            <small class="text-muted"><?= htmlspecialchars($cs['academic_year']) ?></small>
                                        </div>
                                        <h5 class="mb-1 text-dark"><?= htmlspecialchars($cs['course_program']) ?> Yr<?= $cs['year_level'] ?> - <?= htmlspecialchars($cs['section']) ?></h5>
                                        <p class="text-muted mb-0 small"><?= htmlspecialchars($cs['title']) ?></p>
                                        <div class="mt-3 pt-3 border-top">
                                            <span class="badge badge-info"><?= $cs['semester'] == 1 ? '1st Semester' : '2nd Semester' ?></span>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="page-header fade-in">
                    <div class="page-header-left">
                        <div class="page-header-icon">
                            <i class="bi bi-file-earmark-arrow-down"></i>
                        </div>
                        <div>
                            <h1 class="mb-0"><?= htmlspecialchars($class['code']) ?></h1>
                            <p class="text-muted mb-0"><?= htmlspecialchars($class['course_program']) ?> Yr<?= $class['year_level'] ?>-<?= $class['section'] ?> - <?= $class['academic_year'] ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="row g-3 mb-4 fade-in">
                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-body text-center py-5">
                                <div class="stat-icon red mx-auto mb-3">
                                    <i class="bi bi-file-earmark-pdf"></i>
                                </div>
                                <h5 class="mb-2">Class Record</h5>
                                <p class="text-muted mb-4">Complete grading breakdown with all components</p>
                                <div class="d-flex gap-2 justify-content-center">
                                    <button class="btn btn-danger" onclick="exportClassRecord(<?= $classId ?>, 'pdf')">
                                        <i class="bi bi-download"></i> Export PDF
                                    </button>
                                    <button class="btn btn-success" onclick="exportClassRecord(<?= $classId ?>, 'xlsx')">
                                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-body text-center py-5">
                                <div class="stat-icon orange mx-auto mb-3">
                                    <i class="bi bi-file-earmark-text"></i>
                                </div>
                                <h5 class="mb-2">E-Grading</h5>
                                <p class="text-muted mb-4">Optimized for registrar submission</p>
                                <div class="d-flex gap-2 justify-content-center">
                                    <button class="btn btn-danger" onclick="exportEGrading(<?= $classId ?>, 'pdf')">
                                        <i class="bi bi-download"></i> Export PDF
                                    </button>
                                    <button class="btn btn-success" onclick="exportEGrading(<?= $classId ?>, 'xlsx')">
                                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-body text-center py-5">
                                <div class="stat-icon purple mx-auto mb-3">
                                    <i class="bi bi-calendar-check"></i>
                                </div>
                                <h5 class="mb-2">Attendance Report</h5>
                                <p class="text-muted mb-4">Student attendance summary with rates</p>
                                <div class="d-flex gap-2 justify-content-center">
                                    <button class="btn btn-primary" onclick="exportAttendance(<?= $classId ?>, 'csv')">
                                        <i class="bi bi-download"></i> Export CSV
                                    </button>
                                    <button class="btn btn-success" onclick="exportAttendance(<?= $classId ?>, 'xlsx')">
                                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card fade-in">
                    <div class="card-header">
                        <span><i class="bi bi-eye me-2"></i>Class Record Preview</span>
                    </div>
                    <div class="card-body" id="classRecordPreview">
                        <div class="empty-state">
                            <i class="bi bi-file-earmark"></i>
                            <p>Select an export format to generate preview</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="assets/vendor/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('mobileToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        });
        
        function exportClassRecord(classId, format) {
            window.open(`api/index.php?action=generate_report&class_id=${classId}&format=${format}`);
        }
        
        function exportEGrading(classId, format) {
            window.open(`api/index.php?action=export_egrading&class_id=${classId}&format=${format}`);
        }
        
        function exportAttendance(classId, format) {
            window.open(`api/index.php?action=export_attendance&class_id=${classId}&format=${format}`);
        }
    </script>
</body>
</html>
