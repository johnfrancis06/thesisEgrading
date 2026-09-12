// API Helper
const API = {
    async call(action, method = 'GET', data = null) {
        const url = `api/index.php?action=${action}`;
        const options = { method, headers: { 'Content-Type': 'application/json' } };
        if (data) options.body = JSON.stringify(data);
        
        try {
            const resp = await fetch(url, options);
            const json = await resp.json();
            if (!json.success && resp.status >= 400) throw new Error(json.message);
            return json;
        } catch (e) {
            alert('Error: ' + e.message);
            throw e;
        }
    }
};

// Modal helper functions will be added after loadClasses

async function checkAndViewStudents(classId) {
    try {
        const resp = await fetch(`api/index.php?action=check_class_students&class_id=${classId}`);
        const data = await resp.json();
        
        if (data.success && data.data && data.data.has_students) {
            window.location.href = `index.php?page=students`;
        } else {
            document.getElementById('noStudentsModal').dataset.classId = classId;
            const modal = document.getElementById('noStudentsModal');
            modal.dataset.classYearLevel = data.data.class_year_level || '';
            modal.dataset.classSection = data.data.class_section || '';
            modal.dataset.classProgram = data.data.class_program || '';

            // Update class year label
            const yearLabel = document.getElementById('classYearLabel');
            if (yearLabel && data.data.class_year_level) {
                yearLabel.textContent = data.data.class_year_level + ' Year';
            }

            new bootstrap.Modal(modal).show();
            loadEnrolledSectionsForModal();
        }
    } catch (e) {
        alert('Error checking students: ' + e.message);
    }
}

async function loadEnrolledSectionsForModal() {
    const resp = await fetch('api/index.php?action=get_enrolled_sections');
    const data = await resp.json();
    
    if (data.success && data.data.length > 0) {
        const sectionSelect = document.getElementById('modalSectionSelect');
        const modal = document.getElementById('noStudentsModal');
        const classYearLevel = parseInt(modal.dataset.classYearLevel);
        
        sectionSelect.innerHTML = '<option value="">Select Section</option>';
        
        // Filter sections by class year level only
        if (classYearLevel) {
            window.modalSections = data.data.filter(s => s.year_level == classYearLevel);
        } else {
            window.modalSections = data.data;
        }
        
        updateModalSections();
    }
}

function updateModalSections() {
    const sectionSelect = document.getElementById('modalSectionSelect');
    
    sectionSelect.innerHTML = '<option value="">Select Section</option>';
    
    if (!window.modalSections || window.modalSections.length === 0) {
        const option = document.createElement('option');
        option.value = "";
        option.textContent = 'No sections available';
        option.disabled = true;
        sectionSelect.appendChild(option);
        return;
    }
    
    const sections = [...new Set(window.modalSections.map(s => s.section))];
    sections.sort().forEach(section => {
        const option = document.createElement('option');
        option.value = section;
        option.textContent = 'Section ' + section;
        sectionSelect.appendChild(option);
    });
}

// Classes Management
async function loadClasses() {
    const data = await API.call('get_classes');
    if (!data.success) return;
    
    const html = data.data.map(cls => `
        <tr>
            <td>${cls.code}</td>
            <td>${cls.course_program} ${cls.year_level}-${cls.section}</td>
            <td>${cls.semester}</td>
            <td>${cls.academic_year}</td>
            <td>
                <a href="index.php?page=grading&id=${cls.id}" class="btn btn-sm btn-primary">
                    <i class="bi bi-pencil"></i> Grade
                </a>
                <button onclick="checkAndViewStudents(${cls.id})" class="btn btn-sm btn-info">
                    <i class="bi bi-people"></i> Students
                </button>
                <a href="index.php?page=attendance&id=${cls.id}" class="btn btn-sm btn-warning">
                    <i class="bi bi-calendar"></i> Attend
                </a>
                <button onclick="editClass(${cls.id})" class="btn btn-sm btn-warning">
                    <i class="bi bi-pencil-square"></i> Edit
                </button>
                <button onclick="deleteClass(${cls.id})" class="btn btn-sm btn-danger">
                    <i class="bi bi-trash"></i> Delete
                </button>
            </td>
        </tr>
    `).join('');
    
    document.getElementById('classesList').innerHTML = html || '<tr><td colspan="5" class="text-center">No classes found</td></tr>';
}

async function saveClass() {
    const data = {
        subject_id: parseInt(document.getElementById('subjectSelect').value),
        course_program: document.getElementById('courseProgram').value,
        year_level: parseInt(document.getElementById('yearLevel').value),
        section: document.getElementById('section').value,
        semester: parseInt(document.getElementById('semester').value),
        academic_year: document.getElementById('academicYear').value
    };
    
    // Always use auto-enrollment - students come from section enrollment
    const resp = await API.call('create_class_with_students', 'POST', data);
    if (resp.success) {
        alert(resp.message || 'Class created successfully!');
        bootstrap.Modal.getInstance(document.getElementById('newClassModal')).hide();
        loadClasses();
        loadSubjects();
    }
}

// Subjects Management
async function loadSubjects() {
    try {
        const resp = await fetch('api/index.php?action=get_subjects&t=' + Date.now() + '');
        const data = await resp.json();
        if (!data.success) return;
        
        const select = document.getElementById('subjectSelect');
        if (select) {
            select.innerHTML = data.data.map(s => `<option value="${s.id}">${s.code} - ${s.title}</option>`).join('');
        }
        
        const list = document.getElementById('subjectsList');
        if (list) {
            list.innerHTML = data.data.map(s => `
                <div class="col-md-4 mb-3">
                    <div class="card">
                        <div class="card-body">
                            <h5>${s.code}</h5>
                            <p class="text-muted">${s.title}</p>
                            <small>Units: ${s.default_units}</small>
                            <div class="mt-2">
                                <button onclick="editSubject(${s.id})" class="btn btn-sm btn-warning me-1">
                                    <i class="bi bi-pencil-square"></i> Edit
                                </button>
                                <button onclick="deleteSubject(${s.id})" class="btn btn-sm btn-danger">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `).join('');
        }
    } catch (e) {
        console.error('Error loading subjects:', e);
    }
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
            bootstrap.Modal.getInstance(document.getElementById('newSubjectModal')).hide();
            cancelEditSubject();
            loadSubjects();
        } else {
            alert('Error: ' + (json.message || 'Failed to save subject'));
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Delete Subject
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
            loadSubjects();
        } else {
            alert('Error: ' + json.message);
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Edit Subject
async function editSubject(id) {
    try {
        const resp = await fetch('api/index.php?action=get_subjects&t=' + Date.now() + '');
        const data = await resp.json();
        if (!data.success || !data.data) return;
        
        const subject = data.data.find(s => s.id === id);
        if (!subject) return;
        
        document.getElementById('subjectId').value = subject.id;
        document.getElementById('subjectCode').value = subject.code;
        document.getElementById('subjectTitle').value = subject.title;
        document.getElementById('defaultUnits').value = subject.default_units;
        
        document.getElementById('subjectModalTitle').textContent = 'Edit Subject';
        document.getElementById('saveSubjectBtn').textContent = 'Update Subject';
        document.getElementById('cancelEditGroup').style.display = 'block';
        
        new bootstrap.Modal(document.getElementById('newSubjectModal')).show();
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Delete Class
async function deleteClass(id) {
    if (!confirm('Are you sure you want to delete this class?')) return;
    
    try {
        const resp = await fetch('api/index.php?action=delete_class', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const json = await resp.json();
        if (json.success) {
            alert('Class deleted!');
            loadClasses();
        } else {
            alert('Error: ' + json.message);
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Edit Class
async function editClass(id) {
    alert('Edit functionality coming soon!');
}


