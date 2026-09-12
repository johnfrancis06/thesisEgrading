<?php
$db = Database::getInstance()->getConnection();
$faculty_id = $auth->getFacultyId();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Subjects - <?= APP_NAME ?></title>
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
                <h1 class="topbar-title">Subjects</h1>
            </div>
            <div class="topbar-right">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newSubjectModal">
                    <i class="bi bi-plus"></i> New Subject
                </button>
            </div>
        </div>
        
        <div class="content-area">
            <div class="page-header fade-in">
                <div class="page-header-left">
                    <div class="page-header-icon">
                        <i class="bi bi-book"></i>
                    </div>
                    <div>
                        <h1 class="mb-0">Subject Catalog</h1>
                        <p class="text-muted mb-0">Manage subjects for your classes</p>
                    </div>
                </div>
            </div>
            
            <div class="row g-3" id="subjectsList">
                <div class="col-12">
                    <div class="empty-state">
                        <i class="bi bi-arrow-clockwise"></i>
                        <p>Loading subjects...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="newSubjectModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="subjectModalTitle">Add New Subject</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Subject Code</label>
                        <input type="text" id="subjectCode" class="form-control" placeholder="GE 104" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Subject Title</label>
                        <input type="text" id="subjectTitle" class="form-control" placeholder="Math in the Modern World" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Default Units</label>
                        <input type="number" id="defaultUnits" class="form-control" value="3" min="1">
                    </div>
                    <input type="hidden" id="subjectId" value="">
                    <div class="form-group" id="cancelEditGroup" style="display: none;">
                        <button type="button" class="btn btn-secondary" onclick="cancelEditSubject()">Cancel Edit</button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="saveSubjectBtn" onclick="saveSubject()">Add Subject</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="assets/vendor/bootstrap.bundle.min.js"></script>
    <script src="assets/js/main.js"></script>
    <script>
        document.getElementById('mobileToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        });
        loadSubjects();
        
        // Reset modal when closed
        document.getElementById('newSubjectModal').addEventListener('hidden.bs.modal', function () {
            cancelEditSubject();
        });
        
        function cancelEditSubject() {
            document.getElementById('subjectId').value = '';
            document.getElementById('subjectCode').value = '';
            document.getElementById('subjectTitle').value = '';
            document.getElementById('defaultUnits').value = '3';
            document.getElementById('subjectModalTitle').textContent = 'Add New Subject';
            document.getElementById('saveSubjectBtn').textContent = 'Add Subject';
            document.getElementById('cancelEditGroup').style.display = 'none';
        }
    </script>
</body>
</html>
