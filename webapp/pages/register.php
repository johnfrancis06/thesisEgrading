<?php
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($name) || empty($email) || empty($password)) {
            $error = 'All fields are required';
        } elseif (!Validator::validateEmail($email)) {
            $error = 'Please enter a valid email address';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters';
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $error = 'Password must contain at least one uppercase letter';
        } elseif (!preg_match('/[a-z]/', $password)) {
            $error = 'Password must contain at least one lowercase letter';
        } elseif (!preg_match('/[0-9]/', $password)) {
            $error = 'Password must contain at least one digit';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match';
        } else {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT id FROM faculty WHERE email = ? LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $error = 'Email address is already registered';
            } elseif ($auth->register($email, $name, $password)) {
                $success = 'Account created successfully. You can now sign in.';
            } else {
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Sign Up</title>
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
            max-width: 480px;
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

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border-color: #bbf7d0;
        }

        .alert i {
            font-size: 1.1rem;
            flex-shrink: 0;
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

        .form-hint {
            font-size: 0.75rem;
            color: var(--gray-500);
            margin-top: 0.25rem;
        }

        .password-requirements {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: var(--gray-500);
        }

        .password-requirements span {
            display: flex;
            align-items: center;
            gap: 0.375rem;
            transition: color 0.2s ease;
        }

        .password-requirements span.valid {
            color: var(--success);
        }

        .password-requirements span i {
            font-size: 0.7rem;
            width: 0.75rem;
            text-align: center;
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
            
            <?php if ($success): ?>
                <div class="alert alert-success" role="alert">
                    <i class="bi bi-check-circle-fill"></i>
                    <span><?= htmlspecialchars($success) ?></span>
                    <div class="mt-3">
                        <a href="index.php?page=login" class="login-btn" style="width: auto; padding: 0.75rem 1.5rem; margin: 0; box-shadow: var(--shadow-sm);">
                            <i class="bi bi-box-arrow-in-right"></i>
                            <span>Sign In</span>
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <form method="POST" class="login-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    
                    <div class="form-group">
                        <label class="form-label" for="name">Full Name</label>
                        <input type="text" id="name" name="name" class="form-control" placeholder="Jane Doe" required autofocus autocomplete="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control" placeholder="you@institution.edu" required autocomplete="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="password">Password</label>
                        <input type="password" id="password" name="password" class="form-control" placeholder="Min 8 chars, 1 uppercase, 1 lowercase, 1 digit" required autocomplete="new-password">
                        <div class="password-requirements" id="passwordHints">
                            <span data-req="length"><i class="bi bi-circle"></i> At least 8 characters</span>
                            <span data-req="upper"><i class="bi bi-circle"></i> One uppercase letter</span>
                            <span data-req="lower"><i class="bi bi-circle"></i> One lowercase letter</span>
                            <span data-req="digit"><i class="bi bi-circle"></i> One digit</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Confirm your password" required autocomplete="new-password">
                        <span class="form-hint" id="matchHint" style="display: none; color: var(--danger);">
                            <i class="bi bi-exclamation-circle"></i> Passwords do not match
                        </span>
                        <span class="form-hint" id="matchOk" style="display: none; color: var(--success);">
                            <i class="bi bi-check-circle"></i> Passwords match
                        </span>
                    </div>
                    
                    <button type="submit" class="login-btn">
                        <i class="bi bi-person-plus"></i>
                        <span>Sign Up</span>
                    </button>
                </form>
                
                <div class="login-footer">
                    <p class="mb-0">
                        Already have an account?
                        <a href="index.php?page=login">Sign In</a>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Password validation feedback
        const passwordInput = document.getElementById('password');
        const confirmInput = document.getElementById('confirm_password');
        const requirements = {
            length: /^.{8,}$/,
            upper: /[A-Z]/,
            lower: /[a-z]/,
            digit: /[0-9]/
        };

        function updateRequirements() {
            const value = passwordInput.value;
            Object.keys(requirements).forEach(req => {
                const el = document.querySelector(`[data-req="${req}"]`);
                if (el) {
                    const valid = requirements[req].test(value);
                    el.classList.toggle('valid', valid);
                    el.querySelector('i').className = valid ? 'bi bi-check-circle-fill' : 'bi bi-circle';
                }
            });
        }

        function updateMatchHint() {
            const matchHint = document.getElementById('matchHint');
            const matchOk = document.getElementById('matchOk');
            if (confirmInput.value && passwordInput.value) {
                if (passwordInput.value === confirmInput.value) {
                    matchHint.style.display = 'none';
                    matchOk.style.display = 'block';
                    confirmInput.style.borderColor = 'var(--success)';
                } else {
                    matchHint.style.display = 'block';
                    matchOk.style.display = 'none';
                    confirmInput.style.borderColor = 'var(--danger)';
                }
            } else {
                matchHint.style.display = 'none';
                matchOk.style.display = 'none';
                confirmInput.style.borderColor = '';
            }
        }

        passwordInput.addEventListener('input', () => {
            updateRequirements();
            updateMatchHint();
        });

        confirmInput.addEventListener('input', updateMatchHint);
    </script>
</body>
</html>