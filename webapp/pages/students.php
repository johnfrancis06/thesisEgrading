<?php
$db = Database::getInstance()->getConnection();
$faculty_id = $auth->getFacultyId();
$classId = intval($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT cs.*, s.code, s.title FROM class_section cs 
    JOIN subject s ON cs.subject_id = s.id WHERE cs.faculty_id = ? ORDER BY cs.academic_year DESC, cs.semester DESC");
$stmt->bind_param("i", $faculty_id);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$class = null;
if ($classId > 0) {
    $stmt = $db->prepare("SELECT cs.*, s.code, s.title FROM class_section cs 
        JOIN subject s ON cs.subject_id = s.id WHERE cs.id = ?");
    $stmt->bind_param("i", $classId);
    $stmt->execute();
    $class = $stmt->get_result()->fetch_assoc();
}

$pageTitle = $class ? htmlspecialchars($class['code']) . ' - Students' : 'Select a Class';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?= APP_NAME ?></title>
    <link href="assets/vendor/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css?v=8">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <button class="mobile-toggle" id="mobileToggle">
                    <i class="bi bi-list"></i>
                </button>
                <h1 class="topbar-title"><?= $class ? 'Students' : 'Select a Class' ?></h1>
            </div>
            <div class="topbar-right">
                <a href="index.php?page=section-enrollment" class="btn btn-primary">
                    <i class="bi bi-person-plus"></i> Enroll Students
                </a>
            </div>
        </div>
        
        <div class="content-area">
                        <div class="card fade-in mb-3">
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Year Level</label>
                            <select id="filterYear" class="form-select" onchange="updateSections()">
                                <option value="">All Years</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Section</label>
                            <select id="filterSection" class="form-select">
                                <option value="">All Sections</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-primary w-100" onclick="applyFilters()">
                                <i class="bi bi-funnel"></i> Filter
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php if ($class): ?>
            <div class="page-header fade-in">
                <div class="page-header-left">
                    <div class="page-header-icon">
                        <i class="bi bi-people"></i>
                    </div>
                    <div>
                        <h1 class="mb-0"><?= htmlspecialchars($class['code']) ?> - Students</h1>
                        <p class="text-muted mb-0"><?= htmlspecialchars($class['course_program']) ?> Yr<?= $class['year_level'] ?>-<?= $class['section'] ?> - <?= $class['academic_year'] ?></p>
                    </div>
                </div>
            </div>
            
            <div class="alert alert-info fade-in">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Note:</strong> Students are registered in the <a href="index.php?page=section-enrollment" class="text-decoration-underline">Section Enrollment</a> page and automatically added to this class.
            </div>
            
            <div class="card fade-in">
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Student No</th>
                                <th>Last Name</th>
                                <th>First Name</th>
                                <th>Middle Initial</th>
                            </tr>
                        </thead>
                        <tbody id="studentsList">
                            <tr><td colspan="4" class="text-center py-4">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <div class="page-header fade-in">
                <div class="page-header-left">
                    <div class="page-header-icon">
                        <i class="bi bi-folder"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">All Students</h1>
                        <p class="text-muted mb-0">Filter by year and section, then select a class</p>
                    </div>
                </div>
            </div>
            
            <div class="card fade-in">
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Student No</th>
                                <th>Last Name</th>
                                <th>First Name</th>
                                <th>Middle Initial</th>
                                <th>Subject</th>
                                <th>Class</th>
                                <th>AY</th>
                            </tr>
                        </thead>
                        <tbody id="studentsList">
                            <tr><td colspan="7" class="text-center py-4">Loading...</td></tr>
                        </tbody>
                    </table>
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
        
        async function loadEnrolledSections() {
            const resp = await fetch('api/index.php?action=get_enrolled_sections');
            const data = await resp.json();
            
            if (data.success && data.data.length > 0) {
                const yearSelect = document.getElementById('filterYear');
                const sectionSelect = document.getElementById('filterSection');
                
                // Get unique years and sections
                const years = [...new Set(data.data.map(s => s.year_level))].sort((a, b) => a - b);
                const sections = [...new Set(data.data.map(s => s.section))].sort();
                
                // Populate year dropdown
                years.forEach(year => {
                    const option = document.createElement('option');
                    option.value = year;
                    option.textContent = year + ' Year';
                    yearSelect.appendChild(option);
                });
                
                // Store all sections for filtering
                window.allSections = data.data;
                
                // Populate section dropdown with all sections initially
                updateSections();
            }
        }
        
        function updateSections() {
            const yearSelect = document.getElementById('filterYear');
            const sectionSelect = document.getElementById('filterSection');
            const selectedYear = yearSelect.value;
            
            // Clear existing options except "All Sections"
            sectionSelect.innerHTML = '<option value="">All Sections</option>';
            
            if (!window.allSections) return;
            
            // Filter sections by selected year
            let sections;
            if (selectedYear) {
                sections = [...new Set(window.allSections.filter(s => s.year_level == selectedYear).map(s => s.section))];
            } else {
                sections = [...new Set(window.allSections.map(s => s.section))];
            }
            
            sections.sort().forEach(section => {
                const option = document.createElement('option');
                option.value = section;
                option.textContent = 'Section ' + section;
                sectionSelect.appendChild(option);
            });
        }

        async function loadStudents(classId, yearLevel, section) {
            let url = `api/index.php?action=get_students&class_id=${classId}`;
            if (yearLevel) url += `&year_level=${encodeURIComponent(yearLevel)}`;
            if (section) url += `&section=${encodeURIComponent(section)}`;
            
            const resp = await fetch(url);
            const data = await resp.json();
            let html = '';
            
            if (data.data && data.data.length > 0) {
                if (classId) {
                    html = data.data.map(s => `
                        <tr>
                            <td><strong>${s.student_no}</strong></td>
                            <td>${s.last_name}</td>
                            <td>${s.first_name}</td>
                            <td>${s.middle_initial || '-'}</td>
                        </tr>
                    `).join('');
                } else {
                    html = data.data.map(s => `
                        <tr>
                            <td><strong>${s.student_no}</strong></td>
                            <td>${s.last_name}</td>
                            <td>${s.first_name}</td>
                            <td>${s.middle_initial || '-'}</td>
                            <td><span class="badge badge-primary">${s.code || ''}</span></td>
                            <td>${s.course_program || ''} Yr${s.year_level || ''}-${s.section || ''}</td>
                            <td>${s.academic_year || ''}</td>
                        </tr>
                    `).join('');
                }
            } else {
                html = '<tr><td colspan="' + (classId ? '4' : '7') + '" class="text-center py-4 text-muted">No students found</td></tr>';
            }
            document.getElementById('studentsList').innerHTML = html;
        }
        
        const currentClassId = <?= $classId ?>;
        
        function applyFilters() {
            const yearLevel = document.getElementById('filterYear').value;
            const section = document.getElementById('filterSection').value;
            loadStudents(currentClassId, yearLevel, section);
        }
        loadEnrolledSections();
        
        <?php if ($class): ?>
        loadStudents(currentClassId);
        <?php else: ?>
        loadStudents(0);
        <?php endif; ?>
    </script>

</body>
</html>




