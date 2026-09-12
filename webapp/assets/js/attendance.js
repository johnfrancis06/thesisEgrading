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

async function loadAttendanceGrid(classId, year, month) {
    currentClassId = classId;
    currentYear = year;
    currentMonth = month;
    
    try {
        const [studentsResp, configResp, weekdaysResp] = await Promise.all([
            fetch(`api/index.php?action=get_students&class_id=${classId}`),
            fetch(`api/index.php?action=get_attendance_config&class_id=${classId}`),
            fetch(`api/index.php?action=get_attendance_weekdays&class_id=${classId}`)
        ]);
        
        let studentsData, configData, weekdaysData;
        try {
            studentsData = await studentsResp.json();
            configData = await configResp.json();
            weekdaysData = await weekdaysResp.json();
        } catch (parseError) {
            document.getElementById('attendanceGridBody').innerHTML = `<tr><td colspan="32" class="text-center py-3 text-danger">Server error.</td></tr>`;
            return;
        }
        
        if (!studentsData.success) return;
        
        students = studentsData.data;
        attendanceConfig = configData.success ? configData.data : { attendance_late_counts_present: false };
        attendanceWeekdays = weekdaysData.success && weekdaysData.data.weekdays ? weekdaysData.data.weekdays : [1, 2, 3, 4, 5];
        
        // Generate all dates for the selected month
        const daysInMonth = new Date(year, month, 0).getDate();
        const datesInMonth = [];
        for (let day = 1; day <= daysInMonth; day++) {
            const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            datesInMonth.push(dateStr);
        }
        
        const filteredDates = datesInMonth.filter(dateStr => {
            const jsDay = new Date(dateStr + 'T00:00:00').getDay();
            const dbDay = jsDay === 0 ? 7 : jsDay;
            return attendanceWeekdays.includes(dbDay);
        });
        
        // Auto-create sessions for dates that don't exist yet
        await autoCreateSessions(classId, filteredDates);
        
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
        
        renderMonthlySheet(daysInMonth, datesInMonth, sessionsByDate, students, recordsMap);
    } catch (e) {
        console.error('Error loading attendance grid:', e);
        document.getElementById('attendanceGridBody').innerHTML = `<tr><td colspan="32" class="text-center py-3 text-danger">Error: ${e.message}</td></tr>`;
    }
}

function renderMonthlySheet(daysInMonth, datesInMonth, sessionsByDate, students, recordsMap) {
    const thead = document.getElementById('attendanceGridHead');
    const tbody = document.getElementById('attendanceGridBody');
    const tfoot = document.getElementById('attendanceGridFoot');
    
    // Build header rows
    let headerHtml = '<tr><th class="col-student">STUDENT NAME</th><th class="col-roll">ROLL NO.</th>';
    
    for (let day = 1; day <= daysInMonth; day++) {
        const jsDay = new Date(datesInMonth[day - 1] + 'T00:00:00').getDay();
        const dayLetter = jsDay === 0 ? 'S' : jsDay === 1 ? 'M' : jsDay === 2 ? 'T' : jsDay === 3 ? 'W' : jsDay === 4 ? 'T' : jsDay === 5 ? 'F' : 'S';
        const isWeekend = jsDay === 0;
        const weekendClass = isWeekend ? 'col-weekend' : '';
        
        headerHtml += `<th class="col-day ${weekendClass}" data-day="${day}" data-date="${datesInMonth[day - 1]}">
            <div class="day-header-day">${dayLetter}</div>
            <div class="day-header-date">${day}</div>
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
        
        // Day cells
        for (let day = 1; day <= daysInMonth; day++) {
            const dateStr = datesInMonth[day - 1];
            const jsDay = new Date(dateStr + 'T00:00:00').getDay();
            const isWeekend = jsDay === 0;
            const weekendClass = isWeekend ? 'col-weekend' : '';
            
            const session = sessionsByDate[dateStr];
            const sessionId = session ? session.id : 0;
            const key = `${sessionId}_${student ? student.id : 0}`;
            const record = recordsMap[key];
            const status = record ? record.status : 'absent';
            
            bodyHtml += `<td class="col-day ${weekendClass}" data-date="${dateStr}" data-session-id="${sessionId}" data-student-id="${student ? student.id : 0}" data-record-id="${record ? record.id : ''}" data-status="${status}">
                <span class="status-mark ${status}">${getStatusSymbol(status)}</span>
            </td>`;
        }
        
        bodyHtml += '</tr>';
    }
    tbody.innerHTML = bodyHtml;
    
    // Update info bar
    updateInfoBar(students, recordsMap, daysInMonth);
    
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
    return symbols[status] || 'A';
}

function updateInfoBar(students, recordsMap, daysInMonth) {
    let presentCount = 0;
    let absentCount = 0;
    
    students.forEach(student => {
        for (let day = 1; day <= daysInMonth; day++) {
            const dateStr = `${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const session = sessions.find(s => s.date === dateStr);
            if (session) {
                const key = `${session.id}_${student.id}`;
                const record = recordsMap[key];
                if (record) {
                    if (record.status === 'present') presentCount++;
                    else if (record.status === 'absent') absentCount++;
                } else {
                    absentCount++;
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
    
    const currentStatus = cell.dataset.status || 'absent';
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
        { value: 'excused', label: 'Excused', symbol: 'O', class: 'excused' }
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

document.getElementById('mobileToggle')?.addEventListener('click', function() {
    document.getElementById('sidebar').classList.toggle('show');
    document.getElementById('sidebarOverlay').classList.toggle('show');
});
