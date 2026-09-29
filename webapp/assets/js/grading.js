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
        perfectScores[key] = getCategoryPerfectScore(config);
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

function getCategoryPerfectScore(config) {
    return (config.items || []).reduce((total, item) => {
        return total + (parseFloat(item.max_score) || 0);
    }, 0);
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
        const perfectScore = getCategoryPerfectScore(config);
        const weight = config.weight_percent;
        
        items.forEach(item => {
            html += `<td class="cell-raw perfect-cell">${parseFloat(item.max_score) || 0}</td>`;
        });
        
        html += `<td class="cell-total perfect-cell">${perfectScore || ''}</td>`;
        html += `<td class="cell-equiv perfect-cell"></td>`;
        html += `<td class="cell-weight perfect-cell"><input type="number" class="weight-input" data-component="${getComponentKeyFromTemplate(config.template_id)}" data-max-weight="${weight}" value="${weight}" readonly style="width: 60px; padding: 3px 4px; text-align: center; border: 1px solid #999; border-radius: 2px; font-weight: bold;"></td>`;
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
        const perfectScore = getCategoryPerfectScore(config);
        const weight = parseFloat(config.weight_percent) || 0;
        
        // Render item inputs
        items.forEach((item, idx) => {
            const itemData = (Array.isArray(compData.items) ? compData.items[idx] : null) || { raw_score: '', max_score: 0, item_id: null };
            const score = itemData.raw_score !== undefined && itemData.raw_score !== null ? itemData.raw_score : '';
            const maxScore = parseFloat(item.max_score) || parseFloat(itemData.max_score) || 100;
            
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
        html += `<td class="cell-weight"><input type="number" class="weight-input" data-student="${student.id}" data-component="${key}" data-max-weight="100" value="${weight.toFixed(2)}" step="0.01" min="0" max="100" oninput="onWeightInput(this, ${student.id}, '${key}')" style="width: 60px; padding: 3px 4px; text-align: center; border: 1px solid #999; border-radius: 2px; font-weight: bold;"></td>`;
    });
    
    // Calculate initial weighted total for display
    let totalWeighted = 0;
    configs.forEach(config => {
        const key = config.template_id ? getComponentKeyFromTemplate(config.template_id) : config.custom_name.toLowerCase().replace(/\s+/g, '_');
        const compData = componentsData[key] || {};
        const perfectScore = getCategoryPerfectScore(config);
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
    
    let allComponentData = {};
    
    // Get configured weights from configs (not editable inputs)
    const weights = {};
    configs.forEach(config => {
        const key = config.template_id ? getComponentKeyFromTemplate(config.template_id) : config.custom_name.toLowerCase().replace(/\s+/g, '_');
        weights[key] = parseFloat(config.weight_percent) / 100 || 0;
    });
    
    // Build component details (from DOM inputs) for each component
    const componentDetails = {};
    
    configs.forEach(config => {
        const key = config.template_id ? getComponentKeyFromTemplate(config.template_id) : config.custom_name.toLowerCase().replace(/\s+/g, '_');
        const perfectScore = perfectScores[key] || getCategoryPerfectScore(config) || 100;
        
        let total = 0;
        const items = [];
        let hasAnyScore = false;
        
        const inputs = document.querySelectorAll(`input.grade-input[data-student="${studentId}"][data-component="${key}"]`);
        inputs.forEach((input, idx) => {
            const score = parseFloat(input.value) || 0;
            const maxScore = parseFloat(input.dataset.max) || 100;
            const hasScore = input.value !== '' && input.value !== null && input.value !== undefined;
            total += score;
            
            items.push({
                label: input.dataset.label || `Item ${idx + 1}`,
                raw_score: hasScore ? score : 0,
                max_score: maxScore,
                has_score: hasScore
            });
            
            if (hasScore) hasAnyScore = true;
        });
        
        const equivScore = perfectScore > 0 ? transmute(total, perfectScore) : 0;
        
        componentDetails[key] = {
            items: items,
            raw_total: total,
            max_total: perfectScore
        };
        
        allComponentData[key] = {
            total: total,
            equiv: equivScore,
            hasAnyScore: hasAnyScore,
            perfectScore: perfectScore,
            configuredWeight: weights[key] * 100
        };
    });
    
    // Calculate period grade using backend formula
    const componentScores = Object.fromEntries(Object.entries(allComponentData).map(([k, v]) => [k, v.equiv]));
    const periodGrade = calculatePeriodGrade(componentScores, componentDetails, weights);
    
    // Calculate grade point
    const gradePoint = getGradePoint(periodGrade);
    
    // Check completeness for this period
    const urlParams = new URLSearchParams(window.location.search);
    const currentPeriod = urlParams.get('period') || 'midterm';
    
    const completeness = checkPeriodCompleteness(
        Object.fromEntries(Object.entries(componentDetails).map(([k, v]) => [k, {
            complete: v.items.length > 0 && v.items.every(item => item.has_score),
            missing_items: v.items.filter(item => !item.has_score).map(item => item.label),
            configured: v.items.length > 0,
            total_items: v.items.length,
            scored_items: v.items.filter(item => item.has_score).length
        }])),
        currentPeriod
    );
    
    const isComplete = completeness.complete;
    const isDropped = false;
    
    const remarks = getRemarks(gradePoint, isComplete, isDropped);
    
    // Update all computed cells for this student
    updateComputedCells(studentId, allComponentData, periodGrade, configs, gradePoint, remarks, isComplete);
}

function updateComputedCells(studentId, allComponentData, periodGrade, configs, gradePoint, remarks, isComplete) {
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
            const data = allComponentData[key];
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
                const dataWeight = data.weight; // effective weight
                const isManuallyEdited = data.isManuallyEdited;
                
                if (!isManuallyEdited) {
                    // Display effective weight (earned weight) instead of configured weight
                    // e.g., 90% score * 20% weight = 18% effective weight
                    input.value = dataWeight > 0 ? dataWeight.toFixed(2) : '';
                }
                // If manually edited, keep the manual value
            }
        });
    } else {
        // Fallback to old behavior
        const compOrder = ['class_participation', 'problem_set', 'quizzes', 'periodical_exam'];
        compOrder.forEach((compKey, idx) => {
            const data = allComponentData[compKey];
            if (!data) return;
            
            if (totalCells[idx]) totalCells[idx].textContent = data.total > 0 ? data.total : '';
            if (equivCells[idx]) equivCells[idx].textContent = data.total > 0 ? data.equiv.toFixed(2) : '';
            if (weightInputs[idx]) {
                const input = weightInputs[idx];
                const dataWeight = data.weight;
                const isManuallyEdited = data.isManuallyEdited;
                
                if (!isManuallyEdited) {
                    // Display effective weight (earned weight)
                    input.value = dataWeight > 0 ? dataWeight.toFixed(2) : '';
                }
            }
        });
    }
    
    // Midterm/Final grade (period grade)
    if (midtermCell) {
        midtermCell.textContent = periodGrade > 0 ? periodGrade.toFixed(2) : '';
    }
    
    // Round off
    if (roundoffCell) {
        roundoffCell.textContent = periodGrade > 0 ? Math.round(periodGrade) : '';
    }
    
    // Remarks - use backend formula
    if (remarksCell) {
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

// Calculate period grade matching backend formula:
// Only weights components that have at least one scored item
// Normalizes by total weight of completed components
function calculatePeriodGrade(componentScores, componentDetails, weights) {
    weights = weights || {
        'class_participation': 0.20,
        'problem_set': 0.20,
        'quizzes': 0.30,
        'periodical_exam': 0.30
    };
    
    let weightedSum = 0;
    let totalWeight = 0;
    
    for (const [component, score] of Object.entries(componentScores)) {
        if (!weights[component]) continue;
        
        // Check if this component has any scored items
        const details = componentDetails[component] || null;
        let hasScores = false;
        if (details && details.items) {
            for (const item of details.items) {
                if (item.has_score && item.raw_score !== null && item.raw_score !== undefined) {
                    hasScores = true;
                    break;
                }
            }
        }
        
        if (hasScores) {
            weightedSum += score * weights[component];
            totalWeight += weights[component];
        }
    }
    
    // Normalize by total weight of completed components
    if (totalWeight > 0) {
        return Math.round(weightedSum / totalWeight * 100) / 100;
    }
    
    return 0;
}

// Calculate overall final grade matching backend:
// If both midterm and final complete: Overall = (Final × 0.6) + (Midterm × 0.4)
// If only one period complete: use that period's grade
function calculateOverallGrade(midtermGrade, finalGrade, midtermComplete, finalComplete) {
    if (midtermComplete && finalComplete) {
        return Math.round((finalGrade * 0.6) + (midtermGrade * 0.4));
    } else if (midtermComplete) {
        return Math.round(midtermGrade);
    } else if (finalComplete) {
        return Math.round(finalGrade);
    }
    return 0;
}

// Determine remarks matching backend:
// DRP = Dropped student
// INC = Incomplete requirements
// Failed = Complete but below 75%
// Passed = Complete and 75% or above
function getRemarks(gradePoint, isComplete, isDropped) {
    if (isDropped) return 'DRP';
    if (!isComplete) return 'INC';
    if (gradePoint === 5.00) return 'Failed';
    return 'Passed';
}

document.addEventListener('hide.bs.modal', function (event) {
if (document.activeElement && event.target.contains(document.activeElement)) {
        document.activeElement.blur();
    }
});

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
window.loadTotalGrades = loadTotalGrades;
window.toggleCompletenessDetails = toggleCompletenessDetails;

// Total Grade Tab - Shows Midterm and Final grades combined (simple view)
async function loadTotalGrades(classId) {
    try {
        const resp = await fetch(`api/index.php?action=get_class_grades&class_id=${classId}`, {
            credentials: 'include'
        });
        const data = await resp.json();
        if (!data.success) {
            console.error('API Error:', data.message);
            return;
        }
        
        // DEBUG: Log the API response
        console.log('Total Grades API Response:', data);
        if (data.data && data.data.grades && data.data.grades.length > 0) {
            console.log('First student grades:', data.data.grades[0]);
        }
        
        renderTotalGradesTable(data.data, classId);
    } catch (e) {
        console.error('Error loading total grades:', e);
        document.getElementById('gradingBody').innerHTML = `<tr><td colspan="50" class="text-center text-danger">Error loading total grades: ${e.message}</td></tr>`;
    }
}

function renderTotalGradesTable(data, classId) {
    const thead = document.getElementById('gradingHead');
    const tbody = document.getElementById('gradingBody');
    const container = document.querySelector('.grading-table-wrapper');
    
    if (!data || !data.grades || data.grades.length === 0) {
        tbody.innerHTML = `<tr><td colspan="50" class="text-center">No students found</td></tr>`;
        return;
    }
    
    const gradesData = data.grades;
    const classInfo = data.class;
    const midtermWeight = classInfo.midterm_weight ? Math.round(classInfo.midterm_weight * 100) : 40;
    const finalWeight = classInfo.final_weight ? Math.round(classInfo.final_weight * 100) : 60;
    
    // Build completeness overview
    const overviewHtml = buildCompletenessOverview(gradesData);
    
    // Insert overview above table
    if (container) {
        const existingOverview = container.querySelector('.completeness-overview');
        if (existingOverview) existingOverview.remove();
        container.insertAdjacentHTML('beforebegin', overviewHtml);
    }
    
    // Simple header - just student name, midterm, final, total
    let headerHTML = '';
    headerHTML += '<tr class="header-row1">';
    headerHTML += '<th rowspan="2" class="col-student" style="min-width: 220px; max-width: 220px;">Name of Students</th>';
    headerHTML += `<th colspan="3" class="header-main bg-info text-white">MIDTERM GRADE (${midtermWeight}%)</th>`;
    headerHTML += `<th colspan="3" class="header-main bg-primary text-white">FINAL GRADE (${finalWeight}%)</th>`;
    headerHTML += '<th rowspan="2" class="header-main header-midterm bg-success text-white" style="min-width: 100px;">TOTAL GRADE</th>';
    headerHTML += '<th rowspan="2" class="header-main header-roundoff bg-success text-white" style="min-width: 80px;">Round off</th>';
    headerHTML += '<th rowspan="2" class="header-main header-remarks bg-success text-white" style="min-width: 90px;">REMARKS</th>';
    headerHTML += '</tr>';
    
    headerHTML += '<tr class="header-row2">';
    headerHTML += '<th class="header-col header-raw bg-info text-white">Grade</th>';
    headerHTML += '<th class="header-col header-equiv bg-info text-white">Grade Point</th>';
    headerHTML += '<th class="header-col header-total bg-info text-white">Remarks</th>';
    headerHTML += '<th class="header-col header-raw bg-primary text-white">Grade</th>';
    headerHTML += '<th class="header-col header-equiv bg-primary text-white">Grade Point</th>';
    headerHTML += '<th class="header-col header-total bg-primary text-white">Remarks</th>';
    headerHTML += '</tr>';
    
    headerHTML += '<tr class="header-row3"></tr>';
    
    thead.innerHTML = headerHTML;
    
    // Build body rows
    let bodyHTML = '';
    gradesData.forEach(student => {
        bodyHTML += buildTotalGradeStudentRow(student);
    });
    
    tbody.innerHTML = bodyHTML || `<tr><td colspan="10" class="text-center">No data</td></tr>`;
    
    // Initialize Bootstrap tooltips for completeness warnings
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

function buildCompletenessOverview(gradesData) {
    const requiredComponents = ['class_participation', 'problem_set', 'quizzes', 'periodical_exam'];
    const componentLabels = {
        'class_participation': 'Class Participation',
        'problem_set': 'Problem Set',
        'quizzes': 'Quizzes',
        'periodical_exam': 'Periodical Exam'
    };
    
    let completeCount = 0;
    let incompleteCount = 0;
    const studentStatus = [];
    
    gradesData.forEach(student => {
        const completeness = student.completeness || {};
        let studentComplete = true;
        const missingDetails = [];
        
        ['midterm', 'final'].forEach(period => {
            const periodData = completeness[period] || {};
            requiredComponents.forEach(comp => {
                const data = periodData[comp];
                if (data && data.configured && !data.complete) {
                    studentComplete = false;
                    missingDetails.push({
                        period: period.charAt(0).toUpperCase() + period.slice(1),
                        component: componentLabels[comp] || comp,
                        missing: data.missing_items,
                        scored: data.scored_items,
                        total: data.total_items
                    });
                }
            });
        });
        
        if (studentComplete) {
            completeCount++;
        } else {
            incompleteCount++;
        }
        
        studentStatus.push({
            name: `${student.last_name}, ${student.first_name} ${student.middle_initial || ''}.`,
            complete: studentComplete,
            missing: missingDetails
        });
    });
    
    let html = `
        <div class="completeness-overview card mb-3">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Grade Completeness Overview</h6>
                <button class="btn btn-sm btn-outline-secondary" onclick="toggleCompletenessDetails()">
                    <i class="bi bi-chevron-down" id="overviewToggleIcon"></i> Details
                </button>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-3">
                        <div class="text-center p-3 bg-success bg-opacity-10 rounded">
                            <div class="display-6 fw-bold text-success">${completeCount}</div>
                            <small class="text-muted">Complete</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center p-3 bg-warning bg-opacity-10 rounded">
                            <div class="display-6 fw-bold text-warning">${incompleteCount}</div>
                            <small class="text-muted">Incomplete</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center p-3 bg-info bg-opacity-10 rounded">
                            <div class="display-6 fw-bold text-info">${gradesData.length}</div>
                            <small class="text-muted">Total Students</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center p-3 bg-secondary bg-opacity-10 rounded">
                            <div class="display-6 fw-bold text-secondary">${gradesData.length > 0 ? Math.round((completeCount / gradesData.length) * 100) : 0}%</div>
                            <small class="text-muted">Completion Rate</small>
                        </div>
                    </div>
                </div>
                
                <div id="completenessDetails" class="collapse">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 40%;">Student</th>
                                    <th style="width: 15%;">Status</th>
                                    <th>Missing Details</th>
                                </tr>
                            </thead>
                            <tbody>`;
    
    studentStatus.forEach(s => {
        const statusBadge = s.complete 
            ? '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Complete</span>'
            : '<span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Incomplete</span>';
        
        let missingHtml = s.complete ? '<span class="text-muted">All requirements met</span>' : '';
        if (!s.complete) {
            missingHtml = '<ul class="mb-0 ps-3 small">';
            s.missing.forEach(m => {
                missingHtml += `<li><strong>${m.period} - ${m.component}:</strong> ${m.missing.join(', ')} <span class="text-muted">(${m.scored}/${m.total} scored)</span></li>`;
            });
            missingHtml += '</ul>';
        }
        
        html += `
            <tr class="${s.complete ? 'table-success' : 'table-warning'}">
                <td class="fw-medium">${s.name}</td>
                <td>${statusBadge}</td>
                <td>${missingHtml}</td>
            </tr>`;
    });
    
    html += `
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>`;
    
    return html;
}

function toggleCompletenessDetails() {
    const collapseEl = document.getElementById('completenessDetails');
    const icon = document.getElementById('overviewToggleIcon');
    if (collapseEl.classList.contains('show')) {
        bootstrap.Collapse.getInstance(collapseEl)?.hide();
        icon.classList.remove('bi-chevron-up');
        icon.classList.add('bi-chevron-down');
    } else {
        new bootstrap.Collapse(collapseEl, { toggle: true });
        icon.classList.remove('bi-chevron-down');
        icon.classList.add('bi-chevron-up');
    }
}

function buildTotalGradeStudentRow(student) {
    const midterm = student.midterm || {};
    const final = student.final || {};
    const overall = student.overall || {};
    const completeness = student.completeness || {};
    const componentScores = student.component_scores || {};
    const isDropped = student.is_dropped || false;
    
    const midtermGrade = midterm.grade !== undefined ? midterm.grade : 0;
    const midtermGradePoint = midterm.grade_point !== undefined ? midterm.grade_point : 0;
    const midtermRemarks = midterm.remarks || '';
    const finalGrade = final.grade !== undefined ? final.grade : 0;
    const finalGradePoint = final.grade_point !== undefined ? final.grade_point : 0;
    const finalRemarks = final.remarks || '';
    const overallGrade = overall.grade !== undefined ? overall.grade : 0;
    const overallGradePoint = overall.grade_point !== undefined ? overall.grade_point : 0;
    const overallRounded = overallGrade !== undefined && overallGrade !== '' ? Math.round(overallGrade) : '';
    const overallRemarks = overall.remarks || '';
    
    // Check completeness for midterm and final
    const midtermCompleteness = checkPeriodCompleteness(completeness, 'midterm');
    const finalCompleteness = checkPeriodCompleteness(completeness, 'final');
    const overallComplete = midtermCompleteness.complete && finalCompleteness.complete;
    
    let html = `<tr data-student="${student.id}"${isDropped ? ' class="table-secondary"' : ''}>`;
    html += `<td class="col-student" style="min-width: 220px; max-width: 220px;">${student.last_name}, ${student.first_name} ${student.middle_initial || ''}.</td>`;
    
    // Midterm
    html += `<td class="cell-raw bg-info">${midtermGrade !== null ? midtermGrade.toFixed(2) : ''}</td>`;
    html += `<td class="cell-equiv bg-info">${midtermGradePoint !== null ? midtermGradePoint.toFixed(2) : ''}</td>`;
    html += `<td class="cell-total bg-info">${midtermRemarks}</td>`;
    
    // Final
    html += `<td class="cell-raw bg-primary">${finalGrade !== null ? finalGrade.toFixed(2) : ''}</td>`;
    html += `<td class="cell-equiv bg-primary">${finalGradePoint !== null ? finalGradePoint.toFixed(2) : ''}</td>`;
    html += `<td class="cell-total bg-primary">${finalRemarks}</td>`;
    
    // Overall - with completeness warning
    const warningIcon = overallComplete && !isDropped ? '' : `
        <i class="bi bi-exclamation-triangle-fill text-warning ms-1" 
           data-bs-toggle="tooltip" 
           data-bs-placement="top"
           title="${buildCompletenessTooltip(midtermCompleteness, finalCompleteness, isDropped)}"
           style="cursor: help;"></i>
    `;
    html += `<td class="cell-midterm cell-readonly bg-success text-white fw-bold" style="min-width: 100px;">${overallGrade !== null && overallGrade !== '' ? overallGrade.toFixed(2) : ''}${warningIcon}</td>`;
    html += `<td class="cell-roundoff cell-readonly bg-success text-white fw-bold" style="min-width: 80px;">${overallRounded !== '' ? overallRounded : ''}</td>`;
    html += `<td class="cell-remarks cell-readonly bg-success text-white fw-bold" style="min-width: 90px;">${overallRemarks}</td>`;
    
    html += '</tr>';
    return html;
}

function checkPeriodCompleteness(completeness, period) {
    const periodData = completeness[period] || {};
    const requiredComponents = ['class_participation', 'problem_set', 'quizzes', 'periodical_exam'];
    let complete = true;
    const missingComponents = [];
    
    requiredComponents.forEach(comp => {
        const data = periodData[comp];
        if (data && data.configured && !data.complete) {
            complete = false;
            missingComponents.push({
                name: formatComponentName(comp),
                missing: data.missing_items,
                scored: data.scored_items,
                total: data.total_items
            });
        }
    });
    
    return { complete, missingComponents };
}

function formatComponentName(comp) {
    const names = {
        'class_participation': 'Class Participation',
        'problem_set': 'Problem Set',
        'quizzes': 'Quizzes',
        'periodical_exam': 'Periodical Exam'
    };
    return names[comp] || comp.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
}

function buildCompletenessTooltip(midtermComp, finalComp, isDropped) {
    if (isDropped) {
        return 'Student is dropped (DRP)';
    }
    let tooltip = 'Incomplete grades:\\n';
    
    if (midtermComp.missingComponents.length > 0) {
        tooltip += '\\nMidterm:';
        midtermComp.missingComponents.forEach(comp => {
            tooltip += `\\n  • ${comp.name}: missing ${comp.missing.join(', ')} (${comp.scored}/${comp.total} scored)`;
        });
    }
    
    if (finalComp.missingComponents.length > 0) {
        tooltip += '\\nFinal:';
        finalComp.missingComponents.forEach(comp => {
            tooltip += `\\n  • ${comp.name}: missing ${comp.missing.join(', ')} (${comp.scored}/${comp.total} scored)`;
        });
    }
    
    return tooltip;
}