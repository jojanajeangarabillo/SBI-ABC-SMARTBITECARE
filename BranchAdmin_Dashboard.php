<?php
session_start();
require_once 'sources/db_connect.php';

// Check if user is logged in and is branch admin
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 2 // Assuming role_id 2 is for branch admin
) {
    header("Location: login.php");
    exit();
}

// Get user branch information
$user_id = $_SESSION['user_id'];
$branch_id = null;
$branch_name = '';

// Fetch user's branch info
$userQuery = "SELECT u.branch_id, b.branch_name 
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
}

// If no branch assigned, show error or redirect
if (!$branch_id) {
    // You might want to handle this case differently
    $branch_name = 'No Branch Assigned';
}

// Fetch dynamic statistics for the branch
$stats = [];

// Total patients for this branch
$patientQuery = "SELECT COUNT(DISTINCT p.patient_id) as total 
                 FROM patients p 
                 JOIN animal_bite_cases abc ON p.patient_id = abc.patient_id 
                 WHERE abc.branch_id = ?";
$stmt = $conn->prepare($patientQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$patientResult = $stmt->get_result();
$stats['total_patients'] = $patientResult->fetch_assoc()['total'] ?? 0;

// Ongoing cases
$ongoingQuery = "SELECT COUNT(*) as ongoing 
                 FROM animal_bite_cases 
                 WHERE branch_id = ? AND case_status = 'Ongoing'";
$stmt = $conn->prepare($ongoingQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$ongoingResult = $stmt->get_result();
$stats['ongoing_cases'] = $ongoingResult->fetch_assoc()['ongoing'] ?? 0;

// Completed cases
$completedQuery = "SELECT COUNT(*) as completed 
                   FROM animal_bite_cases 
                   WHERE branch_id = ? AND case_status = 'Completed'";
$stmt = $conn->prepare($completedQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$completedResult = $stmt->get_result();
$stats['completed_cases'] = $completedResult->fetch_assoc()['completed'] ?? 0;

// Low stocks
$lowStockQuery = "SELECT COUNT(*) as low_stock 
                  FROM inventory_stocks s 
                  JOIN inventory_items i ON s.item_id = i.item_id 
                  WHERE s.branch_id = ? 
                  AND s.quantity_available < i.minimum_stock";
$stmt = $conn->prepare($lowStockQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$lowStockResult = $stmt->get_result();
$stats['low_stocks'] = $lowStockResult->fetch_assoc()['low_stock'] ?? 0;

// Expiring stocks (within 30 days)
$expiringQuery = "SELECT COUNT(*) as expiring 
                  FROM inventory_stocks 
                  WHERE branch_id = ? 
                  AND expiration_date IS NOT NULL 
                  AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) 
                  AND expiration_date >= CURDATE()";
$stmt = $conn->prepare($expiringQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$expiringResult = $stmt->get_result();
$stats['expiring_stocks'] = $expiringResult->fetch_assoc()['expiring'] ?? 0;

// Fetch recent prediction alerts for this branch
$alerts = [];
$alertQuery = "SELECT pr.*, i.item_name 
               FROM prediction_results pr 
               JOIN inventory_items i ON pr.item_id = i.item_id 
               WHERE pr.branch_id = ? 
               AND pr.prediction_status = 'High Risk' 
               ORDER BY pr.prediction_date DESC 
               LIMIT 5";
$stmt = $conn->prepare($alertQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$alertResult = $stmt->get_result();
while ($row = $alertResult->fetch_assoc()) {
    $alerts[] = $row;
}

// Fetch recent activities for this branch
$activities = [];
$activityQuery = "SELECT * FROM audit_logs 
                  WHERE branch_id = ? 
                  ORDER BY created_at DESC 
                  LIMIT 5";
$stmt = $conn->prepare($activityQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$activityResult = $stmt->get_result();
while ($row = $activityResult->fetch_assoc()) {
    $activities[] = $row;
}

// Fetch user info for profile display
$userInfoQuery = "SELECT username, email FROM users WHERE user_id = ?";
$stmt = $conn->prepare($userInfoQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$userInfoResult = $stmt->get_result();
$userInfo = $userInfoResult->fetch_assoc();
$username = $userInfo['username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Branch Admin Dashboard - <?php echo htmlspecialchars($branch_name); ?></title>

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

height:140px;

box-shadow:0 3px 8px rgba(0,0,0,.08);

}

.stat-title{

font-weight:600;

color:var(--primary);

font-size:18px;

}

.stat-number{

margin-top:20px;

font-size:48px;

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

}

.placeholder-box{

height:220px;

background:#ddd;

display:flex;

align-items:center;

justify-content:center;

font-size:34px;

color:#999;

border-radius:12px;

}

.btn-custom{

background:var(--primary);

color:white;

border-radius:8px;

padding:8px 18px;

border:none;

}

.btn-custom:hover{

background:#1d2863;

}

.activity{

margin-bottom:14px;

}

.activity i{

color:var(--accent);

margin-right:10px;

}

.alert-item{

margin-bottom:14px;

}

.alert-item i{

color:var(--accent);

margin-right:10px;

}

@media(max-width:991px){

.main{

margin-left:90px;

}

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

<li><a class="active" href="BranchAdmin_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>

<li><a href="BranchAdmin_UserManagement.php"><i class="bi bi-people-fill"></i><span>User Management</span></a></li>

<li><a href="BranchAdmin_PatientMonitoring.php"><i class="bi bi-heart-pulse-fill"></i><span>Patient Monitoring</span></a></li>

<li><a href="BranchAdmin_MedicalSupplies.php"><i class="bi bi-box-seam"></i><span>Medical Supplies</span></a></li>

<li><a href="BranchAdmin_PredictionModule.php"><i class="bi bi-graph-up-arrow"></i><span>Prediction Module</span></a></li>

<li><a href="BranchAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>

<li><a href="BranchAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>

<li><a href="BranchAdmin_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>

<li><a href="BranchAdmin_Settings.php"><i class="bi bi-gear-fill"></i><span>Settings</span></a></li>

</ul>

</nav>

<div class="logout">
<a href="landing.php"> <i class="bi bi-box-arrow-right"></i>
<span>Logout</span>
</a>
</div>

</div>

<!-- Main Content -->

<div class="main">

<div class="topbar">
<h3>Dashboard <small><?php echo htmlspecialchars($branch_name); ?></small></h3>
<div class="profile"> <?php echo htmlspecialchars($username); ?> <i class="bi bi-caret-down-fill"></i> </div>
</div>

<div class="dashboard">
<div class="row g-4">

<div class="col-lg-4">

<div class="stat-card">
<div class="stat-title">Total Patients</div>
<div class="stat-number"><?php echo number_format($stats['total_patients']); ?></div>
</div>

</div>

<div class="col-lg-4">

<div class="stat-card">
<div class="stat-title">Ongoing Cases</div>
<div class="stat-number"><?php echo number_format($stats['ongoing_cases']); ?></div>
</div>

</div>

<div class="col-lg-4">

<div class="stat-card">
<div class="stat-title">Completed Cases</div>
<div class="stat-number"><?php echo number_format($stats['completed_cases']); ?></div>
</div>

</div>

<div class="col-lg-6">

<div class="stat-card">
<div class="stat-title">Low Stocks</div>
<div class="stat-number"><?php echo number_format($stats['low_stocks']); ?></div>
</div>

</div>

<div class="col-lg-6">

<div class="stat-card">
<div class="stat-title">Expiring Stocks</div>
<div class="stat-number"><?php echo number_format($stats['expiring_stocks']); ?></div>
</div>

</div>

        <div class="col-lg-6">

            <div class="large-card">

                <div class="section-title">
                    Patient Trend <small class="text-muted">(This Month)</small>
                </div>

                <div class="placeholder-box">
                    PLACEHOLDER FOR CHART
                </div>

            </div>

        </div>

        <div class="col-lg-6">

            <div class="large-card">

                <div class="section-title">
                    Supply Usage Trend
                </div>

                <div class="placeholder-box">
                    PLACEHOLDER FOR CHART
                </div>

            </div>

        </div>

        <div class="col-lg-6">

            <div class="large-card">

                <div class="section-title">
                    Prediction Alerts
                </div>

                <?php if (empty($alerts)): ?>
                    <div class="alert-item">
                        <i class="bi bi-check-circle-fill" style="color: #28a745;"></i>
                        No high-risk predictions at this time.
                    </div>
                <?php else: ?>
                    <?php foreach ($alerts as $alert): ?>
                        <div class="alert-item">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            High risk of shortage. <?php echo htmlspecialchars($alert['item_name']); ?> 
                            (Score: <?php echo $alert['probability_score']; ?>%)
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="text-end mt-4">
                    <button class="btn btn-custom" onclick="window.location.href='BranchAdmin_PredictionModule.php'">
                        View All Alerts
                    </button>
                </div>

            </div>

        </div>

        <div class="col-lg-6">

            <div class="large-card">

                <div class="section-title">
                    Recent Activities
                </div>

                <?php if (empty($activities)): ?>
                    <div class="activity">
                        <i class="bi bi-square-fill"></i>
                        No recent activities
                    </div>
                <?php else: ?>
                    <?php foreach ($activities as $activity): ?>
                        <div class="activity">
                            <i class="bi bi-square-fill"></i>
                            <?php echo htmlspecialchars($activity['action']); ?>
                            <small class="text-muted">(<?php echo date('M d, Y h:i A', strtotime($activity['created_at'])); ?>)</small>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="text-end mt-4">
                    <button class="btn btn-custom" onclick="window.location.href='BranchAdmin_AuditLogs.php'">
                        View All
                    </button>
                </div>

            </div>

        </div>

    </div>

</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>