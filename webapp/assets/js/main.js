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
                <a href="index.php?page=students&id=${cls.id}" class="btn btn-sm btn-info">
                    <i class="bi bi-people"></i> Students
                </a>
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
    const courseProgram = document.getElementById('courseProgram').value.trim();
    const yearLevelEl = document.getElementById('yearLevel');
    const yearLevel = parseInt(yearLevelEl.value);
    const section = document.getElementById('section').value.trim();
    const semester = parseInt(document.getElementById('semester').value);
    const academicYear = document.getElementById('academicYear').value.trim();

    if (!courseProgram || !yearLevel || !section || !academicYear) {
        alert('Please fill in all required class fields');
        return;
    }

    let subjectId = parseInt(document.getElementById('subjectSelect').value);
    const subjectMode = document.querySelector('input[name="subjectMode"]:checked')?.value || 'select';

    if (subjectMode === 'create') {
        const code = document.getElementById('newSubjectCode').value.trim();
        const title = document.getElementById('newSubjectTitle').value.trim();
        const units = parseInt(document.getElementById('newSubjectUnits').value);
        if (!code || !title) {
            alert('Please fill in Subject Code and Title');
            return;
        }
        try {
            const resp = await API.call('create_subject', 'POST', { code, title, default_units: units });
            if (!resp.success) {
                alert('Error creating subject: ' + (resp.message || 'Failed'));
                return;
            }
            subjectId = resp.data.id;
        } catch (e) {
            alert('Error creating subject: ' + e.message);
            return;
        }
    }

    if (!subjectId) {
        alert('Please select or create a subject');
        return;
    }

    // Collect inline student entries
    const inlineStudents = [];
    document.querySelectorAll('#inlineStudentsContainer .row').forEach(function(row) {
        const studentNo = row.querySelector('[name="inlineStudentNo"]').value.trim();
        const lastName = row.querySelector('[name="inlineLastName"]').value.trim();
        const firstName = row.querySelector('[name="inlineFirstName"]').value.trim();
        const middleInitial = row.querySelector('[name="inlineMiddleInitial"]').value.trim();
        if (studentNo || lastName || firstName) {
            inlineStudents.push({
                student_no: studentNo, last_name: lastName, first_name: firstName, middle_initial: middleInitial
            });
        }
    });

    const data = {
        subject_id: subjectId,
        course_program: courseProgram,
        year_level: yearLevel,
        section: section,
        semester: semester,
        academic_year: academicYear,
        students: inlineStudents
    };

    const resp = await API.call('create_class_with_students', 'POST', data);
    if (resp.success) {
        alert(resp.message || 'Class created successfully!');
        bootstrap.Modal.getInstance(document.getElementById('newClassModal')).hide();
        loadClasses();
        loadSubjects();
    } else {
        alert('Error creating class: ' + (resp.message || 'Unknown error'));
    }
}

// Subjects Management
async function loadSubjects() {
    try {
        const data = await API.call('get_subjects');
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

function cancelEditSubject() {
    document.getElementById('subjectId').value = '';
    document.getElementById('subjectCode').value = '';
    document.getElementById('subjectTitle').value = '';
    document.getElementById('defaultUnits').value = '3';
    document.getElementById('subjectModalTitle').textContent = 'Add New Subject';
    document.getElementById('saveSubjectBtn').textContent = 'Add Subject';
    const cancelGroup = document.getElementById('cancelEditGroup');
    if (cancelGroup) cancelGroup.style.display = 'none';
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


