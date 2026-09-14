<?php
$db = Database::getInstance()->getConnection();
$faculty_id = $auth->getFacultyId();
$classId = intval($_GET['id'] ?? 0);

$classes = [];
if ($classId <= 0) {
    $classes = $db->query("SELECT cs.*, s.code, s.title FROM class_section cs 
        JOIN subject s ON cs.subject_id = s.id WHERE cs.faculty_id = $faculty_id 
        ORDER BY cs.academic_year DESC, cs.semester DESC")->fetch_all(MYSQLI_ASSOC);
} else {
    $class = $db->query("SELECT cs.*, s.code, s.title FROM class_section cs 
        JOIN subject s ON cs.subject_id = s.id WHERE cs.id = $classId")->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $classId <= 0 ? 'Select a Class' : 'Reports - ' . htmlspecialchars($class['code']) ?> - <?= APP_NAME ?></title>
    <link href="assets/vendor/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css?v=8">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <button class="mobile-toggle" id="mobileToggle">
                    <i class="bi bi-list"></i>
                </button>
                <h1 class="topbar-title"><?= $classId <= 0 ? 'Reports' : 'Reports' ?></h1>
            </div>
        </div>
        
        <div class="content-area">
            <?php if ($classId <= 0): ?>
                <div class="page-header fade-in">
                    <div class="page-header-left">
                        <div class="page-header-icon">
                            <i class="bi bi-folder2-open"></i>
                        </div>
                        <div>
                            <h1 class="mb-0">Select a Class</h1>
                            <p class="text-muted mb-0">Choose a class to generate reports</p>
                        </div>
                    </div>
                </div>
                
                <?php if (empty($classes)): ?>
                    <div class="card fade-in">
                        <div class="card-body text-center py-5">
                            <div class="empty-state">
                                <i class="bi bi-inbox"></i>
                                <p>No classes found. Create a class first to generate reports.</p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($classes as $cs): ?>
                            <div class="col-md-6 col-lg-4">
                                <a href="index.php?page=reports&id=<?= $cs['id'] ?>" class="card card-hover text-decoration-none">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <span class="badge badge-primary"><?= htmlspecialchars($cs['code']) ?></span>
                                            <small class="text-muted"><?= htmlspecialchars($cs['academic_year']) ?></small>
                                        </div>
                                        <h5 class="mb-1 text-dark"><?= htmlspecialchars($cs['course_program']) ?> Yr<?= $cs['year_level'] ?> - <?= htmlspecialchars($cs['section']) ?></h5>
                                        <p class="text-muted mb-0 small"><?= htmlspecialchars($cs['title']) ?></p>
                                        <div class="mt-3 pt-3 border-top">
                                            <span class="badge badge-info"><?= $cs['semester'] == 1 ? '1st Semester' : '2nd Semester' ?></span>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="page-header fade-in">
                    <div class="page-header-left">
                        <div class="page-header-icon">
                            <i class="bi bi-file-earmark-arrow-down"></i>
                        </div>
                        <div>
                            <h1 class="mb-0"><?= htmlspecialchars($class['code']) ?></h1>
                            <p class="text-muted mb-0"><?= htmlspecialchars($class['course_program']) ?> Yr<?= $class['year_level'] ?>-<?= $class['section'] ?> - <?= $class['academic_year'] ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- GE-104 Report Types -->
                <div class="row g-3 mb-4 fade-in">
                    <!-- Grading Sheet - Summary roster -->
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 border-info">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>Grading Sheet</h5>
                            </div>
                            <div class="card-body text-center py-5">
                                <div class="stat-icon purple mx-auto mb-3">
                                    <i class="bi bi-journal-check"></i>
                                </div>
                                <h5 class="mb-2">Grading Sheet</h5>
                                <p class="text-muted mb-4">Condensed roster: Midterm/Final ratings, Grade Point, Unit Credit</p>
                                <div class="d-flex flex-column gap-2">
                                    <button class="btn btn-danger" onclick="exportReport('grading_sheet', <?= $classId ?>, 'pdf')">
                                        <i class="bi bi-file-earmark-pdf me-1"></i> Export PDF
                                    </button>
                                    <button class="btn btn-success" onclick="exportReport('grading_sheet', <?= $classId ?>, 'xlsx')">
                                        <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
                                    </button>
                                    <button class="btn btn-primary" onclick="exportReport('grading_sheet', <?= $classId ?>, 'doc')">
                                        <i class="bi bi-file-earmark-word me-1"></i> Export Word
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Attendance Report -->
                <div class="row g-3 mb-4 fade-in">
                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-header bg-secondary text-white">
                                <h5 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Attendance Report</h5>
                            </div>
                            <div class="card-body text-center py-5">
                                <div class="stat-icon teal mx-auto mb-3">
                                    <i class="bi bi-calendar-event"></i>
                                </div>
                                <h5 class="mb-2">Attendance Summary</h5>
                                <p class="text-muted mb-4">Student attendance with daily breakdown and rates</p>
                                <div class="d-flex gap-2 justify-content-center">
                                    <button class="btn btn-primary" onclick="exportAttendance(<?= $classId ?>, 'csv')">
                                        <i class="bi bi-download"></i> Export CSV
                                    </button>
                                    <button class="btn btn-success" onclick="exportAttendance(<?= $classId ?>, 'xlsx')">
                                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Preview Area -->
                <div class="card fade-in">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-eye me-2"></i>Grading Sheet Preview</span>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" onclick="previewReport('grading_sheet', <?= $classId ?>)">
                                <i class="bi bi-journal-text me-1"></i> Grading Sheet
                            </button>
                            <button class="btn btn-outline-primary" id="editReportBtn" onclick="toggleReportEditing()" disabled>
                                <i class="bi bi-pencil me-1"></i> Edit Content
                            </button>
                            <button class="btn btn-outline-success" id="saveReportBtn" onclick="saveReportDraft()" disabled>
                                <i class="bi bi-save me-1"></i> Save Draft
                            </button>
                            <button class="btn btn-outline-dark" id="printEditedReportBtn" onclick="printEditedReport()" disabled>
                                <i class="bi bi-printer me-1"></i> Print Edited
                            </button>
                            <button class="btn btn-outline-info" id="editLogoBtn" onclick="openLogoEditor()" disabled>
                                <i class="bi bi-image me-1"></i> Edit Logo
                            </button>
                        </div>
                    </div>
                    <div class="card-body" id="classRecordPreview">
                        <div class="empty-state">
                            <i class="bi bi-file-earmark"></i>
                            <p>Select a report type above to generate preview</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal fade" id="logoEditorModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-image me-2"></i>Edit University Logo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="file" id="logoFileInput" class="form-control mb-3" accept="image/png,image/jpeg,image/webp" onchange="loadLogoFile(event)">
                    <div class="logo-editor-preview mb-3">
                        <canvas id="logoCropCanvas"></canvas>
                    </div>
                    <div class="row g-2">
                        <div class="col-6 col-md-3"><label class="form-label">Crop X</label><input id="logoCropX" type="number" class="form-control" min="0" value="0" oninput="renderLogoCrop()"></div>
                        <div class="col-6 col-md-3"><label class="form-label">Crop Y</label><input id="logoCropY" type="number" class="form-control" min="0" value="0" oninput="renderLogoCrop()"></div>
                        <div class="col-6 col-md-3"><label class="form-label">Crop Width</label><input id="logoCropW" type="number" class="form-control" min="1" value="100" oninput="renderLogoCrop()"></div>
                        <div class="col-6 col-md-3"><label class="form-label">Crop Height</label><input id="logoCropH" type="number" class="form-control" min="1" value="100" oninput="renderLogoCrop()"></div>
                        <div class="col-6 col-md-3"><label class="form-label">Output Width</label><input id="logoOutputW" type="number" class="form-control" min="40" max="1000" value="160" oninput="renderLogoCrop()"></div>
                        <div class="col-6 col-md-3"><label class="form-label">Output Height</label><input id="logoOutputH" type="number" class="form-control" min="40" max="1000" value="140" oninput="renderLogoCrop()"></div>
                    </div>
                    <small class="text-muted d-block mt-2">Upload an image, adjust the crop rectangle, then choose the output size.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="applyLogoToReport()">Apply Logo</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="assets/vendor/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('mobileToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        });
        
        function exportReport(type, classId, format) {
            window.open(`api/index.php?action=generate_${type}_report&class_id=${classId}&format=${format}`);
        }
        
        function previewReport(type, classId) {
            const previewDiv = document.getElementById('classRecordPreview');
            previewDiv.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2">Loading preview...</p></div>';
            
            fetch(`api/index.php?action=generate_${type}_report&class_id=${classId}&format=pdf`)
                .then(response => response.text())
                .then(html => {
                    previewDiv.innerHTML = html;
                    enableReportControls();
                    restoreReportDraft(classId);
                    applySavedLogo();
                })
                .catch(err => {
                    previewDiv.innerHTML = '<div class="text-center py-4 text-danger">Error loading preview: ' + err.message + '</div>';
                });
        }

        function getReportPreviewTable() {
            return document.querySelector('#classRecordPreview table.grade-sheet');
        }

        function enableReportControls() {
            ['editReportBtn', 'saveReportBtn', 'printEditedReportBtn', 'editLogoBtn'].forEach(id => {
                document.getElementById(id).disabled = false;
            });
        }

        function toggleReportEditing() {
            const table = getReportPreviewTable();
            if (!table) return;
            const editable = table.dataset.editable !== 'true';
            table.dataset.editable = editable ? 'true' : 'false';
            table.querySelectorAll('td').forEach(cell => {
                cell.contentEditable = editable ? 'true' : 'false';
                cell.classList.toggle('report-editable-cell', editable);
            });
            document.getElementById('editReportBtn').innerHTML = editable
                ? '<i class="bi bi-check2 me-1"></i> Finish Editing'
                : '<i class="bi bi-pencil me-1"></i> Edit Content';
        }

        function saveReportDraft() {
            const table = getReportPreviewTable();
            if (!table) return;
            localStorage.setItem('grading-report-draft-<?= $classId ?>', table.outerHTML);
            alert('Report draft saved in this browser.');
        }

        function restoreReportDraft(classId) {
            const saved = localStorage.getItem(`grading-report-draft-${classId}`);
            const preview = document.getElementById('classRecordPreview');
            if (!saved || !preview) return;
            const currentTable = getReportPreviewTable();
            if (currentTable && confirm('Restore the saved edited report draft?')) {
                currentTable.replaceWith(document.createRange().createContextualFragment(saved));
            }
        }

        function printEditedReport() {
            const table = getReportPreviewTable();
            if (!table) return;
            const printWindow = window.open('', '_blank', 'width=1200,height=800');
            if (!printWindow) return;
            printWindow.document.write(`<html><head><title>Edited Grading Sheet</title><style>
                @page{size:landscape;margin:10mm}body{font-family:Arial;font-size:10px}
                table{border-collapse:collapse;width:100%}th,td{border:1px solid #000;padding:4px;text-align:center}
                th{background:#d9d9d9}td.name{text-align:left}</style></head><body>${table.outerHTML}</body></html>`);
            printWindow.document.close();
            printWindow.print();
        }

        let logoEditorImage = null;

        function openLogoEditor() {
            if (!getReportPreviewTable()) {
                alert('Load the Grading Sheet preview first.');
                return;
            }
            new bootstrap.Modal(document.getElementById('logoEditorModal')).show();
        }

        function loadLogoFile(event) {
            const file = event.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = function () {
                const image = new Image();
                image.onload = function () {
                    logoEditorImage = image;
                    document.getElementById('logoCropX').value = 0;
                    document.getElementById('logoCropY').value = 0;
                    document.getElementById('logoCropW').value = image.naturalWidth;
                    document.getElementById('logoCropH').value = image.naturalHeight;
                    renderLogoCrop();
                };
                image.src = reader.result;
            };
            reader.readAsDataURL(file);
        }

        function renderLogoCrop() {
            if (!logoEditorImage) return;
            const canvas = document.getElementById('logoCropCanvas');
            const cropX = Math.max(0, parseInt(document.getElementById('logoCropX').value, 10) || 0);
            const cropY = Math.max(0, parseInt(document.getElementById('logoCropY').value, 10) || 0);
            const cropW = Math.max(1, parseInt(document.getElementById('logoCropW').value, 10) || 1);
            const cropH = Math.max(1, parseInt(document.getElementById('logoCropH').value, 10) || 1);
            const outputW = Math.max(40, parseInt(document.getElementById('logoOutputW').value, 10) || 160);
            const outputH = Math.max(40, parseInt(document.getElementById('logoOutputH').value, 10) || 140);
            const safeX = Math.min(cropX, logoEditorImage.naturalWidth - 1);
            const safeY = Math.min(cropY, logoEditorImage.naturalHeight - 1);
            const safeW = Math.min(cropW, logoEditorImage.naturalWidth - safeX);
            const safeH = Math.min(cropH, logoEditorImage.naturalHeight - safeY);

            canvas.width = outputW;
            canvas.height = outputH;
            canvas.getContext('2d').drawImage(logoEditorImage, safeX, safeY, safeW, safeH, 0, 0, outputW, outputH);
        }

        function applyLogoToReport() {
            if (!logoEditorImage) {
                alert('Choose an image first.');
                return;
            }
            const canvas = document.getElementById('logoCropCanvas');
            const logoData = canvas.toDataURL('image/png');
            localStorage.setItem('grading-report-logo-<?= $classId ?>', logoData);
            setReportLogo(logoData);
            bootstrap.Modal.getInstance(document.getElementById('logoEditorModal')).hide();
        }

        function setReportLogo(logoData) {
            const logoBox = document.querySelector('#classRecordPreview .logo-box');
            if (!logoBox) return;
            logoBox.innerHTML = `<img src="${logoData}" alt="University logo">`;
        }

        function applySavedLogo() {
            const logoData = localStorage.getItem('grading-report-logo-<?= $classId ?>');
            if (logoData) setReportLogo(logoData);
        }
        
        function exportAttendance(classId, format) {
            window.open(`api/index.php?action=export_attendance&class_id=${classId}&format=${format}`);
        }
    </script>
</body>
</html>
