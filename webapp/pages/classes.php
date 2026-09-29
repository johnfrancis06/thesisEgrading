<?php
$db = Database::getInstance()->getConnection();
$faculty_id = $auth->getFacultyId();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Classes - <?= APP_NAME ?></title>
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
                    <h1 class="page-title">Classes</h1>
                    <span class="page-subtitle">Manage your class sections</span>
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
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newClassModal">
                    <i class="bi bi-plus"></i> New Class
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
                        <h1 class="mb-0">My Classes</h1>
                        <p class="text-muted mb-0">Manage your class sections</p>
                    </div>
                </div>
            </div>
            
            <div class="card fade-in">
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Class Section</th>
                                <th>Semester</th>
                                <th>Academic Year</th>
                                <th>Students</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="classesList">
                            <tr><td colspan="6" class="text-center py-4">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
    
    <div class="modal fade" id="newClassModal" aria-hidden="true" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Class</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <ul class="nav nav-tabs mb-3" id="classWizardTabs">
                        <li class="nav-item">
                            <button class="nav-link active" id="step1Tab" onclick="showClassStep(1)">Class Details</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" id="step2Tab" onclick="showClassStep(2)" disabled>Enroll Students</button>
                        </li>
                    </ul>

                    <div id="classStep1">
                        <h6 class="text-uppercase text-muted mb-2" style="font-size: 0.7rem; letter-spacing: 0.05em;">Subject</h6>
                        <div class="mb-2">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="subjectMode" id="subjectModeSelect" value="select" checked onchange="toggleSubjectMode()">
                                <label class="form-check-label" for="subjectModeSelect">Select Existing</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="subjectMode" id="subjectModeCreate" value="create" onchange="toggleSubjectMode()">
                                <label class="form-check-label" for="subjectModeCreate">Create New</label>
                            </div>
                        </div>
                        <div id="subjectSelectContainer">
                            <select id="subjectSelect" class="form-select">
                                <option>Loading...</option>
                            </select>
                        </div>
                        <div id="subjectCreateContainer" style="display:none;">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <input type="text" id="newSubjectCode" class="form-control" placeholder="Subject Code">
                                </div>
                                <div class="col-md-6">
                                    <input type="text" id="newSubjectTitle" class="form-control" placeholder="Subject Title">
                                </div>
                                <div class="col-md-2">
                                    <input type="number" id="newSubjectUnits" class="form-control" placeholder="Units" min="1" value="3">
                                </div>
                            </div>
                        </div>

                        <h6 class="text-uppercase text-muted mb-2 mt-4" style="font-size: 0.7rem; letter-spacing: 0.05em;">Class Information</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Course / Program</label>
                                <input type="text" id="courseProgram" class="form-control" placeholder="BSIT" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Year Level</label>
                                <select id="yearLevel" class="form-select" required>
                                    <option value="">Select Year</option>
                                    <option value="1">1st Year</option>
                                    <option value="2">2nd Year</option>
                                    <option value="3">3rd Year</option>
                                    <option value="4">4th Year</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Section</label>
                                <input type="text" id="section" class="form-control" placeholder="A" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Semester</label>
                                <select id="semester" class="form-select">
                                    <option value="1">1st Semester</option>
                                    <option value="2">2nd Semester</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Academic Year</label>
                                <input type="text" id="academicYear" class="form-control" placeholder="2025-2026" required>
                            </div>
                        </div>
                    </div>

                    <div id="classStep2" style="display:none;">
                        <h6 class="text-uppercase text-muted mb-2" style="font-size: 0.7rem; letter-spacing: 0.05em;">Enroll Students</h6>
                        <p class="text-muted small mb-3">Students from Section Enrollment will be auto-enrolled. Add more students directly here or bulk import from Excel.</p>
                        <div id="studentPreview"></div>
                        
                        <div class="card fade-in mb-3">
                            <div class="card-header">
                                <span><i class="bi bi-file-earmark-excel me-2"></i>Bulk Import from Excel</span>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info small mb-3">
                                    <strong>Excel Format:</strong><br>
                                    Column A: Student No<br>
                                    Column B: Last Name<br>
                                    Column C: First Name<br>
                                    Column D: Middle Initial
                                </div>
                                <div class="input-group">
                                    <input type="file" id="classExcelFile" class="form-control" accept=".xlsx,.xls">
                                    <button class="btn btn-primary" type="button" onclick="importClassExcel()">
                                        <i class="bi bi-upload"></i> Import
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div id="inlineStudentsContainer"></div>
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="addStudentRow()">
                            <i class="bi bi-plus"></i> Add Student
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-outline-secondary" id="backToStep1Btn" onclick="showClassStep(1)">Back</button>
                    <button type="button" class="btn btn-primary" id="nextToStep2Btn" onclick="goToClassStep2()">Next</button>
                    <button type="button" class="btn btn-success" id="createClassBtn" style="display:none;" onclick="saveClass()">Create Class</button>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/vendor/bootstrap.bundle.min.js"></script>
    <script src="assets/vendor/xlsx.full.min.js"></script>
    <script src="assets/js/main.js?v=3"></script>
    <script>
        document.getElementById('mobileToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        });
        loadClasses();
        loadSubjects();

        function toggleSubjectMode() {
            const mode = document.querySelector('input[name="subjectMode"]:checked').value;
            document.getElementById('subjectSelectContainer').style.display = mode === 'select' ? 'block' : 'none';
            document.getElementById('subjectCreateContainer').style.display = mode === 'create' ? 'block' : 'none';
        }

        function showClassStep(step) {
            document.getElementById('classStep1').style.display = step === 1 ? 'block' : 'none';
            document.getElementById('classStep2').style.display = step === 2 ? 'block' : 'none';
            document.getElementById('step1Tab').classList.toggle('active', step === 1);
            document.getElementById('step2Tab').classList.toggle('active', step === 2);
            document.getElementById('step2Tab').disabled = step === 1;
            document.getElementById('backToStep1Btn').style.display = step === 2 ? 'inline-block' : 'none';
            document.getElementById('nextToStep2Btn').style.display = step === 1 ? 'inline-block' : 'none';
            document.getElementById('createClassBtn').style.display = step === 2 ? 'inline-block' : 'none';
        }

        async function goToClassStep2() {
            const courseProgram = document.getElementById('courseProgram').value.trim();
            const yearLevel = parseInt(document.getElementById('yearLevel').value);
            const section = document.getElementById('section').value.trim();
            const academicYear = document.getElementById('academicYear').value.trim();

            if (!courseProgram || !yearLevel || !section || !academicYear) {
                alert('Please fill in all required class fields');
                return;
            }

            const subjectMode = document.querySelector('input[name="subjectMode"]:checked')?.value || 'select';
            if (subjectMode === 'create') {
                const code = document.getElementById('newSubjectCode').value.trim();
                const title = document.getElementById('newSubjectTitle').value.trim();
                if (!code || !title) {
                    alert('Please fill in Subject Code and Title');
                    return;
                }
            } else {
                let subjectId = parseInt(document.getElementById('subjectSelect').value);
                if (!subjectId) {
                    alert('Please select or create a subject');
                    return;
                }
            }

            document.getElementById('step2Tab').disabled = false;
            const preview = document.getElementById('studentPreview');
            preview.innerHTML = '<div class="alert alert-info mb-3"><i class="bi bi-info-circle me-2"></i>Students from Section Enrollment (if any) will be auto-enrolled when the class is created. Add additional students below.</div>';
            showClassStep(2);
        }

        function addStudentRow() {
            const container = document.getElementById('inlineStudentsContainer');
            const row = document.createElement('div');
            row.className = 'row g-2 mb-2';
            row.innerHTML = `
                <div class="col-md-3">
                    <input type="text" class="form-control form-control-sm" name="inlineStudentNo" placeholder="Student No">
                </div>
                <div class="col-md-3">
                    <input type="text" class="form-control form-control-sm" name="inlineLastName" placeholder="Last Name">
                </div>
                <div class="col-md-3">
                    <input type="text" class="form-control form-control-sm" name="inlineFirstName" placeholder="First Name">
                </div>
                <div class="col-md-2">
                    <input type="text" class="form-control form-control-sm" name="inlineMiddleInitial" placeholder="MI">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.row').remove()">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            `;
            container.appendChild(row);
        }

        function importClassExcel() {
            const file = document.getElementById('classExcelFile').files[0];
            if (!file) { alert('Please select a file'); return; }

            const fileName = file.name.toLowerCase();
            if (!fileName.endsWith('.xlsx') && !fileName.endsWith('.xls')) {
                alert('Please upload an Excel file only (.xlsx or .xls).');
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const workbook = XLSX.read(e.target.result, {type: 'binary'});
                    const sheet = workbook.Sheets[workbook.SheetNames[0]];
                    const rows = XLSX.utils.sheet_to_json(sheet, {header: 1});

                    const container = document.getElementById('inlineStudentsContainer');
                    let count = 0;
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

                        if (!studentNo || !lastName || !firstName) continue;

                        const rowEl = document.createElement('div');
                        rowEl.className = 'row g-2 mb-2';
                        rowEl.innerHTML = `
                            <div class="col-md-3">
                                <input type="text" class="form-control form-control-sm" name="inlineStudentNo" value="${studentNo}">
                            </div>
                            <div class="col-md-3">
                                <input type="text" class="form-control form-control-sm" name="inlineLastName" value="${lastName}">
                            </div>
                            <div class="col-md-3">
                                <input type="text" class="form-control form-control-sm" name="inlineFirstName" value="${firstName}">
                            </div>
                            <div class="col-md-2">
                                <input type="text" class="form-control form-control-sm" name="inlineMiddleInitial" value="${middleInitial}">
                            </div>
                            <div class="col-md-1 d-flex align-items-end">
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.row').remove()">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        `;
                        container.appendChild(rowEl);
                        count++;
                    }

                    alert('Imported ' + count + ' students from Excel');
                    document.getElementById('classExcelFile').value = '';
                } catch (err) {
                    alert('Error reading Excel file: ' + err.message);
                }
            };
            reader.readAsBinaryString(file);
        }

        function resetNewClassModal() {
            document.getElementById('subjectModeSelect').checked = true;
            toggleSubjectMode();
            document.getElementById('newSubjectCode').value = '';
            document.getElementById('newSubjectTitle').value = '';
            document.getElementById('newSubjectUnits').value = '3';
            document.getElementById('courseProgram').value = '';
            document.getElementById('yearLevel').value = '';
            document.getElementById('section').value = '';
            document.getElementById('semester').value = '1';
            document.getElementById('academicYear').value = '';
            document.getElementById('inlineStudentsContainer').innerHTML = '';
            document.getElementById('studentPreview').innerHTML = '';
            document.getElementById('classExcelFile').value = '';
            showClassStep(1);
        }

        var newClassModalEl = document.getElementById('newClassModal');
        if (newClassModalEl) {
        newClassModalEl.addEventListener('hidden.bs.modal', resetNewClassModal);
        }
    </script>
</body>
</html>




