<?php
session_start();
require_once 'sources/db_connect.php';

/**
 * Log logout action
 */
function logLogout($conn, $user_id, $username, $role_name = '') {
    // Get IP address
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    
    // Build action description
    $action = "Logout: User '$username'";
    if (!empty($role_name)) {
        $action .= " (Role: $role_name)";
    }
    $action .= " (IP: $ip_address)";
    
    // Get user's branch_id
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
        $log_user_id = $user_id ?? 0;
        $module = 'Login System';
        $log_stmt->bind_param("isss", $log_user_id, $branch_id, $action, $module);
        $log_stmt->execute();
        $log_stmt->close();
        return true;
    }
    return false;
}

// Store user info before destroying session
$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? 'Unknown';
$role_name = $_SESSION['role_name'] ?? '';

// Log the logout action
if ($user_id) {
    logLogout($conn, $user_id, $username, $role_name);
}

// Destroy session
$_SESSION = array();

// If session cookie exists, delete it
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to landing page
header("Location: landing.php");
exit();
?>