<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/mailer.php';

// Check if user is logged in and is branch admin
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 2
) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$branch_id = null;
$branch_name = '';
$username = '';
$message = '';
$message_type = '';

// Get user's branch info
$userQuery = "SELECT u.branch_id, u.username, b.branch_name 
              FROM users u 
              LEFT JOIN branches b ON u.branch_id = b.branch_id 
              WHERE u.user_id = ?";
$stmt = $conn->prepare($userQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$userResult = $stmt->get_result();

if ($userResult->num_rows > 0) {
    $userData = $userResult->fetch_assoc();
    $branch_id = $userData['branch_id'];
    $branch_name = $userData['branch_name'] ?? 'Unknown Branch';
    $username = $userData['username'] ?? 'Admin';
}

// If no branch assigned
if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
}

// Handle Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    $new_username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role_id = (int)($_POST['role_id'] ?? 0);
    $status = $_POST['status'] ?? 'Active';
    
    // Validate
    $errors = [];
    if (empty($new_username)) $errors[] = "Username is required";
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required";
    if (empty($role_id)) $errors[] = "Role is required";
    
    // Check uniqueness
    if (empty($errors)) {
        $checkQuery = "SELECT user_id FROM users WHERE username = ? OR email = ?";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("ss", $new_username, $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors[] = "Username or email already exists";
        }
    }
    
    if (empty($errors)) {
        $temp_password = bin2hex(random_bytes(8));
        $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
        
        $insertQuery = "INSERT INTO users (branch_id, role_id, username, email, password, status) 
                        VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insertQuery);
        $stmt->bind_param("sissss", $branch_id, $role_id, $new_username, $email, $hashed_password, $status);
        
        if ($stmt->execute()) {
            $new_user_id = $stmt->insert_id;
            
            // Generate a unique token for password reset
            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
            
            // Insert token into user_tokens table
            $tokenQuery = "INSERT INTO user_tokens (user_id, token, token_type, expires_at) 
                           VALUES (?, ?, 'password_reset', ?)";
            $stmt = $conn->prepare($tokenQuery);
            $stmt->bind_param("iss", $new_user_id, $token, $expires_at);
            $stmt->execute();
            
            // Log action
            $logQuery = "INSERT INTO audit_logs (user_id, branch_id, action, module) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($logQuery);
            $action = "Added new user: " . $new_username . " (ID: " . $new_user_id . ")";
            $module = "User Management";
            $stmt->bind_param("isss", $user_id, $branch_id, $action, $module);
            $stmt->execute();
            
            // Send email with PHPMailer
            $reset_link = "http://" . $_SERVER['HTTP_HOST'] . "/change_password.php?token=" . $token . "&email=" . urlencode($email);
            
            $subject = "Welcome to Smart Bite Care - Your Account Credentials";
            $body = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #2B3A8C; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; background: #f9f9f9; }
                    .credentials { background: white; padding: 15px; border-radius: 5px; margin: 15px 0; }
                    .btn { display: inline-block; padding: 10px 20px; background: #2B3A8C; color: white; text-decoration: none; border-radius: 5px; }
                    .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Smart Bite Care</h2>
                        <p>Account Created Successfully</p>
                    </div>
                    <div class='content'>
                        <h3>Welcome, " . htmlspecialchars($new_username) . "!</h3>
                        <p>Your account has been created for branch: <strong>" . htmlspecialchars($branch_name) . "</strong></p>
                        
                        <div class='credentials'>
                            <p><strong>Username:</strong> " . htmlspecialchars($new_username) . "</p>
                            <p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>
                            <p><strong>Temporary Password:</strong> " . $temp_password . "</p>
                        </div>
                        
                        <p>For security, please change your password immediately:</p>
                        
                        <p style='text-align: center;'>
                            <a href='" . $reset_link . "' class='btn'>Set Your Password</a>
                        </p>
                        
                        <p><small>This link expires in 24 hours.</small></p>
                    </div>
                    <div class='footer'>
                        <p>This is an automated message. Please do not reply.</p>
                        <p>&copy; 2024 Smart Bite Care. All rights reserved.</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            $mail_sent = send_email($email, $subject, $body);
            
            if ($mail_sent) {
                $message = "User added successfully! An email with credentials has been sent.";
                $message_type = "success";
            } else {
                $message = "User added but email could not be sent. Please reset password manually.";
                $message_type = "warning";
            }
        } else {
            $message = "Failed to add user: " . $conn->error;
            $message_type = "danger";
        }
    } else {
        $message = implode("<br>", $errors);
        $message_type = "danger";
    }
}

// Handle Edit User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_user') {
    $target_user_id = (int)$_POST['user_id'];
    $new_username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role_id = (int)($_POST['role_id'] ?? 0);
    $status = $_POST['status'] ?? 'Active';
    $new_password = $_POST['new_password'] ?? '';
    
    // Validate
    $errors = [];
    if (empty($new_username)) $errors[] = "Username is required";
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required";
    if (empty($role_id)) $errors[] = "Role is required";
    
    if (empty($errors)) {
        // Check if username or email already exists for other users
        $checkQuery = "SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id != ? AND branch_id = ?";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("ssis", $new_username, $email, $target_user_id, $branch_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $message = "Username or email already exists for another user";
            $message_type = "danger";
        } else {
            // Build update query
            $updateFields = "username = ?, email = ?, role_id = ?, status = ?";
            $params = "ssis";
            $paramValues = [$new_username, $email, $role_id, $status];
            
            if (!empty($new_password)) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $updateFields .= ", password = ?";
                $params .= "s";
                $paramValues[] = $hashed_password;
            }
            
            $paramValues[] = $target_user_id;
                $params .= "i";

                $paramValues[] = $branch_id;   // ADD THIS
                $params .= "s";               // ADD THIS

                $updateQuery = "UPDATE users SET $updateFields WHERE user_id = ? AND branch_id = ?";
                $stmt = $conn->prepare($updateQuery);
                $stmt->bind_param($params, ...$paramValues);
            
            if ($stmt->execute()) {
                // Log action
                $logQuery = "INSERT INTO audit_logs (user_id, branch_id, action, module) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($logQuery);
                $action = "Updated user: " . $new_username . " (ID: " . $target_user_id . ")";
                $module = "User Management";
                $stmt->bind_param("isss", $user_id, $branch_id, $action, $module);
                $stmt->execute();
                
                $message = "User updated successfully!";
                $message_type = "success";
            } else {
                $message = "Failed to update user: " . $conn->error;
                $message_type = "danger";
            }
        }
    } else {
        $message = implode("<br>", $errors);
        $message_type = "danger";
    }
}

// Handle Toggle Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    $target_user_id = (int)$_POST['user_id'];
    $current_status = $_POST['status'];
    $new_status = $current_status === 'Active' ? 'Inactive' : 'Active';
    
    $updateQuery = "UPDATE users SET status = ? WHERE user_id = ? AND branch_id = ?";
    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("sis", $new_status, $target_user_id, $branch_id);
    
    if ($stmt->execute()) {
        // Log action
        $logQuery = "INSERT INTO audit_logs (user_id, branch_id, action, module) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($logQuery);
        $action = "Changed user status to " . $new_status . " (ID: " . $target_user_id . ")";
        $module = "User Management";
        $stmt->bind_param("isss", $user_id, $branch_id, $action, $module);
        $stmt->execute();
        
        $message = "User status updated successfully!";
        $message_type = "success";
    } else {
        $message = "Failed to update status";
        $message_type = "danger";
    }
}

// Handle Archive User (sets status to Inactive)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'archive_user') {
    $target_user_id = (int)$_POST['user_id'];
    
    if ($target_user_id != $user_id) {
        // Check user role
        $roleCheckQuery = "SELECT role_id, username FROM users WHERE user_id = ? AND branch_id = ?";
        $stmt = $conn->prepare($roleCheckQuery);
        $stmt->bind_param("is", $target_user_id, $branch_id);
        $stmt->execute();
        $userData = $stmt->get_result()->fetch_assoc();
        
        if ($userData && !in_array($userData['role_id'], [1, 2])) {
            // Set status to Inactive
            $archiveQuery = "UPDATE users SET status = 'Inactive' WHERE user_id = ? AND branch_id = ?";
            $stmt = $conn->prepare($archiveQuery);
            $stmt->bind_param("is", $target_user_id, $branch_id);
            
            if ($stmt->execute()) {
                // Log action
                $logQuery = "INSERT INTO audit_logs (user_id, branch_id, action, module) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($logQuery);
                $action = "Archived user: " . $userData['username'] . " (ID: " . $target_user_id . ")";
                $module = "User Management";
                $stmt->bind_param("isss", $user_id, $branch_id, $action, $module);
                $stmt->execute();
                
                $message = "User archived successfully!";
                $message_type = "success";
            } else {
                $message = "Failed to archive user";
                $message_type = "danger";
            }
        } else {
            $message = "Cannot archive this user";
            $message_type = "danger";
        }
    } else {
        $message = "You cannot archive your own account";
        $message_type = "danger";
    }
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get total users count
$countQuery = "SELECT COUNT(*) as total 
               FROM users 
               WHERE branch_id = ? 
               AND user_id != ? 
               AND role_id NOT IN (1, 2)";
$stmt = $conn->prepare($countQuery);
$stmt->bind_param("si", $branch_id, $user_id);
$stmt->execute();
$totalUsers = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
$totalPages = ceil($totalUsers / $limit);

// Fetch users
$usersQuery = "SELECT u.user_id, u.username, u.email, u.status, r.role_id, r.role_name
               FROM users u
               JOIN roles r ON u.role_id = r.role_id
               WHERE u.branch_id = ? 
               AND u.user_id != ?
               AND u.role_id NOT IN (1, 2)
               ORDER BY u.user_id DESC
               LIMIT ? OFFSET ?";
$stmt = $conn->prepare($usersQuery);
$stmt->bind_param("siii", $branch_id, $user_id, $limit, $offset);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get available roles
$rolesQuery = "SELECT role_id, role_name FROM roles WHERE role_id NOT IN (1, 2)";
$roles = $conn->query($rolesQuery)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Management - <?php echo htmlspecialchars($branch_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="sidebar.css">
    <style>
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
            --bg: #F2F2F2;
        }
        body {
            background: white;
            font-family: 'Segoe UI', sans-serif;
        }
        .main {
            margin-left: 260px;
            min-height: 100vh;
        }
        .topbar {
            background: white;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 35px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }
        .topbar h3 {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
        }
        .topbar h3 small {
            font-size: 16px;
            font-weight: 400;
            color: #666;
            margin-left: 10px;
        }
        .profile {
            font-weight: 600;
            color: var(--primary);
            cursor: pointer;
        }
        @media(max-width:991px) {
            .main { margin-left: 90px; }
        }
        .content-wrapper {
            padding: 28px 35px 40px 35px;
        }
        .page-header {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            margin-bottom: 24px;
        }
        .btn-add-user {
            background: var(--primary);
            border: none;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 15px;
            border-radius: 8px;
            color: #fff;
            transition: background 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-add-user:hover {
            background: #1f2d6e;
            color: #fff;
        }
        .table-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
            overflow: hidden;
        }
        .table-card .table {
            margin: 0;
            border: 1px solid #d9dee8;
        }
        .table-card .table thead th {
            background: var(--primary);
            color: #fff;
            padding: 16px 20px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .table-card .table tbody td {
            padding: 14px 20px;
            color: #1e293b;
            border-bottom: 1px solid #f0f2f7;
        }
        .table-card .table tbody tr:hover {
            background: #f8faff;
        }
        .badge-status {
            font-weight: 600;
            font-size: 12px;
            padding: 5px 14px;
            border-radius: 20px;
            letter-spacing: 0.2px;
            display: inline-block;
            cursor: pointer;
            border: none;
        }
        .badge-active {
            background: #dff0e6;
            color: #0f7b3a;
        }
        .badge-inactive {
            background: #f1f2f6;
            color: #6b7280;
        }
        .action-icon {
            color: var(--primary);
            font-size: 20px;
            opacity: 0.7;
            transition: opacity 0.2s, transform 0.15s;
            cursor: pointer;
            display: inline-block;
            margin: 0 5px;
        }
        .action-icon:hover {
            opacity: 1;
            transform: scale(1.1);
        }
        .action-icon.archive {
            color: #ffc107;
        }
        .pagination-wrap {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }
        .pagination-wrap .pagination { margin: 0; gap: 2px; }
        .pagination-wrap .page-link {
            border: none;
            color: #2d3a7a;
            font-weight: 600;
            font-size: 14px;
            padding: 8px 15px;
            border-radius: 8px;
            background: transparent;
            transition: background 0.15s, color 0.15s;
            text-decoration: none;
        }
        .pagination-wrap .page-link:hover {
            background: #eef2ff;
            color: var(--primary);
        }
        .pagination-wrap .page-item.active .page-link {
            background: var(--primary);
            color: #fff;
            border-radius: 8px;
        }
        .pagination-wrap .page-item.disabled .page-link {
            color: #b0b8c8;
            opacity: 0.6;
            cursor: not-allowed;
        }
        .quick-actions-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
            border: 1px solid #e9edf4;
            padding: 22px 28px;
            margin-top: 28px;
        }
        .quick-actions-card .qa-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--primary);
            margin: 0 0 14px 0;
            letter-spacing: 0.2px;
        }
        .quick-actions-card .qa-title i {
            margin-right: 8px;
            font-size: 18px;
        }
        .qa-btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        .qa-btn-group .btn-qa {
            background: #F21D2F;
            border: none;
            padding: 9px 22px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 13px;
            color: white;
            transition: background 0.2s, transform 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            border: none;
        }
        .qa-btn-group .btn-qa:hover {
            background: #2B3A8C;
            transform: translateY(-1px);
        }
        .alert-custom {
            border-radius: 10px;
            padding: 12px 20px;
            margin-bottom: 20px;
            border: none;
        }
        .alert-custom-success {
            background: #dff0e6;
            color: #0f7b3a;
        }
        .alert-custom-danger {
            background: #f8d7da;
            color: #721c24;
        }
        .alert-custom-warning {
            background: #fff3cd;
            color: #856404;
        }
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            display: block;
        }
        .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .modal-header {
            background: var(--primary);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 20px 25px;
        }
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        .modal-body { padding: 25px; }
        .modal-footer {
            border-top: none;
            padding: 20px 25px;
        }
        .btn-custom {
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px 25px;
            border-radius: 8px;
            transition: background 0.2s;
        }
        .btn-custom:hover {
            background: #1f2d6e;
            color: white;
        }
        .btn-custom-warning {
            background: #ffc107;
            color: #212529;
        }
        .btn-custom-warning:hover {
            background: #e0a800;
            color: #212529;
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(43, 58, 140, 0.25);
        }
        @media (max-width: 768px) {
            .content-wrapper { padding: 18px 16px 30px 16px; }
            .page-header { flex-direction: column; align-items: stretch; gap: 14px; }
            .btn-add-user { justify-content: center; width: 100%; }
            .table-card .table thead th, .table-card .table tbody td { padding: 12px 14px; font-size: 13px; }
            .quick-actions-card { padding: 18px 18px; }
            .qa-btn-group .btn-qa { padding: 8px 16px; font-size: 12px; flex: 1 0 auto; }
            .pagination-wrap .page-link { padding: 6px 11px; font-size: 13px; }
        }
        @media (max-width: 576px) {
            .table-card .table thead th { font-size: 11px; padding: 10px 10px; }
            .table-card .table tbody td { font-size: 12px; padding: 10px 10px; }
            .badge-status { font-size: 10px; padding: 4px 10px; }
            .qa-btn-group { flex-direction: column; }
            .qa-btn-group .btn-qa { justify-content: center; }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo-area">
            <div class="logo-frame">
                <img src="logo.png" alt="Smart Bite Care Logo" class="logo">
            </div>
            <div class="system-name">Smart Bite Care</div>
        </div>
        <nav class="nav-menu">
            <ul>
                <li><a href="BranchAdmin_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
                <li><a class="active" href="BranchAdmin_UserManagement.php"><i class="bi bi-people-fill"></i><span>User Management</span></a></li>
                <li><a href="BranchAdmin_PatientMonitoring.php"><i class="bi bi-heart-pulse-fill"></i><span>Patient Monitoring</span></a></li>
                <li><a href="BranchAdmin_MedicalSupplies.php"><i class="bi bi-box-seam"></i><span>Medical Supplies</span></a></li>
                <li><a href="BranchAdmin_PredictionModule.php"><i class="bi bi-graph-up-arrow"></i><span>Prediction Module</span></a></li>
                <li><a href="BranchAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
                <li><a href="BranchAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
                <li><a href="BranchAdmin_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
                <li><a href="BranchAdmin_Settings.php"><i class="bi bi-gear-fill"></i><span>Settings</span></a></li>
            </ul>
        </nav>
        <div class="logout">
            <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main">
        <div class="topbar">
            <h3>User Management <small><?php echo htmlspecialchars($branch_name); ?></small></h3>
            <div class="profile"><?php echo htmlspecialchars($username); ?> <i class="bi bi-caret-down-fill"></i></div>
        </div>
        <div class="content-wrapper">
            <?php if ($message): ?>
                <div class="alert alert-custom alert-custom-<?php echo $message_type; ?>">
                    <i class="bi <?php echo $message_type === 'success' ? 'bi-check-circle-fill' : ($message_type === 'warning' ? 'bi-exclamation-triangle-fill' : 'bi-x-circle-fill'); ?>"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="page-header">
                <button class="btn btn-add-user" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="bi bi-person-plus"></i> Add User
                </button>
            </div>

            <div class="table-card">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="empty-state">
                                            <i class="bi bi-people"></i>
                                            <p>No users found for this branch.</p>
                                            <button class="btn btn-add-user" data-bs-toggle="modal" data-bs-target="#addUserModal">
                                                <i class="bi bi-person-plus"></i> Add your first user
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?php echo $user['user_id']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td><?php echo htmlspecialchars($user['role_name']); ?></td>
                                        <td>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                <input type="hidden" name="status" value="<?php echo $user['status']; ?>">
                                                <button type="submit" class="badge-status <?php echo $user['status'] === 'Active' ? 'badge-active' : 'badge-inactive'; ?>">
                                                    <?php echo $user['status']; ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td>
                                            <i class="bi bi-pencil-square action-icon" 
                                               data-bs-toggle="modal" 
                                               data-bs-target="#editUserModal"
                                               data-user-id="<?php echo $user['user_id']; ?>"
                                               data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                               data-email="<?php echo htmlspecialchars($user['email']); ?>"
                                               data-role-id="<?php echo $user['role_id']; ?>"
                                               data-status="<?php echo $user['status']; ?>"></i>
                                            <?php if ($user['user_id'] != $user_id && $user['status'] === 'Active'): ?>
                                                <i class="bi bi-archive-fill action-icon archive" 
                                                   data-bs-toggle="modal" 
                                                   data-bs-target="#archiveUserModal"
                                                   data-user-id="<?php echo $user['user_id']; ?>"
                                                   data-username="<?php echo htmlspecialchars($user['username']); ?>"></i>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination-wrap">
                    <nav>
                        <ul class="pagination">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>"><i class="bi bi-chevron-left"></i></a>
                            </li>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>"><i class="bi bi-chevron-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>

            <div class="quick-actions-card">
                <div class="qa-title"><i class="bi bi-lightning-fill"></i> Quick Actions</div>
                <div class="qa-btn-group">
                    <button class="btn-qa" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="bi bi-person-plus"></i> Add Nurse</button>
                    <button class="btn-qa" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="bi bi-person-plus"></i> Add Admin Staff</button>
                    <button class="btn-qa" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="bi bi-person-plus"></i> Add Inventory Officer</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add New User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_user">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" name="email" required>
                            <small class="text-muted">A temporary password will be sent to this email</small>
                        </div>
                        <div class="mb-3">
                            <label for="role_id" class="form-label">Role <span class="text-danger">*</span></label>
                            <select class="form-select" id="role_id" name="role_id" required>
                                <option value="">Select Role</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['role_id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            The user will receive an email with their login credentials and a link to set their password.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-custom">Add User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_user">
                        <input type="hidden" name="user_id" id="edit_user_id">
                        <div class="mb-3">
                            <label for="edit_username" class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_email" class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="edit_email" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_role_id" class="form-label">Role <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_role_id" name="role_id" required>
                                <option value="">Select Role</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['role_id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_status" class="form-label">Status</label>
                            <select class="form-select" id="edit_status" name="status">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password">
                            <small class="text-muted">Leave blank to keep current password</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-custom">Update User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Archive User Modal -->
    <div class="modal fade" id="archiveUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header" style="background: #ffc107; color: #212529;">
                        <h5 class="modal-title"><i class="bi bi-archive-fill me-2"></i>Archive User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="archive_user">
                        <input type="hidden" name="user_id" id="archive_user_id">
                        <p>Are you sure you want to archive user <strong id="archive_username"></strong>?</p>
                        <p class="text-warning"><small>This will set the user status to Inactive. The user will no longer be able to log in.</small></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-custom-warning">Archive User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Edit User Modal
            const editModal = document.getElementById('editUserModal');
            editModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const userId = button.getAttribute('data-user-id');
                const username = button.getAttribute('data-username');
                const email = button.getAttribute('data-email');
                const roleId = button.getAttribute('data-role-id');
                const status = button.getAttribute('data-status');
                
                document.getElementById('edit_user_id').value = userId;
                document.getElementById('edit_username').value = username;
                document.getElementById('edit_email').value = email;
                document.getElementById('edit_role_id').value = roleId;
                document.getElementById('edit_status').value = status;
            });

            // Archive User Modal
            const archiveModal = document.getElementById('archiveUserModal');
            archiveModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                document.getElementById('archive_user_id').value = button.getAttribute('data-user-id');
                document.getElementById('archive_username').textContent = button.getAttribute('data-username');
            });

            // Auto-dismiss alerts
            const alerts = document.querySelectorAll('.alert-custom');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(function() { alert.style.display = 'none'; }, 500);
                }, 5000);
            });
        });
    </script>
</body>
</html>