<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/workflow_helpers.php';

$user = workflowRequireUser($conn, 3);
$branchId = (string)$user['branch_id'];
$username = (string)($user['username'] ?? 'Nurse');
$branchName = (string)($user['branch_name'] ?? $branchId);

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

$latestDate = null;
$latestStmt = $conn->prepare(
    'SELECT MAX(forecast_date) AS latest_date FROM forecast_results WHERE branch_id=?'
);
$latestStmt->bind_param('s', $branchId);
$latestStmt->execute();
$latestDate = $latestStmt->get_result()->fetch_assoc()['latest_date'] ?? null;
$latestStmt->close();

$forecasts = [];
if ($latestDate !== null) {
    $forecastStmt = $conn->prepare(
        "SELECT fr.item_id,fr.forecast_date,fr.shortage_probability,fr.forecast_status,
                fr.recommended_reorder,fr.forecasted_consumption,fr.forecast_days,
                i.item_name,i.minimum_stock,u.unit_name,
                COALESCE(stock.current_stock,0) AS current_stock
         FROM forecast_results fr
         INNER JOIN inventory_items i ON i.item_id=fr.item_id
         LEFT JOIN units u ON u.unit_id=i.unit_id
         LEFT JOIN (
             SELECT item_id,branch_id,SUM(quantity_available) AS current_stock
             FROM inventory_stocks
             GROUP BY item_id,branch_id
         ) stock ON stock.item_id=fr.item_id AND stock.branch_id=fr.branch_id
         WHERE fr.branch_id=? AND fr.forecast_date=?
         ORDER BY fr.shortage_probability DESC,fr.recommended_reorder DESC,i.item_name"
    );
    $forecastStmt->bind_param('ss', $branchId, $latestDate);
    $forecastStmt->execute();
    $forecasts = $forecastStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $forecastStmt->close();
}

$totalItems = count($forecasts);
$highRiskCount = 0;
$moderateRiskCount = 0;
$totalRecommendedReorder = 0;
$forecastDays = 30;

foreach ($forecasts as $forecast) {
    $probability = (float)$forecast['shortage_probability'];
    if ($probability >= 0.80) {
        $highRiskCount++;
    } elseif ($probability >= 0.60) {
        $moderateRiskCount++;
    }
    $totalRecommendedReorder += max(0, (int)$forecast['recommended_reorder']);
    if ((int)$forecast['forecast_days'] > 0) {
        $forecastDays = (int)$forecast['forecast_days'];
    }
}

$chartForecasts = array_slice($forecasts, 0, 8);
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
            --success: #28a745;
            --warning: #e4a300;
            --danger: #dc3545;
            --text: #1f2a44;
            --muted: #6f7b91;
            --border: #e6eaf2;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f9faff; color: var(--text); font-family: 'Segoe UI', Roboto, system-ui, sans-serif; }
        .main { min-height: 100vh; margin-left: 260px; }
        .topbar { height: 80px; padding: 0 35px; display: flex; align-items: center; justify-content: space-between; background: #fff; border-bottom: 1px solid #e9edf5; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
        .topbar h3 { margin: 0; color: var(--primary); font-size: 28px; font-weight: 700; letter-spacing: -.3px; }
        .topbar h3 small { margin-left: 10px; color: #666; font-size: 16px; font-weight: 400; }
        .profile { display: flex; align-items: center; gap: 6px; color: var(--primary); font-weight: 600; }
        .profile-role { margin-left: 3px; color: #adb5bd; font-size: 12px; font-weight: 400; }
        .content { padding: 35px 35px 40px; }

        .notice { display: flex; align-items: flex-start; gap: 11px; margin-bottom: 23px; padding: 15px 18px; color: #314269; background: #edf1ff; border: 1px solid #dce3fb; border-radius: 12px; }
        .notice i { color: var(--primary); font-size: 21px; }
        .notice strong { display: block; color: var(--primary); }
        .notice p { margin: 2px 0 0; color: #64708b; font-size: 13px; }

        .stats-grid { display: grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap: 18px; margin-bottom: 24px; }
        .stat-card { min-height: 112px; padding: 18px 20px; display: grid; grid-template-columns: 42px 1fr; grid-template-rows: auto auto; column-gap: 12px; align-items: center; background: #fff; border: 0; border-left: 5px solid var(--primary); border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,.08); }
        .stat-card.danger { border-left-color: var(--danger); }.stat-card.warning { border-left-color: var(--warning); }.stat-card.success { border-left-color: var(--success); }
        .stat-icon { grid-row: 1/3; color: var(--primary); font-size: 28px; }.stat-card.danger .stat-icon { color: var(--danger); }.stat-card.warning .stat-icon { color: var(--warning); }.stat-card.success .stat-icon { color: var(--success); }
        .stat-label { color: #526078; font-size: 13px; font-weight: 550; }.stat-value { color: #111827; font-size: 27px; font-weight: 700; line-height: 1.05; }

        .content-card { overflow: hidden; margin-bottom: 24px; background: #fff; border: 0; border-radius: 18px; box-shadow: 0 3px 8px rgba(0,0,0,.08); }
        .content-card-header { display: flex; align-items: center; justify-content: space-between; gap: 18px; padding: 20px 24px; border-bottom: 1px solid #edf0f5; }
        .content-card-header h2 { display: flex; align-items: center; gap: 9px; margin: 0; color: var(--primary); font-size: 19px; font-weight: 700; }
        .content-card-header p { margin: 5px 0 0; color: var(--muted); font-size: 13px; }
        .section-icon { width: 35px; height: 35px; display: inline-flex; align-items: center; justify-content: center; color: #fff; background: var(--primary); border-radius: 9px; }
        .period-pill { padding: 6px 11px; color: var(--primary); background: #edf1ff; border-radius: 999px; font-size: 12px; font-weight: 700; white-space: nowrap; }

        .forecast-table { min-width: 1120px; margin: 0; }
        .forecast-table thead th { padding: 13px 16px; color: #667085; background: #f8f9fc; border-bottom: 1px solid var(--border); font-size: 11px; font-weight: 700; letter-spacing: .25px; text-transform: uppercase; white-space: nowrap; }
        .forecast-table tbody td { padding: 13px 16px; color: #34405d; border-color: #edf0f5; font-size: 13px; vertical-align: middle; }
        .forecast-table tbody tr:hover { background: #fafbff; }
        .item-name { display: block; color: var(--primary); font-weight: 700; }.item-unit { color: var(--muted); font-size: 11px; }
        .risk-badge { display: inline-block; min-width: 91px; padding: 5px 9px; text-align: center; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .risk-badge.danger { color: #b42318; background: #feeceb; }.risk-badge.warning { color: #9a6700; background: #fff4d6; }.risk-badge.success { color: #18794e; background: #e8f7ef; }
        .probability { min-width: 125px; }.probability-top { display: flex; justify-content: space-between; margin-bottom: 5px; color: #4d5870; font-size: 12px; font-weight: 700; }
        .probability-track { height: 6px; overflow: hidden; background: #edf0f5; border-radius: 999px; }.probability-fill { height: 100%; border-radius: inherit; }.probability-fill.danger { background: var(--danger); }.probability-fill.warning { background: var(--warning); }.probability-fill.success { background: var(--success); }
        .reorder-value { color: var(--primary); font-weight: 700; }.no-reorder { color: var(--success); font-weight: 650; }
        .empty-state { padding: 52px 20px !important; color: #8a94a6 !important; text-align: center; }.empty-state i { display: block; margin-bottom: 8px; color: #b0b8ca; font-size: 40px; }

        .chart-body { padding: 24px; }.chart-scroll { overflow-x: auto; }.chart { min-width: 640px; height: 245px; padding: 20px 14px 0; display: flex; align-items: flex-end; gap: 18px; border-bottom: 2px solid #dce1eb; }
        .bar-column { min-width: 58px; height: 100%; flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; }.bar-value { margin-bottom: 5px; color: #46526c; font-size: 11px; font-weight: 700; }
        .bar { width: 44px; min-height: 4px; border-radius: 7px 7px 0 0; }.bar.danger { background: var(--danger); }.bar.warning { background: var(--warning); }.bar.success { background: var(--success); }
        .bar-label { width: 100%; min-height: 46px; padding-top: 7px; color: #39455e; font-size: 10px; font-weight: 650; line-height: 1.2; text-align: center; overflow-wrap: anywhere; }
        .chart-legend { display: flex; flex-wrap: wrap; gap: 18px; margin-top: 14px; color: #59647b; font-size: 12px; }.legend-dot { width: 10px; height: 10px; display: inline-block; margin-right: 5px; border-radius: 50%; }

        @media (max-width: 1199px) { .stats-grid { grid-template-columns: repeat(2,minmax(0,1fr)); } }
        @media (max-width: 991px) { .main { margin-left: 90px; }.topbar { padding: 0 22px; }.content { padding: 28px 22px 35px; }.topbar h3 small,.profile-role { display: none; } }
        @media (max-width: 767px) { .topbar { height: 70px; padding: 0 16px; }.topbar h3 { font-size: 20px; }.content { padding: 20px 14px 30px; }.stats-grid { grid-template-columns: 1fr; }.content-card-header { align-items: flex-start; padding: 17px; flex-direction: column; }.chart-body { padding: 16px; } }
        @media (max-width: 520px) { .profile span { display: none; } }
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="logo-area"><div class="logo-frame"><img src="logo.png" alt="Smart Bite Care Logo" class="logo"></div><div class="system-name">Smart Bite Care</div></div>
    <nav class="nav-menu" aria-label="Nurse navigation"><ul>
        <li><a href="Nurse_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
        <li><a href="Nurse_Patients.php"><i class="bi bi-heart-pulse-fill"></i><span>Patients</span></a></li>
        <li><a href="Nurse_Assessment.php"><i class="bi bi-clipboard2-pulse-fill"></i><span>Assessment Queue</span></a></li>
        <li><a href="Nurse_Vaccination.php"><i class="bi bi-shield-plus"></i><span>Vaccination</span></a></li>
        <li><a href="Nurse_DailyInventory.php"><i class="bi bi-clipboard-data-fill"></i><span>Daily Inventory</span></a></li>
        <li><a href="Nurse_MedicalSuppliesManagement.php"><i class="bi bi-calendar-check"></i><span>Medical Supplies Management</span></a></li>
        <li><a class="active" href="Nurse_Supplyforecasting.php" aria-current="page"><i class="bi bi-box-seam"></i><span>Supply Forecasting</span></a></li>
        <li><a href="Nurse_Notification.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
    </ul></nav>
    <div class="logout"><a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></div>
</aside>

<main class="main">
    <div class="topbar">
        <h3>Supply Forecasting <small><?= workflowH($branchName) ?></small></h3>
        <div class="profile"><i class="bi bi-person-circle"></i><span><?= workflowH($username) ?></span><span class="profile-role">| Nurse</span></div>
    </div>

    <div class="content">
        <div class="notice"><i class="bi bi-info-circle-fill"></i><div><strong>Read-only forecasting view</strong><p>Forecasts are generated automatically by the Branch Admin process. Nurses use these results to monitor possible shortages and support inventory reporting.</p></div></div>

        <section class="stats-grid" aria-label="Forecast summary">
            <div class="stat-card"><div class="stat-icon"><i class="bi bi-boxes"></i></div><div class="stat-label">Forecasted Items</div><div class="stat-value"><?= number_format($totalItems) ?></div></div>
            <div class="stat-card danger"><div class="stat-icon"><i class="bi bi-exclamation-triangle-fill"></i></div><div class="stat-label">High Risk</div><div class="stat-value"><?= number_format($highRiskCount) ?></div></div>
            <div class="stat-card warning"><div class="stat-icon"><i class="bi bi-exclamation-circle-fill"></i></div><div class="stat-label">Moderate Risk</div><div class="stat-value"><?= number_format($moderateRiskCount) ?></div></div>
            <div class="stat-card success"><div class="stat-icon"><i class="bi bi-cart-plus-fill"></i></div><div class="stat-label">Recommended Reorder</div><div class="stat-value"><?= number_format($totalRecommendedReorder) ?></div></div>
        </section>

        <section class="content-card">
            <div class="content-card-header">
                <div><h2><span class="section-icon"><i class="bi bi-graph-up-arrow"></i></span>Latest Supply Forecast</h2><p><?= $latestDate ? 'Generated on '.workflowH(date('F j, Y',strtotime($latestDate))) : 'No forecast has been generated for this branch.' ?></p></div>
                <span class="period-pill"><i class="bi bi-calendar-range me-1"></i>Next <?= $forecastDays ?> Days</span>
            </div>
            <div class="table-responsive">
                <table class="table forecast-table align-middle">
                    <thead><tr><th>Item</th><th>Current Stock</th><th>Minimum Stock</th><th>Forecasted Use</th><th>Shortage Probability</th><th>Risk Level</th><th>Recommended Reorder</th></tr></thead>
                    <tbody>
                    <?php if (!$forecasts): ?>
                        <tr><td colspan="7" class="empty-state"><i class="bi bi-graph-up"></i>No forecasting results are available yet. The Branch Admin forecasting page will generate them automatically.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($forecasts as $forecast): ?>
                        <?php
                            $probability = max(0.0,min(1.0,(float)$forecast['shortage_probability']));
                            $percentage = $probability * 100;
                            $riskClass = nurseForecastRiskClass($probability);
                            $riskLabel = nurseForecastRiskLabel($probability);
                            $unit = trim((string)($forecast['unit_name'] ?? '')) ?: 'unit(s)';
                            $reorder = max(0, (int)$forecast['recommended_reorder']);
                        ?>
                        <tr>
                            <td><span class="item-name"><?= workflowH((string)$forecast['item_name']) ?></span><span class="item-unit"><?= workflowH($unit) ?></span></td>
                            <td><?= number_format((float)$forecast['current_stock'],2) ?></td>
                            <td><?= number_format((float)$forecast['minimum_stock'],2) ?></td>
                            <td><?= number_format((float)$forecast['forecasted_consumption'],2) ?></td>
                            <td><div class="probability"><div class="probability-top"><span><?= number_format($percentage,1) ?>%</span></div><div class="probability-track"><div class="probability-fill <?= $riskClass ?>" style="width:<?= min(100,$percentage) ?>%"></div></div></div></td>
                            <td><span class="risk-badge <?= $riskClass ?>"><?= workflowH($riskLabel) ?></span></td>
                            <td><?php if ($reorder > 0): ?><span class="reorder-value"><?= number_format($reorder).' '.workflowH($unit) ?></span><?php else: ?><span class="no-reorder"><i class="bi bi-check-circle-fill me-1"></i>No reorder</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ($chartForecasts): ?>
            <section class="content-card mb-0">
                <div class="content-card-header"><div><h2><span class="section-icon"><i class="bi bi-bar-chart-fill"></i></span>Shortage Probability</h2><p>Top <?= count($chartForecasts) ?> items with the highest calculated shortage probability.</p></div></div>
                <div class="chart-body"><div class="chart-scroll"><div class="chart">
                    <?php foreach ($chartForecasts as $forecast): ?>
                        <?php $probability=max(0.0,min(1.0,(float)$forecast['shortage_probability']));$percentage=$probability*100;$riskClass=nurseForecastRiskClass($probability); ?>
                        <div class="bar-column"><div class="bar-value"><?= number_format($percentage,1) ?>%</div><div class="bar <?= $riskClass ?>" style="height:<?= max(4,$percentage) ?>%"></div><div class="bar-label"><?= workflowH((string)$forecast['item_name']) ?></div></div>
                    <?php endforeach; ?>
                </div></div><div class="chart-legend"><span><i class="legend-dot" style="background:var(--danger)"></i>High: 80% and above</span><span><i class="legend-dot" style="background:var(--warning)"></i>Moderate: 60–79.9%</span><span><i class="legend-dot" style="background:var(--success)"></i>Low: below 60%</span></div></div>
            </section>
        <?php endif; ?>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
