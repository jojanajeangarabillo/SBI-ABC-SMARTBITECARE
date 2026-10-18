<?php
declare(strict_types=1);

require_once __DIR__ . '/forecast_status_helper.php';

/** Forecast alerts are current conditions; stock receipts and requests are events. */
function forecastNotificationTypesSql(): string
{
    return "('forecast','shortage_forecast','prediction','shortage_prediction')";
}

function forecastNotificationStatement(mysqli $conn, string $sql): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare forecast notification synchronization.');
    return $stmt;
}

function forecastNotificationExecute(mysqli_stmt $stmt): void
{
    if (!$stmt->execute()) throw new RuntimeException('Unable to synchronize forecast notifications.');
}

function forecastNotificationUser(mysqli $conn, int $userId): ?array
{
    $stmt = forecastNotificationStatement($conn,
        'SELECT user_id, branch_id, role_id, status FROM users WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    forecastNotificationExecute($stmt);
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

function forecastNotificationKey(string $branchId, int $itemId, int $days, string $date): string
{
    return 'forecast:v2:' . $branchId . ':' . $itemId . ':' . $days . ':' . $date;
}

function forecastNotificationContent(array $row): array
{
    $days = (int)$row['forecast_days'];
    $status = (string)$row['forecast_status'];
    // Display only: quantities are stored in base units (sites/mL) and shown in
    // the clinic's counting unit (vials/ampules), like the forecasting pages.
    return [
        'title' => $days . '-day Supply Forecast: ' . $status,
        'message' => $status . ' for ' . $row['item_name'] . '. '
            . 'Forecast period: ' . $days . ' days. '
            . 'Forecast generated: ' . $row['forecast_date'] . '. '
            . 'Estimated stockout risk: ' . number_format((float)$row['shortage_probability'] * 100, 1) . '%. '
            . 'Forecasted consumption: ' . inventoryForecastQuantityText((float)$row['forecasted_consumption'], $row) . '. '
            . 'Recommended reorder: ' . inventoryReorderText((float)$row['recommended_reorder'], $row) . '.',
        'priority' => forecastStatusPriority($status),
    ];
}

/** Call before any caller-owned transaction; no inventory or forecast data is written. */
function forecastNotificationSynchronize(mysqli $conn, int $userId): void
{
    $user = forecastNotificationUser($conn, $userId);
    // Preserve other roles' notification behavior. Branch is taken from the database.
    if (!$user || $user['status'] !== 'Active' || (int)$user['role_id'] !== 5) return;
    $branchId = (string)($user['branch_id'] ?? '');
    if ($branchId === '') return;

    // This statement also rejects an active caller-owned transaction before we
    // begin/commit/rollback anything. Do not change the session default isolation.
    if (!$conn->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')) {
        throw new RuntimeException('Forecast alerts must be refreshed before starting a stock transaction.');
    }
    if (!$conn->begin_transaction()) throw new RuntimeException('Unable to start forecast alert synchronization.');
    try {
        // Same lock order as forecast publication: revision, catalog, branch stock.
        // Lock an existing revision (or its gap) without creating/changing one.
        $stmt = forecastNotificationStatement($conn,
            'SELECT revision FROM forecast_training_branch_state WHERE branch_id = ? FOR UPDATE');
        $stmt->bind_param('s', $branchId);
        forecastNotificationExecute($stmt);
        $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $items = $conn->query('SELECT item_id FROM inventory_items ORDER BY item_id FOR UPDATE');
        if (!$items) throw new RuntimeException('Unable to check forecast item settings.');
        $items->fetch_all(MYSQLI_ASSOC);
        $items->free();
        $stmt = forecastNotificationStatement($conn,
            'SELECT stock_id FROM inventory_stocks WHERE branch_id = ? ORDER BY stock_id FOR UPDATE');
        $stmt->bind_param('s', $branchId);
        forecastNotificationExecute($stmt);
        $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Establish the consistent-read snapshot only after the locks are held.
        $currentUser = forecastNotificationUser($conn, $userId);
        if (!$currentUser || $currentUser['status'] !== 'Active' || (int)$currentUser['role_id'] !== 5
            || (string)$currentUser['branch_id'] !== $branchId) {
            $conn->rollback();
            return;
        }
        $rows = forecastStatusLatestRows($conn, $branchId);
        $latest = [];
        foreach ($rows as $row) {
            $key = forecastNotificationKey($branchId, (int)$row['item_id'],
                (int)$row['forecast_days'], (string)$row['forecast_date']);
            // Legacy duplicate result rows cannot create duplicate alerts. Use
            // the newest result, including when it resolves an older warning.
            if (!isset($latest[$key]) || (int)$row['forecast_id'] > (int)$latest[$key]['forecast_id']) {
                $latest[$key] = $row;
            }
        }
        $desired = [];
        foreach ($latest as $key => $row) {
            if ($row['forecast_status'] !== 'Sufficient') $desired[$key] = forecastNotificationContent($row);
        }

        $typesSql = forecastNotificationTypesSql();
        $stmt = forecastNotificationStatement($conn,
            "SELECT notification_id, source_key, title, message, is_read
             FROM notifications WHERE user_id = ? AND notification_type IN $typesSql
             ORDER BY notification_id FOR UPDATE");
        $stmt->bind_param('i', $userId);
        forecastNotificationExecute($stmt);
        $saved = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $existing = [];
        foreach ($saved as $notice) {
            $key = (string)($notice['source_key'] ?? '');
            if (isset($desired[$key])) $existing[$key] = $notice;
        }

        $delete = forecastNotificationStatement($conn,
            "DELETE FROM notifications WHERE notification_id = ? AND user_id = ? AND notification_type IN $typesSql");
        $migrate = forecastNotificationStatement($conn,
            "UPDATE notifications SET source_key = ? WHERE notification_id = ? AND user_id = ? AND notification_type IN $typesSql");
        foreach ($saved as $notice) {
            $id = (int)$notice['notification_id'];
            $key = (string)($notice['source_key'] ?? '');
            if (isset($desired[$key])) continue;
            // The old identity lacked a horizon. Migrate only when its message
            // explicitly identifies one current horizon; never guess from risk.
            $target = null;
            if (preg_match('/\Aforecast:([1-9][0-9]*):(\d{4}-\d{2}-\d{2})\z/D', $key, $parts)
                && preg_match('/\bForecast period:\s*(7|14|30)\s+days\./i', (string)$notice['message'], $period)) {
                $candidate = forecastNotificationKey($branchId, (int)$parts[1], (int)$period[1], $parts[2]);
                if (isset($desired[$candidate]) && !isset($existing[$candidate])) $target = $candidate;
            }
            if ($target !== null) {
                $migrate->bind_param('sii', $target, $id, $userId);
                forecastNotificationExecute($migrate);
                $notice['source_key'] = $target;
                $existing[$target] = $notice;
            } else {
                $delete->bind_param('ii', $id, $userId);
                forecastNotificationExecute($delete);
            }
        }
        $delete->close();
        $migrate->close();

        $insert = forecastNotificationStatement($conn,
            "INSERT INTO notifications (user_id, title, message, notification_type, source_key, is_read)
             VALUES (?, ?, ?, 'forecast', ?, 0)");
        $update = forecastNotificationStatement($conn,
            "UPDATE notifications SET title = ?, message = ?, notification_type = 'forecast',
             is_read = CASE WHEN ? = 1 THEN 0 ELSE is_read END,
             created_at = CASE WHEN ? = 1 THEN CURRENT_TIMESTAMP ELSE created_at END
             WHERE notification_id = ? AND user_id = ? AND notification_type IN $typesSql");
        foreach ($desired as $key => $content) {
            $title = $content['title'];
            $message = $content['message'];
            if (!isset($existing[$key])) {
                $insert->bind_param('isss', $userId, $title, $message, $key);
                forecastNotificationExecute($insert);
                continue;
            }
            $notice = $existing[$key];
            $oldPriority = str_starts_with((string)$notice['message'], 'Shortage Risk for ') ? 3
                : (str_starts_with((string)$notice['message'], 'Reorder Needed for ') ? 2 : 0);
            // Refreshes retain read state/time. A known escalation warrants a
            // new unread warning; ambiguous legacy wording does not reset it.
            $escalated = $oldPriority > 0 && $content['priority'] > $oldPriority ? 1 : 0;
            $id = (int)$notice['notification_id'];
            $update->bind_param('ssiiii', $title, $message, $escalated, $escalated, $id, $userId);
            forecastNotificationExecute($update);
        }
        $insert->close();
        $update->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/** On failure callers hide forecast warnings, preserving every saved row. */
function forecastNotificationRefresh(mysqli $conn, int $userId): bool
{
    try {
        forecastNotificationSynchronize($conn, $userId);
        return true;
    } catch (Throwable $e) {
        error_log('Forecast notification synchronization failed: ' . $e->getMessage());
        return false;
    }
}
