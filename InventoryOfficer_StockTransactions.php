<?php
session_start();

// ============================================
// CONFIGURATION & SECURITY
// ============================================

require_once 'sources/db_connect.php';

// Check if user is logged in and is Inventory Officer (role_id = 5) or Super Admin (role_id = 1)
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    ($_SESSION['role_id'] != 1 && $_SESSION['role_id'] != 5)
) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'];
$branch_id = $_SESSION['branch_id'] ?? null;

// If branch_id is not set for Inventory Officer, redirect
if ($role_id == 5 && empty($branch_id)) {
    header("Location: login.php?error=no_branch");
    exit();
}

// ============================================
// GET FILTERS
// ============================================

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// ============================================
// BUILD QUERY
// ============================================

$conditions = ["s.branch_id = ?"];
$params = [$branch_id];
$types = "s";

if (!empty($search)) {
    $conditions[] = "(i.item_name LIKE ? OR st.batch_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if ($type_filter != 'all') {
    $conditions[] = "s.transaction_type = ?";
    $params[] = strtoupper($type_filter);
    $types .= "s";
}

$where_clause = implode(" AND ", $conditions);

// Count total
$count_sql = "SELECT COUNT(*) as total 
               FROM stock_transactions s
               JOIN inventory_items i ON s.item_id = i.item_id
               LEFT JOIN inventory_stocks st ON s.stock_id = st.stock_id
               WHERE $where_clause";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_rows = $count_result->fetch_assoc()['total'];
$count_stmt->close();

$total_pages = ceil($total_rows / $per_page);

// Get transactions
$sql = "SELECT 
            s.transaction_id,
            s.transaction_type,
            s.quantity,
            s.remarks,
            s.transaction_date,
            s.stock_id,
            s.vaccination_id,
            i.item_name,
            i.item_id,
            u.username,
            st.batch_number,
            st.expiration_date
        FROM stock_transactions s
        JOIN inventory_items i ON s.item_id = i.item_id
        JOIN users u ON s.user_id = u.user_id
        LEFT JOIN inventory_stocks st ON s.stock_id = st.stock_id
        WHERE $where_clause
        ORDER BY s.transaction_date DESC
        LIMIT ? OFFSET ?";

$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$transactions = [];
while ($row = $result->fetch_assoc()) {
    // Format transaction type display
    $type_display = $row['transaction_type'];
    $type_class = 'badge-in';
    if ($row['transaction_type'] == 'OUT') {
        $type_class = 'badge-out';
        $qty_display = '-' . $row['quantity'];
    } elseif ($row['transaction_type'] == 'ADJUSTMENT') {
        $type_class = 'badge-adjust';
        // Check if adjustment was positive or negative
        $qty_display = ($row['quantity'] >= 0) ? '+' . $row['quantity'] : $row['quantity'];
    } else {
        $qty_display = '+' . $row['quantity'];
    }
    
    // Build remarks with vaccination info if applicable
    $remarks_display = $row['remarks'] ?? '';
    if ($row['vaccination_id'] && empty($remarks_display)) {
        $remarks_display = 'Vaccination ID: ' . $row['vaccination_id'];
    }
    
    $transactions[] = [
        'transaction_id' => $row['transaction_id'],
        'type' => $type_display,
        'type_class' => $type_class,
        'item_name' => $row['item_name'],
        'quantity_display' => $qty_display,
        'quantity' => $row['quantity'],
        'date' => date('m/d/Y', strtotime($row['transaction_date'])),
        'username' => $row['username'],
        'remarks' => $remarks_display,
        'batch_number' => $row['batch_number'] ?? 'N/A',
        'expiration_date' => $row['expiration_date'] ?? null
    ];
}
$stmt->close();

// Function to get transaction type class
function trxTypeClass($type) {
    switch ($type) {
        case 'IN': return 'badge-in';
        case 'OUT': return 'badge-out';
        case 'ADJUSTMENT': return 'badge-adjust';
        default: return 'badge-in';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stock Transactions</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="sidebar.css">
    <style>
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
            --bg: #F2F2F2;
        }

        body {
            background: white;
            font-family: 'Segoe UI', sans-serif;
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            background: white;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 35px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }

        .topbar h3 {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
        }

        .profile {
            font-weight: 600;
            color: var(--primary);
            cursor: pointer;
        }

        .page-body {
            padding: 35px;
        }

        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .search-box {
            position: relative;
            flex: 1;
            max-width: 340px;
        }

        .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #9aa0c3;
        }

        .search-box input {
            width: 100%;
            padding: 10px 14px 10px 38px;
            border-radius: 10px;
            border: 1px solid #dcdee8;
            background: #F7F8FC;
            font-size: 14px;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
        }

        .filter-select {
            max-width: 200px;
            border-radius: 10px;
            border: 1px solid #dcdee8;
            font-size: 14px;
            padding: 10px 14px;
        }

        .table-wrap {
            background: white;
            border-radius: 12px;
            border: 1px solid #dfe1ee;
            overflow: hidden;
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

        .badge-in {
            background: #E6F4EA;
            color: #1E7B34;
        }

        .badge-out {
            background: #FFEAEA;
            color: var(--accent);
        }

        .badge-adjust {
            background: #EDEFFA;
            color: var(--primary);
        }

        .pagination-custom {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 24px;
        }

        .pagination-custom a, .pagination-custom span {
            color: var(--primary);
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            padding: 6px 10px;
            border-radius: 6px;
        }

        .pagination-custom a.active {
            background: var(--accent);
            color: white;
        }

        .pagination-custom a:hover:not(.active) {
            background: #eef0f7;
        }

        .pagination-custom .disabled {
            color: #ccc;
            cursor: not-allowed;
        }

        .expiry-badge {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 10px;
        }

        .expiry-badge.soon {
            background: #FFF3CD;
            color: #856404;
        }

        .expiry-badge.expired {
            background: #FFEAEA;
            color: var(--accent);
        }

        .expiry-badge.ok {
            background: #E6F4EA;
            color: #1E7B34;
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
            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            .search-box {
                max-width: 100%;
            }
            .filter-select {
                max-width: 100%;
            }
            .table-wrap {
                overflow-x: auto;
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
            <li><a href="InventoryOfficer_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="InventoryOfficer_InventoryItems.php"><i class="bi bi-box-seam"></i><span>Inventory Items</span></a></li>
            <li><a href="InventoryOfficer_Categories.php"><i class="bi bi-tags"></i><span>Categories & Units</span></a></li>
            <li><a href="InventoryOfficer_StockManagement.php"><i class="bi bi-boxes"></i><span>Stock Management</span></a></li>
            <li><a class="active" href="InventoryOfficer_StockTransactions.php"><i class="bi bi-arrow-left-right"></i><span>Stock Transactions</span></a></li>
            <li><a href="InventoryOfficer_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Inventory Reports</span></a></li>
            <li><a href="InventoryOfficer_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
        </ul>
    </nav>

    <div class="logout">
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </div>
</div>

<!-- ========== MAIN CONTENT ========== -->
<div class="main">
    <div class="topbar">
        <h3>Stock Transactions</h3>
        <div class="profile">INVENTORY <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <div class="page-body">
        <div class="toolbar">
            <form method="GET" action="<?php echo $_SERVER['PHP_SELF']; ?>" class="d-flex gap-2 flex-wrap" style="flex:1;">
                <div class="search-box" style="flex:1;max-width:340px;">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Search by item or batch..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <select class="filter-select" name="type" onchange="this.form.submit()">
                    <option value="all" <?php echo $type_filter == 'all' ? 'selected' : ''; ?>>All Types</option>
                    <option value="IN" <?php echo $type_filter == 'IN' ? 'selected' : ''; ?>>Stock In</option>
                    <option value="OUT" <?php echo $type_filter == 'OUT' ? 'selected' : ''; ?>>Stock Out</option>
                    <option value="ADJUSTMENT" <?php echo $type_filter == 'ADJUSTMENT' ? 'selected' : ''; ?>>Adjustment</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
            </form>
        </div>

        <div class="table-wrap">
            <table class="table data-table">
                <thead>
                    <tr>
                        <th>Trx No.</th>
                        <th>Type</th>
                        <th>Item</th>
                        <th>Batch</th>
                        <th>Qty</th>
                        <th>Date</th>
                        <th>By</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($transactions) > 0): ?>
                        <?php foreach ($transactions as $t): ?>
                        <tr>
                            <td>#<?php echo str_pad($t['transaction_id'], 5, '0', STR_PAD_LEFT); ?></td>
                            <td><span class="badge-status <?php echo $t['type_class']; ?>"><?php echo htmlspecialchars($t['type']); ?></span></td>
                            <td><strong><?php echo htmlspecialchars($t['item_name']); ?></strong></td>
                            <td>
                                <?php echo htmlspecialchars($t['batch_number']); ?>
                                <?php if ($t['expiration_date']): ?>
                                    <?php 
                                    $days = (strtotime($t['expiration_date']) - time()) / 86400;
                                    $expiry_class = $days < 0 ? 'expired' : ($days < 30 ? 'soon' : 'ok');
                                    ?>
                                    <span class="expiry-badge <?php echo $expiry_class; ?>">
                                        <?php echo date('m/d/Y', strtotime($t['expiration_date'])); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($t['quantity_display']); ?></td>
                            <td><?php echo htmlspecialchars($t['date']); ?></td>
                            <td><?php echo htmlspecialchars($t['username']); ?></td>
                            <td><?php echo htmlspecialchars($t['remarks'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">
                                <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                No transactions found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination-custom">
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($type_filter); ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
            <?php else: ?>
                <span class="disabled"><i class="bi bi-chevron-left"></i></span>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php if ($i == $page): ?>
                    <span class="active"><?php echo $i; ?></span>
                <?php elseif ($i <= 2 || $i > $total_pages - 2 || abs($i - $page) <= 1): ?>
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($type_filter); ?>">
                        <?php echo $i; ?>
                    </a>
                <?php elseif ($i == 3 && $page > 4): ?>
                    <span>...</span>
                <?php elseif ($i == $total_pages - 2 && $page < $total_pages - 3): ?>
                    <span>...</span>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($type_filter); ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            <?php else: ?>
                <span class="disabled"><i class="bi bi-chevron-right"></i></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>