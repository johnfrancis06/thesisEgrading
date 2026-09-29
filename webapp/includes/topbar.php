<?php
// Topbar navigation — included on all authenticated pages
$currentPage = $_GET['page'] ?? 'dashboard';
$currentPage = preg_replace('/[^a-z0-9_-]/', '', $currentPage);
$facultyName = $_SESSION['faculty_name'] ?? 'User';
$initials = strtoupper(substr($facultyName, 0, 2));
?>
<header class="topbar-modern">
    <div class="topbar-left">
        <button class="mobile-toggle" id="mobileToggle" aria-label="Toggle navigation">
            <i class="bi bi-list"></i>
        </button>
        <button class="sidebar-toggle d-none d-lg-flex" id="sidebarToggle" aria-label="Collapse sidebar" title="Collapse sidebar">
            <i class="bi bi-chevron-bar-left"></i>
        </button>
        <a href="index.php?page=dashboard" class="topbar-brand" title="Dashboard">
            <div class="topbar-brand-icon">EG</div>
            <div class="topbar-brand-text">E-Grading</div>
        </a>
    </div>
    <div class="topbar-right">
        <nav class="topbar-nav d-none d-lg-flex" role="navigation" aria-label="Main menu">
            <a href="index.php?page=section-enrollment" class="topbar-nav-link <?= $currentPage === 'section-enrollment' ? 'active' : '' ?>">
                <i class="bi bi-person-plus"></i> Enroll Students
            </a>
            <a href="index.php?page=classes" class="topbar-nav-link <?= $currentPage === 'classes' ? 'active' : '' ?>">
                <i class="bi bi-people"></i> Classes
            </a>
        </nav>
        <div class="user-menu">
            <div class="user-avatar"><?= htmlspecialchars($initials) ?></div>
            <div class="user-info d-none d-md-block">
                <span class="user-name"><?= htmlspecialchars($facultyName) ?></span>
                <span class="user-role">Faculty</span>
            </div>
        </div>
    </div>
</header>