// Grading Sheet Functions - GE-104 Exact Excel Match

async function loadGradingSheet(classId, period) {
    try {
        const resp = await fetch(`api/index.php?action=get_class_grades&class_id=${classId}`, {
            credentials: 'include'
        });
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
        const resp = await fetch(`api/index.php?action=get_component_perfect_scores&class_id=${classId}&period=${period}`, {
            credentials: 'include'
        });
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
        const resp = await fetch(`api/index.php?action=save_component_perfect_scores`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
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

async function renderGradingTable(data, classId, period) {
    const table = document.getElementById('gradingTable');
    const thead = document.getElementById('gradingHead');
    const tbody = document.getElementById('gradingBody');
    
    if (!data || !data.grades || data.grades.length === 0) {
        tbody.innerHTML = `<tr><td colspan="50" class="text-center">No students found</td></tr>`;
        return;
    }
    
    const gradesData = data.grades;
    const classInfo = data.class;
    
    // Fetch dynamic category configs
    const configs = await fetchCategoryConfigs(classId, period);
    if (!configs || configs.length === 0) {
        tbody.innerHTML = `<tr><td colspan="50" class="text-center">No categories configured. Click "Manage Categories" to set up.</td></tr>`;
        return;
    }
    
    // Store configs globally for use in calculations
    window.currentCategoryConfigs = configs;
    
    // Build perfect scores object from configs
    const perfectScores = {};
    configs.forEach(config => {
        const key = config.template_id ? getComponentKeyFromTemplate(config.template_id) : config.custom_name.toLowerCase().replace(/\s+/g, '_');
        perfectScores[key] = config.perfect_score;
    });
    
    // Store perfect scores globally
    window.currentPerfectScores = perfectScores;
    
    // Update perfect score display inputs
    updatePerfectScoreDisplay(perfectScores);
    
    // Build dynamic header
    buildDynamicHeader(configs);
    
    // Build body rows
    let bodyHTML = '';
    
    // Perfect Score Reference Row
    bodyHTML += buildPerfectScoreRow(configs);
    
    // Student rows
    gradesData.forEach(student => {
        bodyHTML += buildStudentRow(student, configs, period);
    });
    
    tbody.innerHTML = bodyHTML || `<tr><td colspan="50" class="text-center">No data</td></tr>`;
    
    // Attach hover handlers
    attachRowHoverEffects();
    
    // Calculate initial grades for all students
    gradesData.forEach(student => {
        calculateRowGrades(student.id);
    });
}

async function fetchCategoryConfigs(classId, period) {
    try {
        const resp = await fetch(`api/index.php?action=get_category_configs&class_id=${classId}&period=${period}`, {
            credentials: 'include'
        });
        const data = await resp.json();
        if (data.success) return data.data;
        return [];
    } catch (e) {
        console.error('Error fetching category configs:', e);
        return [];
    }
}

function getComponentKeyFromTemplate(templateId) {
    // Map template IDs to component keys for backward compatibility
    const templateMap = {
        1: 'class_participation',
        2: 'problem_set',
        3: 'quizzes',
        4: 'periodical_exam'
    };
    return templateMap[templateId] || `custom_${templateId}`;
}

function updatePerfectScoreDisplay(perfectScores) {
    document.querySelectorAll('.perfect-score-input').forEach(input => {
        const component = input.dataset.component;
        if (perfectScores[component] !== undefined) {
            input.value = perfectScores[component];
        }
    });
}

function buildDynamicHeader(configs) {
    const thead = document.getElementById('gradingHead');
    let headerHTML = '';
    
    // Calculate total columns for span calculations
    let totalItemCols = 0;
    configs.forEach(config => {
        totalItemCols += (config.items ? config.items.length : 1) + 2; // items + total + equiv + weight
    });
    
    // Row 1: Main category spans
    headerHTML += '<tr class="header-row1">';
    headerHTML += '<th rowspan="3" class="col-student">Name of Students</th>';
    
    configs.forEach(config => {
        const itemCount = config.items ? config.items.length : 1;
        const colSpan = itemCount + 3; // items + total + equiv + weight
        headerHTML += `<th colspan="${colSpan}" class="header-main">${config.custom_name || config.template_name || 'Category'}</th>`;
    });
    
    // MIDTERM GRADE, Round off, REMARKS
    headerHTML += '<th rowspan="3" class="header-main header-midterm">MIDTERM GRADE</th>';
    headerHTML += '<th rowspan="3" class="header-main header-roundoff">Round off</th>';
    headerHTML += '<th rowspan="3" class="header-main header-remarks">REMARKS</th>';
    headerHTML += '</tr>';
    
    // Row 2: Only add if there are multiple items per category (sub-groups)
    // For simplicity, we'll skip row 2 or make it conditional
    // Actually, the original design had CLASS STANDING spanning CP + PS
    // We'll keep it simple: each category is its own group
    // So no row 2 needed for dynamic structure
    
    // Row 3: Column labels
    headerHTML += '<tr class="header-row3">';
    
    configs.forEach(config => {
        const items = config.items || [];
        const key = config.template_id ? getComponentKeyFromTemplate(config.template_id) : config.custom_name.toLowerCase().replace(/\s+/g, '_');
        const weight = config.weight_percent;
        const maxWeight = weight;
        
        items.forEach(item => {
            headerHTML += `<th class="header-col header-raw">${item.label}</th>`;
        });
        
        // Total, Equiv, Weight
        headerHTML += `<th class="header-col header-total">Total</th>`;
        headerHTML += `<th class="header-col header-equiv">EQUIV.</th>`;
        headerHTML += `<th class="header-col header-weight"><input type="number" class="category-weight-input" value="${maxWeight}" min="0" max="100" step="0.01" data-config-id="${config.id || ''}" data-category-key="${key}" onchange="updateCategoryWeight(this)" aria-label="${key} category weight">%</th>`;
    });
    
    headerHTML += '</tr>';
    
    thead.innerHTML = headerHTML;
}

function buildPerfectScoreRow(configs) {
    let html = '<tr class="perfect-score-row">';
    html += '<td class="col-student"><strong>Perfect Score</strong></td>';
    
    configs.forEach(config => {
        const items = config.items || [];
        const perfectScore = config.perfect_score;
        const weight = config.weight_percent;
        const itemCount = items.length;
        const maxPerItem = itemCount > 0 ? Math.round(perfectScore / itemCount) : 0;
        
        items.forEach(() => {
            html += `<td class="cell-raw perfect-cell">${maxPerItem}</td>`;
        });
        
        html += `<td class="cell-total perfect-cell">${perfectScore || ''}</td>`;
        html += `<td class="cell-equiv perfect-cell"></td>`;
        html += `<td class="cell-weight perfect-cell"><input type="number" class="weight-input" data-component="${getComponentKeyFromTemplate(config.template_id)}" data-max-weight="${weight}" value="${weight}" readonly style="width: 60px; padding: 3px 4px; text-align: center; border: 1px solid #999; border-radius: 2px; font-weight: bold; background: #FF0000; color: #FFF;"></td>`;
    });
    
    // Summary columns
    html += `<td class="cell-midterm perfect-cell"></td>`;
    html += `<td class="cell-roundoff perfect-cell"></td>`;
    html += `<td class="cell-remarks perfect-cell"></td>`;
    html += '</tr>';
    
    return html;
}

function buildStudentRow(student, configs, period) {
    const periodData = period === 'midterm' ? (student.midterm || {}) : (student.final || {});
    const componentsData = periodData.component_details || {};
    
    let html = `<tr data-student="${student.id}">`;
    html += `<td class="col-student">${student.last_name}, ${student.first_name} ${student.middle_initial || ''}.</td>`;
    
    configs.forEach(config => {
        const key = config.template_id ? getComponentKeyFromTemplate(config.template_id) : config.custom_name.toLowerCase().replace(/\s+/g, '_');
        const compData = componentsData[key] || {};
        const items = config.items || [];
        const perfectScore = config.perfect_score;
        const weight = parseFloat(config.weight_percent) || 0;
        
        // Render item inputs
        items.forEach((item, idx) => {
            const itemData = (Array.isArray(compData.items) ? compData.items[idx] : null) || { raw_score: '', max_score: 0, item_id: null };
            const score = itemData.raw_score !== undefined && itemData.raw_score !== null ? itemData.raw_score : '';
            const maxScore = parseFloat(itemData.max_score || item.max_score) || 100;
            
            html += `<td class="cell-raw">
                <input type="number" class="grade-input" 
                       data-item="${itemData.item_id || 'new'}" data-student="${student.id}" 
                       data-max="${maxScore}" data-component="${key}" 
                       data-sub-idx="${idx}"
                       value="${score !== '' ? score : ''}" 
                       step="1" min="0" max="${maxScore}"
                       oninput="onGradeInput(this, ${student.id}, '${key}', ${idx})"
                       onchange="saveGradeInput(this)"
                       style="width: 100%; padding: 4px 6px; text-align: center; border: 1px solid #999; border-radius: 2px; font-weight: bold; background: white;">
            </td>`;
        });
        
        const rawTotal = compData.raw_total || 0;
        const equivScore = perfectScore > 0 ? transmute(rawTotal, perfectScore) : 0;
        html += `<td class="cell-total cell-readonly">${rawTotal > 0 ? rawTotal : ''}</td>`;
        html += `<td class="cell-equiv cell-readonly">${rawTotal > 0 ? equivScore.toFixed(2) : ''}</td>`;
        html += `<td class="cell-weight"><input type="number" class="weight-input" data-student="${student.id}" data-component="${key}" data-max-weight="100" value="${weight.toFixed(2)}" step="0.01" min="0" max="100" oninput="onWeightInput(this, ${student.id}, '${key}')" style="width: 60px; padding: 3px 4px; text-align: center; border: 1px solid #999; border-radius: 2px; font-weight: bold; background: #FF0000; color: #FFF;"></td>`;
    });
    
    // Calculate initial weighted total for display
    let totalWeighted = 0;
    configs.forEach(config => {
        const key = config.template_id ? getComponentKeyFromTemplate(config.template_id) : config.custom_name.toLowerCase().replace(/\s+/g, '_');
        const compData = componentsData[key] || {};
        const perfectScore = config.perfect_score;
        const weight = config.weight_percent;
        const rawTotal = compData.raw_total || 0;
        const equivScore = perfectScore > 0 ? transmute(rawTotal, perfectScore) : 0;
        totalWeighted += equivScore * (weight / 100);
    });
    
    const remarks = totalWeighted >= 75 ? 'Passed' : (totalWeighted > 0 ? 'Failed' : 'INC');
    
    html += `<td class="cell-midterm cell-readonly">${totalWeighted > 0 ? totalWeighted.toFixed(2) : ''}</td>`;
    html += `<td class="cell-roundoff cell-readonly">${totalWeighted > 0 ? Math.round(totalWeighted) : ''}</td>`;
    html += `<td class="cell-remarks cell-readonly">${remarks}</td>`;
    
    html += '</tr>';
return html;
}
     
const saveTimers = {};

async function saveGradeInput(input) {
    const gradeItemId = parseInt(input.dataset.item, 10);
    const studentId = parseInt(input.dataset.student, 10);
    if (!gradeItemId || !studentId) return;

    const maxScore = parseFloat(input.dataset.max) || 100;
    const rawScore = Math.min(Math.max(parseFloat(input.value) || 0, 0), maxScore);
    input.value = Math.round(rawScore);

    try {
        const resp = await fetch('api/index.php?action=save_grade', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
                item_id: gradeItemId,
                student_id: studentId,
                raw_score: input.value
            })
        });
        const data = await resp.json();
        if (!data.success) console.error('Save failed:', data.message);
    } catch (e) {
        console.error('Error saving grade:', e);
    }
}

function onGradeInput(input, studentId, componentKey, subIdx) {
    // Clamp value to max
    const maxScore = parseFloat(input.dataset.max) || 100;
    let clamped = Math.min(Math.max(parseFloat(input.value) || 0, 0), maxScore);
    clamped = Math.round(clamped);
    input.value = clamped;
    
    // Clear manual edit flag for this component so weight recalculates
    const row = document.querySelector(`tr[data-student="${studentId}"]`);
    if (row) {
        const weightInput = row.querySelector(`input.weight-input[data-component="${componentKey}"]`);
        if (weightInput) {
            delete weightInput.dataset.manuallyEdited;
        }
    }
    
    calculateRowGrades(studentId);
    
    const gradeItemId = input.dataset.item;
    const studentKey = `${studentId}_${componentKey}_${subIdx}`;
    
    if (saveTimers[studentKey]) {
        clearTimeout(saveTimers[studentKey]);
    }
    
    // Only save if we have a valid grade_item_id (not 'new')
    if (gradeItemId !== 'new' && parseInt(gradeItemId) > 0) {
        saveTimers[studentKey] = setTimeout(async () => {
            await saveGradeInput(input);
        }, 500);
    }
}

function onWeightInput(input, studentId, componentKey) {
    // Clamp weight to max
    const maxWeight = parseFloat(input.dataset.maxWeight) || 30;
    let clamped = Math.min(Math.max(parseFloat(input.value) || 0, 0), maxWeight);
    clamped = parseFloat(clamped.toFixed(2));
    input.value = clamped;
    
    // Mark as manually edited so auto-calc doesn't override
    input.dataset.manuallyEdited = 'true';
    
    calculateRowGrades(studentId);
}

async function updateCategoryWeight(input) {
    const newWeight = parseFloat(input.value);
    const configId = parseInt(input.dataset.configId, 10);

    if (!configId || Number.isNaN(newWeight) || newWeight < 0 || newWeight > 100) {
        input.value = input.defaultValue;
        return;
    }

    try {
        const resp = await fetch('api/index.php?action=update_category_config_weight', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({
                config_id: configId,
                class_id: classId,
                period,
                weight_percent: newWeight
            })
        });
        const data = await resp.json();
        if (!data.success) {
            throw new Error(data.message || 'Unable to update category weight');
        }

        input.defaultValue = newWeight;
        loadGradingSheet(classId, period);
    } catch (e) {
        console.error('Error updating category weight:', e);
        input.value = input.defaultValue;
        alert(e.message);
    }
}

function updateWeightFromEquiv(studentId, componentKey) {
    const row = document.querySelector(`tr[data-student="${studentId}"]`);
    if (!row) return;
    
    const equivCells = row.querySelectorAll('.cell-equiv.cell-readonly');
    const configs = window.currentCategoryConfigs || [];
    
    let compIdx = -1;
    let maxWeight = 30;
    
    if (configs.length > 0) {
        configs.forEach((config, idx) => {
            const key = config.template_id ? getComponentKeyFromTemplate(config.template_id) : config.custom_name.toLowerCase().replace(/\s+/g, '_');
            if (key === componentKey) {
                compIdx = idx;
                maxWeight = config.weight_percent;
            }
        });
    } else {
        // Fallback
        const compOrder = ['class_participation', 'problem_set', 'quizzes', 'periodical_exam'];
        compIdx = compOrder.indexOf(componentKey);
        const maxWeights = {
            'class_participation': 20,
            'problem_set': 20,
            'quizzes': 30,
            'periodical_exam': 30
        };
        maxWeight = maxWeights[componentKey] || 30;
    }
    
    if (compIdx >= 0 && equivCells[compIdx]) {
        const equivText = equivCells[compIdx].textContent;
        const equiv = parseFloat(equivText) || 0;
        
        const autoWeight = equiv > 0 ? (equiv / 100) * maxWeight : 0;
        
        const weightInput = row.querySelector(`input.weight-input[data-component="${componentKey}"]`);
        if (weightInput && !weightInput.dataset.manuallyEdited) {
            weightInput.value = autoWeight.toFixed(2);
        }
    }
}

function calculateRowGrades(studentId) {
    // Get perfect scores from global (set by renderGradingTable)
    const perfectScores = window.currentPerfectScores || {};
    const configs = window.currentCategoryConfigs || [];
    
    if (configs.length === 0) return;
    
    let allWeightedScores = {};
    
    configs.forEach(config => {
        const key = config.template_id ? getComponentKeyFromTemplate(config.template_id) : config.custom_name.toLowerCase().replace(/\s+/g, '_');
        const configuredWeight = parseFloat(config.weight_percent) || 0;
        const perfectScore = perfectScores[key] || config.perfect_score || 100;
        
        let total = 0;
        
        const inputs = document.querySelectorAll(`input.grade-input[data-student="${studentId}"][data-component="${key}"]`);
        inputs.forEach(input => {
            const score = parseFloat(input.value) || 0;
            total += score;
        });
        
        const equivScore = perfectScore > 0 ? transmute(total, perfectScore) : 0;
        
        // Read weight from input (editable by teacher)
        const weightInput = document.querySelector(`input.weight-input[data-student="${studentId}"][data-component="${key}"]`);
        const weightPercent = weightInput ? parseFloat(weightInput.value) : configuredWeight;
        const weight = (Number.isFinite(weightPercent) ? weightPercent : configuredWeight) / 100;
        
        const weighted = equivScore * weight;
        
        allWeightedScores[key] = { total, equiv: equivScore, weighted, weight: weight * 100 };
    });
    
    // Calculate period grade (sum of weighted scores)
    let periodGrade = 0;
    Object.values(allWeightedScores).forEach(data => {
        periodGrade += (data.weighted || 0);
    });
    
    // Update all computed cells for this student
    updateComputedCells(studentId, allWeightedScores, periodGrade, configs);
}

function updateComputedCells(studentId, allWeightedScores, periodGrade, configs) {
    const row = document.querySelector(`tr[data-student="${studentId}"]`);
    if (!row) return;
    
    const totalCells = row.querySelectorAll('.cell-total.cell-readonly');
    const equivCells = row.querySelectorAll('.cell-equiv.cell-readonly');
    const weightInputs = row.querySelectorAll('input.weight-input');
    const midtermCell = row.querySelector('.cell-midterm.cell-readonly');
    const roundoffCell = row.querySelector('.cell-roundoff.cell-readonly');
    const remarksCell = row.querySelector('.cell-remarks.cell-readonly');
    
    // Use configs order instead of hardcoded compOrder
    if (configs && configs.length > 0) {
        configs.forEach((config, idx) => {
            const key = config.template_id ? getComponentKeyFromTemplate(config.template_id) : config.custom_name.toLowerCase().replace(/\s+/g, '_');
            const data = allWeightedScores[key];
            if (!data) return;
            
            // Total cell
            if (totalCells[idx]) {
                totalCells[idx].textContent = data.total > 0 ? data.total : '';
            }
            
            // Equiv cell
            if (equivCells[idx]) {
                equivCells[idx].textContent = data.total > 0 ? data.equiv.toFixed(2) : '';
            }
            
            // Weight input - update if not manually edited
            if (weightInputs[idx]) {
                const input = weightInputs[idx];
                if (!input.dataset.manuallyEdited) {
                    input.value = data.weight > 0 ? data.weight.toFixed(2) : '';
                }
            }
        });
    } else {
        // Fallback to old behavior
        const compOrder = ['class_participation', 'problem_set', 'quizzes', 'periodical_exam'];
        compOrder.forEach((compKey, idx) => {
            const data = allWeightedScores[compKey];
            if (!data) return;
            
            if (totalCells[idx]) totalCells[idx].textContent = data.total > 0 ? data.total : '';
            if (equivCells[idx]) equivCells[idx].textContent = data.total > 0 ? data.equiv.toFixed(2) : '';
            if (weightInputs[idx]) {
                const input = weightInputs[idx];
                if (!input.dataset.manuallyEdited) {
                    input.value = data.weight > 0 ? data.weight.toFixed(2) : '';
                }
            }
        });
    }
    
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

// Equivalent score as a percentage of the configured perfect score.
function transmute(raw, max) {
    if (max <= 0) return 0;
    return (raw / max) * 100;
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
        const resp = await fetch(`api/index.php?action=get_categories_by_period&class_id=${classId}&period=${period}`, {
            credentials: 'include'
        });
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
        const resp = await fetch(`api/index.php?action=get_grade_items_by_category&category_id=${categoryId}`, {
            credentials: 'include'
        });
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
            credentials: 'include',
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
            credentials: 'include',
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
            credentials: 'include',
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
            credentials: 'include',
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

function getExportTable() {
    const table = document.getElementById('gradingTable');
    if (!table || !table.querySelector('tbody tr')) {
        alert('The grading sheet is still loading. Please try again.');
        return null;
    }

    const exportTable = table.cloneNode(true);
    exportTable.querySelectorAll('input, select, textarea').forEach(input => {
        const cell = input.closest('th, td');
        if (cell) cell.textContent = input.value || '';
    });
    exportTable.querySelectorAll('[style]').forEach(element => element.removeAttribute('style'));
    return exportTable;
}

function getGradingExportName(extension) {
    const classCode = document.querySelector('.page-header h1')?.textContent.trim() || 'grading-sheet';
    const classInfo = document.querySelector('.page-header p')?.textContent.trim() || '';
    const safeName = `${classCode}-${classInfo}-${period}`.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '');
    return `${safeName || 'grading-sheet'}.${extension}`;
}

function exportVisibleGradingSheet(format) {
    const table = getExportTable();
    if (!table) return;

    if (format === 'excel') {
        if (typeof XLSX === 'undefined') {
            alert('Excel export is unavailable because the spreadsheet library did not load.');
            return;
        }
        const workbook = XLSX.utils.table_to_book(table, { sheet: 'Grading Sheet' });
        XLSX.writeFile(workbook, getGradingExportName('xlsx'));
        return;
    }

    const title = document.querySelector('.page-header h1')?.textContent.trim() || 'Grading Sheet';
    const metadata = document.querySelector('.page-header p')?.textContent.trim() || '';
    const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>${title}</title>
        <style>body{font-family:Calibri,Arial,sans-serif;font-size:10pt}h1,h2,p{text-align:center;margin:4px}
        table{border-collapse:collapse;width:100%}th,td{border:1px solid #777;padding:4px;text-align:center;vertical-align:middle}
        th{background:#d9d9d9;font-weight:700}td:first-child{text-align:left;white-space:nowrap}</style>
        </head><body><h1>GRADING SHEET</h1><h2>${title}</h2><p>${metadata} | ${period.toUpperCase()}</p>${table.outerHTML}</body></html>`;
    const blob = new Blob([html], { type: 'application/msword' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = getGradingExportName('doc');
    link.click();
    URL.revokeObjectURL(link.href);
}

function printVisibleGradingSheet() {
    const table = getExportTable();
    if (!table) return;

    const title = document.querySelector('.page-header h1')?.textContent.trim() || 'Grading Sheet';
    const metadata = document.querySelector('.page-header p')?.textContent.trim() || '';
    const printWindow = window.open('', '_blank', 'width=1200,height=800');
    if (!printWindow) {
        alert('Please allow pop-ups to print the grading sheet.');
        return;
    }
    printWindow.document.write(`<!DOCTYPE html><html><head><title>${title}</title><style>
        @page{size:landscape;margin:8mm}body{font-family:Calibri,Arial,sans-serif;font-size:9pt}
        h1,h2,p{text-align:center;margin:3px}table{border-collapse:collapse;width:100%}
        th,td{border:1px solid #777;padding:3px;text-align:center;vertical-align:middle}
        th{background:#d9d9d9;font-weight:700}td:first-child{text-align:left;white-space:nowrap}
    </style></head><body><h1>GRADING SHEET</h1><h2>${title}</h2><p>${metadata} | ${period.toUpperCase()}</p>${table.outerHTML}</body></html>`);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
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
window.updateCategoryWeight = updateCategoryWeight;
window.exportVisibleGradingSheet = exportVisibleGradingSheet;
window.printVisibleGradingSheet = printVisibleGradingSheet;