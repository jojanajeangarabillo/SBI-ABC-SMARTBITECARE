<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/mailer.php';

// Check if user is logged in and is super admin
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 1
) {
    header("Location: login.php");
    exit();
}

// ========== AUDIT LOG FUNCTION ==========
function addAuditLog($conn, $user_id, $action, $module = 'Branch & Admin Management') {
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

// ========== FUNCTION TO GET BRANCH NAME ==========
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

// ========== BRANCH MANAGEMENT HANDLERS ==========

// Handle Add Branch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_branch'])) {
    $branch_id = $conn->real_escape_string($_POST['branch_id']);
    $branch_name = $conn->real_escape_string($_POST['branch_name']);
    $branch_address = $conn->real_escape_string($_POST['branch_address']);
    $contact_number = $conn->real_escape_string($_POST['contact_number']);
    $email = $conn->real_escape_string($_POST['email']);
    $status = $conn->real_escape_string($_POST['status']);

    $sql = "INSERT INTO branches (branch_id, branch_name, branch_address, contact_number, email, status) 
            VALUES ('$branch_id', '$branch_name', '$branch_address', '$contact_number', '$email', '$status')";

    if ($conn->query($sql) === TRUE) {
        $action_detail = "Added new branch: $branch_name (ID: $branch_id)";
        addAuditLog($conn, $_SESSION['user_id'], $action_detail);
        $_SESSION['success'] = "Branch added successfully!";
    } else {
        $_SESSION['error'] = "Error adding branch: " . $conn->error;
        addAuditLog($conn, $_SESSION['user_id'], "Failed to add branch: $branch_name (ID: $branch_id) - " . $conn->error);
    }
    header("Location: SuperAdmin_CombinedManagement.php?tab=branches");
    exit();
}

// Handle Edit Branch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_branch'])) {
    $branch_id = $conn->real_escape_string($_POST['edit_branch_id']);
    $branch_name = $conn->real_escape_string($_POST['edit_branch_name']);
    $branch_address = $conn->real_escape_string($_POST['edit_branch_address']);
    $contact_number = $conn->real_escape_string($_POST['edit_contact_number']);
    $email = $conn->real_escape_string($_POST['edit_email']);
    $status = $conn->real_escape_string($_POST['edit_status']);

    // Get old data for audit log
    $old_sql = "SELECT branch_name, branch_address, contact_number, email, status FROM branches WHERE branch_id = '$branch_id'";
    $old_result = $conn->query($old_sql);
    $old_data = $old_result->fetch_assoc();

    $sql = "UPDATE branches SET 
            branch_name = '$branch_name',
            branch_address = '$branch_address',
            contact_number = '$contact_number',
            email = '$email',
            status = '$status'
            WHERE branch_id = '$branch_id'";

    if ($conn->query($sql) === TRUE) {
        $changes = [];
        if ($old_data['branch_name'] != $branch_name) {
            $changes[] = "Name: '{$old_data['branch_name']}' → '$branch_name'";
        }
        if ($old_data['branch_address'] != $branch_address) {
            $changes[] = "Address: '{$old_data['branch_address']}' → '$branch_address'";
        }
        if ($old_data['contact_number'] != $contact_number) {
            $changes[] = "Contact: '{$old_data['contact_number']}' → '$contact_number'";
        }
        if ($old_data['email'] != $email) {
            $changes[] = "Email: '{$old_data['email']}' → '$email'";
        }
        if ($old_data['status'] != $status) {
            $changes[] = "Status: '{$old_data['status']}' → '$status'";
        }

        if (!empty($changes)) {
            $action_detail = "Updated branch: $branch_id ($branch_name) - Changes: " . implode(", ", $changes);
        } else {
            $action_detail = "Updated branch: $branch_id ($branch_name) - No changes made";
        }
        addAuditLog($conn, $_SESSION['user_id'], $action_detail);
        $_SESSION['success'] = "Branch updated successfully!";
    } else {
        $_SESSION['error'] = "Error updating branch: " . $conn->error;
        addAuditLog($conn, $_SESSION['user_id'], "Failed to update branch: $branch_id - " . $conn->error);
    }
    header("Location: SuperAdmin_CombinedManagement.php?tab=branches");
    exit();
}

// Handle Archive Branch (set status to Inactive)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_branch'])) {
    $branch_id = $conn->real_escape_string($_POST['archive_branch_id']);
    
    $name_sql = "SELECT branch_name FROM branches WHERE branch_id = '$branch_id'";
    $name_result = $conn->query($name_sql);
    $branch_data = $name_result->fetch_assoc();
    $branch_name = $branch_data['branch_name'] ?? $branch_id;
    
    $sql = "UPDATE branches SET status = 'Inactive' WHERE branch_id = '$branch_id'";
    
    if ($conn->query($sql) === TRUE) {
        $action_detail = "Archived branch: $branch_name (ID: $branch_id)";
        addAuditLog($conn, $_SESSION['user_id'], $action_detail);
        $_SESSION['success'] = "Branch archived successfully!";
    } else {
        $_SESSION['error'] = "Error archiving branch: " . $conn->error;
        addAuditLog($conn, $_SESSION['user_id'], "Failed to archive branch: $branch_id - " . $conn->error);
    }
    header("Location: SuperAdmin_CombinedManagement.php?tab=branches");
    exit();
}

// ========== BRANCH ADMIN MANAGEMENT HANDLERS ==========

// Handle Add Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_admin') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $branch_id = trim($_POST['branch_id']);
    $role_id = 2; // Branch Admin role
    
    // Validate inputs
    if (empty($username) || empty($email) || empty($branch_id)) {
        $_SESSION['error'] = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Invalid email address.';
    } else {
        // Check if username or email already exists
        $check_sql = "SELECT user_id FROM users WHERE username = ? OR email = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ss", $username, $email);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $_SESSION['error'] = 'Username or email already exists.';
            addAuditLog($conn, $_SESSION['user_id'], "Failed to create branch admin - Username or email already exists: $username, $email");
        } else {
            // Generate temporary password
            $temp_password = bin2hex(random_bytes(6));
            $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
            
            // Insert user
            $insert_sql = "INSERT INTO users (branch_id, role_id, username, email, password, status) 
                          VALUES (?, ?, ?, ?, ?, 'Active')";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("sisss", $branch_id, $role_id, $username, $email, $hashed_password);
            
            if ($insert_stmt->execute()) {
                $user_id = $conn->insert_id;
                
                $action_detail = "Created new branch admin: $username (ID: $user_id) for branch ID: $branch_id";
                addAuditLog($conn, $_SESSION['user_id'], $action_detail);
                
                // Generate password reset token
                $token = bin2hex(random_bytes(32));
                $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
                
                $token_sql = "INSERT INTO user_tokens (user_id, token, token_type, expires_at) 
                              VALUES (?, ?, 'password_reset', ?)";
                $token_stmt = $conn->prepare($token_sql);
                $token_stmt->bind_param("iss", $user_id, $token, $expires_at);
                $token_stmt->execute();
                
                // ========== FIXED: Email link generation ==========
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                
                // Build the URL properly using http_build_query()
                $reset_link = "http://" . $_SERVER['HTTP_HOST'] . "/change_password.php";
$reset_link .= "?" . http_build_query(['token' => $token, 'email' => $email]);
                // ========== END FIX ==========
                
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
                    $_SESSION['success'] = 'Branch Admin created successfully! An email with credentials has been sent to ' . htmlspecialchars($email) . '.';
                    addAuditLog($conn, $_SESSION['user_id'], "Welcome email sent to new branch admin: $username (ID: $user_id, Email: $email)");
                } else {
                    $_SESSION['error'] = 'Account created but failed to send email. Please reset password manually.';
                    addAuditLog($conn, $_SESSION['user_id'], "Failed to send welcome email to new branch admin: $username (ID: $user_id, Email: $email)");
                }
            } else {
                $_SESSION['error'] = 'Failed to create admin account. Please try again.';
                addAuditLog($conn, $_SESSION['user_id'], "Failed to create branch admin: $username - Database error: " . $conn->error);
            }
        }
    }
    header("Location: SuperAdmin_CombinedManagement.php?tab=admins");
    exit();
}

// Handle Archive (Deactivate) Admin
if (isset($_GET['archive_admin_id'])) {
    $archive_id = intval($_GET['archive_admin_id']);
    
    $admin_sql = "SELECT username, email, branch_id FROM users WHERE user_id = ? AND role_id = 2";
    $admin_stmt = $conn->prepare($admin_sql);
    $admin_stmt->bind_param("i", $archive_id);
    $admin_stmt->execute();
    $admin_result = $admin_stmt->get_result();
    $admin_details = $admin_result->fetch_assoc();
    $admin_stmt->close();
    
    if ($admin_details) {
        $check_sql = "SELECT user_id, role_id FROM users WHERE user_id = ? AND role_id = 2";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $archive_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $archive_sql = "UPDATE users SET status = 'Inactive' WHERE user_id = ?";
            $archive_stmt = $conn->prepare($archive_sql);
            $archive_stmt->bind_param("i", $archive_id);
            
            if ($archive_stmt->execute()) {
                $token_delete_sql = "DELETE FROM user_tokens WHERE user_id = ?";
                $token_delete_stmt = $conn->prepare($token_delete_sql);
                $token_delete_stmt->bind_param("i", $archive_id);
                $token_delete_stmt->execute();
                
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
    
    header('Location: SuperAdmin_CombinedManagement.php?tab=admins');
    exit();
}

// Handle Send Email (with custom message)
if (isset($_POST['send_email']) && isset($_POST['user_id'])) {
    $user_id = intval($_POST['user_id']);
    $subject = trim($_POST['email_subject']);
    $message = trim($_POST['email_message']);
    
    $user_sql = "SELECT username, email FROM users WHERE user_id = ? AND role_id = 2";
    $user_stmt = $conn->prepare($user_sql);
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    
    if ($user_row = $user_result->fetch_assoc()) {
        if (empty($subject) || empty($message)) {
            $_SESSION['error'] = 'Please fill in both subject and message.';
            addAuditLog($conn, $_SESSION['user_id'], "Failed to send email to branch admin: " . $user_row['username'] . " - Missing subject or message");
        } else {
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
                $action_detail = "Sent custom email to branch admin: " . $user_row['username'] . 
                                " (ID: $user_id, Email: " . $user_row['email'] . 
                                ") - Subject: " . $subject;
                addAuditLog($conn, $_SESSION['user_id'], $action_detail);
            } else {
                $_SESSION['error'] = 'Failed to send email. Please try again.';
                addAuditLog($conn, $_SESSION['user_id'], "Failed to send custom email to branch admin: " . $user_row['username'] . " (ID: $user_id)");
            }
        }
    } else {
        $_SESSION['error'] = 'Admin not found.';
        addAuditLog($conn, $_SESSION['user_id'], "Failed to send email - Branch admin not found (ID: $user_id)");
    }
    
    header('Location: SuperAdmin_CombinedManagement.php?tab=admins');
    exit();
}

// ========== FETCH DATA ==========

$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'branches';

// Fetch all branches
$sql = "SELECT * FROM branches ORDER BY branch_id";
$result = $conn->query($sql);

// Get all branch admins
$admins = [];
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$admin_sql = "SELECT u.user_id, u.username, u.email, u.status, u.created_at, 
        b.branch_id, b.branch_name 
        FROM users u 
        LEFT JOIN branches b ON u.branch_id = b.branch_id 
        WHERE u.role_id = 2";

if ($search) {
    $admin_sql .= " AND (u.username LIKE ? OR u.email LIKE ? OR b.branch_name LIKE ?)";
}

$admin_sql .= " ORDER BY u.created_at DESC";

$admin_stmt = $conn->prepare($admin_sql);

if ($search) {
    $search_param = "%$search%";
    $admin_stmt->bind_param("sss", $search_param, $search_param, $search_param);
}

$admin_stmt->execute();
$admin_result = $admin_stmt->get_result();

while ($row = $admin_result->fetch_assoc()) {
    $admins[] = $row;
}

// Get all branches for dropdown
$branches = [];
$branch_sql = "SELECT branch_id, branch_name FROM branches WHERE status = 'Active' ORDER BY branch_name";
$branch_result = $conn->query($branch_sql);
while ($row = $branch_result->fetch_assoc()) {
    $branches[] = $row;
}

// Fetch single branch for view/edit
$view_branch = null;
if (isset($_GET['view_id'])) {
    $view_id = $conn->real_escape_string($_GET['view_id']);
    $view_sql = "SELECT * FROM branches WHERE branch_id = '$view_id'";
    $view_result = $conn->query($view_sql);
    if ($view_result && $view_result->num_rows > 0) {
        $view_branch = $view_result->fetch_assoc();
    }
}

$edit_branch = null;
if (isset($_GET['edit_id'])) {
    $edit_id = $conn->real_escape_string($_GET['edit_id']);
    $edit_sql = "SELECT * FROM branches WHERE branch_id = '$edit_id'";
    $edit_result = $conn->query($edit_sql);
    if ($edit_result && $edit_result->num_rows > 0) {
        $edit_branch = $edit_result->fetch_assoc();
    }
}

$archive_branch = null;
if (isset($_GET['archive_id'])) {
    $archive_id = $conn->real_escape_string($_GET['archive_id']);
    $archive_sql = "SELECT * FROM branches WHERE branch_id = '$archive_id'";
    $archive_result = $conn->query($archive_sql);
    if ($archive_result && $archive_result->num_rows > 0) {
        $archive_branch = $archive_result->fetch_assoc();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Super Admin - Branch & Admin Management</title>
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <!-- Reusable Sidebar CSS (simulated) -->
    <link rel="stylesheet" href="sidebar.css" />
    <style>
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

        /* ===== TABS ===== */
        .nav-tabs-custom {
            border-bottom: 2px solid #e9edf5;
            margin-bottom: 30px;
            display: flex;
            gap: 0;
        }
        .nav-tabs-custom .nav-item {
            margin-bottom: -2px;
        }
        .nav-tabs-custom .nav-link {
            border: none;
            border-bottom: 3px solid transparent;
            color: #6c7a9a;
            font-weight: 600;
            font-size: 16px;
            padding: 12px 24px;
            border-radius: 0;
            background: transparent;
            transition: all 0.2s;
        }
        .nav-tabs-custom .nav-link:hover {
            color: var(--primary);
            border-bottom-color: #c5cee3;
        }
        .nav-tabs-custom .nav-link.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: transparent;
        }
        .nav-tabs-custom .nav-link i {
            margin-right: 8px;
            font-size: 18px;
        }
        .nav-tabs-custom .nav-link .badge-tab {
            background: var(--card-bg);
            color: var(--primary);
            font-size: 12px;
            padding: 2px 10px;
            border-radius: 20px;
            margin-left: 6px;
            font-weight: 600;
        }
        .nav-tabs-custom .nav-link.active .badge-tab {
            background: var(--primary);
            color: white;
        }

        .tab-pane {
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .section-header h4 {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
        }

        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            width: 100%;
            gap: 16px;
            flex-wrap: wrap;
        }
        
        .search-wrap {
            position: relative;
            width: 100%;
            max-width: 400px;
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
            border-radius: 10px;
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
        }
        .btn-add:hover {
            background: #1d2863;
            color: #fff;
        }
        .btn-add-success {
            background: #28a745;
        }
        .btn-add-success:hover {
            background: #1e7e34;
        }

        .table-wrap {
            background: white;
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            padding: 0;
        }
        .table {
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }
        .table thead th {
            background: var(--primary);
            color: white;
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
        .table tbody tr:hover {
            background: #f8f9fe;
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
        .modal-header.bg-danger {
            background: #dc3545 !important;
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
            justify-content: center;
            align-items: center;
            padding-top: 20px;
        }

        .pagination-wrap .pagination {
            display: flex;
            align-items: center;
            gap: 0;
            margin: 0;
        }

        .pagination-wrap .page-item {
            margin: 0;
        }

        .pagination-wrap .page-link {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            margin: 0 3px;
            border: 1px solid #dbe2f0;
            border-radius: 10px !important;
            background: #fff;
            color: var(--primary);
            font-size: 15px;
            font-weight: 500;
        }

        .pagination-wrap .page-item.active .page-link {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
            border-radius: 10px !important;
        }

        .pagination-wrap .page-item.disabled .page-link {
            background: #eef1f6;
            color: #aeb8ca;
            border-color: #dbe2f0;
            border-radius: 10px !important;
        }

        .pagination-wrap .page-item:not(.active):not(.disabled) .page-link:hover {
            background: #f4f6fb;
            color: var(--primary);
        }

        .pagination-wrap .page-link:focus {
            box-shadow: none;
        }

        /* Toast / Alert styling */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }
        .toast-custom {
            background: white;
            border-radius: 12px;
            padding: 16px 24px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            border-left: 6px solid #28a745;
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 320px;
            animation: slideIn 0.4s ease;
        }
        .toast-custom.error {
            border-left-color: #dc3545;
        }
        .toast-custom .toast-icon {
            font-size: 28px;
            color: #28a745;
        }
        .toast-custom.error .toast-icon {
            color: #dc3545;
        }
        .toast-custom .toast-msg {
            font-weight: 500;
            color: #1f2a4a;
            flex: 1;
        }
        .toast-custom .toast-close {
            background: none;
            border: none;
            font-size: 22px;
            color: #999;
            cursor: pointer;
            padding: 0 4px;
        }
        .toast-custom .toast-close:hover {
            color: #333;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(40px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @media (max-width: 991px) {
            .main { margin-left: 90px; }
        }
        @media (max-width: 576px) {
            .topbar { padding: 0 16px; height: 70px; }
            .content { padding: 20px 16px; }
            .nav-tabs-custom .nav-link { padding: 10px 16px; font-size: 14px; }
            .table-wrap { overflow-x: auto; }
            .toolbar { flex-direction: column; align-items: stretch; }
            .search-wrap { max-width: 100%; }
            .btn-add { justify-content: center; }
        }
    </style>
</head>
<body>

<!-- ========== TOAST / ALERT CONTAINER ========== -->
<div class="toast-container" id="toastContainer"></div>

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
            <li><a class="active" href="SuperAdmin_CombinedManagement.php"><i class="bi bi-people-fill"></i><span>Branch Management</span></a></li>
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
        <h3>Branch & Admin Management</h3>
        <div class="profile">
            <?php echo htmlspecialchars($_SESSION['username'] ?? 'SUPER ADMIN'); ?>
        </div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="content">

        <!-- Display session messages -->
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

        <!-- ===== TABS ===== -->
        <ul class="nav nav-tabs-custom" id="managementTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <a class="nav-link <?php echo $active_tab === 'branches' ? 'active' : ''; ?>" 
                   id="branches-tab" data-bs-toggle="tab" data-bs-target="#branches-pane" 
                   type="button" role="tab" aria-controls="branches-pane" 
                   aria-selected="<?php echo $active_tab === 'branches' ? 'true' : 'false'; ?>"
                   href="?tab=branches">
                    <i class="bi bi-buildings"></i> Branches
                    <span class="badge-tab"><?php echo $result ? $result->num_rows : 0; ?></span>
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link <?php echo $active_tab === 'admins' ? 'active' : ''; ?>" 
                   id="admins-tab" data-bs-toggle="tab" data-bs-target="#admins-pane" 
                   type="button" role="tab" aria-controls="admins-pane" 
                   aria-selected="<?php echo $active_tab === 'admins' ? 'true' : 'false'; ?>"
                   href="?tab=admins">
                    <i class="bi bi-person-badge"></i> Branch Admins
                    <span class="badge-tab"><?php echo count($admins); ?></span>
                </a>
            </li>
        </ul>

        <!-- ===== TAB CONTENT ===== -->
        <div class="tab-content">

            <!-- ============================================================ -->
            <!-- BRANCH MANAGEMENT TAB -->
            <!-- ============================================================ -->
            <div class="tab-pane fade <?php echo $active_tab === 'branches' ? 'show active' : ''; ?>" 
                 id="branches-pane" role="tabpanel" aria-labelledby="branches-tab">

                <div class="toolbar">
                    <div class="search-wrap">
                        <i class="bi bi-search"></i>
                        <input type="text" id="searchInput" placeholder="Search branch..." onkeyup="filterTable()" />
                    </div>
                    <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addBranchModal">
                        <i class="bi bi-plus-circle"></i> Add Branch
                    </button>
                </div>

                <!-- Branch Table -->
                <div class="table-wrap">
                    <table class="table table-hover align-middle" id="branchTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Branch Name</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row['branch_id']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['branch_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['branch_address']); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo strtolower($row['status']) === 'inactive' ? 'inactive' : ''; ?>">
                                                <?php echo htmlspecialchars($row['status']); ?>
                                            </span>
                                        </td>
                                        <td class="action-icons">
                                            <i class="bi bi-eye" title="View" onclick="viewBranch('<?php echo $row['branch_id']; ?>')"></i>
                                            <i class="bi bi-pencil-square" title="Edit" onclick="editBranch('<?php echo $row['branch_id']; ?>')"></i>
                                            <i class="bi bi-archive" title="Archive" onclick="archiveBranch('<?php echo $row['branch_id']; ?>')"></i>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4">
                                        <i class="bi bi-inbox fs-1 d-block mb-2" style="color: #ccc;"></i>
                                        No branches found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Branch Pagination -->
                <div class="pagination-wrap">
                    <nav aria-label="Branch pagination">
                        <ul class="pagination">
                            <li class="page-item disabled">
                                <a class="page-link" href="#" aria-label="Previous">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                            <li class="page-item active">
                                <a class="page-link" href="#">1</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="#" aria-label="Next">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- BRANCH ADMIN MANAGEMENT TAB -->
            <!-- ============================================================ -->
            <div class="tab-pane fade <?php echo $active_tab === 'admins' ? 'show active' : ''; ?>" 
                 id="admins-pane" role="tabpanel" aria-labelledby="admins-tab">

                <div class="toolbar">
                    <form method="GET" action="SuperAdmin_CombinedManagement.php" class="search-wrap" style="flex: 1 1 280px; margin: 0;">
                        <input type="hidden" name="tab" value="admins" />
                        <i class="bi bi-search"></i>
                        <input type="text" name="search" placeholder="Search admin..." value="<?php echo htmlspecialchars($search); ?>" />
                    </form>
                    <button class="btn-add btn-add-success" data-bs-toggle="modal" data-bs-target="#addAdminModal">
                        <i class="bi bi-person-plus"></i> Add Branch Admin
                    </button>
                </div>

                <!-- Admin Table -->
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

                <!-- Admin Pagination -->
                <div class="pagination-wrap">
                    <nav aria-label="Branch admin pagination">
                        <ul class="pagination">
                            <li class="page-item disabled">
                                <a class="page-link" href="#" aria-label="Previous">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                            <li class="page-item active">
                                <a class="page-link" href="#">1</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="#" aria-label="Next">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>

        </div><!-- /tab-content -->

    </div> <!-- /content -->
</div> <!-- /main -->

<!-- ============================================================ -->
<!-- MODALS -->
<!-- ============================================================ -->

<!-- ========== ADD BRANCH MODAL ========== -->
<div class="modal fade" id="addBranchModal" tabindex="-1" aria-labelledby="addBranchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addBranchModalLabel"><i class="bi bi-plus-circle"></i> Add New Branch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="SuperAdmin_CombinedManagement.php">
                <input type="hidden" name="tab" value="branches" />
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="branch_id" class="form-label">Branch ID</label>
                            <input type="text" class="form-control" id="branch_id" name="branch_id" required placeholder="e.g. SBI-002">
                        </div>
                        <div class="col-md-6">
                            <label for="branch_name" class="form-label">Branch Name</label>
                            <input type="text" class="form-control" id="branch_name" name="branch_name" required placeholder="e.g. Quezon City Branch">
                        </div>
                        <div class="col-12">
                            <label for="branch_address" class="form-label">Branch Address</label>
                            <textarea class="form-control" id="branch_address" name="branch_address" rows="2" placeholder="Full address"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label for="contact_number" class="form-label">Contact Number</label>
                            <input type="text" class="form-control" id="contact_number" name="contact_number" placeholder="e.g. 09123456789">
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" placeholder="branch@smartbitecare.com">
                        </div>
                        <div class="col-md-6">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="Active" selected>Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_branch" class="btn" style="background: var(--primary); color: white;">Add Branch</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========== VIEW BRANCH MODAL ========== -->
<div class="modal fade" id="viewBranchModal" tabindex="-1" aria-labelledby="viewBranchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewBranchModalLabel"><i class="bi bi-eye"></i> View Branch Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if ($view_branch): ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="fw-bold">Branch ID</label>
                            <p class="form-control-plaintext"><?php echo htmlspecialchars($view_branch['branch_id']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold">Branch Name</label>
                            <p class="form-control-plaintext"><?php echo htmlspecialchars($view_branch['branch_name']); ?></p>
                        </div>
                        <div class="col-12">
                            <label class="fw-bold">Address</label>
                            <p class="form-control-plaintext"><?php echo htmlspecialchars($view_branch['branch_address']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold">Contact Number</label>
                            <p class="form-control-plaintext"><?php echo htmlspecialchars($view_branch['contact_number']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold">Email</label>
                            <p class="form-control-plaintext"><?php echo htmlspecialchars($view_branch['email']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold">Status</label>
                            <p class="form-control-plaintext">
                                <span class="status-badge <?php echo strtolower($view_branch['status']) === 'inactive' ? 'inactive' : ''; ?>">
                                    <?php echo htmlspecialchars($view_branch['status']); ?>
                                </span>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold">Created At</label>
                            <p class="form-control-plaintext"><?php echo htmlspecialchars($view_branch['created_at']); ?></p>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-center">Branch not found.</p>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ========== EDIT BRANCH MODAL ========== -->
<div class="modal fade" id="editBranchModal" tabindex="-1" aria-labelledby="editBranchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editBranchModalLabel"><i class="bi bi-pencil-square"></i> Edit Branch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="SuperAdmin_CombinedManagement.php">
                <input type="hidden" name="tab" value="branches" />
                <div class="modal-body">
                    <?php if ($edit_branch): ?>
                        <input type="hidden" name="edit_branch_id" value="<?php echo htmlspecialchars($edit_branch['branch_id']); ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="edit_branch_name" class="form-label">Branch Name</label>
                                <input type="text" class="form-control" id="edit_branch_name" name="edit_branch_name" value="<?php echo htmlspecialchars($edit_branch['branch_name']); ?>" required>
                            </div>
                            <div class="col-12">
                                <label for="edit_branch_address" class="form-label">Branch Address</label>
                                <textarea class="form-control" id="edit_branch_address" name="edit_branch_address" rows="2"><?php echo htmlspecialchars($edit_branch['branch_address']); ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_contact_number" class="form-label">Contact Number</label>
                                <input type="text" class="form-control" id="edit_contact_number" name="edit_contact_number" value="<?php echo htmlspecialchars($edit_branch['contact_number']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="edit_email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="edit_email" name="edit_email" value="<?php echo htmlspecialchars($edit_branch['email']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="edit_status" class="form-label">Status</label>
                                <select class="form-select" id="edit_status" name="edit_status">
                                    <option value="Active" <?php echo $edit_branch['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="Inactive" <?php echo $edit_branch['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="text-center">Branch not found.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_branch" class="btn" style="background: var(--primary); color: white;">Update Branch</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========== ARCHIVE BRANCH CONFIRMATION MODAL ========== -->
<div class="modal fade" id="archiveBranchModal" tabindex="-1" aria-labelledby="archiveBranchModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger">
                <h5 class="modal-title" id="archiveBranchModalLabel" style="color: white;"><i class="bi bi-exclamation-triangle"></i> Archive Branch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="filter: brightness(0) invert(1);"></button>
            </div>
            <form method="POST" action="SuperAdmin_CombinedManagement.php">
                <input type="hidden" name="tab" value="branches" />
                <div class="modal-body">
                    <?php if ($archive_branch): ?>
                        <input type="hidden" name="archive_branch_id" value="<?php echo htmlspecialchars($archive_branch['branch_id']); ?>">
                        <p class="fs-5">Are you sure you want to archive this branch?</p>
                        <div class="bg-light p-3 rounded">
                            <p class="mb-1"><strong>Branch ID:</strong> <?php echo htmlspecialchars($archive_branch['branch_id']); ?></p>
                            <p class="mb-1"><strong>Branch Name:</strong> <?php echo htmlspecialchars($archive_branch['branch_name']); ?></p>
                            <p class="mb-0"><strong>Status:</strong> 
                                <span class="status-badge <?php echo strtolower($archive_branch['status']) === 'inactive' ? 'inactive' : ''; ?>">
                                    <?php echo htmlspecialchars($archive_branch['status']); ?>
                                </span>
                            </p>
                        </div>
                        <p class="text-danger mt-3"><i class="bi bi-info-circle"></i> This will set the branch status to "Inactive".</p>
                    <?php else: ?>
                        <p class="text-center">Branch not found.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="archive_branch" class="btn btn-danger">Yes, Archive</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========== ADD ADMIN MODAL ========== -->
<div class="modal fade" id="addAdminModal" tabindex="-1" aria-labelledby="addAdminModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addAdminModalLabel">
                    <i class="bi bi-person-plus me-2"></i> Add Branch Admin
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="SuperAdmin_CombinedManagement.php" id="addAdminForm">
                <input type="hidden" name="tab" value="admins" />
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
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle me-1"></i> Create Admin
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========== EMAIL MODAL ========== -->
<div class="modal fade" id="emailModal" tabindex="-1" aria-labelledby="emailModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="emailModalLabel">
                    <i class="bi bi-envelope me-2"></i> Send Email to Admin
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="SuperAdmin_CombinedManagement.php" id="emailForm">
                <input type="hidden" name="tab" value="admins" />
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

<!-- ========== ARCHIVE ADMIN CONFIRMATION MODAL ========== -->
<div class="modal fade" id="archiveConfirmModal" tabindex="-1" aria-labelledby="archiveConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger">
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
    // ========== BRANCH FUNCTIONS ==========
    
    // Filter table rows based on search input
    function filterTable() {
        const input = document.getElementById('searchInput');
        const filter = input.value.toLowerCase();
        const table = document.getElementById('branchTable');
        const rows = table.getElementsByTagName('tr');

        for (let i = 1; i < rows.length; i++) {
            const cells = rows[i].getElementsByTagName('td');
            let found = false;
            for (let j = 0; j < cells.length - 1; j++) {
                const text = cells[j].textContent.toLowerCase();
                if (text.indexOf(filter) > -1) {
                    found = true;
                    break;
                }
            }
            rows[i].style.display = found ? '' : 'none';
        }
    }

    // View Branch - redirect to page with view_id parameter
    function viewBranch(id) {
        window.location.href = 'SuperAdmin_CombinedManagement.php?view_id=' + encodeURIComponent(id) + '&tab=branches';
    }

    // Edit Branch - redirect to page with edit_id parameter
    function editBranch(id) {
        window.location.href = 'SuperAdmin_CombinedManagement.php?edit_id=' + encodeURIComponent(id) + '&tab=branches';
    }

    // Archive Branch - redirect to page with archive_id parameter
    function archiveBranch(id) {
        window.location.href = 'SuperAdmin_CombinedManagement.php?archive_id=' + encodeURIComponent(id) + '&tab=branches';
    }

    // ========== ADMIN FUNCTIONS ==========
    
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
        document.getElementById('archiveConfirmLink').href = 'SuperAdmin_CombinedManagement.php?archive_admin_id=' + userId + '&tab=admins';
        var modal = new bootstrap.Modal(document.getElementById('archiveConfirmModal'));
        modal.show();
    }

    // Auto-submit search on change
    document.querySelector('.search-wrap input[type="text"]')?.addEventListener('keyup', function(e) {
        if (e.key === 'Enter') {
            this.closest('form').submit();
        }
    });

    // Clear search when input is empty (after typing)
    document.querySelector('.search-wrap input[type="text"]')?.addEventListener('search', function() {
        if (this.value === '') {
            this.closest('form').submit();
        }
    });

    // Auto-open modals if parameters are present
    document.addEventListener('DOMContentLoaded', function() {
        // Open view modal
        <?php if (isset($_GET['view_id']) && $view_branch): ?>
            var viewModal = new bootstrap.Modal(document.getElementById('viewBranchModal'));
            viewModal.show();
        <?php endif; ?>

        // Open edit modal
        <?php if (isset($_GET['edit_id']) && $edit_branch): ?>
            var editModal = new bootstrap.Modal(document.getElementById('editBranchModal'));
            editModal.show();
        <?php endif; ?>

        // Open archive modal
        <?php if (isset($_GET['archive_id']) && $archive_branch): ?>
            var archiveModal = new bootstrap.Modal(document.getElementById('archiveBranchModal'));
            archiveModal.show();
        <?php endif; ?>
    });

    // Handle tab switching with URL parameters
    document.addEventListener('DOMContentLoaded', function() {
        const tabs = document.querySelectorAll('#managementTabs .nav-link');
        tabs.forEach(tab => {
            tab.addEventListener('click', function(e) {
                // The href already has the tab parameter
                // Let Bootstrap handle the tab switching
            });
        });
    });
</script>

<?php
// Close connection
$conn->close();
?>
</body>
</html>