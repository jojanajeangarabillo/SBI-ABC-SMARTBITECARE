<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = array_slice($argv, 1);
if (in_array('--help', $args, true)) {
    echo "Usage: php sources/migrate_login_lockout.php [--apply]\nDefault: read-only preview.\n";
    exit;
}
foreach ($args as $arg) if ($arg !== '--apply') { fwrite(STDERR, "Unknown argument. Use --help.\n"); exit(1); }
require_once __DIR__ . '/db_connect.php';
/*
 * Login lockout (5 failed attempts lock the account for 15 minutes).
 * Additive only: one new table, login_attempts, keyed by the entered
 * username (lower case), so an unknown username is counted the same way as a
 * real one and the login page never reveals whether a username exists.
 * Existing users and passwords are not changed.
 */
$completed = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts'");
    $stmt->execute();
    $exists = (int)$stmt->get_result()->fetch_assoc()['total'] > 0;
    $stmt->close();
    $plan = [];
    if (!$exists) {
        $plan[] = ['Create table login_attempts', "CREATE TABLE login_attempts (
            username_key VARCHAR(191) NOT NULL,
            failed_count INT NOT NULL DEFAULT 0,
            first_failed_at DATETIME NULL DEFAULT NULL,
            last_failed_at DATETIME NULL DEFAULT NULL,
            locked_until DATETIME NULL DEFAULT NULL,
            lock_count INT NOT NULL DEFAULT 0,
            unlocked_by INT NULL DEFAULT NULL,
            unlocked_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (username_key),
            KEY idx_login_attempts_locked (locked_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"];
    }
    $apply = in_array('--apply', $args, true);
    echo ($apply ? 'APPLY' : 'PREVIEW — read-only') . "\n";
    if (!$plan) echo "No migration changes are needed.\n";
    foreach ($plan as [$label, $sql]) {
        echo '- ' . $label . "\n";
        if ($apply) {
            if (!$conn->query($sql)) throw new RuntimeException('Unable to apply ' . $label . ': ' . $conn->error);
            $completed++;
        }
    }
    if ($apply) echo "Login lockout is ready. Existing accounts and passwords were not changed.\n";
    else echo "No data or structure was changed. Add --apply to execute the plan.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Login lockout migration stopped: ' . $e->getMessage() . "\n"
        . ($completed ? "Some steps completed; correct the problem and rerun.\n" : "No migration changes were applied.\n"));
    exit(1);
}
