<?php
session_start();

// ============================================
// CONFIGURATION & SECURITY
// ============================================

require_once 'sources/db_connect.php';
require_once 'sources/notification_helper.php';

// Check if user is logged in and is Branch Admin (role_id = 2)
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 2
) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$notification_count = getUnreadNotificationCount($conn, $user_id);
$branch_id = $_SESSION['branch_id'] ?? null;

// If branch_id is not set for Branch Admin, redirect
if (empty($branch_id)) {
    header("Location: login.php?error=no_branch");
    exit();
}

// Get user info
$user_sql = "SELECT u.username, b.branch_name 
             FROM users u 
             LEFT JOIN branches b ON u.branch_id = b.branch_id 
             WHERE u.user_id = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_data = $user_result->fetch_assoc();
$user_stmt->close();

$username = $user_data['username'] ?? 'Branch Admin';
$branch_name = $user_data['branch_name'] ?? 'Unknown Branch';

// ============================================
// AUDIT LOG FUNCTION
// ============================================

function addAuditLog($conn, $user_id, $action, $module = 'Supply Forecasting') {
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



/**
 * Forecast quantities are stored/calculated in the item's base unit.
 * Example for converted vial-based products:
 *   base_unit_label = mL
 *   display_unit_label = Vial
 *   conversion_to_base = 5
 * means 1 physical vial contains 5 mL.
 */
function forecastUnitMeta(array $row): array
{
    $legacyUnit = trim((string)($row['unit_name'] ?? ''));
    $baseUnit = trim((string)($row['base_unit_label'] ?? ''));
    $displayUnit = trim((string)($row['display_unit_label'] ?? ''));
    $conversion = (float)($row['conversion_to_base'] ?? 1);

    if ($baseUnit === '') {
        $baseUnit = $legacyUnit !== '' ? $legacyUnit : 'unit';
    }

    if ($displayUnit === '') {
        $displayUnit = $legacyUnit !== '' ? $legacyUnit : $baseUnit;
    }

    if ($conversion <= 0) {
        $conversion = 1.0;
    }

    $isConverted = $conversion > 1.000001
        && strcasecmp($baseUnit, $displayUnit) !== 0;

    return [
        'base_unit' => $baseUnit,
        'display_unit' => $displayUnit,
        'conversion' => $conversion,
        'is_converted' => $isConverted,
    ];
}

function forecastFormatQuantity(float $value, int $decimals = 2): string
{
    $formatted = number_format($value, $decimals, '.', ',');
    return rtrim(rtrim($formatted, '0'), '.');
}

function forecastDisplayUnitLabel(string $label, float $quantity): string
{
    if (strcasecmp($label, 'Vial') === 0 && abs($quantity - 1.0) > 0.000001) {
        return 'Vials';
    }
    return $label;
}

// ============================================
// AUTOMATIC DAILY FORECAST GENERATION
// ============================================

$allowed_forecast_days = [7, 14, 30];
$forecast_days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
if (!in_array($forecast_days, $allowed_forecast_days, true)) {
    $forecast_days = 30;
}

$minimum_records_per_item = 15;
$forecast_message = '';
$forecast_message_type = 'info';

// Count forecastable items that have enough historical records.
$eligible_sql = "SELECT COUNT(*) AS eligible_count
                 FROM (
                     SELECT td.item_id
                     FROM training_dataset td
                     JOIN inventory_items i ON i.item_id = td.item_id
                     WHERE td.branch_id = ?
                       AND i.is_forecastable = 1
                     GROUP BY td.item_id
                     HAVING COUNT(*) >= ?
                 ) AS eligible_items";
$eligible_stmt = $conn->prepare($eligible_sql);
$eligible_stmt->bind_param("si", $branch_id, $minimum_records_per_item);
$eligible_stmt->execute();
$eligible_data = $eligible_stmt->get_result()->fetch_assoc();
$eligible_item_count = (int)($eligible_data['eligible_count'] ?? 0);
$eligible_stmt->close();

// Results are refreshed automatically on the first page visit of each day.
$latest_forecast_sql = "SELECT MAX(CASE WHEN is_stale = 0 THEN forecast_date END) AS latest_forecast_date,
                               CURDATE() AS database_today,
                               COALESCE(SUM(
                                   CASE
                                       WHEN forecast_date = CURDATE() AND is_stale = 0 THEN 1
                                       ELSE 0
                                   END
                               ), 0) AS today_forecast_count,
                               COALESCE(SUM(
                                   CASE
                                       WHEN is_stale = 1 THEN 1
                                       ELSE 0
                                   END
                               ), 0) AS stale_forecast_count
                        FROM forecast_results
                        WHERE branch_id = ?
                          AND forecast_days = ?";
$latest_forecast_stmt = $conn->prepare($latest_forecast_sql);
$latest_forecast_stmt->bind_param("si", $branch_id, $forecast_days);
$latest_forecast_stmt->execute();
$latest_forecast_data = $latest_forecast_stmt->get_result()->fetch_assoc();
$latest_forecast_date = $latest_forecast_data['latest_forecast_date'] ?? null;
$today = $latest_forecast_data['database_today'] ?? date('Y-m-d');
$today_forecast_count = (int)($latest_forecast_data['today_forecast_count'] ?? 0);
$stale_forecast_count = (int)($latest_forecast_data['stale_forecast_count'] ?? 0);
$latest_forecast_stmt->close();

$forecast_is_due = $eligible_item_count > 0 && $today_forecast_count === 0;

if ($forecast_is_due) {
    $python_script = __DIR__ . '/forecasting.py';

    if (!is_file($python_script)) {
        $forecast_message = 'Automatic forecasting could not start because forecasting.py was not found.';
        $forecast_message_type = 'error';
    } else {
        $configured_python = getenv('SMARTBITECARE_PYTHON');
        $python_executable = $configured_python !== false && trim($configured_python) !== ''
            ? trim($configured_python)
            : (PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3');

        $python_command = escapeshellarg($python_executable)
            . ' ' . escapeshellarg($python_script)
            . ' ' . escapeshellarg($branch_id)
            . ' ' . escapeshellarg((string)$forecast_days)
            . ' 2>&1';

        $output = shell_exec($python_command);
        $result = is_string($output) ? json_decode(trim($output), true) : null;

        if ($result && !empty($result['success']) && !empty($result['forecasts']) && is_array($result['forecasts'])) {
            try {
                $conn->begin_transaction();

                // Keep other forecast horizons. Replace only the currently selected horizon.
                $delete_forecasts_stmt = $conn->prepare(
                    "DELETE FROM forecast_results WHERE branch_id = ? AND forecast_days = ?"
                );
                $delete_forecasts_stmt->bind_param("si", $branch_id, $forecast_days);
                $delete_forecasts_stmt->execute();
                $delete_forecasts_stmt->close();

                $insert_forecast_stmt = $conn->prepare("
                    INSERT INTO forecast_results
                    (item_id, branch_id, forecast_date, forecast_start_date, forecast_end_date,
                     current_stock_snapshot, minimum_stock_snapshot, stock_status,
                     shortage_probability, forecast_status, recommended_reorder, generated_by,
                     forecasted_consumption, forecast_days, is_stale, stale_at, stale_reason)
                    VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, NULL)
                ");

                $forecast_count = 0;
                foreach ($result['forecasts'] as $forecast_row) {
                    $item_id = (int)($forecast_row['item_id'] ?? 0);
                    if ($item_id <= 0) {
                        continue;
                    }

                    $item_check_stmt = $conn->prepare(
                        "SELECT item_id
                         FROM inventory_items
                         WHERE item_id = ? AND is_forecastable = 1"
                    );
                    $item_check_stmt->bind_param("i", $item_id);
                    $item_check_stmt->execute();
                    $valid_item = $item_check_stmt->get_result()->fetch_assoc();
                    $item_check_stmt->close();

                    if (!$valid_item) {
                        continue;
                    }

                    $forecast_start_date = !empty($forecast_row['forecast_start'])
                        ? (string)$forecast_row['forecast_start']
                        : null;
                    $forecast_end_date = !empty($forecast_row['forecast_end'])
                        ? (string)$forecast_row['forecast_end']
                        : null;

                    if (!$forecast_start_date || !$forecast_end_date) {
                        continue;
                    }

                    $current_stock_snapshot = max(
                        0.0,
                        (float)($forecast_row['current_stock'] ?? 0)
                    );
                    $minimum_stock_snapshot = max(
                        0.0,
                        (float)($forecast_row['minimum_stock'] ?? 0)
                    );
                    $stock_status = (string)($forecast_row['stock_status'] ?? 'SUFFICIENT STOCK');
                    $shortage_probability = max(
                        0.0,
                        min(1.0, (float)($forecast_row['shortage_probability'] ?? 0))
                    );
                    $forecast_status = (string)($forecast_row['forecast_status'] ?? 'Sufficient');
                    $recommended_reorder = max(0, (int)($forecast_row['recommended_reorder'] ?? 0));
                    $generated_by = $user_id;
                    $forecasted_consumption = max(
                        0.0,
                        (float)($forecast_row['forecasted_consumption'] ?? 0)
                    );

                    $recommended_reorder_value = (float)$recommended_reorder;

                    $insert_forecast_stmt->bind_param(
                        "isssddsdsdidi",
                        $item_id,
                        $branch_id,
                        $forecast_start_date,
                        $forecast_end_date,
                        $current_stock_snapshot,
                        $minimum_stock_snapshot,
                        $stock_status,
                        $shortage_probability,
                        $forecast_status,
                        $recommended_reorder_value,
                        $generated_by,
                        $forecasted_consumption,
                        $forecast_days
                    );
                    $insert_forecast_stmt->execute();
                    $forecast_count++;
                }
                $insert_forecast_stmt->close();

                if ($forecast_count === 0) {
                    throw new RuntimeException('The model did not return any valid forecastable items.');
                }

                $conn->commit();
                $latest_forecast_date = $today;
                $forecast_message = $stale_forecast_count > 0
                    ? "Inventory changed after the previous forecast. A fresh {$forecast_days}-day forecast was generated for {$forecast_count} items."
                    : "Today's {$forecast_days}-day forecasts were updated automatically for {$forecast_count} items.";
                $forecast_message_type = 'success';
                addAuditLog(
                    $conn,
                    $user_id,
                    "Automatically generated {$forecast_days}-day forecasts for {$forecast_count} items",
                    'Supply Forecasting'
                );
            } catch (Throwable $exception) {
                $conn->rollback();
                $forecast_message = 'Automatic forecasting failed: ' . $exception->getMessage();
                $forecast_message_type = 'error';
            }
        } else {
            $forecast_message = 'Automatic forecasting failed: '
                . (is_array($result) ? ($result['error'] ?? 'Invalid model response.') : 'Invalid model response.');
            $forecast_message_type = 'error';
        }
    }
} elseif ($eligible_item_count === 0) {
    $forecast_message = "Automatic forecasting is waiting for at least $minimum_records_per_item records per forecastable item.";
    $forecast_message_type = 'warning';
}

// ============================================
// GET FORECAST RESULTS
// ============================================

$forecasts = [];
$forecast_sql = "SELECT 
                p.*,
                i.item_name,
                i.base_unit_label,
                i.display_unit_label,
                i.conversion_to_base,
                u.unit_name
             FROM forecast_results p
             JOIN inventory_items i ON p.item_id = i.item_id
             LEFT JOIN units u ON i.unit_id = u.unit_id
             WHERE p.branch_id = ?
               AND p.forecast_days = ?
               AND p.is_stale = 0
               AND p.forecast_date = (
                   SELECT MAX(fr2.forecast_date)
                   FROM forecast_results fr2
                   WHERE fr2.branch_id = ?
                     AND fr2.forecast_days = ?
                     AND fr2.is_stale = 0
               )
             ORDER BY p.shortage_probability DESC, p.recommended_reorder DESC";

$forecast_stmt = $conn->prepare($forecast_sql);
$forecast_stmt->bind_param("sisi", $branch_id, $forecast_days, $branch_id, $forecast_days);
$forecast_stmt->execute();
$forecast_result = $forecast_stmt->get_result();

while ($row = $forecast_result->fetch_assoc()) {
    // Determine status color
    if ($row['shortage_probability'] >= 0.8) {
        $status_color = 'danger';
    } elseif ($row['shortage_probability'] >= 0.6) {
        $status_color = 'warning';
    } else {
        $status_color = 'success';
    }
    
    $forecasts[] = [
        'item_name' => $row['item_name'],
        'unit_name' => $row['unit_name'],
        'base_unit_label' => $row['base_unit_label'],
        'display_unit_label' => $row['display_unit_label'],
        'conversion_to_base' => (float)$row['conversion_to_base'],
        'shortage_probability' => (float)$row['shortage_probability'],
        'forecast_status' => $row['forecast_status'],
        'status_color' => $status_color,
        'recommended_reorder' => (int)$row['recommended_reorder'],
        'forecasted_consumption' => (float)$row['forecasted_consumption'],
        'forecast_days' => (int)$row['forecast_days'],
        'current_stock' => (float)($row['current_stock_snapshot'] ?? 0),
        'minimum_stock' => (float)($row['minimum_stock_snapshot'] ?? 0),
        'stock_status' => (string)($row['stock_status'] ?? 'SUFFICIENT STOCK'),
        'forecast_date' => date('m/d/Y', strtotime($row['forecast_date'])),
        'forecast_start_date' => !empty($row['forecast_start_date'])
            ? date('M d, Y', strtotime($row['forecast_start_date']))
            : null,
        'forecast_end_date' => !empty($row['forecast_end_date'])
            ? date('M d, Y', strtotime($row['forecast_end_date']))
            : null
    ];
}
$forecast_stmt->close();

// ============================================
// GET TRAINING DATA SUMMARY
// ============================================

$training_stats = [];
$stats_sql = "SELECT 
                COUNT(DISTINCT item_id) as item_count,
                COUNT(*) as total_records,
                MIN(record_date) as earliest_date,
                MAX(record_date) as latest_date,
                AVG(quantity_used) as avg_usage
             FROM training_dataset 
             WHERE branch_id = ?";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param("s", $branch_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$training_stats = $stats_result->fetch_assoc();
$stats_stmt->close();

// ============================================
// GET FORECASTABLE ITEMS
// ============================================

$forecastable_items = [];
$items_sql = "SELECT
                    i.item_id,
                    i.item_name,
                    i.minimum_stock,
                    i.base_unit_label,
                    i.display_unit_label,
                    i.conversion_to_base,
                    u.unit_name
              FROM inventory_items i
              LEFT JOIN units u ON i.unit_id = u.unit_id
              WHERE i.is_forecastable = 1
              ORDER BY i.item_name";
$items_result = $conn->query($items_sql);
while ($row = $items_result->fetch_assoc()) {
    $forecastable_items[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Supply Forecasting - SmartBiteCare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="sidebar.css">
    <style>
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
            --bg: #F2F2F2;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
        }

        body {
            background: #f0f2f5;
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
            border-bottom: 1px solid #e9edf5;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }

        .topbar h3 {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
        }

        .topbar h3 small {
            color: #666;
            font-size: 16px;
            font-weight: 400;
            margin-left: 10px;
        }
         .profile-role {
            margin-left: 4px;
            color: #adb5bd;
            font-size: 12px;
            font-weight: 400;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 7px;
            font-weight: 600;
            color: var(--primary);
            cursor: default;
        }

        .profile i {
            font-size: 19px;
        }

        .profile .profile-role {
            font-weight: 600;
        }

        .page-body {
            padding: 35px 35px 40px;
        }

  /* =========================================================
   SYSTEM TOAST / PAGE ALERT
   ========================================================= */

.toast-container {
    width: 100%;
    margin: 0 0 24px 0;
    position: relative;
    z-index: 10;
}

.toast-custom {
    width: 100%;
    min-height: 60px;
    padding: 15px 18px;
    margin: 0;

    display: flex;
    align-items: center;
    gap: 10px;

    border: none;
    border-radius: 12px;
    box-shadow: none;

    font-size: 16px;
    line-height: 1.4;

    animation: toastFadeIn .25s ease;
}

/* SUCCESS */
.toast-custom.success {
    background: #d1e7dd;
    color: #0f5132;
}

.toast-custom.success .toast-icon {
    color: #0f5132;
}

/* ERROR */
.toast-custom.error {
    background: #f8d7da;
    color: #842029;
}

.toast-custom.error .toast-icon {
    color: #842029;
}

/* WARNING */
.toast-custom.warning {
    background: #fff3cd;
    color: #664d03;
}

.toast-custom.warning .toast-icon {
    color: #664d03;
}

/* INFO */
.toast-custom.info {
    background: #cff4fc;
    color: #055160;
}

.toast-custom.info .toast-icon {
    color: #055160;
}

.toast-custom .toast-icon {
    flex: 0 0 auto;
    width: 22px;
    height: 22px;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 18px;
}

.toast-custom .toast-msg {
    flex: 1;
    min-width: 0;

    font-size: 16px;
    font-weight: 400;
    line-height: 1.4;
}

/* No close X */
.toast-custom .toast-close {
    display: none;
}

@keyframes toastFadeIn {
    from {
        opacity: 0;
        transform: translateY(-6px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes toastFadeOut {
    from {
        opacity: 1;
        transform: translateY(0);
    }

    to {
        opacity: 0;
        transform: translateY(-6px);
    }
}

@media (max-width: 768px) {
    .toast-custom {
        min-height: 56px;
        padding: 13px 15px;
        gap: 9px;
    }

    .toast-custom .toast-icon {
        width: 20px;
        height: 20px;
        font-size: 17px;
    }

    .toast-custom .toast-msg {
        font-size: 14px;
    }
}
/* =========================================================
   GLOBAL LOGOUT CONFIRMATION MODAL 
   ========================================================= */

.confirm-modal .modal-dialog {
    max-width: 500px !important;
    width: calc(100% - 30px);
    margin: 1.75rem auto;
}

.confirm-modal .modal-content {
    overflow: hidden !important;
    border: 0 !important;
    border-radius: 20px !important;
    background: #fff !important;
    box-shadow: 0 20px 55px rgba(31, 45, 110, 0.20) !important;
}

.confirm-modal .modal-header {
    display: block !important;
    padding: 26px 24px 6px !important;
    border: 0 !important;
    background: #fff !important;
    text-align: center !important;
}

.confirm-modal .modal-icon {
    width: 64px !important;
    height: 64px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 auto 14px !important;

    color: #fff !important;
    background: linear-gradient(135deg, #ef3340, #f05b68) !important;
    border-radius: 50% !important;

    box-shadow: 0 9px 22px rgba(239, 51, 64, 0.22) !important;
    font-size: 27px !important;
}

.confirm-modal .modal-icon.logout-icon {
    background: linear-gradient(135deg, #ef3340, #f05b68) !important;
    box-shadow: 0 9px 22px rgba(239, 51, 64, 0.22) !important;
}

.confirm-modal .modal-title {
    margin: 0 !important;
    color: #283a7a !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    line-height: 1.3 !important;
}

.confirm-modal .modal-body {
    padding: 6px 30px 22px !important;
    background: #fff !important;
    color: #7a879e !important;
    text-align: center !important;
}

.confirm-modal .modal-body p {
    margin: 0 !important;
    color: #7a879e !important;
    font-size: 17px !important;
    line-height: 1.45 !important;
}

.confirm-modal .modal-footer {
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 10px !important;
    padding: 0 24px 26px !important;
    border: 0 !important;
    background: #fff !important;
}

.confirm-modal .modal-footer .btn {
    min-height: 50px !important;
    margin: 0 !important;
    border-radius: 10px !important;
    font-size: 16px !important;
    font-weight: 700 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}

.confirm-modal .btn-light,
.confirm-modal .btn-cancel {
    color: #111 !important;
    background: #f8f9fa !important;
    border: 1px solid #d9dfe8 !important;
}

.confirm-modal .btn-light:hover,
.confirm-modal .btn-cancel:hover {
    background: #eef0f3 !important;
}

.confirm-modal .btn-danger,
.confirm-modal .btn-logout {
    color: #fff !important;
    background: #e83445 !important;
    border: 1px solid #e83445 !important;
    text-decoration: none !important;
}

.confirm-modal .btn-danger:hover,
.confirm-modal .btn-logout:hover {
    color: #fff !important;
    background: #d92839 !important;
    border-color: #d92839 !important;
}
        .toast-custom.error {
            border-left-color: #dc3545;
        }

        .toast-custom.warning {
            border-left-color: #ffc107;
        }

        .toast-custom .toast-icon {
            font-size: 28px;
            color: #28a745;
        }

        .toast-custom.error .toast-icon {
            color: #dc3545;
        }

        .toast-custom.warning .toast-icon {
            color: #d99b00;
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

        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 20px;
        }

        .stat-card {
            display: grid;
            grid-template-columns: 42px minmax(0, 1fr);
            column-gap: 12px;
            align-items: center;
            height: 120px;
            padding: 18px 22px;
            overflow: hidden;
            background: #fff;
            border: 0;
            border-left: 5px solid var(--primary);
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,.08);
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(0,0,0,.10);
        }

        .stat-card .stat-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 30px;
        }

        .stat-card .stat-content {
            min-width: 0;
        }

        .stat-card .stat-label {
            overflow: hidden;
            color: #2f3b4d;
            font-size: 14px;
            font-weight: 500;
            line-height: 1.2;
            margin-bottom: 2px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .stat-card .stat-number {
            color: #111827;
            font-size: 28px;
            font-weight: 700;
            line-height: 1.05;
        }

        .stat-card .stat-description {
            overflow: hidden;
            color: #71809d;
            font-size: 12px;
            line-height: 1.2;
            margin-top: 3px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .stat-primary { border-left-color: var(--primary); }
        .stat-success { border-left-color: var(--success); }
        .stat-info { border-left-color: var(--info); }
        .stat-warning { border-left-color: var(--warning); }
        .stat-primary .stat-icon { color: var(--primary); }
        .stat-success .stat-icon { color: var(--success); }
        .stat-info .stat-icon { color: var(--info); }
        .stat-warning .stat-icon { color: #d99b00; }

        .forecast-horizon-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
            background: #fff;
            border: 1px solid #e8ebf3;
            border-radius: 14px;
            padding: 18px 20px;
            margin-bottom: 22px;
            box-shadow: 0 3px 10px rgba(43, 58, 140, 0.06);
        }

        .forecast-horizon-card h6 {
            margin: 0 0 4px;
            color: var(--primary);
            font-size: 15px;
            font-weight: 700;
        }

        .forecast-horizon-card p {
            margin: 0;
            color: #6c757d;
            font-size: 13px;
        }

        .forecast-horizon-options {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .forecast-horizon-options .btn {
            min-width: 88px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
        }

        .forecast-status-card {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
            padding: 18px 22px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,.08);
        }

        .forecast-status-card .status-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            flex: 0 0 44px;
            color: var(--primary);
            background: #eef1ff;
            border-radius: 12px;
            font-size: 22px;
        }

        .forecast-status-card h5 {
            color: #18233f;
            font-size: 16px;
            font-weight: 700;
            margin: 0 0 3px;
        }

        .forecast-status-card p {
            color: #71809d;
            font-size: 13px;
            margin: 0;
        }

        .table-wrap {
            background: white;
            border-radius: 16px;
            border: 0;
            box-shadow: 0 4px 12px rgba(0,0,0,.08);
            overflow: hidden;
        }

        .table-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 22px;
            border-bottom: 1px solid #edf0f5;
        }

        .table-header h5 {
            color: var(--primary);
            font-size: 18px;
            font-weight: 700;
            margin: 0;
        }

        .data-table {
            margin: 0;
        }

        .data-table thead th {
            background: var(--primary);
            color: white;
            font-weight: 600;
            font-size: 13px;
            border: none;
            padding: 14px;
            white-space: nowrap;
        }

        .data-table tbody td {
            font-size: 14px;
            color: #333;
            padding: 13px 14px;
            vertical-align: middle;
            border-bottom: 1px solid #eef0f7;
        }

        .data-table tbody tr:last-child td {
            border-bottom: none;
        }

        .data-table tbody tr:hover {
            background: #f7f8fc;
        }

        .badge-status {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-success {
            background: #E6F4EA;
            color: #1E7B34;
        }

        .badge-warning {
            background: #FFF3CD;
            color: #856404;
        }

        .badge-danger {
            background: #FFEAEA;
            color: var(--accent);
        }

        .probability-bar {
            height: 8px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 4px;
        }

        .probability-bar .fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.6s ease;
        }

        .fill-high {
            background: var(--accent);
        }

        .fill-medium {
            background: #ffc107;
        }

        .fill-low {
            background: #28a745;
        }

        /* =========================================================
           FORECAST VISUALIZATION + TABLE CONTROLS
           ========================================================= */

        .forecast-visual-card {
            height: 100%;
            background: #fff;
            border: 1px solid #e8ebf3;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,.06);
            padding: 20px;
        }

        .forecast-visual-card .visual-title {
            color: var(--primary);
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .forecast-visual-card .visual-subtitle {
            color: #71809d;
            font-size: 12px;
            margin-bottom: 14px;
        }

        .chart-box {
            position: relative;
            min-height: 290px;
        }

        .table-tools {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding: 14px 18px;
            border-bottom: 1px solid #edf0f5;
            background: #fbfcff;
        }

        .table-tools-left,
        .table-tools-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .forecast-search {
            min-width: 250px;
        }

        .table-tools .form-control,
        .table-tools .form-select {
            border-radius: 10px;
            border-color: #dfe4ee;
            font-size: 13px;
            min-height: 38px;
        }

        .table-responsive-forecast {
            overflow-x: auto;
        }

        .data-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
        }

        .pagination-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding: 14px 18px;
            border-top: 1px solid #edf0f5;
            background: #fff;
        }

        .pagination-summary {
            color: #71809d;
            font-size: 13px;
        }

        .forecast-pagination {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }

        .forecast-pagination button {
            min-width: 36px;
            height: 36px;
            padding: 0 10px;
            border: 1px solid #dfe4ee;
            border-radius: 9px;
            background: #fff;
            color: var(--primary);
            font-size: 13px;
            font-weight: 700;
        }

        .forecast-pagination button:hover:not(:disabled),
        .forecast-pagination button.active {
            color: #fff;
            background: var(--primary);
            border-color: var(--primary);
        }

        .forecast-pagination button:disabled {
            opacity: .45;
            cursor: not-allowed;
        }

        .filtered-empty-row td {
            padding: 28px !important;
            text-align: center;
            color: #71809d !important;
        }

        @media (max-width: 991px) {
            .main {
                margin-left: 90px;
            }
        }

        @media (max-width: 576px) {
            .topbar {
                padding: 0 16px;
                height: 70px;
            }
            .topbar h3 {
                font-size: 20px;
            }
            .page-body {
                padding: 20px 16px;
            }
            .profile .profile-role {
                display: none;
            }
            .forecast-status-card {
                align-items: flex-start;
            }
            .table-wrap {
                overflow: hidden;
            }

            .forecast-search {
                min-width: 100%;
                width: 100%;
            }

            .table-tools-left,
            .table-tools-right {
                width: 100%;
            }

            .table-tools .form-select {
                flex: 1 1 140px;
            }

            .pagination-footer {
                align-items: flex-start;
            }

            .chart-box {
                min-height: 250px;
            }
        }
    </style>
</head>
<body>

<!-- ========== SIDEBAR ========== -->
<div class="sidebar">
    <div class="logo-area">
        <div class="logo-frame">
            <img src="logo.png" alt="Smart Bite Care Logo" class="logo">
        </div>
        <div class="system-name">Smart Bite Care</div>
    </div>

    <nav class="nav-menu">
        <ul>
            <li><a href="BranchAdmin_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="BranchAdmin_UserManagement.php"><i class="bi bi-people-fill"></i><span>User Management</span></a></li>
            <li><a href="BranchAdmin_PatientMonitoring.php"><i class="bi bi-heart-pulse-fill"></i><span>Patient Monitoring</span></a></li>
            <li><a href="BranchAdmin_PhilhealthWorkflow.php"><i class="bi bi-file-medical-fill"></i><span>PhilHealth Processing</span></a></li>
            <li><a href="BranchAdmin_InventoryOverview.php"><i class="bi bi-box-seam"></i><span>Inventory Overview</span></a></li>
            <li><a class="active" href="BranchAdmin_Forecasting.php"><i class="bi bi-graph-up-arrow"></i><span>Supply Forecasting</span></a></li>
            <li><a href="BranchAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
            <li><a href="BranchAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
            <li><a href="BranchAdmin_Notifications.php" class="notification-link"><i class="bi bi-bell-fill"></i><span>Notifications</span>
                <?php if ($notification_count > 0): ?>
                    <span class="notification-badge"><?php echo $notification_count; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="BranchAdmin_Settings.php"><i class="bi bi-gear-fill"></i><span>Settings</span></a></li>
        </ul>
    </nav>
</div>

<!-- ========== MAIN CONTENT ========== -->
<div class="main">
    <div class="topbar">
        <h3>Supply Forecasting <small class="text-muted fs-6"><?php echo htmlspecialchars($branch_name); ?></small></h3>
         <div class="dropdown">
                <button class="profile dropdown-toggle border-0 bg-transparent px-3 py-2 rounded-3"
                        type="button" id="branchAdminProfileMenu"
                        data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle"></i>
                    <span><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="profile-role">| Branch Admin</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end border-0 shadow p-2 mt-2"
                    aria-labelledby="branchAdminProfileMenu">
                    <li><h6 class="dropdown-header">Account options</h6></li>
                    <li>
                        <a class="dropdown-item rounded-2 py-2" href="Account_ChangePassword.php">
                            <i class="bi bi-key-fill me-2"></i>Change Password
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item rounded-2 py-2 text-danger"
                            href="#"
                            data-bs-toggle="modal"
                            data-bs-target="#logoutConfirmModal">
                            <i class="bi bi-box-arrow-right me-2"></i>Logout
                        </a>
                    </li>
                </ul>
            </div>
    </div>

    <div class="page-body">

    <!-- ========== TOAST CONTAINER ========== -->
    <div class="toast-container" id="toastContainer"></div>

        <!-- Forecast Horizon Selector -->
        <div class="forecast-horizon-card">
            <div>
                <h6><i class="bi bi-calendar-range me-2"></i>Forecast Horizon</h6>
                <p>Select how far ahead the system should forecast. Each horizon is saved separately.</p>
            </div>
            <div class="forecast-horizon-options" role="group" aria-label="Forecast horizon">
                <?php foreach ($allowed_forecast_days as $days_option): ?>
                    <a href="BranchAdmin_Forecasting.php?days=<?php echo $days_option; ?>"
                       class="btn <?php echo $forecast_days === $days_option ? 'btn-primary' : 'btn-outline-primary'; ?>">
                        <?php echo $days_option; ?> Days
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stat-card stat-primary">
                    <div class="stat-icon"><i class="bi bi-database-check"></i></div>
                    <div class="stat-content">
                        <div class="stat-label">Training Records</div>
                        <div class="stat-number"><?php echo number_format($training_stats['total_records'] ?? 0); ?></div>
                        <div class="stat-description">Stored historical records</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card stat-info">
                    <div class="stat-icon"><i class="bi bi-boxes"></i></div>
                    <div class="stat-content">
                        <div class="stat-label">Items with Data</div>
                        <div class="stat-number"><?php echo number_format($training_stats['item_count'] ?? 0); ?></div>
                        <div class="stat-description">Items in the training set</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card stat-success">
                    <div class="stat-icon"><i class="bi bi-graph-up-arrow"></i></div>
                    <div class="stat-content">
                        <div class="stat-label">Current Forecasts</div>
                        <div class="stat-number"><?php echo count($forecasts); ?></div>
                        <div class="stat-description">Automatic <?php echo $forecast_days; ?>-day results</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="stat-card stat-warning">
                    <div class="stat-icon"><i class="bi bi-calendar-check"></i></div>
                    <div class="stat-content">
                        <div class="stat-label">Last Updated</div>
                        <div class="stat-number" style="font-size:22px;">
                            <?php echo $latest_forecast_date ? date('M d, Y', strtotime($latest_forecast_date)) : 'Pending'; ?>
                        </div>
                        <div class="stat-description">Refreshed once each day</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Automatic Forecast Information -->
        <div class="forecast-status-card">
            <div class="status-icon"><i class="bi bi-cpu-fill"></i></div>
            <div>
                <h5>Automatic XGBoost Forecasting</h5>
                <p>
                    The system automatically refreshes the selected <?php echo $forecast_days; ?>-day supply forecast on the first page visit for that horizon each day.
                    SMAPE is used for model evaluation, and only items with at least
                    <?php echo $minimum_records_per_item; ?> historical records are included.
                </p>
            </div>
        </div>

        <!-- Forecast Visualizations -->
        <div class="row g-4 mb-4">
            <div class="col-xl-4 col-lg-5">
                <div class="forecast-visual-card">
                    <div class="visual-title">
                        <i class="bi bi-pie-chart-fill me-2"></i>Risk Distribution
                    </div>
                    <div class="visual-subtitle">
                        Number of forecasted items by stockout-risk level.
                    </div>
                    <div class="chart-box">
                        <canvas id="riskDistributionChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-xl-8 col-lg-7">
                <div class="forecast-visual-card">
                    <div class="visual-title">
                        <i class="bi bi-bar-chart-fill me-2"></i>Highest Stockout Risks
                    </div>
                    <div class="visual-subtitle">
                        Top forecasted supplies that need the most attention for the selected horizon.
                    </div>
                    <div class="chart-box">
                        <canvas id="topRiskChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Forecast Results -->
        <div class="table-wrap">
            <div class="table-header">
                <h5><i class="bi bi-clipboard-data me-2"></i>Forecast Results</h5>
                <span class="text-muted" style="font-size:13px;">
                    <?php echo count($forecasts); ?> forecasts found · Next <?php echo $forecast_days; ?> days
                </span>
            </div>

            <?php if (count($forecasts) > 0): ?>
                <div class="table-tools">
                    <div class="table-tools-left">
                        <div class="input-group forecast-search">
                            <span class="input-group-text bg-white border-end-0">
                                <i class="bi bi-search"></i>
                            </span>
                            <input
                                type="search"
                                class="form-control border-start-0"
                                id="forecastSearch"
                                placeholder="Search item..."
                                autocomplete="off"
                            >
                        </div>

                        <select class="form-select" id="riskFilter" aria-label="Filter by risk">
                            <option value="all">All risk levels</option>
                            <option value="high">High Risk</option>
                            <option value="moderate">Moderate Risk</option>
                            <option value="low">Low Risk</option>
                        </select>

                        <select class="form-select" id="stockFilter" aria-label="Filter by stock status">
                            <option value="all">All stock statuses</option>
                            <option value="OUT OF STOCK">Out of Stock</option>
                            <option value="LOW STOCK">Low Stock</option>
                            <option value="SUFFICIENT STOCK">Sufficient Stock</option>
                        </select>
                    </div>

                    <div class="table-tools-right">
                        <label for="pageSize" class="text-muted" style="font-size:13px;">Rows:</label>
                        <select class="form-select" id="pageSize" style="width:auto;">
                            <option value="10" selected>10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                </div>
            <?php endif; ?>

            <div class="table-responsive-forecast">
                <table class="table data-table" id="forecastTable">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Unit</th>
                            <th>Stock at Forecast</th>
                            <th>Min Stock</th>
                            <th>Stock Status</th>
                            <th>Forecasted Consumption</th>
                            <th>Stockout Risk</th>
                            <th>Status</th>
                            <th>Recommendation</th>
                            <th>Forecast Period</th>
                        </tr>
                    </thead>
                    <tbody id="forecastTableBody">
                        <?php if (count($forecasts) > 0): ?>
                            <?php foreach ($forecasts as $forecast): ?>
                                <?php
                                    $risk_level = $forecast['shortage_probability'] >= 0.8
                                        ? 'high'
                                        : ($forecast['shortage_probability'] >= 0.6 ? 'moderate' : 'low');

                                    $unitMeta = forecastUnitMeta($forecast);
                                    $baseUnit = $unitMeta['base_unit'];
                                    $displayUnit = $unitMeta['display_unit'];
                                    $conversion = (float)$unitMeta['conversion'];
                                    $isConverted = (bool)$unitMeta['is_converted'];

                                    $currentStock = max(0.0, (float)$forecast['current_stock']);
                                    $minimumStock = max(0.0, (float)$forecast['minimum_stock']);
                                    $forecastUse = max(0.0, (float)($forecast['forecasted_consumption'] ?? 0));
                                    $reorderBase = max(0.0, (float)$forecast['recommended_reorder']);

                                    $currentDisplay = $isConverted ? $currentStock / $conversion : $currentStock;
                                    $minimumDisplay = $isConverted ? $minimumStock / $conversion : $minimumStock;
                                    $reorderDisplay = $isConverted
                                        ? (int)ceil($reorderBase / $conversion)
                                        : (int)ceil($reorderBase);

                                    $reorderDisplayUnit = forecastDisplayUnitLabel(
                                        $displayUnit,
                                        (float)$reorderDisplay
                                    );
                                ?>
                                <tr
                                    class="forecast-row"
                                    data-item="<?php echo htmlspecialchars(strtolower($forecast['item_name']), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-risk="<?php echo $risk_level; ?>"
                                    data-stock-status="<?php echo htmlspecialchars($forecast['stock_status'], ENT_QUOTES, 'UTF-8'); ?>"
                                >
                                    <td><strong><?php echo htmlspecialchars($forecast['item_name']); ?></strong></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($baseUnit); ?></strong>
                                        <?php if ($isConverted): ?>
                                            <div class="text-muted" style="font-size:11px;">
                                                1 <?php echo htmlspecialchars($displayUnit); ?>
                                                = <?php echo forecastFormatQuantity($conversion); ?>
                                                <?php echo htmlspecialchars($baseUnit); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?php echo forecastFormatQuantity($currentStock); ?>
                                            <?php echo htmlspecialchars($baseUnit); ?>
                                        </strong>
                                        <?php if ($isConverted): ?>
                                            <div class="text-muted" style="font-size:11px;">
                                                ≈ <?php echo forecastFormatQuantity($currentDisplay); ?>
                                                <?php echo htmlspecialchars(
                                                    forecastDisplayUnitLabel($displayUnit, $currentDisplay)
                                                ); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?php echo forecastFormatQuantity($minimumStock); ?>
                                            <?php echo htmlspecialchars($baseUnit); ?>
                                        </strong>
                                        <?php if ($isConverted): ?>
                                            <div class="text-muted" style="font-size:11px;">
                                                = <?php echo forecastFormatQuantity($minimumDisplay); ?>
                                                <?php echo htmlspecialchars(
                                                    forecastDisplayUnitLabel($displayUnit, $minimumDisplay)
                                                ); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                            $stock_status_class = $forecast['stock_status'] === 'OUT OF STOCK'
                                                ? 'bg-danger'
                                                : ($forecast['stock_status'] === 'LOW STOCK' ? 'bg-warning text-dark' : 'bg-success');
                                        ?>
                                        <span class="badge <?php echo $stock_status_class; ?>">
                                            <?php echo htmlspecialchars($forecast['stock_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong>
                                            <?php echo forecastFormatQuantity($forecastUse); ?>
                                            <?php echo htmlspecialchars($baseUnit); ?>
                                        </strong>
                                        <?php if ($isConverted): ?>
                                            <div class="text-muted" style="font-size:11px;">
                                                ≈ <?php echo forecastFormatQuantity($forecastUse / $conversion); ?>
                                                <?php echo htmlspecialchars(
                                                    forecastDisplayUnitLabel($displayUnit, $forecastUse / $conversion)
                                                ); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo number_format($forecast['shortage_probability'] * 100, 1); ?>%
                                        <div class="probability-bar">
                                            <div class="fill <?php
                                                echo $forecast['shortage_probability'] >= 0.8 ? 'fill-high' :
                                                    ($forecast['shortage_probability'] >= 0.6 ? 'fill-medium' : 'fill-low');
                                            ?>"
                                                 style="width: <?php echo $forecast['shortage_probability'] * 100; ?>%;">
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php
                                            echo $forecast['status_color'] == 'danger' ? 'badge-danger' :
                                                ($forecast['status_color'] == 'warning' ? 'badge-warning' : 'badge-success');
                                        ?>">
                                            <?php echo htmlspecialchars($forecast['forecast_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($reorderBase > 0): ?>
                                            <span class="badge bg-warning text-dark">
                                                Reorder
                                                <?php echo number_format($reorderDisplay); ?>
                                                <?php echo htmlspecialchars(
                                                    $isConverted ? $reorderDisplayUnit : $displayUnit
                                                ); ?>
                                            </span>

                                            <?php if ($isConverted): ?>
                                                <div class="text-muted mt-1" style="font-size:11px;">
                                                    <?php echo forecastFormatQuantity($reorderBase); ?>
                                                    <?php echo htmlspecialchars($baseUnit); ?> required
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-success fw-semibold">
                                                <i class="bi bi-check-circle-fill me-1"></i>No reorder
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($forecast['forecast_start_date'] && $forecast['forecast_end_date']): ?>
                                            <strong><?php echo htmlspecialchars($forecast['forecast_start_date']); ?></strong>
                                            <div class="text-muted" style="font-size:12px;">
                                                to <?php echo htmlspecialchars($forecast['forecast_end_date']); ?>
                                            </div>
                                            <div class="text-muted" style="font-size:11px;">
                                                Generated <?php echo htmlspecialchars($forecast['forecast_date']); ?>
                                            </div>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($forecast['forecast_date']); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <tr id="filteredEmptyRow" class="filtered-empty-row" style="display:none;">
                                <td colspan="10">
                                    <i class="bi bi-search fs-4 d-block mb-2"></i>
                                    No forecasts match the selected filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                    No automatic forecast is available yet.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($forecasts) > 0): ?>
                <div class="pagination-footer">
                    <div class="pagination-summary" id="paginationSummary">
                        Showing forecasts
                    </div>
                    <div class="forecast-pagination" id="forecastPagination"></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Forecastable Items List -->
        <div class="mt-4">
            <h6 class="fw-bold text-muted" style="font-size:13px;text-transform:uppercase;letter-spacing:0.3px;">
                <i class="bi bi-list-check me-2"></i>Forecastable Items in Inventory
            </h6>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($forecastable_items as $item): ?>
                    <span class="badge bg-light text-dark border" style="padding:6px 14px;font-weight:600;">
                        <?php
                            $itemUnitMeta = forecastUnitMeta($item);
                            $itemBaseUnit = $itemUnitMeta['base_unit'];
                            $itemDisplayUnit = $itemUnitMeta['display_unit'];
                            $itemConversion = (float)$itemUnitMeta['conversion'];
                        ?>
                        <?php echo htmlspecialchars($item['item_name']); ?>
                        <span class="text-muted ms-1" style="font-weight:400;">
                            (<?php echo htmlspecialchars($itemBaseUnit); ?>
                            <?php if ($itemUnitMeta['is_converted']): ?>
                                → <?php echo htmlspecialchars($itemDisplayUnit); ?>,
                                1 <?php echo htmlspecialchars($itemDisplayUnit); ?>
                                = <?php echo forecastFormatQuantity($itemConversion); ?>
                                <?php echo htmlspecialchars($itemBaseUnit); ?>
                            <?php endif; ?>)
                        </span>
                    </span>
                <?php endforeach; ?>
                <?php if (empty($forecastable_items)): ?>
                    <span class="text-muted">No forecastable items found. Add items with is_forecastable=1.</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal fade confirm-modal" id="logoutConfirmModal" tabindex="-1"
     aria-labelledby="logoutConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <div class="modal-icon logout-icon">
                    <i class="bi bi-box-arrow-right"></i>
                </div>

                <h2 class="modal-title" id="logoutConfirmModalLabel">
                    Log out of Smart Bite Care?
                </h2>
            </div>

            <div class="modal-body">
                <p class="mb-0">
                    Make sure you have saved any unfinished work before leaving your account.
                </p>
            </div>

            <div class="modal-footer">
                <button
                    type="button"
                    class="btn btn-light border"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>

                <a
                    href="logout.php"
                    class="btn btn-danger d-flex align-items-center justify-content-center"
                >
                    <i class="bi bi-box-arrow-right me-1"></i>
                    Yes, Log Out
                </a>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
// ============================================
// FORECAST VISUALIZATION + FILTER + PAGINATION
// ============================================

const forecastData = <?php echo json_encode(
    $forecasts,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
); ?>;

function getRiskLevel(probability) {
    const p = Number(probability) || 0;

    if (p >= 0.8) {
        return 'high';
    }

    if (p >= 0.6) {
        return 'moderate';
    }

    return 'low';
}

function initializeForecastCharts() {
    if (typeof Chart === 'undefined' || !Array.isArray(forecastData) || forecastData.length === 0) {
        return;
    }

    const highCount = forecastData.filter(item => getRiskLevel(item.shortage_probability) === 'high').length;
    const moderateCount = forecastData.filter(item => getRiskLevel(item.shortage_probability) === 'moderate').length;
    const lowCount = forecastData.filter(item => getRiskLevel(item.shortage_probability) === 'low').length;

    const riskCanvas = document.getElementById('riskDistributionChart');

    if (riskCanvas) {
        new Chart(riskCanvas, {
            type: 'doughnut',
            data: {
                labels: ['High Risk', 'Moderate Risk', 'Low Risk'],
                datasets: [{
                    data: [highCount, moderateCount, lowCount],
                    backgroundColor: ['#dc3545', '#ffc107', '#28a745'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 18
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.label + ': ' + context.raw + ' item(s)';
                            }
                        }
                    }
                }
            }
        });
    }

    const topRiskCanvas = document.getElementById('topRiskChart');

    if (topRiskCanvas) {
        const topRiskItems = [...forecastData]
            .sort((a, b) => Number(b.shortage_probability) - Number(a.shortage_probability))
            .slice(0, 10);

        new Chart(topRiskCanvas, {
            type: 'bar',
            data: {
                labels: topRiskItems.map(item => item.item_name),
                datasets: [{
                    label: 'Stockout Risk (%)',
                    data: topRiskItems.map(item => Math.round((Number(item.shortage_probability) || 0) * 1000) / 10),
                    backgroundColor: topRiskItems.map(item => {
                        const risk = getRiskLevel(item.shortage_probability);
                        return risk === 'high'
                            ? '#dc3545'
                            : (risk === 'moderate' ? '#ffc107' : '#28a745');
                    }),
                    borderRadius: 7,
                    borderSkipped: false
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: value => value + '%'
                        },
                        grid: {
                            color: '#edf0f5'
                        }
                    },
                    y: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            autoSkip: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Risk: ' + context.raw.toFixed(1) + '%';
                            }
                        }
                    }
                }
            }
        });
    }
}

function initializeForecastTable() {
    const rows = Array.from(document.querySelectorAll('#forecastTableBody .forecast-row'));
    const searchInput = document.getElementById('forecastSearch');
    const riskFilter = document.getElementById('riskFilter');
    const stockFilter = document.getElementById('stockFilter');
    const pageSizeSelect = document.getElementById('pageSize');
    const pagination = document.getElementById('forecastPagination');
    const summary = document.getElementById('paginationSummary');
    const emptyRow = document.getElementById('filteredEmptyRow');

    if (
        rows.length === 0 ||
        !searchInput ||
        !riskFilter ||
        !stockFilter ||
        !pageSizeSelect ||
        !pagination ||
        !summary
    ) {
        return;
    }

    let currentPage = 1;

    function filteredRows() {
        const searchTerm = searchInput.value.trim().toLowerCase();
        const selectedRisk = riskFilter.value;
        const selectedStock = stockFilter.value;

        return rows.filter(row => {
            const matchesSearch =
                searchTerm === '' ||
                (row.dataset.item || '').includes(searchTerm);

            const matchesRisk =
                selectedRisk === 'all' ||
                row.dataset.risk === selectedRisk;

            const matchesStock =
                selectedStock === 'all' ||
                row.dataset.stockStatus === selectedStock;

            return matchesSearch && matchesRisk && matchesStock;
        });
    }

    function createPageButton(label, page, disabled = false, active = false) {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = label;
        button.disabled = disabled;

        if (active) {
            button.classList.add('active');
        }

        button.addEventListener('click', function() {
            if (disabled || currentPage === page) {
                return;
            }

            currentPage = page;
            render();
        });

        return button;
    }

    function renderPagination(totalPages) {
        pagination.innerHTML = '';

        pagination.appendChild(
            createPageButton(
                '‹',
                Math.max(1, currentPage - 1),
                currentPage === 1
            )
        );

        let startPage = Math.max(1, currentPage - 2);
        let endPage = Math.min(totalPages, startPage + 4);

        if (endPage - startPage < 4) {
            startPage = Math.max(1, endPage - 4);
        }

        for (let page = startPage; page <= endPage; page++) {
            pagination.appendChild(
                createPageButton(
                    String(page),
                    page,
                    false,
                    page === currentPage
                )
            );
        }

        pagination.appendChild(
            createPageButton(
                '›',
                Math.min(totalPages, currentPage + 1),
                currentPage === totalPages
            )
        );
    }

    function render() {
        const matches = filteredRows();
        const pageSize = Math.max(1, Number(pageSizeSelect.value) || 10);
        const totalPages = Math.max(1, Math.ceil(matches.length / pageSize));

        if (currentPage > totalPages) {
            currentPage = totalPages;
        }

        const startIndex = (currentPage - 1) * pageSize;
        const endIndex = Math.min(startIndex + pageSize, matches.length);
        const visibleRows = new Set(matches.slice(startIndex, endIndex));

        rows.forEach(row => {
            row.style.display = visibleRows.has(row) ? '' : 'none';
        });

        if (emptyRow) {
            emptyRow.style.display = matches.length === 0 ? '' : 'none';
        }

        if (matches.length === 0) {
            summary.textContent = '0 forecasts match the selected filters';
            pagination.innerHTML = '';
            return;
        }

        summary.textContent =
            `Showing ${startIndex + 1}-${endIndex} of ${matches.length} forecast${matches.length === 1 ? '' : 's'}`;

        renderPagination(totalPages);
    }

    function resetToFirstPageAndRender() {
        currentPage = 1;
        render();
    }

    searchInput.addEventListener('input', resetToFirstPageAndRender);
    riskFilter.addEventListener('change', resetToFirstPageAndRender);
    stockFilter.addEventListener('change', resetToFirstPageAndRender);
    pageSizeSelect.addEventListener('change', resetToFirstPageAndRender);

    render();
}

document.addEventListener('DOMContentLoaded', function() {
    initializeForecastCharts();
    initializeForecastTable();
});

// ============================================
// TOAST NOTIFICATIONS
// ============================================

function showToast(message, type = 'success') {

    const container =
        document.getElementById('toastContainer');

    if (!container) {
        return;
    }

    const toast =
        document.createElement('div');

    toast.className =
        `toast-custom ${type}`;

    const iconMap = {
        success: 'bi-check-circle',
        error: 'bi-x-circle',
        warning: 'bi-exclamation-circle',
        info: 'bi-info-circle'
    };

    const icon =
        iconMap[type] || 'bi-info-circle';

    toast.innerHTML = `
        <span class="toast-icon">
            <i class="bi ${icon}"></i>
        </span>

        <span class="toast-msg">
            ${message}
        </span>
    `;

    container.appendChild(toast);

    /* Automatically disappear after 4 seconds */
    setTimeout(function () {

        if (!toast.parentElement) {
            return;
        }

        toast.style.animation =
            'toastFadeOut .3s ease forwards';

        setTimeout(function () {
            if (toast.parentElement) {
                toast.remove();
            }
        }, 300);

    }, 4000);
}

<?php if (isset($forecast_message) && !empty($forecast_message)): ?>
    showToast(
        <?php echo json_encode($forecast_message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
        <?php echo json_encode($forecast_message_type); ?>
    );
<?php endif; ?>
</script>
</body>
</html>
