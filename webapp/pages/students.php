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
        <header class="topbar-modern">
            <div class="topbar-left">
                <button class="mobile-toggle" id="mobileToggle" aria-label="Toggle navigation">
                    <i class="bi bi-list"></i>
                </button>
                <div class="page-title-block">
                    <h1 class="page-title"><?= $class ? 'Students' : 'Select a Class' ?></h1>
                    <span class="page-subtitle"><?= $class ? htmlspecialchars($class['course_program']) . ' Yr' . $class['year_level'] . '-' . htmlspecialchars($class['section']) . ' - ' . $class['academic_year'] : 'Choose a class to view students' ?></span>
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
            <?php if (!$class): ?>
            <div class="card fade-in mb-3">
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Year Level</label>
                            <select id="filterYear" class="form-select" onchange="updateFilters()">
                                <option value="">All Years</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Section</label>
                            <select id="filterSection" class="form-select" onchange="updateFilters()">
                                <option value="">All Sections</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Subject</label>
                            <select id="filterSubject" class="form-select" onchange="applyFilters()">
                                <option value="">All Subjects</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-primary w-100" onclick="applyFilters()">
                                <i class="bi bi-funnel"></i> Filter
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
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
                                <th>Subjects Enrolled</th>
                                <th>Classes</th>
                                <th>Academic Years</th>
                            </tr>
                        </thead>
                        <tbody id="studentsList">
                            <tr><td colspan="7" class="text-center py-4">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        </main>
    </div>
    
    <script src="assets/vendor/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('mobileToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        });
        
        async function loadFilterOptions() {
            try {
                const resp = await fetch('api/index.php?action=get_student_filter_options');
                const data = await resp.json();
                if (!data.success) return;

                window.classOptions = data.data;

                const years = [...new Set(data.data.map(o => o.year_level))]
                    .filter(y => y !== null && y !== '')
                    .sort((a, b) => a - b);

                const yearSelect = document.getElementById('filterYear');
                years.forEach(year => {
                    const option = document.createElement('option');
                    option.value = year;
                    option.textContent = 'Year ' + year;
                    yearSelect.appendChild(option);
                });

                updateFilters();
            } catch (e) {
                console.error('Error loading filter options:', e);
            }
        }

        // Sections narrow by year level; subjects narrow by year level and section.
        function updateFilters() {
            const yearSelect = document.getElementById('filterYear');
            const sectionSelect = document.getElementById('filterSection');
            const subjectSelect = document.getElementById('filterSubject');
            if (!sectionSelect || !subjectSelect) return;

            const selectedYear = yearSelect.value;
            const selectedSection = sectionSelect.value;
            const options = window.classOptions || [];

            const inYear = options.filter(o => !selectedYear || String(o.year_level) === String(selectedYear));
            const inSection = inYear.filter(o =>
                !selectedSection || String(o.section).toLowerCase() === selectedSection.toLowerCase()
            );

            // The database compares these columns case-insensitively, so collapse the
            // 'A'/'a' duplicates that survive the DISTINCT query.
            const uniqueValues = values => {
                const seen = new Map();
                values.filter(Boolean).forEach(value => {
                    const key = String(value).toLowerCase();
                    if (!seen.has(key)) seen.set(key, value);
                });
                return [...seen.entries()];
            };

            sectionSelect.innerHTML = '<option value="">All Sections</option>';
            uniqueValues(inYear.map(o => o.section))
                .sort((a, b) => a[1].localeCompare(b[1], undefined, { sensitivity: 'base' }))
                .forEach(([, section]) => {
                    const option = document.createElement('option');
                    option.value = section;
                    option.textContent = 'Section ' + section;
                    sectionSelect.appendChild(option);
                });
            if (selectedSection) sectionSelect.value = selectedSection;

            const subjects = new Map();
            inSection.forEach(o => {
                if (!o.subject_code) return;
                const key = String(o.subject_code).toLowerCase();
                if (!subjects.has(key)) {
                    subjects.set(key, { code: o.subject_code, title: o.subject_title });
                }
            });

            const selectedSubject = subjectSelect.value;
            const selectedSubjectKey = selectedSubject.toLowerCase();
            subjectSelect.innerHTML = '<option value="">All Subjects</option>';
            [...subjects.values()]
                .sort((a, b) => a.code.localeCompare(b.code, undefined, { sensitivity: 'base' }))
                .forEach(subject => {
                    const option = document.createElement('option');
                    option.value = subject.code;
                    option.textContent = subject.title
                        ? `${subject.code} - ${subject.title}`
                        : subject.code;
                    subjectSelect.appendChild(option);
                });
            if (subjects.has(selectedSubjectKey)) {
                subjectSelect.value = subjects.get(selectedSubjectKey).code;
            }
        }

        async function loadStudents(classId, yearLevel, section, subject) {
            let url = `api/index.php?action=get_students&class_id=${classId}`;
            if (yearLevel) url += `&year_level=${encodeURIComponent(yearLevel)}`;
            if (section) url += `&section=${encodeURIComponent(section)}`;
            if (subject) url += `&subject=${encodeURIComponent(subject)}`;
            
            const resp = await fetch(url);
            const data = await resp.json();
            let html = '';
            
            if (data.data && data.data.length > 0) {
                if (classId) {
                    html = data.data.map(s => `
                        <tr>
                            <td><strong>${escapeStudentHtml(s.student_no)}</strong></td>
                            <td>${escapeStudentHtml(s.last_name)}</td>
                            <td>${escapeStudentHtml(s.first_name)}</td>
                            <td>${escapeStudentHtml(s.middle_initial || '-')}</td>
                        </tr>
                    `).join('');
                } else {
                    const groupedStudents = groupStudentsByNumber(data.data);
                    html = groupedStudents.map(s => `
                        <tr>
                            <td><strong>${escapeStudentHtml(s.student_no)}</strong></td>
                            <td>${escapeStudentHtml(s.last_name)}</td>
                            <td>${escapeStudentHtml(s.first_name)}</td>
                            <td>${escapeStudentHtml(s.middle_initial || '-')}</td>
                            <td>
                                <div class="student-subject-tags">
                                    ${s.subjects.map(subject => `<span class="badge badge-primary subject-tag">${escapeStudentHtml(subject)}</span>`).join('')}
                                </div>
                                <small class="student-subject-count">${s.subjects.length} subject${s.subjects.length === 1 ? '' : 's'}</small>
                            </td>
                            <td>${s.classes.map(item => `<span class="student-class-line">${escapeStudentHtml(item)}</span>`).join('')}</td>
                            <td>${s.academicYears.map(year => `<span class="student-year-line">${escapeStudentHtml(year)}</span>`).join('')}</td>
                        </tr>
                    `).join('');
                }
            } else {
                html = '<tr><td colspan="' + (classId ? '4' : '7') + '" class="text-center py-4 text-muted">No students found</td></tr>';
            }
            document.getElementById('studentsList').innerHTML = html;
        }

        function groupStudentsByNumber(students) {
            const grouped = new Map();
            students.forEach(student => {
                const key = student.student_no;
                if (!grouped.has(key)) {
                    grouped.set(key, {
                        student_no: student.student_no,
                        last_name: student.last_name,
                        first_name: student.first_name,
                        middle_initial: student.middle_initial,
                        subjects: [],
                        classes: [],
                        academicYears: []
                    });
                }
                const item = grouped.get(key);
                if (student.code && !item.subjects.includes(student.code)) item.subjects.push(student.code);
                const classLabel = `${student.course_program || ''} Yr${student.year_level || ''}-${student.section || ''}`.trim();
                if (classLabel && !item.classes.includes(classLabel)) item.classes.push(classLabel);
                if (student.academic_year && !item.academicYears.includes(student.academic_year)) item.academicYears.push(student.academic_year);
            });
            return Array.from(grouped.values());
        }

        function escapeStudentHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, character => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
            }[character]));
        }
        
        const currentClassId = <?= $classId ?>;

        function applyFilters() {
            const yearSelect = document.getElementById('filterYear');
            const sectionSelect = document.getElementById('filterSection');
            const subjectSelect = document.getElementById('filterSubject');

            if (!yearSelect || !sectionSelect || !subjectSelect) {
                loadStudents(currentClassId);
                return;
            }

            loadStudents(
                currentClassId,
                yearSelect.value,
                sectionSelect.value,
                subjectSelect.value
            );
        }

        <?php if ($class): ?>
        loadStudents(currentClassId);
        <?php else: ?>
        loadFilterOptions().then(applyFilters);
        <?php endif; ?>
    </script>

</body>
</html>




