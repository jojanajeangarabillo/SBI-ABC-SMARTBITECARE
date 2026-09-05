<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/workflow_helpers.php';

$user = workflowRequireUser($conn, 3);
$userId = (int)$user['user_id'];
$branchId = (string)$user['branch_id'];
$csrf = workflowCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        workflowVerifyCsrf();
        $itemId = (int)($_POST['item_id'] ?? 0);
        $inventoryDate = (string)($_POST['inventory_date'] ?? '');
        $beginning = (float)($_POST['beginning_stock'] ?? 0);
        $delivery = (float)($_POST['delivery'] ?? 0);
        $pullOut = (float)($_POST['pull_out'] ?? 0);
        $actual = (float)($_POST['actual_count'] ?? 0);
        $remarks = trim((string)($_POST['remarks'] ?? ''));
        if ($itemId < 1 || DateTime::createFromFormat('Y-m-d', $inventoryDate)?->format('Y-m-d') !== $inventoryDate) {
            throw new RuntimeException('Select a valid item and inventory date.');
        }
        foreach ([$beginning,$delivery,$pullOut,$actual] as $number) {
            if ($number < 0) throw new RuntimeException('Inventory values cannot be negative.');
        }

        $itemCheck = $conn->prepare('SELECT item_name FROM inventory_items WHERE item_id=? AND is_consumable=1 LIMIT 1');
        $itemCheck->bind_param('i', $itemId);
        $itemCheck->execute();
        $item = $itemCheck->get_result()->fetch_assoc();
        $itemCheck->close();
        if (!$item) throw new RuntimeException('Select a consumable inventory item.');

        $usageStmt = $conn->prepare(
            'SELECT COALESCE(SUM(quantity_used),0) AS consumed FROM inventory_usage_history
             WHERE item_id=? AND branch_id=? AND usage_date=?'
        );
        $usageStmt->bind_param('iss', $itemId, $branchId, $inventoryDate);
        $usageStmt->execute();
        $consumed = (float)($usageStmt->get_result()->fetch_assoc()['consumed'] ?? 0);
        $usageStmt->close();
        $computed = $beginning + $delivery - $consumed - $pullOut;
        $variance = $actual - $computed;

        $conn->begin_transaction();
        $stmt = $conn->prepare(
            "INSERT INTO daily_inventory_closings
             (branch_id,item_id,inventory_date,beginning_stock,delivery,consumed,pull_out,
              computed_ending,actual_count,variance,remarks,status,submitted_by,submitted_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,'Submitted',?,NOW())
             ON DUPLICATE KEY UPDATE beginning_stock=VALUES(beginning_stock),delivery=VALUES(delivery),
              consumed=VALUES(consumed),pull_out=VALUES(pull_out),computed_ending=VALUES(computed_ending),
              actual_count=VALUES(actual_count),variance=VALUES(variance),remarks=VALUES(remarks),
              status='Submitted',submitted_by=VALUES(submitted_by),submitted_at=NOW()"
        );
        $stmt->bind_param('sisdddddddsi', $branchId,$itemId,$inventoryDate,$beginning,$delivery,$consumed,$pullOut,$computed,$actual,$variance,$remarks,$userId);
        $stmt->execute();
        $stmt->close();
        workflowAudit($conn,$userId,$branchId,'Submitted daily inventory for '.$item['item_name'].' on '.$inventoryDate,'Daily Inventory');
        $conn->commit();
        workflowFlash('success','Daily inventory submitted. Consumed quantity was calculated from completed treatment records.');
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        workflowFlash('danger',$e->getMessage());
    }
    header('Location: Nurse_DailyInventory.php');
    exit;
}

$items = $conn->query(
    "SELECT i.item_id,i.item_name,i.base_unit_label,i.display_unit_label,i.conversion_to_base,
            COALESCE(SUM(s.quantity_available),0) AS current_stock
     FROM inventory_items i LEFT JOIN inventory_stocks s ON s.item_id=i.item_id AND s.branch_id='".$conn->real_escape_string($branchId)."'
     WHERE i.is_consumable=1 GROUP BY i.item_id ORDER BY i.item_name"
)->fetch_all(MYSQLI_ASSOC);
$stmt = $conn->prepare(
    "SELECT d.*,i.item_name,i.base_unit_label,u.username
     FROM daily_inventory_closings d INNER JOIN inventory_items i ON i.item_id=d.item_id
     INNER JOIN users u ON u.user_id=d.submitted_by
     WHERE d.branch_id=? AND d.inventory_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)
     ORDER BY d.inventory_date DESC,i.item_name"
);
$stmt->bind_param('s',$branchId);$stmt->execute();$closings=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
$flash=workflowTakeFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daily Inventory - Smart Bite Care</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sidebar.css">
    <style>
        :root {
            --primary: #2B3A8C;
            --primary-dark: #1f2d6e;
            --success: #28a745;
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

        .page-intro { display: flex; align-items: center; gap: 12px; margin-bottom: 22px; padding: 15px 18px; color: #314269; background: #edf1ff; border: 1px solid #dce3fb; border-radius: 12px; }
        .page-intro i { color: var(--primary); font-size: 22px; }
        .page-intro strong { display: block; margin-bottom: 2px; color: var(--primary); }
        .page-intro p { margin: 0; color: #64708b; font-size: 13px; }

        .content-card { overflow: hidden; margin-bottom: 24px; background: #fff; border: 0; border-radius: 18px; box-shadow: 0 3px 8px rgba(0,0,0,.08); }
        .content-card-header { display: flex; align-items: center; justify-content: space-between; gap: 15px; padding: 20px 24px; border-bottom: 1px solid #edf0f5; }
        .content-card-header h2 { display: flex; align-items: center; gap: 9px; margin: 0; color: var(--primary); font-size: 19px; font-weight: 700; }
        .content-card-header p { margin: 5px 0 0; color: var(--muted); font-size: 13px; }
        .section-icon { width: 35px; height: 35px; display: inline-flex; align-items: center; justify-content: center; color: #fff; background: var(--primary); border-radius: 9px; }
        .content-card-body { padding: 24px; }

        .form-label { margin-bottom: 6px; color: #48546f; font-size: 13px; font-weight: 650; }
        .required::after { content: ' *'; color: var(--danger); }
        .form-control, .form-select { min-height: 44px; border-color: #d9dfeb; border-radius: 9px; }
        .form-control:focus, .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 .2rem rgba(43,58,140,.12); }
        .small-help { display: flex; align-items: flex-start; gap: 7px; color: var(--muted); font-size: 12px; }
        .btn-primary { min-height: 44px; background: var(--primary); border-color: var(--primary); font-weight: 650; }
        .btn-primary:hover, .btn-primary:focus { background: var(--primary-dark); border-color: var(--primary-dark); }
        .alert { border: 0; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.05); }

        .inventory-table { min-width: 1020px; margin: 0; }
        .inventory-table thead th { padding: 13px 16px; color: #667085; background: #f8f9fc; border-bottom: 1px solid var(--border); font-size: 11px; font-weight: 700; letter-spacing: .25px; text-transform: uppercase; white-space: nowrap; }
        .inventory-table tbody td { padding: 13px 16px; color: #34405d; border-color: #edf0f5; font-size: 13px; vertical-align: middle; }
        .inventory-table tbody tr:hover { background: #fafbff; }
        .item-name { color: var(--primary); font-weight: 650; }
        .variance-badge { display: inline-block; min-width: 62px; padding: 4px 8px; text-align: center; border-radius: 999px; font-weight: 700; }
        .variance-badge.match { color: #198754; background: #e8f7ef; }
        .variance-badge.difference { color: #c0392b; background: #fdebec; }
        .empty { padding: 38px 20px !important; color: #8a94a6 !important; text-align: center; }

        @media (max-width: 991px) {
            .main { margin-left: 90px; }
            .topbar { padding: 0 22px; }
            .content { padding: 28px 22px 35px; }
            .topbar h3 small, .profile-role { display: none; }
        }
        @media (max-width: 767px) {
            .topbar { height: 70px; padding: 0 16px; }
            .topbar h3 { font-size: 20px; }
            .content { padding: 20px 14px 30px; }
            .content-card-header, .content-card-body { padding: 17px; }
            .page-intro { align-items: flex-start; }
        }
        @media (max-width: 520px) { .profile span { display: none; } }
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="logo-area">
        <div class="logo-frame"><img src="logo.png" alt="Smart Bite Care Logo" class="logo"></div>
        <div class="system-name">Smart Bite Care</div>
    </div>
    <nav class="nav-menu" aria-label="Nurse navigation">
        <ul>
            <li><a href="Nurse_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="Nurse_Patients.php"><i class="bi bi-heart-pulse-fill"></i><span>Patients</span></a></li>
            <li><a href="Nurse_Assessment.php"><i class="bi bi-clipboard2-pulse-fill"></i><span>Assessment Queue</span></a></li>
            <li><a href="Nurse_Vaccination.php"><i class="bi bi-shield-plus"></i><span>Vaccination</span></a></li>
            <li><a class="active" href="Nurse_DailyInventory.php" aria-current="page"><i class="bi bi-clipboard-data-fill"></i><span>Daily Inventory</span></a></li>
            <li><a href="Nurse_MedicalSuppliesManagement.php"><i class="bi bi-calendar-check"></i><span>Medical Supplies Management</span></a></li>
            <li><a href="Nurse_Supplyforecasting.php"><i class="bi bi-box-seam"></i><span>Supply Forecasting</span></a></li>
            <li><a href="Nurse_Notification.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
        </ul>
    </nav>
    <div class="logout"><a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></div>
</aside>

<main class="main">
    <div class="topbar">
        <h3>Daily Inventory <small><?= workflowH((string)($user['branch_name'] ?? $branchId)) ?></small></h3>
        <div class="profile"><i class="bi bi-person-circle"></i><span><?= workflowH((string)$user['username']) ?></span><span class="profile-role">| Nurse</span></div>
    </div>

    <div class="content">
        <?php if ($flash): ?>
            <div class="alert alert-<?= workflowH((string)$flash['type']) ?> alert-dismissible fade show" role="alert">
                <?= workflowH((string)$flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="page-intro">
            <i class="bi bi-calculator-fill"></i>
            <div><strong>Daily closing formula</strong><p>Beginning stock + delivery − consumed quantity − pull-out = computed ending stock.</p></div>
        </div>

        <section class="content-card">
            <div class="content-card-header">
                <div><h2><span class="section-icon"><i class="bi bi-clipboard-check-fill"></i></span>Submit End-of-Shift Count</h2><p>Record the physical count for one consumable item.</p></div>
            </div>
            <div class="content-card-body">
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= workflowH($csrf) ?>">
                    <div class="col-xl-4 col-lg-6">
                        <label class="form-label required" for="item_id">Item</label>
                        <select class="form-select" id="item_id" name="item_id" required>
                            <option value="">Select an item</option>
                            <?php foreach ($items as $item): ?>
                                <option value="<?= (int)$item['item_id'] ?>" data-stock="<?= workflowH((string)$item['current_stock']) ?>">
                                    <?= workflowH($item['item_name'].' | Stock: '.$item['current_stock'].' '.($item['base_unit_label'] ?: '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-xl-2 col-lg-3 col-md-6">
                        <label class="form-label required" for="inventory_date">Inventory Date</label>
                        <input type="date" class="form-control" id="inventory_date" name="inventory_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-xl-2 col-lg-3 col-md-6">
                        <label class="form-label required" for="beginning_stock">Beginning Stock</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="beginning_stock" name="beginning_stock" required>
                    </div>
                    <div class="col-xl-2 col-md-6">
                        <label class="form-label" for="delivery">Delivery</label>
                        <input type="number" step="0.01" min="0" value="0" class="form-control" id="delivery" name="delivery">
                    </div>
                    <div class="col-xl-2 col-md-6">
                        <label class="form-label" for="pull_out">Pull-out</label>
                        <input type="number" step="0.01" min="0" value="0" class="form-control" id="pull_out" name="pull_out">
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label required" for="actual_count">Actual Physical Count</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="actual_count" name="actual_count" required>
                    </div>
                    <div class="col-lg-7 col-md-6">
                        <label class="form-label" for="remarks">Remarks</label>
                        <input class="form-control" id="remarks" name="remarks" maxlength="500" placeholder="Explain any variance or pull-out">
                    </div>
                    <div class="col-lg-2 d-flex align-items-end">
                        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-send-check-fill me-1"></i>Submit</button>
                    </div>
                    <div class="col-12 small-help"><i class="bi bi-info-circle-fill"></i><span>Consumed quantity is automatically read from completed vaccination and supply-usage records to prevent double entry.</span></div>
                </form>
            </div>
        </section>

        <section class="content-card mb-0">
            <div class="content-card-header">
                <div><h2><span class="section-icon"><i class="bi bi-clock-history"></i></span>Closing History</h2><p>Submitted daily inventory closings from the last 30 days.</p></div>
            </div>
            <div class="table-responsive">
                <table class="table inventory-table align-middle">
                    <thead><tr><th>Date</th><th>Item</th><th>Beginning</th><th>Delivery</th><th>Consumed</th><th>Pull-out</th><th>Computed</th><th>Actual</th><th>Variance</th><th>Submitted By</th></tr></thead>
                    <tbody>
                    <?php if (!$closings): ?>
                        <tr><td colspan="10" class="empty"><i class="bi bi-inbox me-1"></i>No submitted closing reports in the last 30 days.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($closings as $closing): ?>
                        <?php $hasVariance = abs((float)$closing['variance']) > .009; ?>
                        <tr>
                            <td><?= workflowH(date('M d, Y', strtotime((string)$closing['inventory_date']))) ?></td>
                            <td class="item-name"><?= workflowH((string)$closing['item_name']) ?></td>
                            <td><?= number_format((float)$closing['beginning_stock'], 2) ?></td>
                            <td><?= number_format((float)$closing['delivery'], 2) ?></td>
                            <td><?= number_format((float)$closing['consumed'], 2) ?></td>
                            <td><?= number_format((float)$closing['pull_out'], 2) ?></td>
                            <td><?= number_format((float)$closing['computed_ending'], 2) ?></td>
                            <td><?= number_format((float)$closing['actual_count'], 2) ?></td>
                            <td><span class="variance-badge <?= $hasVariance ? 'difference' : 'match' ?>"><?= number_format((float)$closing['variance'], 2) ?></span></td>
                            <td><?= workflowH((string)$closing['username']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('item_id')?.addEventListener('change', function () {
    const selected = this.options[this.selectedIndex];
    const beginning = document.getElementById('beginning_stock');
    if (selected && selected.dataset.stock !== undefined && beginning && beginning.value === '') {
        beginning.value = Number(selected.dataset.stock).toFixed(2);
    }
});
</script>
</body>
</html>
