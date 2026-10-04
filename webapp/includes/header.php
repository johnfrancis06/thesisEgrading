<?php
// Sidebar navigation — included on all authenticated pages
$currentPage = $_GET['page'] ?? 'dashboard';
$currentPage = preg_replace('/[^a-z0-9_-]/', '', $currentPage);
$facultyName = $_SESSION['faculty_name'] ?? 'User';
$initials = strtoupper(substr($facultyName, 0, 2));
?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <a href="index.php?page=dashboard" style="display: flex; align-items: center; gap: 0.75rem; color: var(--white); text-decoration: none;">
            <div class="sidebar-brand-icon">
                <img src="assets/images/capsu.jpg" alt="CAPSU logo">
            </div>
            <div class="sidebar-brand-text">E-Grading</div>
        </a>
    </div>
    
    <nav class="sidebar-nav">
        
        <div class="sidebar-section">Activities</div>
        <a href="index.php?page=grading" class="sidebar-link <?= $currentPage === 'grading' ? 'active' : '' ?>">
            <i class="bi bi-pencil-square"></i> Grading
        </a>
        <a href="index.php?page=attendance" class="sidebar-link <?= $currentPage === 'attendance' ? 'active' : '' ?>">
            <i class="bi bi-calendar-check"></i> Attendance
        </a>
        <a href="index.php?page=reports" class="sidebar-link <?= $currentPage === 'reports' ? 'active' : '' ?>">
            <i class="bi bi-file-earmark-arrow-down"></i> Reports
        </a>
        <a href="index.php?page=students" class="sidebar-link <?= $currentPage === 'students' ? 'active' : '' ?>">
            <i class="bi bi-people"></i> Students
        </a>
        
        <div class="sidebar-section">Account</div>
        <a href="index.php?page=logout" class="sidebar-link">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
    </nav>
    
    <div class="sidebar-footer">
        <div class="d-flex align-items-center gap-2">
            <div class="topbar-avatar"><?= htmlspecialchars($initials) ?></div>
            <div>
                <div style="color: var(--text-secondary); font-weight: 600; font-size: 0.85rem;">
                    <?= htmlspecialchars($facultyName) ?>
                </div>
            </div>
        </div>
    </div>
</aside>