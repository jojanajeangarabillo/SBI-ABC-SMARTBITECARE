<?php
session_start();
require_once 'sources/db_connect.php';

$token = $_GET['token'] ?? '';
$email = $_GET['email'] ?? '';

if (empty($token) || empty($email)) {
    die("
    <!DOCTYPE html>
    <html>
    <head>
        <title>Invalid Verification Link</title>
        <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' rel='stylesheet'>
        <style>
            body { background: #f8f9fa; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
            .card { border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); max-width: 500px; }
            .card-header { background: #dc3545; color: white; border-radius: 16px 16px 0 0; padding: 20px; }
        </style>
    </head>
    <body>
        <div class='card'>
            <div class='card-header'>
                <h4 class='mb-0'><i class='bi bi-exclamation-triangle-fill me-2'></i>Invalid Verification Link</h4>
            </div>
            <div class='card-body text-center py-4'>
                <p class='mb-3'>The verification link is incomplete or invalid.</p>
                <a href='login.php' class='btn btn-primary'>Go to Login</a>
            </div>
        </div>
    </body>
    </html>
    ");
    exit();
}

// Verify token and email
$stmt = $conn->prepare("SELECT user_id, username FROM users WHERE email = ? AND verification_token = ? AND token_expiry > NOW() AND status = 'Inactive'");
$stmt->bind_param("ss", $email, $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    
    // Token is valid, redirect to change password page
    $_SESSION['verification_email'] = $email;
    $_SESSION['verification_token'] = $token;
    
    // Optionally update user to set as verified
    $update_stmt = $conn->prepare("UPDATE users SET status = 'Active' WHERE email = ?");
    $update_stmt->bind_param("s", $email);
    $update_stmt->execute();
    $update_stmt->close();
    
    // Mark token as used in user_tokens table
    $tokenStmt = $conn->prepare("UPDATE user_tokens SET used_at = NOW() WHERE token = ?");
    $tokenStmt->bind_param("s", $token);
    $tokenStmt->execute();
    $tokenStmt->close();
    
    // Redirect to change password page
    header("Location: change_password.php?token=" . $token . "&email=" . urlencode($email));
    exit();
    
} else {
    // Check if already verified
    $checkStmt = $conn->prepare("SELECT status FROM users WHERE email = ? AND status = 'Active'");
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        // Already verified
        echo "
        <!DOCTYPE html>
        <html>
        <head>
            <title>Account Already Verified</title>
            <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' rel='stylesheet'>
            <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css'>
            <style>
                body { background: #f8f9fa; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
                .card { border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); max-width: 500px; }
                .card-header { background: #28a745; color: white; border-radius: 16px 16px 0 0; padding: 20px; }
            </style>
        </head>
        <body>
            <div class='card'>
                <div class='card-header'>
                    <h4 class='mb-0'><i class='bi bi-check-circle-fill me-2'></i>Account Already Verified</h4>
                </div>
                <div class='card-body text-center py-4'>
                    <p class='mb-3'>Your account has already been verified and activated.</p>
                    <p class='text-muted'>You can now login with your credentials.</p>
                    <a href='login.php' class='btn btn-success'>Go to Login</a>
                </div>
            </div>
        </body>
        </html>
        ";
    } else {
        // Invalid or expired token
        echo "
        <!DOCTYPE html>
        <html>
        <head>
            <title>Invalid or Expired Link</title>
            <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' rel='stylesheet'>
            <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css'>
            <style>
                body { background: #f8f9fa; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
                .card { border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); max-width: 500px; }
                .card-header { background: #dc3545; color: white; border-radius: 16px 16px 0 0; padding: 20px; }
            </style>
        </head>
        <body>
            <div class='card'>
                <div class='card-header'>
                    <h4 class='mb-0'><i class='bi bi-clock-history me-2'></i>Invalid or Expired Link</h4>
                </div>
                <div class='card-body text-center py-4'>
                    <p class='mb-3'>The verification link is invalid or has expired.</p>
                    <p class='text-muted'>Please contact your Super Admin to resend the verification email.</p>
                    <a href='login.php' class='btn btn-primary'>Go to Login</a>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    $checkStmt->close();
}

$stmt->close();
$conn->close();
?>