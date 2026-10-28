<?php
declare(strict_types=1);

/*
 * LOGIN LOCKOUT AND PASSWORD RULE
 * - 5 failed attempts lock the entered username for 15 minutes
 *   (login_attempts, sources/migrate_login_lockout.php). While locked even
 *   the correct password is refused. A successful login resets the counter.
 * - Unknown usernames are counted and locked the same way and get the same
 *   messages, so the login page does not reveal whether a username exists.
 * - On a lock: audit entry and a notification to the account's Branch Admin
 *   (the Super Admin for Branch Admin and Super Admin accounts).
 * - Branch Admin (own branch, not other admins) and Super Admin can unlock.
 * - New and changed passwords: at least 8 characters with letters and numbers.
 *   Existing passwords keep working (only setting a password is checked).
 */

const LOGIN_MAX_FAILED_ATTEMPTS = 5;
const LOGIN_LOCK_MINUTES = 15;
const LOGIN_LOCKED_MESSAGE = 'Account temporarily locked. Try again later or contact your Branch Admin.';
const LOGIN_INVALID_MESSAGE = 'Invalid username or password.';
const PASSWORD_RULE_TEXT = 'Use at least 8 characters with both letters and numbers.';

/** null when the password meets the rule, otherwise the message to show. */
function passwordPolicyProblem(string $password): ?string
{
    if (strlen($password) > 72) return 'Password must not exceed 72 characters.';
    if (mb_strlen($password) < 8 || !preg_match('/\p{L}/u', $password) || !preg_match('/\d/', $password)) {
        return 'Password must be at least 8 characters long and contain both letters and numbers.';
    }
    return null;
}

/** Random temporary password that always meets the rule (letters and digits). */
function passwordGenerateTemporary(int $length = 12): string
{
    $letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';
    $digits = '23456789';
    $all = $letters . $digits;
    $chars = [$letters[random_int(0, strlen($letters) - 1)], $digits[random_int(0, strlen($digits) - 1)]];
    for ($i = 2; $i < max(8, $length); $i++) $chars[] = $all[random_int(0, strlen($all) - 1)];
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
}

function loginSecurityReady(mysqli $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts'");
        $stmt->execute();
        $ready = (int)$stmt->get_result()->fetch_assoc()['total'] > 0;
        $stmt->close();
    }
    return $ready;
}

function loginUsernameKey(string $username): string
{
    return mb_substr(mb_strtolower(trim($username)), 0, 191);
}

/** Current attempt row (locks it inside the caller's transaction when $forUpdate). */
function loginAttemptRow(mysqli $conn, string $username, bool $forUpdate = false): ?array
{
    if (!loginSecurityReady($conn)) return null;
    $key = loginUsernameKey($username);
    $stmt = $conn->prepare('SELECT *, (locked_until IS NOT NULL AND locked_until > NOW()) AS is_locked,
            GREATEST(0, CEIL(TIMESTAMPDIFF(SECOND, NOW(), locked_until) / 60)) AS minutes_left
        FROM login_attempts WHERE username_key = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** True while the entered username is locked. */
function loginIsLocked(mysqli $conn, string $username): bool
{
    $row = loginAttemptRow($conn, $username);
    return $row !== null && (int)$row['is_locked'] === 1;
}

/**
 * Record one failed attempt. Returns true when this attempt locked the
 * username. $user is the matching account (null for an unknown username).
 */
function loginRecordFailure(mysqli $conn, string $username, ?array $user): bool
{
    if (!loginSecurityReady($conn)) return false;
    $key = loginUsernameKey($username);
    $conn->begin_transaction();
    try {
        $row = loginAttemptRow($conn, $username, true);
        // A lock that ran out starts a fresh count.
        $count = $row === null || ($row['locked_until'] !== null && (int)$row['is_locked'] === 0) ? 0 : (int)$row['failed_count'];
        $count++;
        $locks = $count >= LOGIN_MAX_FAILED_ATTEMPTS;
        $minutes = LOGIN_LOCK_MINUTES;
        $l = $locks ? 1 : 0;
        if ($row === null) {
            $stmt = $conn->prepare("INSERT INTO login_attempts (username_key, failed_count, first_failed_at, last_failed_at, locked_until, lock_count)
                VALUES (?, ?, NOW(), NOW(), IF(?, NOW() + INTERVAL ? MINUTE, NULL), ?)");
            $stmt->bind_param('siiii', $key, $count, $l, $minutes, $l);
        } else {
            $stmt = $conn->prepare("UPDATE login_attempts SET failed_count = ?,
                    first_failed_at = IF(? = 1, NOW(), first_failed_at), last_failed_at = NOW(),
                    locked_until = IF(?, NOW() + INTERVAL ? MINUTE, NULL), lock_count = lock_count + ?
                WHERE username_key = ?");
            $stmt->bind_param('iiiiis', $count, $count, $l, $minutes, $l, $key);
        }
        $stmt->execute();
        $stmt->close();
        if ($locks) loginLockedEvent($conn, $username, $user);
        $conn->commit();
        return $locks;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Login lockout error: ' . $e->getMessage());
        return false;
    }
}

/** Audit entry and notification when an account is locked. */
function loginLockedEvent(mysqli $conn, string $username, ?array $user): void
{
    $text = 'Account locked for ' . LOGIN_LOCK_MINUTES . ' minutes after ' . LOGIN_MAX_FAILED_ATTEMPTS
        . " failed login attempts: User '" . $username . "'";
    if ($user === null || empty($user['user_id'])) {
        // No account: nothing to notify; the audit table needs a user and branch.
        error_log($text . ' (unknown username)');
        return;
    }
    $userId = (int)$user['user_id'];
    $branchId = (string)($user['branch_id'] ?? '');
    if ($branchId !== '') {
        $module = 'Login System';
        $stmt = $conn->prepare('INSERT INTO audit_logs (user_id, branch_id, action, module) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('isss', $userId, $branchId, $text, $module);
        $stmt->execute();
        $stmt->close();
    }
    $roleId = (int)($user['role_id'] ?? 0);
    // Branch Admin and Super Admin accounts are unlocked by the Super Admin.
    if ($roleId === 1 || $roleId === 2 || $branchId === '') {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE role_id = 1 AND status = 'Active' AND user_id <> ?");
        $stmt->bind_param('i', $userId);
    } else {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE role_id = 2 AND status = 'Active' AND branch_id = ?");
        $stmt->bind_param('s', $branchId);
    }
    $stmt->execute();
    $recipients = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'user_id'));
    $stmt->close();
    $title = 'Account locked: ' . $username;
    $message = $username . ' (' . ($user['role_name'] ?? 'user') . ') was locked for ' . LOGIN_LOCK_MINUTES
        . ' minutes after ' . LOGIN_MAX_FAILED_ATTEMPTS . ' failed login attempts. You can unlock the account early in User Management.';
    $type = 'account_locked';
    $sourceKey = 'account_locked:' . $userId . ':' . date('YmdHi');
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, notification_type, source_key, is_read, created_at)
        VALUES (?, ?, ?, ?, ?, 0, NOW()) ON DUPLICATE KEY UPDATE message = VALUES(message), is_read = 0, created_at = NOW()");
    foreach ($recipients as $recipient) {
        $stmt->bind_param('issss', $recipient, $title, $message, $type, $sourceKey);
        $stmt->execute();
    }
    $stmt->close();
}

/** Successful login: forget the failed attempts of this username. */
function loginResetAttempts(mysqli $conn, string $username): void
{
    if (!loginSecurityReady($conn)) return;
    $key = loginUsernameKey($username);
    $stmt = $conn->prepare('DELETE FROM login_attempts WHERE username_key = ?');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->close();
}

/** Lock state of accounts by user_id, for the User Management lists. */
function loginLockStates(mysqli $conn, array $users): array
{
    $states = [];
    if (!loginSecurityReady($conn) || !$users) return $states;
    $keys = [];
    foreach ($users as $user) $keys[loginUsernameKey((string)$user['username'])] = (int)$user['user_id'];
    $stmt = $conn->prepare('SELECT username_key, failed_count, locked_until FROM login_attempts
        WHERE locked_until IS NOT NULL AND locked_until > NOW()');
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        if (isset($keys[$row['username_key']])) $states[$keys[$row['username_key']]] = $row;
    }
    $stmt->close();
    return $states;
}

/**
 * Unlock an account early. A Branch Admin unlocks Nurse, Admin Staff and
 * Inventory Officer accounts of the same branch; the Super Admin unlocks
 * any account. Writes an audit entry. Returns the message to show.
 */
function loginUnlockAccount(mysqli $conn, int $adminId, int $targetUserId): string
{
    if (!loginSecurityReady($conn)) throw new RuntimeException('Login lockout needs the database update: php sources/migrate_login_lockout.php --apply');
    $stmt = $conn->prepare("SELECT user_id, username, role_id, branch_id, status FROM users WHERE user_id IN (?, ?)");
    $stmt->bind_param('ii', $adminId, $targetUserId);
    $stmt->execute();
    $rows = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $rows[(int)$row['user_id']] = $row;
    $stmt->close();
    $admin = $rows[$adminId] ?? null;
    $target = $rows[$targetUserId] ?? null;
    if (!$admin || $admin['status'] !== 'Active' || !in_array((int)$admin['role_id'], [1, 2], true)) {
        throw new RuntimeException('Only an active Branch Admin or Super Admin can unlock accounts.');
    }
    if (!$target) throw new RuntimeException('Account not found.');
    if ((int)$admin['role_id'] === 2 && ((string)$admin['branch_id'] !== (string)$target['branch_id']
        || in_array((int)$target['role_id'], [1, 2], true))) {
        throw new RuntimeException('You can unlock only Nurse, Admin Staff and Inventory Officer accounts of your branch.');
    }
    $key = loginUsernameKey((string)$target['username']);
    $stmt = $conn->prepare('UPDATE login_attempts SET failed_count = 0, locked_until = NULL, unlocked_by = ?, unlocked_at = NOW()
        WHERE username_key = ?');
    $stmt->bind_param('is', $adminId, $key);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    $auditBranch = (string)($target['branch_id'] ?: $admin['branch_id']);
    if ($auditBranch !== '') {
        $action = 'Unlocked login of ' . $target['username'] . ' (ID: ' . $targetUserId . ')' . ($changed ? '' : ' (it was not locked)');
        $module = 'User Management';
        $stmt = $conn->prepare('INSERT INTO audit_logs (user_id, branch_id, action, module) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('isss', $adminId, $auditBranch, $action, $module);
        $stmt->execute();
        $stmt->close();
    }
    return $changed ? 'The account ' . $target['username'] . ' was unlocked. The user can log in again.'
        : 'The account ' . $target['username'] . ' was not locked.';
}
