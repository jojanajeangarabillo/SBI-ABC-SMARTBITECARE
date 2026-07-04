<?php
session_start();
require_once 'sources/db_connect.php';

$error = '';
$success = '';
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$email = isset($_GET['email']) ? trim($_GET['email']) : '';

// If form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['token']) ? trim($_POST['token']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';
    $confirm_password = isset($_POST['confirm_password']) ? trim($_POST['confirm_password']) : '';
    
    // Validate inputs
    if (empty($token) || empty($email)) {
        $error = 'Invalid request. Missing token or email.';
    } elseif (empty($password) || empty($confirm_password)) {
        $error = 'Please enter and confirm your password.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        // Verify token
        $sql = "SELECT t.user_id, t.token, t.expires_at, t.used_at, u.email, u.username, u.status 
                FROM user_tokens t 
                JOIN users u ON t.user_id = u.user_id 
                WHERE t.token = ? AND t.token_type = 'password_reset' AND u.email = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $token, $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            // Check if token is expired
            if (strtotime($row['expires_at']) < time()) {
                $error = 'This password reset link has expired. Please contact your administrator to generate a new one.';
            } 
            // Check if token is already used
            elseif (!is_null($row['used_at'])) {
                $error = 'This password reset link has already been used. Please contact your administrator.';
            }
            // Check if user is active
            elseif ($row['status'] === 'Inactive') {
                $error = 'Your account has been deactivated. Please contact your administrator.';
            }
            else {
                // Hash the new password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Update user's password
                $update_sql = "UPDATE users SET password = ?, status = 'Active' WHERE user_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("si", $hashed_password, $row['user_id']);
                
                if ($update_stmt->execute()) {
                    // Mark token as used
                    $token_update_sql = "UPDATE user_tokens SET used_at = NOW() WHERE token = ?";
                    $token_update_stmt = $conn->prepare($token_update_sql);
                    $token_update_stmt->bind_param("s", $token);
                    $token_update_stmt->execute();
                    
                    $success = 'Password has been set successfully! You can now log in with your new password.';
                    
                    // Redirect after 3 seconds
                    echo '<script>
                        setTimeout(function() {
                            window.location.href = "login.php";
                        }, 3000);
                    </script>';
                } else {
                    $error = 'Failed to update password. Please try again.';
                }
            }
        } else {
            $error = 'Invalid token or email. Please check your link or contact your administrator.';
        }
    }
}

// If token and email are provided in URL, validate them before showing the form
if (!empty($token) && !empty($email) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $sql = "SELECT t.token, t.expires_at, t.used_at, u.email, u.status 
            FROM user_tokens t 
            JOIN users u ON t.user_id = u.user_id 
            WHERE t.token = ? AND t.token_type = 'password_reset' AND u.email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $token, $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        if (strtotime($row['expires_at']) < time()) {
            $error = 'This password reset link has expired. Please contact your administrator to generate a new one.';
        } elseif (!is_null($row['used_at'])) {
            $error = 'This password reset link has already been used. Please contact your administrator.';
        } elseif ($row['status'] === 'Inactive') {
            $error = 'Your account has been deactivated. Please contact your administrator.';
        }
        // If all valid, show the form
    } else {
        $error = 'Invalid token or email. Please check your link or contact your administrator.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Set Password - Smart Bite Care</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <style>
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
        }
        body {
            background: linear-gradient(135deg, #f0f3fc 0%, #ffffff 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
        }
        .card {
            border-radius: 24px;
            box-shadow: 0 12px 48px rgba(0,0,0,0.08);
            border: none;
            max-width: 450px;
            width: 100%;
        }
        .card-header {
            background: var(--primary);
            color: white;
            border-radius: 24px 24px 0 0 !important;
            padding: 25px 30px;
            border: none;
            text-align: center;
        }
        .card-body {
            padding: 30px;
        }
        .form-label {
            font-weight: 600;
            color: var(--primary);
        }
        .form-control {
            border-radius: 12px;
            border: 1px solid #d0d7e8;
            padding: 12px 16px;
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.12);
        }
        .btn-primary {
            background: var(--primary);
            border: none;
            border-radius: 40px;
            padding: 12px 30px;
            font-weight: 600;
            width: 100%;
        }
        .btn-primary:hover {
            background: #1d2863;
        }
        .alert {
            border-radius: 12px;
        }
        .success-icon {
            font-size: 64px;
            color: #28a745;
        }
        .brand-name {
            font-weight: 800;
            font-size: 24px;
            letter-spacing: -0.5px;
        }
        .brand-name span {
            color: var(--accent);
        }
        .password-requirements {
            font-size: 13px;
            color: #6c757d;
            padding-left: 0;
            list-style: none;
        }
        .password-requirements li::before {
            content: "• ";
            color: var(--primary);
        }
        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #8a96b8;
            cursor: pointer;
            padding: 4px 8px;
        }
        .toggle-password:hover {
            color: var(--primary);
        }
        .position-relative {
            position: relative;
        }
        .error-icon {
            font-size: 48px;
            color: #dc3545;
        }
    </style>
</head>
<body>

<div class="card">
    <div class="card-header">
        <div class="brand-name">SBI-<span>ABC</span></div>
        <div class="mt-2" style="font-size: 14px; opacity: 0.8;">Smart Bite Care</div>
    </div>
    
    <div class="card-body">
        <?php if (!empty($error)): ?>
            <div class="text-center mb-3">
                <div class="error-icon mb-3">
                    <i class="bi bi-exclamation-circle-fill"></i>
                </div>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-circle-fill me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            </div>
            <div class="text-center mt-3">
                <a href="login.php" class="btn btn-primary">Go to Login</a>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="text-center">
                <div class="success-icon mb-3">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success); ?>
                </div>
                <p class="mt-3">Redirecting to login page...</p>
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        <?php elseif (empty($error) && !empty($token) && !empty($email)): ?>
            <h5 class="text-center mb-3">Set Your Password</h5>
            <p class="text-muted text-center mb-4">
                Create a strong password for your account.<br>
                <small>Account: <strong><?php echo htmlspecialchars($email); ?></strong></small>
            </p>
            
            <form action="change_password.php" method="POST">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>" />
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>" />
                
                <div class="mb-3">
                    <label class="form-label">New Password</label>
                    <div class="position-relative">
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder="Enter new password" required minlength="8" />
                        <button type="button" class="toggle-password" onclick="togglePassword('password')">
                            <i class="bi bi-eye" id="passwordIcon"></i>
                        </button>
                    </div>
                    <ul class="password-requirements mt-2">
                        <li>At least 8 characters long</li>
                        <li>Use a combination of letters, numbers, and symbols</li>
                    </ul>
                </div>
                
                <div class="mb-4">
                    <label class="form-label">Confirm Password</label>
                    <div class="position-relative">
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                               placeholder="Confirm your password" required />
                        <button type="button" class="toggle-password" onclick="togglePassword('confirm_password')">
                            <i class="bi bi-eye" id="confirmPasswordIcon"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check2-circle me-1"></i> Set Password & Activate Account
                </button>
            </form>
            
            <p class="text-center text-muted mt-3" style="font-size: 13px;">
                <i class="bi bi-info-circle me-1"></i>
                This link will expire after 24 hours.
            </p>
        <?php endif; ?>
    </div>
</div>

<script>
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const icon = document.getElementById(fieldId + 'Icon');
    
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
}

// Password strength indicator (optional)
document.getElementById('password')?.addEventListener('input', function() {
    const password = this.value;
    const requirements = document.querySelectorAll('.password-requirements li');
    
    // Check length
    if (password.length >= 8) {
        requirements[0].style.color = '#28a745';
        requirements[0].style.listStyle = 'none';
    } else {
        requirements[0].style.color = '#6c757d';
    }
    
    // Check for combination (simple check)
    const hasLetter = /[a-zA-Z]/.test(password);
    const hasNumber = /[0-9]/.test(password);
    const hasSymbol = /[^a-zA-Z0-9]/.test(password);
    
    if (hasLetter && hasNumber && hasSymbol) {
        requirements[1].style.color = '#28a745';
    } else {
        requirements[1].style.color = '#6c757d';
    }
});
</script>

</body>
</html>