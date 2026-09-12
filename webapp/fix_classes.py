#!/usr/bin/env python3
import os

file_path = r"C:\xampp\htdocs\egradin thesis\webapp\pages\classes.php"
with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# Normalize line endings
content = content.replace('\r\n', '\n')

# 1. Add Manage Subjects button
old1 = '<button class="btn btn-success ms-2" data-bs-toggle="modal" data-bs-target="#bulkEnrollModal">\n                    <i class="bi bi-people me-1"></i> Bulk Enroll\n                </button>'
new1 = '<button class="btn btn-success ms-2" data-bs-toggle="modal" data-bs-target="#bulkEnrollModal">\n                    <i class="bi bi-people me-1"></i> Bulk Enroll\n                </button>\n                <button class="btn btn-outline-primary ms-2" onclick="toggleSubjectsSection()">\n                    <i class="bi bi-book me-1"></i> Manage Subjects\n                </button>'

if old1 in content:
    content = content.replace(old1, new1)
    print("Added Manage Subjects button")
else:
    print("FAIL: Could not find Bulk Enroll button")

# 2. Add subjects section
old2 = '            </div>\n        </div>\n    </div>\n    \n    <div class="modal fade" id="newClassModal">'
new2 = '''            </div>
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
    
    <div class="modal fade" id="newClassModal">'''

if 'id="newClassModal"' in content:
    content = content.replace(old2, new2)
    print("Added subjects section")
else:
    print("FAIL: Could not find newClassModal")

# 3. Replace the bottom script block
old3 = '''    <script src="assets/vendor/bootstrap.bundle.min.js"></script>
    <script src="assets/js/main.js"></script>
    <script>
        document.getElementById('mobileToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        });
        loadClasses();
        loadSubjects();
    </script>'''

new3 = '''    <script src="assets/vendor/bootstrap.bundle.min.js"></script>
    <script src="assets/js/main.js"></script>
    <script>
        document.getElementById('mobileToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        });
        loadClasses();
        loadSubjects();
        
        let editingSubjectId = null;
        
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
            editingSubjectId = null;
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
            editingSubjectId = id;
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
    </script>'''

if 'loadClasses();' in content and 'loadSubjects();' in content:
    content = content.replace(old3, new3)
    print("Updated script block")
else:
    print("FAIL: Could not find original script")

# 4. Add subject modal before </body>
old4 = '    <script src="assets/vendor/bootstrap.bundle.min.js"></script>'
new4 = '''    <!-- Subject Modal -->
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
    
    <script src="assets/vendor/bootstrap.bundle.min.js"></script>'''

if 'bootstrap.bundle.min.js' in content:
    content = content.replace(old4, new4, 1)
    print("Added subject modal")
else:
    print("FAIL: Could not find bootstrap.bundle.min.js")

# Restore CRLF and write back
content = content.replace('\n', '\r\n')
with open(file_path, 'w', encoding='utf-8') as f:
    f.write(content)

print("Done updating classes.php")
