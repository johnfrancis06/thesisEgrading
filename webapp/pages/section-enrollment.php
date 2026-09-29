<?php
$db = Database::getInstance()->getConnection();
$faculty_id = $auth->getFacultyId();
$subjects = $db->query("SELECT id, code, title, default_units FROM subject ORDER BY code")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Enrollment - <?= APP_NAME ?></title>
    <link href="assets/vendor/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css?v=9">
    <script src="assets/vendor/xlsx.full.min.js"></script>
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
                    <h1 class="page-title">Student Enrollment</h1>
                    <span class="page-subtitle">Enroll students into sections</span>
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
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#registerStudentModal">
                    <i class="bi bi-person-plus"></i> Register Student
                </button>
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
            <div class="page-header fade-in">
                <div class="page-header-left">
                    <div class="page-header-icon">
                        <i class="bi bi-people"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">Enrolled Students</h1>
                        <p class="text-muted mb-0">View and manage section enrollments</p>
                    </div>
                </div>
            </div>
            
            <div class="card fade-in">
                <div class="card-header">
                    <span><i class="bi bi-people me-2"></i>Enrolled Students</span>
                </div>
                <div class="card-body">
                    <div id="yearLevelAccordion" class="accordion"></div>
                </div>
            </div>
        </main>
    </div>
    
    <div class="modal fade" id="registerStudentModal" aria-hidden="true" tabindex="-1">
        <div class="modal-dialog modal-lg modal-right-shifted">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="registrationModalTitle">Create Subject</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="modal-screen active" id="subjectScreen">
                        <h6 class="text-uppercase text-muted mb-3" style="font-size: 0.75rem; letter-spacing: 0.08em; font-weight: 600;">Subject Information</h6>
                        <div class="mb-3">
                            <label class="form-label" for="subjectCode">Subject Code <span class="text-danger">*</span></label>
                            <input type="text" id="subjectCode" class="form-control" placeholder="GE 104" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="subjectTitle">Subject Title <span class="text-danger">*</span></label>
                            <input type="text" id="subjectTitle" class="form-control" placeholder="Math in the Modern World" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="defaultUnits">Default Units</label>
                            <input type="number" id="defaultUnits" class="form-control" value="3" min="1">
                        </div>
                        <input type="hidden" id="subjectId" value="">
                        <div class="form-group" id="cancelEditGroup" style="display: none;">
                            <button type="button" class="btn btn-secondary" onclick="cancelEditSubject()">Cancel Edit</button>
                        </div>
                        <div class="d-flex justify-content-end mb-3">
                            <button type="button" class="btn btn-primary" id="saveSubjectBtn" onclick="saveSubject()">Add Subject</button>
                        </div>
                        <h6 class="text-uppercase text-muted mb-3 mt-4" style="font-size: 0.75rem; letter-spacing: 0.08em; font-weight: 600;">Subject Catalog</h6>
                        <div class="card mb-4" style="background: var(--gray-50);">
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Code</th>
                                                <th>Title</th>
                                                <th>Units</th>
                                                <th style="width: 150px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="subjectsTable">
                                            <tr><td colspan="4" class="text-center py-4">Loading...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-screen" id="studentScreen">
                        <h6 class="text-uppercase text-muted mb-3" style="font-size: 0.75rem; letter-spacing: 0.08em; font-weight: 600;">Section Information</h6>
                        <form id="enrollmentForm">
                            <div class="mb-3">
                                <label class="form-label" for="programInput">Program <span class="text-danger">*</span></label>
                                <input type="text" id="programInput" class="form-control" placeholder="BSIT" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="yearInput">Year Level <span class="text-danger">*</span></label>
                                <select id="yearInput" class="form-select" required>
                                    <option value="">Select Year</option>
                                    <option value="1">1st Year</option>
                                    <option value="2">2nd Year</option>
                                    <option value="3">3rd Year</option>
                                    <option value="4">4th Year</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="sectionInput">Section <span class="text-danger">*</span></label>
                                <input type="text" id="sectionInput" class="form-control" placeholder="A" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="ayInput">Academic Year <span class="text-danger">*</span></label>
                                <input type="text" id="ayInput" class="form-control" placeholder="2025-2026" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="semesterInput">Semester</label>
                                <select id="semesterInput" class="form-select">
                                    <option value="">-- Select Semester --</option>
                                    <option value="1">1st Semester</option>
                                    <option value="2">2nd Semester</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="subjectSelect">Subject</label>
                                <select id="subjectSelect" class="form-select">
                                    <option value="">-- Select Subject --</option>
                                    <?php foreach ($subjects as $s): ?>
                                        <option value="<?= $s['id']; ?>"><?= htmlspecialchars($s['code'] . ': ' . $s['title']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>

                        <h6 class="text-uppercase text-muted mb-3" style="font-size: 0.75rem; letter-spacing: 0.08em; font-weight: 600;">Student Information</h6>
                        <ul class="nav nav-tabs mb-3" id="studentInputTabs">
                            <li class="nav-item">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#manualInput" type="button">Manual Input</button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#bulkImport" type="button">Bulk Import (Excel)</button>
                            </li>
                        </ul>
                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="manualInput">
                                <div class="mb-3">
                                    <label class="form-label" for="studentNoInput">Student No <span class="text-danger">*</span></label>
                                    <input type="text" id="studentNoInput" class="form-control" placeholder="2401001" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="lastNameInput">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" id="lastNameInput" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="firstNameInput">First Name <span class="text-danger">*</span></label>
                                    <input type="text" id="firstNameInput" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="miInput">Middle Initial</label>
                                    <input type="text" id="miInput" class="form-control" maxlength="5">
                                </div>
                                <div class="d-flex justify-content-end gap-2">
                                    <button type="button" class="btn btn-secondary" id="cancelEditBtn" style="display: none;" onclick="cancelEdit()">
                                        <i class="bi bi-x-circle me-1"></i> Cancel
                                    </button>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="bulkImport">
                                <div class="alert alert-info small mb-3">
                                    <strong>Excel Format:</strong><br>
                                    Column A: Student No<br>
                                    Column B: Last Name<br>
                                    Column C: First Name<br>
                                    Column D: Middle Initial
                                </div>
                                <div class="input-group">
                                    <input type="file" id="csvFile" class="form-control" accept=".xlsx,.xls">
                                    <button class="btn btn-primary" type="button" onclick="importExcel()">
                                        <i class="bi bi-upload"></i> Import
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-screen" id="confirmationScreen">
                        <h6 class="text-uppercase text-muted mb-3" style="font-size: 0.75rem; letter-spacing: 0.08em; font-weight: 600;">Review Details</h6>
                        <div class="card mb-3" style="background: var(--gray-50);">
                            <div class="card-header" style="background: transparent; border-bottom: 1px solid var(--gray-200);">
                                <h6 class="mb-0"><i class="bi bi-book me-2"></i>Subject</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4 mb-2"><strong>Code</strong><br><span id="confirmSubjectCode"></span></div>
                                    <div class="col-md-4 mb-2"><strong>Title</strong><br><span id="confirmSubjectTitle"></span></div>
                                    <div class="col-md-4 mb-2"><strong>Units</strong><br><span id="confirmSubjectUnits"></span></div>
                                </div>
                            </div>
                        </div>
                        <div class="card mb-3" style="background: var(--gray-50);">
                            <div class="card-header" style="background: transparent; border-bottom: 1px solid var(--gray-200);">
                                <h6 class="mb-0"><i class="bi bi-people me-2"></i>Section</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-2"><strong>Program</strong><br><span id="confirmProgram"></span></div>
                                    <div class="col-md-6 mb-2"><strong>Year Level</strong><br><span id="confirmYear"></span></div>
                                    <div class="col-md-6 mb-2"><strong>Section</strong><br><span id="confirmSection"></span></div>
                                    <div class="col-md-6 mb-2"><strong>Academic Year</strong><br><span id="confirmAy"></span></div>
                                    <div class="col-md-6 mb-2"><strong>Semester</strong><br><span id="confirmSemester"></span></div>
                                    <div class="col-md-6 mb-2"><strong>Subject</strong><br><span id="confirmSubject"></span></div>
                                </div>
                            </div>
                        </div>
                        <div class="card mb-3" style="background: var(--gray-50);">
                            <div class="card-header" style="background: transparent; border-bottom: 1px solid var(--gray-200);">
                                <h6 class="mb-0"><i class="bi bi-person me-2"></i>Student</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-2"><strong>Student No</strong><br><span id="confirmStudentNo"></span></div>
                                    <div class="col-md-6 mb-2"><strong>Last Name</strong><br><span id="confirmLastName"></span></div>
                                    <div class="col-md-6 mb-2"><strong>First Name</strong><br><span id="confirmFirstName"></span></div>
                                    <div class="col-md-6 mb-2"><strong>Middle Initial</strong><br><span id="confirmMi"></span></div>
                                </div>
                            </div>
                        </div>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-1"></i>
                            Review the details above. Use the edit buttons to return and make changes before saving.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="backScreenBtn" onclick="goToPreviousScreen()">Back</button>
                    <button type="button" class="btn btn-primary" id="nextScreenBtn" onclick="goToNextScreen()">Next</button>
                    <button type="button" class="btn btn-success" id="saveStudentBtn" onclick="addStudentToSection()">Save Student</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="assets/vendor/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('mobileToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        });

        let currentRegistrationScreen = 0;
        const registrationScreens = [
            document.getElementById('subjectScreen'),
            document.getElementById('studentScreen'),
            document.getElementById('confirmationScreen')
        ];
        const registrationTitles = ['Create Subject', 'Register Student', 'Review & Confirm'];

        document.getElementById('registerStudentModal').addEventListener('shown.bs.modal', function() {
            loadSubjectsTable();
            showRegistrationScreen(0);
        });

        document.getElementById('registerStudentModal').addEventListener('hide.bs.modal', function () {
            if (document.activeElement && document.activeElement !== document.body) {
                document.activeElement.blur();
            }
        });
        
        function showRegistrationScreen(index) {
            currentRegistrationScreen = index;
            registrationScreens.forEach(function(screen, screenIndex) {
                screen.classList.toggle('active', screenIndex === index);
            });
            document.getElementById('registrationModalTitle').textContent = registrationTitles[index];
            document.getElementById('backScreenBtn').style.display = index === 0 ? 'none' : 'inline-block';
            document.getElementById('nextScreenBtn').style.display = index < 2 ? 'inline-block' : 'none';
            document.getElementById('saveStudentBtn').style.display = index === 2 ? 'inline-block' : 'none';
            document.getElementById('saveSubjectBtn').style.display = index === 0 ? 'inline-block' : 'none';

            if (index === 2) {
                loadConfirmationDetails();
            }
        }

        function validateSubjectScreen() {
            const code = document.getElementById('subjectCode').value.trim();
            const title = document.getElementById('subjectTitle').value.trim();
            const units = parseInt(document.getElementById('defaultUnits').value);
            if (!code || !title || !units || units < 1) {
                alert('Please fill in Subject Code, Subject Title, and a valid Default Units value');
                return false;
            }
            return true;
        }

        function validateStudentScreen() {
            const form = document.getElementById('enrollmentForm');
            if (!form.checkValidity()) {
                form.reportValidity();
                return false;
            }
            const activeTab = document.querySelector('#studentInputTabs .nav-link.active').getAttribute('data-bs-target');
            if (activeTab === '#manualInput') {
                const studentFields = ['studentNoInput', 'lastNameInput', 'firstNameInput'];
                const missing = studentFields.find(function(id) {
                    return !document.getElementById(id).value.trim();
                });
                if (missing) {
                    alert('Please fill in all required student fields');
                    document.getElementById(missing).focus();
                    return false;
                }
            }
            return true;
        }

        function goToNextScreen() {
            if (currentRegistrationScreen === 0 && !validateSubjectScreen()) return;
            if (currentRegistrationScreen === 1 && !validateStudentScreen()) return;
            showRegistrationScreen(currentRegistrationScreen + 1);
        }

        function goToPreviousScreen() {
            showRegistrationScreen(currentRegistrationScreen - 1);
        }

        function loadConfirmationDetails() {
            document.getElementById('confirmSubjectCode').textContent = document.getElementById('subjectCode').value.trim() || '-';
            document.getElementById('confirmSubjectTitle').textContent = document.getElementById('subjectTitle').value.trim() || '-';
            document.getElementById('confirmSubjectUnits').textContent = document.getElementById('defaultUnits').value || '-';
            document.getElementById('confirmProgram').textContent = document.getElementById('programInput').value.trim() || '-';
            const yearOptions = document.getElementById('yearInput').options;
            document.getElementById('confirmYear').textContent = yearOptions[document.getElementById('yearInput').selectedIndex]?.textContent || '-';
            document.getElementById('confirmSection').textContent = document.getElementById('sectionInput').value.trim() || '-';
            document.getElementById('confirmAy').textContent = document.getElementById('ayInput').value.trim() || '-';
            const semesterOptions = document.getElementById('semesterInput').options;
            document.getElementById('confirmSemester').textContent = semesterOptions[document.getElementById('semesterInput').selectedIndex]?.textContent || '-';
            const subjectSelect = document.getElementById('subjectSelect');
            document.getElementById('confirmSubject').textContent = subjectSelect ? (subjectSelect.options[subjectSelect.selectedIndex]?.textContent || '-') : '-';
            document.getElementById('confirmStudentNo').textContent = document.getElementById('studentNoInput').value.trim() || '-';
            document.getElementById('confirmLastName').textContent = document.getElementById('lastNameInput').value.trim() || '-';
            document.getElementById('confirmFirstName').textContent = document.getElementById('firstNameInput').value.trim() || '-';
            document.getElementById('confirmMi').textContent = document.getElementById('miInput').value.trim() || '-';
        }
        
        let editingStudentId = null;

        function cancelEdit() {
            editingStudentId = null;
            document.getElementById('enrollmentForm').reset();
            document.getElementById('cancelEditBtn').style.display = 'none';
        }
        
        async function addStudentToSection() {
            const program = document.getElementById('programInput').value.trim();
            const year = document.getElementById('yearInput').value;
            const section = document.getElementById('sectionInput').value.trim();
            const ay = document.getElementById('ayInput').value.trim();
            const semester = document.getElementById('semesterInput') ? document.getElementById('semesterInput').value : '';
            const subjectId = document.getElementById('subjectSelect').value;
            const studentNo = document.getElementById('studentNoInput').value.trim();
            const lastName = document.getElementById('lastNameInput').value.trim();
            const firstName = document.getElementById('firstNameInput').value.trim();
            const mi = document.getElementById('miInput').value.trim();

            if (!program || !year || !section || !ay || !studentNo || !lastName || !firstName) {
                alert('Please fill in all required fields');
                return;
            }

            let url = 'api/index.php?action=add_section_student';
            let body = {
                course_program: program, year_level: parseInt(year), section: section, academic_year: ay,
                student_no: studentNo, last_name: lastName, first_name: firstName, middle_initial: mi,
                subject_id: subjectId ? parseInt(subjectId) : 0,
                semester: semester ? parseInt(semester) : 0
            };
            if (editingStudentId !== null) {
                url = 'api/index.php?action=update_section_student';
                body.id = editingStudentId;
            }
            const resp = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            const data = await resp.json();
            if (data.success) {
                document.getElementById('enrollmentForm').reset();
                loadEnrolledStudents();
                cancelEdit();
                showRegistrationScreen(0);
                let msg = editingStudentId !== null ? 'Student updated successfully' : 'Student added successfully';
                if (data.data.created_class) {
                    msg += `\nNew class created (ID: ${data.data.created_class})`;
                }
                if (data.data.enrolled_in_classes > 0) {
                    msg += `\nEnrolled in ${data.data.enrolled_in_classes} class(es)`;
                }
                if (data.data.synced_classes > 0) {
                    msg += `\nSynced to ${data.data.synced_classes} class roster(s)`;
                }
                alert(msg);
            } else {
                alert(data.message || 'Failed to save student');
            }
        }

        function editStudent(id, studentNo, lastName, firstName, mi, program, year, section, ay, subjectId, semester) {
            editingStudentId = id;
            document.getElementById('programInput').value = program;
            document.getElementById('yearInput').value = year;
            document.getElementById('sectionInput').value = section;
            document.getElementById('ayInput').value = ay;
            if (document.getElementById('subjectSelect')) {
                document.getElementById('subjectSelect').value = subjectId || '';
            }
            if (document.getElementById('semesterInput')) {
                document.getElementById('semesterInput').value = semester || '';
            }
            document.getElementById('studentNoInput').value = studentNo;
            document.getElementById('lastNameInput').value = lastName;
            document.getElementById('firstNameInput').value = firstName;
            document.getElementById('miInput').value = mi;
            document.getElementById('cancelEditBtn').style.display = 'inline-block';
            showRegistrationScreen(1);
            document.getElementById('studentNoInput').focus();
        }

        function importExcel() {
            const file = document.getElementById('csvFile').files[0];
            if (!file) { alert('Please select a file'); return; }

            const fileName = file.name.toLowerCase();
            if (!fileName.endsWith('.xlsx') && !fileName.endsWith('.xls')) {
                alert('Please upload an Excel file only (.xlsx or .xls).');
                return;
            }
            const program = document.getElementById('programInput').value.trim();
            const year = document.getElementById('yearInput').value;
            const section = document.getElementById('sectionInput').value.trim();
            const ay = document.getElementById('ayInput').value.trim();
            const semester = document.getElementById('semesterInput') ? document.getElementById('semesterInput').value : '';
            const subjectId = document.getElementById('subjectSelect') ? document.getElementById('subjectSelect').value : '';
            if (!program || !year || !section || !ay) {
                alert('Please fill in Program, Year Level, Section, and Academic Year first');
                return;
            }
            const reader = new FileReader();
            reader.onload = async function(e) {
                try {
                    const workbook = XLSX.read(e.target.result, {type: 'binary'});
                    const sheet = workbook.Sheets[workbook.SheetNames[0]];
                    const rows = XLSX.utils.sheet_to_json(sheet, {header: 1});

                    let count = 0;
                    let errors = [];
                    let skippedHeader = false;
                    
                    for (let i = 0; i < rows.length; i++) {
                        const row = rows[i];
                        const studentNo = String(row[0] || '').trim();
                        const lastName = String(row[1] || '').trim();
                        const firstName = String(row[2] || '').trim();
                        const middleInitial = String(row[3] || '').trim();
                        
                        if (!skippedHeader && studentNo.toLowerCase().includes('student') && lastName.toLowerCase().includes('name')) {
                            skippedHeader = true;
                            continue;
                        }
                        skippedHeader = true;
                        
                        if (!studentNo || !lastName || !firstName) {
                            continue;
                        }
                        
                        try {
                            const resp = await fetch('api/index.php?action=add_section_student', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    course_program: program, year_level: parseInt(year), section: section, academic_year: ay,
                                    student_no: studentNo, last_name: lastName, first_name: firstName, middle_initial: middleInitial,
                                    subject_id: subjectId ? parseInt(subjectId) : 0,
                                    semester: semester ? parseInt(semester) : 0
                                })
                            });
                            const data = await resp.json();
                            if (data.success) {
                                if ((data.data && data.data.enrolled_in_classes > 0) || (data.data && data.data.created_class)) count++;
                            } else {
                                errors.push('Row ' + (i + 1) + ': ' + (data.message || 'Failed'));
                            }
                        } catch (err) {
                            errors.push('Row ' + (i + 1) + ': ' + err.message);
                        }
                    }
                    
                    let message = 'Successfully imported ' + count + ' students';
                    if (errors.length > 0) {
                        message += '\nErrors:\n' + errors.join('\n');
                    }
                    alert(message);
                    document.getElementById('csvFile').value = '';
                    loadEnrolledStudents();
                    showRegistrationScreen(0);
                    bootstrap.Modal.getInstance(document.getElementById('registerStudentModal')).hide();
                } catch (err) {
                    alert('Error reading Excel file: ' + err.message);
                }
            };
            reader.readAsBinaryString(file);
        }

        async function loadEnrolledStudents() {
            const resp = await fetch('api/index.php?action=get_sections');
            const data = await resp.json();
            if (!data.success || !data.data || data.data.length === 0) {
                document.getElementById('yearLevelAccordion').innerHTML = '<div class="empty-state"><i class="bi bi-person-dash"></i><p>No students enrolled yet</p></div>';
                return;
            }
            const grouped = {};
            data.data.forEach(function(section) {
                if (!grouped[section.year_level]) grouped[section.year_level] = {};
                if (!grouped[section.year_level][section.section]) grouped[section.year_level][section.section] = section;
            });
            let html = '';
            const years = Object.keys(grouped).sort();
            for (let y = 0; y < years.length; y++) {
                const year = years[y];
                const sections = grouped[year];
                const yearId = 'year' + year;
                html += '<div class="accordion-item">';
                html += '<h2 class="accordion-header">';
                html += '<button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#' + yearId + '">';
                html += '<strong>Year ' + year + '</strong>';
                html += '<span class="badge badge-secondary ms-2">' + Object.keys(sections).length + ' Section(s)</span>';
                html += '</button></h2>';
                html += '<div id="' + yearId + '" class="accordion-collapse collapse" data-bs-parent="#yearLevelAccordion">';
                html += '<div class="accordion-body p-0"><div class="list-group list-group-flush">';
                
                const sectionKeys = Object.keys(sections);
                for (let s = 0; s < sectionKeys.length; s++) {
                    const section = sectionKeys[s];
                    const sectionData = sections[section];
                    const sectionId = 'section' + year + section;
                    html += '<div class="list-group-item">';
                    html += '<button class="btn btn-link text-start w-100" type="button" data-bs-toggle="collapse" data-bs-target="#' + sectionId + '">';
                    html += '<i class="bi bi-chevron-right me-2"></i>Section ' + section + ' (' + sectionData.count + ' students)';
                    html += '</button>';
                    html += '<div id="' + sectionId + '" class="collapse">';
                    html += '<div class="table-responsive p-3">';
                    html += '<table class="table table-sm table-hover">';
                    html += '<thead><tr><th>Student No</th><th>Name</th><th>Actions</th></tr></thead>';
                    html += '<tbody id="students' + year + section + '"></tbody>';
                    html += '</table></div></div></div>';
                }
                html += '</div></div></div></div>';
            }
            document.getElementById('yearLevelAccordion').innerHTML = html;

            const yearKeys = Object.keys(grouped);
            for (let y = 0; y < yearKeys.length; y++) {
                const year = yearKeys[y];
                const sections = grouped[year];
                const sectionKeys = Object.keys(sections);
                for (let s = 0; s < sectionKeys.length; s++) {
                    const section = sectionKeys[s];
                    const sectionData = sections[section];
                    loadSectionStudents(year, section, sectionData.course_program, sectionData.academic_year);
                }
            }
        }

        async function loadSectionStudents(year, section, program, ay) {
            const url = 'api/index.php?action=get_section_students&program=' + encodeURIComponent(program) + '&year=' + encodeURIComponent(year) + '&section=' + encodeURIComponent(section) + '&ay=' + encodeURIComponent(ay);
            const resp = await fetch(url);
            const data = await resp.json();
            let html = '';
            if (data.success && data.data && data.data.length > 0) {
                for (let i = 0; i < data.data.length; i++) {
                    const s = data.data[i];
                    html += '<tr>';
                    html += '<td><strong>' + s.student_no + '</strong></td>';
                    html += '<td>' + s.last_name + ', ' + s.first_name + ' ' + (s.middle_initial || '') + '</td>';
                    html += '<td>';
                    html += '<button class="btn btn-sm btn-warning" onclick="editStudent(' + s.id + ', \'' + s.student_no + '\', \'' + s.last_name + '\', \'' + s.first_name + '\', \'' + s.middle_initial + '\', \'' + program + '\', ' + year + ', \'' + section + '\', \'' + ay + '\', ' + (s.subject_id || 0) + ', ' + (s.semester || 0) + ')">';
                    html += '<i class="bi bi-pencil"></i>';
                    html += '</button> ';
                    html += '<button class="btn btn-sm btn-danger" onclick="deleteStudent(' + s.id + ')">';
                    html += '<i class="bi bi-trash"></i>';
                    html += '</button>';
                    html += '</td></tr>';
                }
            } else {
                html = '<tr><td colspan="3" class="text-center text-muted">No students</td></tr>';
            }
            var tbodyId = 'students' + year + section;
            document.getElementById(tbodyId).innerHTML = html;
        }

        async function deleteStudent(id) {
            if (!confirm('Delete this student?')) return;
            const resp = await fetch('api/index.php?action=remove_section_student', { method: 'POST', body: JSON.stringify({ id }) }).then(r => r.json());
            if (resp.success) {
                loadEnrolledStudents();
                if (editingStudentId === id) cancelEdit();
            }
        }

        loadEnrolledStudents();
        
        // Load subjects table when Register Student modal is shown
        document.getElementById('registerStudentModal').addEventListener('shown.bs.modal', function() {
            loadSubjectsTable();
        });
        
        // Subject management functions
        async function loadSubjectsTable() {
            try {
                const resp = await fetch('api/index.php?action=get_subjects&t=' + Date.now());
                const data = await resp.json();
                if (!data.success) return;
                
                const tbody = document.getElementById('subjectsTable');
                if (data.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">No subjects yet</td></tr>';
                    return;
                }
                
                tbody.innerHTML = data.data.map(s => `
                    <tr>
                        <td><strong>${s.code}</strong></td>
                        <td>${s.title}</td>
                        <td>${s.default_units}</td>
                        <td>
                            <button onclick="editSubject(${s.id}, '${s.code}', '${s.title}', ${s.default_units})" class="btn btn-sm btn-warning me-1">
                                <i class="bi bi-pencil-square"></i> Edit
                            </button>
                            <button onclick="deleteSubject(${s.id})" class="btn btn-sm btn-danger">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </td>
                    </tr>
                `).join('');
            } catch (e) {
                console.error('Error loading subjects:', e);
            }
        }
        
        function showAddSubjectModal() {
            document.getElementById('subjectId').value = '';
            document.getElementById('subjectCode').value = '';
            document.getElementById('subjectTitle').value = '';
            document.getElementById('defaultUnits').value = '3';
            document.getElementById('cancelEditGroup').style.display = 'none';
            showRegistrationScreen(0);
            document.getElementById('subjectCode').focus();
        }
        
        async function saveSubject() {
            const id = document.getElementById('subjectId').value;
            const data = {
                code: document.getElementById('subjectCode').value.trim(),
                title: document.getElementById('subjectTitle').value.trim(),
                default_units: parseInt(document.getElementById('defaultUnits').value)
            };
            
            if (!data.code || !data.title) {
                alert('Please fill in Subject Code and Title');
                return;
            }
            
            try {
                const action = id ? 'update_subject' : 'create_subject';
                const body = id ? { ...data, id: parseInt(id) } : data;
                
                const resp = await fetch(`api/index.php?action=${action}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });
                const json = await resp.json();
                if (json.success) {
                    alert(id ? 'Subject updated!' : 'Subject added!');
                    cancelEditSubject();
                    loadSubjectsTable();
                    loadSubjects();
                    showRegistrationScreen(1);
                } else {
                    alert('Error: ' + (json.message || 'Failed to save subject'));
                }
            } catch (e) {
                alert('Error: ' + e.message);
            }
        }
        
        function cancelEditSubject() {
            document.getElementById('subjectId').value = '';
            document.getElementById('subjectCode').value = '';
            document.getElementById('subjectTitle').value = '';
            document.getElementById('defaultUnits').value = '3';
            const cancelGroup = document.getElementById('cancelEditGroup');
            if (cancelGroup) cancelGroup.style.display = 'none';
        }
        
        async function editSubject(id, code, title, units) {
            document.getElementById('subjectId').value = id;
            document.getElementById('subjectCode').value = code;
            document.getElementById('subjectTitle').value = title;
            document.getElementById('defaultUnits').value = units;
            document.getElementById('cancelEditGroup').style.display = 'block';
            showRegistrationScreen(0);
            document.getElementById('subjectCode').focus();
        }
        
        async function deleteSubject(id) {
            if (!confirm('Are you sure you want to delete this subject?')) return;
            
            try {
                const resp = await fetch('api/index.php?action=delete_subject', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const json = await resp.json();
                if (json.success) {
                    alert('Subject deleted!');
                    loadSubjectsTable();
                    loadSubjects();
                } else {
                    alert('Error: ' + json.message);
                }
            } catch (e) {
                alert('Error: ' + e.message);
            }
        }
    </script>
</body>
</html>



