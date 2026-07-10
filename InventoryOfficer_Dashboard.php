<?php
session_start();
require_once 'sources/db_connect.php';

// Check if user is logged in and is an inventory officer
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 5 // role_id 5 for Inventory Officer
) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$branch_id = null;
$branch_name = '';
$username = '';

// Get user's branch info
$userQuery = "SELECT u.branch_id, u.username, b.branch_name 
              FROM users u 
              LEFT JOIN branches b ON u.branch_id = b.branch_id 
              WHERE u.user_id = ?";
$stmt = $conn->prepare($userQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$userResult = $stmt->get_result();

if ($userResult->num_rows > 0) {
    $userData = $userResult->fetch_assoc();
    $branch_id = $userData['branch_id'];
    $branch_name = $userData['branch_name'] ?? 'Unknown Branch';
    $username = $userData['username'] ?? 'Inventory Officer';
}

// If no branch assigned
if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
}

// Handle AJAX request for refreshing data
if (isset($_GET['ajax']) && $_GET['ajax'] == 'refresh_stats') {
    header('Content-Type: application/json');
    
    $response = [];
    
    // Current stocks
    $currentStocksQuery = "SELECT SUM(quantity_available) as total 
                           FROM inventory_stocks 
                           WHERE branch_id = ?";
    $stmt = $conn->prepare($currentStocksQuery);
    $stmt->bind_param("s", $branch_id);
    $stmt->execute();
    $currentStocksResult = $stmt->get_result();
    $response['current_stocks'] = (int)($currentStocksResult->fetch_assoc()['total'] ?? 0);
    
    // Low stocks
    $lowStocksQuery = "SELECT COUNT(*) as low_stock_count 
                       FROM inventory_stocks s 
                       JOIN inventory_items i ON s.item_id = i.item_id 
                       WHERE s.branch_id = ? 
                       AND s.quantity_available < i.minimum_stock";
    $stmt = $conn->prepare($lowStocksQuery);
    $stmt->bind_param("s", $branch_id);
    $stmt->execute();
    $lowStocksResult = $stmt->get_result();
    $response['low_stocks'] = (int)($lowStocksResult->fetch_assoc()['low_stock_count'] ?? 0);
    
    // Expiring stocks
    $expiringStocksQuery = "SELECT COUNT(*) as expiring_count 
                            FROM inventory_stocks 
                            WHERE branch_id = ? 
                            AND expiration_date IS NOT NULL 
                            AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) 
                            AND expiration_date >= CURDATE()";
    $stmt = $conn->prepare($expiringStocksQuery);
    $stmt->bind_param("s", $branch_id);
    $stmt->execute();
    $expiringStocksResult = $stmt->get_result();
    $response['expiring_stocks'] = (int)($expiringStocksResult->fetch_assoc()['expiring_count'] ?? 0);
    
    // Recent transactions
    $recentTransactionsQuery = "SELECT COUNT(*) as recent_count 
                                FROM stock_transactions 
                                WHERE branch_id = ? 
                                AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    $stmt = $conn->prepare($recentTransactionsQuery);
    $stmt->bind_param("s", $branch_id);
    $stmt->execute();
    $recentTransactionsResult = $stmt->get_result();
    $response['recent_transactions'] = (int)($recentTransactionsResult->fetch_assoc()['recent_count'] ?? 0);
    
    echo json_encode(['success' => true, 'stats' => $response]);
    exit();
}

// Fetch statistics for the inventory officer's branch
$stats = [];

// Current stocks (total quantity available)
$currentStocksQuery = "SELECT SUM(quantity_available) as total 
                       FROM inventory_stocks 
                       WHERE branch_id = ?";
$stmt = $conn->prepare($currentStocksQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$currentStocksResult = $stmt->get_result();
$stats['current_stocks'] = $currentStocksResult->fetch_assoc()['total'] ?? 0;

// Low stocks (items where quantity_available < minimum_stock)
$lowStocksQuery = "SELECT COUNT(*) as low_stock_count 
                   FROM inventory_stocks s 
                   JOIN inventory_items i ON s.item_id = i.item_id 
                   WHERE s.branch_id = ? 
                   AND s.quantity_available < i.minimum_stock";
$stmt = $conn->prepare($lowStocksQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$lowStocksResult = $stmt->get_result();
$stats['low_stocks'] = $lowStocksResult->fetch_assoc()['low_stock_count'] ?? 0;

// Expiring stocks (within 30 days)
$expiringStocksQuery = "SELECT COUNT(*) as expiring_count 
                        FROM inventory_stocks 
                        WHERE branch_id = ? 
                        AND expiration_date IS NOT NULL 
                        AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) 
                        AND expiration_date >= CURDATE()";
$stmt = $conn->prepare($expiringStocksQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$expiringStocksResult = $stmt->get_result();
$stats['expiring_stocks'] = $expiringStocksResult->fetch_assoc()['expiring_count'] ?? 0;

// Recent transactions (last 30 days)
$recentTransactionsQuery = "SELECT COUNT(*) as recent_count 
                            FROM stock_transactions 
                            WHERE branch_id = ? 
                            AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
$stmt = $conn->prepare($recentTransactionsQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$recentTransactionsResult = $stmt->get_result();
$stats['recent_transactions'] = $recentTransactionsResult->fetch_assoc()['recent_count'] ?? 0;

// Fetch low stock items with details
$lowStockItemsQuery = "SELECT i.item_id, i.item_name, c.category_name, 
                       s.quantity_available, s.stock_id,
                       u.unit_name, i.minimum_stock
                       FROM inventory_stocks s
                       JOIN inventory_items i ON s.item_id = i.item_id
                       JOIN inventory_categories c ON i.category_id = c.category_id
                       JOIN units u ON i.unit_id = u.unit_id
                       WHERE s.branch_id = ? 
                       AND s.quantity_available < i.minimum_stock
                       ORDER BY (s.quantity_available / i.minimum_stock) ASC
                       LIMIT 10";
$stmt = $conn->prepare($lowStockItemsQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$lowStockItemsResult = $stmt->get_result();
$lowStockItems = [];
while ($row = $lowStockItemsResult->fetch_assoc()) {
    // Determine status based on quantity
    $percentage = ($row['minimum_stock'] > 0) ? ($row['quantity_available'] / $row['minimum_stock']) : 0;
    if ($percentage <= 0.25) {
        $status = 'Critical';
    } elseif ($percentage <= 0.50) {
        $status = 'Low Stock';
    } else {
        $status = 'Below Minimum';
    }
    $row['status'] = $status;
    $row['item_id_formatted'] = 'ITM-' . str_pad($row['item_id'], 4, '0', STR_PAD_LEFT);
    $row['stock_display'] = $row['quantity_available'] . ' ' . $row['unit_name'];
    $lowStockItems[] = $row;
}

// Fetch recent transactions
$recentTransactionsListQuery = "SELECT t.*, i.item_name, c.category_name, 
                               u.unit_name,
                               t.transaction_type,
                               t.quantity
                               FROM stock_transactions t
                               JOIN inventory_items i ON t.item_id = i.item_id
                               JOIN inventory_categories c ON i.category_id = c.category_id
                               JOIN units u ON i.unit_id = u.unit_id
                               WHERE t.branch_id = ? 
                               ORDER BY t.transaction_date DESC
                               LIMIT 10";
$stmt = $conn->prepare($recentTransactionsListQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$recentTransactionsListResult = $stmt->get_result();
$recentTransactionsList = [];
while ($row = $recentTransactionsListResult->fetch_assoc()) {
    $row['item_id_formatted'] = 'ITM-' . str_pad($row['item_id'], 4, '0', STR_PAD_LEFT);
    $row['stock_display'] = $row['quantity'] . ' ' . $row['unit_name'];
    switch ($row['transaction_type']) {
        case 'IN':
            $row['status'] = 'Stock In';
            break;
        case 'OUT':
            $row['status'] = 'Stock Out';
            break;
        case 'ADJUSTMENT':
            $row['status'] = 'Adjustment';
            break;
        default:
            $row['status'] = 'Unknown';
    }
    $row['transaction_type_display'] = $row['status'];
    $recentTransactionsList[] = $row;
}

function statusBadgeClass($status) {
    switch ($status) {
        case 'Critical':   return 'badge-critical';
        case 'Low Stock':  return 'badge-low';
        case 'Below Minimum': return 'badge-warning';
        case 'Stock In':   return 'badge-in';
        case 'Stock Out':  return 'badge-out';
        case 'Adjustment': return 'badge-adjustment';
        default:           return 'badge-low';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Inventory Officer Dashboard - <?php echo htmlspecialchars($branch_name); ?></title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link rel="stylesheet"
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<!--REUSABLE SIDEBAR CSS-->
<link rel="stylesheet" href="sidebar.css">

<style>

/*=========================================
  INTERNAL CSS
=========================================*/

:root{

--primary:#2B3A8C;
--accent:#F21D2F;
--bg:#F2F2F2;

}

body{

background:white;

font-family:'Segoe UI',sans-serif;

}

.main{

margin-left:260px;

min-height:100vh;

}

.topbar{

background:white;

height:80px;

display:flex;

align-items:center;

justify-content:space-between;

padding:0 35px;

box-shadow:0 2px 8px rgba(0,0,0,.08);

}

.topbar h3{

font-size:28px;

font-weight:700;

color:var(--primary);

margin:0;

}

.topbar h3 small {
    font-size: 16px;
    font-weight: 400;
    color: #666;
    margin-left: 10px;
}

.profile{

font-weight:600;

color:var(--primary);

cursor:pointer;

}

.dashboard{

padding:35px;

}

.stat-card{

background:#ECEEF7;

border-radius:18px;

padding:22px;

min-height:140px;

display:flex;

flex-direction:column;

justify-content:space-between;

box-shadow:0 3px 8px rgba(0,0,0,.08);
transition: transform 0.2s, box-shadow 0.2s;
cursor: default;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 16px rgba(0,0,0,.12);
}

.stat-title{

font-weight:600;

color:var(--primary);

font-size:18px;

line-height:1.25;

}

.stat-number{

margin-top:20px;

font-size:clamp(28px,3.4vw,48px);

font-weight:700;

color:var(--primary);

}

.large-card{

background:#ECEEF7;

border-radius:18px;

padding:20px;

margin-top:25px;

box-shadow:0 3px 8px rgba(0,0,0,.08);

}

.section-title{

font-size:20px;

font-weight:700;

color:var(--primary);

margin-bottom:20px;
display: flex;
justify-content: space-between;
align-items: center;
}

.section-title .badge-count {
    font-size: 14px;
    font-weight: 600;
    color: #666;
    background: white;
    padding: 4px 12px;
    border-radius: 20px;
}

.btn-custom{

background:var(--primary);

color:white;

border-radius:8px;

padding:8px 18px;

border:none;
transition: background 0.2s;
}

.btn-custom:hover{

background:#1d2863;
color: white;

}

.btn-refresh {
    background: white;
    color: var(--primary);
    border: 2px solid var(--primary);
    border-radius: 8px;
    padding: 6px 14px;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.2s;
}

.btn-refresh:hover {
    background: var(--primary);
    color: white;
}

/* Tables */

.table-wrap{

background:white;

border-radius:12px;

border:1px solid #dfe1ee;

overflow:hidden;

}

.data-table{

margin:0;

}

.data-table thead th{

background:var(--primary);

color:white;

font-weight:600;

font-size:13px;

border:none;

padding:12px 14px;

white-space:nowrap;

}

.data-table tbody td{

font-size:14px;

color:#333;

padding:12px 14px;

vertical-align:middle;

border-bottom:1px solid #eef0f7;

}

.data-table tbody tr:last-child td{

border-bottom:none;

}

.data-table tbody tr:hover{

background:#f7f8fc;

}

.badge-status{

display:inline-block;

padding:5px 12px;

border-radius:20px;

font-size:12px;

font-weight:600;

}

.badge-low{

background:#FFEAEA;

color:var(--accent);

}

.badge-critical{

background:var(--accent);

color:white;

}

.badge-warning{
    background: #FFF3CD;
    color: #856404;
}

.badge-in{

background:#E6F4EA;

color:#1E7B34;

}

.badge-out{

background:#EDEFFA;

color:var(--primary);

}

.badge-adjustment{

background:#FFF3CD;

color:#856404;

}

.empty-state {
    text-align: center;
    padding: 30px 10px;
    color: #999;
}
.empty-state i {
    font-size: 32px;
    margin-bottom: 10px;
    display: block;
}

.no-branch {
    text-align: center;
    padding: 60px 20px;
    background: #f8f9fa;
    border-radius: 18px;
}

.no-branch i {
    font-size: 64px;
    color: #d9dee8;
    display: block;
    margin-bottom: 20px;
}

.no-branch h4 {
    color: #1e293b;
    margin-bottom: 10px;
}

.no-branch p {
    color: #6b7280;
}

@media(max-width:991px){

.main{

margin-left:90px;

}

}

@media (max-width: 768px) {
    .dashboard {
        padding: 20px 15px;
    }
    
    .topbar h3 {
        font-size: 20px;
    }
    
    .topbar h3 small {
        font-size: 13px;
        display: block;
        margin-left: 0;
    }
    
    .stat-card {
        min-height: 110px;
        padding: 18px;
    }
    
    .stat-number {
        font-size: 28px;
    }
}

/* Toast notification */
.toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
}

.toast {
    border-radius: 10px;
    border: none;
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}

.toast-success {
    background: #dff0e6;
    border-left: 4px solid #0f7b3a;
}

.toast-error {
    background: #f8d7da;
    border-left: 4px solid #721c24;
}

.toast .toast-body {
    padding: 16px 20px;
    font-weight: 500;
}

</style>

</head>


<body>
<!-- SIDEBAR LOGO-->

<div class="sidebar">

<div class="logo-area">

    <div class="logo-frame">
        <img src="logo.png" alt="Smart Bite Care Logo" class="logo">
    </div>

    <div class="system-name">
        Smart Bite Care
    </div>

</div>

<!-- SIDEBAR NAVIGATION -->

<nav class="nav-menu">

<ul>

<li><a class="active" href="InventoryOfficer_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>

<li><a href="InventoryOfficer_InventoryItems.php"><i class="bi bi-box-seam"></i><span>Inventory Items</span></a></li>
<li><a href="InventoryOfficer_Categories.php"><i class="bi bi-tags"></i><span>Categories & Units</span></a></li>

<li><a href="InventoryOfficer_StockManagement.php"><i class="bi bi-boxes"></i><span>Stock Management</span></a></li>

<li><a href="InventoryOfficer_StockTransactions.php"><i class="bi bi-arrow-left-right"></i><span>Stock Transactions</span></a></li>

<li><a href="InventoryOfficer_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Inventory Reports</span></a></li>

<li><a href="InventoryOfficer_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>



</ul>

</nav>

<div class="logout">
<a href="logout.php"> <i class="bi bi-box-arrow-right"></i>
<span>Logout</span>
</a>
</div>

</div>

<!-- Toast Container -->
<div class="toast-container">
    <div id="toastMessage" class="toast" role="alert" aria-live="polite" aria-atomic="true">
        <div class="toast-body" id="toastBody"></div>
    </div>
</div>

<!-- Main Content -->

<div class="main">

<div class="topbar">
<h3>Dashboard <small><?php echo htmlspecialchars($branch_name); ?></small></h3>
<div class="profile"> <?php echo htmlspecialchars($username); ?> <i class="bi bi-caret-down-fill"></i> </div>
</div>

<div class="dashboard">
    
<?php if (!$branch_id): ?>
    <!-- No Branch Assigned -->
    <div class="no-branch">
        <i class="bi bi-exclamation-triangle"></i>
        <h4>No Branch Assigned</h4>
        <p>You have not been assigned to a branch yet. Please contact your administrator.</p>
    </div>
<?php else: ?>

<div class="row g-4">

<div class="col-lg-3 col-md-6">

<div class="stat-card">
<div class="stat-title">Current Stocks</div>
<div class="stat-number" id="stat-current-stocks"><?php echo number_format($stats['current_stocks']); ?></div>
</div>

</div>

<div class="col-lg-3 col-md-6">

<div class="stat-card">
<div class="stat-title">Low Stocks</div>
<div class="stat-number" id="stat-low-stocks"><?php echo number_format($stats['low_stocks']); ?></div>
</div>

</div>

<div class="col-lg-3 col-md-6">

<div class="stat-card">
<div class="stat-title">Expiring Stocks</div>
<div class="stat-number" id="stat-expiring-stocks"><?php echo number_format($stats['expiring_stocks']); ?></div>
</div>

</div>

<div class="col-lg-3 col-md-6">

<div class="stat-card">
<div class="stat-title">Recent Transactions</div>
<div class="stat-number" id="stat-recent-transactions"><?php echo number_format($stats['recent_transactions']); ?></div>
</div>

</div>

<div class="col-lg-6">

<div class="large-card">

<div class="section-title">
    <span>Low Stock Items</span>
    <span>
        <span class="badge-count"><?php echo count($lowStockItems); ?> items</span>
        <button class="btn-refresh ms-2" id="refreshBtn" title="Refresh data">
            <i class="bi bi-arrow-clockwise"></i>
        </button>
    </span>
</div>

<div class="table-wrap">
<table class="table data-table">
<thead>
<tr>
<th>Item ID</th>
<th>Item</th>
<th>Category</th>
<th>Stock</th>
<th>Status</th>
</tr>
</thead>
<tbody id="lowStockTableBody">
<?php if (empty($lowStockItems)): ?>
<tr>
    <td colspan="5">
        <div class="empty-state">
            <i class="bi bi-check-circle"></i>
            <p>No low stock items found.</p>
        </div>
    </td>
</tr>
<?php else: ?>
    <?php foreach ($lowStockItems as $item): ?>
    <tr>
        <td><?php echo htmlspecialchars($item['item_id_formatted']); ?></td>
        <td><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
        <td><?php echo htmlspecialchars($item['category_name']); ?></td>
        <td><?php echo htmlspecialchars($item['stock_display']); ?></td>
        <td><span class="badge-status <?php echo statusBadgeClass($item['status']); ?>"><?php echo htmlspecialchars($item['status']); ?></span></td>
    </tr>
    <?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>

<div class="text-end mt-3">
<button class="btn btn-custom" onclick="window.location.href='InventoryOfficer_StockManagement.php'">
<i class="bi bi-boxes"></i> View All Low Stocks
</button>
</div>

</div>

</div>

<div class="col-lg-6">

<div class="large-card">

<div class="section-title">
    <span>Recent Transactions</span>
    <span>
        <span class="badge-count">Last 10</span>
    </span>
</div>

<div class="table-wrap">
<table class="table data-table">
<thead>
<tr>
<th>Item ID</th>
<th>Item</th>
<th>Category</th>
<th>Quantity</th>
<th>Status</th>
</tr>
</thead>
<tbody id="recentTransactionsBody">
<?php if (empty($recentTransactionsList)): ?>
<tr>
    <td colspan="5">
        <div class="empty-state">
            <i class="bi bi-clock-history"></i>
            <p>No recent transactions found.</p>
        </div>
    </td>
</tr>
<?php else: ?>
    <?php foreach ($recentTransactionsList as $item): ?>
    <tr>
        <td><?php echo htmlspecialchars($item['item_id_formatted']); ?></td>
        <td><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
        <td><?php echo htmlspecialchars($item['category_name']); ?></td>
        <td><?php echo htmlspecialchars($item['stock_display']); ?></td>
        <td><span class="badge-status <?php echo statusBadgeClass($item['status']); ?>"><?php echo htmlspecialchars($item['status']); ?></span></td>
    </tr>
    <?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>

<div class="text-end mt-3">
<button class="btn btn-custom" onclick="window.location.href='InventoryOfficer_StockTransactions.php'">
<i class="bi bi-arrow-left-right"></i> View All Transactions
</button>
</div>

</div>

</div>

</div>

<?php endif; ?>

</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const refreshBtn = document.getElementById('refreshBtn');
    const toast = new bootstrap.Toast(document.getElementById('toastMessage'), {
        delay: 3000
    });
    
    // Show toast message
    function showToast(message, type = 'success') {
        const toastEl = document.getElementById('toastMessage');
        const toastBody = document.getElementById('toastBody');
        toastEl.className = `toast toast-${type}`;
        toastBody.innerHTML = message;
        toast.show();
    }
    
    // Refresh stats
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function() {
            // Show loading state
            const originalHtml = this.innerHTML;
            this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Loading...';
            this.disabled = true;
            
            fetch('?ajax=refresh_stats')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update stat numbers with animation
                        const stats = data.stats;
                        animateNumber('stat-current-stocks', stats.current_stocks);
                        animateNumber('stat-low-stocks', stats.low_stocks);
                        animateNumber('stat-expiring-stocks', stats.expiring_stocks);
                        animateNumber('stat-recent-transactions', stats.recent_transactions);
                        
                        showToast('Dashboard updated successfully!', 'success');
                    } else {
                        showToast('Failed to refresh data.', 'error');
                    }
                })
                .catch(error => {
                    showToast('An error occurred while refreshing.', 'error');
                    console.error('Error:', error);
                })
                .finally(() => {
                    this.innerHTML = originalHtml;
                    this.disabled = false;
                });
        });
    }
    
    // Animate number change
    function animateNumber(elementId, newValue) {
        const element = document.getElementById(elementId);
        if (!element) return;
        
        const currentValue = parseInt(element.textContent.replace(/,/g, '')) || 0;
        const diff = newValue - currentValue;
        const steps = 20;
        const stepValue = diff / steps;
        let current = currentValue;
        
        // Clear any existing animation
        if (element._animationInterval) {
            clearInterval(element._animationInterval);
        }
        
        let step = 0;
        element._animationInterval = setInterval(function() {
            step++;
            current += stepValue;
            if (step >= steps) {
                current = newValue;
                clearInterval(element._animationInterval);
                element._animationInterval = null;
            }
            element.textContent = Math.round(current).toLocaleString();
        }, 20);
    }
    
    // Auto-refresh every 60 seconds
    let autoRefreshInterval = setInterval(function() {
        if (refreshBtn && !refreshBtn.disabled) {
            refreshBtn.click();
        }
    }, 60000);
    
    // Stop auto-refresh when page is hidden
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            clearInterval(autoRefreshInterval);
        } else {
            autoRefreshInterval = setInterval(function() {
                if (refreshBtn && !refreshBtn.disabled) {
                    refreshBtn.click();
                }
            }, 60000);
        }
    });
});
</script>

</body>
</html>