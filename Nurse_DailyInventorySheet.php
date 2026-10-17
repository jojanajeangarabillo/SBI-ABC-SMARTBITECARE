<?php
session_start();

require_once 'sources/db_connect.php';
require_once 'sources/inventory_stock_helpers.php';
require_once 'sources/daily_inventory_helpers.php';
require_once 'sources/workflow_helpers.php';
require_once 'sources/forecast_training_helper.php';
require_once 'sources/inventory_unit_helpers.php';
require_once 'sources/notification_helper.php';

/*
 * Whole-day Daily Inventory sheet, laid out like the clinic's paper sheet:
 * Beginning, Delivery, Consumed, Pull-out, Inv. End (computed), Actual,
 * Variance and Remarks for every consumable item on one page. Each row is
 * saved with dailyInventorySaveClosing(), the same validation,
 * daily_inventory_closings write and training_dataset sync as the
 * single-item form. Rows without an Actual count are not saved.
 */
$user = workflowRequireUser($conn, 3);
$userId = (int)$user['user_id'];
$branchId = (string)$user['branch_id'];

// A stale session role must not authorize a different current database role.
$roleCheck = $conn->prepare("SELECT user_id FROM users WHERE user_id=? AND role_id=3 AND status='Active'");
$roleCheck->bind_param('i', $userId);
$roleCheck->execute();
$isNurse = (bool)$roleCheck->get_result()->fetch_assoc();
$roleCheck->close();
if (!$isNurse) {
    header('Location: login.php');
    exit;
}

$notification_count = getUnreadNotificationCount($conn, $userId);
$csrf = workflowCsrfToken();

function sheetValidDate(string $date): bool
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date && $date <= date('Y-m-d');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sheetDate = is_string($_POST['inventory_date'] ?? null) ? trim($_POST['inventory_date']) : '';
    $rows = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
    $saved = [];
    $failed = [];
    $restore = [];
    try {
        workflowVerifyCsrf();
        if (!sheetValidDate($sheetDate)) {
            throw new RuntimeException('Select a valid inventory date that is not in the future.');
        }
        foreach ($rows as $rawItemId => $row) {
            if (!is_array($row)) continue;
            $text = static fn($key) => is_string($row[$key] ?? null) ? trim($row[$key]) : '';
            if ($text('actual_count') === '') {
                continue; // Not counted yet: nothing is saved for this item.
            }
            $itemName = $text('item_name');
            try {
                $itemId = dailyInventoryItemId((string)$rawItemId);
                // A saved closing posted back unchanged is skipped (no duplicate audit entry).
                $current = dailyInventoryContext($conn, $branchId, $itemId, $sheetDate);
                if ($current['opening_source'] === 'saved_closing' && !$current['needs_physical_count']
                    && $text('wastage_quantity') === '' && $text('remarks') === trim($current['fields']['remarks'])) {
                    $unchanged = true;
                    foreach (['beginning_stock', 'delivery', 'pull_out', 'actual_count'] as $field) {
                        try {
                            if (stockQuantityParse($text($field) === '' ? '0' : $text($field), true)
                                !== stockQuantityParse((string)$current['fields'][$field], true)) {
                                $unchanged = false;
                            }
                        } catch (InvalidArgumentException $ignored) {
                            $unchanged = false;
                        }
                    }
                    if ($unchanged) continue;
                }
                $result = dailyInventorySaveClosing(
                    $conn,
                    $branchId,
                    $userId,
                    $itemId,
                    $sheetDate,
                    [
                        'beginning_stock' => $text('beginning_stock'),
                        'delivery' => $text('delivery') === '' ? '0' : $text('delivery'),
                        'pull_out' => $text('pull_out') === '' ? '0' : $text('pull_out'),
                        'actual_count' => $text('actual_count'),
                    ],
                    $text('remarks'),
                    $text('context_token'),
                    $text('wastage_quantity'),
                    $text('wastage_reason'),
                    $text('wastage_other')
                );
                $saved[] = (string)$result['item']['item_name'];
            } catch (Throwable $e) {
                $message = $e->getMessage();
                $failed[] = ($itemName !== '' && !str_starts_with($message, $itemName) ? $itemName . ': ' : '') . $message;
                $restore[(string)$rawItemId] = $row;
                if ($e instanceof DailyInventoryStaleContextException) {
                    $restore[(string)$rawItemId]['actual_count'] = '';
                }
            }
        }
        if (!$saved && !$failed) {
            throw new RuntimeException('Nothing new to save. Enter or change the Actual count for at least one item.');
        }
        $message = count($saved) . ' item(s) saved for ' . date('M d, Y', strtotime($sheetDate)) . '.';
        if ($failed) {
            workflowFlash('warning', $message . ' Not saved: ' . implode(' | ', $failed));
        } else {
            workflowFlash('success', $message . ' Consumed came from the Assessment and Vaccination usage of the day (plus any wastage adjustment); forecasting data was updated for forecastable items.');
        }
    } catch (Throwable $e) {
        workflowFlash('danger', $e->getMessage());
        $restore = $rows;
    }
    $_SESSION['daily_inventory_sheet_restore'] = ['date' => $sheetDate, 'rows' => $restore];
    header('Location: Nurse_DailyInventorySheet.php?' . http_build_query(['date' => sheetValidDate($sheetDate) ? $sheetDate : date('Y-m-d')]));
    exit;
}

$sheetDate = is_string($_GET['date'] ?? null) ? trim($_GET['date']) : date('Y-m-d');
if (!sheetValidDate($sheetDate)) {
    $sheetDate = date('Y-m-d');
}
$restoreData = $_SESSION['daily_inventory_sheet_restore'] ?? null;
unset($_SESSION['daily_inventory_sheet_restore']);
$restoreRows = is_array($restoreData) && ($restoreData['date'] ?? '') === $sheetDate ? (array)$restoreData['rows'] : [];

$itemStmt = $conn->prepare("SELECT i.item_id, i.item_name FROM inventory_items i WHERE i.is_consumable = 1");
$itemStmt->execute();
$itemList = dailyInventorySortItems($itemStmt->get_result()->fetch_all(MYSQLI_ASSOC));
$itemStmt->close();

$sheetRows = [];
$sheetOrder = array_flip(dailyInventorySheetOrder());
foreach ($itemList as $listed) {
    try {
        $context = dailyInventoryContext($conn, $branchId, (int)$listed['item_id'], $sheetDate);
    } catch (Throwable $e) {
        continue;
    }
    $saved = $context['opening_source'] === 'saved_closing';
    $restore = $restoreRows[(string)$listed['item_id']] ?? null;
    $value = static function (string $field) use ($context, $restore) {
        if (is_array($restore) && is_string($restore[$field] ?? null)) return $restore[$field];
        return (string)($context['fields'][$field] ?? '');
    };
    $sheetRows[] = [
        'item' => $context['item'],
        'on_sheet' => isset($sheetOrder[dailyInventorySheetKey((string)$listed['item_name'])]),
        'context' => $context,
        'saved' => $saved && !$context['needs_physical_count'],
        'values' => [
            'beginning_stock' => $value('beginning_stock'),
            'delivery' => $value('delivery'),
            'pull_out' => $value('pull_out'),
            'actual_count' => $value('actual_count'),
            'remarks' => $value('remarks'),
            'wastage_quantity' => is_array($restore) ? (string)($restore['wastage_quantity'] ?? '') : '',
            'wastage_reason' => is_array($restore) ? (string)($restore['wastage_reason'] ?? '') : '',
            'wastage_other' => is_array($restore) ? (string)($restore['wastage_other'] ?? '') : '',
        ],
    ];
}

$sourceLabels = [
    'saved_closing' => 'Saved closing',
    'previous_day' => "Yesterday's actual",
    'previous_computed' => "Yesterday's Inv. End",
    'manual' => 'Enter opening',
];
$flash = workflowTakeFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daily Inventory Sheet - Smart Bite Care</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="sidebar.css">
    <link rel="stylesheet" href="notif-num.css">
    <style>
        :root{--primary:#2B3A8C;--accent:#F21D2F}
        *{box-sizing:border-box}
        body{margin:0;background:#f0f2f5;font-family:'Segoe UI',sans-serif}
        .main{min-height:100vh;margin-left:260px;background:#f0f2f5}
        .topbar{display:flex;align-items:center;justify-content:space-between;height:80px;padding:0 35px;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.08)}
        .topbar h3{margin:0;color:var(--primary);font-size:28px;font-weight:700}
        .topbar h3 small{margin-left:8px;color:#6c757d;font-size:16px;font-weight:400}
        .profile{display:flex;align-items:center;gap:6px;color:var(--primary);font-weight:600}
        .content{padding:30px 35px}
        .sheet-card{background:#fff;border-radius:14px;box-shadow:0 4px 16px rgba(43,58,140,.08);overflow:hidden}
        .sheet-header{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-end;gap:14px;padding:20px 24px;border-bottom:1px solid #edf0f5}
        .sheet-header h5{margin:0;color:var(--primary);font-weight:700}
        .sheet-header p{margin:4px 0 0;color:#8b95a7;font-size:13px}
        .sheet-table{min-width:1500px;margin:0;font-size:12px}
        .sheet-table thead th{position:sticky;top:0;z-index:1;background:var(--primary);color:#fff;font-size:11px;text-transform:uppercase;white-space:nowrap;padding:10px 8px}
        .sheet-table td{padding:8px;vertical-align:top}
        .sheet-table input.form-control{min-width:86px;font-size:12px;padding:4px 6px}
        .sheet-table .remarks-input{min-width:170px}
        .item-name{font-weight:700;color:#25324b}
        .unit-note,.source-note{display:block;color:#8b95a7;font-size:11px}
        .source-note.filled{color:#1d4ed8}
        .computed{font-weight:700;color:#25324b;white-space:nowrap}
        .variance-ok{color:#1f7a35;font-weight:700}
        .variance-bad{color:#b42318;font-weight:700}
        .row-saved{background:#f3fbf5}
        .off-sheet td:first-child .item-name{color:#5e6878}
        @media(max-width:991px){.main{margin-left:90px}}
    </style>
</head>
<body>
<aside class="sidebar" id="sidebar">
    <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar" aria-expanded="true">
        <i class="bi bi-chevron-left"></i>
    </button>
    <div class="logo-area">
        <div class="logo-frame"><img src="logo.png" alt="Smart Bite Care Logo" class="logo"></div>
        <div class="system-name">Smart Bite Care</div>
    </div>
    <nav class="nav-menu" aria-label="Nurse navigation">
        <ul>
            <li><a href="Nurse_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="Nurse_Calendar.php"><i class="bi bi-calendar3"></i><span>Calendar</span></a></li>
            <li><a href="Nurse_Patients.php"><i class="bi bi-heart-pulse-fill"></i><span>Patients</span></a></li>
            <li><a href="Nurse_Assessment.php"><i class="bi bi-clipboard2-pulse-fill"></i><span>Assessment Queue</span></a></li>
            <li><a href="Nurse_Vaccination.php"><i class="bi bi-shield-plus"></i><span>Vaccination</span></a></li>
            <li><a class="active" href="Nurse_DailyInventory.php" aria-current="page"><i class="bi bi-clipboard-data-fill"></i><span>Daily Inventory</span></a></li>
            <li><a href="Nurse_MedicalSuppliesManagement.php"><i class="bi bi-calendar-check"></i><span>Medical Supplies Management</span></a></li>
            <li><a href="Nurse_Supplyforecasting.php"><i class="bi bi-graph-up-arrow"></i><span>Supply Forecasting</span></a></li>
            <li>
                <a href="Nurse_Notification.php" class="notification-link">
                    <i class="bi bi-bell-fill"></i><span>Notifications</span>
                    <?php if ($notification_count > 0): ?>
                        <span class="notification-badge"><?php echo (int)$notification_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>
    </nav>
</aside>

<main class="main">
    <div class="topbar">
        <h3>Daily Inventory Sheet <small><?= workflowH((string)($user['branch_name'] ?? $branchId)) ?></small></h3>
        <div class="profile"><i class="bi bi-person-circle"></i><span><?= workflowH((string)($user['username'] ?? '')) ?></span></div>
    </div>

    <div class="content">
        <?php if ($flash): ?>
            <div class="alert alert-<?= workflowH((string)$flash['type']) ?> alert-dismissible fade show" role="alert">
                <?= workflowH((string)$flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <section class="sheet-card">
            <div class="sheet-header">
                <div>
                    <h5><i class="bi bi-table me-1"></i>Whole-day sheet</h5>
                    <p>
                        Inv. End = Beginning + Delivery - Consumed - Pull-out. Consumed is auto-computed from the day's Assessment and
                        Vaccination records (each use counted once; doses given at another clinic are not counted); Edit / Adjust adds
                        wastage with a reason. Beginning is filled from yesterday's Actual count
                        (or yesterday's Inv. End when there was no actual count) and stays editable. Only rows with an Actual count are saved.
                        Quantities use each item's base unit.
                    </p>
                </div>
                <form method="get" class="d-flex align-items-end gap-2">
                    <div>
                        <label class="form-label small mb-1" for="sheetDate">Inventory date</label>
                        <input type="date" class="form-control form-control-sm" id="sheetDate" name="date" value="<?= workflowH($sheetDate) ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <button class="btn btn-outline-primary btn-sm" type="submit">Load</button>
                    <a class="btn btn-light border btn-sm" href="Nurse_DailyInventory.php"><i class="bi bi-arrow-left me-1"></i>Single-item form</a>
                </form>
            </div>

            <form method="post" id="dailySheetForm">
                <input type="hidden" name="csrf_token" value="<?= workflowH($csrf) ?>">
                <input type="hidden" name="inventory_date" value="<?= workflowH($sheetDate) ?>">
                <div class="table-responsive" style="max-height:72vh">
                    <table class="table sheet-table align-middle">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Beginning</th>
                                <th>Delivery</th>
                                <th>Consumed (Auto)</th>
                                <th>Edit / Adjust<br><span class="fw-normal">(wastage)</span></th>
                                <th>Pull-out</th>
                                <th>Inv. End</th>
                                <th>Actual</th>
                                <th>Variance</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sheetRows as $row):
                            $item = $row['item'];
                            $id = (int)$item['item_id'];
                            $context = $row['context'];
                            $base = inventoryBaseUnitLabel($item);
                            $name = 'rows[' . $id . ']';
                            $source = (string)$context['opening_source'];
                        ?>
                            <tr class="sheet-row <?= $row['saved'] ? 'row-saved' : '' ?> <?= $row['on_sheet'] ? '' : 'off-sheet' ?>" data-consumed="<?= workflowH((string)$context['consumed']) ?>">
                                <td>
                                    <span class="item-name"><?= workflowH(trim((string)$item['item_name'])) ?></span>
                                    <span class="unit-note"><?= workflowH($base) ?><?= $row['saved'] ? ' · Saved' : '' ?></span>
                                    <input type="hidden" name="<?= $name ?>[context_token]" value="<?= workflowH((string)$context['context_token']) ?>">
                                    <input type="hidden" name="<?= $name ?>[item_name]" value="<?= workflowH(trim((string)$item['item_name'])) ?>">
                                </td>
                                <td>
                                    <input type="number" step="0.0001" min="0" class="form-control sheet-number" data-field="beginning" name="<?= $name ?>[beginning_stock]" value="<?= workflowH($row['values']['beginning_stock']) ?>">
                                    <span class="source-note <?= in_array($source, ['previous_day', 'previous_computed'], true) ? 'filled' : '' ?>" title="<?= workflowH((string)$context['opening_message']) ?>">
                                        <?= workflowH($sourceLabels[$source] ?? $source) ?>
                                    </span>
                                </td>
                                <td><input type="number" step="0.0001" min="0" class="form-control sheet-number" data-field="delivery" name="<?= $name ?>[delivery]" value="<?= workflowH($row['values']['delivery']) ?>"></td>
                                <td>
                                    <span class="computed"><?= workflowH(inventoryFormatNumber((float)$context['consumed'])) ?></span>
                                    <span class="source-note" title="Auto-computed from Assessment and Vaccination records"><?= workflowH((string)$context['consumed_breakdown']) ?></span>
                                    <?php if (!empty($context['closing_out_of_date'])): ?>
                                        <span class="badge text-bg-warning mt-1">Closing out of date</span>
                                        <button type="button" class="btn btn-link btn-sm p-0 d-block refresh-closing"
                                                data-saved-actual="<?= workflowH((string)$context['closing_saved_actual']) ?>"
                                                title="Usage was recorded after the closing. Reload the saved Actual count, verify it and save again.">Refresh closing</button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input type="number" step="0.0001" min="0" class="form-control sheet-number" data-field="extra" name="<?= $name ?>[wastage_quantity]" value="<?= workflowH($row['values']['wastage_quantity']) ?>" placeholder="0" title="Extra quantity lost (wastage)">
                                    <select class="form-select form-select-sm mt-1 wastage-reason" name="<?= $name ?>[wastage_reason]">
                                        <option value="">Reason</option>
                                        <?php foreach (dailyInventoryWastageReasons() as $reason): ?>
                                            <option value="<?= workflowH($reason) ?>" <?= $row['values']['wastage_reason'] === $reason ? 'selected' : '' ?>><?= workflowH($reason === 'Other' ? 'Other (specify)' : $reason) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" class="form-control mt-1 remarks-input wastage-other" maxlength="200" name="<?= $name ?>[wastage_other]" value="<?= workflowH($row['values']['wastage_other']) ?>" placeholder="Specify (if Other)">
                                </td>
                                <td><input type="number" step="0.0001" min="0" class="form-control sheet-number" data-field="pullout" name="<?= $name ?>[pull_out]" value="<?= workflowH($row['values']['pull_out']) ?>"></td>
                                <td class="computed" data-output="end">-</td>
                                <td>
                                    <input type="number" step="0.0001" min="0" class="form-control sheet-number" data-field="actual" name="<?= $name ?>[actual_count]" value="<?= workflowH($row['values']['actual_count']) ?>">
                                    <button type="button" class="btn btn-link btn-sm p-0 use-end">Use Inv. End</button>
                                </td>
                                <td data-output="variance">-</td>
                                <td><input type="text" class="form-control remarks-input" maxlength="500" name="<?= $name ?>[remarks]" value="<?= workflowH($row['values']['remarks']) ?>" placeholder="Required if variance"></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$sheetRows): ?>
                            <tr><td colspan="10" class="text-center text-muted py-5">No consumable items are configured.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-top">
                    <small class="text-muted">Each counted row is saved separately with the same checks as the single-item form; rows that fail are listed and keep your entries.</small>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save-fill me-1"></i>Save Counted Items</button>
                </div>
            </form>
        </section>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="sources/sidebar.js"></script>
<script>
// Integer ten-thousandths, matching the server's DECIMAL(12,4) arithmetic.
function sheetScaled(value) {
    if (value === '' || value === null || value === undefined) return 0;
    if (!/^(?:[0-9]+(?:\.[0-9]{1,4})?|\.[0-9]{1,4})$/.test(String(value))) return null;
    const parts = String(value).split('.');
    return Number(parts[0] || '0') * 10000 + Number((parts[1] || '').padEnd(4, '0'));
}
function sheetDecimal(scaled) {
    const magnitude = Math.abs(scaled);
    return (scaled < 0 ? '-' : '') + Math.floor(magnitude / 10000) + '.' + String(magnitude % 10000).padStart(4, '0');
}
function sheetText(scaled) {
    return (scaled / 10000).toLocaleString(undefined, { maximumFractionDigits: 4 });
}
function refreshRow(row) {
    const get = field => row.querySelector(`[data-field="${field}"]`)?.value ?? '';
    const beginningText = get('beginning');
    const values = ['beginning', 'delivery', 'extra', 'pullout'].map(f => sheetScaled(get(f)));
    const consumed = sheetScaled(row.dataset.consumed);
    const endCell = row.querySelector('[data-output="end"]');
    const varianceCell = row.querySelector('[data-output="variance"]');
    varianceCell.className = '';
    if (beginningText === '' || values.includes(null) || consumed === null) {
        endCell.textContent = '-';
        varianceCell.textContent = '-';
        row.dataset.end = '';
        return;
    }
    const end = values[0] + values[1] - consumed - values[2] - values[3];
    row.dataset.end = String(end);
    endCell.textContent = sheetText(end);
    const actualText = get('actual');
    const actual = sheetScaled(actualText);
    if (actualText === '' || actual === null) {
        varianceCell.textContent = '-';
    } else {
        const variance = actual - end;
        varianceCell.textContent = variance === 0 ? '0' : sheetText(variance);
        varianceCell.className = variance === 0 ? 'variance-ok' : 'variance-bad';
    }
}
document.querySelectorAll('.sheet-row').forEach(row => {
    row.addEventListener('input', () => refreshRow(row));
    row.querySelector('.refresh-closing')?.addEventListener('click', event => {
        row.querySelector('[data-field="actual"]').value = event.currentTarget.dataset.savedActual || '';
        refreshRow(row);
        row.querySelector('[data-field="actual"]').focus();
    });
    row.querySelector('.use-end')?.addEventListener('click', () => {
        if (row.dataset.end === '' || Number(row.dataset.end) < 0) return;
        row.querySelector('[data-field="actual"]').value = sheetDecimal(Number(row.dataset.end));
        refreshRow(row);
    });
    refreshRow(row);
});
</script>
</body>
</html>
