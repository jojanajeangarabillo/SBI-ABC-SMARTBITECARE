<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/mailer.php';

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 1
) {
    header("Location: login.php");
    exit();
}

// ========== AUDIT LOG FUNCTION ==========
function addAuditLog($conn, $user_id, $action, $module = 'Branch Admin Management') {
    // Get user's branch_id
    $branch_id = null;
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
    
    // Insert audit log
    $log_sql = "INSERT INTO audit_logs (user_id, branch_id, action, module) VALUES (?, ?, ?, ?)";
    $log_stmt = $conn->prepare($log_sql);
    if ($log_stmt) {
        $log_stmt->bind_param("isss", $user_id, $branch_id, $action, $module);
        $result = $log_stmt->execute();
        $log_stmt->close();
        return $result;
    }
    return false;
}

// Handle Add Admin
$add_error = '';
$add_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_admin') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $branch_id = trim($_POST['branch_id']);
    $role_id = 2; // Branch Admin role
    
    // Validate inputs
    if (empty($username) || empty($email) || empty($branch_id)) {
        $add_error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $add_error = 'Invalid email address.';
    } else {
        // Check if username or email already exists
        $check_sql = "SELECT user_id FROM users WHERE username = ? OR email = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ss", $username, $email);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $add_error = 'Username or email already exists.';
            // Log failed attempt
            addAuditLog($conn, $_SESSION['user_id'], "Failed to create branch admin - Username or email already exists: $username, $email");
        } else {
            // Generate temporary password
            $temp_password = bin2hex(random_bytes(6)); // 12 characters
            $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
            
            // Insert user
            $insert_sql = "INSERT INTO users (branch_id, role_id, username, email, password, status) 
                          VALUES (?, ?, ?, ?, ?, 'Active')";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("sisss", $branch_id, $role_id, $username, $email, $hashed_password);
            
            if ($insert_stmt->execute()) {
                $user_id = $conn->insert_id;
                
                // Log: Admin created
                $action_detail = "Created new branch admin: $username (ID: $user_id) for branch ID: $branch_id";
                addAuditLog($conn, $_SESSION['user_id'], $action_detail);
                
                // Generate password reset token (used for first-time login)
                $token = bin2hex(random_bytes(32));
                $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
                
                $token_sql = "INSERT INTO user_tokens (user_id, token, token_type, expires_at) 
                              VALUES (?, ?, 'password_reset', ?)";
                $token_stmt = $conn->prepare($token_sql);
                $token_stmt->bind_param("iss", $user_id, $token, $expires_at);
                $token_stmt->execute();
                
                // Determine protocol (HTTP or HTTPS)
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                // Use localhost or domain
                $host = $_SERVER['HTTP_HOST'];
                
                // Send email with credentials
                $reset_link = $protocol . "://" . $host . "/SBI-ABC-SMARTBITECARE/change_password.php?token=" . $token . "&email=" . urlencode($email);
                
                $email_body = "
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #2B3A8C; color: white; padding: 20px; text-align: center; }
                        .content { padding: 20px; background: #f9faff; }
                        .credentials { background: #ECEEF7; padding: 15px; border-radius: 8px; margin: 20px 0; }
                        .button { display: inline-block; background: #2B3A8C; color: white; padding: 12px 30px; text-decoration: none; border-radius: 40px; }
                        .footer { margin-top: 20px; font-size: 12px; color: #888; text-align: center; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h2>Welcome to Smart Bite Care</h2>
                        </div>
                        <div class='content'>
                            <h3>Hello, " . htmlspecialchars($username) . "!</h3>
                            <p>Your Branch Admin account has been created. Please use the credentials below to log in.</p>
                            
                            <div class='credentials'>
                                <p><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                                <p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>
                                <p><strong>Temporary Password:</strong> " . htmlspecialchars($temp_password) . "</p>
                            </div>
                            
                            <p><strong>Important:</strong> This temporary password will expire in 24 hours. You must change it upon your first login.</p>
                            
                            <p style='text-align: center; margin-top: 30px;'>
                                <a href='" . $reset_link . "' class='button'>Set Your Password</a>
                            </p>
                            
                            <p><small>If the button doesn't work, copy and paste this link into your browser:</small></p>
                            <p><small>" . $reset_link . "</small></p>
                            
                            <p style='margin-top: 20px;'>
                                <strong>Branch:</strong> " . getBranchName($conn, $branch_id) . "<br>
                            </p>
                        </div>
                        <div class='footer'>
                            <p>This is an automated message from Smart Bite Care System.</p>
                            <p>&copy; 2026 Smart Bite Care. All rights reserved.</p>
                        </div>
                    </div>
                </body>
                </html>
                ";
                
                if (send_email($email, 'Welcome to Smart Bite Care - Your Branch Admin Account', $email_body)) {
                    $add_success = 'Branch Admin created successfully! An email with credentials has been sent to ' . htmlspecialchars($email) . '.';
                    // Log: Email sent successfully
                    addAuditLog($conn, $_SESSION['user_id'], "Welcome email sent to new branch admin: $username (ID: $user_id, Email: $email)");
                } else {
                    $add_error = 'Account created but failed to send email. Please reset password manually.';
                    // Log: Email failed
                    addAuditLog($conn, $_SESSION['user_id'], "Failed to send welcome email to new branch admin: $username (ID: $user_id, Email: $email)");
                }
            } else {
                $add_error = 'Failed to create admin account. Please try again.';
                // Log: Creation failed
                addAuditLog($conn, $_SESSION['user_id'], "Failed to create branch admin: $username - Database error: " . $conn->error);
            }
        }
    }
}

// Handle Archive (Deactivate) Admin
if (isset($_GET['archive_id'])) {
    $archive_id = intval($_GET['archive_id']);
    
    // Get admin details before archiving
    $admin_sql = "SELECT username, email, branch_id FROM users WHERE user_id = ? AND role_id = 2";
    $admin_stmt = $conn->prepare($admin_sql);
    $admin_stmt->bind_param("i", $archive_id);
    $admin_stmt->execute();
    $admin_result = $admin_stmt->get_result();
    $admin_details = $admin_result->fetch_assoc();
    $admin_stmt->close();
    
    if ($admin_details) {
        // Check if user exists and is branch admin
        $check_sql = "SELECT user_id, role_id FROM users WHERE user_id = ? AND role_id = 2";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $archive_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            // Soft delete - update status to Inactive
            $archive_sql = "UPDATE users SET status = 'Inactive' WHERE user_id = ?";
            $archive_stmt = $conn->prepare($archive_sql);
            $archive_stmt->bind_param("i", $archive_id);
            
            if ($archive_stmt->execute()) {
                // Delete associated tokens
                $token_delete_sql = "DELETE FROM user_tokens WHERE user_id = ?";
                $token_delete_stmt = $conn->prepare($token_delete_sql);
                $token_delete_stmt->bind_param("i", $archive_id);
                $token_delete_stmt->execute();
                
                // Log: Admin archived
                $action_detail = "Archived (deactivated) branch admin: " . $admin_details['username'] . 
                                " (ID: $archive_id, Email: " . $admin_details['email'] . 
                                ", Branch ID: " . $admin_details['branch_id'] . ")";
                addAuditLog($conn, $_SESSION['user_id'], $action_detail);
                
                $_SESSION['success'] = 'Admin has been archived successfully.';
            } else {
                $_SESSION['error'] = 'Failed to archive admin.';
                addAuditLog($conn, $_SESSION['user_id'], "Failed to archive branch admin: " . $admin_details['username'] . " (ID: $archive_id) - Database error");
            }
            $archive_stmt->close();
        } else {
            $_SESSION['error'] = 'Admin not found.';
            addAuditLog($conn, $_SESSION['user_id'], "Failed to archive branch admin - User ID: $archive_id not found");
        }
    } else {
        $_SESSION['error'] = 'Admin not found.';
        addAuditLog($conn, $_SESSION['user_id'], "Failed to archive branch admin - User ID: $archive_id not found");
    }
    
    header('Location: SuperAdmin_BranchAdminManagement.php');
    exit();
}

// Handle Send Email (with custom message)
if (isset($_POST['send_email']) && isset($_POST['user_id'])) {
    $user_id = intval($_POST['user_id']);
    $subject = trim($_POST['email_subject']);
    $message = trim($_POST['email_message']);
    
    // Get user details
    $user_sql = "SELECT username, email FROM users WHERE user_id = ? AND role_id = 2";
    $user_stmt = $conn->prepare($user_sql);
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    
    if ($user_row = $user_result->fetch_assoc()) {
        if (empty($subject) || empty($message)) {
            $_SESSION['error'] = 'Please fill in both subject and message.';
            // Log: Email attempt with missing fields
            addAuditLog($conn, $_SESSION['user_id'], "Failed to send email to branch admin: " . $user_row['username'] . " - Missing subject or message");
        } else {
            // Send email
            $email_body = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #2B3A8C; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; background: #f9faff; }
                    .footer { margin-top: 20px; font-size: 12px; color: #888; text-align: center; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Smart Bite Care</h2>
                    </div>
                    <div class='content'>
                        <h3>Hello, " . htmlspecialchars($user_row['username']) . "!</h3>
                        <p>" . nl2br(htmlspecialchars($message)) . "</p>
                    </div>
                    <div class='footer'>
                        <p>This is an automated message from Smart Bite Care System.</p>
                        <p>&copy; 2026 Smart Bite Care. All rights reserved.</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            if (send_email($user_row['email'], $subject, $email_body)) {
                $_SESSION['success'] = 'Email sent successfully to ' . htmlspecialchars($user_row['email']) . '.';
                // Log: Email sent
                $action_detail = "Sent custom email to branch admin: " . $user_row['username'] . 
                                " (ID: $user_id, Email: " . $user_row['email'] . 
                                ") - Subject: " . $subject;
                addAuditLog($conn, $_SESSION['user_id'], $action_detail);
            } else {
                $_SESSION['error'] = 'Failed to send email. Please try again.';
                // Log: Email failed
                addAuditLog($conn, $_SESSION['user_id'], "Failed to send custom email to branch admin: " . $user_row['username'] . " (ID: $user_id)");
            }
        }
    } else {
        $_SESSION['error'] = 'Admin not found.';
        addAuditLog($conn, $_SESSION['user_id'], "Failed to send email - Branch admin not found (ID: $user_id)");
    }
    
    header('Location: SuperAdmin_BranchAdminManagement.php');
    exit();
}

// Get all branch admins
$admins = [];
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$sql = "SELECT u.user_id, u.username, u.email, u.status, u.created_at, 
        b.branch_id, b.branch_name 
        FROM users u 
        LEFT JOIN branches b ON u.branch_id = b.branch_id 
        WHERE u.role_id = 2";

if ($search) {
    $sql .= " AND (u.username LIKE ? OR u.email LIKE ? OR b.branch_name LIKE ?)";
}

$sql .= " ORDER BY u.created_at DESC";

$stmt = $conn->prepare($sql);

if ($search) {
    $search_param = "%$search%";
    $stmt->bind_param("sss", $search_param, $search_param, $search_param);
}

$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $admins[] = $row;
}

// Function to get branch name
function getBranchName($conn, $branch_id) {
    if (!$branch_id) return 'N/A';
    $sql = "SELECT branch_name FROM branches WHERE branch_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $branch_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['branch_name'];
    }
    return 'N/A';
}

// Get all branches for dropdown
$branches = [];
$branch_sql = "SELECT branch_id, branch_name FROM branches WHERE status = 'Active' ORDER BY branch_name";
$branch_result = $conn->query($branch_sql);
while ($row = $branch_result->fetch_assoc()) {
    $branches[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Super Admin - Branch Admin Management</title>
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <!-- Reusable Sidebar CSS (simulated) -->
    <link rel="stylesheet" href="sidebar.css" />
    <style>
        /* =========================================
           INTERNAL CSS – matches image style
           ========================================= */
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
            --bg: #F2F2F2;
            --card-bg: #ECEEF7;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background: white;
            font-family: 'Segoe UI', Roboto, system-ui, sans-serif;
            margin: 0;
            padding: 0;
        }


        /* ---- main content ---- */
        .main {
            margin-left: 260px;
            min-height: 100vh;
            background: #f9faff;
        }

        .topbar {
            background: white;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 35px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border-bottom: 1px solid #e9edf5;
        }
        .topbar h3 {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
            letter-spacing: -0.3px;
        }
        .profile {
            font-weight: 600;
            color: var(--primary);
            cursor: default;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .content {
            padding: 35px 35px 40px;
        }

        /* ---- page header ---- */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 28px;
        }
        .page-header h2 {
            font-size: 26px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
        }
        .page-header .badge-role {
            background: var(--primary);
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            padding: 6px 16px;
            border-radius: 30px;
            letter-spacing: 0.3px;
            margin-left: 12px;
        }

        /* ---- search + add ---- */
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: center;
            margin-bottom: 24px;
        }
        .search-wrap {
            position: relative;
            flex: 1 1 280px;
        }
        .search-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #7a85a8;
            font-size: 18px;
        }
        .search-wrap input {
            width: 100%;
            padding: 12px 12px 12px 44px;
            border: 1px solid #d0d7e8;
            border-radius: 40px;
            font-size: 15px;
            background: white;
            outline: none;
            transition: 0.15s;
        }
        .search-wrap input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.15);
        }
        .btn-add {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 12px 28px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            transition: 0.15s;
            cursor: pointer;
        }
        .btn-add:hover {
            background: #1d2863;
            color: #fff;
        }

        /* ---- table ---- */
        .table-wrap {
            background: white;
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            padding: 6px 0 6px 0;
        }
        .table {
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }
        .table thead th {
            background: #f0f3fc;
            color: var(--primary);
            font-weight: 700;
            font-size: 15px;
            padding: 16px 20px;
            border-bottom: 1px solid #e2e7f2;
            letter-spacing: 0.3px;
        }
        .table tbody td {
            padding: 16px 20px;
            vertical-align: middle;
            border-bottom: 1px solid #edf1f8;
            color: #1f2a4a;
            font-weight: 500;
        }
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        .status-badge {
            display: inline-block;
            background: #d4f0d4;
            color: #1a6e1a;
            font-weight: 600;
            font-size: 13px;
            padding: 4px 16px;
            border-radius: 40px;
            letter-spacing: 0.2px;
        }
        .status-badge.inactive {
            background: #f8d7da;
            color: #721c24;
        }
        .action-icons i {
            font-size: 20px;
            color: var(--primary);
            margin-right: 10px;
            cursor: pointer;
            opacity: 0.7;
            transition: 0.1s;
        }
        .action-icons i:hover {
            opacity: 1;
        }
        .action-icons i:last-child {
            margin-right: 0;
        }
        .action-icons .text-danger {
            color: #dc3545 !important;
        }
        .action-icons .text-success {
            color: #28a745 !important;
        }

        /* Modal Styles */
        .modal-content {
            border-radius: 18px;
            border: none;
        }
        .modal-header {
            background: var(--primary);
            color: white;
            border-radius: 18px 18px 0 0;
            padding: 20px 25px;
        }
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        .modal-body {
            padding: 25px;
        }
        .modal-footer {
            padding: 15px 25px;
            border-top: 1px solid #edf1f8;
        }
        .form-label {
            font-weight: 600;
            color: var(--primary);
        }
        .form-control, .form-select {
            border-radius: 12px;
            border: 1px solid #d0d7e8;
            padding: 12px 16px;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.12);
        }

        /* ---- pagination ---- */
        .pagination-wrap {
            display: flex;
            justify-content: flex-end;
            padding-top: 24px;
            align-items: center;
            gap: 6px;
        }
        .pagination-wrap .page-item .page-link {
            color: var(--primary);
            border: 1px solid #d7def0;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 600;
            background: white;
            margin: 0 2px;
            transition: 0.1s;
        }
        .pagination-wrap .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        .pagination-wrap .page-item .page-link:hover {
            background: #e7ecfc;
            border-color: var(--primary);
        }
        .pagination-wrap .page-item.disabled .page-link {
            color: #b0bcd6;
            background: #f5f7fc;
        }

        /* responsive */
        @media (max-width: 991px) {
            .main {
                margin-left: 90px;
            }
            .sidebar {
                width: 90px;
                padding: 16px 10px;
            }
            .system-name,
            .nav-menu span,
            .logout span {
                display: none;
            }
            .logo-area {
                justify-content: center;
            }
            .nav-menu a {
                justify-content: center;
                padding: 12px 8px;
            }
            .nav-menu a i {
                font-size: 26px;
                margin: 0;
            }
            .logout a {
                justify-content: center;
            }
        }

        @media (max-width: 576px) {
            .topbar {
                padding: 0 16px;
                height: 70px;
            }
            .content {
                padding: 20px 16px;
            }
            .page-header h2 {
                font-size: 22px;
            }
            .table-wrap {
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>

<!-- ========== SIDEBAR (Super Admin) ========== -->
<div class="sidebar">
    <div class="logo-area">
        <div class="logo-frame">
            <img src="logo.png" alt="Smart Bite Care Logo" class="logo" />
        </div>
        <div class="system-name">Smart Bite Care</div>
    </div>

    <nav class="nav-menu">
        <ul>
            <li><a href="SuperAdmin_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="SuperAdmin_BranchManagement.php"><i class="bi bi-people-fill"></i><span>Branch Management</span></a></li>
            <li><a class="active" href="SuperAdmin_BranchAdminManagement.php"><i class="bi bi-heart-pulse-fill"></i><span>Branch Admin Management</span></a></li>
            <li><a href="SuperAdmin_UserMonitoring.php"><i class="bi bi-box-seam"></i><span>User Monitoring</span></a></li>
            <li><a href="SuperAdmin_BranchPerformanceMonitoring.php"><i class="bi bi-graph-up-arrow"></i><span>Branch Performance Monitoring</span></a></li>
            <li><a href="SuperAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
            <li><a href="SuperAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
            <li><a href="SuperAdmin_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
        </ul>
    </nav>

    <div class="logout">
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </div>
</div>

<!-- ========== MAIN CONTENT ========== -->
<div class="main">

    <!-- TOP BAR -->
    <div class="topbar">
        <h3>Branch Admin Management</h3>
        <div class="profile">SUPER ADMIN <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="content">
        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php echo htmlspecialchars($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-circle-fill me-2"></i>
                <?php echo htmlspecialchars($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <?php if ($add_error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-circle-fill me-2"></i>
                <?php echo htmlspecialchars($add_error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($add_success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php echo htmlspecialchars($add_success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- toolbar: search + add button -->
        <div class="toolbar">
            <form method="GET" action="SuperAdmin_BranchAdminManagement.php" class="search-wrap" style="flex: 1 1 280px; margin: 0;">
                <i class="bi bi-search"></i>
                <input type="text" name="search" placeholder="Search admin..." value="<?php echo htmlspecialchars($search); ?>" />
            </form>
            <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addAdminModal">
                <i class="bi bi-plus-circle"></i> Add Branch Admin
            </button>
        </div>

        <!-- table -->
        <div class="table-wrap">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Branch</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($admins) > 0): ?>
                        <?php foreach ($admins as $admin): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($admin['user_id']); ?></strong></td>
                                <td><?php echo htmlspecialchars($admin['username']); ?></td>
                                <td><?php echo htmlspecialchars($admin['email']); ?></td>
                                <td><?php echo htmlspecialchars($admin['branch_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $admin['status'] === 'Inactive' ? 'inactive' : ''; ?>">
                                        <?php echo htmlspecialchars($admin['status']); ?>
                                    </span>
                                </td>
                                <td class="action-icons">
                                    <i class="bi bi-envelope text-success" title="Send Email" 
                                       onclick="openEmailModal(<?php echo htmlspecialchars($admin['user_id']); ?>, '<?php echo htmlspecialchars($admin['username']); ?>', '<?php echo htmlspecialchars($admin['email']); ?>')"></i>
                                    <?php if ($admin['status'] !== 'Inactive'): ?>
                                        <i class="bi bi-archive text-danger" title="Archive Admin" 
                                           onclick="archiveAdmin(<?php echo $admin['user_id']; ?>, '<?php echo htmlspecialchars($admin['username']); ?>')"></i>
                                    <?php else: ?>
                                        <i class="bi bi-archive" style="opacity: 0.3; cursor: not-allowed;" title="Admin already archived"></i>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-4">
                                <i class="bi bi-inbox fs-1 d-block mb-2" style="color: #ccc;"></i>
                                No branch admins found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- pagination: < 1 2 3 4 5 > -->
        <div class="pagination-wrap">
            <nav aria-label="Branch admin pagination">
                <ul class="pagination">
                    <li class="page-item disabled">
                        <a class="page-link" href="#" tabindex="-1" aria-disabled="true">&lt;</a>
                    </li>
                    <li class="page-item active"><a class="page-link" href="#">1</a></li>
                    <li class="page-item"><a class="page-link" href="#">2</a></li>
                    <li class="page-item"><a class="page-link" href="#">3</a></li>
                    <li class="page-item"><a class="page-link" href="#">4</a></li>
                    <li class="page-item"><a class="page-link" href="#">5</a></li>
                    <li class="page-item">
                        <a class="page-link" href="#">&gt;</a>
                    </li>
                </ul>
            </nav>
        </div>

    </div> <!-- /content -->
</div> <!-- /main -->

<!-- Add Admin Modal -->
<div class="modal fade" id="addAdminModal" tabindex="-1" aria-labelledby="addAdminModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addAdminModalLabel">
                    <i class="bi bi-person-plus me-2"></i> Add Branch Admin
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="SuperAdmin_BranchAdminManagement.php" id="addAdminForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_admin" />
                    
                    <div class="mb-3">
                        <label for="username" class="form-label">Username *</label>
                        <input type="text" class="form-control" id="username" name="username" required 
                               placeholder="Enter username" />
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address *</label>
                        <input type="email" class="form-control" id="email" name="email" required 
                               placeholder="Enter email address" />
                    </div>
                    
                    <div class="mb-3">
                        <label for="branch_id" class="form-label">Branch *</label>
                        <select class="form-select" id="branch_id" name="branch_id" required>
                            <option value="">Select Branch</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo htmlspecialchars($branch['branch_id']); ?>">
                                    <?php echo htmlspecialchars($branch['branch_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <small>
                            The new admin will receive an email with their username and a temporary password.
                            They will be required to change their password upon first login.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i> Create Admin
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Email Modal -->
<div class="modal fade" id="emailModal" tabindex="-1" aria-labelledby="emailModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="emailModalLabel">
                    <i class="bi bi-envelope me-2"></i> Send Email to Admin
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="SuperAdmin_BranchAdminManagement.php" id="emailForm">
                <div class="modal-body">
                    <input type="hidden" name="send_email" value="1" />
                    <input type="hidden" name="user_id" id="email_user_id" />
                    
                    <div class="mb-3">
                        <label class="form-label">Recipient</label>
                        <p class="form-control-static" id="email_recipient" style="padding: 10px; background: #f0f3fc; border-radius: 8px;"></p>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email_subject" class="form-label">Subject *</label>
                        <input type="text" class="form-control" id="email_subject" name="email_subject" required 
                               placeholder="Enter email subject" />
                    </div>
                    
                    <div class="mb-3">
                        <label for="email_message" class="form-label">Message *</label>
                        <textarea class="form-control" id="email_message" name="email_message" rows="6" required 
                               placeholder="Type your message here..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <small>
                            This email will be sent to the admin's registered email address.
                            You can use this to send announcements, instructions, or any important information.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i> Send Email
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Archive Confirmation Modal -->
<div class="modal fade" id="archiveConfirmModal" tabindex="-1" aria-labelledby="archiveConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background: #dc3545;">
                <h5 class="modal-title" id="archiveConfirmModalLabel" style="color: white;">
                    <i class="bi bi-archive me-2"></i> Confirm Archive
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="filter: brightness(0) invert(1);"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to archive <strong id="archiveAdminName"></strong>?</p>
                <p class="text-muted"><small>This admin will be deactivated and will no longer be able to access the system.</small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="archiveConfirmLink" class="btn btn-danger">
                    <i class="bi bi-archive me-1"></i> Archive Admin
                </a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Open Email Modal
function openEmailModal(userId, username, email) {
    document.getElementById('email_user_id').value = userId;
    document.getElementById('email_recipient').innerHTML = '<strong>' + username + '</strong> (' + email + ')';
    document.getElementById('email_subject').value = '';
    document.getElementById('email_message').value = '';
    var modal = new bootstrap.Modal(document.getElementById('emailModal'));
    modal.show();
}

// Archive admin confirmation
function archiveAdmin(userId, username) {
    document.getElementById('archiveAdminName').textContent = username;
    document.getElementById('archiveConfirmLink').href = 'SuperAdmin_BranchAdminManagement.php?archive_id=' + userId;
    var modal = new bootstrap.Modal(document.getElementById('archiveConfirmModal'));
    modal.show();
}

// Auto-submit search on change
document.querySelector('.search-wrap input')?.addEventListener('keyup', function(e) {
    if (e.key === 'Enter') {
        this.closest('form').submit();
    }
});

// Clear search when input is empty (after typing)
document.querySelector('.search-wrap input')?.addEventListener('search', function() {
    if (this.value === '') {
        this.closest('form').submit();
    }
});
</script>
</body>
</html>