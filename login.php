<?php
session_start();
require_once 'sources/db_connect.php';

$error = '';

/**
 * Log login attempts with detailed information
 */
function logLoginAttempt($conn, $user_id, $username, $status, $details = '') {

// Build action description
$action = "Login $status: User '$username'";
if (!empty($details)) {
    $action .= " - $details";
}
    // If user_id is provided, get branch_id
    $branch_id = null;
    if ($user_id) {
        $user_sql = "SELECT branch_id FROM users WHERE user_id = ?";
        $user_stmt = $conn->prepare($user_sql);
        if ($user_stmt) {
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            if ($user_row = $user_result->fetch_assoc()) {
                $branch_id = $user_row['branch_id'];
            }
            $user_stmt->close();
        }
    }
    
    // Insert audit log
    $log_sql = "INSERT INTO audit_logs (user_id, branch_id, action, module) VALUES (?, ?, ?, ?)";
    $log_stmt = $conn->prepare($log_sql);
    if ($log_stmt) {
        // If user_id is null, use 0 as placeholder
        $log_user_id = $user_id ?? 0;
        $module = 'Login System'; // Define the module variable
        $log_stmt->bind_param("isss", $log_user_id, $branch_id, $action, $module);
        $log_stmt->execute();
        $log_stmt->close();
        return true;
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (!empty($username) && !empty($password)) {
        // Prepare statement to prevent SQL injection
        $stmt = $conn->prepare("SELECT u.*, r.role_name, b.branch_name 
                                FROM users u 
                                LEFT JOIN roles r ON u.role_id = r.role_id 
                                LEFT JOIN branches b ON u.branch_id = b.branch_id 
                                WHERE u.username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Check if user is active
            if ($user['status'] !== 'Active') {
                $error = 'Your account has been deactivated. Please contact your administrator.';
                // Log failed login - inactive account
                logLoginAttempt($conn, $user['user_id'], $username, 'Failed', 
                    "Account is inactive");
            }
            // Verify password
            elseif (password_verify($password, $user['password'])) {
                // Update last login
                $updateStmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
                $updateStmt->bind_param("i", $user['user_id']);
                $updateStmt->execute();
                $updateStmt->close();
                
                // Log successful login
                logLoginAttempt($conn, $user['user_id'], $username, 'Success', 
                    "Role: " . $user['role_name'] . ", Branch: " . ($user['branch_name'] ?? 'N/A'));
                
                // Set session variables
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role_id'] = $user['role_id'];
                $_SESSION['role_name'] = $user['role_name'];
                $_SESSION['branch_id'] = $user['branch_id'];
                $_SESSION['branch_name'] = $user['branch_name'];
                $_SESSION['logged_in'] = true;
                
                // Redirect based on role
                switch ($user['role_id']) {
                    case 1: // Super Admin
                        header("Location: SuperAdmin_Dashboard.php");
                        break;
                    case 2: // Branch Admin
                        header("Location: BranchAdmin_Dashboard.php");
                        break;
                    case 3: // Nurse
                        header("Location: Nurse_Dashboard.php");
                        break;
                    case 4: // Administrative Staff
                        header("Location: AdminStaff_Dashboard.php");
                        break;
                    case 5: // Inventory Officer
                        header("Location: InventoryOfficer_Dashboard.php");
                        break;
                    default:
                        header("Location: dashboard.php");
                }
                exit();
            } else {
                $error = 'Invalid username or password.';
                // Log failed login - wrong password
                logLoginAttempt($conn, $user['user_id'], $username, 'Failed', 
                    "Incorrect password");
            }
        } else {
            $error = 'Invalid username or password.';
            // Log failed login - user not found
            logLoginAttempt($conn, null, $username, 'Failed', 
                "Username not found");
        }
        $stmt->close();
    } else {
        $error = 'Please enter both username and password.';
        // Log empty credentials attempt
        logLoginAttempt($conn, null, 'Unknown', 'Failed', 
            "Empty username or password");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>SBI-ABC - Login</title>
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <style>
        /* =========================================
           LOGIN PAGE - SBI-ABC
           Color Scheme: #2B3A8C (primary), #F21D2F (accent)
           ========================================= */
        :root {
            --primary: #2B3A8C;
            --primary-dark: #1a235a;
            --primary-light: #3a4b9e;
            --accent: #F21D2F;
            --accent-hover: #c9182a;
            --bg-light: #f0f3fc;
            --text-dark: #1a2340;
            --text-muted: #5a6a8a;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Roboto, system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--bg-light) 0%, #ffffff 100%);
            padding: 20px;
        }

        /* ---- PAW DECORATIONS ---- */
        .paw-bg-1 {
            position: fixed;
            font-size: 120px;
            opacity: 0.04;
            color: var(--primary);
            top: 5%;
            left: 3%;
            transform: rotate(-15deg);
            pointer-events: none;
            z-index: 0;
        }
        .paw-bg-2 {
            position: fixed;
            font-size: 160px;
            opacity: 0.04;
            color: var(--primary);
            bottom: 5%;
            right: 3%;
            transform: rotate(25deg);
            pointer-events: none;
            z-index: 0;
        }
        .paw-bg-3 {
            position: fixed;
            font-size: 80px;
            opacity: 0.04;
            color: var(--primary);
            top: 30%;
            right: 8%;
            transform: rotate(10deg);
            pointer-events: none;
            z-index: 0;
        }
        .paw-bg-4 {
            position: fixed;
            font-size: 100px;
            opacity: 0.04;
            color: var(--primary);
            bottom: 30%;
            left: 5%;
            transform: rotate(-5deg);
            pointer-events: none;
            z-index: 0;
        }

        /* ---- LOGIN CARD ---- */
        .login-wrapper {
            width: 100%;
            max-width: 440px;
            position: relative;
            z-index: 1;
        }

        .login-card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 12px 48px rgba(0, 0, 0, 0.08);
            padding: 44px 36px 36px;
            position: relative;
            overflow: hidden;
        }

        /* Subtle accent line at top */
        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
        }

        /* ---- LOGO ---- */
        .login-logo {
            text-align: center;
            margin-bottom: 28px;
        }
        .login-logo .logo-img {
            height: 56px;
            width: auto;
            border-radius: 10px;
            margin-bottom: 8px;
        }
        .login-logo .brand-name {
            font-weight: 800;
            font-size: 24px;
            color: var(--primary);
            letter-spacing: -0.5px;
        }
        .login-logo .brand-name span {
            color: var(--accent);
        }
        .login-logo .brand-sub {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            letter-spacing: 1px;
            text-transform: uppercase;
            display: block;
            margin-top: -2px;
        }

        /* ---- FORM ---- */
        .login-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--primary);
            text-align: center;
            margin-bottom: 4px;
        }
        .login-sub {
            text-align: center;
            color: var(--text-muted);
            font-size: 15px;
            margin-bottom: 28px;
        }

        .form-label {
            font-weight: 600;
            color: var(--primary);
            font-size: 14px;
        }

        .input-group-custom {
            position: relative;
        }
        .input-group-custom .form-control {
            border-radius: 12px;
            border: 1px solid #d0d7e8;
            padding: 12px 16px 12px 44px;
            font-size: 15px;
            transition: 0.15s;
            background: #fafbff;
        }
        .input-group-custom .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.12);
            background: white;
        }
        .input-group-custom .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #8a96b8;
            font-size: 18px;
            pointer-events: none;
        }

        /* Password toggle button */
        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #8a96b8;
            font-size: 18px;
            cursor: pointer;
            padding: 4px 8px;
            transition: 0.15s;
            z-index: 2;
        }
        .password-toggle:hover {
            color: var(--primary);
        }
        .password-toggle:focus {
            outline: none;
        }

        .form-check-label {
            font-size: 14px;
            color: var(--text-muted);
        }
        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .forgot-link {
            font-size: 14px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }
        .forgot-link:hover {
            text-decoration: underline;
        }

        .btn-login {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 14px;
            font-weight: 700;
            font-size: 16px;
            width: 100%;
            transition: 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .btn-login:hover {
            background: var(--primary-dark);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(43, 58, 140, 0.35);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        /* ---- BACK LINK ---- */
        .back-link {
            display: block;
            text-align: center;
            margin-top: 18px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: 0.15s;
        }
        .back-link:hover {
            color: var(--primary);
        }
        .back-link i {
            margin-right: 6px;
        }

        /* Alert styling */
        .alert-custom {
            border-radius: 12px;
            border: none;
            padding: 12px 16px;
            font-size: 14px;
            font-weight: 500;
        }

        /* ---- RESPONSIVE ---- */
        @media (max-width: 576px) {
            .login-card {
                padding: 32px 20px 28px;
            }
            .login-logo .logo-img {
                height: 44px;
            }
            .login-logo .brand-name {
                font-size: 20px;
            }
            .login-title {
                font-size: 20px;
            }
            .paw-bg-1,
            .paw-bg-2,
            .paw-bg-3,
            .paw-bg-4 {
                display: none;
            }
        }
    </style>
</head>
<body>

    <!-- PAW DECORATIONS -->
    <div class="paw-bg-1"><i class="bi bi- paw"></i></div>
    <div class="paw-bg-2"><i class="bi bi- paw"></i></div>
    <div class="paw-bg-3"><i class="bi bi- paw"></i></div>
    <div class="paw-bg-4"><i class="bi bi- paw"></i></div>

    <!-- LOGIN WRAPPER -->
    <div class="login-wrapper">

        <div class="login-card">

            <!-- Logo -->
            <div class="login-logo">
                <img src="logo.png" alt="SBI-ABC Logo" class="logo-img" />
                <div class="brand-name">SBI-<span>ABC</span></div>
                <div class="brand-sub">Smart Bite Care</div>
            </div>

            <!-- Title -->
            <h2 class="login-title">Welcome Back</h2>
            <p class="login-sub">Sign in to your SBI-ABC account</p>

            <!-- Error Message -->
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-custom d-flex align-items-center" role="alert">
                    <i class="bi bi-exclamation-circle-fill me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST">
                <!-- Username -->
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <div class="input-group-custom">
                        <span class="input-icon"><i class="bi bi-person"></i></span>
                        <input type="text" class="form-control" name="username" placeholder="Enter your username" required 
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" />
                    </div>
                </div>

                <!-- Password with toggle -->
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <div class="input-group-custom">
                        <span class="input-icon"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="passwordInput" name="password" placeholder="Enter your password" required />
                        <button type="button" class="password-toggle" id="togglePassword" aria-label="Toggle password visibility">
                            <i class="bi bi-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Forgot Password -->
                <div class="mb-3 d-flex justify-content-end">
                    <a href="#" class="forgot-link">Forgot password?</a>
                </div>

                <!-- Login Button -->
                <button type="submit" class="btn-login">
                    <i class="bi bi-box-arrow-in-right"></i> Sign In
                </button>
            </form>

            <!-- Back to Home - links to landing.php -->
            <a href="landing.php" class="back-link">
                <i class="bi bi-arrow-left"></i> Back to Home
            </a>

        </div>

    </div>

    <!-- Password Toggle Script -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const toggleBtn = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('passwordInput');
            const toggleIcon = document.getElementById('toggleIcon');

            toggleBtn.addEventListener('click', function() {
                // Toggle password visibility
                const isPassword = passwordInput.getAttribute('type') === 'password';
                passwordInput.setAttribute('type', isPassword ? 'text' : 'password');

                // Toggle icon
                toggleIcon.classList.toggle('bi-eye');
                toggleIcon.classList.toggle('bi-eye-slash');
            });
        });
    </script>

</body>
</html>