<?php
session_start();

require_once 'sources/db_connect.php';
require_once 'sources/mailer.php';
require_once 'sources/app_config.php';

$message = '';
$messageType = 'success';
$submittedEmail = '';

if (empty($_SESSION['forgot_password_csrf'])) {
    $_SESSION['forgot_password_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['forgot_password_csrf'];

/**
 * Record password-reset activity only when a matching user exists.
 * This avoids invalid foreign keys for unknown email addresses.
 */
function forgotPasswordAudit(
    mysqli $conn,
    int $userId,
    string $branchId,
    string $action
): void {
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
        error_log('SmartBiteCare password-recovery audit error: ' . $e->getMessage());
    }
}

/**
 * Return the same public response whether an email exists or not.
 * This prevents the page from revealing registered accounts.
 */
function forgotPasswordPublicMessage(): string
{
    return 'If an active account matches that email address, a password-reset link has been sent. Please also check your spam folder.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedEmail = strtolower(trim((string)($_POST['email'] ?? '')));
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $postedCsrf)) {
        $message = 'Your request expired. Refresh the page and try again.';
        $messageType = 'danger';
    } elseif (!filter_var($submittedEmail, FILTER_VALIDATE_EMAIL)) {
        $message = 'Enter a valid email address.';
        $messageType = 'danger';
    } else {
        // Always prepare the generic public response first. Detailed errors are
        // written only to server logs and audit logs.
        $message = forgotPasswordPublicMessage();
        $messageType = 'success';

        try {
            $userStmt = $conn->prepare(
                "SELECT user_id, username, email, branch_id
                 FROM users
                 WHERE LOWER(email) = ?
                   AND status = 'Active'
                 LIMIT 1"
            );
            $userStmt->bind_param('s', $submittedEmail);
            $userStmt->execute();
            $user = $userStmt->get_result()->fetch_assoc();
            $userStmt->close();

            if ($user) {
                $userId = (int)$user['user_id'];
                $username = (string)$user['username'];
                $accountEmail = (string)$user['email'];
                $branchId = (string)$user['branch_id'];

                // Limit repeated requests for the same account to one every
                // two minutes while still returning the generic public message.
                $cooldownStmt = $conn->prepare(
                    "SELECT token_id
                     FROM user_tokens
                     WHERE user_id = ?
                       AND token_type = 'password_reset'
                       AND used_at IS NULL
                       AND expires_at > NOW()
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                     LIMIT 1"
                );
                $cooldownStmt->bind_param('i', $userId);
                $cooldownStmt->execute();
                $recentRequest = $cooldownStmt->get_result()->fetch_assoc();
                $cooldownStmt->close();

                if (!$recentRequest) {
                    $rawToken = bin2hex(random_bytes(32));
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    $transactionStarted = false;

                    try {
                        $conn->begin_transaction();
                        $transactionStarted = true;

                        // Only the newest unused reset link should remain valid.
                        $deleteStmt = $conn->prepare(
                            "DELETE FROM user_tokens
                             WHERE user_id = ?
                               AND token_type = 'password_reset'
                               AND used_at IS NULL"
                        );
                        $deleteStmt->bind_param('i', $userId);
                        $deleteStmt->execute();
                        $deleteStmt->close();

                        $tokenStmt = $conn->prepare(
                            "INSERT INTO user_tokens
                                (user_id, token, token_type, expires_at)
                             VALUES (?, ?, 'password_reset', ?)"
                        );
                        $tokenStmt->bind_param(
                            'iss',
                            $userId,
                            $rawToken,
                            $expiresAt
                        );
                        $tokenStmt->execute();
                        $tokenStmt->close();

                        $conn->commit();
                        $transactionStarted = false;
                    } catch (Throwable $tokenError) {
                        if ($transactionStarted) {
                            try {
                                $conn->rollback();
                            } catch (Throwable $ignored) {
                            }
                        }
                        throw $tokenError;
                    }

                    $resetLink = APP_URL
                        . '/change_password.php?'
                        . http_build_query([
                            'token' => $rawToken,
                            'email' => $accountEmail
                        ]);

                    $safeUsername = htmlspecialchars(
                        $username,
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    $safeResetLink = htmlspecialchars(
                        $resetLink,
                        ENT_QUOTES,
                        'UTF-8'
                    );

                    $emailBody = "
                    <!DOCTYPE html>
                    <html lang='en'>
                    <head>
                        <meta charset='UTF-8'>
                        <meta name='viewport' content='width=device-width, initial-scale=1'>
                        <style>
                            body {
                                margin: 0;
                                padding: 24px;
                                background: #f0f3fc;
                                color: #1a2340;
                                font-family: Arial, sans-serif;
                            }
                            .card {
                                max-width: 600px;
                                margin: 0 auto;
                                overflow: hidden;
                                border-radius: 18px;
                                background: #ffffff;
                                box-shadow: 0 10px 30px rgba(43, 58, 140, .12);
                            }
                            .header {
                                padding: 24px;
                                background: #2B3A8C;
                                color: #ffffff;
                                text-align: center;
                            }
                            .content {
                                padding: 30px;
                                line-height: 1.65;
                            }
                            .button {
                                display: inline-block;
                                margin: 18px 0;
                                padding: 13px 28px;
                                border-radius: 999px;
                                background: #2B3A8C;
                                color: #ffffff !important;
                                font-weight: bold;
                                text-decoration: none;
                            }
                            .link {
                                overflow-wrap: anywhere;
                                color: #2B3A8C;
                                font-size: 13px;
                            }
                            .notice {
                                padding: 12px 14px;
                                border-radius: 10px;
                                background: #fff4e5;
                                color: #7a4b00;
                            }
                            .footer {
                                padding: 18px 30px;
                                background: #f8f9fc;
                                color: #6c7897;
                                font-size: 12px;
                                text-align: center;
                            }
                        </style>
                    </head>
                    <body>
                        <div class='card'>
                            <div class='header'>
                                <h2>Smart Bite Care</h2>
                            </div>
                            <div class='content'>
                                <h3>Password reset request</h3>
                                <p>Hello, {$safeUsername}.</p>
                                <p>We received a request to reset the password for your SmartBiteCare account.</p>
                                <p style='text-align:center;'>
                                    <a class='button' href='{$safeResetLink}'>Reset Password</a>
                                </p>
                                <p class='notice'>This link expires in 24 hours and can only be used once. If you did not request it, you can ignore this email.</p>
                                <p>If the button does not work, copy this address:</p>
                                <p class='link'>{$safeResetLink}</p>
                            </div>
                            <div class='footer'>
                                This is an automated message from Smart Bite Care System.
                            </div>
                        </div>
                    </body>
                    </html>";

                    try {
                        $sent = send_email(
                            $accountEmail,
                            'SmartBiteCare Password Reset',
                            $emailBody
                        );
                    } catch (Throwable $mailError) {
                        $sent = false;
                        error_log(
                            'SmartBiteCare password-reset mail error for user ID '
                            . $userId . ': ' . $mailError->getMessage()
                        );
                    }

                    if ($sent) {
                        forgotPasswordAudit(
                            $conn,
                            $userId,
                            $branchId,
                            'SUCCESS | Password-reset link requested and sent'
                        );
                    } else {
                        forgotPasswordAudit(
                            $conn,
                            $userId,
                            $branchId,
                            'FAILED | Password-reset email delivery failed'
                        );
                        error_log(
                            'SmartBiteCare could not send password-reset email for user ID '
                            . $userId
                        );
                    }
                } else {
                    forgotPasswordAudit(
                        $conn,
                        $userId,
                        $branchId,
                        'NO CHANGE | Password-reset request received during cooldown'
                    );
                }
            }
        } catch (Throwable $e) {
            // Do not expose database, account, or mail details to the visitor.
            error_log('SmartBiteCare forgot-password error: ' . $e->getMessage());

            if (isset($userId, $branchId) && $userId > 0 && $branchId !== '') {
                forgotPasswordAudit(
                    $conn,
                    $userId,
                    $branchId,
                    'FAILED | Password-reset request could not be processed'
                );
            }
        }

        // Rotate the form token after a valid submission.
        $_SESSION['forgot_password_csrf'] = bin2hex(random_bytes(32));
        $csrfToken = (string)$_SESSION['forgot_password_csrf'];
        $submittedEmail = '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password - SmartBiteCare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        :root {
            --primary: #2B3A8C;
            --primary-dark: #1a235a;
            --accent: #F21D2F;
            --bg-light: #f0f3fc;
            --text-dark: #1a2340;
            --text-muted: #5a6a8a;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow-x: hidden;
            background: linear-gradient(135deg, var(--bg-light), #ffffff);
            color: var(--text-dark);
            font-family: 'Segoe UI', Roboto, system-ui, sans-serif;
        }

        .background-icon {
            position: fixed;
            z-index: 0;
            color: var(--primary);
            opacity: .045;
            pointer-events: none;
        }

        .background-icon.one {
            top: 7%;
            left: 5%;
            font-size: 120px;
            transform: rotate(-12deg);
        }

        .background-icon.two {
            right: 5%;
            bottom: 7%;
            font-size: 150px;
            transform: rotate(12deg);
        }

        .page-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 440px;
        }

        .recovery-card {
            position: relative;
            overflow: hidden;
            padding: 44px 36px 36px;
            border-radius: 24px;
            background: #ffffff;
            box-shadow: 0 12px 48px rgba(0, 0, 0, .08);
        }

        .recovery-card::before {
            position: absolute;
            top: 0;
            right: 0;
            left: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            content: '';
        }

        .brand {
            margin-bottom: 28px;
            text-align: center;
        }

        .brand img {
            width: auto;
            height: 56px;
            margin-bottom: 8px;
            border-radius: 10px;
            object-fit: contain;
        }

        .brand-name {
            color: var(--primary);
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -.5px;
        }

        .brand-name span {
            color: var(--accent);
        }

        .brand-subtitle {
            display: block;
            margin-top: -2px;
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .recovery-icon {
            display: flex;
            width: 62px;
            height: 62px;
            margin: 0 auto 18px;
            align-items: center;
            justify-content: center;
            border-radius: 18px;
            background: rgba(43, 58, 140, .1);
            color: var(--primary);
            font-size: 28px;
        }

        h1 {
            margin: 0 0 7px;
            color: var(--primary);
            font-size: 22px;
            font-weight: 700;
            text-align: center;
        }

        .description {
            margin-bottom: 26px;
            color: var(--text-muted);
            font-size: 14px;
            line-height: 1.55;
            text-align: center;
        }

        .form-label {
            color: var(--primary);
            font-size: 14px;
            font-weight: 600;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper .input-icon {
            position: absolute;
            top: 50%;
            left: 14px;
            color: #8a96b8;
            font-size: 18px;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .input-wrapper .form-control {
            padding: 12px 16px 12px 44px;
            border: 1px solid #d0d7e8;
            border-radius: 12px;
            background: #fafbff;
            font-size: 15px;
        }

        .input-wrapper .form-control:focus {
            border-color: var(--primary);
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(43, 58, 140, .12);
        }

        .btn-reset {
            display: flex;
            width: 100%;
            padding: 14px;
            align-items: center;
            justify-content: center;
            gap: 9px;
            border: 0;
            border-radius: 40px;
            background: var(--primary);
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            transition: .2s;
        }

        .btn-reset:hover {
            background: var(--primary-dark);
            box-shadow: 0 8px 25px rgba(43, 58, 140, .28);
            color: #ffffff;
            transform: translateY(-2px);
        }

        .alert-custom {
            padding: 12px 14px;
            border: 0;
            border-radius: 12px;
            font-size: 14px;
        }

        .back-link {
            display: block;
            margin-top: 20px;
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 500;
            text-align: center;
            text-decoration: none;
        }

        .back-link:hover {
            color: var(--primary);
        }

        @media (max-width: 576px) {
            .recovery-card {
                padding: 34px 20px 28px;
            }

            .background-icon {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="background-icon one"><i class="bi bi-shield-lock"></i></div>
    <div class="background-icon two"><i class="bi bi-envelope-check"></i></div>

    <main class="page-wrapper">
        <section class="recovery-card">
            <div class="brand">
                <img src="logo.png" alt="SBI-ABC Logo">
                <div class="brand-name">SBI-<span>ABC</span></div>
                <span class="brand-subtitle">Smart Bite Care</span>
            </div>

            <div class="recovery-icon">
                <i class="bi bi-key"></i>
            </div>

            <h1>Forgot your password?</h1>
            <p class="description">
                Enter the email address connected to your account. We will send
                you a secure link to choose a new password.
            </p>

            <?php if ($message !== ''): ?>
                <div class="alert alert-<?php echo htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8'); ?> alert-custom" role="alert">
                    <i class="bi <?php echo $messageType === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill'; ?> me-2"></i>
                    <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="forgot_password.php" autocomplete="off">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"
                >

                <div class="mb-4">
                    <label for="email" class="form-label">Email address</label>
                    <div class="input-wrapper">
                        <span class="input-icon"><i class="bi bi-envelope"></i></span>
                        <input
                            type="email"
                            class="form-control"
                            id="email"
                            name="email"
                            maxlength="255"
                            placeholder="Enter your registered email"
                            value="<?php echo htmlspecialchars($submittedEmail, ENT_QUOTES, 'UTF-8'); ?>"
                            autocomplete="email"
                            required
                        >
                    </div>
                </div>

                <button type="submit" class="btn-reset">
                    <i class="bi bi-send"></i>
                    Send Reset Link
                </button>
            </form>

            <a href="login.php" class="back-link">
                <i class="bi bi-arrow-left me-1"></i>
                Back to Sign In
            </a>
        </section>
    </main>
</body>
</html>
