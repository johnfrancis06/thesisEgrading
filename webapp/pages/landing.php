<?php
// Landing page - shown before login
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - E-Grading Management System</title>
    <link href="assets/vendor/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css?v=8">
    <style>
        .landing-page {
            min-height: 100vh;
            background: linear-gradient(135deg, #0d6efd 0%, #0dcaf0 100%);
            display: flex;
            flex-direction: column;
            position: relative;
        }
        .bg-image {
            position: fixed;
            inset: 0;
            background-image: url('../assets/images/capsu_gate.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            filter: blur(8px);
            transform: scale(1.1);
            z-index: 0;
            opacity: 0.35;
            pointer-events: none;
        }
        .bg-overlay {
            position: fixed;
            inset: 0;
            background: linear-gradient(135deg, rgba(13, 110, 253, 0.88) 0%, rgba(13, 202, 240, 0.88) 100%);
            z-index: 1;
            pointer-events: none;
        }
        .landing-nav {
            padding: 1rem 2rem;
            position: relative;
            z-index: 10;
        }
        .landing-nav .navbar-brand {
            font-weight: 700;
            font-size: 1.5rem;
            color: #fff !important;
        }
        .landing-nav .btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: #fff;
            padding: 0.5rem 1.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .landing-nav .btn:hover {
            background: #fff;
            color: #0d6efd;
        }
        .landing-nav .btn-primary {
            background: #fff;
            border-color: #fff;
            color: #0d6efd;
        }
        .landing-nav .btn-primary:hover {
            background: #f8f9fa;
        }
        .hero-section {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 4rem 2rem;
            text-align: center;
            color: #fff;
            position: relative;
            z-index: 2;
            min-height: 60vh;
        }
        .hero-icon {
            width: 120px;
            height: 120px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 2rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
        }
        .hero-icon i {
            font-size: 3.5rem;
        }
        .hero-title {
            font-size: 3.5rem;
            font-weight: 800;
            margin-bottom: 1rem;
            text-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        .hero-subtitle {
            font-size: 1.5rem;
            opacity: 0.95;
            max-width: 700px;
            margin: 0 auto 3rem;
            line-height: 1.6;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        .hero-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        .hero-buttons .btn {
            padding: 1rem 2.5rem;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 50px;
            transition: all 0.3s ease;
        }
        .hero-buttons .btn-primary {
            background: #fff;
            border-color: #fff;
            color: #0d6efd;
        }
        .hero-buttons .btn-primary:hover {
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        }
        .hero-buttons .btn-outline-light {
            border-color: rgba(255,255,255,0.5);
            color: #fff;
        }
        .hero-buttons .btn-outline-light:hover {
            background: rgba(255,255,255,0.1);
            border-color: #fff;
            transform: translateY(-2px);
        }
        .features-section {
            padding: 5rem 2rem;
            background: #fff;
            position: relative;
            z-index: 2;
        }
        .features-section .container {
            max-width: 1000px;
        }
        .section-header {
            text-align: center;
            margin-bottom: 4rem;
        }
        .section-header h2 {
            font-size: 2.5rem;
            font-weight: 700;
            color: #212529;
            margin-bottom: 1rem;
        }
        .section-header p {
            font-size: 1.1rem;
            color: #6c757d;
        }
        .feature-card {
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 16px;
            padding: 2.5rem 2rem;
            text-align: center;
            transition: all 0.3s ease;
            height: 100%;
            position: relative;
            overflow: hidden;
        }
        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #0d6efd, #0dcaf0);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.3s ease;
        }
        .feature-card:hover::before {
            transform: scaleX(1);
        }
        .feature-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(13, 110, 253, 0.15);
            border-color: #0d6efd;
        }
        .feature-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #0d6efd 0%, #0dcaf0 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }
        .feature-icon i {
            font-size: 2rem;
            color: #fff;
        }
        .feature-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #212529;
            margin-bottom: 0.75rem;
        }
        .feature-desc {
            color: #6c757d;
            line-height: 1.6;
            font-size: 0.95rem;
        }
        .stats-section {
            padding: 4rem 2rem;
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            position: relative;
            z-index: 2;
        }
        .stat-item {
            text-align: center;
            color: #fff;
        }
        .stat-number {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .stat-label {
            font-size: 1.1rem;
            opacity: 0.8;
        }
        .footer-section {
            padding: 2rem;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            position: relative;
            z-index: 2;
        }
        .footer-section p {
            margin: 0;
            color: #6c757d;
            text-align: center;
        }
        @media (max-width: 768px) {
            .hero-title { font-size: 2.5rem; }
            .hero-subtitle { font-size: 1.1rem; }
            .hero-buttons { flex-direction: column; align-items: center; }
            .hero-buttons .btn { width: 100%; max-width: 300px; }
            .section-header h2 { font-size: 2rem; }
        }
    </style>
</head>
<body class="landing-page">
    <!-- Background Image Layer (fixed, blurred) -->
    <div class="bg-image" aria-hidden="true"></div>
    <div class="bg-overlay" aria-hidden="true"></div>

    <!-- Navigation -->
    <nav class="landing-nav">
        <div class="container d-flex justify-content-between align-items-center">
            <a href="#" class="navbar-brand">
                <i class="bi bi-mortarboard-fill me-2"></i><?= APP_NAME ?>
            </a>
            <div class="d-flex gap-2">
                <a href="index.php?page=login" class="btn">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                </a>
                <a href="index.php?page=register" class="btn btn-primary">
                    <i class="bi bi-person-plus me-1"></i> Get Started
                </a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container">
            <div class="hero-icon">
                <i class="bi bi-journal-check"></i>
            </div>
            <h1 class="hero-title">E-Grading Management System</h1>
            <p class="hero-subtitle">
                Streamline your grading workflow with a modern, intuitive platform designed for faculty. 
                Manage classes, record grades, track attendance, and generate reports — all in one place.
            </p>
            <div class="hero-buttons">
                <a href="index.php?page=register" class="btn btn-primary btn-lg">
                    <i class="bi bi-rocket-takeoff me-2"></i>Start Free Trial
                </a>
                <a href="index.php?page=login" class="btn btn-outline-light btn-lg">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                </a>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section">
        <div class="container">
            <div class="section-header">
                <h2>Everything You Need for Efficient Grading</h2>
                <p>Powerful features built specifically for academic faculty</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-table"></i>
                        </div>
                        <h3 class="feature-title">Dynamic Grading Sheets</h3>
                        <p class="feature-desc">Excel-like grading interface with customizable categories, weights, and items. Automatic computation of equivalents and weighted scores.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-people"></i>
                        </div>
                        <h3 class="feature-title">Class & Student Management</h3>
                        <p class="feature-desc">Organize classes by subject, year level, and section. Bulk enroll students from section rosters with year-level validation.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-calendar-check"></i>
                        </div>
                        <h3 class="feature-title">Attendance Tracking</h3>
                        <p class="feature-desc">Create sessions manually or auto-generate by schedule. Track present, absent, late, and excused statuses with configurable rules.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-bar-chart"></i>
                        </div>
                        <h3 class="feature-title">Comprehensive Reports</h3>
                        <p class="feature-desc">Generate detailed grading reports with midterm, final, and overall grades. Export to Excel or Word for official submission.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-shield-lock"></i>
                        </div>
                        <h3 class="feature-title">Secure & Role-Based</h3>
                        <p class="feature-desc">Faculty-only access with secure authentication. Each instructor manages only their assigned classes and students.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-gear"></i>
                        </div>
                        <h3 class="feature-title">Flexible Configuration</h3>
                        <p class="feature-desc">Customize grade categories, weights, perfect scores, and item labels per class and period (Midterm/Final).</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="stats-section">
        <div class="container">
            <div class="row text-center">
                <div class="col-md-3">
                    <div class="stat-item">
                        <div class="stat-number">4</div>
                        <div class="stat-label">Core Components</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-item">
                        <div class="stat-number">2</div>
                        <div class="stat-label">Grading Periods</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-item">
                        <div class="stat-number">100%</div>
                        <div class="stat-label">Automated Computation</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-item">
                        <div class="stat-number">∞</div>
                        <div class="stat-label">Customizable Items</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer-section">
        <div class="container">
            <p class="mb-1">&copy; <?= date('Y') ?> <?= APP_NAME ?>. All rights reserved.</p>
            <p class="small">A modern grading management system for academic institutions.</p>
        </div>
    </footer>

    <script src="assets/vendor/bootstrap.bundle.min.js"></script>
</body>
</html>