<?php
// Sidebar navigation — included on all authenticated pages
$currentPage = $_GET['page'] ?? 'dashboard';
$currentPage = preg_replace('/[^a-z0-9_-]/', '', $currentPage);
$facultyName = $_SESSION['faculty_name'] ?? 'User';
$initials = strtoupper(substr($facultyName, 0, 2));


<!-- Main Navigation -->
<nav class="main-nav">
    <div class="container">
        <ul class="nav">
            <li class="nav-item">
                <a href="index.php?page=dashboard" class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="index.php?page=classes" class="nav-link <?= $currentPage === 'classes' ? 'active' : '' ?>">
                    <i class="bi bi-people"></i> Classes
                </a>
            </li>
            <li class="nav-item">
                <a href="index.php?page=subjects" class="nav-link <?= $currentPage === 'subjects' ? 'active' : '' ?>">
                    <i class="bi bi-book"></i> Subjects
                </a>
            </li>
            <li class="nav-item">
                <a href="index.php?page=section-enrollment" class="nav-link <?= $currentPage === 'section-enrollment' ? 'active' : '' ?>">
                    <i class="bi bi-person-plus"></i> Enroll Students
                </a>
            </li>
            <li class="nav-item">
                <a href="index.php?page=grading" class="nav-link <?= $currentPage === 'grading' ? 'active' : '' ?>">
                    <i class="bi bi-pencil-square"></i> Grading
                </a>
            </li>
            <li class="nav-item">
                <a href="index.php?page=attendance" class="nav-link <?= $currentPage === 'attendance' ? 'active' : '' ?>">
                    <i class="bi bi-calendar-check"></i> Attendance
                </a>
            </li>
            <li class="nav-item">
                <a href="index.php?page=reports" class="nav-link <?= $currentPage === 'reports' ? 'active' : '' ?>">
                    <i class="bi bi-file-earmark-arrow-down"></i> Reports
                </a>
            </li>
            <li class="nav-item">
                <a href="index.php?page=students" class="nav-link <?= $currentPage === 'students' ? 'active' : '' ?>">
                    <i class="bi bi-people"></i> Students
                </a>
            </li>
            <li class="nav-item">
                <a href="index.php?page=logout" class="nav-link">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </li>
        </ul>
    </div>
</nav>


                <div style="color: var(--text-secondary); font-weight: 600; font-size: 0.85rem;">
                    <?= htmlspecialchars($facultyName) ?>
                </div>
            </div>
        </div>
    </div>
</aside>
