<?php
session_start();

require_once __DIR__ . '/sources/db_connect.php';
require_once __DIR__ . '/sources/mailer.php';

if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$sessionRoleId = (int)$_SESSION['role_id'];

$dashboardRoutes = [
    1 => 'SuperAdmin_Dashboard.php',
    2 => 'BranchAdmin_Dashboard.php',
    3 => 'Nurse_Dashboard.php',
    4 => 'AdminStaff_Dashboard.php',
    5 => 'InventoryOfficer_Dashboard.php'
];

$roleNames = [
    1 => 'Super Admin',
    2 => 'Branch Admin',
    3 => 'Nurse',
    4 => 'Administrative Staff',
    5 => 'Inventory Officer'
];

$dashboardUrl = $dashboardRoutes[$sessionRoleId] ?? 'login.php';
$roleName = $roleNames[$sessionRoleId] ?? 'User';
$error = '';

if (empty($_SESSION['account_password_csrf'])) {
    $_SESSION['account_password_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['account_password_csrf'];

$userStatement = $conn->prepare(
    "SELECT user_id, branch_id, role_id, username, email, password, status
     FROM users
     WHERE user_id = ?
     LIMIT 1"
);
$userStatement->bind_param('i', $userId);
$userStatement->execute();
$account = $userStatement->get_result()->fetch_assoc();
$userStatement->close();

if (
    !$account ||
    (int)$account['role_id'] !== $sessionRoleId ||
    (string)$account['status'] !== 'Active'
) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

$username = (string)$account['username'];
$email = (string)$account['email'];
$branchId = (string)$account['branch_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = (string)($_POST['csrf_token'] ?? '');
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $transactionStarted = false;

    try {
        if (!hash_equals($csrfToken, $submittedCsrf)) {
            throw new RuntimeException('Your form session expired. Refresh the page and try again.');
        }

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            throw new RuntimeException('Complete all password fields.');
        }

        if (!password_verify($currentPassword, (string)$account['password'])) {
            throw new RuntimeException('The current password is incorrect.');
        }

        if (strlen($newPassword) < 8) {
            throw new RuntimeException('The new password must contain at least 8 characters.');
        }

        if (strlen($newPassword) > 128) {
            throw new RuntimeException('The new password must not exceed 128 characters.');
        }

        if (!preg_match('/[A-Z]/', $newPassword)) {
            throw new RuntimeException('The new password must contain an uppercase letter.');
        }

        if (!preg_match('/[a-z]/', $newPassword)) {
            throw new RuntimeException('The new password must contain a lowercase letter.');
        }

        if (!preg_match('/[0-9]/', $newPassword)) {
            throw new RuntimeException('The new password must contain a number.');
        }

        if (!preg_match('/[^A-Za-z0-9]/', $newPassword)) {
            throw new RuntimeException('The new password must contain a special character.');
        }

        if ($newPassword !== $confirmPassword) {
            throw new RuntimeException('The new password and confirmation do not match.');
        }

        if (password_verify($newPassword, (string)$account['password'])) {
            throw new RuntimeException('Choose a password different from your current password.');
        }

        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($newPasswordHash === false) {
            throw new RuntimeException('The password could not be secured. Please try again.');
        }

        $conn->begin_transaction();
        $transactionStarted = true;

        $updateStatement = $conn->prepare(
            'UPDATE users SET password = ? WHERE user_id = ? AND status = \'Active\''
        );
        $updateStatement->bind_param('si', $newPasswordHash, $userId);
        $updateStatement->execute();

        if ($updateStatement->affected_rows !== 1) {
            $updateStatement->close();
            throw new RuntimeException('The password was not updated. Please sign in again.');
        }
        $updateStatement->close();

        $tokenStatement = $conn->prepare(
            "DELETE FROM user_tokens
             WHERE user_id = ? AND token_type = 'password_reset'"
        );
        $tokenStatement->bind_param('i', $userId);
        $tokenStatement->execute();
        $invalidatedTokens = $tokenStatement->affected_rows;
        $tokenStatement->close();

        $auditAction = 'Changed own account password successfully; invalidated '
            . $invalidatedTokens
            . ' password-reset token(s).';
        $auditModule = 'Account Security';
        $auditStatement = $conn->prepare(
            'INSERT INTO audit_logs (user_id, branch_id, action, module)
             VALUES (?, ?, ?, ?)'
        );
        $auditStatement->bind_param(
            'isss',
            $userId,
            $branchId,
            $auditAction,
            $auditModule
        );
        $auditStatement->execute();
        $auditStatement->close();

        $conn->commit();
        $transactionStarted = false;

        session_regenerate_id(true);
        $_SESSION['account_password_csrf'] = bin2hex(random_bytes(32));

        $safeUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $changeTime = date('F j, Y \a\t g:i A');
        $mailBody = "
            <html>
            <body style='font-family:Arial,sans-serif;color:#27304d;'>
                <div style='max-width:600px;margin:0 auto;padding:24px;'>
                    <div style='background:#2B3A8C;color:#fff;padding:20px;text-align:center;'>
                        <h2 style='margin:0;'>Smart Bite Care</h2>
                    </div>
                    <div style='background:#f7f8fc;padding:24px;'>
                        <h3>Hello, {$safeUsername}!</h3>
                        <p>Your Smart Bite Care account password was changed on {$changeTime}.</p>
                        <p>If you did not make this change, contact your system administrator immediately.</p>
                    </div>
                    <p style='font-size:12px;color:#7c849b;text-align:center;'>
                        This is an automated security notification. Your password is never included in email.
                    </p>
                </div>
            </body>
            </html>";

        try {
            send_email($email, 'Smart Bite Care Password Changed', $mailBody);
        } catch (Throwable $mailError) {
            error_log('SmartBiteCare password notification error: ' . $mailError->getMessage());
        }

        header('Location: ' . $dashboardUrl . '?password_changed=1');
        exit;
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                // Keep the original error for the user.
            }
        }

        $error = $exception->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change Password - Smart Bite Care</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
            --page-bg: #f4f6fb;
        }

        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            margin: 0;
            background:
                radial-gradient(circle at 8% 12%, rgba(43, 58, 140, 0.10), transparent 28%),
                radial-gradient(circle at 92% 88%, rgba(242, 29, 47, 0.05), transparent 24%),
                var(--page-bg);
            color: #1f2a4d;
            font-family: 'Segoe UI', Roboto, system-ui, sans-serif;
        }

        .account-topbar {
            min-height: 80px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 14px 38px;
            background: #fff;
            border-bottom: 1px solid #e6eaf2;
            box-shadow: 0 3px 18px rgba(31, 42, 77, 0.06);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--primary);
            font-size: 21px;
            font-weight: 750;
        }

        .brand img {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            object-fit: contain;
            background: #fff;
        }

        .account-user {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--primary);
            font-weight: 650;
        }

        .account-user small {
            color: #8b93aa;
            font-weight: 450;
        }

        .account-shell {
            width: min(100% - 36px, 980px);
            margin: 48px auto;
        }

        .password-card {
            display: grid;
            grid-template-columns: minmax(300px, 0.88fr) minmax(420px, 1.22fr);
            overflow: hidden;
            background: #fff;
            border: 1px solid rgba(43, 58, 140, 0.10);
            border-radius: 24px;
            box-shadow: 0 22px 55px rgba(43, 58, 140, 0.14);
        }

        .card-heading {
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-height: 620px;
            padding: 44px 38px;
            color: #fff;
            background: linear-gradient(150deg, #2B3A8C 0%, #1f2d73 62%, #172354 100%);
            isolation: isolate;
        }

        .card-heading::before,
        .card-heading::after {
            position: absolute;
            z-index: -1;
            content: '';
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.06);
        }

        .card-heading::before {
            width: 240px;
            height: 240px;
            top: -90px;
            right: -100px;
        }

        .card-heading::after {
            width: 170px;
            height: 170px;
            bottom: -75px;
            left: -65px;
        }

        .shield-visual {
            width: 74px;
            height: 74px;
            display: grid;
            place-items: center;
            margin-bottom: 26px;
            border: 1px solid rgba(255, 255, 255, 0.22);
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.12);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.18);
            font-size: 34px;
        }

        .card-heading h1 {
            margin: 0 0 12px;
            font-size: 31px;
            font-weight: 750;
            letter-spacing: -0.5px;
        }

        .card-heading p {
            margin: 0;
            color: rgba(255, 255, 255, 0.76);
            line-height: 1.7;
        }

        .security-points {
            display: grid;
            gap: 14px;
            margin-top: 34px;
        }

        .security-point {
            display: flex;
            align-items: flex-start;
            gap: 11px;
            color: rgba(255, 255, 255, 0.86);
            font-size: 14px;
        }

        .security-point i {
            margin-top: 1px;
            color: #7ee2a8;
            font-size: 17px;
        }

        .card-content { padding: 42px 44px; }

        .form-heading {
            margin-bottom: 24px;
        }

        .form-heading h2 {
            margin: 0 0 7px;
            color: #202c68;
            font-size: 25px;
            font-weight: 750;
            letter-spacing: -0.3px;
        }

        .form-heading p {
            margin: 0;
            color: #7b849d;
            font-size: 14px;
        }

        .important-note {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 24px;
            padding: 14px 16px;
            border: 1px solid #f3d995;
            border-left: 4px solid #e4aa1a;
            border-radius: 12px;
            background: #fff9e9;
            color: #745513;
            font-size: 13px;
            line-height: 1.5;
        }

        .important-note i {
            margin-top: 1px;
            color: #d99b08;
            font-size: 19px;
        }

        .form-label {
            color: #34406b;
            font-weight: 650;
        }

        .password-field { position: relative; }

        .password-field .form-control {
            min-height: 48px;
            padding: 11px 48px 11px 14px;
            border-color: #dce1ec;
            border-radius: 10px;
            background: #fbfcff;
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: #77809a;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(43, 58, 140, 0.14);
        }

        .password-rules {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 12px;
            margin: 0;
            padding: 14px 16px;
            list-style: none;
            border: 1px solid #e6e9f1;
            border-radius: 12px;
            background: #f8f9fc;
            color: #5f6984;
            font-size: 13px;
        }

        .password-rules li {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .password-rules li::before {
            content: '\\F287';
            color: #a3aac0;
            font-family: 'bootstrap-icons';
        }

        .password-rules li.met {
            color: #257641;
        }

        .password-rules li.met::before {
            content: '\\F26A';
            color: #2ea45d;
        }

        .strength-wrap {
            margin: -4px 0 14px;
        }

        .strength-track {
            height: 5px;
            overflow: hidden;
            border-radius: 999px;
            background: #e9ecf3;
        }

        .strength-bar {
            width: 0;
            height: 100%;
            border-radius: inherit;
            background: #dc3545;
            transition: width 0.2s ease, background-color 0.2s ease;
        }

        .strength-label {
            display: block;
            margin-top: 6px;
            color: #8790a7;
            font-size: 12px;
        }

        .btn-primary {
            border-color: var(--primary);
            background: var(--primary);
            box-shadow: 0 8px 18px rgba(43, 58, 140, 0.18);
        }

        .btn-primary:hover,
        .btn-primary:focus {
            border-color: #202d72;
            background: #202d72;
        }

        .button-row .btn {
            min-height: 45px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 18px;
            border-radius: 10px;
            font-weight: 650;
        }

        @media (max-width: 850px) {
            .password-card { grid-template-columns: 1fr; }
            .card-heading {
                min-height: auto;
                padding: 32px;
            }
            .security-points {
                grid-template-columns: 1fr 1fr;
                margin-top: 24px;
            }
        }

        @media (max-width: 575px) {
            .account-topbar {
                align-items: flex-start;
                flex-direction: column;
                padding: 14px 18px;
            }

            .account-shell { margin: 24px auto; }
            .password-card { border-radius: 18px; }
            .card-heading, .card-content { padding: 26px 22px; }
            .security-points, .password-rules { grid-template-columns: 1fr; }
            .button-row { flex-direction: column-reverse; }
            .button-row .btn { width: 100%; }
        }
    </style>
</head>
<body>
    <header class="account-topbar">
        <div class="brand">
            <img src="logo.png" alt="Smart Bite Care logo">
            <span>Smart Bite Care</span>
        </div>
        <div class="account-user">
            <i class="bi bi-person-circle"></i>
            <span><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></span>
            <small>| <?= htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8') ?></small>
        </div>
    </header>

    <main class="account-shell">
        <section class="password-card">
            <div class="card-heading">
                <div class="shield-visual"><i class="bi bi-shield-lock-fill"></i></div>
                <h1>Keep your account secure</h1>
                <p>Use a strong, unique password to protect Smart Bite Care records and your account activity.</p>

                <div class="security-points">
                    <div class="security-point">
                        <i class="bi bi-check-circle-fill"></i>
                        <span>Your current password is required.</span>
                    </div>
                    <div class="security-point">
                        <i class="bi bi-check-circle-fill"></i>
                        <span>Old password-reset links will be invalidated.</span>
                    </div>
                    <div class="security-point">
                        <i class="bi bi-check-circle-fill"></i>
                        <span>The change is recorded in the audit log.</span>
                    </div>
                </div>
            </div>

            <div class="card-content">
                <div class="form-heading">
                    <h2>Change password</h2>
                    <p>Enter your current password, then create a new one.</p>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="bi bi-exclamation-circle-fill me-2"></i>
                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>


                <div class="important-note" role="note">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div>
                        <strong>Important:</strong> After saving, you will return to your dashboard.
                        The next time you log in, your old password will no longer work—use your new password.
                    </div>
                </div>

                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="mb-3">
                        <label class="form-label" for="current_password">Current password</label>
                        <div class="password-field">
                            <input
                                class="form-control"
                                id="current_password"
                                name="current_password"
                                type="password"
                                autocomplete="current-password"
                                required
                            >
                            <button class="password-toggle" type="button" data-password-target="current_password" aria-label="Show current password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="new_password">New password</label>
                        <div class="password-field">
                            <input
                                class="form-control"
                                id="new_password"
                                name="new_password"
                                type="password"
                                autocomplete="new-password"
                                minlength="8"
                                maxlength="128"
                                required
                            >
                            <button class="password-toggle" type="button" data-password-target="new_password" aria-label="Show new password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="strength-wrap" aria-live="polite">
                        <div class="strength-track"><div class="strength-bar" id="strengthBar"></div></div>
                        <span class="strength-label" id="strengthLabel">Password strength: not entered</span>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="confirm_password">Confirm new password</label>
                        <div class="password-field">
                            <input
                                class="form-control"
                                id="confirm_password"
                                name="confirm_password"
                                type="password"
                                autocomplete="new-password"
                                minlength="8"
                                maxlength="128"
                                required
                            >
                            <button class="password-toggle" type="button" data-password-target="confirm_password" aria-label="Show password confirmation">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <ul class="password-rules mb-4" id="passwordRules">
                        <li data-rule="length">At least 8 characters</li>
                        <li data-rule="upper">One uppercase letter</li>
                        <li data-rule="lower">One lowercase letter</li>
                        <li data-rule="number">One number</li>
                        <li data-rule="special">One special character</li>
                        <li data-rule="match">Passwords match</li>
                    </ul>

                    <div class="button-row d-flex justify-content-end gap-2">
                        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>">
                            <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
                        </a>
                        <button class="btn btn-primary" type="submit">
                            <i class="bi bi-check2-circle me-1"></i>Save New Password
                        </button>
                    </div>
                </form>
            </div>
        </section>
    </main>

    <script>
        document.querySelectorAll('[data-password-target]').forEach(function (button) {
            button.addEventListener('click', function () {
                const input = document.getElementById(button.dataset.passwordTarget);
                const icon = button.querySelector('i');
                const showing = input.type === 'text';

                input.type = showing ? 'password' : 'text';
                icon.className = showing ? 'bi bi-eye' : 'bi bi-eye-slash';
                button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            });
        });

        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');
        const strengthBar = document.getElementById('strengthBar');
        const strengthLabel = document.getElementById('strengthLabel');
        const ruleItems = document.querySelectorAll('#passwordRules [data-rule]');

        function updatePasswordFeedback() {
            const value = newPassword.value;
            const checks = {
                length: value.length >= 8,
                upper: /[A-Z]/.test(value),
                lower: /[a-z]/.test(value),
                number: /[0-9]/.test(value),
                special: /[^A-Za-z0-9]/.test(value),
                match: value.length > 0 && value === confirmPassword.value
            };

            ruleItems.forEach(function (item) {
                item.classList.toggle('met', Boolean(checks[item.dataset.rule]));
            });

            const score = ['length', 'upper', 'lower', 'number', 'special']
                .filter(function (key) { return checks[key]; })
                .length;

            const levels = [
                { width: '0%', color: '#dc3545', label: 'Password strength: not entered' },
                { width: '20%', color: '#dc3545', label: 'Password strength: very weak' },
                { width: '40%', color: '#e67e22', label: 'Password strength: weak' },
                { width: '60%', color: '#d6a000', label: 'Password strength: fair' },
                { width: '80%', color: '#4b9b61', label: 'Password strength: good' },
                { width: '100%', color: '#238b45', label: 'Password strength: strong' }
            ];
            const level = value.length === 0 ? levels[0] : levels[score];

            strengthBar.style.width = level.width;
            strengthBar.style.backgroundColor = level.color;
            strengthLabel.textContent = level.label;
        }

        newPassword.addEventListener('input', updatePasswordFeedback);
        confirmPassword.addEventListener('input', updatePasswordFeedback);
        updatePasswordFeedback();
    </script>
</body>
</html>
