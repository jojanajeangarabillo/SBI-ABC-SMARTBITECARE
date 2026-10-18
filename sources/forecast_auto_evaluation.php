<?php
declare(strict_types=1);

require_once __DIR__ . '/forecast_training_helper.php';

/*
 * Automatic forecast evaluations.
 *
 * When a branch's training data changes, forecast_training_branch_state.revision
 * increases (daily closing sync, recorded usage, dataset import). An evaluation
 * is out of date when its saved revision is lower than the current one. Then
 * a BACKGROUND process (sources/run_forecast_evaluation.php) re-runs:
 *   1. the repeated tests & baselines (evaluate_forecast_reliability.py --save), and
 *   2. the 30-day forecast with its single-holdout test (the same generation the
 *      Supply Forecasting page runs), when that is also out of date.
 * Nothing waits for it: the Daily Inventory save and the Forecast Accuracy page
 * only start it. One run per branch at a time (a MySQL named lock). Saved runs
 * are only ever added; earlier runs stay selectable. A failed run changes
 * nothing, so the previous results stay visible.
 * forecast_evaluation_jobs records each run (revision, state, message).
 */

const FORECAST_AUTO_HORIZON = 30;
// A failed run for the same data is retried after this many minutes.
const FORECAST_AUTO_RETRY_MINUTES = 30;
// A queued run that never started (process could not launch) expires after this.
const FORECAST_AUTO_QUEUE_MINUTES = 5;

function forecastAutoEvaluationTableSql(): string
{
    return "CREATE TABLE forecast_evaluation_jobs (
        job_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        branch_id VARCHAR(10) NOT NULL,
        training_revision BIGINT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL,
        trigger_reason VARCHAR(100) NOT NULL,
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        started_at DATETIME NULL,
        finished_at DATETIME NULL,
        reliability_id BIGINT UNSIGNED NULL,
        evaluation_id BIGINT UNSIGNED NULL,
        message VARCHAR(1000) NULL,
        PRIMARY KEY (job_id),
        KEY idx_evaluation_jobs_branch (branch_id, job_id),
        KEY idx_evaluation_jobs_reliability (reliability_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
}

function forecastAutoEvaluationSchemaProblems(mysqli $conn): array
{
    $result = $conn->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forecast_evaluation_jobs'");
    $columns = array_column($result->fetch_all(MYSQLI_ASSOC), 'COLUMN_NAME');
    if (!$columns) return ['forecast_evaluation_jobs is missing'];
    $problems = [];
    foreach (['job_id', 'branch_id', 'training_revision', 'status', 'trigger_reason', 'requested_at',
              'started_at', 'finished_at', 'reliability_id', 'evaluation_id', 'message'] as $column) {
        if (!in_array($column, $columns, true)) $problems[] = "forecast_evaluation_jobs.$column is missing";
    }
    return $problems;
}

/** True once sources/migrate_forecast_auto_evaluation.php was applied. */
function forecastAutoEvaluationReady(mysqli $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = forecastAutoEvaluationSchemaProblems($conn) === [];
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

function forecastAutoEvaluationLockName(string $branchId): string
{
    return 'sbc_forecast_eval_' . $branchId;
}

function forecastAutoEvaluationLockFree(mysqli $conn, string $branchId): bool
{
    $name = forecastAutoEvaluationLockName($branchId);
    $stmt = $conn->prepare('SELECT IS_FREE_LOCK(?) AS free');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $free = (int)($stmt->get_result()->fetch_assoc()['free'] ?? 1);
    $stmt->close();
    return $free === 1;
}

/** Current training-data revision of the branch (read only). */
function forecastAutoEvaluationRevision(mysqli $conn, string $branchId): int
{
    $stmt = $conn->prepare('SELECT revision FROM forecast_training_branch_state WHERE branch_id = ?');
    $stmt->bind_param('s', $branchId);
    $stmt->execute();
    $revision = (int)($stmt->get_result()->fetch_assoc()['revision'] ?? 0);
    $stmt->close();
    return $revision;
}

function forecastAutoEvaluationJob(mysqli $conn, string $sql, array $params, string $types): ?array
{
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * State for the page: current revision, whether a run is in progress, the last
 * successful and last failed run, and whether the saved results are out of date.
 * A queued/running job whose process is gone is marked Failed ("interrupted").
 */
function forecastAutoEvaluationState(mysqli $conn, string $branchId): array
{
    $state = ['ready' => forecastAutoEvaluationReady($conn), 'revision' => 0, 'running' => false,
        'running_job' => null, 'last_saved' => null, 'last_failed' => null, 'stale' => false];
    if (!$state['ready'] || $branchId === '') return $state;
    $state['revision'] = forecastAutoEvaluationRevision($conn, $branchId);
    $active = forecastAutoEvaluationJob($conn,
        "SELECT *, TIMESTAMPDIFF(MINUTE, requested_at, NOW()) AS age_minutes FROM forecast_evaluation_jobs
         WHERE branch_id = ? AND status IN ('Queued', 'Running') ORDER BY job_id DESC LIMIT 1", [$branchId], 's');
    if ($active) {
        $alive = $active['status'] === 'Queued'
            ? (int)$active['age_minutes'] < FORECAST_AUTO_QUEUE_MINUTES
            : !forecastAutoEvaluationLockFree($conn, $branchId);
        if ($alive) {
            $state['running'] = true;
            $state['running_job'] = $active;
        } else {
            $message = $active['status'] === 'Queued'
                ? 'The background process did not start.'
                : 'The background process stopped before finishing.';
            $stmt = $conn->prepare("UPDATE forecast_evaluation_jobs SET status = 'Failed', finished_at = NOW(), message = ?
                WHERE job_id = ? AND status IN ('Queued', 'Running')");
            $jobId = (int)$active['job_id'];
            $stmt->bind_param('si', $message, $jobId);
            $stmt->execute();
            $stmt->close();
        }
    }
    $state['last_saved'] = forecastAutoEvaluationJob($conn,
        "SELECT * FROM forecast_evaluation_jobs WHERE branch_id = ? AND status = 'Saved' ORDER BY job_id DESC LIMIT 1",
        [$branchId], 's');
    $state['last_failed'] = forecastAutoEvaluationJob($conn,
        "SELECT *, TIMESTAMPDIFF(MINUTE, finished_at, NOW()) AS age_minutes FROM forecast_evaluation_jobs
         WHERE branch_id = ? AND status = 'Failed' ORDER BY job_id DESC LIMIT 1", [$branchId], 's');
    if ($state['last_failed'] && $state['last_saved'] && (int)$state['last_saved']['job_id'] > (int)$state['last_failed']['job_id']) {
        $state['last_failed'] = null; // A later run succeeded.
    }
    $state['stale'] = !$state['last_saved'] || (int)$state['last_saved']['training_revision'] < $state['revision'];
    return $state;
}

/**
 * Start the background evaluation when the saved results are out of date.
 * Never waits for the run. Returns: unavailable | running | current | failed | started.
 */
function forecastAutoEvaluationTrigger(mysqli $conn, string $branchId, string $reason): string
{
    if ($branchId === '' || !forecastAutoEvaluationReady($conn)) return 'unavailable';
    // Serialize the check-and-queue step so two rapid triggers start one run.
    $triggerLock = 'sbc_forecast_eval_trigger_' . $branchId;
    $stmt = $conn->prepare('SELECT GET_LOCK(?, 3) AS locked');
    $stmt->bind_param('s', $triggerLock);
    $stmt->execute();
    $locked = (int)($stmt->get_result()->fetch_assoc()['locked'] ?? 0) === 1;
    $stmt->close();
    if (!$locked) return 'running';
    try {
        $state = forecastAutoEvaluationState($conn, $branchId);
        if ($state['running']) return 'running';
        if (!$state['stale']) return 'current';
        $failed = $state['last_failed'];
        if ($failed && (int)$failed['training_revision'] >= $state['revision']
            && (int)$failed['age_minutes'] < FORECAST_AUTO_RETRY_MINUTES) {
            return 'failed';
        }
        $reason = substr($reason, 0, 100);
        $stmt = $conn->prepare("INSERT INTO forecast_evaluation_jobs (branch_id, training_revision, status, trigger_reason)
            VALUES (?, ?, 'Queued', ?)");
        $revision = $state['revision'];
        $stmt->bind_param('sis', $branchId, $revision, $reason);
        $stmt->execute();
        $jobId = (int)$conn->insert_id;
        $stmt->close();
        forecastAutoEvaluationSpawn($branchId, $jobId);
        return 'started';
    } finally {
        $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->bind_param('s', $triggerLock);
        $stmt->execute();
        $stmt->get_result();
        $stmt->close();
    }
}

/** Launch sources/run_forecast_evaluation.php without waiting for it. */
function forecastAutoEvaluationSpawn(string $branchId, int $jobId): void
{
    $configured = getenv('SMARTBITECARE_PHP');
    if ($configured !== false && trim($configured) !== '') {
        $php = trim($configured);
    } elseif (is_executable('/usr/local/bin/php')) {
        $php = '/usr/local/bin/php'; // Docker php:8.2-apache image
    } elseif (PHP_SAPI === 'cli' && PHP_BINARY !== '') {
        $php = PHP_BINARY;
    } else {
        $php = PHP_OS_FAMILY === 'Windows' ? 'php' : (is_executable('/usr/bin/php') ? '/usr/bin/php' : 'php');
    }
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'run_forecast_evaluation.php';
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smartbitecare-forecast-eval-' . preg_replace('/[^A-Za-z0-9_-]/', '', $branchId) . '.log';
    $command = escapeshellarg($php) . ' ' . escapeshellarg($script)
        . ' ' . escapeshellarg('--branch=' . $branchId) . ' ' . escapeshellarg('--job=' . $jobId);
    if (PHP_OS_FAMILY === 'Windows') {
        pclose(popen('start /B "" ' . $command . ' > ' . escapeshellarg($log) . ' 2>&1', 'r'));
    } else {
        exec('nohup ' . $command . ' >> ' . escapeshellarg($log) . ' 2>&1 &');
    }
}

/** reliability_id / evaluation_id => training revision of runs started automatically. */
function forecastAutoEvaluationRevisions(mysqli $conn, string $branchId, string $column): array
{
    if (!forecastAutoEvaluationReady($conn) || !in_array($column, ['reliability_id', 'evaluation_id'], true)) return [];
    $stmt = $conn->prepare("SELECT $column AS id, MIN(training_revision) AS revision FROM forecast_evaluation_jobs
        WHERE branch_id = ? AND status = 'Saved' AND $column IS NOT NULL GROUP BY $column");
    $stmt->bind_param('s', $branchId);
    $stmt->execute();
    $map = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $map[(int)$row['id']] = (int)$row['revision'];
    $stmt->close();
    return $map;
}
