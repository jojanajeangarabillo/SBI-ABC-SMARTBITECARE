<?php
// SmartBiteCare database configuration.
// Local XAMPP falls back to localhost/root/no password.
// Railway/production should provide SMARTBITECARE_DB_* environment variables.

function smartbitecareEnv(string $name, ?string $fallback = null): ?string
{
    $value = getenv($name);
    if ($value === false || trim((string)$value) === '') {
        return $fallback;
    }
    return (string)$value;
}

$dbHost = smartbitecareEnv('SMARTBITECARE_DB_HOST', 'localhost');
$dbUser = smartbitecareEnv('SMARTBITECARE_DB_USER', 'root');
$dbPass = smartbitecareEnv('SMARTBITECARE_DB_PASSWORD', '');
$dbName = smartbitecareEnv('SMARTBITECARE_DB_NAME', 'smartbitecare');
$dbPort = (int)smartbitecareEnv('SMARTBITECARE_DB_PORT', '3306');

// Set PHP timezone to Philippines.
date_default_timezone_set('Asia/Manila');

$conn = new mysqli(
    $dbHost,
    $dbUser,
    $dbPass,
    $dbName,
    $dbPort
);

if ($conn->connect_error) {
    error_log('SmartBiteCare database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    die('Unable to connect to the database.');
}

$conn->set_charset('utf8mb4');

// Keep database session dates aligned with the Philippines.
$conn->query("SET time_zone = '+08:00'");

function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

function checkUserRole($allowedRoles = []) {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }

    if (!empty($allowedRoles) && !in_array($_SESSION['role_id'], $allowedRoles)) {
        switch ($_SESSION['role_id']) {
            case 1:
                header('Location: SuperAdmin_Dashboard.php');
                break;
            case 2:
                header('Location: BranchAdmin_Dashboard.php');
                break;
            case 3:
                header('Location: Nurse_Dashboard.php');
                break;
            case 4:
                header('Location: AdminStaff_Dashboard.php');
                break;
            case 5:
                header('Location: InventoryOfficer_Dashboard.php');
                break;
            default:
                header('Location: landing.php');
        }
        exit();
    }
}

function getUserData($conn, $userId) {
    $stmt = $conn->prepare("SELECT u.*, r.role_name, b.branch_name
                            FROM users u
                            LEFT JOIN roles r ON u.role_id = r.role_id
                            LEFT JOIN branches b ON u.branch_id = b.branch_id
                            WHERE u.user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}
?>
