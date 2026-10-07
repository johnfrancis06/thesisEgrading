// Attendance Functions
let currentClassId = null;
let attendanceData = [];
let students = [];
let sessions = [];
let attendanceConfig = { attendance_late_counts_present: false };
// Last data the Absent Checker rendered, so Print does not refetch it.
let absentReportCache = null;
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
    let headerHtml = '<tr><th class="col-student">STUDENT NAME</th>';

    for (const d of classDates) {
        const dayLetter = d.jsDay === 0 ? 'S' : d.jsDay === 1 ? 'M' : d.jsDay === 2 ? 'T' : d.jsDay === 3 ? 'W' : d.jsDay === 4 ? 'T' : d.jsDay === 5 ? 'F' : 'S';
        const isWeekend = d.jsDay === 0 || d.jsDay === 6;
        const session = sessionsByDate[d.dateStr];
        const dayType = session && session.session_type && session.session_type !== 'regular'
            ? session.session_type : '';
        const dayLabel = session && session.label ? session.label : '';

        // A holiday or seminar date gets its own highlight class and a marker, so
        // the column stands out from the ordinary class days.
        const typeClass = dayType ? ` col-${dayType}` : '';
        const weekendClass = isWeekend ? 'col-weekend' : '';
        const typeIcon = dayType === 'holiday' ? 'bi-cake2' : 'bi-mic';

        // Each day carries its own Save button. Pressing it finishes the column:
        // everyone still unmarked becomes present. A holiday or seminar column
        // is locked, so it carries no Save button at all.
        const isCompleted = session ? String(session.is_completed) === '1' : false;
        const completedClass = isCompleted ? ' col-completed' : '';

        headerHtml += `<th class="col-day ${weekendClass}${typeClass}${completedClass}" data-day="${d.day}" data-date="${d.dateStr}"
                       data-day-type="${dayType}" data-completed="${isCompleted ? '1' : '0'}" title="${escapeHtml(dayType ? dayType + ': ' + dayLabel : d.dateStr)}">
            <div class="day-header-day">${dayLetter}</div>
            <div class="day-header-date">${d.day}</div>
            ${dayType ? `<i class="bi ${typeIcon} day-type-icon" aria-hidden="true"></i>` : ''}
            ${!dayType ? `<button type="button" class="day-save-btn ${isCompleted ? 'is-complete' : ''}"
                    data-save-date="${d.dateStr}" data-save-session="${session ? session.id : 0}"
                    title="${isCompleted ? 'Day saved. Click to save again.' : 'Save this day: anyone left unmarked becomes present.'}"
                    aria-label="Save ${d.dateStr}">
                <i class="bi ${isCompleted ? 'bi-check-circle-fill' : 'bi-save'}"></i>
                <span class="day-save-text">${isCompleted ? 'Saved' : 'Save'}</span>
            </button>` : ''}
        </th>`;
    }

    // Right-hand column: each student's own attendance for the month.
    headerHtml += '<th class="col-overall" title="Days attended out of the days met, and the percentage">ATTENDANCE</th>';
    headerHtml += '</tr>';
    thead.innerHTML = headerHtml;

    // Build body rows - 20 student rows
    let bodyHtml = '';
    const displayStudents = students.slice(0, 20);
    // The grid always renders the same number of rows, so a merged cell
    // must span every one of them to keep the columns aligned.
    const gridRowCount = 20;

    // Days the teacher actually met, so every student's percentage is measured
    // against the same denominator the overview panel reports. Holidays and
    // seminars are locked and carry no marks, so they never count.
    const metDates = classDates.filter(d => {
        const session = sessionsByDate[d.dateStr];
        if (!session) return false;
        if (session.session_type && session.session_type !== 'regular') return false;
        return students.some(student => {
            const record = recordsMap[`${session.id}_${student.id}`];
            return record && record.status;
        });
    });

    for (let i = 0; i < gridRowCount; i++) {
        const student = displayStudents[i];
        bodyHtml += `<tr data-student-id="${student ? student.id : 0}">`;

        if (student) {
            bodyHtml += `
                <td class="col-student">${escapeHtml(student.last_name)}, ${escapeHtml(student.first_name)}</td>
            `;
        } else {
            bodyHtml += `
                <td class="col-student" style="color:#94a3b8; font-style:italic;">WRITE HERE</td>
            `;
        }

        // Day cells - only class dates
        for (const d of classDates) {
            const dateStr = d.dateStr;
            const isWeekend = d.jsDay === 0 || d.jsDay === 6;
            const weekendClass = isWeekend ? 'col-weekend' : '';

            const session = sessionsByDate[dateStr];
            const sessionId = session ? session.id : 0;
            const dayType = session && session.session_type && session.session_type !== 'regular'
                ? session.session_type : '';
            const typeClass = dayType ? ` col-${dayType}` : '';

            // A holiday or seminar column is locked: one merged cell runs
            // down the whole grid carrying the day's name vertically, and
            // every row after the first skips the cell entirely.
            if (dayType) {
                if (i === 0) {
                    const dayLabel = session && session.label ? session.label : '';
                    const fallback = dayType === 'holiday' ? 'Holiday' : 'Seminar';
                    bodyHtml += `<td class="col-day ${weekendClass}${typeClass} day-merged" rowspan="${gridRowCount}"
                                     data-date="${dateStr}" data-day-type="${dayType}"
                                     title="${escapeHtml(dayType + (dayLabel ? ': ' + dayLabel : ''))}">
                        <span class="merged-label">${escapeHtml(dayLabel || fallback)}</span>
                    </td>`;
                }
                continue;
            }

            const key = `${sessionId}_${student ? student.id : 0}`;
            const record = recordsMap[key];
            const status = record ? record.status : '';

            bodyHtml += `<td class="col-day ${weekendClass}${typeClass}" data-date="${dateStr}" data-session-id="${sessionId}" data-student-id="${student ? student.id : 0}" data-record-id="${record ? record.id : ''}" data-status="${status}">
                <span class="status-mark ${status}">${getStatusSymbol(status)}</span>
            </td>`;
        }

        // Per-student attendance for the days met.
        bodyHtml += student
            ? `<td class="col-overall">${renderStudentAttendance(student, metDates, sessionsByDate, recordsMap)}</td>`
            : '<td class="col-overall">&nbsp;</td>';

        bodyHtml += '</tr>';
    }
    tbody.innerHTML = bodyHtml;

    // The legend spans every column, including the new attendance column.
    const legendRow = tfoot ? tfoot.querySelector('.legend-row td') : null;
    if (legendRow) legendRow.colSpan = classDates.length + 2;

    // Update info bar
    updateInfoBar(students, recordsMap, classDates);

    // Month overview panel beside the grid
    updateMonthOverview(students, recordsMap, classDates, sessionsByDate);

    // Attach click handlers
    tbody.addEventListener('click', function(e) {
        const cell = e.target.closest('td.col-day');
        // A locked column carries one merged label cell, which is not
        // editable and opens no status menu.
        if (!cell || cell.classList.contains('day-merged')) return;
        showStatusMenu(cell, e);
    });

    // Clicking a date header opens the holiday/seminar editor for that date.
    thead.addEventListener('click', function(e) {
        const saveBtn = e.target.closest('.day-save-btn');
        if (saveBtn) {
            // The Save button sits inside the header, so keep the day-type editor
            // from opening when the button itself is clicked.
            e.stopPropagation();
            e.preventDefault();
            completeAttendanceDay(saveBtn.dataset.saveDate, parseInt(saveBtn.dataset.saveSession));
            return;
        }
        const th = e.target.closest('th.col-day');
        if (!th) return;
        showDayTypeEditor(th.dataset.date);
    });
}

/**
 * Finishes one attendance column. The teacher only marks the absentees, so
 * everyone still unmarked is turned into present before the day is locked.
 */
async function completeAttendanceDay(dateStr, sessionId) {
    if (!dateStr) return;

    const btn = document.querySelector(`.day-save-btn[data-save-date="${dateStr}"]`);
    if (btn) {
        btn.disabled = true;
        btn.classList.add('is-saving');
    }

    try {
        // A day with no session yet has no records to complete, so create it first.
        if (!sessionId) {
            const created = await fetch('api/index.php?action=create_attendance_session', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ class_id: currentClassId, date: dateStr, label: '' })
            }).then(r => r.json());

            if (!created.success || !created.data || !created.data.id) {
                throw new Error(created.message || 'Could not create the day');
            }
            sessionId = created.data.id;
        }

        const resp = await fetch('api/index.php?action=complete_attendance_day', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_id: sessionId, class_id: currentClassId, date: dateStr })
        });
        const data = await resp.json();

        if (!data.success) throw new Error(data.message || 'Failed to save the day');

        await loadAttendanceGrid(currentClassId, currentYear, currentMonth);
        showToast(data.message || 'Day saved', 'success');
    } catch (err) {
        console.error('Error completing day:', err);
        alert('Error: ' + err.message);
        if (btn) {
            btn.disabled = false;
            btn.classList.remove('is-saving');
        }
    }
}

/** Small transient status message, so a save never looks like it did nothing. */
function showToast(message, kind = 'success') {
    let toast = document.getElementById('attendanceToast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'attendanceToast';
        toast.className = 'attendance-toast';
        document.body.appendChild(toast);
    }
    toast.className = 'attendance-toast show ' + kind;
    toast.textContent = message;
    clearTimeout(toast._hideTimer);
    toast._hideTimer = setTimeout(() => { toast.classList.remove('show'); }, 2500);
}

/** Escapes text for safe interpolation into grid markup. */
function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
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
            // Locked days carry no marks, so they never
            // contribute to the month's totals.
            if (!session || (session.session_type && session.session_type !== 'regular')) continue;
            const key = `${session.id}_${student.id}`;
            const record = recordsMap[key];
            if (record) {
                if (record.status === 'present') presentCount++;
                else if (record.status === 'absent') absentCount++;
            }
        }
    });
    
    document.getElementById('totalPresent').textContent = presentCount;
    document.getElementById('totalAbsent').textContent = absentCount;
    
    const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    document.getElementById('infoMonthYear').textContent = `${monthNames[currentMonth - 1]} ${currentYear}`;
}

/**
 * One student's attendance cell: days attended out of the days met, plus the
 * percentage. Whether a late counts as attended follows the same class setting
 * the overview panel uses, so the two figures always agree.
 */
function renderStudentAttendance(student, metDates, sessionsByDate, recordsMap) {
    const lateCounts = !!(attendanceConfig && attendanceConfig.attendance_late_counts_present);

    let attended = 0;
    metDates.forEach(d => {
        const session = sessionsByDate[d.dateStr];
        const record = recordsMap[`${session.id}_${student.id}`];
        const status = record ? record.status : '';
        if (status === 'present') attended++;
        else if (status === 'late' && lateCounts) attended++;
    });

    const met = metDates.length;
    const pct = met > 0 ? (attended / met) * 100 : 0;

    // Colour tracks how the month is going, so a struggling row stands out.
    let level = 'is-none';
    if (met > 0) {
        if (pct >= 90) level = 'is-good';
        else if (pct >= 75) level = 'is-fair';
        else level = 'is-low';
    }

    return `<span class="student-attendance ${level}">
                <span class="sa-days">${attended}/${met}</span>
                <span class="sa-pct">${met > 0 ? pct.toFixed(0) + '%' : '–'}</span>
            </span>`;
}

/**
 * Fills the panel beside the grid with the selected month's totals.
 *
 * "Total Meets" counts the days the teacher actually met, meaning days that
 * already carry at least one attendance mark. The percentage only divides by
 * days that have been marked, so an unfinished day never drags the figure down.
 */
function updateMonthOverview(students, recordsMap, classDates, sessionsByDate) {
    const counts = { present: 0, late: 0, absent: 0, excused: 0, unmarked: 0 };
    const daysWithMarks = new Set();

    students.forEach(student => {
        classDates.forEach(d => {
            const session = sessionsByDate[d.dateStr];
            if (!session) return;
            // Holidays and seminars are locked and hold no marks,
            // so they never reach the totals.
            if (session.session_type && session.session_type !== 'regular') return;

            const record = recordsMap[`${session.id}_${student.id}`];
            const status = record ? record.status : '';
            const key = status || 'unmarked';

            if (counts[key] !== undefined) counts[key]++;
            if (status) daysWithMarks.add(session.id);
        });
    });

    const lateCounts = !!(attendanceConfig && attendanceConfig.attendance_late_counts_present);
    const marked = counts.present + counts.late + counts.absent + counts.excused;
    const attended = counts.present + (lateCounts ? counts.late : 0);
    const percent = marked > 0 ? (attended / marked) * 100 : 0;

    let daysSaved = 0;
    classDates.forEach(d => {
        const session = sessionsByDate[d.dateStr];
        if (!session) return;
        if (session.session_type && session.session_type !== 'regular') return;
        if (String(session.is_completed) === '1') daysSaved++;
    });

    const set = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    };

    set('ovTotalMeets', daysWithMarks.size);
    set('ovDaysRecorded', attended);
    set('ovPercent', `${percent.toFixed(1)}%`);
    set('ovCountPresent', counts.present);
    set('ovCountLate', counts.late);
    set('ovCountAbsent', counts.absent);
    set('ovCountExcused', counts.excused);
    set('ovCountUnmarked', counts.unmarked);
    set('ovDaysSaved', daysSaved);

    // The same figures, condensed, for the minimised rail.
    set('ovMiniPercent', `${percent.toFixed(0)}%`);
    set('ovMiniMeets', daysWithMarks.size);
    set('ovMiniAttended', attended);
    set('ovMiniSaved', daysSaved);

    // Proportional bar over the marked entries only, so an unmarked day does not
    // show up as a gap in the distribution.
    const share = value => (marked > 0 ? (value / marked) * 100 : 0);
    set('ovSegPresent', share(counts.present));
    set('ovSegLate', share(counts.late));
    set('ovSegAbsent', share(counts.absent));
    set('ovSegExcused', share(counts.excused));

    const bar = document.querySelector('.month-overview-bar');
    if (bar) bar.classList.toggle('is-empty', marked === 0);
}

/**
 * Collapses the overview panel into a narrow rail so the grid gets the space
 * back. The choice is remembered between visits.
 */
function toggleMonthOverview(forceState) {
    const panel = document.getElementById('monthOverview');
    const button = document.getElementById('monthOverviewToggle');
    if (!panel || !button) return;

    const minimized = typeof forceState === 'boolean'
        ? forceState
        : !panel.classList.contains('is-minimized');

    panel.classList.toggle('is-minimized', minimized);
    button.setAttribute('aria-expanded', String(!minimized));
    button.title = minimized
        ? 'Expand the overview'
        : 'Minimise the overview to free up space';

    const mini = document.getElementById('monthOverviewMini');
    if (mini) mini.setAttribute('aria-hidden', String(minimized));

    try {
        localStorage.setItem('attendanceOverviewMinimized', minimized ? '1' : '0');
    } catch (e) {
        // Private browsing can refuse storage; the toggle still works for now.
    }
}

/** Restores the saved panel state once the page has loaded. */
function restoreMonthOverviewState() {
    let saved = null;
    try {
        saved = localStorage.getItem('attendanceOverviewMinimized');
    } catch (e) {
        saved = null;
    }
    if (saved === '1') toggleMonthOverview(true);
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

/**
 * Downloads the month currently on screen as a real .xlsx, so the spreadsheet
 * matches the sheet the teacher is marking rather than the whole year.
 */
function exportAttendanceExcel() {
    if (!currentClassId || !currentYear || !currentMonth) return;
    const url = `api/index.php?action=export_attendance&format=xlsx`
        + `&class_id=${currentClassId}&year=${currentYear}&month=${currentMonth}`;
    window.location.href = url;
}

/**
 * Class Record dialog.
 *
 * The printed sheet carries its own header wording and signature names, and those
 * come from settings rather than being hard-coded, so they are edited here. The
 * period split decides which attendance columns land in the 1st Period block and
 * which in the 2nd.
 */
async function showClassRecord() {
    if (!currentClassId) return;
    const wrapper = document.getElementById('configModalWrapper');

    let record = null;
    try {
        const resp = await fetch(`api/index.php?action=get_class_record&class_id=${currentClassId}&_=${Date.now()}`);
        const json = await resp.json();
        if (!json.success) {
            showToast(json.message || 'Could not load the class record', 'danger');
            return;
        }
        record = json.data;
    } catch (e) {
        showToast('Could not load the class record', 'danger');
        return;
    }

    const m = record.meta;
    const periods = record.periods || [];

    const field = (key, label, extra = '') => `
        <div class="col-md-6">
            <label class="form-label small fw-semibold mb-1" for="cr_${key}">${label}</label>
            <input type="text" class="form-control form-control-sm" id="cr_${key}"
                   data-field="${key}" value="${escapeHtml(m[key] || '')}" ${extra}>
        </div>`;

    const modalHtml = `
        <div class="modal fade" id="classRecordModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Print Attendance &mdash; Class Record</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small">
                            The class record prints one column per class date, in two period
                            blocks, with Attendance, Total Meet and Attendance (%) after the last block.
                            ${record.student_count} student${record.student_count === 1 ? '' : 's'} will be listed.
                        </p>

                        <h6 class="fw-bold mb-2"><i class="bi bi-calendar-range me-1"></i>Period &amp; Term</h6>
                        <p class="text-muted small">
                            Choose the months this class record is for. Only attendance
                            records dated inside the range get a column when the sheet
                            is printed or exported; leave a box empty for no bound.
                        </p>
                        <div class="row g-2 mb-2">
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold mb-1" for="cr_cr_from_month">From month</label>
                                <input type="month" class="form-control form-control-sm" id="cr_cr_from_month"
                                       data-field="cr_from_month" value="${escapeHtml(m.cr_from_month || '')}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold mb-1" for="cr_cr_to_month">To month</label>
                                <input type="month" class="form-control form-control-sm" id="cr_cr_to_month"
                                       data-field="cr_to_month" value="${escapeHtml(m.cr_to_month || '')}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold mb-1" for="cr_cr_term_year">Term year</label>
                                <input type="number" class="form-control form-control-sm" id="cr_cr_term_year"
                                       data-field="cr_term_year" min="0" max="2100"
                                       value="${escapeHtml(m.cr_term_year || 0)}">
                                <div class="form-text">0 keeps every year the class has.</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold mb-1" for="cr_cr_period_split">2nd period starts</label>
                                <input type="date" class="form-control form-control-sm" id="cr_cr_period_split"
                                       data-field="cr_period_split" value="${escapeHtml(m.cr_period_split || '')}">
                                <div class="form-text">Dates on or after this move to the 2nd block.</div>
                            </div>
                        </div>
                        <div class="table-responsive mb-4">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr><th>Block</th><th>Date columns</th><th>Class days</th><th>Range</th></tr>
                                </thead>
                                <tbody>
                                    ${periods.map(p => `
                                        <tr>
                                            <td class="align-middle">${escapeHtml(p.label)}</td>
                                            <td class="align-middle">${p.dates}</td>
                                            <td class="align-middle">${p.countable}</td>
                                            <td class="align-middle small text-muted">${escapeHtml(p.first)} &ndash; ${escapeHtml(p.last)}</td>
                                        </tr>`).join('')}
                                </tbody>
                            </table>
                        </div>
                        <p class="text-muted small">
                            Holidays and seminars keep their own column but show the event name and
                            are left out of the attendance figures.
                        </p>

                        <h6 class="fw-bold mb-2"><i class="bi bi-card-heading me-1"></i>Header</h6>
                        <div class="row g-2 mb-4">
                            ${field('course_number', 'Course number')}
                            ${field('course_title', 'Course title')}
                            ${field('semester_term', 'Semester and term')}
                            ${field('course_and_year', 'Course &amp; year')}
                        </div>

                        <h6 class="fw-bold mb-2"><i class="bi bi-pen me-1"></i>Footer names</h6>
                        <p class="text-muted small">
                            Printed in upper case under each signature line. These are the same
                            names the Grade Sheet footer uses.
                        </p>
                        <div class="row g-2 mb-4">
                            ${field('facilitator_name', 'Course Facilitator')}
                            ${field('program_chair', 'Program Chair')}
                            ${field('satellite_director', 'Satellite College Director')}
                        </div>

                        <h6 class="fw-bold mb-2"><i class="bi bi-printer me-1"></i>Paper</h6>
                        <div class="row g-2 mb-2">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold mb-1" for="cr_cr_paper">Paper size</label>
                                <select class="form-select form-select-sm" id="cr_cr_paper" data-field="cr_paper">
                                    <option value="legal"${m.cr_paper === 'legal' ? ' selected' : ''}>Legal (14&quot; &times; 8.5&quot;) &mdash; widest</option>
                                    <option value="a4"${m.cr_paper === 'a4' ? ' selected' : ''}>A4 (297 &times; 210 mm)</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold mb-1">Date headings</label>
                                <select class="form-select form-select-sm" id="cr_cr_rotate_dates" data-field="cr_rotate_dates">
                                    <option value="1"${m.cr_rotate_dates !== '0' ? ' selected' : ''}>Down the column</option>
                                    <option value="0"${m.cr_rotate_dates === '0' ? ' selected' : ''}>Across the column</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold mb-1" for="cr_class_record_note">Note</label>
                            <textarea class="form-control form-control-sm" id="cr_class_record_note"
                                      data-field="class_record_note" rows="2">${escapeHtml(m.class_record_note || '')}</textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-outline-dark" onclick="saveClassRecordSettings()">
                            <i class="bi bi-check me-1"></i> Save Settings
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="openClassRecordPrint()">
                            <i class="bi bi-printer me-1"></i> Print
                        </button>
                        <button type="button" class="btn btn-outline-success" onclick="exportClassRecordExcel()">
                            <i class="bi bi-file-earmark-excel me-1"></i> Download Excel
                        </button>
                        <button type="button" class="btn btn-primary" onclick="exportClassRecordPdf()">
                            <i class="bi bi-file-earmark-pdf me-1"></i> Export to PDF
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;

    wrapper.innerHTML = modalHtml;
    const modal = new bootstrap.Modal(document.getElementById('classRecordModal'));
    modal.show();
}

/** Collects every field in the dialog into the payload the API expects. */
function collectClassRecordFields() {
    const fields = {};
    document.querySelectorAll('#classRecordModal [data-field]').forEach(el => {
        fields[el.dataset.field] = el.value;
    });
    return fields;
}

/** Saves the dialog, then reopens it so the period summary reflects the change. */
async function saveClassRecordSettings() {
    if (!currentClassId) return;
    const btn = document.querySelector('#classRecordModal .btn-outline-dark');
    const original = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving';
    }

    try {
        const resp = await fetch(`api/index.php?action=save_class_record_settings&class_id=${currentClassId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ class_id: currentClassId, fields: collectClassRecordFields() })
        });
        const json = await resp.json();
        if (!json.success) {
            showToast(json.message || 'Could not save the settings', 'danger');
            return;
        }
        showToast('Class record settings saved');

        // A changed term year or split date moves columns between the two blocks,
        // so reopen the dialog to show the new split rather than a stale one.
        bootstrap.Modal.getInstance(document.getElementById('classRecordModal'))?.hide();
        setTimeout(showClassRecord, 300);
    } catch (e) {
        showToast('Could not save the settings', 'danger');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = original;
        }
    }
}

/** Prints with unsaved edits in the dialog, so a typo is not silently discarded. */
async function openClassRecordPrint() {
    if (!currentClassId) return;
    await saveClassRecordSettings();
    window.open(`classrecord_print.php?id=${currentClassId}`, '_blank');
}

async function exportClassRecordPdf() {
    if (!currentClassId) return;
    await saveClassRecordSettings();
    window.location.href = `classrecord_pdf.php?id=${currentClassId}`;
}

/** Same sheet as the printed form, downloaded as a real .xlsx. */
async function exportClassRecordExcel() {
    if (!currentClassId) return;
    await saveClassRecordSettings();
    window.location.href = `classrecord_xlsx.php?id=${currentClassId}`;
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
        const [wdResp, cfgResp] = await Promise.all([
            fetch(`api/index.php?action=get_attendance_weekdays&class_id=${currentClassId}&_=${Date.now()}`),
            fetch(`api/index.php?action=get_attendance_config&class_id=${currentClassId}&_=${Date.now()}`)
        ]);
        const wdData = await wdResp.json();
        const cfgData = await cfgResp.json();

        attendanceWeekdays = wdData.success && wdData.data.weekdays ? wdData.data.weekdays : [1, 2, 3, 4, 5];
        if (cfgData.success && cfgData.data) {
            attendanceConfig = cfgData.data;
        }
    } catch (e) {
        console.error('Error fetching attendance config:', e);
        attendanceWeekdays = [1, 2, 3, 4, 5];
    }

    const markedDays = await getMarkedDays();

    const days = [
        { value: 1, label: 'Monday', short: 'Mon' },
        { value: 2, label: 'Tuesday', short: 'Tue' },
        { value: 3, label: 'Wednesday', short: 'Wed' },
        { value: 4, label: 'Thursday', short: 'Thu' },
        { value: 5, label: 'Friday', short: 'Fri' },
        { value: 6, label: 'Saturday', short: 'Sat' },
        { value: 7, label: 'Sunday', short: 'Sun' }
    ];

    const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    const markedRows = markedDays.length
        ? markedDays.map(d => `
            <tr>
                <td class="align-middle"><strong>${escapeHtml(d.pretty)}</strong></td>
                <td class="align-middle">
                    <select class="form-select form-select-sm day-type-select" data-date="${d.date}">
                        <option value="holiday"${d.session_type === 'holiday' ? ' selected' : ''}>Holiday</option>
                        <option value="seminar"${d.session_type === 'seminar' ? ' selected' : ''}>Seminar</option>
                    </select>
                </td>
                <td class="align-middle">
                    <input type="text" class="form-control form-control-sm day-label-input"
                           data-date="${d.date}" value="${escapeHtml(d.label)}"
                           placeholder="Name of holiday or seminar" maxlength="255">
                </td>
                <td class="align-middle text-end">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-day-btn" data-date="${d.date}"
                            title="Remove"><i class="bi bi-trash"></i></button>
                </td>
            </tr>`).join('')
        : `<tr><td colspan="4" class="text-center text-muted small py-3">No holidays or seminars marked this month.</td></tr>`;

    const modalHtml = `
        <div class="modal fade" id="attendanceConfigModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-calendar-week me-2"></i>Attendance Settings</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <!-- Holidays and seminars -->
                        <h6 class="fw-bold mb-2"><i class="bi bi-mic me-1"></i>Holidays &amp; Seminars</h6>
                        <p class="text-muted small mb-2">
                            Marked dates are highlighted in the grid and show their name in the
                            column header. Currently showing
                            <strong>${escapeHtml(monthNames[currentMonth - 1])} ${currentYear}</strong>.
                        </p>
                        <div class="table-responsive mb-2">
                            <table class="table table-sm align-middle mb-0" id="markedDaysTable">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:30%">Date</th>
                                        <th style="width:25%">Type</th>
                                        <th>Name</th>
                                        <th style="width:60px"></th>
                                    </tr>
                                </thead>
                                <tbody id="markedDaysBody">${markedRows}</tbody>
                            </table>
                        </div>
                        <div class="d-flex gap-2 align-items-end mb-4">
                            <div class="flex-grow-1">
                                <label class="form-label small fw-semibold mb-1" for="newDayDate">Add a date</label>
                                <input type="date" class="form-control form-control-sm" id="newDayDate"
                                       value="${escapeHtml(markedDays.length ? '' : (defaultDaySuggestion()))}">
                            </div>
                            <button type="button" class="btn btn-sm btn-primary" onclick="addMarkedDayRow()">
                                <i class="bi bi-plus-lg me-1"></i>Mark as Holiday
                            </button>
                        </div>

                        <!-- Meeting days -->
                        <h6 class="fw-bold mb-2"><i class="bi bi-calendar3 me-1"></i>Class Meeting Days</h6>
                        <p class="text-muted small mb-2">Attendance sessions are only created for these days.</p>
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
                            <i class="bi bi-check me-1"></i> Save Settings
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
            badge.classList.toggle('bg-primary', e.target.checked);
            badge.classList.toggle('bg-secondary', !e.target.checked);
        }
    });

    // Save the type/name edits and removals immediately, one row at a time.
    document.getElementById('markedDaysBody').addEventListener('change', function(e) {
        const row = e.target.closest('tr');
        if (!row || !e.target.dataset.date) return;
        const type = row.querySelector('.day-type-select').value;
        const label = row.querySelector('.day-label-input').value.trim();
        saveDayType(e.target.dataset.date, type, label);
    });
    document.getElementById('markedDaysBody').addEventListener('click', function(e) {
        const btn = e.target.closest('.remove-day-btn');
        if (!btn) return;
        saveDayType(btn.dataset.date, 'regular', '');
    });
}

/** Suggests today's date, or the 1st of the displayed month when that has passed. */
function defaultDaySuggestion() {
    const now = new Date();
    if (now.getFullYear() === currentYear && now.getMonth() + 1 === currentMonth) {
        return `${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    }
    return `${currentYear}-${String(currentMonth).padStart(2, '0')}-01`;
}

/** Holidays and seminars for the displayed month, as {date, label, session_type}. */
async function getMarkedDays() {
    try {
        const resp = await fetch(`api/index.php?action=get_attendance_sessions&class_id=${currentClassId}&_=${Date.now()}`);
        const data = await resp.json();
        if (!data.success) return [];

        return data.data
            .filter(s => {
                const d = new Date(s.date + 'T00:00:00');
                return d.getFullYear() === currentYear
                    && d.getMonth() + 1 === currentMonth
                    && s.session_type && s.session_type !== 'regular';
            })
            .map(s => ({
                date: s.date,
                label: s.label || '',
                session_type: s.session_type,
                pretty: new Date(s.date + 'T00:00:00').toLocaleDateString(undefined, {
                    weekday: 'short', month: 'short', day: 'numeric', year: 'numeric'
                })
            }))
            .sort((a, b) => a.date.localeCompare(b.date));
    } catch (e) {
        console.error('Error loading marked days:', e);
        return [];
    }
}

/** Persists one date's type and name, then refreshes the grid. */
async function saveDayType(date, type, label) {
    try {
        const resp = await fetch('api/index.php?action=set_attendance_day_type', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ class_id: currentClassId, date: date, session_type: type, label: label })
        });
        const data = await resp.json();
        if (!data.success) {
            alert(data.message || 'Failed to update the date.');
            return;
        }
        await loadAttendanceGrid(currentClassId, currentYear, currentMonth);
    } catch (e) {
        console.error('Error saving day type:', e);
        alert('Error: ' + e.message);
    }
}

/** Adds an empty row to the manager so a new holiday/seminar can be entered. */
function addMarkedDayRow() {
    const input = document.getElementById('newDayDate');
    const date = input ? input.value : '';
    if (!date) {
        alert('Please choose a date first.');
        return;
    }

    const body = document.getElementById('markedDaysBody');
    if (!body) return;

    const placeholder = body.querySelector('td[colspan]');
    if (placeholder) body.innerHTML = '';

    if (body.querySelector(`tr[data-date="${date}"]`)) {
        alert('That date is already listed.');
        return;
    }

    const tr = document.createElement('tr');
    tr.dataset.date = date;
    const pretty = new Date(date + 'T00:00:00').toLocaleDateString(undefined, {
        weekday: 'short', month: 'short', day: 'numeric', year: 'numeric'
    });
    tr.innerHTML = `
        <td class="align-middle"><strong>${escapeHtml(pretty)}</strong></td>
        <td class="align-middle">
            <select class="form-select form-select-sm day-type-select" data-date="${date}">
                <option value="holiday" selected>Holiday</option>
                <option value="seminar">Seminar</option>
            </select>
        </td>
        <td class="align-middle">
            <input type="text" class="form-control form-control-sm day-label-input" data-date="${date}"
                   placeholder="Name of holiday or seminar" maxlength="255">
        </td>
        <td class="align-middle text-end">
            <button type="button" class="btn btn-sm btn-outline-danger remove-day-btn" data-date="${date}" title="Remove">
                <i class="bi bi-trash"></i>
            </button>
        </td>`;
    body.appendChild(tr);

    // Switching the type or typing the name writes it straight away.
    tr.querySelector('.day-type-select').addEventListener('change', () => {
        saveDayType(date, tr.querySelector('.day-type-select').value, tr.querySelector('.day-label-input').value.trim());
    });
    tr.querySelector('.day-label-input').addEventListener('change', () => {
        saveDayType(date, tr.querySelector('.day-type-select').value, tr.querySelector('.day-label-input').value.trim());
    });

    input.value = '';
}

/** Per-date holiday/seminar editor, opened by clicking a column header. */
function showDayTypeEditor(dateStr) {
    if (!dateStr) return;
    const wrapper = document.getElementById('configModalWrapper');
    const session = sessions.find(s => s.date === dateStr);
    const type = session && session.session_type ? session.session_type : 'regular';
    const label = session && session.label ? session.label : '';
    const pretty = new Date(dateStr + 'T00:00:00').toLocaleDateString(undefined, {
        weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'
    });

    wrapper.innerHTML = `
        <div class="modal fade" id="dayTypeModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-calendar-event me-2"></i>${escapeHtml(pretty)}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="dayTypeSelect">This date is</label>
                            <select class="form-select" id="dayTypeSelect">
                                <option value="regular"${type === 'regular' ? ' selected' : ''}>A regular class meeting</option>
                                <option value="holiday"${type === 'holiday' ? ' selected' : ''}>A holiday (no class)</option>
                                <option value="seminar"${type === 'seminar' ? ' selected' : ''}>A seminar or special event</option>
                            </select>
                        </div>
                        <div class="mb-2" id="dayLabelWrap">
                            <label class="form-label fw-semibold" for="dayLabelInput">Holiday or seminar name</label>
                            <input type="text" class="form-control" id="dayLabelInput" maxlength="255"
                                   value="${escapeHtml(label)}" placeholder="e.g. Independence Day, ICT Summit">
                        </div>
                        <p class="text-muted small mb-0" id="dayTypeHint"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="dayTypeSaveBtn">
                            <i class="bi bi-check me-1"></i> Save
                        </button>
                    </div>
                </div>
            </div>
        </div>`;

    const modal = new bootstrap.Modal(document.getElementById('dayTypeModal'));
    modal.show();

    const select = document.getElementById('dayTypeSelect');
    const labelWrap = document.getElementById('dayLabelWrap');
    const hint = document.getElementById('dayTypeHint');

    function syncVisibility() {
        const isSpecial = select.value !== 'regular';
        labelWrap.style.display = isSpecial ? '' : 'none';
        hint.textContent = isSpecial
            ? 'The column is highlighted and the name is shown in its header.'
            : 'Marking this as a regular meeting removes the highlight and the name.';
    }
    select.addEventListener('change', syncVisibility);
    syncVisibility();

    document.getElementById('dayTypeSaveBtn').addEventListener('click', async function() {
        const btn = this;
        btn.disabled = true;
        modal.hide();
        await saveDayType(dateStr, select.value, document.getElementById('dayLabelInput').value.trim());
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
    if (saveBtn) saveBtn.disabled = true;
    try {
        // Meeting days and the late-count rule are independent settings, so they
        // are written by two calls; both are required for the form to apply.
        const [weekdayResp, configResp] = await Promise.all([
            fetch('api/index.php?action=update_attendance_weekdays', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ class_id: currentClassId, weekdays: selected })
            }),
            fetch('api/index.php?action=update_attendance_config', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    class_id: currentClassId,
                    attendance_late_counts_present: attendanceConfig.attendance_late_counts_present ? 1 : 0
                })
            })
        ]);

        const weekdayData = await weekdayResp.json();
        const configData = await configResp.json();

        if (!weekdayData.success || !configData.success) {
            alert('Error: ' + (weekdayData.message || configData.message || 'Failed to update'));
            return;
        }

        attendanceWeekdays = selected;
        attendanceConfig.attendance_late_counts_present = configData.data?.attendance_late_counts_present ?? 0;

        const modal = bootstrap.Modal.getInstance(document.getElementById('attendanceConfigModal'));
        if (modal) modal.hide();
        await loadAttendanceGrid(currentClassId, currentYear, currentMonth, selected);
    } catch (e) {
        console.error('Error saving settings:', e);
        alert('Error: ' + e.message);
    } finally {
        if (saveBtn) saveBtn.disabled = false;
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
                        <button type="button" class="btn btn-outline-dark" id="absentPrintBtn" onclick="printAbsentReport()">
                            <i class="bi bi-printer me-1"></i> Print
                        </button>
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
            absentReportCache = null;
            document.getElementById('absentTableBody').innerHTML = '<tr><td colspan="10" class="text-center py-3 text-danger">Error loading report</td></tr>';
            return;
        }
        
        if (!data.success) {
            absentReportCache = null;
            document.getElementById('absentTableBody').innerHTML = '<tr><td colspan="10" class="text-center py-3 text-danger">Failed to load report</td></tr>';
            return;
        }
        
        renderAbsentReport(data.data.report, data.data.summary);
    } catch (e) {
        console.error('Error loading absent report:', e);
        absentReportCache = null;
        document.getElementById('absentTableBody').innerHTML = `<tr><td colspan="10" class="text-center py-3 text-danger">Error: ${e.message}</td></tr>`;
    }
}

function renderAbsentReport(report, summary) {
    const tbody = document.getElementById('absentTableBody');
    const summaryDiv = document.getElementById('absentSummary');

    // The Print button reopens this data, so hold the last render rather than
    // re-fetching: the filters are already reflected in it.
    absentReportCache = { report: report || [], summary: summary || {} };
    
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
        // Cached as an empty set rather than null, so Print still produces a
        // sheet that states there is nothing to report.
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

/**
 * Prints the Absent Checker report.
 *
 * A self-contained document in a new window rather than printing the page: the
 * report lives in a Bootstrap modal, and printing the host page would carry the
 * sidebar, topbar and on-screen buttons onto the paper. The Drop buttons are
 * deliberately left out - a printed sheet is a record, not a control panel.
 */
function printAbsentReport() {
    const cache = absentReportCache;
    if (!cache) {
        showToast('Load the report first', 'warning');
        return;
    }

    const periodText = cache.summary.period || '';
    const printedOn = new Date().toLocaleString();

    // Only the students the rules actually flag, plus anyone within one absence
    // of the threshold, so the sheet stays short enough to sign.
    const flagged = cache.report.filter(s => (s.max_consecutive_absent || 0) >= 1);

    const rows = (flagged.length ? flagged : cache.report).map(s => {
        const max = s.max_consecutive_absent || 0;
        const status = max >= 3
            ? 'DROP RECOMMENDED'
            : (max === 2 ? 'At Risk' : 'OK');
        const shade = max >= 3 ? 'row-drop' : (max === 2 ? 'row-risk' : '');
        const dates = (s.absent_dates || []).join(', ');
        return `<tr class="${shade}">
            <td class="num">${escapeHtml(s.student_no || '')}</td>
            <td>${escapeHtml(s.name || '')}</td>
            <td class="num">${s.total_sessions ?? 0}</td>
            <td class="num">${s.present ?? 0}</td>
            <td class="num strong">${s.absent ?? 0}</td>
            <td class="num">${s.late ?? 0}</td>
            <td class="num">${s.excused ?? 0}</td>
            <td class="num strong">${max}</td>
            <td class="num">${escapeHtml(status)}</td>
            <td class="dates">${escapeHtml(dates || '')}</td>
        </tr>`;
    }).join('');

    const doc = `<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Absent Checker ${escapeHtml(periodText)}</title>
<style>
    @page { size: landscape; margin: 10mm; }
    body { font-family: 'Times New Roman', Times, serif; color: #000; margin: 0; }
    h1 { font-size: 15pt; text-align: center; margin: 0 0 2mm; text-transform: uppercase; }
    .sub { font-size: 10pt; text-align: center; margin: 0 0 4mm; }
    .meta { width: 100%; border-collapse: collapse; margin-bottom: 3mm; font-size: 10pt; }
    .meta td { border: 1px solid #000; padding: 1mm 1.5mm; }
    .meta .k { width: 18%; background: #f0f0f0; font-weight: bold; }
    table.grid { width: 100%; border-collapse: collapse; font-size: 9pt; }
    table.grid th, table.grid td { border: 1px solid #000; padding: 0.8mm 1mm; }
    table.grid thead th { background: #d9d9d9; text-align: center; font-weight: bold; }
    .num { text-align: center; }
    .strong { font-weight: bold; }
    .dates { font-size: 8pt; }
    tr.row-risk td { background: #fff8e1; }
    tr.row-drop td { background: #fdecea; }
    .sign { width: 100%; border-collapse: collapse; margin-top: 8mm; font-size: 10pt; }
    .sign td { border: none; padding: 0 1mm; vertical-align: bottom; }
    .sign .line { border-top: 1px solid #000; width: 60mm; text-align: center; padding-top: 1mm; font-size: 9pt; }
    .foot { margin-top: 4mm; font-size: 8pt; font-style: italic; }
    .empty { text-align: center; font-style: italic; padding: 3mm; }
</style></head><body>
<h1>Absent Checker Report</h1>
<p class="sub">${escapeHtml(periodText)}</p>
<table class="meta">
    <tr>
        <td class="k">Class</td>
        <td>${escapeHtml(window.attendanceClassLabel || '')}</td>
        <td class="k">Teacher</td>
        <td>${escapeHtml(window.attendanceTeacherName || '')}</td>
    </tr>
    <tr>
        <td class="k">Students</td>
        <td>${cache.summary.total_students ?? 0}</td>
        <td class="k">Sessions in Period</td>
        <td>${cache.summary.total_sessions ?? 0}</td>
    </tr>
    <tr>
        <td class="k">At Risk (3+ consecutive)</td>
        <td>${cache.summary.at_risk ?? 0}</td>
        <td class="k">Printed</td>
        <td>${escapeHtml(printedOn)}</td>
    </tr>
</table>
<table class="grid">
    <thead><tr>
        <th>Student No.</th><th>Name</th><th>Sessions</th><th>Present</th>
        <th>Absent</th><th>Late</th><th>Excused</th>
        <th>Max Consec. Absent</th><th>Status</th><th>Dates Absent</th>
    </tr></thead>
    <tbody>${rows || '<tr><td colspan="10" class="empty">No students to report</td></tr>'}</tbody>
</table>
<table class="sign">
    <tr>
        <td><div class="line">Class Facilitator</div></td>
        <td style="width: 12mm;"></td>
        <td><div class="line">Program Chair</div></td>
    </tr>
</table>
<p class="foot">A student is marked DROP RECOMMENDED after three or more consecutive
absences. Absent dates list every session recorded as absent in the period above.</p>
</body></html>`;

    const win = window.open('', '_blank');
    if (!win) {
        showToast('Allow pop-ups to print this report', 'warning');
        return;
    }
    win.document.open();
    win.document.write(doc);
    win.document.close();
    win.focus();
    // Give the stylesheet a moment to apply, otherwise some browsers print
    // before the layout is laid out and the table comes out unstyled.
    setTimeout(() => { win.print(); }, 250);
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
