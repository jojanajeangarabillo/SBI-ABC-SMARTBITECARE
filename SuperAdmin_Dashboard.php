<?php
session_start();
require_once 'sources/db_connect.php';

// Check if user is Super Admin
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 1
) {
    header("Location: login.php");
    exit();
}

// ============================================
// AUDIT LOG FUNCTION
// ============================================
function addAuditLog($conn, $user_id, $action, $module = 'Dashboard') {
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

// ============================================
// GET DASHBOARD STATISTICS
// ============================================

// 1. Total Branches
$branches_sql = "SELECT 
                    COUNT(*) as total_branches,
                    SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_branches,
                    SUM(CASE WHEN status = 'Inactive' THEN 1 ELSE 0 END) as inactive_branches
                 FROM branches";
$branches_result = $conn->query($branches_sql);
$branches_data = $branches_result->fetch_assoc();

// 2. Total Patients
$patients_sql = "SELECT COUNT(*) as total_patients FROM patients";
$patients_result = $conn->query($patients_sql);
$patients_data = $patients_result->fetch_assoc();

// 3. Total Users by Role
$users_sql = "SELECT 
                COUNT(*) as total_users,
                SUM(CASE WHEN role_id = 1 THEN 1 ELSE 0 END) as super_admins,
                SUM(CASE WHEN role_id = 2 THEN 1 ELSE 0 END) as branch_admins,
                SUM(CASE WHEN role_id = 3 THEN 1 ELSE 0 END) as nurses,
                SUM(CASE WHEN role_id = 4 THEN 1 ELSE 0 END) as admin_staff,
                SUM(CASE WHEN role_id = 5 THEN 1 ELSE 0 END) as inventory_officers
              FROM users WHERE status = 'Active'";
$users_result = $conn->query($users_sql);
$users_data = $users_result->fetch_assoc();

// 4. Total Cases
$cases_sql = "SELECT 
                COUNT(*) as total_cases,
                SUM(CASE WHEN case_status = 'Ongoing' THEN 1 ELSE 0 END) as ongoing_cases,
                SUM(CASE WHEN case_status = 'Completed' THEN 1 ELSE 0 END) as completed_cases
              FROM animal_bite_cases";
$cases_result = $conn->query($cases_sql);
$cases_data = $cases_result->fetch_assoc();

// 5. Total Vaccinations
$vaccinations_sql = "SELECT 
                        COUNT(*) as total_vaccinations,
                        SUM(CASE WHEN vaccination_status = 'Completed' THEN 1 ELSE 0 END) as completed_vaccinations,
                        SUM(CASE WHEN vaccination_status = 'Scheduled' THEN 1 ELSE 0 END) as scheduled_vaccinations,
                        SUM(CASE WHEN vaccination_status = 'Missed' THEN 1 ELSE 0 END) as missed_vaccinations
                      FROM vaccination_records";
$vaccinations_result = $conn->query($vaccinations_sql);
$vaccinations_data = $vaccinations_result->fetch_assoc();

// 6. Total Inventory Items
$inventory_sql = "SELECT 
                    COUNT(*) as total_items,
                    SUM(minimum_stock) as total_minimum_stock
                  FROM inventory_items";
$inventory_result = $conn->query($inventory_sql);
$inventory_data = $inventory_result->fetch_assoc();

// 7. Total Inventory Stocks by Branch
$stock_sql = "SELECT 
                COUNT(*) as total_stocks,
                SUM(quantity_available) as total_quantity_available
              FROM inventory_stocks";
$stock_result = $conn->query($stock_sql);
$stock_data = $stock_result->fetch_assoc();

// 8. Recent Activities (Last 5)
$activities_sql = "SELECT 
                    al.action, 
                    al.module, 
                    al.created_at,
                    u.username,
                    b.branch_name
                  FROM audit_logs al
                  LEFT JOIN users u ON al.user_id = u.user_id
                  LEFT JOIN branches b ON al.branch_id = b.branch_id
                  ORDER BY al.created_at DESC
                  LIMIT 5";
$activities_result = $conn->query($activities_sql);
$activities = [];
while ($row = $activities_result->fetch_assoc()) {
    $activities[] = $row;
}

// 9. System Alerts (Inventory alerts)
$alerts_sql = "SELECT 
                i.item_name,
                s.quantity_available,
                i.minimum_stock,
                b.branch_name,
                'Low Stock' as alert_type
              FROM inventory_stocks s
              JOIN inventory_items i ON s.item_id = i.item_id
              JOIN branches b ON s.branch_id = b.branch_id
              WHERE s.quantity_available <= i.minimum_stock
              ORDER BY s.quantity_available ASC
              LIMIT 4";
$alerts_result = $conn->query($alerts_sql);
$alerts = [];
while ($row = $alerts_result->fetch_assoc()) {
    $alerts[] = $row;
}

// 10. Monthly Statistics (Last 6 months)
$monthly_sql = "SELECT 
                  DATE_FORMAT(created_at, '%Y-%m') as month,
                  COUNT(*) as total
                FROM audit_logs
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                ORDER BY month ASC";
$monthly_result = $conn->query($monthly_sql);
$monthly_data = [];
while ($row = $monthly_result->fetch_assoc()) {
    $monthly_data[] = $row;
}

// 11. Branch Performance (Top 5 branches by cases)
$branch_performance_sql = "SELECT 
                            b.branch_name,
                            COUNT(abc.case_id) as total_cases
                          FROM branches b
                          LEFT JOIN animal_bite_cases abc ON b.branch_id = abc.branch_id
                          WHERE b.status = 'Active'
                          GROUP BY b.branch_id
                          ORDER BY total_cases DESC
                          LIMIT 5";
$branch_performance_result = $conn->query($branch_performance_sql);
$branch_performance = [];
while ($row = $branch_performance_result->fetch_assoc()) {
    $branch_performance[] = $row;
}

// 12. Today's Activities
$today_sql = "SELECT COUNT(*) as today_activities FROM audit_logs WHERE DATE(created_at) = CURDATE()";
$today_result = $conn->query($today_sql);
$today_data = $today_result->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Super Admin Dashboard</title>
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="sidebar.css" />
    <style>
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

        .dashboard {
            padding: 35px 35px 40px;
        }

        /* ---- stat cards ---- */
        .stat-card {
            background: var(--card-bg);
            border-radius: 18px;
            padding: 20px 22px;
            height: 140px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.06);
            display: flex;
            flex-direction: column;
            justify-content: center;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
        }
        .stat-title {
            font-weight: 600;
            color: var(--primary);
            font-size: 17px;
            letter-spacing: 0.2px;
        }
        .stat-number {
            margin-top: 8px;
            font-size: 44px;
            font-weight: 700;
            color: var(--primary);
            line-height: 1.1;
        }
        .stat-sub {
            font-size: 14px;
            font-weight: 400;
            color: #4a5a8c;
            margin-top: 2px;
        }
        .stat-sub .highlight {
            color: var(--accent);
            font-weight: 600;
        }

        /* ---- large cards ---- */
        .large-card {
            background: var(--card-bg);
            border-radius: 18px;
            padding: 22px 24px;
            margin-top: 25px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.06);
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 16px;
        }
        .section-title small {
            font-weight: 400;
            font-size: 15px;
            color: #6c7a9e;
        }

        /* activity & alert items */
        .activity,
        .alert-item {
            margin-bottom: 16px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 15px;
            color: #1f2a4a;
            padding: 10px 14px;
            background: rgba(255,255,255,0.5);
            border-radius: 10px;
            transition: background 0.2s;
        }
        .activity:hover,
        .alert-item:hover {
            background: rgba(255,255,255,0.8);
        }
        .activity i {
            color: var(--accent);
            font-size: 14px;
            margin-top: 4px;
            flex-shrink: 0;
        }
        .alert-item i {
            color: #ff6b35;
            font-size: 18px;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .activity small,
        .alert-item small {
            color: #4a5a8c;
            font-size: 12px;
            display: block;
            margin-top: 2px;
        }
        .module-badge {
            display: inline-block;
            background: var(--primary);
            color: white;
            font-size: 10px;
            padding: 1px 10px;
            border-radius: 12px;
            margin-right: 6px;
        }
        .text-end.mt-4 {
            margin-top: auto;
            padding-top: 14px;
        }

        .btn-custom {
            background: var(--primary);
            color: white;
            border-radius: 8px;
            padding: 8px 22px;
            border: none;
            font-weight: 600;
            transition: 0.15s;
        }
        .btn-custom:hover {
            background: #1d2863;
            color: #fff;
        }

        .alert-stock {
            font-weight: 600;
            color: #dc3545;
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
        }

        @media (max-width: 576px) {
            .topbar {
                padding: 0 16px;
                height: 70px;
            }
            .topbar h3 {
                font-size: 20px;
            }
            .dashboard {
                padding: 20px 16px;
            }
            .stat-number {
                font-size: 34px;
            }
            .stat-card {
                height: 120px;
            }
        }
    </style>
</head>
<body>

<!-- ========== SIDEBAR ========== -->
<div class="sidebar">
    <div class="logo-area">
        <div class="logo-frame">
            <img src="logo.png" alt="Smart Bite Care Logo" class="logo" />
        </div>
        <div class="system-name">Smart Bite Care</div>
    </div>

    <nav class="nav-menu">
        <ul>
            <li><a class="active" href="#"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="SuperAdmin_BranchManagement.php"><i class="bi bi-people-fill"></i><span>Branch Management</span></a></li>
            <li><a href="SuperAdmin_BranchAdminManagement.php"><i class="bi bi-heart-pulse-fill"></i><span>Branch Admin Management</span></a></li>
            <li><a href="SuperAdmin_UserMonitoring.php"><i class="bi bi-box-seam"></i><span>User Monitoring</span></a></li>
            <li><a href="SuperAdmin_BranchPerformanceMonitoring.php"><i class="bi bi-graph-up-arrow"></i><span>Branch Performance Monitoring</span></a></li>
            <li><a href="SuperAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
            <li><a href="SuperAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
            <li><a href="SuperAdmin_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
        </ul>
    </nav>

    <div class="logout">
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </div>
</div>

<!-- ========== MAIN CONTENT ========== -->
<div class="main">

    <!-- TOP BAR -->
    <div class="topbar">
        <h3>Dashboard</h3>
        <div class="profile">
            <?php echo htmlspecialchars($_SESSION['username'] ?? 'SUPER ADMIN'); ?>
            <i class="bi bi-caret-down-fill"></i>
        </div>
    </div>

    <!-- DASHBOARD CONTENT -->
    <div class="dashboard">

        <!-- FIRST ROW: 6 Stat Cards -->
        <div class="row g-4">

            <!-- Total Branches -->
            <div class="col-lg-4 col-md-6">
                <div class="stat-card">
                    <div class="stat-title"><i class="bi bi-building me-2"></i>Total Branches</div>
                    <div class="stat-number"><?php echo number_format($branches_data['total_branches'] ?? 0); ?></div>
                    <div class="stat-sub">
                        <span class="text-success"><?php echo $branches_data['active_branches'] ?? 0; ?> Active</span> | 
                        <span class="text-danger"><?php echo $branches_data['inactive_branches'] ?? 0; ?> Inactive</span>
                    </div>
                </div>
            </div>

            <!-- Total Patients -->
            <div class="col-lg-4 col-md-6">
                <div class="stat-card">
                    <div class="stat-title"><i class="bi bi-person me-2"></i>Total Patients</div>
                    <div class="stat-number"><?php echo number_format($patients_data['total_patients'] ?? 0); ?></div>
                    <div class="stat-sub">All Time Total</div>
                </div>
            </div>

            <!-- Total Users -->
            <div class="col-lg-4 col-md-6">
                <div class="stat-card">
                    <div class="stat-title"><i class="bi bi-people me-2"></i>Total Users</div>
                    <div class="stat-number"><?php echo number_format($users_data['total_users'] ?? 0); ?></div>
                    <div class="stat-sub">
                        <span class="highlight"><?php echo $users_data['super_admins'] ?? 0; ?></span> Super Admins | 
                        <span class="highlight"><?php echo $users_data['branch_admins'] ?? 0; ?></span> Branch Admins
                    </div>
                </div>
            </div>

            <!-- Total Cases -->
            <div class="col-lg-4 col-md-6">
                <div class="stat-card">
                    <div class="stat-title"><i class="bi bi-clipboard2-pulse me-2"></i>Total Cases</div>
                    <div class="stat-number"><?php echo number_format($cases_data['total_cases'] ?? 0); ?></div>
                    <div class="stat-sub">
                        <span class="text-warning"><?php echo $cases_data['ongoing_cases'] ?? 0; ?> Ongoing</span> | 
                        <span class="text-success"><?php echo $cases_data['completed_cases'] ?? 0; ?> Completed</span>
                    </div>
                </div>
            </div>

            <!-- Total Vaccinations -->
            <div class="col-lg-4 col-md-6">
                <div class="stat-card">
                    <div class="stat-title"><i class="bi bi-syringe me-2"></i>Total Vaccinations</div>
                    <div class="stat-number"><?php echo number_format($vaccinations_data['total_vaccinations'] ?? 0); ?></div>
                    <div class="stat-sub">
                        <span class="text-success"><?php echo $vaccinations_data['completed_vaccinations'] ?? 0; ?> Completed</span> | 
                        <span class="text-warning"><?php echo $vaccinations_data['scheduled_vaccinations'] ?? 0; ?> Scheduled</span>
                    </div>
                </div>
            </div>

            <!-- Total Inventory Items -->
            <div class="col-lg-4 col-md-6">
                <div class="stat-card">
                    <div class="stat-title"><i class="bi bi-boxes me-2"></i>Total Inventory Items</div>
                    <div class="stat-number"><?php echo number_format($inventory_data['total_items'] ?? 0); ?></div>
                    <div class="stat-sub">
                        <?php echo number_format($stock_data['total_quantity_available'] ?? 0); ?> Total Stock Available
                    </div>
                </div>
            </div>
        </div>

        <!-- SECOND ROW: Recent Activities & System Alerts -->
        <div class="row g-4 mt-2">

            <!-- Recent System Activities -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="section-title">
                        Recent System Activities 
                        <small>(Today: <?php echo $today_data['today_activities'] ?? 0; ?> activities)</small>
                    </div>

                    <?php if (count($activities) > 0): ?>
                        <?php foreach ($activities as $activity): ?>
                            <div class="activity">
                                <i class="bi bi-square-fill"></i>
                                <div>
                                    <span class="module-badge"><?php echo htmlspecialchars($activity['module'] ?? 'System'); ?></span>
                                    <strong><?php echo htmlspecialchars($activity['username'] ?? 'System'); ?></strong>
                                    <br />
                                    <?php echo htmlspecialchars($activity['action'] ?? 'No action details'); ?>
                                    <br />
                                    <small><?php echo date('M d, Y h:i A', strtotime($activity['created_at'])); ?></small>
                                    <?php if ($activity['branch_name']): ?>
                                        <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars($activity['branch_name']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                            No recent activities found.
                        </div>
                    <?php endif; ?>

                    <div class="text-end mt-3">
                        <a href="SuperAdmin_AuditLogs.php" class="btn btn-custom">
                            <i class="bi bi-arrow-right me-1"></i> View All
                        </a>
                    </div>
                </div>
            </div>

            <!-- System Alerts -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="section-title">
                        System Alerts 
                        <small>(<?php echo count($alerts); ?> low stock items)</small>
                    </div>

                    <?php if (count($alerts) > 0): ?>
                        <?php foreach ($alerts as $alert): ?>
                            <div class="alert-item">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <div>
                                    <strong class="alert-stock">
                                        <?php echo htmlspecialchars($alert['item_name']); ?>
                                    </strong>
                                    <br />
                                    Low stock in <strong><?php echo htmlspecialchars($alert['branch_name']); ?></strong> 
                                    (<?php echo htmlspecialchars($alert['quantity_available']); ?> / <?php echo htmlspecialchars($alert['minimum_stock']); ?> minimum)
                                    <br />
                                    <small>Action required: Replenish stock immediately</small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-success py-4">
                            <i class="bi bi-check-circle-fill fs-1 d-block mb-2" style="color:#28a745;"></i>
                            <p>No alerts! All inventory levels are healthy.</p>
                        </div>
                    <?php endif; ?>

                    <div class="text-end mt-3">
                        <a href="SuperAdmin_BranchPerformanceMonitoring.php" class="btn btn-custom">
                            <i class="bi bi-arrow-right me-1"></i> View All
                        </a>
                    </div>
                </div>
            </div>

        </div>

        <!-- THIRD ROW: Branch Performance -->
        <div class="row g-4 mt-2">
            <div class="col-12">
                <div class="large-card">
                    <div class="section-title">
                        <i class="bi bi-trophy me-2"></i>Branch Performance
                        <small>(Top branches by total cases)</small>
                    </div>

                    <?php if (count($branch_performance) > 0): ?>
                        <div class="row g-3">
                            <?php foreach ($branch_performance as $index => $branch): ?>
                                <div class="col-md-2 col-6">
                                    <div class="text-center p-3 bg-white rounded-3" style="border-left: 4px solid <?php echo $index === 0 ? '#FFD700' : ($index === 1 ? '#C0C0C0' : ($index === 2 ? '#CD7F32' : 'var(--primary)')); ?>">
                                        <div style="font-size: 28px; font-weight: 700; color: var(--primary);">
                                            <?php echo number_format($branch['total_cases'] ?? 0); ?>
                                        </div>
                                        <div style="font-size: 13px; color: #4a5a8c; font-weight: 600;">
                                            <?php echo htmlspecialchars($branch['branch_name']); ?>
                                        </div>
                                        <?php if ($index === 0): ?>
                                            <span class="badge bg-warning text-dark">🏆 Top</span>
                                        <?php elseif ($index === 1): ?>
                                            <span class="badge bg-secondary">🥈 2nd</span>
                                        <?php elseif ($index === 2): ?>
                                            <span class="badge bg-danger">🥉 3rd</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-bar-chart-line fs-1 d-block mb-2"></i>
                            No branch performance data available.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- FOURTH ROW: Monthly Activity Chart (Text-based) -->
        <?php if (count($monthly_data) > 0): ?>
        <div class="row g-4 mt-2">
            <div class="col-12">
                <div class="large-card">
                    <div class="section-title">
                        <i class="bi bi-graph-up me-2"></i>Monthly Activity Trend
                        <small>(Last 6 months)</small>
                    </div>
                    <div class="row g-2">
                        <?php 
                        $max_value = max(array_column($monthly_data, 'total'));
                        $max_value = $max_value > 0 ? $max_value : 1;
                        ?>
                        <?php foreach ($monthly_data as $data): ?>
                            <?php 
                            $percentage = ($data['total'] / $max_value) * 100;
                            $month_name = date('M Y', strtotime($data['month'] . '-01'));
                            ?>
                            <div class="col-2 text-center">
                                <div style="font-size: 12px; color: #4a5a8c; font-weight: 600;"><?php echo $data['total']; ?></div>
                                <div class="progress" style="height: 80px; width: 30px; margin: 0 auto; background: #e2e7f2; border-radius: 8px;">
                                    <div class="progress-bar" style="height: <?php echo $percentage; ?>%; width: 100%; background: var(--primary); border-radius: 8px;" role="progressbar"></div>
                                </div>
                                <div style="font-size: 11px; color: #6c7a9a; margin-top: 4px;"><?php echo $month_name; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div> <!-- /dashboard -->
</div> <!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>