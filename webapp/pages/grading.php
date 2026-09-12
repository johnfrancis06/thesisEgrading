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
    <link rel="stylesheet" href="assets/css/style.css?v=3">
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
                <a href="index.php?page=reports&id=<?= $classId ?>" class="btn btn-secondary">
                    <i class="bi bi-file-earmark-arrow-down"></i> Reports
                </a>
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
            
            <!-- Category Management -->
            <div class="card border-info mb-3 fade-in">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Grade Categories</h5>
                    <button class="btn btn-sm btn-light" onclick="showAddCategoryModal('<?= $classId ?>', '<?= $period ?>')">
                        <i class="bi bi-plus"></i> Add Category
                    </button>
                </div>
                <div class="card-body">
                    <div id="categoriesContainer" class="d-flex flex-wrap gap-2">
                        <p class="text-muted">Loading categories...</p>
                    </div>
                    <small class="text-muted">Total Weight: <strong id="totalWeight">0</strong>%</small>
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
            
            <!-- Grading Table -->
            <div class="card fade-in">
                <div class="card-body p-0">
                    <div class="grading-scroll-container">
                        <div class="grading-table-wrapper">
                            <table class="table grading-table" id="gradingTable">
                                <thead>
                                    <tr><th>Student</th></tr>
                                </thead>
                                <tbody id="gradingBody">
                                    <tr><td colspan="15" class="text-center py-3">Loading...</td></tr>
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
    
    <div class="modal fade" id="categoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="categoryModalTitle">Add Grade Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="categoryForm">
                        <div class="form-group">
                            <label for="categoryName" class="form-label">Category Name *</label>
                            <input type="text" class="form-control" id="categoryName" placeholder="e.g., Quizzes, Midterm Exam, Project" required>
                        </div>
                        <div class="form-group">
                            <label for="categoryWeight" class="form-label">Weight Percentage (%) *</label>
                            <input type="number" class="form-control" id="categoryWeight" min="1" max="100" value="20" required>
                            <small class="form-text">Current total weight: <span id="currentTotal">0</span>%</small>
                        </div>
                        <div class="form-group">
                            <label for="categoryPeriod" class="form-label">Period *</label>
                            <select id="categoryPeriod" class="form-select">
                                <option value="midterm">Midterm</option>
                                <option value="final">Final</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="categoryMaxScore" class="form-label">Max Score *</label>
                            <input type="number" class="form-control" id="categoryMaxScore" min="1" max="1000" value="100" required>
                        </div>
                        <div class="alert alert-info" id="weightWarning" style="display: none;">
                            <strong>Note:</strong> Total weight after adding will be <span id="newTotal">0</span>%. Make sure it totals 100% for the period.
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
        loadCategoriesForDisplay(classId, period);
        
        document.querySelectorAll('#periodTabs a').forEach(link => {
            link.addEventListener('click', function(e) {
                const newPeriod = this.getAttribute('href').includes('period=') ? 
                    this.getAttribute('href').split('period=')[1] : 'midterm';
                loadCategoriesForDisplay(classId, newPeriod);
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



