<?php
declare(strict_types=1);

function managementDeny(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    exit($message);
}

/** Read current permissions from the database rather than trusting cached roles. */
function managementRequireUser(mysqli $conn, int $role, bool $requireBranch = false, bool $lock = false): array
{
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    $sessionRole = filter_var($_SESSION['role_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId || $userId < 1 || !$sessionRole) {
        header('Location: login.php');
        exit;
    }
    if ($sessionRole !== $role) {
        managementDeny(403, 'Your role cannot access this management page.');
    }
    $stmt = $conn->prepare(
        'SELECT u.user_id, u.role_id, u.status, u.branch_id, u.username, b.branch_name
         FROM users u LEFT JOIN branches b ON b.branch_id = u.branch_id
         WHERE u.user_id = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || $user['status'] !== 'Active' || (int)$user['role_id'] !== $role) {
        // An account disabled or reassigned since login must sign in again.
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }
    if ($requireBranch && (empty($user['branch_id']) || $user['branch_name'] === null)) {
        managementDeny(403, 'Your account has no valid branch assignment.');
    }
    header('Cache-Control: no-store');
    return $user;
}

function managementCsrfToken(): string
{
    $token = $_SESSION['management_csrf_token'] ?? null;
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
        $_SESSION['management_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['management_csrf_token'];
}

function managementVerifyPost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        managementDeny(405, 'Submit changes using the management form.');
    }
    foreach ($_POST as $value) {
        if (!is_string($value)) {
            managementDeny(400, 'Invalid form data. Refresh the page and try again.');
        }
    }
    $token = $_POST['csrf_token'] ?? '';
    if ($token === '' || !hash_equals(managementCsrfToken(), $token)) {
        managementDeny(403, 'Your form session expired. Refresh the page and try again.');
    }
}

/** Call inside the caller's transaction so permission and target checks stay locked. */
function managementBranchTarget(mysqli $conn, array $actor, int $targetId): array
{
    if ($targetId < 1) {
        managementDeny(400, 'Select a valid user.');
    }
    if ($targetId === (int)$actor['user_id']) {
        managementDeny(403, 'Use your account settings to manage your own account.');
    }
    $branchId = (string)$actor['branch_id'];
    $stmt = $conn->prepare(
        'SELECT user_id, role_id, username, status FROM users
         WHERE user_id = ? AND branch_id = ? AND role_id IN (3, 4, 5)
         LIMIT 1 FOR UPDATE'
    );
    $stmt->bind_param('is', $targetId, $branchId);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$target) {
        managementDeny(403, 'You can only manage Nurses, Admin Staff, and Inventory Officers in your branch.');
    }
    return $target;
}

function managementRevokeTokens(mysqli $conn, int $userId): void
{
    $stmt = $conn->prepare('DELETE FROM user_tokens WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}
