// Attendance Functions
let currentClassId = null;
let attendanceData = [];
let students = [];
let sessions = [];
let attendanceConfig = { attendance_late_counts_present: false };
let attendanceWeekdays = [1, 2, 3, 4, 5];
let activeMenuCell = null;
let currentMonth = null;
let currentYear = null;

function getClassDates(year, month) {
    const daysInMonth = new Date(year, month, 0).getDate();
    const dates = [];
    for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const jsDay = new Date(dateStr + 'T00:00:00').getDay();
        const dbDay = jsDay === 0 ? 7 : jsDay;
        if (attendanceWeekdays.includes(dbDay)) {
            dates.push({ day, dateStr, jsDay });
        }
    }
    return dates;
}

async function loadAttendanceGrid(classId, year, month, presetWeekdays = null) {
    currentClassId = classId;
    currentYear = year;
    currentMonth = month;
    
    try {
        const [studentsResp, configResp, weekdaysResp] = await Promise.all([
            fetch(`api/index.php?action=get_students&class_id=${classId}&_=${Date.now()}`),
            fetch(`api/index.php?action=get_attendance_config&class_id=${classId}&_=${Date.now()}`),
            presetWeekdays ? Promise.resolve({ success: true, data: { weekdays: presetWeekdays }, json: () => Promise.resolve({ success: true, data: { weekdays: presetWeekdays } }) }) : fetch(`api/index.php?action=get_attendance_weekdays&class_id=${classId}&_=${Date.now()}`)
        ]);
        
        let studentsData, configData, weekdaysData;
        try {
            studentsData = await studentsResp.json();
            configData = await configResp.json();
            weekdaysData = await weekdaysResp.json();
        } catch (parseError) {
            console.error('JSON parse error in loadAttendanceGrid:', parseError);
            document.getElementById('attendanceGridBody').innerHTML = `<tr><td colspan="32" class="text-center py-3 text-danger">Server error. Check console.</td></tr>`;
            return;
        }
        
        if (!studentsData.success) return;
        
        students = studentsData.data;
        attendanceConfig = configData.success ? configData.data : { attendance_late_counts_present: false };
        attendanceWeekdays = weekdaysData.success && weekdaysData.data.weekdays ? weekdaysData.data.weekdays : [1, 2, 3, 4, 5];
        
        // Generate class dates for the selected month (only selected weekdays)
        const classDates = getClassDates(year, month);
        
        // Auto-create sessions for dates that don't exist yet
        const filteredDateStrs = classDates.map(d => d.dateStr);
        await autoCreateSessions(classId, filteredDateStrs);
        
        // Fetch all sessions for this month
        const sessionsResp = await fetch(`api/index.php?action=get_attendance_sessions&class_id=${classId}`);
        const sessionsData = await sessionsResp.json();
        
        if (!sessionsData.success) return;
        
        const allSessions = sessionsData.data;
        const monthSessions = allSessions.filter(s => {
            const d = new Date(s.date + 'T00:00:00');
            return d.getFullYear() === year && d.getMonth() + 1 === month;
        });
        
        sessions = monthSessions;
        const sessionsByDate = {};
        monthSessions.forEach(s => { sessionsByDate[s.date] = s; });
        
        // Fetch all attendance records for this month
        const recordsPromises = monthSessions.map(s => 
            fetch(`api/index.php?action=get_attendance_records&session_id=${s.id}`).then(r => r.json())
        );
        const recordsResponses = await Promise.all(recordsPromises);
        
        const recordsMap = {};
        attendanceData = [];
        
        recordsResponses.forEach((data, idx) => {
            if (data.success && data.data) {
                data.data.forEach(r => {
                    const key = `${monthSessions[idx].id}_${r.student_id}`;
                    recordsMap[key] = r;
                    attendanceData.push({
                        ...r,
                        attendance_session_id: monthSessions[idx].id
                    });
                });
            }
        });
        
        renderMonthlySheet(classDates, sessionsByDate, students, recordsMap);
    } catch (e) {
        console.error('Error loading attendance grid:', e);
        document.getElementById('attendanceGridBody').innerHTML = `<tr><td colspan="32" class="text-center py-3 text-danger">Error: ${e.message}</td></tr>`;
    }
}

function renderMonthlySheet(classDates, sessionsByDate, students, recordsMap) {
    const thead = document.getElementById('attendanceGridHead');
    const tbody = document.getElementById('attendanceGridBody');
    const tfoot = document.getElementById('attendanceGridFoot');
    
    // Build header rows - only class dates
    let headerHtml = '<tr><th class="col-student">STUDENT NAME</th><th class="col-roll">ROLL NO.</th>';
    
    for (const d of classDates) {
        const dayLetter = d.jsDay === 0 ? 'S' : d.jsDay === 1 ? 'M' : d.jsDay === 2 ? 'T' : d.jsDay === 3 ? 'W' : d.jsDay === 4 ? 'T' : d.jsDay === 5 ? 'F' : 'S';
        const isWeekend = d.jsDay === 0 || d.jsDay === 6;
        const weekendClass = isWeekend ? 'col-weekend' : '';
        
        headerHtml += `<th class="col-day ${weekendClass}" data-day="${d.day}" data-date="${d.dateStr}">
            <div class="day-header-day">${dayLetter}</div>
            <div class="day-header-date">${d.day}</div>
        </th>`;
    }
    headerHtml += '</tr>';
    thead.innerHTML = headerHtml;
    
    // Build body rows - 20 student rows
    let bodyHtml = '';
    const displayStudents = students.slice(0, 20);
    
    for (let i = 0; i < 20; i++) {
        const student = displayStudents[i];
        bodyHtml += `<tr data-student-id="${student ? student.id : 0}">`;
        
        if (student) {
            bodyHtml += `
                <td class="col-student">${student.last_name}, ${student.first_name}</td>
                <td class="col-roll">${student.student_no}</td>
            `;
        } else {
            bodyHtml += `
                <td class="col-student" style="color:#94a3b8; font-style:italic;">WRITE HERE</td>
                <td class="col-roll">&nbsp;</td>
            `;
        }
        
        // Day cells - only class dates
        for (const d of classDates) {
            const dateStr = d.dateStr;
            const isWeekend = d.jsDay === 0 || d.jsDay === 6;
            const weekendClass = isWeekend ? 'col-weekend' : '';
            
            const session = sessionsByDate[dateStr];
            const sessionId = session ? session.id : 0;
            const key = `${sessionId}_${student ? student.id : 0}`;
            const record = recordsMap[key];
            const status = record ? record.status : '';
            
            bodyHtml += `<td class="col-day ${weekendClass}" data-date="${dateStr}" data-session-id="${sessionId}" data-student-id="${student ? student.id : 0}" data-record-id="${record ? record.id : ''}" data-status="${status}">
                <span class="status-mark ${status}">${getStatusSymbol(status)}</span>
            </td>`;
        }
        
        bodyHtml += '</tr>';
    }
    tbody.innerHTML = bodyHtml;
    
    // Update info bar
    updateInfoBar(students, recordsMap, classDates);
    
    // Attach click handlers
    tbody.addEventListener('click', function(e) {
        const cell = e.target.closest('td.col-day');
        if (!cell) return;
        showStatusMenu(cell, e);
    });
}

function getStatusSymbol(status) {
    const symbols = {
        present: 'P',
        absent: 'A',
        late: 'L',
        excused: 'E'
    };
    return symbols[status] || '';
}

function updateInfoBar(students, recordsMap, classDates) {
    let presentCount = 0;
    let absentCount = 0;
    
    students.forEach(student => {
        for (const d of classDates) {
            const dateStr = d.dateStr;
            const session = sessions.find(s => s.date === dateStr);
            if (session) {
                const key = `${session.id}_${student.id}`;
                const record = recordsMap[key];
                if (record) {
                    if (record.status === 'present') presentCount++;
                    else if (record.status === 'absent') absentCount++;
                }
            }
        }
    });
    
    document.getElementById('totalPresent').textContent = presentCount;
    document.getElementById('totalAbsent').textContent = absentCount;
    
    const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    document.getElementById('infoMonthYear').textContent = `${monthNames[currentMonth - 1]} ${currentYear}`;
}

async function autoCreateSessions(classId, dates) {
    const existingDates = new Set();
    const sessionsResp = await fetch(`api/index.php?action=get_attendance_sessions&class_id=${classId}`);
    const sessionsData = await sessionsResp.json();
    
    if (sessionsData.success) {
        sessionsData.data.forEach(s => {
            if (s.date) existingDates.add(s.date);
        });
    }
    
    const missingDates = dates.filter(d => !existingDates.has(d));
    if (missingDates.length === 0) return;
    
    const promises = missingDates.map(date => 
        fetch('api/index.php?action=create_attendance_session', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ class_id: classId, date: date, label: '' })
        })
    );
    
    await Promise.all(promises);
}

function changeAttendanceMonth() {
    const year = parseInt(document.getElementById('yearFilter').value);
    const month = parseInt(document.getElementById('monthFilter').value);
    loadAttendanceGrid(currentClassId, year, month);
}

async function showStatusMenu(cell, event) {
    const menu = document.getElementById('statusMenu');
    activeMenuCell = cell;
    
    const currentStatus = cell.dataset.status || '';
    const date = cell.dataset.date;
    const sessionId = parseInt(cell.dataset.sessionId);
    const studentId = parseInt(cell.dataset.studentId);
    const recordId = cell.dataset.recordId;
    
    // If no session exists for this date, create one first
    if (!sessionId) {
        try {
            const resp = await fetch('api/index.php?action=create_attendance_session', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ class_id: currentClassId, date: date, label: '' })
            });
            const data = await resp.json();
            if (data.success && data.data && data.data.id) {
                cell.dataset.sessionId = data.data.id;
            }
        } catch (err) {
            console.error('Failed to create session:', err);
            return;
        }
    }
    
    const statuses = [
        { value: 'present', label: 'Present', symbol: 'P', class: 'present' },
        { value: 'absent', label: 'Absent', symbol: 'A', class: 'absent' },
        { value: 'late', label: 'Late', symbol: 'L', class: 'late' },
        { value: 'excused', label: 'Excused', symbol: 'E', class: 'excused' },
        { value: '', label: 'Unmarked', symbol: '', class: 'unmarked' }
    ];
    
    let menuHtml = '<div class="status-menu-inner">';
    statuses.forEach(s => {
        const isActive = s.value === currentStatus;
        menuHtml += `
            <div class="status-menu-item ${s.class} ${isActive ? 'active' : ''}" data-status="${s.value}">
                <span class="status-menu-icon">${s.symbol}</span>
                <span class="status-menu-label">${s.label}</span>
            </div>
        `;
    });
    menuHtml += '</div>';
    
    menu.innerHTML = menuHtml;
    menu.style.display = 'block';
    
    const rect = cell.getBoundingClientRect();
    let left = rect.left + (rect.width / 2) - 70;
    let top = rect.bottom + 8;
    
    if (left < 10) left = 10;
    if (left + 150 > window.innerWidth) left = window.innerWidth - 160;
    if (top + 200 > window.innerHeight) top = rect.top - 200;
    
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
    
    menu.querySelectorAll('.status-menu-item').forEach(item => {
        item.addEventListener('click', function(e) {
            e.stopPropagation();
            const newStatus = this.dataset.status;
            selectStatus(newStatus, cell);
            menu.style.display = 'none';
            activeMenuCell = null;
        });
    });
}

async function selectStatus(status, cell) {
    cell.dataset.status = status;
    cell.innerHTML = `<span class="status-mark ${status}">${getStatusSymbol(status)}</span>`;
    
    const sessionId = parseInt(cell.dataset.sessionId);
    const studentId = parseInt(cell.dataset.studentId);
    const recordId = cell.dataset.recordId;
    
    if (sessionId && studentId) {
        try {
            if (recordId) {
                await fetch('api/index.php?action=save_attendance', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        record_id: recordId,
                        status: status,
                        remarks: ''
                    })
                });
            } else {
                await fetch('api/index.php?action=save_attendance', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        session_id: sessionId,
                        student_id: studentId,
                        status: status,
                        remarks: ''
                    })
                });
            }
        } catch (err) {
            console.error('Save failed:', err);
        }
    }
    
    // Refresh grid
    loadAttendanceGrid(currentClassId, currentYear, currentMonth);
}

function exportAttendance(classId, format) {
    window.open(`api/index.php?action=export_attendance&class_id=${classId}&format=${format}`);
}

class AttendanceHelper {
    static getSessionSummary(sessionId) {
        const sessionRecords = attendanceData.filter(r => r.attendance_session_id == sessionId);
        return {
            total: sessionRecords.length,
            present: sessionRecords.filter(r => r.status === 'present').length,
            absent: sessionRecords.filter(r => r.status === 'absent').length,
            late: sessionRecords.filter(r => r.status === 'late').length,
            excused: sessionRecords.filter(r => r.status === 'excused').length
        };
    }
}

async function showAttendanceConfig() {
    const wrapper = document.getElementById('configModalWrapper');
    
    try {
        const resp = await fetch(`api/index.php?action=get_attendance_weekdays&class_id=${currentClassId}&_=${Date.now()}`);
        const text = await resp.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('JSON parse error in showAttendanceConfig:', e, 'Response:', text);
            attendanceWeekdays = [1, 2, 3, 4, 5];
        }
        attendanceWeekdays = data.success && data.data.weekdays ? data.data.weekdays : [1, 2, 3, 4, 5];
    } catch (e) {
        console.error('Error fetching weekdays:', e);
        attendanceWeekdays = [1, 2, 3, 4, 5];
    }

    const days = [
        { value: 1, label: 'Monday', short: 'Mon' },
        { value: 2, label: 'Tuesday', short: 'Tue' },
        { value: 3, label: 'Wednesday', short: 'Wed' },
        { value: 4, label: 'Thursday', short: 'Thu' },
        { value: 5, label: 'Friday', short: 'Fri' },
        { value: 6, label: 'Saturday', short: 'Sat' },
        { value: 7, label: 'Sunday', short: 'Sun' }
    ];

    let modalHtml = `
        <div class="modal fade" id="attendanceConfigModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-calendar-week me-2"></i>Attendance Schedule Settings</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-3">Select which days of the week this class meets. Attendance sessions will only be created for enabled days.</p>
                        <div class="row g-2" id="weekdayToggles">
                            ${days.map(d => `
                                <div class="col-6 col-md-4 col-lg-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="weekday_${d.value}" value="${d.value}" ${attendanceWeekdays.includes(d.value) ? 'checked' : ''}>
                                        <label class="form-check-label d-flex align-items-center gap-2" for="weekday_${d.value}">
                                            <span class="day-badge ${attendanceWeekdays.includes(d.value) ? 'bg-primary' : 'bg-secondary'}">${d.short}</span>
                                            <span>${d.label}</span>
                                        </label>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="saveAttendanceWeekdays()">
                            <i class="bi bi-check me-1"></i> Save Schedule
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;

    wrapper.innerHTML = modalHtml;
    const modal = new bootstrap.Modal(document.getElementById('attendanceConfigModal'));
    modal.show();

    document.getElementById('attendanceConfigModal').addEventListener('change', function(e) {
        if (e.target.type === 'checkbox' && e.target.id.startsWith('weekday_')) {
            const badge = e.target.nextElementSibling.querySelector('.day-badge');
            if (e.target.checked) {
                badge.classList.remove('bg-secondary');
                badge.classList.add('bg-primary');
            } else {
                badge.classList.remove('bg-primary');
                badge.classList.add('bg-secondary');
            }
        }
    });
}

async function saveAttendanceWeekdays() {
    const selected = [];
    document.querySelectorAll('#weekdayToggles input[type="checkbox"]:checked').forEach(cb => {
        selected.push(parseInt(cb.value));
    });

    if (selected.length === 0) {
        alert('Please select at least one day.');
        return;
    }

    const saveBtn = document.querySelector('#attendanceConfigModal .btn-primary');
    try {
        const resp = await fetch('api/index.php?action=update_attendance_weekdays', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ class_id: currentClassId, weekdays: selected })
        });
        const text = await resp.text();
        console.log('Raw response:', text);
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('JSON parse error:', e, 'Response:', text);
            alert('Server returned invalid response. Check console.');
            return;
        }
        console.log('Parsed response:', data);
        if (data.success) {
            attendanceWeekdays = selected;
            if (saveBtn) saveBtn.blur();
            bootstrap.Modal.getInstance(document.getElementById('attendanceConfigModal')).hide();
            loadAttendanceGrid(currentClassId, currentYear, currentMonth, selected);
        } else {
            alert('Error: ' + (data.message || 'Failed to update'));
        }
    } catch (e) {
        console.error('Error saving weekdays:', e);
        alert('Error: ' + e.message);
    }
}

document.getElementById('mobileToggle')?.addEventListener('click', function() {
    document.getElementById('sidebar').classList.toggle('show');
    document.getElementById('sidebarOverlay').classList.toggle('show');
});

async function showAbsentChecker() {
    const wrapper = document.getElementById('configModalWrapper');
    
    let modalHtml = `
        <div class="modal fade" id="absentCheckerModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Absent Checker</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <select class="form-select form-select-sm" id="absentView" onchange="loadAbsentReport()">
                                    <option value="month">This Month</option>
                                    <option value="week">This Week</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select class="form-select form-select-sm" id="absentYearFilter">
                                    ${(() => {
                                        let opts = '';
                                        for (let y = currentYear; y >= currentYear - 2; y--) {
                                            opts += `<option value="${y}" ${y === currentYear ? 'selected' : ''}>${y}</option>`;
                                        }
                                        return opts;
                                    })()}
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select class="form-select form-select-sm" id="absentMonthFilter" onchange="loadAbsentReport()">
                                    ${(() => {
                                        const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                                        return months.map((m, i) => `<option value="${i+1}" ${i+1 === currentMonth ? 'selected' : ''}>${m}</option>`).join('');
                                    })()}
                                </select>
                            </div>
                        </div>
                        
                        <div id="absentSummary" class="row mb-3"></div>
                        
                        <div class="table-responsive">
                            <table class="table table-sm table-hover" id="absentTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Student No.</th>
                                        <th>Name</th>
                                        <th>Total Sessions</th>
                                        <th>Present</th>
                                        <th class="text-danger">Absent</th>
                                        <th>Late</th>
                                        <th>Excused</th>
                                        <th>Max Consecutive Absent</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="absentTableBody">
                                    <tr><td colspan="10" class="text-center py-3">Loading...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    `;

    wrapper.innerHTML = modalHtml;
    const modal = new bootstrap.Modal(document.getElementById('absentCheckerModal'));
    
    // Fix aria-hidden focus issue
    document.getElementById('absentCheckerModal').addEventListener('hide.bs.modal', function() {
        const closeBtn = this.querySelector('.btn-close');
        if (closeBtn) closeBtn.blur();
    });
    
    modal.show();
    
    loadAbsentReport();
}

async function loadAbsentReport() {
    const view = document.getElementById('absentView').value;
    const year = parseInt(document.getElementById('absentYearFilter').value);
    const month = parseInt(document.getElementById('absentMonthFilter').value);
    
    try {
        const resp = await fetch(`api/index.php?action=get_absent_report&class_id=${currentClassId}&year=${year}&month=${month}&view=${view}&_=${Date.now()}`);
        const text = await resp.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('JSON parse error:', e, 'Response:', text);
            document.getElementById('absentTableBody').innerHTML = '<tr><td colspan="10" class="text-center py-3 text-danger">Error loading report</td></tr>';
            return;
        }
        
        if (!data.success) {
            document.getElementById('absentTableBody').innerHTML = '<tr><td colspan="10" class="text-center py-3 text-danger">Failed to load report</td></tr>';
            return;
        }
        
        renderAbsentReport(data.data.report, data.data.summary);
    } catch (e) {
        console.error('Error loading absent report:', e);
        document.getElementById('absentTableBody').innerHTML = `<tr><td colspan="10" class="text-center py-3 text-danger">Error: ${e.message}</td></tr>`;
    }
}

function renderAbsentReport(report, summary) {
    const tbody = document.getElementById('absentTableBody');
    const summaryDiv = document.getElementById('absentSummary');
    
    // Summary cards
    summaryDiv.innerHTML = `
        <div class="col-md-3">
            <div class="card bg-light border-0">
                <div class="card-body text-center">
                    <h6 class="text-muted mb-1">Total Students</h6>
                    <h3 class="mb-0">${summary.total_students}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white border-0">
                <div class="card-body text-center">
                    <h6 class="text-white-50 mb-1">At Risk (≥3 Consecutive)</h6>
                    <h3 class="mb-0">${summary.at_risk}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white border-0">
                <div class="card-body text-center">
                    <h6 class="text-white-50 mb-1">Sessions in Period</h6>
                    <h3 class="mb-0">${summary.total_sessions}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-secondary text-white border-0">
                <div class="card-body text-center">
                    <h6 class="text-white-50 mb-1">Period</h6>
                    <h6 class="mb-0">${summary.period}</h6>
                </div>
            </div>
        </div>
    `;
    
    if (!report || report.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" class="text-center py-3 text-muted">No students found</td></tr>';
        return;
    }
    
    let html = '';
    report.forEach(student => {
        const isAtRisk = student.should_drop;
        const rowClass = isAtRisk ? 'table-danger' : '';
        
        html += `
            <tr class="${rowClass}">
                <td>${student.student_no}</td>
                <td>${student.name}</td>
                <td>${student.total_sessions}</td>
                <td class="text-success">${student.present}</td>
                <td class="text-danger fw-bold">${student.absent}</td>
                <td class="text-warning">${student.late}</td>
                <td class="text-info">${student.excused}</td>
                <td class="${student.max_consecutive_absent >= 3 ? 'fw-bold text-danger' : ''}">${student.max_consecutive_absent}</td>
                <td>
                    ${isAtRisk 
                        ? '<span class="badge bg-danger">DROP RECOMMENDED</span>' 
                        : (student.max_consecutive_absent >= 2 ? '<span class="badge bg-warning">At Risk</span>' : '<span class="badge bg-success">OK</span>')}
                </td>
                <td>
                    ${isAtRisk 
                        ? `<button class="btn btn-sm btn-danger" onclick="dropStudent(${student.student_id}, '${student.name.replace(/'/g, "\\'")}')">
                            <i class="bi bi-person-x"></i> Drop
                           </button>`
                        : `<button class="btn btn-sm btn-outline-secondary" disabled>No Action</button>`}
                </td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
}

async function dropStudent(studentId, studentName) {
    if (!confirm(`Drop ${studentName} due to 3+ consecutive absences?`)) return;
    
    try {
        const resp = await fetch('api/index.php?action=drop_student', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ student_id: studentId, class_id: currentClassId, reason: '3+ consecutive absences' })
        });
        const data = await resp.json();
        if (data.success) {
            alert('Student dropped successfully');
            loadAbsentReport();
            loadAttendanceGrid(currentClassId, currentYear, currentMonth);
        } else {
            alert('Error: ' + (data.message || 'Failed to drop student'));
        }
    } catch (e) {
        console.error('Error dropping student:', e);
        alert('Error: ' + e.message);
    }
}
