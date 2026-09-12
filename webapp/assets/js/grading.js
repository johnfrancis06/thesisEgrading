// Grading Sheet Functions
function getGradeStorageKey(classId, period) {
    return `grading_grades_${classId}_${period}`;
}

function saveGradeToLocalStorage(classId, period, gradeItemId, studentId, score) {
    const key = getGradeStorageKey(classId, period);
    let grades = {};
    try {
        const existing = localStorage.getItem(key);
        if (existing) grades = JSON.parse(existing);
    } catch (e) {}
    const itemKey = `${gradeItemId}_${studentId}`;
    grades[itemKey] = score;
    localStorage.setItem(key, JSON.stringify(grades));
}

function getLocalStorageGrades(classId, period) {
    const key = getGradeStorageKey(classId, period);
    try {
        const existing = localStorage.getItem(key);
        return existing ? JSON.parse(existing) : {};
    } catch (e) {
        return {};
    }
}

function clearLocalStorageGrades(classId, period) {
    const key = getGradeStorageKey(classId, period);
    localStorage.removeItem(key);
}

async function loadGradingSheet(classId, period) {
    try {
        const resp = await fetch(`api/index.php?action=get_grading_sheet&class_id=${classId}&period=${period}`);
        const data = await resp.json();
        if (!data.success) {
            console.error('API Error:', data.message);
            return;
        }
        
        const localGrades = getLocalStorageGrades(classId, period);
        if (data.data && data.data.categories) {
            data.data.categories.forEach(cat => {
                if (cat.items && cat.items.length > 0) {
                    cat.items.forEach(item => {
                        if (!item.scores) item.scores = [];
                        if (data.data.students && data.data.students.length > 0) {
                            data.data.students.forEach(student => {
                                const itemKey = `${item.id}_${student.id}`;
                                if (localGrades[itemKey] !== undefined) {
                                    item.scores = item.scores.filter(s => s.student_id != student.id);
                                    item.scores.push({
                                        student_id: student.id,
                                        raw_score: localGrades[itemKey]
                                    });
                                }
                            });
                        }
                    });
                }
            });
        }
        
        // Check if attendance integration is enabled
        const configResp = await fetch(`api/index.php?action=get_attendance_config&class_id=${classId}`);
        const configData = await configResp.json();
        if (configData.success && configData.data.attendance_late_counts_present) {
            const attResp = await fetch(`api/index.php?action=get_attendance_for_grading&class_id=${classId}`);
            const attData = await attResp.json();
            if (attData.success && attData.data.length > 0) {
                const attMap = {};
                attData.data.forEach(a => { attMap[a.student_id] = a.attendance_rate; });
                
                // Find Class Standing category in current period
                const standingCat = data.data.categories.find(c => c.period === period && c.name.toLowerCase().includes('standing'));
                if (standingCat) {
                    const virtualItem = {
                        id: -1,
                        grade_category_id: standingCat.id,
                        label: 'Attendance',
                        max_score: 100,
                        sort_order: standingCat.items.length,
                        isVirtual: true,
                        scores: data.data.students.map(s => ({
                            student_id: s.id,
                            raw_score: attMap[s.id] || 0
                        }))
                    };
                    standingCat.items.push(virtualItem);
                }
            }
        }
        
        renderGradingTable(data.data, classId);
    } catch (e) {
        console.error('Error loading grading sheet:', e);
        document.getElementById('gradingBody').innerHTML = `<tr><td colspan="20" class="text-center text-danger">Error loading grading sheet: ${e.message}</td></tr>`;
    }
}

function renderGradingTable(data, classId) {
    const table = document.getElementById('gradingTable');
    const thead = table.querySelector('thead');
    const tbody = document.getElementById('gradingBody');
    
    if (!data || !data.students || data.students.length === 0) {
        tbody.innerHTML = `<tr><td colspan="100" class="text-center">No students found</td></tr>`;
        return;
    }
    
    if (!data.categories || data.categories.length === 0) {
        tbody.innerHTML = `<tr><td colspan="100" class="text-center">No grade categories found</td></tr>`;
        return;
    }
    
    // Store category info for calculations
    window.gradingData = data;
    
    // Define colors for categories
    const categoryColors = {
        'Class Standing': '#fff3cd',
        'Problem Set': '#e2f0fb',
        'Quizzes': '#f0f8ff',
        'Exam': '#ffe6e6',
        'default': '#f5f5f5'
    };
    
    const getCategoryColor = (name) => categoryColors[name] || categoryColors['default'];
    
    // First, build all column definitions
    let columns = [];
    let totalCols = 1; // Start with student name
    
    data.categories.forEach((cat, catIdx) => {
        if (cat.items && cat.items.length > 0) {
            cat.items.forEach((item, itemIdx) => {
                columns.push({ type: 'score', catIdx, itemIdx, item, cat });
                totalCols++;
            });
            columns.push({ type: 'catGrade', catIdx, cat });
            totalCols++;
        }
    });
    
    // Add summary columns
    columns.push({ type: 'period' });
    columns.push({ type: 'finalGrade' });
    columns.push({ type: 'gradePoint' });
    columns.push({ type: 'status' });
    totalCols += 4;
    
    // Build main header (category names)
    let headerRow1 = '<tr><th rowspan="2" style="min-width: 200px; background: #f0f0f0; border: 2px solid #999; padding: 10px; vertical-align: middle;">Student</th>';
    
    let categoryColCount = 0;
    data.categories.forEach((cat, catIdx) => {
        if (cat.items && cat.items.length > 0) {
            const itemCount = cat.items.length;
            const colspan = itemCount + 1; // items + category total
            const colWidth = Math.max(140, itemCount * 90);
            
            headerRow1 += `<th colspan="${colspan}" class="text-center category-header" 
                          style="background: ${getCategoryColor(cat.name)}; border: 2px solid #999; cursor: pointer; min-width: ${colWidth}px; padding: 10px;"
                          onclick="toggleCategory(${catIdx})" title="Click to collapse/expand">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="flex: 1;">
                        <strong>${cat.name}</strong> <small>(${cat.weight_percent}%)</small>
                        <i class="bi bi-chevron-down" id="chevron-${catIdx}" style="font-size: 12px; margin-left: 5px;"></i>
                    </span>
                    <button class="btn btn-sm btn-light" onclick="event.stopPropagation(); addGradeItem(${cat.id}, '${cat.name}')" style="margin-left: 5px; padding: 2px 5px; white-space: nowrap;">
                        <i class="bi bi-plus"></i> Add
                    </button>
                </div>
            </th>`;
            categoryColCount += colspan;
        }
    });
    
    headerRow1 += `<th colspan="4" class="text-center summary-sticky" style="background: #f0f0f0; border: 2px solid #999; min-width: 450px; padding: 10px;"><strong>Summary</strong></th></tr>`;
    
    // Build sub-header (item names)
    let headerRow2 = '<tr>';
    
    data.categories.forEach((cat, catIdx) => {
        if (cat.items && cat.items.length > 0) {
            cat.items.forEach((item, itemIdx) => {
                // Determine item type and get icon
                let itemIcon = '📋';
                const itemLabel = item.label.toLowerCase();
                
                if (itemLabel.includes('quiz')) itemIcon = '✓';
                else if (itemLabel.includes('exam')) itemIcon = '📝';
                else if (itemLabel.includes('problem')) itemIcon = '🔧';
                else if (itemLabel.includes('standing')) itemIcon = '👤';
                
                headerRow2 += `<th class="text-center category-item-header" id="cat-${catIdx}-item-${itemIdx}"
                                style="font-size: 11px; background: ${getCategoryColor(cat.name)}; padding: 8px; border: 1px solid #ccc; min-width: 90px; vertical-align: top;">
                    <div style="font-size: 18px; margin: 2px 0;">${item.isVirtual ? '📅' : itemIcon}</div>
                    <div style="font-weight: bold; line-height: 1.2; font-size: 10px;">${item.label}</div>
                    <div style="font-size: 9px; color: #666; margin: 2px 0;">/${item.max_score}</div>
                    ${item.isVirtual ? '<small class="text-info">Auto</small>' : `<button class="btn btn-xs btn-link p-0" onclick="deleteGradeItem(${item.id})" style="font-size: 8px; color: #dc3545; text-decoration: none; margin-top: 2px;">✕</button>`}
                </th>`;
            });
            
            headerRow2 += `<th class="text-center" id="cat-${catIdx}-total" 
                             style="background: ${getCategoryColor(cat.name)}; font-weight: bold; font-size: 11px; border: 2px solid #999; padding: 8px; min-width: 80px; vertical-align: top;">
                             <strong>${cat.name.split(' ')[0]}</strong><br><small>Grade</small></th>`;
        }
    });
    
    headerRow2 += `<th class="summary-sticky" style="background: #f0f0f0; font-size: 11px; border: 2px solid #999; padding: 8px; min-width: 100px; vertical-align: top;"><strong>Period<br>Grade</strong></th>
                     <th class="summary-sticky" style="background: #fff3cd; font-size: 11px; border: 2px solid #999; padding: 8px; min-width: 100px; vertical-align: top;"><strong>Final<br>Grade</strong></th>
                     <th class="summary-sticky" style="background: #c3e6cb; font-size: 11px; border: 2px solid #999; padding: 8px; min-width: 90px; vertical-align: top;"><strong>Grade<br>Point</strong></th>
                     <th class="summary-sticky" style="background: #f0f0f0; font-size: 11px; border: 2px solid #999; padding: 8px; min-width: 90px; vertical-align: top;"><strong>Status</strong></th></tr>`;
    
    thead.innerHTML = headerRow1 + headerRow2;
    
    // Build body rows
    let bodyHtml = '';
    data.students.forEach(student => {
        bodyHtml += `<tr data-student="${student.id}" style="height: 50px;">
            <td style="font-weight: bold; position: sticky; left: 0; background: white; z-index: 10; border-right: 3px solid #999; padding: 8px; vertical-align: middle; min-width: 200px;">
                ${student.last_name}, ${student.first_name}
            </td>`;
        
        let allCategoryGrades = [];
        
        data.categories.forEach((cat, catIndex) => {
            let categoryTotal = 0;
            let categoryMaxScore = 0;
            
            if (cat.items && cat.items.length > 0) {
                cat.items.forEach((item, itemIdx) => {
                    // Get score from item.scores array
                    let score = '';
                    if (item.scores && Array.isArray(item.scores)) {
                        const scoreRecord = item.scores.find(s => s.student_id == student.id);
                        score = scoreRecord ? scoreRecord.raw_score : '';
                    }
                    
                    if (item.isVirtual) {
                        bodyHtml += `<td class="grade-cell category-${catIndex}-item" 
                                    style="text-align: center; padding: 4px; background: ${getCategoryColor(cat.name)}; border: 1px solid #ddd; min-width: 90px; vertical-align: middle;">
                            <span class="badge bg-info" style="font-size: 12px; padding: 8px 12px;">${score !== '' ? parseFloat(score).toFixed(1) : '0.0'}%</span>
                        </td>`;
                    } else {
                        bodyHtml += `<td class="grade-cell category-${catIndex}-item" 
                                    style="text-align: center; padding: 4px; background: ${getCategoryColor(cat.name)}; border: 1px solid #ddd; min-width: 90px; vertical-align: middle;">
                            <input type="number" class="grade-input" 
                                   data-item="${item.id}" data-student="${student.id}" 
                                   data-max="${item.max_score}" data-cat-idx="${catIndex}" 
                                   value="${score || ''}" 
                                   step="1" min="0" max="${item.max_score}"
                                   oninput="saveGradeAndCalculate(${item.id}, ${student.id}, this.value, ${catIndex})"
                                   style="width: 75px; padding: 8px; text-align: center; border: 1px solid #999; border-radius: 3px; font-weight: bold; background: white;">
                        </td>`;
                    }
                    
                    if (score !== '' && score !== null && score !== undefined) {
                        categoryTotal += parseFloat(score);
                    }
                    categoryMaxScore += parseFloat(item.max_score);
                });
                
                // Calculate category grade using transmutation formula
                let catGrade = categoryMaxScore > 0 ? transmute(categoryTotal, categoryMaxScore) : 0;
                allCategoryGrades.push({ weight: cat.weight_percent / 100, grade: catGrade });
                
                bodyHtml += `<td class="cat-grade category-${catIndex}-total" data-student="${student.id}" data-cat="${catIndex}" 
                             style="text-align: center; background: ${getCategoryColor(cat.name)}; font-weight: bold; padding: 8px; border: 2px solid #999; min-width: 80px; vertical-align: middle;">
                             ${catGrade.toFixed(2)}</td>`;
            }
        });
        
        // Calculate period grade (weighted average)
        let periodGrade = 0;
        let totalWeight = 0;
        allCategoryGrades.forEach(cg => {
            periodGrade += cg.grade * cg.weight;
            totalWeight += cg.weight;
        });
        periodGrade = totalWeight > 0 ? periodGrade / totalWeight : 0;
        
        // Get grade point
        let gradePoint = getGradePoint(periodGrade);
        let remarks = periodGrade >= 75 ? 'PASS' : 'FAIL';
        
        bodyHtml += `<td class="period-grade summary-sticky" data-student="${student.id}" 
                    style="text-align: center; background: #f0f0f0; font-weight: bold; padding: 8px; border: 1px solid #999; min-width: 100px; vertical-align: middle;">
                    ${periodGrade.toFixed(2)}</td>`;
        bodyHtml += `<td class="final-grade summary-sticky" data-student="${student.id}" 
                    style="text-align: center; background: #fff3cd; font-weight: bold; padding: 8px; border-radius: 3px; font-size: 16px; border: 1px solid #999; min-width: 100px; vertical-align: middle;">
                    ${Math.round(periodGrade)}</td>`;
        bodyHtml += `<td class="grade-point summary-sticky" data-student="${student.id}" 
                    style="text-align: center; background: #c3e6cb; font-weight: bold; padding: 8px; border-radius: 3px; border: 1px solid #999; min-width: 90px; vertical-align: middle;">
                    ${gradePoint.toFixed(2)}</td>`;
        bodyHtml += `<td class="remarks summary-sticky" data-student="${student.id}" 
                    style="text-align: center; padding: 8px; border: 1px solid #999; min-width: 90px; vertical-align: middle;">
                    <span class="badge ${remarks === 'PASS' ? 'bg-success' : 'bg-danger'}" style="font-size: 11px; padding: 6px 10px;">
                        ${remarks}</span></td>`;
        bodyHtml += '</tr>';
    });
    
    tbody.innerHTML = bodyHtml || `<tr><td colspan="100" class="text-center">No data</td></tr>`;
    
    // Initialize all categories as expanded
    window.collapsedCategories = {};
}

const saveTimers = {};

async function saveGradeAndCalculate(gradeItemId, studentId, score, categoryIndex) {
    const studentKey = `${studentId}`;
    
    if (saveTimers[studentKey]) {
        clearTimeout(saveTimers[studentKey]);
    }
    
    const input = document.querySelector(`input[data-item="${gradeItemId}"][data-student="${studentId}"]`);
    if (input) {
        const maxScore = parseFloat(input.dataset.max) || 100;
        let clamped = Math.min(Math.max(parseFloat(score) || 0, 0), maxScore);
        clamped = Math.round(clamped);
        input.value = clamped;
    }
    
    const urlParams = new URLSearchParams(window.location.search);
    const currentClassId = urlParams.get('id');
    const currentPeriod = urlParams.get('period') || 'midterm';
    saveGradeToLocalStorage(currentClassId, currentPeriod, gradeItemId, studentId, parseFloat(input?.value || score) || 0);
    
    calculateRowGrades(studentId);
    
    saveTimers[studentKey] = setTimeout(async () => {
        try {
            const resp = await fetch('api/index.php?action=save_grade', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    grade_item_id: gradeItemId, 
                    student_id: studentId, 
                    raw_score: parseFloat(input?.value || score) || 0 
                })
            });
            const data = await resp.json();
            if (data.success) {
                saveGradeToLocalStorage(currentClassId, currentPeriod, gradeItemId, studentId, parseFloat(input?.value || score) || 0);
            }
            if (!data.success) {
                console.error('Save failed:', data.message);
            }
        } catch (e) {
            console.error('Error saving grade:', e);
        }
    }, 500);
}

function calculateRowGrades(studentId) {
    if (!window.gradingData) return;
    
    const data = window.gradingData;
    let allCategoryGrades = [];
    
    // Calculate each category grade
    data.categories.forEach((cat, catIndex) => {
        let categoryTotal = 0;
        let categoryMaxScore = 0;
        
        if (cat.items && cat.items.length > 0) {
            cat.items.forEach(item => {
                if (item.isVirtual) {
                    const scoreRecord = item.scores.find(s => s.student_id == studentId);
                    const score = scoreRecord ? Math.round(parseFloat(scoreRecord.raw_score) || 0) : 0;
                    categoryTotal += score;
                    categoryMaxScore += parseFloat(item.max_score);
                } else {
                    const input = document.querySelector(`input[data-item="${item.id}"][data-student="${studentId}"]`);
                    const score = input ? Math.round(parseFloat(input.value) || 0) : 0;
                    
                    categoryTotal += score;
                    categoryMaxScore += parseFloat(item.max_score);
                }
            });
        }
        
        // Transmute the category score
        let catGrade = categoryMaxScore > 0 ? transmute(categoryTotal, categoryMaxScore) : 0;
        allCategoryGrades.push({ weight: cat.weight_percent / 100, grade: catGrade, index: catIndex });
        
        // Update category grade cell
        const catGradeCell = document.querySelector(`.cat-grade[data-student="${studentId}"][data-cat="${catIndex}"]`);
        if (catGradeCell) {
            catGradeCell.textContent = catGrade.toFixed(2);
        }
    });
    
    // Calculate period grade (weighted average)
    let periodGrade = 0;
    let totalWeight = 0;
    allCategoryGrades.forEach(cg => {
        periodGrade += cg.grade * cg.weight;
        totalWeight += cg.weight;
    });
    periodGrade = totalWeight > 0 ? periodGrade / totalWeight : 0;
    
    // Update period grade cell
    const periodCell = document.querySelector(`.period-grade[data-student="${studentId}"]`);
    if (periodCell) {
        periodCell.textContent = periodGrade.toFixed(2);
    }
    
    // Update final grade (rounded)
    const finalCell = document.querySelector(`.final-grade[data-student="${studentId}"]`);
    if (finalCell) {
        finalCell.textContent = Math.round(periodGrade);
    }
    
    // Update grade point
    let gradePoint = getGradePoint(periodGrade);
    const pointCell = document.querySelector(`.grade-point[data-student="${studentId}"]`);
    if (pointCell) {
        pointCell.textContent = gradePoint.toFixed(2);
    }
    
    // Update remarks
    let remarks = periodGrade >= 75 ? 'PASS' : 'FAIL';
    const remarksCell = document.querySelector(`.remarks[data-student="${studentId}"]`);
    if (remarksCell) {
        const badge = remarksCell.querySelector('.badge');
        if (badge) {
            badge.textContent = remarks;
            badge.className = `badge ${remarks === 'PASS' ? 'bg-success' : 'bg-danger'}`;
        }
    }
}

// Toggle category collapse/expand
function toggleCategory(catIdx) {
    if (!window.collapsedCategories) window.collapsedCategories = {};
    
    window.collapsedCategories[catIdx] = !window.collapsedCategories[catIdx];
    const isCollapsed = window.collapsedCategories[catIdx];
    
    // Toggle visibility of all items in this category
    const items = document.querySelectorAll(`.category-${catIdx}-item`);
    const total = document.querySelector(`.category-${catIdx}-total`);
    const chevron = document.getElementById(`chevron-${catIdx}`);
    
    items.forEach(item => {
        item.style.display = isCollapsed ? 'none' : 'table-cell';
    });
    
    if (total) {
        total.style.display = isCollapsed ? 'none' : 'table-cell';
    }
    
    if (chevron) {
        chevron.style.transform = isCollapsed ? 'rotate(-90deg)' : 'rotate(0deg)';
        chevron.style.transition = 'transform 0.3s ease';
    }
}

// Transmutation formula: (raw/max)*50+50
function transmute(raw, max) {
    if (max <= 0) return 0;
    return (raw / max) * 50 + 50;
}

// Grade point lookup
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

// Add Grade Item to Category
async function addGradeItem(categoryId, categoryName) {
    const label = prompt(`Add new item to ${categoryName}:`, `${categoryName} ${Math.floor(Math.random() * 10)}`);
    if (!label) return;
    
    const maxScore = prompt('Max score:', '10');
    if (!maxScore) return;
    
    try {
        const resp = await fetch('api/index.php?action=add_grade_item', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ category_id: categoryId, label, max_score: parseFloat(maxScore) })
        });
        const data = await resp.json();
        if (data.success) {
            alert('Grade item added!');
            // Reload the grading sheet
            const urlParams = new URLSearchParams(window.location.search);
            const classId = urlParams.get('id');
            const period = urlParams.get('period') || 'midterm';
            loadGradingSheet(classId, period);
        } else {
            alert('Error: ' + data.message);
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Delete Grade Item
async function deleteGradeItem(itemId) {
    if (!confirm('Delete this grade item?')) return;
    
    try {
        const resp = await fetch('api/index.php?action=delete_grade_item', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: itemId })
        });
        const data = await resp.json();
        if (data.success) {
            const urlParams = new URLSearchParams(window.location.search);
            const classId = urlParams.get('id');
            const period = urlParams.get('period') || 'midterm';
            clearLocalStorageGrades(classId, period);
            alert('Grade item deleted!');
            loadGradingSheet(classId, period);
        } else {
            alert('Error: ' + data.message);
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}

// Category Management Functions
async function loadCategoriesForDisplay(classId, period) {
    try {
        const resp = await fetch(`api/index.php?action=get_categories_by_period&class_id=${classId}&period=${period}`);
        const data = await resp.json();
        if (!data.success) return;
        
        displayCategories(data.data);
    } catch (e) {
        console.error('Error loading categories:', e);
    }
}

function displayCategories(categories) {
    const container = document.getElementById('categoriesContainer');
    let html = '';
    let totalWeight = 0;
    
    categories.forEach(cat => {
        totalWeight += parseFloat(cat.weight_percent);
        html += `
        <div class="card" style="flex: 0 0 auto; min-width: 250px;">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h6 class="mb-1">${cat.name}</h6>
                        <small class="text-muted">${cat.period.toUpperCase()}</small>
                    </div>
                    <div class="btn-group" role="group">
                        <button class="btn btn-sm btn-outline-primary" onclick="showEditCategoryModal(${cat.id}, '${cat.name}', ${cat.weight_percent})">
                            ✎ Edit
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteCategory(${cat.id})">
                            ✕ Delete
                        </button>
                    </div>
                </div>
                <div class="progress" style="height: 25px;">
                    <div class="progress-bar bg-info" style="width: ${Math.min(cat.weight_percent, 100)}%;">
                        <span style="font-weight: bold; color: white;">${cat.weight_percent}%</span>
                    </div>
                </div>
            </div>
        </div>
        `;
    });
    
    container.innerHTML = html || '<p class="text-muted">No categories yet. Add one to get started!</p>';
    document.getElementById('totalWeight').textContent = totalWeight;
    
    // Show warning if total is not 100%
    if (totalWeight !== 100) {
        const warning = document.createElement('div');
        warning.className = 'alert alert-warning mt-2';
        warning.innerHTML = `<strong>Warning:</strong> Total weight is ${totalWeight}%, but should be 100% for proper grading.`;
        container.parentElement.appendChild(warning);
    }
}

function showAddCategoryModal(classId, period) {
    document.getElementById('categoryModalTitle').textContent = 'Add Grade Category';
    document.getElementById('categoryForm').reset();
    document.getElementById('categoryWeight').value = '20';
    document.getElementById('categoryPeriod').value = period;
    document.getElementById('categoryMaxScore').value = '100';
    
    window.editingCategoryId = null;
    window.currentClassId = classId;
    window.currentPeriod = period;
    
    updateWeightWarning();
    
    const modal = new bootstrap.Modal(document.getElementById('categoryModal'));
    modal.show();
}

function showEditCategoryModal(catId, name, weight) {
    document.getElementById('categoryModalTitle').textContent = 'Edit Grade Category';
    document.getElementById('categoryName').value = name;
    document.getElementById('categoryWeight').value = weight;
    document.getElementById('categoryPeriod').value = window.currentPeriod;
    document.getElementById('categoryMaxScore').value = '100';
    
    window.editingCategoryId = catId;
    
    updateWeightWarning();
    
    const modal = new bootstrap.Modal(document.getElementById('categoryModal'));
    modal.show();
}

function updateWeightWarning() {
    const currentWeight = parseFloat(document.getElementById('categoryWeight').value) || 0;
    const otherWeights = document.querySelectorAll('.card .progress-bar');
    
    let totalOther = 0;
    otherWeights.forEach(bar => {
        const parentCard = bar.closest('.card');
        if (!parentCard || !parentCard.textContent.includes('Edit')) {
            const text = bar.textContent.match(/(\d+)/);
            if (text && !window.editingCategoryId) {
                totalOther += parseInt(text[1]);
            }
        }
    });
    
    const newTotal = totalOther + currentWeight;
    const warning = document.getElementById('weightWarning');
    
    if (newTotal !== 100) {
        warning.style.display = 'block';
        document.getElementById('newTotal').textContent = newTotal;
    } else {
        warning.style.display = 'none';
    }
    
    document.getElementById('currentTotal').textContent = totalOther;
}

async function saveCategory() {
    const name = document.getElementById('categoryName').value.trim();
    const weight = parseFloat(document.getElementById('categoryWeight').value);
    
    if (!name) {
        alert('Category name is required');
        return;
    }
    
    if (isNaN(weight) || weight <= 0 || weight > 100) {
        alert('Weight must be a number between 1 and 100');
        return;
    }
    
    const isEditing = window.editingCategoryId;
    const endpoint = isEditing ? 'update_category' : 'add_category';
    const period = document.getElementById('categoryPeriod').value;
    const maxScore = parseFloat(document.getElementById('categoryMaxScore').value) || 100;
    const body = isEditing 
        ? { id: window.editingCategoryId, name, period, weight }
        : { class_id: window.currentClassId, period, name, weight, max_score: maxScore };
    
    try {
        const resp = await fetch(`api/index.php?action=${endpoint}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        
        const data = await resp.json();
        if (data.success) {
            const modalEl = document.getElementById('categoryModal');
            if (document.activeElement && modalEl.contains(document.activeElement)) {
                document.activeElement.blur();
            }
            const modal = bootstrap.Modal.getInstance(modalEl);
            modal.hide();
            
            // Reload everything
            const urlParams = new URLSearchParams(window.location.search);
            const classId = urlParams.get('id') || window.currentClassId;
            const period = urlParams.get('period') || window.currentPeriod;
            
            loadCategoriesForDisplay(classId, period);
            loadGradingSheet(classId, period);
            
            alert(isEditing ? 'Category updated!' : 'Category added!');
        } else {
            alert('Error: ' + data.message);
        }
    } catch (e) {
        alert('Error saving category: ' + e.message);
    }
}

async function deleteCategory(catId) {
    if (!confirm('Delete this category? All grade items in this category will also be deleted.')) return;
    
    try {
        const resp = await fetch('api/index.php?action=delete_category', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: catId })
        });
        
        const data = await resp.json();
        if (data.success) {
            const urlParams = new URLSearchParams(window.location.search);
            const classId = urlParams.get('id');
            const period = urlParams.get('period') || 'midterm';
            clearLocalStorageGrades(classId, period);
            loadCategoriesForDisplay(classId, period);
            loadGradingSheet(classId, period);
            
            alert('Category deleted!');
        } else {
            alert('Error: ' + data.message);
        }
    } catch (e) {
        alert('Error deleting category: ' + e.message);
    }
}

document.addEventListener('hide.bs.modal', function (event) {
    if (document.activeElement && event.target.contains(document.activeElement)) {
        document.activeElement.blur();
    }
});
