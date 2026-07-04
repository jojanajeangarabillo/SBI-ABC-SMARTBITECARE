<?php
session_start();
require_once 'sources/db_connect.php';

// Check if user is logged in and is super admin
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 1
) {
    header("Location: login.php");
    exit();
}

// Get filter parameters
$filter_type = isset($_GET['filter']) ? trim($_GET['filter']) : 'all'; // all, branches, roles
$branch_filter = isset($_GET['branch_id']) ? trim($_GET['branch_id']) : '';
$role_filter = isset($_GET['role_id']) ? trim($_GET['role_id']) : '';

// Get all roles with user counts
$roles_data = [];
$total_users = 0;
$total_active = 0;
$total_inactive = 0;

// Base query for role statistics
$sql = "SELECT r.role_id, r.role_name, 
        COUNT(u.user_id) as total_users,
        SUM(CASE WHEN u.status = 'Active' THEN 1 ELSE 0 END) as active_users,
        SUM(CASE WHEN u.status = 'Inactive' THEN 1 ELSE 0 END) as inactive_users
        FROM roles r
        LEFT JOIN users u ON r.role_id = u.role_id";

// Add filters
$where_clauses = [];
$params = [];
$types = "";

if ($filter_type === 'branches' && !empty($branch_filter)) {
    $where_clauses[] = "u.branch_id = ?";
    $params[] = $branch_filter;
    $types .= "s";
} elseif ($filter_type === 'roles' && !empty($role_filter)) {
    $where_clauses[] = "r.role_id = ?";
    $params[] = $role_filter;
    $types .= "i";
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}

$sql .= " GROUP BY r.role_id, r.role_name ORDER BY r.role_name";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $roles_data[] = $row;
    $total_users += $row['total_users'];
    $total_active += $row['active_users'];
    $total_inactive += $row['inactive_users'];
}

// Get all branches for filter dropdown
$branches = [];
$branch_sql = "SELECT branch_id, branch_name FROM branches WHERE status = 'Active' ORDER BY branch_name";
$branch_result = $conn->query($branch_sql);
while ($row = $branch_result->fetch_assoc()) {
    $branches[] = $row;
}

// Get all roles for filter dropdown
$roles = [];
$role_sql = "SELECT role_id, role_name FROM roles ORDER BY role_name";
$role_result = $conn->query($role_sql);
while ($row = $role_result->fetch_assoc()) {
    $roles[] = $row;
}

// Get branch-specific statistics for detailed view
$branch_stats = [];
if ($filter_type === 'branches' && !empty($branch_filter)) {
    $branch_stats_sql = "SELECT r.role_name, 
                         COUNT(u.user_id) as total_users,
                         SUM(CASE WHEN u.status = 'Active' THEN 1 ELSE 0 END) as active_users,
                         SUM(CASE WHEN u.status = 'Inactive' THEN 1 ELSE 0 END) as inactive_users
                         FROM roles r
                         LEFT JOIN users u ON r.role_id = u.role_id
                         WHERE u.branch_id = ?
                         GROUP BY r.role_id, r.role_name
                         ORDER BY r.role_name";
    $branch_stats_stmt = $conn->prepare($branch_stats_sql);
    $branch_stats_stmt->bind_param("s", $branch_filter);
    $branch_stats_stmt->execute();
    $branch_stats_result = $branch_stats_stmt->get_result();
    while ($row = $branch_stats_result->fetch_assoc()) {
        $branch_stats[] = $row;
    }
}

// Get role-specific statistics for detailed view
$role_stats = [];
if ($filter_type === 'roles' && !empty($role_filter)) {
    $role_stats_sql = "SELECT b.branch_name, 
                       COUNT(u.user_id) as total_users,
                       SUM(CASE WHEN u.status = 'Active' THEN 1 ELSE 0 END) as active_users,
                       SUM(CASE WHEN u.status = 'Inactive' THEN 1 ELSE 0 END) as inactive_users
                       FROM branches b
                       LEFT JOIN users u ON b.branch_id = u.branch_id
                       WHERE u.role_id = ?
                       GROUP BY b.branch_id, b.branch_name
                       ORDER BY b.branch_name";
    $role_stats_stmt = $conn->prepare($role_stats_sql);
    $role_stats_stmt->bind_param("i", $role_filter);
    $role_stats_stmt->execute();
    $role_stats_result = $role_stats_stmt->get_result();
    while ($row = $role_stats_result->fetch_assoc()) {
        $role_stats[] = $row;
    }
}

// Get role name for filter display
$selected_role_name = '';
if (!empty($role_filter)) {
    $role_name_sql = "SELECT role_name FROM roles WHERE role_id = ?";
    $role_name_stmt = $conn->prepare($role_name_sql);
    $role_name_stmt->bind_param("i", $role_filter);
    $role_name_stmt->execute();
    $role_name_result = $role_name_stmt->get_result();
    if ($row = $role_name_result->fetch_assoc()) {
        $selected_role_name = $row['role_name'];
    }
}

// Get branch name for filter display
$selected_branch_name = '';
if (!empty($branch_filter)) {
    $branch_name_sql = "SELECT branch_name FROM branches WHERE branch_id = ?";
    $branch_name_stmt = $conn->prepare($branch_name_sql);
    $branch_name_stmt->bind_param("s", $branch_filter);
    $branch_name_stmt->execute();
    $branch_name_result = $branch_name_stmt->get_result();
    if ($row = $branch_name_result->fetch_assoc()) {
        $selected_branch_name = $row['branch_name'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Super Admin - User Monitoring</title>
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <!-- Reusable Sidebar CSS (simulated) -->
    <link rel="stylesheet" href="sidebar.css" />
    <style>
        /* =========================================
           INTERNAL CSS – matches image style
           ========================================= */
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
            --bg: #F2F2F2;
            --card-bg: #ECEEF7;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background: white;
            font-family: 'Segoe UI', Roboto, system-ui, sans-serif;
            margin: 0;
            padding: 0;
        }


        /* ---- main content ---- */
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
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border-bottom: 1px solid #e9edf5;
        }
        .topbar h3 {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
            letter-spacing: -0.3px;
        }
        .profile {
            font-weight: 600;
            color: var(--primary);
            cursor: default;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .content {
            padding: 35px 35px 40px;
        }

        /* ---- page header ---- */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 28px;
        }
        .page-header h2 {
            font-size: 26px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
        }
        .page-header .badge-role {
            background: var(--primary);
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            padding: 6px 16px;
            border-radius: 30px;
            letter-spacing: 0.3px;
            margin-left: 12px;
        }

        /* ---- tabs (All Branches · All Roles · Filters) ---- */
        .nav-tabs-custom {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 28px;
            margin-bottom: 28px;
            border-bottom: 1px solid #e2e7f2;
            padding-bottom: 12px;
        }
        .nav-tabs-custom .tab-item {
            font-weight: 600;
            font-size: 16px;
            color: #4a5a8c;
            cursor: pointer;
            padding: 6px 0;
            position: relative;
            transition: 0.1s;
            letter-spacing: 0.2px;
            text-decoration: none;
        }
        .nav-tabs-custom .tab-item:hover {
            color: var(--primary);
        }
        .nav-tabs-custom .tab-item.active {
            color: var(--primary);
        }
        .nav-tabs-custom .tab-item.active::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: -13px;
            width: 100%;
            height: 3px;
            background: var(--primary);
            border-radius: 4px;
        }
        .nav-tabs-custom .tab-item i {
            margin-right: 8px;
            font-size: 18px;
        }

        /* Filter dropdowns in tabs */
        .filter-group {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-left: auto;
        }
        .filter-group select {
            border-radius: 20px;
            border: 1px solid #d0d7e8;
            padding: 6px 16px;
            font-size: 14px;
            background: white;
            color: var(--primary);
            font-weight: 500;
            cursor: pointer;
        }
        .filter-group select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.12);
            outline: none;
        }
        .filter-group .btn-clear {
            background: none;
            border: none;
            color: #dc3545;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            padding: 6px 12px;
            border-radius: 20px;
            transition: 0.15s;
        }
        .filter-group .btn-clear:hover {
            background: #f8d7da;
        }

        /* ---- table ---- */
        .table-wrap {
            background: white;
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            padding: 6px 0 6px 0;
        }
        .table {
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }
        .table thead th {
            background: #f0f3fc;
            color: var(--primary);
            font-weight: 700;
            font-size: 15px;
            padding: 16px 20px;
            border-bottom: 1px solid #e2e7f2;
            letter-spacing: 0.3px;
        }
        .table tbody td {
            padding: 16px 20px;
            vertical-align: middle;
            border-bottom: 1px solid #edf1f8;
            color: #1f2a4a;
            font-weight: 500;
        }
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        .table tbody tr.total-row td {
            background: #f0f3fc;
            font-weight: 700;
            color: var(--primary);
            border-top: 2px solid #d7def0;
        }
        .badge-count {
            display: inline-block;
            background: #e7ecfc;
            color: var(--primary);
            font-weight: 600;
            font-size: 13px;
            padding: 4px 16px;
            border-radius: 40px;
        }
        .badge-count.active-badge {
            background: #d4f0d4;
            color: #1a6e1a;
        }
        .badge-count.inactive-badge {
            background: #f8d7da;
            color: #721c24;
        }
        .filter-info {
            font-size: 14px;
            color: #4a5a8c;
            padding: 8px 0;
            margin-bottom: 16px;
        }
        .filter-info strong {
            color: var(--primary);
        }

        /* responsive */
        @media (max-width: 991px) {
            .main {
                margin-left: 90px;
            }
            .sidebar {
                width: 90px;
                padding: 16px 10px;
            }
            .system-name,
            .nav-menu span,
            .logout span {
                display: none;
            }
            .logo-area {
                justify-content: center;
            }
            .nav-menu a {
                justify-content: center;
                padding: 12px 8px;
            }
            .nav-menu a i {
                font-size: 26px;
                margin: 0;
            }
            .logout a {
                justify-content: center;
            }
            .filter-group {
                margin-left: 0;
                width: 100%;
                margin-top: 8px;
            }
            .filter-group select {
                flex: 1;
            }
        }

        @media (max-width: 576px) {
            .topbar {
                padding: 0 16px;
                height: 70px;
            }
            .content {
                padding: 20px 16px;
            }
            .page-header h2 {
                font-size: 22px;
            }
            .table-wrap {
                overflow-x: auto;
            }
            .nav-tabs-custom {
                gap: 4px 16px;
            }
            .nav-tabs-custom .tab-item {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>

<!-- ========== SIDEBAR (Super Admin) ========== -->
<div class="sidebar">
    <div class="logo-area">
        <div class="logo-frame">
            <img src="logo.png" alt="Smart Bite Care Logo" class="logo" />
        </div>
        <div class="system-name">Smart Bite Care</div>
    </div>

    <nav class="nav-menu">
        <ul>
            <li><a href="SuperAdmin_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="SuperAdmin_BranchManagement.php"><i class="bi bi-people-fill"></i><span>Branch Management</span></a></li>
            <li><a href="SuperAdmin_BranchAdminManagement.php"><i class="bi bi-heart-pulse-fill"></i><span>Branch Admin Management</span></a></li>
            <li><a class="active" href="SuperAdmin_UserMonitoring.php"><i class="bi bi-box-seam"></i><span>User Monitoring</span></a></li>
            <li><a href="SuperAdmin_BranchPerformanceMonitoring.php"><i class="bi bi-graph-up-arrow"></i><span>Branch Performance Monitoring</span></a></li>
            <li><a href="SuperAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
            <li><a href="SuperAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
            <li><a href="SuperAdmin_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
        </ul>
    </nav>

    <div class="logout">
        <a href="landing.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </div>
</div>

<!-- ========== MAIN CONTENT ========== -->
<div class="main">

    <!-- TOP BAR -->
    <div class="topbar">
        <h3>User Monitoring</h3>
        <div class="profile">SUPER ADMIN <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="content">

        <!-- Tabs: All Branches · All Roles · Filters -->
        <div class="nav-tabs-custom">
            <a href="SuperAdmin_UserMonitoring.php?filter=all" class="tab-item <?php echo $filter_type === 'all' ? 'active' : ''; ?>">
                <i class="bi bi-diagram-3"></i> All Branches
            </a>
            <a href="SuperAdmin_UserMonitoring.php?filter=roles" class="tab-item <?php echo $filter_type === 'roles' ? 'active' : ''; ?>">
                <i class="bi bi-person-badge"></i> All Roles
            </a>
            <a href="SuperAdmin_UserMonitoring.php?filter=branches" class="tab-item <?php echo $filter_type === 'branches' ? 'active' : ''; ?>">
                <i class="bi bi-funnel"></i> Filters
            </a>
            
            <!-- Filter Controls -->
            <?php if ($filter_type === 'branches'): ?>
                <div class="filter-group">
                    <form method="GET" action="SuperAdmin_UserMonitoring.php" style="display: inline-flex; align-items: center; gap: 10px;">
                        <input type="hidden" name="filter" value="branches" />
                        <select name="branch_id" onchange="this.form.submit()">
                            <option value="">Select Branch</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo htmlspecialchars($branch['branch_id']); ?>" 
                                        <?php echo $branch_filter === $branch['branch_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($branch['branch_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($branch_filter)): ?>
                            <a href="SuperAdmin_UserMonitoring.php?filter=all" class="btn-clear">
                                <i class="bi bi-x-circle"></i> Clear
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            <?php elseif ($filter_type === 'roles'): ?>
                <div class="filter-group">
                    <form method="GET" action="SuperAdmin_UserMonitoring.php" style="display: inline-flex; align-items: center; gap: 10px;">
                        <input type="hidden" name="filter" value="roles" />
                        <select name="role_id" onchange="this.form.submit()">
                            <option value="">Select Role</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo htmlspecialchars($role['role_id']); ?>" 
                                        <?php echo $role_filter === (string)$role['role_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($role['role_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($role_filter)): ?>
                            <a href="SuperAdmin_UserMonitoring.php?filter=all" class="btn-clear">
                                <i class="bi bi-x-circle"></i> Clear
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <!-- Filter Information -->
        <?php if ($filter_type === 'branches' && !empty($branch_filter)): ?>
            <div class="filter-info">
                <i class="bi bi-info-circle me-1"></i> 
                Showing users for branch: <strong><?php echo htmlspecialchars($selected_branch_name); ?></strong>
                <?php if (!empty($branch_stats)): ?>
                    <span class="badge-count ms-2"><?php echo array_sum(array_column($branch_stats, 'total_users')); ?> total users</span>
                <?php endif; ?>
            </div>
        <?php elseif ($filter_type === 'roles' && !empty($role_filter)): ?>
            <div class="filter-info">
                <i class="bi bi-info-circle me-1"></i> 
                Showing users with role: <strong><?php echo htmlspecialchars($selected_role_name); ?></strong>
                <?php if (!empty($role_stats)): ?>
                    <span class="badge-count ms-2"><?php echo array_sum(array_column($role_stats, 'total_users')); ?> total users</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Table -->
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th><?php echo ($filter_type === 'branches' && !empty($branch_filter)) ? 'Role' : (($filter_type === 'roles' && !empty($role_filter)) ? 'Branch' : 'Role'); ?></th>
                        <th class="text-center">Total Users</th>
                        <th class="text-center">Active</th>
                        <th class="text-center">Inactive</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($filter_type === 'branches' && !empty($branch_filter)): ?>
                        <!-- Branch-specific view -->
                        <?php if (!empty($branch_stats)): ?>
                            <?php foreach ($branch_stats as $stat): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($stat['role_name']); ?></strong></td>
                                    <td class="text-center"><?php echo number_format($stat['total_users']); ?></td>
                                    <td class="text-center">
                                        <span class="badge-count active-badge"><?php echo number_format($stat['active_users']); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge-count inactive-badge"><?php echo number_format($stat['inactive_users']); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td><strong>Total</strong></td>
                                <td class="text-center"><span class="badge-count"><?php echo number_format(array_sum(array_column($branch_stats, 'total_users'))); ?></span></td>
                                <td class="text-center"><span class="badge-count active-badge"><?php echo number_format(array_sum(array_column($branch_stats, 'active_users'))); ?></span></td>
                                <td class="text-center"><span class="badge-count inactive-badge"><?php echo number_format(array_sum(array_column($branch_stats, 'inactive_users'))); ?></span></td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text-center py-4">
                                    <i class="bi bi-inbox fs-1 d-block mb-2" style="color: #ccc;"></i>
                                    No users found for this branch.
                                </td>
                            </tr>
                        <?php endif; ?>
                        
                    <?php elseif ($filter_type === 'roles' && !empty($role_filter)): ?>
                        <!-- Role-specific view -->
                        <?php if (!empty($role_stats)): ?>
                            <?php foreach ($role_stats as $stat): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($stat['branch_name'] ?: 'No Branch'); ?></strong></td>
                                    <td class="text-center"><?php echo number_format($stat['total_users']); ?></td>
                                    <td class="text-center">
                                        <span class="badge-count active-badge"><?php echo number_format($stat['active_users']); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge-count inactive-badge"><?php echo number_format($stat['inactive_users']); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td><strong>Total</strong></td>
                                <td class="text-center"><span class="badge-count"><?php echo number_format(array_sum(array_column($role_stats, 'total_users'))); ?></span></td>
                                <td class="text-center"><span class="badge-count active-badge"><?php echo number_format(array_sum(array_column($role_stats, 'active_users'))); ?></span></td>
                                <td class="text-center"><span class="badge-count inactive-badge"><?php echo number_format(array_sum(array_column($role_stats, 'inactive_users'))); ?></span></td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text-center py-4">
                                    <i class="bi bi-inbox fs-1 d-block mb-2" style="color: #ccc;"></i>
                                    No users found with this role.
                                </td>
                            </tr>
                        <?php endif; ?>
                        
                    <?php else: ?>
                        <!-- Default view - All branches/roles -->
                        <?php if (!empty($roles_data)): ?>
                            <?php foreach ($roles_data as $role): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($role['role_name']); ?></strong></td>
                                    <td class="text-center"><?php echo number_format($role['total_users']); ?></td>
                                    <td class="text-center">
                                        <span class="badge-count active-badge"><?php echo number_format($role['active_users']); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge-count inactive-badge"><?php echo number_format($role['inactive_users']); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td><strong>Total</strong></td>
                                <td class="text-center"><span class="badge-count"><?php echo number_format($total_users); ?></span></td>
                                <td class="text-center"><span class="badge-count active-badge"><?php echo number_format($total_active); ?></span></td>
                                <td class="text-center"><span class="badge-count inactive-badge"><?php echo number_format($total_inactive); ?></span></td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text-center py-4">
                                    <i class="bi bi-inbox fs-1 d-block mb-2" style="color: #ccc;"></i>
                                    No user data available.
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div> <!-- /content -->
</div> <!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>