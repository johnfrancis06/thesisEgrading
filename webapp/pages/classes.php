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
                <h1 class="topbar-title">Classes</h1>
            </div>
            <div class="topbar-right">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newClassModal">
                    <i class="bi bi-plus"></i> New Class
                </button>
                <button class="btn btn-success ms-2" data-bs-toggle="modal" data-bs-target="#bulkEnrollModal">
                    <i class="bi bi-people me-1"></i> Bulk Enroll
                </button>
                <button class="btn btn-outline-primary ms-2" onclick="toggleSubjectsSection()">
                    <i class="bi bi-book me-1"></i> Manage Subjects
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
        </div>
    </div>
    
    <!-- Subjects Management Section -->
    <div id="subjectsSection" style="display: none;">
        <div class="card fade-in mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-book me-2"></i>Subject Catalog</span>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#subjectModal" onclick="showAddSubjectModal()">
                    <i class="bi bi-plus"></i> Add Subject
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-container">
                    <table class="table table-hover mb-0">
                        <thead>
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
    
    <div class="modal fade" id="newClassModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Class</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Subject</label>
                        <select id="subjectSelect" class="form-select" required>
                            <option>Loading...</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Course / Program</label>
                        <input type="text" id="courseProgram" class="form-control" placeholder="BSIT" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Year Level</label>
                                <input type="number" id="yearLevel" class="form-control" min="1" max="4" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Section</label>
                                <input type="text" id="section" class="form-control" placeholder="A" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Semester</label>
                                <select id="semester" class="form-select">
                                    <option value="1">1st Semester</option>
                                    <option value="2">2nd Semester</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">Academic Year</label>
                                <input type="text" id="academicYear" class="form-control" placeholder="2025-2026" required>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Auto-enrollment enabled.</strong> Students registered in Section Enrollment will be automatically added to this class.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="saveClass()">Create Class</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- No Students Modal -->
    <div class="modal fade" id="noStudentsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-people me-2"></i>Assign Section to Class</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">This class has no enrolled students. Only students from the same year level can be assigned.</p>
                    
                    <div class="alert alert-warning mb-3" id="classYearInfo">
                        <i class="bi bi-shield-check me-2"></i>
                        <span id="classYearLabel">Loading class info...</span>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-8">
                            <label class="form-label">Section</label>
                            <select id="modalSectionSelect" class="form-select">
                                <option value="">Select Section</option>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button class="btn btn-success w-100" onclick="addSectionToClass()">
                                <i class="bi bi-people me-1"></i> Assign Entire Section
                            </button>
                        </div>
                    </div>
                    
                    <div class="alert alert-info" id="sectionInfo">
                        <i class="bi bi-info-circle me-2"></i>
                        Select a section to see how many students will be assigned.
                    </div>
                    
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bulk Enroll Modal -->
    <div class="modal fade" id="bulkEnrollModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-people me-2"></i>Bulk Enroll Section to Subjects</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Enroll all students from a year/section/program into multiple compatible subjects at once.</p>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label">Course / Program</label>
                            <input type="text" id="bulkProgram" class="form-control" placeholder="BSIT" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Year Level</label>
                            <select id="bulkYear" class="form-select">
                                <option value="1">1st Year</option>
                                <option value="2">2nd Year</option>
                                <option value="3">3rd Year</option>
                                <option value="4">4th Year</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Section</label>
                            <input type="text" id="bulkSection" class="form-control" placeholder="A" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Academic Year</label>
                            <input type="text" id="bulkAY" class="form-control" placeholder="2025-2026" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Semester</label>
                            <select id="bulkSemester" class="form-select">
                                <option value="1">1st Semester</option>
                                <option value="2">2nd Semester</option>
                            </select>
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button class="btn btn-primary w-100" onclick="findCompatibleClasses()">
                                <i class="bi bi-search"></i> Find
                            </button>
                        </div>
                    </div>
                    
                    <div id="compatibleClassesContainer" style="display:none;">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0">Compatible Subjects</h6>
                            <div>
                                <button class="btn btn-sm btn-outline-primary" onclick="selectAllClasses(true)">Select All</button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="selectAllClasses(false)">Deselect All</button>
                            </div>
                        </div>
                        <div class="table-container" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-hover mb-0">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th style="width: 40px;"><input type="checkbox" id="selectAllCheckbox" onchange="toggleAllClasses(this.checked)"></th>
                                        <th>Subject</th>
                                        <th>Class Section</th>
                                        <th>Semester</th>
                                        <th>AY</th>
                                        <th>Current Students</th>
                                    </tr>
                                </thead>
                                <tbody id="compatibleClassesList">
                                    <tr><td colspan="6" class="text-center py-4 text-muted">Click "Find" to see compatible subjects</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3" id="bulkEnrollInfo" style="display:none;">
                        <i class="bi bi-info-circle me-2"></i>
                        <span id="bulkEnrollInfoText">Select subjects to enroll.</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success" id="bulkEnrollBtn" onclick="bulkEnrollSection()" disabled>
                        <i class="bi bi-people me-1"></i> Enroll Selected Subjects
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Subject Modal -->
    <div class="modal fade" id="subjectModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="subjectModalTitle">Add New Subject</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="subjectId" value="">
                    <div class="form-group">
                        <label class="form-label">Subject Code</label>
                        <input type="text" id="subjectCode" class="form-control" placeholder="GE 104" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Subject Title</label>
                        <input type="text" id="subjectTitle" class="form-control" placeholder="Math in the Modern World" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Default Units</label>
                        <input type="number" id="defaultUnits" class="form-control" value="3" min="1">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="saveSubjectBtn" onclick="saveSubject()">Add Subject</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="assets/vendor/bootstrap.bundle.min.js"></script>
    <script src="assets/js/main.js"></script>
    <script>
        document.getElementById('mobileToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        });
        loadClasses();
        loadSubjects();
    </script>
    <script>
        async function addSectionToClass() {
            const section = document.getElementById('modalSectionSelect').value;
            const classId = document.getElementById('noStudentsModal').dataset.classId;
            const classYearLevel = document.getElementById('noStudentsModal').dataset.classYearLevel;
            
            if (!classYearLevel) {
                alert('Error: Class year level not found. Please refresh and try again.');
                return;
            }
            
            if (!section) {
                alert('Please select a Section');
                return;
            }
            
            if (!confirm(`Assign all Year ${classYearLevel} Section ${section} students to this class?`)) {
                return;
            }
            
            const resp = await fetch('api/index.php?action=add_section_to_class', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ class_id: classId, year_level: classYearLevel, section: section })
            });
            const data = await resp.json();
            
            if (data.success) {
                alert(data.message || 'Section assigned successfully!');
                bootstrap.Modal.getInstance(document.getElementById('noStudentsModal')).hide();
                loadClasses();
            } else {
                alert('Error: ' + (data.message || 'Failed to assign section'));
            }
        }
    </script>
    <script>
        async function findCompatibleClasses() {
            const program = document.getElementById('bulkProgram').value.trim();
            const year = document.getElementById('bulkYear').value;
            const section = document.getElementById('bulkSection').value.trim();
            const ay = document.getElementById('bulkAY').value.trim();
            const semester = document.getElementById('bulkSemester').value;
            
            if (!program || !year || !section || !ay) {
                alert('Please fill in Program, Year, Section, and Academic Year');
                return;
            }
            
            const params = new URLSearchParams({
                program: program,
                year: year,
                section: section,
                ay: ay,
                semester: semester
            });
            
            const resp = await fetch(`api/index.php?action=get_compatible_classes&${params}`);
            const data = await resp.json();
            
            const tbody = document.getElementById('compatibleClassesList');
            const container = document.getElementById('compatibleClassesContainer');
            const info = document.getElementById('bulkEnrollInfo');
            const infoText = document.getElementById('bulkEnrollInfoText');
            const enrollBtn = document.getElementById('bulkEnrollBtn');
            
            if (data.success && data.data.length > 0) {
                tbody.innerHTML = data.data.map(cls => `
                    <tr>
                        <td><input type="checkbox" class="class-checkbox" value="${cls.id}" checked></td>
                        <td><strong>${cls.code}</strong><br><small class="text-muted">${cls.title}</small></td>
                        <td>${cls.course_program} ${cls.year_level}-${cls.section}</td>
                        <td>${cls.semester == 1 ? '1st' : '2nd'} Semester</td>
                        <td>${cls.academic_year}</td>
                        <td><span class="badge bg-secondary">${cls.student_count || 0}</span></td>
                    </tr>
                `).join('');
                container.style.display = 'block';
                document.querySelectorAll('.class-checkbox').forEach(cb => {
                    cb.addEventListener('change', updateBulkEnrollInfo);
                });
                info.style.display = 'block';
                infoText.textContent = `${data.data.length} compatible subjects found. Select the ones you want to enroll.`;
                enrollBtn.disabled = false;
                document.getElementById('selectAllCheckbox').checked = true;
            } else {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">No compatible subjects found for this section</td></tr>';
                container.style.display = 'block';
                info.style.display = 'none';
                enrollBtn.disabled = true;
            }
        }
        
        function toggleAllClasses(checked) {
            document.querySelectorAll('.class-checkbox').forEach(cb => cb.checked = checked);
            updateBulkEnrollInfo();
        }
        
        function selectAllClasses(checked) {
            document.querySelectorAll('.class-checkbox').forEach(cb => cb.checked = checked);
            document.getElementById('selectAllCheckbox').checked = checked;
            updateBulkEnrollInfo();
        }
        
        function updateBulkEnrollInfo() {
            const checked = document.querySelectorAll('.class-checkbox:checked');
            const infoText = document.getElementById('bulkEnrollInfoText');
            const enrollBtn = document.getElementById('bulkEnrollBtn');
            infoText.textContent = `${checked.length} subject(s) selected for enrollment.`;
            enrollBtn.disabled = checked.length === 0;
        }
        
        async function bulkEnrollSection() {
            const program = document.getElementById('bulkProgram').value.trim();
            const year = document.getElementById('bulkYear').value;
            const section = document.getElementById('bulkSection').value.trim();
            const ay = document.getElementById('bulkAY').value.trim();
            
            const checkedBoxes = document.querySelectorAll('.class-checkbox:checked');
            const classIds = Array.from(checkedBoxes).map(cb => parseInt(cb.value));
            
            if (classIds.length === 0) {
                alert('Please select at least one subject');
                return;
            }
            
            if (!confirm(`Enroll all Year ${year} ${program}-${section} students into ${classIds.length} subject(s)?`)) {
                return;
            }
            
            const resp = await fetch('api/index.php?action=add_section_to_multiple_classes', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    program: program,
                    year_level: parseInt(year),
                    section: section,
                    academic_year: ay,
                    class_ids: classIds
                })
            });
            const data = await resp.json();
            
            if (data.success) {
                let message = data.message + '\n\n';
                message += 'Per-subject breakdown:\n';
                data.data.results.forEach(r => {
                    if (r.error) {
                        message += `• ${r.code}: ERROR - ${r.error}\n`;
                    } else {
                        message += `• ${r.code}: +${r.added} students`;
                        if (r.skipped > 0) message += ` (${r.skipped} skipped)`;
                        message += '\n';
                    }
                });
                alert(message);
                bootstrap.Modal.getInstance(document.getElementById('bulkEnrollModal')).hide();
                loadClasses();
            } else {
                alert('Error: ' + (data.message || 'Failed to enroll section'));
            }
        }
    </script>

    <script>
        // Subject CRUD functions
        function toggleSubjectsSection() {
            const section = document.getElementById('subjectsSection');
            if (section.style.display === 'none') {
                section.style.display = 'block';
                loadSubjectsTable();
            } else {
                section.style.display = 'none';
            }
        }
        
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
            document.getElementById('subjectModalTitle').textContent = 'Add New Subject';
            document.getElementById('saveSubjectBtn').textContent = 'Add Subject';
        }
        
        async function saveSubject() {
            const id = document.getElementById('subjectId').value;
            const data = {
                code: document.getElementById('subjectCode').value,
                title: document.getElementById('subjectTitle').value,
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
                    bootstrap.Modal.getInstance(document.getElementById('subjectModal')).hide();
                    loadSubjectsTable();
                    loadSubjects();
                } else {
                    alert('Error: ' + (json.message || 'Failed to save subject'));
                }
            } catch (e) {
                alert('Error: ' + e.message);
            }
        }
        
        async function editSubject(id, code, title, units) {
            document.getElementById('subjectId').value = id;
            document.getElementById('subjectCode').value = code;
            document.getElementById('subjectTitle').value = title;
            document.getElementById('defaultUnits').value = units;
            document.getElementById('subjectModalTitle').textContent = 'Edit Subject';
            document.getElementById('saveSubjectBtn').textContent = 'Update Subject';
            new bootstrap.Modal(document.getElementById('subjectModal')).show();
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






