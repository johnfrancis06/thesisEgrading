<?php
$db = Database::getInstance()->getConnection();
$faculty_id = $auth->getFacultyId();
$classId = intval($_GET['id'] ?? 0);
$period = $_GET['period'] ?? 'midterm';

if ($classId > 0) {
    $class = $db->query("SELECT cs.*, s.code, s.title FROM class_section cs 
        JOIN subject s ON cs.subject_id = s.id WHERE cs.id = $classId AND cs.faculty_id = $faculty_id")->fetch_assoc();

    if (!$class) {
        die("Class not found");
    }
} else {
    $classes = $db->query("SELECT cs.*, s.code, s.title FROM class_section cs 
        JOIN subject s ON cs.subject_id = s.id WHERE cs.faculty_id = $faculty_id ORDER BY cs.academic_year DESC, cs.semester DESC")->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $classId > 0 ? 'Grading Sheet - ' . APP_NAME : 'Select a Class - ' . APP_NAME ?></title>
    <link href="assets/vendor/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css?v=6">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <button class="mobile-toggle" id="mobileToggle">
                    <i class="bi bi-list"></i>
                </button>
                <h1 class="topbar-title"><?= $classId > 0 ? 'Grading Sheet' : 'Select a Class' ?></h1>
            </div>
            <div class="topbar-right">
                <?php if ($classId > 0): ?>
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-outline-secondary btn-sm" onclick="showCategoryManager()">
                        <i class="bi bi-gear me-1"></i> Manage Categories
                    </button>
                    <span class="badge bg-secondary period-badge" data-period="midterm">
                        Midterm (40%)
                    </span>
                    <span class="badge bg-primary period-badge" data-period="final">
                        Final (60%)
                    </span>
                    <a href="index.php?page=reports&id=<?= $classId ?>" class="btn btn-secondary">
                        <i class="bi bi-file-earmark-arrow-down"></i> Reports
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="content-area">
            <?php if ($classId > 0): ?>
            <div class="page-header fade-in">
                <div class="page-header-left">
                    <div class="page-header-icon">
                        <i class="bi bi-pencil-square"></i>
                    </div>
                    <div>
                        <h1 class="mb-0"><?= htmlspecialchars($class['code']) ?></h1>
                        <p class="text-muted mb-0"><?= htmlspecialchars($class['course_program']) ?> Yr<?= $class['year_level'] ?>-<?= $class['section'] ?> - <?= $class['academic_year'] ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Perfect Scores Setup - Collapsible Category -->
            <div class="card border-info mb-3 fade-in perfect-score-category" id="perfectScoreCategory">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center category-header" onclick="togglePerfectScoreCategory()" style="cursor: pointer;">
                    <h5 class="mb-0"><i class="bi bi-trophy me-2"></i>Perfect Scores (Highest Possible) - <?= ucfirst($period) ?></h5>
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-chevron-down" id="perfectScoreChevron" style="font-size: 1.2rem; transition: transform 0.3s ease;"></i>
                        <button class="btn btn-sm btn-light" onclick="event.stopPropagation(); showPerfectScoreModal()">
                            <i class="bi bi-pencil-square"></i> Edit
                        </button>
                    </div>
                </div>
                <div class="card-body category-body" id="perfectScoreBody">
                    <div class="row g-3" id="perfectScoresDisplay">
                        <div class="col-md-3">
                            <label class="form-label">Class Participation</label>
                            <input type="number" class="form-control perfect-score-input" data-component="class_participation" placeholder="Enter perfect score" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Problem Set</label>
                            <input type="number" class="form-control perfect-score-input" data-component="problem_set" placeholder="Enter perfect score" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Quizzes</label>
                            <input type="number" class="form-control perfect-score-input" data-component="quizzes" placeholder="Enter perfect score" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Periodical Exam</label>
                            <input type="number" class="form-control perfect-score-input" data-component="periodical_exam" placeholder="Enter perfect score" readonly>
                        </div>
                    </div>
                    <small class="text-muted">These are the highest possible scores for each component. They are used in the transmutation formula: (raw/perfect)*50+50</small>
                </div>
            </div>
            
            <!-- Period Tabs -->
            <ul class="nav nav-tabs mb-3 fade-in" id="periodTabs">
                <li class="nav-item d-flex align-items-center gap-2">
                    <a class="nav-link <?= $period === 'midterm' ? 'active' : '' ?>" href="index.php?page=grading&id=<?= $classId ?>&period=midterm">
                        Midterm
                    </a>
                    <span class="badge bg-info weight-editable" data-period="midterm" data-weight="<?= round($class['midterm_weight'] * 100) ?>" style="cursor: pointer; font-size: 0.85rem;" title="Click to edit weight">
                        <?= round($class['midterm_weight'] * 100) ?>% <i class="bi bi-pencil-square ms-1"></i>
                    </span>
                </li>
                <li class="nav-item d-flex align-items-center gap-2">
                    <a class="nav-link <?= $period === 'final' ? 'active' : '' ?>" href="index.php?page=grading&id=<?= $classId ?>&period=final">
                        Final
                    </a>
                    <span class="badge bg-info weight-editable" data-period="final" data-weight="<?= round($class['final_weight'] * 100) ?>" style="cursor: pointer; font-size: 0.85rem;" title="Click to edit weight">
                        <?= round($class['final_weight'] * 100) ?>% <i class="bi bi-pencil-square ms-1"></i>
                    </span>
                </li>
            </ul>
            
            <!-- Grading Table Container -->
            <div class="card fade-in">
                <div class="card-body p-0">
                    <div class="grading-scroll-container">
                        <div class="grading-table-wrapper">
                            <table class="table grading-table-excel" id="gradingTable">
                                <thead id="gradingHead"></thead>
                                <tbody id="gradingBody">
                                    <tr><td colspan="50" class="text-center py-3">Loading...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="page-header fade-in">
                <div class="page-header-left">
                    <div class="page-header-icon">
                        <i class="bi bi-mortarboard"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">Select a Class</h1>
                        <p class="text-muted mb-0">Choose a class to start grading</p>
                    </div>
                </div>
            </div>
            
            <div class="row g-3 fade-in">
                <?php foreach ($classes as $cls): ?>
                <div class="col-md-6 col-lg-4">
                    <a href="index.php?page=grading&id=<?= $cls['id'] ?>" class="card card-hover text-decoration-none h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="card-title mb-0 text-dark"><?= htmlspecialchars($cls['code']) ?></h5>
                                <span class="badge badge-primary"><?= htmlspecialchars($cls['academic_year']) ?></span>
                            </div>
                            <p class="text-muted small mb-2"><?= htmlspecialchars($cls['course_program']) ?> Yr<?= $cls['year_level'] ?>-<?= $cls['section'] ?></p>
                            <div class="d-flex gap-2">
                                <span class="badge badge-info"><?= htmlspecialchars($cls['semester']) ?></span>
                            </div>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
</div>
     
     <!-- Category Manager Modal -->
     <div class="modal fade" id="categoryManagerModal" tabindex="-1" aria-hidden="true">
         <div class="modal-dialog modal-xl modal-dialog-scrollable">
             <div class="modal-content">
                 <div class="modal-header">
                     <h5 class="modal-title"><i class="bi bi-gear me-2"></i>Manage Categories & Items - <span id="catMgrPeriodLabel"><?= ucfirst($period) ?></span></h5>
                     <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                 </div>
                 <div class="modal-body">
                     <div class="row">
                         <div class="col-md-4">
                             <div class="card">
                                 <div class="card-header d-flex justify-content-between align-items-center">
                                     <h6 class="mb-0">Categories (<?= ucfirst($period) ?>)</h6>
                                     <button class="btn btn-sm btn-primary" onclick="showAddCategoryModal()">
                                         <i class="bi bi-plus"></i> Add
                                     </button>
                                 </div>
                                 <div class="card-body p-0">
                                     <ul class="list-group list-group-flush" id="categoryList">
                                         <li class="list-group-item text-center text-muted py-4">Loading...</li>
                                     </ul>
                                 </div>
                             </div>
                         </div>
                         <div class="col-md-8">
                             <div class="card">
                                 <div class="card-header d-flex justify-content-between align-items-center">
                                     <h6 class="mb-0" id="selectedCategoryTitle">Grade Items</h6>
                                     <button class="btn btn-sm btn-success" onclick="showAddItemModal()" id="addItemBtn" style="display:none;">
                                         <i class="bi bi-plus"></i> Add Item
                                     </button>
                                 </div>
                                 <div class="card-body p-0">
                                     <div class="table-responsive">
                                         <table class="table table-sm table-hover mb-0" id="itemsTable">
                                             <thead class="table-light">
                                                 <tr>
                                                     <th style="width: 40px;">#</th>
                                                     <th>Label</th>
                                                     <th style="width: 100px;">Max Score</th>
                                                     <th style="width: 100px;">Sort</th>
                                                     <th style="width: 100px;">Actions</th>
                                                 </tr>
                                             </thead>
                                             <tbody>
                                                 <tr><td colspan="5" class="text-center text-muted py-4">Select a category to manage items</td></tr>
                                             </tbody>
                                         </table>
                                     </div>
                                 </div>
                             </div>
                         </div>
                     </div>
                 </div>
                 <div class="modal-footer">
                     <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                 </div>
             </div>
         </div>
     </div>

     <!-- Add/Edit Category Modal -->
     <div class="modal fade" id="categoryModal" tabindex="-1" aria-hidden="true">
         <div class="modal-dialog">
             <div class="modal-content">
                 <div class="modal-header">
                     <h5 class="modal-title" id="categoryModalTitle">Add Category</h5>
                     <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                 </div>
                 <div class="modal-body">
                     <form id="categoryForm">
                         <input type="hidden" id="categoryId" name="id">
                         <input type="hidden" id="categoryPeriod" name="period" value="<?= $period ?>">
                         <input type="hidden" id="categoryClassId" name="class_id" value="<?= $classId ?>">
                         <div class="mb-3">
                             <label class="form-label">Category Name</label>
                             <input type="text" class="form-control" id="categoryName" name="name" required placeholder="e.g., Class Participation">
                         </div>
                         <div class="row g-3">
                             <div class="col-md-6">
                                 <label class="form-label">Weight (%)</label>
                                 <input type="number" class="form-control" id="categoryWeight" name="weight_percent" min="0" max="100" step="0.01" required>
                             </div>
                             <div class="col-md-6">
                                 <label class="form-label">Sort Order</label>
                                 <input type="number" class="form-control" id="categorySort" name="sort_order" min="0" value="0">
                             </div>
                         </div>
                     </form>
                 </div>
                 <div class="modal-footer">
                     <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                     <button type="button" class="btn btn-primary" onclick="saveCategory()">Save Category</button>
                 </div>
             </div>
         </div>
     </div>

     <!-- Add/Edit Grade Item Modal -->
     <div class="modal fade" id="gradeItemModal" tabindex="-1" aria-hidden="true">
         <div class="modal-dialog">
             <div class="modal-content">
                 <div class="modal-header">
                     <h5 class="modal-title" id="gradeItemModalTitle">Add Grade Item</h5>
                     <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                 </div>
                 <div class="modal-body">
                     <form id="gradeItemForm">
                         <input type="hidden" id="gradeItemId" name="id">
                         <input type="hidden" id="gradeItemCategoryId" name="category_id">
                         <div class="mb-3">
                             <label class="form-label">Item Label</label>
                             <input type="text" class="form-control" id="gradeItemLabel" name="label" required placeholder="e.g., Quiz 1">
                         </div>
                         <div class="row g-3">
                             <div class="col-md-6">
                                 <label class="form-label">Max Score</label>
                                 <input type="number" class="form-control" id="gradeItemMaxScore" name="max_score" min="1" step="1" required>
                             </div>
                             <div class="col-md-6">
                                 <label class="form-label">Sort Order</label>
                                 <input type="number" class="form-control" id="gradeItemSort" name="sort_order" min="0" value="0">
                             </div>
                         </div>
                     </form>
                 </div>
                 <div class="modal-footer">
                     <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                     <button type="button" class="btn btn-primary" onclick="saveGradeItem()">Save Item</button>
                 </div>
             </div>
         </div>
     </div>

     <!-- Perfect Score Modal -->
     <div class="modal fade" id="perfectScoreModal" tabindex="-1">
         <div class="modal-dialog">
             <div class="modal-content">
                 <div class="modal-header">
                     <h5 class="modal-title">Edit Perfect Scores - <span id="modalPeriodLabel"><?= ucfirst($period) ?></span></h5>
                     <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                 </div>
                 <div class="modal-body">
                     <form id="perfectScoreForm">
                         <input type="hidden" id="perfectScoreClassId" value="<?= $classId ?>">
                         <input type="hidden" id="perfectScorePeriod" value="<?= $period ?>">
                         <div class="row g-3">
                             <div class="col-md-6">
                                 <label class="form-label">Class Participation <small class="text-muted">(4 items for final, 2 for midterm)</small></label>
                                 <input type="number" class="form-control" id="ps_class_participation" min="1" step="1" placeholder="e.g., 100" required>
                             </div>
                             <div class="col-md-6">
                                 <label class="form-label">Problem Set</label>
                                 <input type="number" class="form-control" id="ps_problem_set" min="1" step="1" placeholder="e.g., 50" required>
                             </div>
                             <div class="col-md-6">
                                 <label class="form-label">Quizzes <small class="text-muted">(2 items)</small></label>
                                 <input type="number" class="form-control" id="ps_quizzes" min="1" step="1" placeholder="e.g., 50" required>
                             </div>
                             <div class="col-md-6">
                                 <label class="form-label">Periodical Exam</label>
                                 <input type="number" class="form-control" id="ps_periodical_exam" min="1" step="1" placeholder="e.g., 100" required>
                             </div>
                         </div>
                     </form>
                 </div>
                 <div class="modal-footer">
                     <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                     <button type="button" class="btn btn-primary" onclick="savePerfectScores()">Save Perfect Scores</button>
                 </div>
             </div>
         </div>
     </div>

     <script src="assets/vendor/bootstrap.bundle.min.js"></script>
     <script src="assets/js/grading.js"></script>
    <?php if ($classId > 0): ?>
    <script>
        document.getElementById('mobileToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        });
        
        const classId = <?= $classId ?>;
        const period = '<?= $period ?>';
        loadGradingSheet(classId, period);
        loadPerfectScores(classId, period);
        
        document.querySelectorAll('#periodTabs a').forEach(link => {
            link.addEventListener('click', function(e) {
                const newPeriod = this.getAttribute('href').includes('period=') ? 
                    this.getAttribute('href').split('period=')[1] : 'midterm';
                loadPerfectScores(classId, newPeriod);
            });
        });

        // Editable weight functionality
        document.querySelectorAll('.weight-editable').forEach(badge => {
            badge.addEventListener('click', function() {
                const period = this.dataset.period;
                const currentWeight = this.dataset.weight;
                
                const input = document.createElement('input');
                input.type = 'number';
                input.className = 'form-control form-control-sm';
                input.value = currentWeight;
                input.style.width = '80px';
                input.style.display = 'inline-block';
                input.min = '0';
                input.max = '100';
                input.step = '1';
                
                const originalText = this.innerHTML;
                this.innerHTML = '';
                this.appendChild(input);
                input.focus();
                input.select();
                
                const saveWeight = async () => {
                    const newWeight = parseFloat(input.value);
                    if (isNaN(newWeight) || newWeight < 0 || newWeight > 100) {
                        this.innerHTML = originalText;
                        return;
                    }
                    
                    const midtermW = period === 'midterm' ? newWeight : <?= round($class['midterm_weight'] * 100) ?>;
                    const finalW = period === 'final' ? newWeight : <?= round($class['final_weight'] * 100) ?>;
                    
                    const resp = await fetch('api/index.php?action=update_class_weights', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            class_id: <?= $classId ?>,
                            midterm_weight: midtermW,
                            final_weight: finalW
                        })
                    }).then(r => r.json());
                    
                    if (resp.success) {
                        this.dataset.weight = newWeight;
                        this.innerHTML = `${newWeight}% <i class="bi bi-pencil-square ms-1"></i>`;
                    } else {
                        this.innerHTML = originalText;
                        alert('Error: ' + resp.message);
                    }
                };
                
                input.addEventListener('blur', saveWeight);
                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        saveWeight();
                    } else if (e.key === 'Escape') {
                        this.innerHTML = originalText;
                    }
                });
            });
        });
    </script>
    <?php endif; ?>
</body>
</html>