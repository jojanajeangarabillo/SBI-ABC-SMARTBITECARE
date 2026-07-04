<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root'); // Change this to your database username
define('DB_PASS', ''); // Change this to your database password
define('DB_NAME', 'smartbitecare');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to UTF-8
$conn->set_charset("utf8mb4");

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

// Function to check user role
function checkUserRole($allowedRoles = []) {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit();
    }
    
    if (!empty($allowedRoles) && !in_array($_SESSION['role_id'], $allowedRoles)) {
        // Redirect to appropriate dashboard based on role
        switch ($_SESSION['role_id']) {
            case 1:
                header("Location: SuperAdmin_Dashboard.php");
                break;
            case 2:
                header("Location: BranchAdmin_Dashboard.php");
                break;
            case 3:
                header("Location: Nurse_Dashboard.php");
                break;
            case 4:
                header("Location: AdminStaff_Dashboard.php");
                break;
            case 5:
                header("Location: InventoryOfficer_Dashboard.php");
                break;
            default:
                header("Location: dashboard.php");
        }
        exit();
    }
}

// Function to get user data
function getUserData($conn, $userId) {
    $stmt = $conn->prepare("SELECT u.*, r.role_name, b.branch_name 
                            FROM users u 
                            LEFT JOIN roles r ON u.role_id = r.role_id 
                            LEFT JOIN branches b ON u.branch_id = b.branch_id 
                            WHERE u.user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}
?>