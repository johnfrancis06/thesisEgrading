<?php
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($auth->login($_POST['email'], $_POST['password'])) {
        header("Location: index.php?page=dashboard");
        exit;
    } else {
        $error = 'Invalid credentials';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Sign In</title>
    <link href="assets/vendor/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css?v=18">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1e293b;
            --primary-light: #334155;
            --accent: #0ea5e9;
            --accent-hover: #0284c7;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
            --white: #ffffff;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            --radius-sm: 0.375rem;
            --radius: 0.5rem;
            --radius-md: 0.75rem;
            --radius-lg: 1rem;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, var(--gray-900) 0%, var(--gray-800) 100%);
            color: var(--gray-800);
            line-height: 1.6;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .login-page {
            width: 100%;
            max-width: 440px;
        }

        .login-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xl);
            padding: 2.5rem;
            width: 100%;
        }

        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, var(--accent) 0%, var(--primary) 100%);
            border-radius: var(--radius-md);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.25rem;
            box-shadow: var(--shadow-md);
        }

        .login-header h2 {
            margin: 0 0 0.5rem;
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--gray-900);
            letter-spacing: -0.02em;
        }

        .login-header p {
            color: var(--gray-500);
            margin: 0;
            font-size: 1rem;
            font-weight: 400;
        }

        .alert {
            padding: 0.875rem 1.25rem;
            border-radius: var(--radius);
            border: 1px solid transparent;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border-color: #fecaca;
        }

        .alert-danger i {
            font-size: 1.1rem;
        }

        .login-form {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.25rem;
        }

        .form-control {
            border: 1.5px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 0.875rem 1rem;
            font-size: 1rem;
            color: var(--gray-800);
            background: var(--white);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
            width: 100%;
            font-family: inherit;
        }

        .form-control:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.15);
            outline: none;
            background: #f0f9ff;
        }

        .form-control::placeholder {
            color: var(--gray-400);
        }

        .form-control:invalid:not(:placeholder-shown) {
            border-color: var(--danger);
        }

        .login-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 1rem;
            font-size: 1rem;
            font-weight: 600;
            border-radius: var(--radius);
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            background: linear-gradient(135deg, var(--accent) 0%, var(--primary) 100%);
            color: var(--white);
            margin-top: 0.5rem;
            box-shadow: var(--shadow);
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .login-btn:focus-visible {
            outline: 3px solid rgba(14, 165, 233, 0.4);
            outline-offset: 2px;
        }

        .login-footer {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--gray-200);
            font-size: 0.875rem;
            color: var(--gray-500);
        }

        .login-footer p {
            margin: 0.5rem 0;
        }

        .login-footer a {
            color: var(--accent);
            font-weight: 500;
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .login-footer a:hover {
            color: var(--primary);
            text-decoration: underline;
        }

        .login-footer code {
            background: var(--gray-100);
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.8rem;
            font-family: 'SF Mono', 'Monaco', 'Inconsolata', monospace;
            color: var(--gray-700);
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 1.5rem;
            }
            .login-header h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-page">
        <div class="login-card">
            <div class="login-header">
                <div class="logo-icon">EG</div>
                <h2><?= APP_NAME ?></h2>
                <p>Faculty Grading System</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="login-form" novalidate>
                <div class="form-group">
                    <label class="form-label" for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" placeholder="you@institution.edu" required autofocus autocomplete="email">
                </div>
                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" required autocomplete="current-password">
                </div>
                <button type="submit" class="login-btn">
                    <i class="bi bi-box-arrow-in-right"></i>
                    <span>Sign In</span>
                </button>
            </form>
            
            <div class="login-footer">
                <p class="mb-0">Demo credentials:<br>
                <code>admin@test.com</code> / <code>password</code></p>
                <p class="mb-0 mt-3">
                    Don't have an account?
                    <a href="index.php?page=register">Sign Up</a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>