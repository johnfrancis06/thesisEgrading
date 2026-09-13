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
    <link rel="stylesheet" href="assets/css/style.css?v=4">
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
                
                <!-- GE-104 Report Types -->
                <div class="row g-3 mb-4 fade-in">
                    <!-- Class Record - Detailed breakdown with all components -->
                    <div class="col-md-4">
                        <div class="card h-100 border-primary">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="bi bi-file-earmark-pdf me-2"></i>Class Record</h5>
                            </div>
                            <div class="card-body text-center py-5">
                                <div class="stat-icon red mx-auto mb-3">
                                    <i class="bi bi-table"></i>
                                </div>
                                <h5 class="mb-2">Class Record (DepEd/CHED)</h5>
                                <p class="text-muted mb-4">Complete grading breakdown with all 4 components per period (CP, PS, Quiz, Exam)</p>
                                <div class="d-flex flex-column gap-2">
                                    <button class="btn btn-danger" onclick="exportReport('class_record', <?= $classId ?>, 'pdf')">
                                        <i class="bi bi-file-earmark-pdf me-1"></i> Export PDF
                                    </button>
                                    <button class="btn btn-success" onclick="exportReport('class_record', <?= $classId ?>, 'xlsx')">
                                        <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- E-Grading - For registrar submission -->
                    <div class="col-md-4">
                        <div class="card h-100 border-warning">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>E-Grading</h5>
                            </div>
                            <div class="card-body text-center py-5">
                                <div class="stat-icon orange mx-auto mb-3">
                                    <i class="bi bi-send"></i>
                                </div>
                                <h5 class="mb-2">E-Grading Submission</h5>
                                <p class="text-muted mb-4">Optimized for registrar portal submission (Name, Midterm, Final, Grade Point)</p>
                                <div class="d-flex flex-column gap-2">
                                    <button class="btn btn-danger" onclick="exportReport('egrading', <?= $classId ?>, 'pdf')">
                                        <i class="bi bi-file-earmark-pdf me-1"></i> Export PDF
                                    </button>
                                    <button class="btn btn-success" onclick="exportReport('egrading', <?= $classId ?>, 'xlsx')">
                                        <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Grading Sheet - Summary roster -->
                    <div class="col-md-4">
                        <div class="card h-100 border-info">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>Grading Sheet</h5>
                            </div>
                            <div class="card-body text-center py-5">
                                <div class="stat-icon purple mx-auto mb-3">
                                    <i class="bi bi-journal-check"></i>
                                </div>
                                <h5 class="mb-2">Grading Sheet</h5>
                                <p class="text-muted mb-4">Condensed roster: Midterm/Final ratings, Grade Point, Unit Credit</p>
                                <div class="d-flex flex-column gap-2">
                                    <button class="btn btn-danger" onclick="exportReport('grading_sheet', <?= $classId ?>, 'pdf')">
                                        <i class="bi bi-file-earmark-pdf me-1"></i> Export PDF
                                    </button>
                                    <button class="btn btn-success" onclick="exportReport('grading_sheet', <?= $classId ?>, 'xlsx')">
                                        <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Attendance Report -->
                <div class="row g-3 mb-4 fade-in">
                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-header bg-secondary text-white">
                                <h5 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Attendance Report</h5>
                            </div>
                            <div class="card-body text-center py-5">
                                <div class="stat-icon teal mx-auto mb-3">
                                    <i class="bi bi-calendar-event"></i>
                                </div>
                                <h5 class="mb-2">Attendance Summary</h5>
                                <p class="text-muted mb-4">Student attendance with daily breakdown and rates</p>
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
                
                <!-- Preview Area -->
                <div class="card fade-in">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-eye me-2"></i>Class Record Preview</span>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" onclick="previewReport('class_record', <?= $classId ?>)">
                                <i class="bi bi-table me-1"></i> Class Record
                            </button>
                            <button class="btn btn-outline-secondary" onclick="previewReport('egrading', <?= $classId ?>)">
                                <i class="bi bi-send me-1"></i> E-Grading
                            </button>
                            <button class="btn btn-outline-secondary" onclick="previewReport('grading_sheet', <?= $classId ?>)">
                                <i class="bi bi-journal-text me-1"></i> Grading Sheet
                            </button>
                        </div>
                    </div>
                    <div class="card-body" id="classRecordPreview">
                        <div class="empty-state">
                            <i class="bi bi-file-earmark"></i>
                            <p>Select a report type above to generate preview</p>
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
        
        function exportReport(type, classId, format) {
            window.open(`api/index.php?action=generate_${type}_report&class_id=${classId}&format=${format}`);
        }
        
        function previewReport(type, classId) {
            const previewDiv = document.getElementById('classRecordPreview');
            previewDiv.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2">Loading preview...</p></div>';
            
            fetch(`api/index.php?action=generate_${type}_report&class_id=${classId}&format=pdf`)
                .then(response => response.text())
                .then(html => {
                    previewDiv.innerHTML = html;
                })
                .catch(err => {
                    previewDiv.innerHTML = '<div class="text-center py-4 text-danger">Error loading preview: ' + err.message + '</div>';
                });
        }
        
        function exportAttendance(classId, format) {
            window.open(`api/index.php?action=export_attendance&class_id=${classId}&format=${format}`);
        }
    </script>
</body>
</html>
