<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Background forecast evaluation for one branch (started automatically by
 * forecastAutoEvaluationTrigger(); can also be run by hand):
 *   php sources/run_forecast_evaluation.php --branch=SBI-002 [--job=N]
 * 1. Repeated tests & baselines: evaluate_forecast_reliability.py --save
 *    (adds a NEW saved run; earlier runs are never changed or deleted).
 * 2. 30-day forecast + single-holdout test, when its saved revision is out of
 *    date (the same generation the Supply Forecasting page runs).
 * Only one run per branch at a time (MySQL named lock). On failure nothing is
 * replaced, so the previous results stay visible; the job records the reason.
 */
$options = getopt('', ['branch:', 'job::']);
$branchId = trim((string)($options['branch'] ?? ''));
$jobId = isset($options['job']) ? (int)$options['job'] : 0;
if (!preg_match('/^[A-Za-z0-9_-]{1,10}$/', $branchId)) {
    fwrite(STDERR, "Usage: php sources/run_forecast_evaluation.php --branch=BRANCH_ID [--job=N]\n");
    exit(1);
}
chdir(dirname(__DIR__));
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/forecast_auto_evaluation.php';
require_once __DIR__ . '/forecast_generation_helper.php';
set_time_limit(0);

function autoEvalLog(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
}

function autoEvalUpdate(mysqli $conn, int $jobId, string $status, ?string $message = null, ?int $reliabilityId = null, ?int $evaluationId = null): void
{
    $finished = in_array($status, ['Saved', 'Failed', 'Skipped'], true);
    $stmt = $conn->prepare('UPDATE forecast_evaluation_jobs SET status = ?, message = ?,
        reliability_id = COALESCE(?, reliability_id), evaluation_id = COALESCE(?, evaluation_id),
        started_at = CASE WHEN ? = \'Running\' THEN COALESCE(started_at, NOW()) ELSE started_at END,
        finished_at = CASE WHEN ? = 1 THEN NOW() ELSE finished_at END
        WHERE job_id = ?');
    $finishedFlag = $finished ? 1 : 0;
    $message = $message !== null ? mb_substr($message, 0, 1000) : null;
    $stmt->bind_param('ssiisii', $status, $message, $reliabilityId, $evaluationId, $status, $finishedFlag, $jobId);
    $stmt->execute();
    $stmt->close();
}

/** Run a command, returning [exit code, stdout, stderr]. */
function autoEvalRun(array $command): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, getcwd());
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start ' . basename($command[1] ?? $command[0]) . '.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string)$stdout, (string)$stderr];
}

if (!forecastAutoEvaluationReady($conn)) {
    fwrite(STDERR, "Apply sources/migrate_forecast_auto_evaluation.php first.\n");
    exit(1);
}
$lock = forecastAutoEvaluationLockName($branchId);
$stmt = $conn->prepare('SELECT GET_LOCK(?, 0) AS locked');
$stmt->bind_param('s', $lock);
$stmt->execute();
$locked = (int)($stmt->get_result()->fetch_assoc()['locked'] ?? 0) === 1;
$stmt->close();
if ($jobId === 0) {
    $revision = forecastAutoEvaluationRevision($conn, $branchId);
    $stmt = $conn->prepare("INSERT INTO forecast_evaluation_jobs (branch_id, training_revision, status, trigger_reason)
        VALUES (?, ?, 'Queued', 'Started by hand')");
    $stmt->bind_param('si', $branchId, $revision);
    $stmt->execute();
    $jobId = (int)$conn->insert_id;
    $stmt->close();
}
if (!$locked) {
    autoEvalUpdate($conn, $jobId, 'Skipped', 'Another evaluation for this branch was already running.');
    autoEvalLog("Job $jobId skipped: another run holds the lock.");
    exit(0);
}

try {
    // The revision at the start; data changed during the run is picked up by the next run.
    $revision = forecastAutoEvaluationRevision($conn, $branchId);
    $stmt = $conn->prepare("UPDATE forecast_evaluation_jobs SET training_revision = ? WHERE job_id = ?");
    $stmt->bind_param('ii', $revision, $jobId);
    $stmt->execute();
    $stmt->close();
    autoEvalUpdate($conn, $jobId, 'Running');
    autoEvalLog("Job $jobId: branch $branchId, data revision $revision.");

    $configured = getenv('SMARTBITECARE_PYTHON');
    $python = $configured !== false && trim($configured) !== ''
        ? trim($configured) : (PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');

    // 1. Repeated tests & baselines (a new saved run; identical data returns the same run).
    [$code, $out, $err] = autoEvalRun([$python, 'evaluate_forecast_reliability.py', '--branch', $branchId, '--save']);
    if ($code !== 0 || !preg_match('/Saved reliability evaluation #(\d+)/', $out, $match)) {
        $reason = trim(preg_replace('/^Window .*$/m', '', $err)) ?: trim($out);
        throw new RuntimeException('Repeated tests did not finish: ' . ($reason !== '' ? $reason : 'exit code ' . $code));
    }
    $reliabilityId = (int)$match[1];
    autoEvalUpdate($conn, $jobId, 'Running', null, $reliabilityId);
    autoEvalLog("Repeated tests saved as run #$reliabilityId.");

    // 2. 30-day forecast with its single-holdout test, when that is out of date too.
    $holdoutNote = null;
    $evaluationId = null;
    $days = FORECAST_AUTO_HORIZON;
    $version = FORECAST_EVALUATION_VERSION;
    $stmt = $conn->prepare('SELECT MAX(training_revision) AS revision FROM forecast_evaluation_runs
        WHERE branch_id = ? AND generated_horizon = ? AND evaluation_version = ?');
    $stmt->bind_param('sis', $branchId, $days, $version);
    $stmt->execute();
    $holdoutRevision = $stmt->get_result()->fetch_assoc()['revision'];
    $stmt->close();
    if ($holdoutRevision !== null && (int)$holdoutRevision >= $revision) {
        $holdoutNote = 'Single holdout already current.';
    } else {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE branch_id = ? AND role_id = 2 AND status = 'Active' ORDER BY user_id LIMIT 1");
        $stmt->bind_param('s', $branchId);
        $stmt->execute();
        $actor = (int)($stmt->get_result()->fetch_assoc()['user_id'] ?? 0);
        $stmt->close();
        if ($actor === 0) {
            $holdoutNote = 'Single holdout not refreshed: the branch has no active Branch Administrator.';
        } else {
            try {
                $snapshot = forecastGenerationCapture($conn, $branchId);
                [$code, $out, $err] = autoEvalRun([$python, 'forecasting.py', $branchId, (string)$days]);
                $result = json_decode(trim($out), true);
                if (!is_array($result) || empty($result['success'])) {
                    throw new RuntimeException(is_array($result) ? (string)($result['error'] ?? 'Invalid model response.') : 'Invalid model response.');
                }
                forecastGenerationPublish($conn, $branchId, $days, $actor, $snapshot, $result);
                $stmt = $conn->prepare('SELECT MAX(evaluation_id) AS id FROM forecast_evaluation_runs WHERE branch_id = ? AND generated_horizon = ?');
                $stmt->bind_param('si', $branchId, $days);
                $stmt->execute();
                $evaluationId = (int)$stmt->get_result()->fetch_assoc()['id'];
                $stmt->close();
                autoEvalLog("30-day forecast and single holdout saved as evaluation #$evaluationId.");
            } catch (Throwable $holdoutError) {
                // The repeated tests are saved; the previous holdout stays visible.
                $holdoutNote = 'Single holdout not refreshed: ' . $holdoutError->getMessage();
            }
        }
    }
    autoEvalUpdate($conn, $jobId, 'Saved', $holdoutNote, $reliabilityId, $evaluationId);
    autoEvalLog("Job $jobId saved." . ($holdoutNote ? ' ' . $holdoutNote : ''));
} catch (Throwable $e) {
    autoEvalUpdate($conn, $jobId, 'Failed', $e->getMessage());
    autoEvalLog("Job $jobId failed: " . $e->getMessage());
    $exitCode = 1;
} finally {
    $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->bind_param('s', $lock);
    $stmt->execute();
    $stmt->get_result();
    $stmt->close();
}
exit($exitCode ?? 0);
