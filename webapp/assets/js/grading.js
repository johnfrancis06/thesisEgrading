// Grading Sheet Functions - GE-104 Exact Excel Match

async function loadGradingSheet(classId, period) {
    try {
        const resp = await fetch(`api/index.php?action=get_class_grades&class_id=${classId}`);
        const data = await resp.json();
        if (!data.success) {
            console.error('API Error:', data.message);
            return;
        }
        
        renderGradingTable(data.data, classId, period);
    } catch (e) {
        console.error('Error loading grading sheet:', e);
        document.getElementById('gradingBody').innerHTML = `<tr><td colspan="50" class="text-center text-danger">Error loading grading sheet: ${e.message}</td></tr>`;
    }
}

async function loadPerfectScores(classId, period) {
    try {
        const resp = await fetch(`api/index.php?action=get_component_perfect_scores&class_id=${classId}&period=${period}`);
        const data = await resp.json();
        if (!data.success) return;
        
        const scores = data.data;
        document.querySelectorAll('.perfect-score-input').forEach(input => {
            const component = input.dataset.component;
            if (scores[component] !== undefined) {
                input.value = scores[component];
            } else {
                input.value = '';
            }
        });
    } catch (e) {
        console.error('Error loading perfect scores:', e);
    }
}

async function savePerfectScores() {
    const classId = document.getElementById('perfectScoreClassId').value;
    const period = document.getElementById('perfectScorePeriod').value;
    
    const scores = {
        class_participation: parseFloat(document.getElementById('ps_class_participation').value) || 0,
        problem_set: parseFloat(document.getElementById('ps_problem_set').value) || 0,
        quizzes: parseFloat(document.getElementById('ps_quizzes').value) || 0,
        periodical_exam: parseFloat(document.getElementById('ps_periodical_exam').value) || 0
    };
    
    try {
        const resp = await fetch('api/index.php?action=save_component_perfect_scores', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ class_id: classId, period, scores })
        });
        const data = await resp.json();
        if (data.success) {
            alert('Perfect scores saved!');
            loadPerfectScores(classId, period);
            bootstrap.Modal.getInstance(document.getElementById('perfectScoreModal')).hide();
            loadGradingSheet(classId, period);
        } else {
            alert('Error: ' + data.message);
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

function showPerfectScoreModal() {
    const urlParams = new URLSearchParams(window.location.search);
    const classId = urlParams.get('id');
    const period = urlParams.get('period') || 'midterm';
    
    document.getElementById('perfectScoreClassId').value = classId;
    document.getElementById('perfectScorePeriod').value = period;
    document.getElementById('modalPeriodLabel').textContent = period.charAt(0).toUpperCase() + period.slice(1);
    
    document.querySelectorAll('.perfect-score-input').forEach(input => {
        const component = input.dataset.component;
        const modalInput = document.getElementById('ps_' + component);
        if (modalInput) {
            modalInput.value = input.value || '';
        }
    });
    
    const modal = new bootstrap.Modal(document.getElementById('perfectScoreModal'));
    modal.show();
}

function renderGradingTable(data, classId, period) {
    const table = document.getElementById('gradingTable');
    const thead = document.getElementById('gradingHead');
    const tbody = document.getElementById('gradingBody');
    
    if (!data || !data.grades || data.grades.length === 0) {
        tbody.innerHTML = `<tr><td colspan="50" class="text-center">No students found</td></tr>`;
        return;
    }
    
    const gradesData = data.grades;
    const classInfo = data.class;
    
    // Define column structure for the period
    // Midterm: CP1, CP2 | PS1 | Q1, Q2 | Exam
    // Final:   CP1, CP2, CP3, CP4 | PS1 | Q1, Q2 | Exam
    const cpCount = period === 'midterm' ? 2 : 4;
    const psCount = 1;
    const quizCount = 2;
    const examCount = 1;
    
    // Perfect scores from DB
    const perfectScores = {
        class_participation: parseFloat(document.querySelector('.perfect-score-input[data-component="class_participation"]')?.value || '0'),
        problem_set: parseFloat(document.querySelector('.perfect-score-input[data-component="problem_set"]')?.value || '0'),
        quizzes: parseFloat(document.querySelector('.perfect-score-input[data-component="quizzes"]')?.value || '0'),
        periodical_exam: parseFloat(document.querySelector('.perfect-score-input[data-component="periodical_exam"]')?.value || '0')
    };
    
    // Build 3-row header
    let headerHTML = '';
    
    // Row 1: Main category spans
    headerHTML += '<tr class="header-row1">';
    headerHTML += '<th rowspan="3" class="col-student">Name of Students</th>';
    
    // CLASS STANDING (spans CP + PS)
    const classStandingCols = (cpCount + 2) + (psCount + 2); // CP items + total + equiv + PS items + total + equiv
    headerHTML += `<th colspan="${classStandingCols}" class="header-main">CLASS STANDING</th>`;
    
    // QUIZZES
    const quizCols = quizCount + 2; // Q items + total + equiv
    headerHTML += `<th colspan="${quizCols}" class="header-main">QUIZZES</th>`;
    
    // PERIODICAL EXAM
    const examCols = examCount + 2; // Score + equiv + weight
    headerHTML += `<th colspan="${examCols}" class="header-main">PERIODICAL EXAM</th>`;
    
    // MIDTERM GRADE, Round off, REMARKS (each spans 3 rows)
    headerHTML += '<th rowspan="3" class="header-main header-midterm">MIDTERM GRADE</th>';
    headerHTML += '<th rowspan="3" class="header-main header-roundoff">Round off</th>';
    headerHTML += '<th rowspan="3" class="header-main header-remarks">REMARKS</th>';
    headerHTML += '</tr>';
    
    // Row 2: Sub-groups (Class Participation | Problem Set)
    headerHTML += '<tr class="header-row2">';
    headerHTML += `<th colspan="${cpCount + 2}" class="header-sub">Class Participation</th>`;
    headerHTML += `<th colspan="${psCount + 2}" class="header-sub">Problem Set</th>`;
    // Quizzes and Periodical Exam don't have sub-groups, so skip
    headerHTML += '</tr>';
    
    // Row 3: Column labels
    headerHTML += '<tr class="header-row3">';
    
    // Class Participation columns
    for (let i = 1; i <= cpCount; i++) {
        headerHTML += `<th class="header-col header-raw">CP${i}</th>`;
    }
    headerHTML += `<th class="header-col header-total">Total</th>`;
    headerHTML += `<th class="header-col header-equiv">EQUIV.</th>`;
    headerHTML += `<th class="header-col header-weight">20%</th>`;
    
    // Problem Set columns
    for (let i = 1; i <= psCount; i++) {
        headerHTML += `<th class="header-col header-raw">PS${i}</th>`;
    }
    headerHTML += `<th class="header-col header-total">Total</th>`;
    headerHTML += `<th class="header-col header-equiv">EQUIV.</th>`;
    headerHTML += `<th class="header-col header-weight">20%</th>`;
    
    // Quizzes columns
    for (let i = 1; i <= quizCount; i++) {
        headerHTML += `<th class="header-col header-raw">Q${i}</th>`;
    }
    headerHTML += `<th class="header-col header-total">Total</th>`;
    headerHTML += `<th class="header-col header-equiv">EQUIV.</th>`;
    headerHTML += `<th class="header-col header-weight">30%</th>`;
    
    // Periodical Exam columns
    headerHTML += `<th class="header-col header-raw">Score</th>`;
    headerHTML += `<th class="header-col header-equiv">EQUIV.</th>`;
    headerHTML += `<th class="header-col header-weight">30%</th>`;
    
    headerHTML += '</tr>';
    
    thead.innerHTML = headerHTML;
    
    // Build body rows
    let bodyHTML = '';
    
    // Perfect Score Reference Row
    bodyHTML += '<tr class="perfect-score-row">';
    bodyHTML += '<td class="col-student"><strong>Perfect Score</strong></td>';
    
    // CP items
    for (let i = 1; i <= cpCount; i++) {
        const maxPerItem = cpCount > 0 ? Math.round(perfectScores.class_participation / cpCount) : 0;
        bodyHTML += `<td class="cell-raw perfect-cell">${maxPerItem}</td>`;
    }
    bodyHTML += `<td class="cell-total perfect-cell">${perfectScores.class_participation || ''}</td>`;
    bodyHTML += `<td class="cell-equiv perfect-cell"></td>`;
    bodyHTML += `<td class="cell-weight perfect-cell">20%</td>`;
    
    // PS items
    for (let i = 1; i <= psCount; i++) {
        bodyHTML += `<td class="cell-raw perfect-cell">${perfectScores.problem_set || ''}</td>`;
    }
    bodyHTML += `<td class="cell-total perfect-cell">${perfectScores.problem_set || ''}</td>`;
    bodyHTML += `<td class="cell-equiv perfect-cell"></td>`;
    bodyHTML += `<td class="cell-weight perfect-cell">20%</td>`;
    
    // Quiz items
    for (let i = 1; i <= quizCount; i++) {
        const maxPerItem = quizCount > 0 ? Math.round(perfectScores.quizzes / quizCount) : 0;
        bodyHTML += `<td class="cell-raw perfect-cell">${maxPerItem}</td>`;
    }
    bodyHTML += `<td class="cell-total perfect-cell">${perfectScores.quizzes || ''}</td>`;
    bodyHTML += `<td class="cell-equiv perfect-cell"></td>`;
    bodyHTML += `<td class="cell-weight perfect-cell">30%</td>`;
    
    // Exam
    bodyHTML += `<td class="cell-raw perfect-cell">${perfectScores.periodical_exam || ''}</td>`;
    bodyHTML += `<td class="cell-equiv perfect-cell"></td>`;
    bodyHTML += `<td class="cell-weight perfect-cell">30%</td>`;
    
    // Summary columns for perfect score row
    bodyHTML += `<td class="cell-midterm perfect-cell"></td>`;
    bodyHTML += `<td class="cell-roundoff perfect-cell"></td>`;
    bodyHTML += `<td class="cell-remarks perfect-cell"></td>`;
    bodyHTML += '</tr>';
    
    // Student rows
    gradesData.forEach(student => {
        const midterm = student['midterm'] || { grade: 0, grade_point: 5.00, remarks: 'INC', components: {} };
        const final = student['final'] || { grade: 0, grade_point: 5.00, remarks: 'INC', components: {} };
        const overall = student['overall'] || { grade: 0, grade_point: 5.00, remarks: 'INC' };
        
        const periodData = period === 'midterm' ? midterm : final;
        const periodGrade = periodData.grade;
        const periodGradePoint = periodData.grade_point;
        const periodRemarks = periodData.remarks;
        const componentsData = periodData.components || {};
        
        bodyHTML += `<tr data-student="${student.id}">`;
        bodyHTML += `<td class="col-student">${student.last_name}, ${student.first_name} ${student.middle_initial || ''}.</td>`;
        
        // Helper to get component data
        const getCompData = (compKey) => componentsData[compKey] || { items: [], raw_total: 0, max_total: 0, perfect_score: 0 };
        
        // CLASS PARTICIPATION
        const cpData = getCompData('class_participation');
        const cpItems = cpData.items || [];
        const cpRawTotal = cpData.raw_total || 0;
        const cpPerfectScore = perfectScores.class_participation || cpData.perfect_score || cpData.max_total || 0;
        const cpEquiv = cpPerfectScore > 0 ? transmute(cpRawTotal, cpPerfectScore) : 0;
        const cpWeighted = cpEquiv * 0.20;
        
        for (let i = 1; i <= cpCount; i++) {
            const item = cpItems[i-1] || { raw_score: '', max_score: 0, item_id: null };
            const score = item.raw_score !== undefined && item.raw_score !== null ? item.raw_score : '';
            const maxScore = item.max_score || (cpCount > 0 ? Math.round(cpPerfectScore / cpCount) : 0);
            bodyHTML += `<td class="cell-raw">
                <input type="number" class="grade-input" 
                       data-item="${item.item_id || 'new'}" data-student="${student.id}" 
                       data-max="${maxScore}" data-component="class_participation" 
                       data-sub-idx="${i-1}"
                       value="${score !== '' ? score : ''}" 
                       step="1" min="0" max="${maxScore}"
                       oninput="onGradeInput(this, ${student.id}, 'class_participation', ${i-1})"
                       style="width: 100%; padding: 4px 6px; text-align: center; border: 1px solid #999; border-radius: 2px; font-weight: bold; background: white;">
            </td>`;
        }
        bodyHTML += `<td class="cell-total cell-readonly">${cpRawTotal > 0 ? cpRawTotal : ''}</td>`;
        bodyHTML += `<td class="cell-equiv cell-readonly">${cpRawTotal > 0 ? cpEquiv.toFixed(2) : ''}</td>`;
        bodyHTML += `<td class="cell-weight cell-readonly">20%</td>`;
        
        // PROBLEM SET
        const psData = getCompData('problem_set');
        const psItems = psData.items || [];
        const psRawTotal = psData.raw_total || 0;
        const psPerfectScore = perfectScores.problem_set || psData.perfect_score || psData.max_total || 0;
        const psEquiv = psPerfectScore > 0 ? transmute(psRawTotal, psPerfectScore) : 0;
        const psWeighted = psEquiv * 0.20;
        
        for (let i = 1; i <= psCount; i++) {
            const item = psItems[i-1] || { raw_score: '', max_score: 0, item_id: null };
            const score = item.raw_score !== undefined && item.raw_score !== null ? item.raw_score : '';
            const maxScore = item.max_score || psPerfectScore;
            bodyHTML += `<td class="cell-raw">
                <input type="number" class="grade-input" 
                       data-item="${item.item_id || 'new'}" data-student="${student.id}" 
                       data-max="${maxScore}" data-component="problem_set" 
                       data-sub-idx="${i-1}"
                       value="${score !== '' ? score : ''}" 
                       step="1" min="0" max="${maxScore}"
                       oninput="onGradeInput(this, ${student.id}, 'problem_set', ${i-1})"
                       style="width: 100%; padding: 4px 6px; text-align: center; border: 1px solid #999; border-radius: 2px; font-weight: bold; background: white;">
            </td>`;
        }
        bodyHTML += `<td class="cell-total cell-readonly">${psRawTotal > 0 ? psRawTotal : ''}</td>`;
        bodyHTML += `<td class="cell-equiv cell-readonly">${psRawTotal > 0 ? psEquiv.toFixed(2) : ''}</td>`;
        bodyHTML += `<td class="cell-weight cell-readonly">20%</td>`;
        
        // QUIZZES
        const quizData = getCompData('quizzes');
        const quizItems = quizData.items || [];
        const quizRawTotal = quizData.raw_total || 0;
        const quizPerfectScore = perfectScores.quizzes || quizData.perfect_score || quizData.max_total || 0;
        const quizEquiv = quizPerfectScore > 0 ? transmute(quizRawTotal, quizPerfectScore) : 0;
        const quizWeighted = quizEquiv * 0.30;
        
        for (let i = 1; i <= quizCount; i++) {
            const item = quizItems[i-1] || { raw_score: '', max_score: 0, item_id: null };
            const score = item.raw_score !== undefined && item.raw_score !== null ? item.raw_score : '';
            const maxScore = item.max_score || (quizCount > 0 ? Math.round(quizPerfectScore / quizCount) : 0);
            bodyHTML += `<td class="cell-raw">
                <input type="number" class="grade-input" 
                       data-item="${item.item_id || 'new'}" data-student="${student.id}" 
                       data-max="${maxScore}" data-component="quizzes" 
                       data-sub-idx="${i-1}"
                       value="${score !== '' ? score : ''}" 
                       step="1" min="0" max="${maxScore}"
                       oninput="onGradeInput(this, ${student.id}, 'quizzes', ${i-1})"
                       style="width: 100%; padding: 4px 6px; text-align: center; border: 1px solid #999; border-radius: 2px; font-weight: bold; background: white;">
            </td>`;
        }
        bodyHTML += `<td class="cell-total cell-readonly">${quizRawTotal > 0 ? quizRawTotal : ''}</td>`;
        bodyHTML += `<td class="cell-equiv cell-readonly">${quizRawTotal > 0 ? quizEquiv.toFixed(2) : ''}</td>`;
        bodyHTML += `<td class="cell-weight cell-readonly">30%</td>`;
        
        // PERIODICAL EXAM
        const examData = getCompData('periodical_exam');
        const examItems = examData.items || [];
        const examRawTotal = examData.raw_total || 0;
        const examPerfectScore = perfectScores.periodical_exam || examData.perfect_score || examData.max_total || 0;
        const examEquiv = examPerfectScore > 0 ? transmute(examRawTotal, examPerfectScore) : 0;
        const examWeighted = examEquiv * 0.30;
        
        const examItem = examItems[0] || { raw_score: '', max_score: 0, item_id: null };
        const examScore = examItem.raw_score !== undefined && examItem.raw_score !== null ? examItem.raw_score : '';
        const examMaxScore = examItem.max_score || examPerfectScore;
        bodyHTML += `<td class="cell-raw">
            <input type="number" class="grade-input" 
                   data-item="${examItem.item_id || 'new'}" data-student="${student.id}" 
                   data-max="${examMaxScore}" data-component="periodical_exam" 
                   data-sub-idx="0"
                   value="${examScore !== '' ? examScore : ''}" 
                   step="1" min="0" max="${examMaxScore}"
                   oninput="onGradeInput(this, ${student.id}, 'periodical_exam', 0)"
                   style="width: 100%; padding: 4px 6px; text-align: center; border: 1px solid #999; border-radius: 2px; font-weight: bold; background: white;">
        </td>`;
        bodyHTML += `<td class="cell-equiv cell-readonly">${examRawTotal > 0 ? examEquiv.toFixed(2) : ''}</td>`;
        bodyHTML += `<td class="cell-weight cell-readonly">30%</td>`;
        
        // MIDTERM GRADE (weighted sum)
        const totalWeighted = cpWeighted + psWeighted + quizWeighted + examWeighted;
        bodyHTML += `<td class="cell-midterm cell-readonly">${totalWeighted > 0 ? totalWeighted.toFixed(2) : ''}</td>`;
        
        // Round off
        bodyHTML += `<td class="cell-roundoff cell-readonly">${totalWeighted > 0 ? Math.round(totalWeighted) : ''}</td>`;
        
        // Remarks
        const remarks = totalWeighted >= 75 ? 'Passed' : (totalWeighted > 0 ? 'Failed' : 'INC');
        bodyHTML += `<td class="cell-remarks cell-readonly">${remarks}</td>`;
        
        bodyHTML += '</tr>';
    });
    
    tbody.innerHTML = bodyHTML || `<tr><td colspan="50" class="text-center">No data</td></tr>`;
    
    // Attach hover handlers for non-colored cells
    attachRowHoverEffects();
}

const saveTimers = {};

function onGradeInput(input, studentId, componentKey, subIdx) {
    // Clamp value to max
    const maxScore = parseFloat(input.dataset.max) || 100;
    let clamped = Math.min(Math.max(parseFloat(input.value) || 0, 0), maxScore);
    clamped = Math.round(clamped);
    input.value = clamped;
    
    calculateRowGrades(studentId);
    
    const gradeItemId = input.dataset.item;
    const studentKey = `${studentId}_${componentKey}_${subIdx}`;
    
    if (saveTimers[studentKey]) {
        clearTimeout(saveTimers[studentKey]);
    }
    
    // Only save if we have a valid grade_item_id (not 'new')
    if (gradeItemId !== 'new' && parseInt(gradeItemId) > 0) {
        saveTimers[studentKey] = setTimeout(async () => {
            try {
                const resp = await fetch('api/index.php?action=save_grade', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        grade_item_id: parseInt(gradeItemId), 
                        student_id: studentId, 
                        raw_score: clamped 
                    })
                });
                const data = await resp.json();
                if (!data.success) {
                    console.error('Save failed:', data.message);
                }
            } catch (e) {
                console.error('Error saving grade:', e);
            }
        }, 500);
    }
}

function calculateRowGrades(studentId) {
    // Get perfect scores from display
    const perfectScores = {
        class_participation: parseFloat(document.querySelector('.perfect-score-input[data-component="class_participation"]')?.value || '0'),
        problem_set: parseFloat(document.querySelector('.perfect-score-input[data-component="problem_set"]')?.value || '0'),
        quizzes: parseFloat(document.querySelector('.perfect-score-input[data-component="quizzes"]')?.value || '0'),
        periodical_exam: parseFloat(document.querySelector('.perfect-score-input[data-component="periodical_exam"]')?.value || '0')
    };
    
    const components = [
        { key: 'class_participation', weight: 0.20 },
        { key: 'problem_set', weight: 0.20 },
        { key: 'quizzes', weight: 0.30 },
        { key: 'periodical_exam', weight: 0.30 }
    ];
    
    let allWeightedScores = {};
    
    components.forEach(comp => {
        let total = 0;
        
        const inputs = document.querySelectorAll(`input[data-student="${studentId}"][data-component="${comp.key}"]`);
        inputs.forEach(input => {
            const score = parseFloat(input.value) || 0;
            total += score;
        });
        
        const perfectScore = perfectScores[comp.key] || 100;
        const equivScore = perfectScore > 0 ? transmute(total, perfectScore) : 0;
        const weighted = equivScore * comp.weight;
        
        allWeightedScores[comp.key] = { total, equiv: equivScore, weighted };
    });
    
    // Calculate period grade (weighted average)
    let periodGrade = 0;
    components.forEach(comp => {
        periodGrade += (allWeightedScores[comp.key]?.weighted || 0);
    });
    
    // Update all computed cells for this student
    updateComputedCells(studentId, allWeightedScores, periodGrade);
}

function updateComputedCells(studentId, allWeightedScores, periodGrade) {
    const row = document.querySelector(`tr[data-student="${studentId}"]`);
    if (!row) return;
    
    // Get all cells in this row (skip the first cell which is student name)
    const cells = row.querySelectorAll('td');
    // We need to find specific cells by their class
    
    const compOrder = ['class_participation', 'problem_set', 'quizzes', 'periodical_exam'];
    
    compOrder.forEach((compKey, idx) => {
        const data = allWeightedScores[compKey];
        if (!data) return;
        
        // Find total cell (first cell with class cell-total.cell-readonly for this component)
        // The structure is: raw inputs, then total, then equiv, then weight
        // We'll find them by looking at the cell classes
    });
    
    // Better: use querySelectorAll with specific class combinations
    const totalCells = row.querySelectorAll('.cell-total.cell-readonly');
    const equivCells = row.querySelectorAll('.cell-equiv.cell-readonly');
    const weightCells = row.querySelectorAll('.cell-weight.cell-readonly');
    const midtermCell = row.querySelector('.cell-midterm.cell-readonly');
    const roundoffCell = row.querySelector('.cell-roundoff.cell-readonly');
    const remarksCell = row.querySelector('.cell-remarks.cell-readonly');
    
    // The order matches compOrder: CP, PS, Quiz, Exam
    compOrder.forEach((compKey, idx) => {
        const data = allWeightedScores[compKey];
        if (!data) return;
        
        // Total cell
        if (totalCells[idx]) {
            totalCells[idx].textContent = data.total > 0 ? data.total : '';
        }
        
        // Equiv cell
        if (equivCells[idx]) {
            equivCells[idx].textContent = data.total > 0 ? data.equiv.toFixed(2) : '';
        }
    });
    
    // Midterm grade
    if (midtermCell) {
        midtermCell.textContent = periodGrade > 0 ? periodGrade.toFixed(2) : '';
    }
    
    // Round off
    if (roundoffCell) {
        roundoffCell.textContent = periodGrade > 0 ? Math.round(periodGrade) : '';
    }
    
    // Remarks
    if (remarksCell) {
        const remarks = periodGrade >= 75 ? 'Passed' : (periodGrade > 0 ? 'Failed' : 'INC');
        remarksCell.textContent = remarks;
    }
}

function attachRowHoverEffects() {
    const rows = document.querySelectorAll('#gradingBody tr:not(.perfect-score-row)');
    rows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.querySelectorAll('td:not(.cell-equiv):not(.cell-weight):not(.cell-midterm):not(.col-student)').forEach(cell => {
                if (!cell.classList.contains('cell-equiv') && !cell.classList.contains('cell-weight') && !cell.classList.contains('cell-midterm')) {
                    cell.style.backgroundColor = 'rgba(14, 165, 233, 0.08)';
                }
            });
        });
        row.addEventListener('mouseleave', function() {
            this.querySelectorAll('td').forEach(cell => {
                if (!cell.classList.contains('cell-equiv') && !cell.classList.contains('cell-weight') && !cell.classList.contains('cell-midterm') && !cell.classList.contains('perfect-cell')) {
                    cell.style.backgroundColor = '';
                }
            });
        });
    });
}

// Transmutation formula: (raw/max)*50+50
function transmute(raw, max) {
    if (max <= 0) return 0;
    return (raw / max) * 50 + 50;
}

// Grade point lookup (for reference)
function getGradePoint(score) {
    score = parseFloat(score);
    if (score < 75) return 5.00;
    if (score < 78) return 3.00;
    if (score < 81) return 2.75;
    if (score < 84) return 2.50;
    if (score < 87) return 2.25;
    if (score < 90) return 2.00;
    if (score < 93) return 1.75;
    if (score < 96) return 1.50;
    if (score < 99) return 1.25;
    return 1.00;
}

document.addEventListener('hide.bs.modal', function (event) {
    if (document.activeElement && event.target.contains(document.activeElement)) {
        document.activeElement.blur();
    }
});

// Perfect Score Category Toggle
window.perfectScoreCollapsed = false;

function togglePerfectScoreCategory() {
    const body = document.getElementById('perfectScoreBody');
    const chevron = document.getElementById('perfectScoreChevron');
    const category = document.getElementById('perfectScoreCategory');
    
    window.perfectScoreCollapsed = !window.perfectScoreCollapsed;
    
    if (window.perfectScoreCollapsed) {
        body.style.display = 'none';
        chevron.style.transform = 'rotate(-90deg)';
        category.classList.add('collapsed');
    } else {
        body.style.display = 'block';
        chevron.style.transform = 'rotate(0deg)';
        category.classList.remove('collapsed');
    }
}

// ==========================================
// CATEGORY MANAGEMENT
// ==========================================

let currentCategoryId = null;

function showCategoryManager() {
    document.getElementById('catMgrPeriodLabel').textContent = period.charAt(0).toUpperCase() + period.slice(1);
    loadCategories();
    new bootstrap.Modal(document.getElementById('categoryManagerModal')).show();
}

async function loadCategories() {
    try {
        const resp = await fetch(`api/index.php?action=get_categories_by_period&class_id=${classId}&period=${period}`);
        const data = await resp.json();
        if (!data.success) return;
        
        const list = document.getElementById('categoryList');
        if (data.data.length === 0) {
            list.innerHTML = '<li class="list-group-item text-center text-muted py-4">No categories yet. Click "Add" to create one.</li>';
            return;
        }
        
        list.innerHTML = data.data.map(cat => `
            <li class="list-group-item d-flex justify-content-between align-items-center ${cat.id === currentCategoryId ? 'active bg-primary bg-opacity-10' : ''}" 
                onclick="selectCategory(${cat.id}, '${cat.name}', ${cat.weight_percent})">
                <div>
                    <div class="fw-bold">${cat.name}</div>
                    <small class="text-muted">${cat.weight_percent}% weight</small>
                </div>
                <div class="d-flex gap-1">
                    <button class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation(); editCategory(${cat.id}, '${cat.name}', ${cat.weight_percent}, ${cat.sort_order})" title="Edit">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation(); deleteCategory(${cat.id})" title="Delete">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </li>
        `).join('');
        
        // Auto-select first category if none selected
        if (currentCategoryId === null && data.data.length > 0) {
            selectCategory(data.data[0].id, data.data[0].name, data.data[0].weight_percent);
        }
    } catch (e) {
        console.error('Error loading categories:', e);
    }
}

function selectCategory(categoryId, categoryName, categoryWeight) {
    currentCategoryId = categoryId;
    document.getElementById('selectedCategoryTitle').textContent = `Grade Items - ${categoryName} (${categoryWeight}%)`;
    document.getElementById('addItemBtn').style.display = 'inline-flex';
    document.getElementById('gradeItemCategoryId').value = categoryId;
    
    // Update active state in list
    document.querySelectorAll('#categoryList .list-group-item').forEach(item => {
        item.classList.remove('active', 'bg-primary', 'bg-opacity-10');
    });
    event?.target.closest('.list-group-item')?.classList.add('active', 'bg-primary', 'bg-opacity-10');
    
    loadItems(categoryId);
}

async function loadItems(categoryId) {
    try {
        const resp = await fetch(`api/index.php?action=get_grade_items_by_category&category_id=${categoryId}`);
        const data = await resp.json();
        
        const tbody = document.querySelector('#itemsTable tbody');
        if (!data.success || !data.data || data.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">No items in this category. Click "Add Item" to create one.</td></tr>';
            return;
        }
        
        tbody.innerHTML = data.data.map((item, idx) => `
            <tr>
                <td>${idx + 1}</td>
                <td>${item.label}</td>
                <td>${item.max_score}</td>
                <td>${item.sort_order}</td>
                <td>
                    <button class="btn btn-sm btn-outline-primary" onclick="editGradeItem(${item.id}, '${item.label}', ${item.max_score}, ${item.sort_order})" title="Edit">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteGradeItem(${item.id})" title="Delete">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>
        `).join('');
    } catch (e) {
        console.error('Error loading items:', e);
    }
}

function showAddCategoryModal() {
    document.getElementById('categoryId').value = '';
    document.getElementById('categoryName').value = '';
    document.getElementById('categoryWeight').value = '';
    document.getElementById('categorySort').value = '0';
    document.getElementById('categoryModalTitle').textContent = 'Add Category';
    new bootstrap.Modal(document.getElementById('categoryModal')).show();
}

function editCategory(id, name, weight, sort) {
    document.getElementById('categoryId').value = id;
    document.getElementById('categoryName').value = name;
    document.getElementById('categoryWeight').value = weight;
    document.getElementById('categorySort').value = sort;
    document.getElementById('categoryModalTitle').textContent = 'Edit Category';
    new bootstrap.Modal(document.getElementById('categoryModal')).show();
}

async function saveCategory() {
    const id = document.getElementById('categoryId').value;
    const data = {
        class_id: parseInt(document.getElementById('categoryClassId').value),
        period: document.getElementById('categoryPeriod').value,
        name: document.getElementById('categoryName').value,
        weight_percent: parseFloat(document.getElementById('categoryWeight').value),
        sort_order: parseInt(document.getElementById('categorySort').value)
    };
    
    if (!data.name || data.weight_percent === undefined || data.weight_percent <= 0) {
        alert('Please fill in all required fields');
        return;
    }
    
    if (id) data.id = parseInt(id);
    
    try {
        const action = id ? 'update_category' : 'add_category';
        const resp = await fetch(`api/index.php?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await resp.json();
        if (result.success) {
            alert(id ? 'Category updated!' : 'Category added!');
            bootstrap.Modal.getInstance(document.getElementById('categoryModal')).hide();
            loadCategories();
            loadGradingSheet(classId, period); // Refresh grading sheet
        } else {
            alert('Error: ' + result.message);
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function deleteCategory(id) {
    if (!confirm('Delete this category and all its items/grades?')) return;
    
    try {
        const resp = await fetch('api/index.php?action=delete_category', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const result = await resp.json();
        if (result.success) {
            alert('Category deleted!');
            currentCategoryId = null;
            loadCategories();
            loadGradingSheet(classId, period);
        } else {
            alert('Error: ' + result.message);
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

function showAddItemModal() {
    if (!currentCategoryId) {
        alert('Please select a category first');
        return;
    }
    document.getElementById('gradeItemId').value = '';
    document.getElementById('gradeItemCategoryId').value = currentCategoryId;
    document.getElementById('gradeItemLabel').value = '';
    document.getElementById('gradeItemMaxScore').value = '';
    document.getElementById('gradeItemSort').value = '0';
    document.getElementById('gradeItemModalTitle').textContent = 'Add Grade Item';
    new bootstrap.Modal(document.getElementById('gradeItemModal')).show();
}

function editGradeItem(id, label, maxScore, sort) {
    document.getElementById('gradeItemId').value = id;
    document.getElementById('gradeItemCategoryId').value = currentCategoryId;
    document.getElementById('gradeItemLabel').value = label;
    document.getElementById('gradeItemMaxScore').value = maxScore;
    document.getElementById('gradeItemSort').value = sort;
    document.getElementById('gradeItemModalTitle').textContent = 'Edit Grade Item';
    new bootstrap.Modal(document.getElementById('gradeItemModal')).show();
}

async function saveGradeItem() {
    const id = document.getElementById('gradeItemId').value;
    const data = {
        category_id: parseInt(document.getElementById('gradeItemCategoryId').value),
        label: document.getElementById('gradeItemLabel').value,
        max_score: parseFloat(document.getElementById('gradeItemMaxScore').value),
        sort_order: parseInt(document.getElementById('gradeItemSort').value)
    };
    
    if (!data.label || !data.max_score) {
        alert('Please fill in all required fields');
        return;
    }
    
    if (id) data.id = parseInt(id);
    
    try {
        const action = id ? 'update_grade_item' : 'add_grade_item';
        const resp = await fetch(`api/index.php?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await resp.json();
        if (result.success) {
            alert(id ? 'Item updated!' : 'Item added!');
            bootstrap.Modal.getInstance(document.getElementById('gradeItemModal')).hide();
            loadItems(currentCategoryId);
            loadGradingSheet(classId, period);
        } else {
            alert('Error: ' + result.message);
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

async function deleteGradeItem(id) {
    if (!confirm('Delete this grade item and all its scores?')) return;
    
    try {
        const resp = await fetch('api/index.php?action=delete_grade_item', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const result = await resp.json();
        if (result.success) {
            alert('Item deleted!');
            loadItems(currentCategoryId);
            loadGradingSheet(classId, period);
        } else {
            alert('Error: ' + result.message);
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Make functions globally accessible
window.showCategoryManager = showCategoryManager;
window.loadCategories = loadCategories;
window.selectCategory = selectCategory;
window.loadItems = loadItems;
window.showAddCategoryModal = showAddCategoryModal;
window.editCategory = editCategory;
window.saveCategory = saveCategory;
window.deleteCategory = deleteCategory;
window.showAddItemModal = showAddItemModal;
window.editGradeItem = editGradeItem;
window.saveGradeItem = saveGradeItem;
window.deleteGradeItem = deleteGradeItem;