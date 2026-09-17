<?php
session_start();

require_once __DIR__ . '/sources/db_connect.php';

$error = '';
$success = '';
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$email = strtolower(trim((string)($_GET['email'] ?? $_POST['email'] ?? '')));
$validRequest = false;
$account = null;

/**
 * Escape output safely.
 */
function resetH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Read and validate a password-reset token without requiring a logged-in session.
 */
function getResetAccount(mysqli $conn, string $token, string $email): ?array
{
    if ($token === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT
            t.token_id,
            t.user_id,
            t.token,
            t.expires_at,
            t.used_at,
            u.username,
            u.email,
            u.branch_id,
            u.status
         FROM user_tokens t
         INNER JOIN users u ON u.user_id = t.user_id
         WHERE t.token = ?
           AND t.token_type = 'password_reset'
           AND LOWER(u.email) = ?
         LIMIT 1"
    );
    $stmt->bind_param('ss', $token, $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * Add an audit event without allowing an audit failure to break password reset.
 */
function resetPasswordAudit(mysqli $conn, int $userId, ?string $branchId, string $action): void
{
    try {
        $module = 'Password Recovery';
        $stmt = $conn->prepare(
            'INSERT INTO audit_logs (user_id, branch_id, action, module)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('isss', $userId, $branchId, $action, $module);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('SmartBiteCare reset-password audit error: ' . $e->getMessage());
    }
}

try {
    $account = getResetAccount($conn, $token, $email);

    if (!$account) {
        $error = 'This password-reset link is invalid. Request a new link from the Forgot Password page.';
    } elseif ((string)$account['status'] !== 'Active') {
        $error = 'This account is not active. Please contact your administrator.';
    } elseif (!empty($account['used_at'])) {
        $error = 'This password-reset link has already been used. Request a new link if you still need to reset your password.';
    } elseif (strtotime((string)$account['expires_at']) <= time()) {
        $error = 'This password-reset link has expired. Request a new link from the Forgot Password page.';
    } else {
        $validRequest = true;
    }
} catch (Throwable $e) {
    error_log('SmartBiteCare reset-password validation error: ' . $e->getMessage());
    $error = 'The password-reset request could not be verified. Please try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validRequest && $account) {
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $transactionStarted = false;

    try {
        if ($password === '' || $confirmPassword === '') {
            throw new RuntimeException('Enter and confirm your new password.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('The new password must contain at least 8 characters.');
        }
        if (strlen($password) > 128) {
            throw new RuntimeException('The new password must not exceed 128 characters.');
        }
        if (!preg_match('/[A-Z]/', $password)) {
            throw new RuntimeException('The new password must contain an uppercase letter.');
        }
        if (!preg_match('/[a-z]/', $password)) {
            throw new RuntimeException('The new password must contain a lowercase letter.');
        }
        if (!preg_match('/[0-9]/', $password)) {
            throw new RuntimeException('The new password must contain a number.');
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            throw new RuntimeException('The new password must contain a special character.');
        }
        if ($password !== $confirmPassword) {
            throw new RuntimeException('The new password and confirmation do not match.');
        }

        // Re-check the token immediately before changing the password so a used/expired
        // token cannot be reused between the initial page load and form submission.
        $freshAccount = getResetAccount($conn, $token, $email);
        if (!$freshAccount) {
            throw new RuntimeException('This password-reset link is no longer valid.');
        }
        if ((string)$freshAccount['status'] !== 'Active') {
            throw new RuntimeException('This account is not active.');
        }
        if (!empty($freshAccount['used_at'])) {
            throw new RuntimeException('This password-reset link has already been used.');
        }
        if (strtotime((string)$freshAccount['expires_at']) <= time()) {
            throw new RuntimeException('This password-reset link has expired.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new RuntimeException('The new password could not be secured. Please try again.');
        }

        $userId = (int)$freshAccount['user_id'];
        $tokenId = (int)$freshAccount['token_id'];
        $branchId = $freshAccount['branch_id'] !== null ? (string)$freshAccount['branch_id'] : null;

        $conn->begin_transaction();
        $transactionStarted = true;

        $updateUser = $conn->prepare(
            "UPDATE users
             SET password = ?
             WHERE user_id = ? AND status = 'Active'"
        );
        $updateUser->bind_param('si', $passwordHash, $userId);
        $updateUser->execute();
        if ($updateUser->affected_rows !== 1) {
            $updateUser->close();
            throw new RuntimeException('The password could not be updated. Please request a new reset link.');
        }
        $updateUser->close();

        // Consume this token exactly once.
        $consumeToken = $conn->prepare(
            "UPDATE user_tokens
             SET used_at = NOW()
             WHERE token_id = ?
               AND used_at IS NULL
               AND expires_at > NOW()"
        );
        $consumeToken->bind_param('i', $tokenId);
        $consumeToken->execute();
        if ($consumeToken->affected_rows !== 1) {
            $consumeToken->close();
            throw new RuntimeException('This password-reset link is no longer valid.');
        }
        $consumeToken->close();

        // Invalidate any other unused reset links for the same account.
        $invalidate = $conn->prepare(
            "UPDATE user_tokens
             SET used_at = NOW()
             WHERE user_id = ?
               AND token_type = 'password_reset'
               AND used_at IS NULL"
        );
        $invalidate->bind_param('i', $userId);
        $invalidate->execute();
        $invalidate->close();

        $conn->commit();
        $transactionStarted = false;

        resetPasswordAudit(
            $conn,
            $userId,
            $branchId,
            'SUCCESS | Password reset completed using emailed reset token'
        );

        $success = 'Your password has been changed successfully. You can now sign in with your new password.';
        $validRequest = false;
        $account = null;
    } catch (Throwable $e) {
        if ($transactionStarted) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                // Preserve the original error.
            }
        }

        error_log('SmartBiteCare reset-password error: ' . $e->getMessage());
        $error = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'The password could not be reset. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password - Smart Bite Care</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --primary: #2B3A8C;
            --primary-dark: #1d2863;
            --accent: #F21D2F;
            --bg-light: #f0f3fc;
            --text-dark: #1a2340;
            --text-muted: #65708c;
        }

        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            margin: 0;
            padding: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--bg-light), #ffffff);
            color: var(--text-dark);
            font-family: 'Segoe UI', Roboto, Arial, sans-serif;
        }

        .reset-card {
            width: min(100%, 480px);
            overflow: hidden;
            border: 0;
            border-radius: 24px;
            background: #fff;
            box-shadow: 0 18px 55px rgba(43, 58, 140, .14);
        }

        .reset-header {
            padding: 28px 30px;
            text-align: center;
            color: #fff;
            background: var(--primary);
        }

        .reset-header .icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 58px;
            height: 58px;
            margin-bottom: 12px;
            border-radius: 50%;
            background: rgba(255,255,255,.14);
            font-size: 26px;
        }

        .reset-header h1 {
            margin: 0;
            font-size: 25px;
            font-weight: 750;
        }

        .reset-header p {
            margin: 7px 0 0;
            color: rgba(255,255,255,.78);
            font-size: 13px;
        }

        .reset-body { padding: 30px; }

        .form-label {
            color: var(--primary);
            font-weight: 650;
        }

        .form-control {
            min-height: 48px;
            border: 1px solid #d6dced;
            border-radius: 12px;
            padding: 10px 14px;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem rgba(43, 58, 140, .12);
        }

        .password-wrap { position: relative; }

        .password-wrap .form-control { padding-right: 48px; }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 8px;
            transform: translateY(-50%);
            width: 38px;
            height: 38px;
            border: 0;
            border-radius: 9px;
            color: var(--primary);
            background: transparent;
        }

        .password-toggle:hover { background: #eef1ff; }

        .requirements {
            margin-top: 10px;
            padding: 12px 14px;
            border-radius: 12px;
            background: #f7f8fc;
            color: var(--text-muted);
            font-size: 12px;
            line-height: 1.55;
        }

        .btn-reset {
            width: 100%;
            min-height: 48px;
            margin-top: 8px;
            border: 0;
            border-radius: 999px;
            background: var(--primary);
            color: #fff;
            font-weight: 700;
        }

        .btn-reset:hover {
            background: var(--primary-dark);
            color: #fff;
        }

        .back-link {
            display: block;
            margin-top: 18px;
            text-align: center;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .back-link:hover { text-decoration: underline; }

        .success-icon {
            display: block;
            margin-bottom: 14px;
            color: #198754;
            font-size: 58px;
            text-align: center;
        }
    </style>
</head>
<body>
<div class="reset-card">
    <div class="reset-header">
        <div class="icon"><i class="bi bi-shield-lock-fill"></i></div>
        <h1>Reset Password</h1>
        <p>Smart Bite Care account recovery</p>
    </div>

    <div class="reset-body">
        <?php if ($success !== ''): ?>
            <i class="bi bi-check-circle-fill success-icon"></i>
            <div class="alert alert-success mb-3"><?php echo resetH($success); ?></div>
            <a href="login.php" class="btn btn-reset d-flex align-items-center justify-content-center text-decoration-none">
                <i class="bi bi-box-arrow-in-right me-2"></i> Sign In
            </a>
        <?php else: ?>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo resetH($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($validRequest && $account): ?>
                <p class="text-muted mb-4">
                    Resetting the password for <strong><?php echo resetH($account['username']); ?></strong>.
                </p>

                <form method="post" autocomplete="off">
                    <input type="hidden" name="token" value="<?php echo resetH($token); ?>">
                    <input type="hidden" name="email" value="<?php echo resetH($email); ?>">

                    <div class="mb-3">
                        <label for="password" class="form-label">New Password</label>
                        <div class="password-wrap">
                            <input type="password" class="form-control" id="password" name="password" required minlength="8" maxlength="128" autocomplete="new-password">
                            <button type="button" class="password-toggle" data-target="password" aria-label="Show or hide password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <div class="password-wrap">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8" maxlength="128" autocomplete="new-password">
                            <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Show or hide password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="requirements">
                        Use at least 8 characters with an uppercase letter, lowercase letter, number, and special character.
                    </div>

                    <button type="submit" class="btn-reset mt-4">
                        <i class="bi bi-key-fill me-2"></i> Set New Password
                    </button>
                </form>
            <?php elseif ($error !== ''): ?>
                <a href="forgot_password.php" class="btn btn-reset d-flex align-items-center justify-content-center text-decoration-none">
                    Request New Reset Link
                </a>
            <?php endif; ?>

            <a href="login.php" class="back-link"><i class="bi bi-arrow-left me-1"></i> Back to Sign In</a>
        <?php endif; ?>
    </div>
</div>

<script>
document.querySelectorAll('.password-toggle').forEach(function (button) {
    button.addEventListener('click', function () {
        const input = document.getElementById(button.dataset.target);
        const icon = button.querySelector('i');
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        icon.classList.toggle('bi-eye', !show);
        icon.classList.toggle('bi-eye-slash', show);
    });
});
</script>
</body>
</html>
