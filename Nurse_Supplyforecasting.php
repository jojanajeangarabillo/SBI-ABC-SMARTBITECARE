<?php
session_start();

require_once 'sources/db_connect.php';
require_once 'sources/workflow_helpers.php';
require_once 'sources/notification_helper.php';

/* =========================================================
   ACCESS / CURRENT NURSE
   ========================================================= */
$user_id = (int)($_SESSION['user_id'] ?? 0);
$notification_count = getUnreadNotificationCount($conn, $user_id);

$user = workflowRequireUser($conn, 3); // Nurse
$branchId = (string)$user['branch_id'];
$username = (string)($user['username'] ?? 'Nurse');
$branchName = (string)($user['branch_name'] ?? $branchId);

/* =========================================================
   HELPERS
   ========================================================= */
function nurseForecastRiskClass(float $probability): string
{
    if ($probability >= 0.80) return 'danger';
    if ($probability >= 0.60) return 'warning';
    return 'success';
}

function nurseForecastRiskLabel(float $probability): string
{
    if ($probability >= 0.80) return 'High Risk';
    if ($probability >= 0.60) return 'Moderate Risk';
    return 'Low Risk';
}

function nurseStockStatusClass(string $status): string
{
    return match (strtoupper(trim($status))) {
        'OUT OF STOCK' => 'danger',
        'LOW STOCK' => 'warning',
        default => 'success'
    };
}

/* =========================================================
   FORECAST HORIZON
   ========================================================= */
$allowedForecastDays = [7, 14, 30];
$forecastDays = isset($_GET['days']) ? (int)$_GET['days'] : 30;

if (!in_array($forecastDays, $allowedForecastDays, true)) {
    $forecastDays = 30;
}

/* =========================================================
   LATEST VALID / NON-STALE FORECAST DATE
   ========================================================= */
$latestDate = null;

$latestStmt = $conn->prepare(
    "SELECT MAX(forecast_date) AS latest_date
     FROM forecast_results
     WHERE branch_id = ?
       AND forecast_days = ?
       AND is_stale = 0"
);

if ($latestStmt) {
    $latestStmt->bind_param('si', $branchId, $forecastDays);
    $latestStmt->execute();
    $latestDate = $latestStmt->get_result()->fetch_assoc()['latest_date'] ?? null;
    $latestStmt->close();
}

/* =========================================================
   FORECAST RESULTS
   Use the stock snapshot saved with the forecast so the Nurse
   never sees live stock mixed with an older risk calculation.
   ========================================================= */
$forecasts = [];

if ($latestDate !== null) {
    $forecastStmt = $conn->prepare(
        "SELECT
            fr.item_id,
            fr.forecast_date,
            fr.forecast_start_date,
            fr.forecast_end_date,
            fr.current_stock_snapshot,
            fr.minimum_stock_snapshot,
            fr.stock_status,
            fr.shortage_probability,
            fr.forecast_status,
            fr.recommended_reorder,
            fr.forecasted_consumption,
            fr.forecast_days,
            i.item_name,
            u.unit_name
         FROM forecast_results fr
         INNER JOIN inventory_items i
            ON i.item_id = fr.item_id
         LEFT JOIN units u
            ON u.unit_id = i.unit_id
         WHERE fr.branch_id = ?
           AND fr.forecast_date = ?
           AND fr.forecast_days = ?
           AND fr.is_stale = 0
         ORDER BY
            fr.shortage_probability DESC,
            fr.recommended_reorder DESC,
            i.item_name ASC"
    );

    if ($forecastStmt) {
        $forecastStmt->bind_param('ssi', $branchId, $latestDate, $forecastDays);
        $forecastStmt->execute();
        $forecasts = $forecastStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $forecastStmt->close();
    }
}

/* =========================================================
   SUMMARY
   ========================================================= */
$totalItems = count($forecasts);
$highRiskCount = 0;
$moderateRiskCount = 0;
$lowRiskCount = 0;

foreach ($forecasts as &$forecast) {
    $probability = max(0.0, min(1.0, (float)$forecast['shortage_probability']));

    if ($probability >= 0.80) {
        $highRiskCount++;
    } elseif ($probability >= 0.60) {
        $moderateRiskCount++;
    } else {
        $lowRiskCount++;
    }

    $forecast['risk_class'] = nurseForecastRiskClass($probability);
    $forecast['risk_label'] = nurseForecastRiskLabel($probability);
    $forecast['risk_percentage'] = round($probability * 100, 1);
    $forecast['current_stock'] = (float)($forecast['current_stock_snapshot'] ?? 0);
    $forecast['minimum_stock'] = (float)($forecast['minimum_stock_snapshot'] ?? 0);
    $forecast['stock_status'] = (string)($forecast['stock_status'] ?? 'SUFFICIENT STOCK');
    $forecast['stock_status_class'] = nurseStockStatusClass($forecast['stock_status']);
}
unset($forecast);

$forecastStartDate = $forecasts[0]['forecast_start_date'] ?? null;
$forecastEndDate = $forecasts[0]['forecast_end_date'] ?? null;

$chartForecasts = array_slice($forecasts, 0, 10);

$chartLabels = array_map(
    fn($row) => (string)$row['item_name'],
    $chartForecasts
);

$chartRiskValues = array_map(
    fn($row) => (float)$row['risk_percentage'],
    $chartForecasts
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Supply Forecasting - Smart Bite Care</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sidebar.css">

    <style>
        :root {
            --primary: #2B3A8C;
            --primary-dark: #1f2d6e;
            --primary-soft: #eef1ff;
            --success: #28a745;
            --success-soft: #e8f7ef;
            --warning: #e4a300;
            --warning-soft: #fff4d6;
            --danger: #dc3545;
            --danger-soft: #feeceb;
            --text: #1f2a44;
            --muted: #6f7b91;
            --border: #e6eaf2;
            --surface: #ffffff;
            --page: #f7f9fd;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--page);
            color: var(--text);
            font-family: 'Segoe UI', Roboto, system-ui, sans-serif;
        }

        .main {
            min-height: 100vh;
            margin-left: 260px;
        }

        .topbar {
            height: 80px;
            padding: 0 35px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #fff;
            border-bottom: 1px solid #e9edf5;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
        }

        .topbar h3 {
            margin: 0;
            color: var(--primary);
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -.3px;
        }

        .topbar h3 small {
            margin-left: 10px;
            color: #6c757d;
            font-size: 15px;
            font-weight: 400;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 7px;
            color: var(--primary);
            font-weight: 600;
        }

        .profile-role {
            margin-left: 3px;
            color: #adb5bd;
            font-size: 12px;
            font-weight: 400;
        }

        .content {
            padding: 32px 35px 42px;
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

/* Hide elements that are not part of the logout confirmation */
#logoutConfirmModal .confirmation-summary,
#logoutConfirmModal .confirmation-warning {
    display: none !important;
}

@media (max-width: 576px) {
    .confirm-modal .modal-dialog {
        width: calc(100% - 20px);
        margin: 10px auto;
    }

    .confirm-modal .modal-header {
        padding: 22px 18px 6px !important;
    }

    .confirm-modal .modal-icon {
        width: 58px !important;
        height: 58px !important;
        margin-bottom: 12px !important;
        font-size: 24px !important;
    }

    .confirm-modal .modal-title {
        font-size: 21px !important;
    }

    .confirm-modal .modal-body {
        padding: 6px 20px 18px !important;
    }

    .confirm-modal .modal-body p {
        font-size: 15px !important;
    }

    .confirm-modal .modal-footer {
        padding: 0 18px 20px !important;
    }

    .confirm-modal .modal-footer .btn {
        min-height: 46px !important;
        font-size: 15px !important;
    }
}

        /* -----------------------------------------
           Read-only notice
           ----------------------------------------- */
        .readonly-notice {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 15px 18px;
            margin-bottom: 20px;
            background: var(--primary-soft);
            border: 1px solid #dce3fb;
            border-radius: 14px;
        }

        .readonly-notice i {
            color: var(--primary);
            font-size: 21px;
            margin-top: 1px;
        }

        .readonly-notice strong {
            display: block;
            color: var(--primary);
            font-size: 14px;
        }

        .readonly-notice p {
            margin: 2px 0 0;
            color: #64708b;
            font-size: 12.5px;
        }

        /* -----------------------------------------
           Horizon selector
           ----------------------------------------- */
        .horizon-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 22px;
            padding: 17px 19px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 3px 10px rgba(43,58,140,.05);
        }

        .horizon-bar strong {
            display: block;
            color: var(--primary);
            font-size: 14px;
        }

        .horizon-bar small {
            color: var(--muted);
            font-size: 12px;
        }

        .horizon-options {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .horizon-options .btn {
            min-width: 86px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
        }

        /* -----------------------------------------
           Summary cards
           ----------------------------------------- */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0,1fr));
            gap: 18px;
            margin-bottom: 24px;
        }

        .stat-card {
            min-height: 112px;
            padding: 18px 20px;
            display: grid;
            grid-template-columns: 44px 1fr;
            grid-template-rows: auto auto;
            column-gap: 12px;
            align-items: center;
            background: #fff;
            border: 0;
            border-left: 5px solid var(--primary);
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,.07);
        }

        .stat-card.danger {
            border-left-color: var(--danger);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
        }

        .stat-card.success {
            border-left-color: var(--success);
        }

        .stat-icon {
            grid-row: 1/3;
            color: var(--primary);
            font-size: 29px;
        }

        .stat-card.danger .stat-icon {
            color: var(--danger);
        }

        .stat-card.warning .stat-icon {
            color: var(--warning);
        }

        .stat-card.success .stat-icon {
            color: var(--success);
        }

        .stat-label {
            color: #526078;
            font-size: 13px;
            font-weight: 600;
        }

        .stat-value {
            color: #111827;
            font-size: 28px;
            font-weight: 700;
            line-height: 1.05;
        }

        /* -----------------------------------------
           Cards
           ----------------------------------------- */
        .content-card {
            overflow: hidden;
            margin-bottom: 24px;
            background: #fff;
            border: 0;
            border-radius: 18px;
            box-shadow: 0 3px 10px rgba(0,0,0,.07);
        }

        .content-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 20px 24px;
            border-bottom: 1px solid #edf0f5;
        }

        .content-card-header h2 {
            display: flex;
            align-items: center;
            gap: 9px;
            margin: 0;
            color: var(--primary);
            font-size: 18px;
            font-weight: 700;
        }

        .content-card-header p {
            margin: 5px 0 0;
            color: var(--muted);
            font-size: 12.5px;
        }

        .section-icon {
            width: 35px;
            height: 35px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            background: var(--primary);
            border-radius: 9px;
        }

        .period-pill {
            padding: 7px 12px;
            color: var(--primary);
            background: var(--primary-soft);
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        /* -----------------------------------------
           Visual overview
           ----------------------------------------- */
        .visual-grid {
            display: grid;
            grid-template-columns: minmax(260px, .8fr) minmax(420px, 1.7fr);
            gap: 20px;
            padding: 22px 24px 24px;
        }

        .chart-panel {
            min-height: 320px;
            padding: 17px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: #fff;
        }

        .chart-panel h6 {
            margin: 0 0 3px;
            color: #25345d;
            font-size: 13px;
            font-weight: 700;
        }

        .chart-panel p {
            margin: 0 0 14px;
            color: var(--muted);
            font-size: 11.5px;
        }

        .chart-canvas-wrap {
            position: relative;
            height: 245px;
        }

        /* -----------------------------------------
           Filter toolbar
           ----------------------------------------- */
        .table-toolbar {
            display: grid;
            grid-template-columns: minmax(220px,1.6fr) minmax(150px,.75fr) minmax(165px,.85fr) auto;
            gap: 10px;
            padding: 16px 20px;
            background: #fbfcff;
            border-bottom: 1px solid var(--border);
        }

        .control-wrap {
            position: relative;
        }

        .control-wrap > i {
            position: absolute;
            top: 50%;
            left: 12px;
            transform: translateY(-50%);
            color: #8b95aa;
            pointer-events: none;
        }

        .control-wrap .form-control {
            padding-left: 36px;
        }

        .form-control,
        .form-select {
            min-height: 40px;
            border-color: #dfe4ed;
            border-radius: 10px;
            font-size: 12px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #9ba8df;
            box-shadow: 0 0 0 .18rem rgba(43,58,140,.10);
        }

        .rows-select {
            display: flex;
            align-items: center;
            gap: 7px;
            white-space: nowrap;
        }

        .rows-select label {
            color: var(--muted);
            font-size: 11.5px;
        }

        .rows-select .form-select {
            width: 82px;
        }

        /* -----------------------------------------
           Forecast table
           ----------------------------------------- */
        .table-responsive {
            max-height: none;
        }

        .forecast-table {
            min-width: 1180px;
            margin: 0;
        }

        .forecast-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            padding: 13px 16px;
            color: #5f6b82;
            background: #f7f8fc;
            border-bottom: 1px solid var(--border);
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: .28px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .forecast-table tbody td {
            padding: 14px 16px;
            color: #34405d;
            border-color: #edf0f5;
            font-size: 12.5px;
            vertical-align: middle;
        }

        .forecast-table tbody tr:hover {
            background: #fafbff;
        }

        .item-name {
            display: block;
            color: var(--primary);
            font-weight: 700;
        }

        .item-unit {
            color: var(--muted);
            font-size: 10.5px;
        }

        .stock-badge,
        .risk-badge {
            display: inline-block;
            min-width: 88px;
            padding: 5px 9px;
            text-align: center;
            border-radius: 999px;
            font-size: 10.5px;
            font-weight: 700;
        }

        .stock-badge.danger,
        .risk-badge.danger {
            color: #b42318;
            background: var(--danger-soft);
        }

        .stock-badge.warning,
        .risk-badge.warning {
            color: #8a6200;
            background: var(--warning-soft);
        }

        .stock-badge.success,
        .risk-badge.success {
            color: #18794e;
            background: var(--success-soft);
        }

        .probability {
            min-width: 125px;
        }

        .probability-top {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            color: #4d5870;
            font-size: 11px;
            font-weight: 700;
        }

        .probability-track {
            height: 6px;
            overflow: hidden;
            background: #edf0f5;
            border-radius: 999px;
        }

        .probability-fill {
            height: 100%;
            border-radius: inherit;
        }

        .probability-fill.danger {
            background: var(--danger);
        }

        .probability-fill.warning {
            background: var(--warning);
        }

        .probability-fill.success {
            background: var(--success);
        }

        .reorder-value {
            color: var(--primary);
            font-weight: 700;
        }

        .no-reorder {
            color: var(--success);
            font-weight: 650;
        }

        .empty-state {
            padding: 52px 20px !important;
            color: #8a94a6 !important;
            text-align: center;
        }

        .empty-state i {
            display: block;
            margin-bottom: 8px;
            color: #b0b8ca;
            font-size: 40px;
        }

        /* -----------------------------------------
           Pagination
           ----------------------------------------- */
        .table-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
            padding: 14px 20px;
            border-top: 1px solid var(--border);
            background: #fff;
        }

        .results-text {
            color: var(--muted);
            font-size: 11.5px;
        }

        .pagination {
            margin: 0;
        }

        .page-link {
            min-width: 34px;
            color: var(--primary);
            border-color: #e0e5ef;
            font-size: 11px;
            text-align: center;
        }

        .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
        }

        .page-item.disabled .page-link {
            color: #adb5bd;
        }

        /* -----------------------------------------
           How to read
           ----------------------------------------- */
        .reading-grid {
            display: grid;
            grid-template-columns: repeat(3,minmax(0,1fr));
            gap: 12px;
            padding: 20px 24px 24px;
        }

        .reading-item {
            padding: 14px 15px;
            background: #fafbff;
            border: 1px solid var(--border);
            border-radius: 12px;
        }

        .reading-item strong {
            display: block;
            margin-bottom: 4px;
            color: #263866;
            font-size: 12px;
        }

        .reading-item span {
            color: var(--muted);
            font-size: 11px;
            line-height: 1.45;
        }

        @media (max-width: 1199px) {
            .stats-grid {
                grid-template-columns: repeat(2,minmax(0,1fr));
            }

            .visual-grid {
                grid-template-columns: 1fr;
            }

            .table-toolbar {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 991px) {
            .main {
                margin-left: 90px;
            }

            .topbar {
                padding: 0 22px;
            }

            .content {
                padding: 28px 22px 35px;
            }

            .topbar h3 small,
            .profile-role {
                display: none;
            }

            .reading-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 767px) {
            .topbar {
                height: 70px;
                padding: 0 16px;
            }

            .topbar h3 {
                font-size: 20px;
            }

            .content {
                padding: 20px 14px 30px;
            }

            .stats-grid,
            .table-toolbar {
                grid-template-columns: 1fr;
            }

            .content-card-header {
                align-items: flex-start;
                padding: 17px;
                flex-direction: column;
            }

            .visual-grid {
                padding: 16px;
            }

            .table-footer {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        @media (max-width: 520px) {
            .profile span {
                display: none;
            }
        }
    </style>
</head>
<body>

<!-- =========================================================
     SIDEBAR
     ========================================================= -->
<aside class="sidebar">
    <div class="logo-area">
        <div class="logo-frame">
            <img src="logo.png" alt="Smart Bite Care Logo" class="logo">
        </div>
        <div class="system-name">Smart Bite Care</div>
    </div>

    <nav class="nav-menu" aria-label="Nurse navigation">
        <ul>
            <li>
                <a href="Nurse_Dashboard.php">
                    <i class="bi bi-grid-fill"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <li>
                <a href="Nurse_Calendar.php">
                    <i class="bi bi-calendar3"></i>
                    <span>Calendar</span>
                </a>
            </li>

            <li>
                <a href="Nurse_Patients.php">
                    <i class="bi bi-heart-pulse-fill"></i>
                    <span>Patients</span>
                </a>
            </li>

            <li>
                <a href="Nurse_Assessment.php">
                    <i class="bi bi-clipboard2-pulse-fill"></i>
                    <span>Assessment Queue</span>
                </a>
            </li>

            <li>
                <a href="Nurse_Vaccination.php">
                    <i class="bi bi-shield-plus"></i>
                    <span>Vaccination</span>
                </a>
            </li>

            <li>
                <a href="Nurse_DailyInventory.php">
                    <i class="bi bi-clipboard-data-fill"></i>
                    <span>Daily Inventory</span>
                </a>
            </li>

            <li>
                <a href="Nurse_MedicalSuppliesManagement.php">
                    <i class="bi bi-calendar-check"></i>
                    <span>Medical Supplies Management</span>
                </a>
            </li>

            <li>
                <a class="active" href="Nurse_Supplyforecasting.php" aria-current="page">
                    <i class="bi bi-graph-up-arrow"></i>
                    <span>Supply Forecasting</span>
                </a>
            </li>

            <li>
                <a href="Nurse_Notification.php">
                    <i class="bi bi-bell-fill"></i>
                    <span class="notification-label">
                        Notifications
                        <?php if ($notification_count > 0): ?>
                            <span class="notification-badge">
                                <?php echo $notification_count; ?>
                            </span>
                        <?php endif; ?>
                    </span>
                </a>
            </li>
        </ul>
    </nav>
</aside>

<!-- =========================================================
     MAIN
     ========================================================= -->
<main class="main">

    <div class="topbar">
        <h3>
            Supply Forecasting
            <small><?= workflowH($branchName) ?></small>
        </h3>

        <div class="dropdown">
            <button
                class="profile dropdown-toggle border-0 bg-transparent px-3 py-2 rounded-3"
                type="button"
                id="nurseProfileMenu"
                data-bs-toggle="dropdown"
                aria-expanded="false"
            >
                <i class="bi bi-person-circle"></i>
                <span><?= workflowH($username) ?></span>
                <span class="profile-role">| Nurse</span>
            </button>

            <ul
                class="dropdown-menu dropdown-menu-end border-0 shadow p-2 mt-2"
                aria-labelledby="nurseProfileMenu"
            >
                <li>
                    <h6 class="dropdown-header">Account options</h6>
                </li>

                <li>
                    <a class="dropdown-item rounded-2 py-2" href="Account_ChangePassword.php">
                        <i class="bi bi-key-fill me-2"></i>
                        Change Password
                    </a>
                </li>

                <li>
                    <hr class="dropdown-divider">
                </li>

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

    <div class="content">

        <!-- Read-only note -->
        <div class="readonly-notice">
            <i class="bi bi-info-circle-fill"></i>
            <div>
                <strong>Read-only forecast view</strong>
                <p>
                    Forecasts are generated by the Branch Admin. Nurses can review current supply risk,
                    forecasted consumption, and recommended reorder quantities for clinical planning.
                </p>
            </div>
        </div>

        <!-- Horizon -->
        <div class="horizon-bar">
            <div>
                <strong>
                    <i class="bi bi-calendar-range me-1"></i>
                    Forecast Horizon
                </strong>
                <small>
                    View the latest 7-day, 14-day, or 30-day forecast for your branch.
                </small>
            </div>

            <div class="horizon-options" role="group" aria-label="Forecast horizon">
                <?php foreach ($allowedForecastDays as $daysOption): ?>
                    <a
                        href="Nurse_Supplyforecasting.php?days=<?= $daysOption ?>"
                        class="btn <?= $forecastDays === $daysOption ? 'btn-primary' : 'btn-outline-primary' ?>"
                    >
                        <?= $daysOption ?> Days
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Summary -->
        <section class="stats-grid" aria-label="Forecast summary">

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="bi bi-boxes"></i>
                </div>
                <div class="stat-label">Forecasted Items</div>
                <div class="stat-value"><?= number_format($totalItems) ?></div>
            </div>

            <div class="stat-card danger">
                <div class="stat-icon">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
                <div class="stat-label">High Risk</div>
                <div class="stat-value"><?= number_format($highRiskCount) ?></div>
            </div>

            <div class="stat-card warning">
                <div class="stat-icon">
                    <i class="bi bi-exclamation-circle-fill"></i>
                </div>
                <div class="stat-label">Moderate Risk</div>
                <div class="stat-value"><?= number_format($moderateRiskCount) ?></div>
            </div>

            <div class="stat-card success">
                <div class="stat-icon">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <div class="stat-label">Low Risk</div>
                <div class="stat-value"><?= number_format($lowRiskCount) ?></div>
            </div>

        </section>

        <?php if ($forecasts): ?>

            <!-- Visual Overview -->
            <section class="content-card">
                <div class="content-card-header">
                    <div>
                        <h2>
                            <span class="section-icon">
                                <i class="bi bi-bar-chart-fill"></i>
                            </span>
                            Forecast Overview
                        </h2>
                        <p>
                            Quick visual summary of risk levels and the supplies with the highest stockout risk.
                        </p>
                    </div>

                    <span class="period-pill">
                        <i class="bi bi-calendar-range me-1"></i>
                        <?= $forecastDays ?>-Day Forecast
                    </span>
                </div>

                <div class="visual-grid">

                    <div class="chart-panel">
                        <h6>Risk Distribution</h6>
                        <p>How many forecasted items fall into each risk level.</p>

                        <div class="chart-canvas-wrap">
                            <canvas id="riskDistributionChart"></canvas>
                        </div>
                    </div>

                    <div class="chart-panel">
                        <h6>Highest Stockout Risks</h6>
                        <p>Top <?= count($chartForecasts) ?> items ordered by stockout risk.</p>

                        <div class="chart-canvas-wrap">
                            <canvas id="topRiskChart"></canvas>
                        </div>
                    </div>

                </div>
            </section>

        <?php endif; ?>

        <!-- Forecast Results -->
        <section class="content-card">

            <div class="content-card-header">
                <div>
                    <h2>
                        <span class="section-icon">
                            <i class="bi bi-clipboard-data-fill"></i>
                        </span>
                        Latest Supply Forecast
                    </h2>

                    <p>
                        <?php if ($latestDate && $forecastStartDate && $forecastEndDate): ?>

                            Forecast period
                            <?= workflowH(date('F j, Y', strtotime($forecastStartDate))) ?>
                            to
                            <?= workflowH(date('F j, Y', strtotime($forecastEndDate))) ?>
                            · Generated
                            <?= workflowH(date('F j, Y', strtotime($latestDate))) ?>

                        <?php elseif ($latestDate): ?>

                            Generated on
                            <?= workflowH(date('F j, Y', strtotime($latestDate))) ?>

                        <?php else: ?>

                            No <?= $forecastDays ?>-day forecast has been generated for this branch yet.

                        <?php endif; ?>
                    </p>
                </div>

                <span class="period-pill">
                    <i class="bi bi-calendar-check me-1"></i>
                    Next <?= $forecastDays ?> Days
                </span>
            </div>

            <?php if ($forecasts): ?>

                <!-- Search / filters / rows -->
                <div class="table-toolbar">

                    <div class="control-wrap">
                        <i class="bi bi-search"></i>
                        <input
                            type="search"
                            id="forecastSearch"
                            class="form-control"
                            placeholder="Search supply item..."
                            autocomplete="off"
                        >
                    </div>

                    <select id="riskFilter" class="form-select" aria-label="Filter by risk">
                        <option value="">All Risk Levels</option>
                        <option value="high risk">High Risk</option>
                        <option value="moderate risk">Moderate Risk</option>
                        <option value="low risk">Low Risk</option>
                    </select>

                    <select id="stockFilter" class="form-select" aria-label="Filter by stock status">
                        <option value="">All Stock Status</option>
                        <option value="out of stock">Out of Stock</option>
                        <option value="low stock">Low Stock</option>
                        <option value="sufficient stock">Sufficient Stock</option>
                    </select>

                    <div class="rows-select">
                        <label for="rowsPerPage">Rows</label>

                        <select id="rowsPerPage" class="form-select">
                            <option value="10" selected>10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>

                </div>

            <?php endif; ?>

            <div class="table-responsive">

                <table class="table forecast-table align-middle" id="forecastTable">

                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Stock at Forecast</th>
                            <th>Minimum Stock</th>
                            <th>Stock Status</th>
                            <th>Forecasted Use</th>
                            <th>Stockout Risk</th>
                            <th>Risk Level</th>
                            <th>Recommended Reorder</th>
                        </tr>
                    </thead>

                    <tbody id="forecastTableBody">

                    <?php if (!$forecasts): ?>

                        <tr>
                            <td colspan="8" class="empty-state">
                                <i class="bi bi-graph-up"></i>

                                No <?= $forecastDays ?>-day forecasting results are available yet.
                                The Branch Admin forecasting page will generate this horizon when selected.
                            </td>
                        </tr>

                    <?php endif; ?>

                    <?php foreach ($forecasts as $forecast): ?>

                        <?php
                            $probability = max(
                                0.0,
                                min(1.0, (float)$forecast['shortage_probability'])
                            );

                            $percentage = $probability * 100;
                            $riskClass = (string)$forecast['risk_class'];
                            $riskLabel = (string)$forecast['risk_label'];

                            $unit = trim((string)($forecast['unit_name'] ?? ''));
                            if ($unit === '') {
                                $unit = 'unit(s)';
                            }

                            $reorder = max(
                                0,
                                (int)$forecast['recommended_reorder']
                            );

                            $stockStatus = (string)$forecast['stock_status'];
                            $stockStatusClass = (string)$forecast['stock_status_class'];
                        ?>

                        <tr
                            class="forecast-data-row"
                            data-item="<?= workflowH(strtolower((string)$forecast['item_name'])) ?>"
                            data-risk="<?= workflowH(strtolower($riskLabel)) ?>"
                            data-stock="<?= workflowH(strtolower($stockStatus)) ?>"
                        >

                            <td>
                                <span class="item-name">
                                    <?= workflowH((string)$forecast['item_name']) ?>
                                </span>

                                <span class="item-unit">
                                    <?= workflowH($unit) ?>
                                </span>
                            </td>

                            <td>
                                <?= number_format((float)$forecast['current_stock'], 2) ?>
                            </td>

                            <td>
                                <?= number_format((float)$forecast['minimum_stock'], 2) ?>
                            </td>

                            <td>
                                <span class="stock-badge <?= $stockStatusClass ?>">
                                    <?= workflowH($stockStatus) ?>
                                </span>
                            </td>

                            <td>
                                <?= number_format((float)$forecast['forecasted_consumption'], 2) ?>
                                <span class="text-muted">
                                    <?= workflowH($unit) ?>
                                </span>
                            </td>

                            <td>

                                <div class="probability">

                                    <div class="probability-top">
                                        <span><?= number_format($percentage, 1) ?>%</span>
                                    </div>

                                    <div class="probability-track">

                                        <div
                                            class="probability-fill <?= $riskClass ?>"
                                            style="width:<?= min(100, $percentage) ?>%"
                                        ></div>

                                    </div>

                                </div>

                            </td>

                            <td>
                                <span class="risk-badge <?= $riskClass ?>">
                                    <?= workflowH($riskLabel) ?>
                                </span>
                            </td>

                            <td>

                                <?php if ($reorder > 0): ?>

                                    <span class="reorder-value">
                                        Reorder
                                        <?= number_format($reorder) ?>
                                        <?= workflowH($unit) ?>
                                    </span>

                                <?php else: ?>

                                    <span class="no-reorder">
                                        <i class="bi bi-check-circle-fill me-1"></i>
                                        No reorder
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

            <?php if ($forecasts): ?>

                <div class="table-footer">

                    <div class="results-text" id="resultsText">
                        Showing forecast results
                    </div>

                    <nav aria-label="Forecast table pages">
                        <ul class="pagination pagination-sm" id="forecastPagination"></ul>
                    </nav>

                </div>

            <?php endif; ?>

        </section>

        <!-- How to read -->
        <section class="content-card mb-0">

            <div class="content-card-header">
                <div>
                    <h2>
                        <span class="section-icon">
                            <i class="bi bi-info-lg"></i>
                        </span>
                        How to Read the Forecast
                    </h2>

                    <p>
                        Use stock condition and forecast risk together when reviewing supplies.
                    </p>
                </div>
            </div>

            <div class="reading-grid">

                <div class="reading-item">
                    <strong>Stock Status</strong>
                    <span>
                        Shows whether stock was out, below minimum, or sufficient when the forecast was generated.
                    </span>
                </div>

                <div class="reading-item">
                    <strong>Stockout Risk</strong>
                    <span>
                        High = 80%+, Moderate = 60–79.9%, Low = below 60%. This reflects expected future demand.
                    </span>
                </div>

                <div class="reading-item">
                    <strong>Recommended Reorder</strong>
                    <span>
                        Amount suggested to cover forecasted use while restoring the supply toward its minimum stock level.
                    </span>
                </div>

            </div>

        </section>

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

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    /* =========================================================
       CHARTS
       ========================================================= */
    const riskDistributionCanvas =
        document.getElementById('riskDistributionChart');

    if (riskDistributionCanvas && window.Chart) {

        new Chart(riskDistributionCanvas, {
            type: 'doughnut',

            data: {
                labels: ['High Risk', 'Moderate Risk', 'Low Risk'],

                datasets: [{
                    data: [
                        <?= (int)$highRiskCount ?>,
                        <?= (int)$moderateRiskCount ?>,
                        <?= (int)$lowRiskCount ?>
                    ],
                    backgroundColor: [
                        '#dc3545',
                        '#e4a300',
                        '#28a745'
                    ],
                    borderWidth: 0,
                    hoverOffset: 4
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
                            boxWidth: 8,
                            padding: 16,
                            font: {
                                size: 11
                            }
                        }
                    },

                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return context.label + ': ' + context.raw + ' item(s)';
                            }
                        }
                    }
                }
            }
        });
    }

    const topRiskCanvas =
        document.getElementById('topRiskChart');

    if (topRiskCanvas && window.Chart) {

        new Chart(topRiskCanvas, {
            type: 'bar',

            data: {
                labels: <?= json_encode(
                    $chartLabels,
                    JSON_HEX_TAG |
                    JSON_HEX_APOS |
                    JSON_HEX_AMP |
                    JSON_HEX_QUOT
                ) ?>,

                datasets: [{
                    label: 'Stockout Risk (%)',

                    data: <?= json_encode(
                        $chartRiskValues,
                        JSON_NUMERIC_CHECK
                    ) ?>,

                    backgroundColor: function (context) {

                        const value =
                            Number(context.raw || 0);

                        if (value >= 80) {
                            return '#dc3545';
                        }

                        if (value >= 60) {
                            return '#e4a300';
                        }

                        return '#28a745';
                    },

                    borderRadius: 6,
                    maxBarThickness: 28
                }]
            },

            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,

                scales: {
                    x: {
                        beginAtZero: true,
                        suggestedMax: 100,
                        max: 100,

                        ticks: {
                            callback: function (value) {
                                return value + '%';
                            },

                            font: {
                                size: 10
                            }
                        },

                        grid: {
                            color: '#eef1f6'
                        }
                    },

                    y: {
                        ticks: {
                            font: {
                                size: 10
                            }
                        },

                        grid: {
                            display: false
                        }
                    }
                },

                plugins: {
                    legend: {
                        display: false
                    },

                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return 'Stockout Risk: ' + context.raw + '%';
                            }
                        }
                    }
                }
            }
        });
    }

    /* =========================================================
       TABLE FILTERING + PAGINATION
       ========================================================= */
    const tableRows =
        Array.from(
            document.querySelectorAll(
                '#forecastTableBody .forecast-data-row'
            )
        );

    const searchInput =
        document.getElementById('forecastSearch');

    const riskFilter =
        document.getElementById('riskFilter');

    const stockFilter =
        document.getElementById('stockFilter');

    const rowsPerPageSelect =
        document.getElementById('rowsPerPage');

    const pagination =
        document.getElementById('forecastPagination');

    const resultsText =
        document.getElementById('resultsText');

    if (
        tableRows.length &&
        searchInput &&
        riskFilter &&
        stockFilter &&
        rowsPerPageSelect &&
        pagination &&
        resultsText
    ) {

        let currentPage = 1;

        function getFilteredRows() {

            const search =
                searchInput.value
                    .trim()
                    .toLowerCase();

            const risk =
                riskFilter.value
                    .trim()
                    .toLowerCase();

            const stock =
                stockFilter.value
                    .trim()
                    .toLowerCase();

            return tableRows.filter(function (row) {

                const item =
                    (row.dataset.item || '')
                        .toLowerCase();

                const rowRisk =
                    (row.dataset.risk || '')
                        .toLowerCase();

                const rowStock =
                    (row.dataset.stock || '')
                        .toLowerCase();

                const matchesSearch =
                    search === '' ||
                    item.includes(search);

                const matchesRisk =
                    risk === '' ||
                    rowRisk === risk;

                const matchesStock =
                    stock === '' ||
                    rowStock === stock;

                return (
                    matchesSearch &&
                    matchesRisk &&
                    matchesStock
                );
            });
        }

        function renderPagination(totalPages) {

            pagination.innerHTML = '';

            if (totalPages <= 1) {
                return;
            }

            function addPageItem(
                label,
                page,
                disabled = false,
                active = false
            ) {

                const li =
                    document.createElement('li');

                li.className =
                    'page-item' +
                    (disabled ? ' disabled' : '') +
                    (active ? ' active' : '');

                const button =
                    document.createElement('button');

                button.type = 'button';
                button.className = 'page-link';
                button.innerHTML = label;

                if (!disabled && !active) {

                    button.addEventListener(
                        'click',
                        function () {
                            currentPage = page;
                            renderTable();
                        }
                    );
                }

                li.appendChild(button);
                pagination.appendChild(li);
            }

            addPageItem(
                '<i class="bi bi-chevron-left"></i>',
                currentPage - 1,
                currentPage === 1
            );

            let startPage =
                Math.max(
                    1,
                    currentPage - 2
                );

            let endPage =
                Math.min(
                    totalPages,
                    startPage + 4
                );

            if (endPage - startPage < 4) {
                startPage =
                    Math.max(
                        1,
                        endPage - 4
                    );
            }

            for (
                let page = startPage;
                page <= endPage;
                page++
            ) {

                addPageItem(
                    String(page),
                    page,
                    false,
                    page === currentPage
                );
            }

            addPageItem(
                '<i class="bi bi-chevron-right"></i>',
                currentPage + 1,
                currentPage === totalPages
            );
        }

        function renderTable() {

            const filteredRows =
                getFilteredRows();

            const rowsPerPage =
                Math.max(
                    1,
                    parseInt(
                        rowsPerPageSelect.value,
                        10
                    ) || 10
                );

            const totalPages =
                Math.max(
                    1,
                    Math.ceil(
                        filteredRows.length /
                        rowsPerPage
                    )
                );

            if (currentPage > totalPages) {
                currentPage = totalPages;
            }

            tableRows.forEach(function (row) {
                row.style.display = 'none';
            });

            const startIndex =
                (currentPage - 1) *
                rowsPerPage;

            const endIndex =
                Math.min(
                    startIndex +
                    rowsPerPage,
                    filteredRows.length
                );

            filteredRows
                .slice(
                    startIndex,
                    endIndex
                )
                .forEach(function (row) {
                    row.style.display = '';
                });

            if (filteredRows.length === 0) {

                resultsText.textContent =
                    'No forecasts match the selected filters.';

            } else {

                resultsText.textContent =
                    'Showing ' +
                    (startIndex + 1) +
                    '–' +
                    endIndex +
                    ' of ' +
                    filteredRows.length +
                    ' forecast' +
                    (filteredRows.length === 1 ? '' : 's');
            }

            renderPagination(
                filteredRows.length === 0
                    ? 0
                    : totalPages
            );
        }

        function resetPageAndRender() {
            currentPage = 1;
            renderTable();
        }

        searchInput.addEventListener(
            'input',
            resetPageAndRender
        );

        riskFilter.addEventListener(
            'change',
            resetPageAndRender
        );

        stockFilter.addEventListener(
            'change',
            resetPageAndRender
        );

        rowsPerPageSelect.addEventListener(
            'change',
            resetPageAndRender
        );

        renderTable();
    }
});
</script>

</body>
</html>
