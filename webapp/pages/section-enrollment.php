<?php
$db = Database::getInstance()->getConnection();
$faculty_id = $auth->getFacultyId();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Enrollment - <?= APP_NAME ?></title>
    <link href="assets/vendor/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css?v=8">
    <script src="assets/vendor/xlsx.full.min.js"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <button class="mobile-toggle" id="mobileToggle">
                    <i class="bi bi-list"></i>
                </button>
                <h1 class="topbar-title">Student Enrollment</h1>
            </div>
            <div class="topbar-right">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#registerStudentModal">
                    <i class="bi bi-person-plus"></i> Register Student
                </button>
            </div>
        </div>
        
        <div class="content-area">
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
        </div>
    </div>
    
    <div class="modal fade" id="registerStudentModal">
        <div class="modal-dialog modal-lg modal-right-shifted">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Register Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="enrollmentForm" onsubmit="event.preventDefault(); addStudentToSection();">
                        <h6 class="text-uppercase text-muted mb-3" style="font-size: 0.75rem; letter-spacing: 0.08em; font-weight: 600;">Section Information</h6>
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
                        <div class="mb-4">
                            <label class="form-label" for="ayInput">Academic Year <span class="text-danger">*</span></label>
                            <input type="text" id="ayInput" class="form-control" placeholder="2025-2026" required>
                        </div>
                        
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
                                    <button type="submit" class="btn btn-success" id="submitStudentBtn">
                                        <i class="bi bi-plus-circle me-1"></i> Add Student
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
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="importExcel()">Import</button>
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

        document.getElementById('registerStudentModal').addEventListener('hide.bs.modal', function () {
            if (document.activeElement && document.activeElement !== document.body) {
                document.activeElement.blur();
            }
        });
        
        let editingStudentId = null;

        function cancelEdit() {
            editingStudentId = null;
            document.getElementById('enrollmentForm').reset();
            document.getElementById('submitStudentBtn').innerHTML = '<i class="bi bi-plus-circle me-1"></i> Add Student';
            document.getElementById('submitStudentBtn').className = 'btn btn-success';
            document.getElementById('cancelEditBtn').style.display = 'none';
        }
        
        async function addStudentToSection() {
            const program = document.getElementById('programInput').value.trim();
            const year = document.getElementById('yearInput').value;
            const section = document.getElementById('sectionInput').value.trim();
            const ay = document.getElementById('ayInput').value.trim();
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
                student_no: studentNo, last_name: lastName, first_name: firstName, middle_initial: mi
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
                alert(editingStudentId !== null ? 'Student updated successfully' : 'Student added successfully');
            } else {
                alert(data.message || 'Failed to save student');
            }
        }

        function editStudent(id, studentNo, lastName, firstName, mi, program, year, section, ay) {
            editingStudentId = id;
            document.getElementById('programInput').value = program;
            document.getElementById('yearInput').value = year;
            document.getElementById('sectionInput').value = section;
            document.getElementById('ayInput').value = ay;
            document.getElementById('studentNoInput').value = studentNo;
            document.getElementById('lastNameInput').value = lastName;
            document.getElementById('firstNameInput').value = firstName;
            document.getElementById('miInput').value = mi;
            document.getElementById('submitStudentBtn').innerHTML = '<i class="bi bi-pencil-square me-1"></i> Update Student';
            document.getElementById('submitStudentBtn').className = 'btn btn-primary';
            document.getElementById('cancelEditBtn').style.display = 'inline-block';
            window.scrollTo({ top: 0, behavior: 'smooth' });
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
                                    student_no: studentNo, last_name: lastName, first_name: firstName, middle_initial: middleInitial
                                })
                            });
                            const data = await resp.json();
                            if (data.success) {
                                if (data.data && data.data.enrolled_in_classes > 0) count++;
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
                    html += '<button class="btn btn-sm btn-warning" onclick="editStudent(' + s.id + ', \'' + s.student_no + '\', \'' + s.last_name + '\', \'' + s.first_name + '\', \'' + s.middle_initial + '\', \'' + program + '\', ' + year + ', \'' + section + '\', \'' + ay + '\')">';
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
    </script>
</body>
</html>



