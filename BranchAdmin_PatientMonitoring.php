<?php
session_start();
require_once 'sources/db_connect.php';

// Check if user is logged in and is a branch admin
checkUserRole([2]); // role_id 2 = Branch Admin

// Get user data
$userData = getUserData($conn, $_SESSION['user_id']);
$branchId = $userData['branch_id'];

// Handle AJAX request for patient details
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_patient') {
    header('Content-Type: application/json');
    
    if (!isset($_GET['id']) || empty($_GET['id'])) {
        echo json_encode(['success' => false, 'message' => 'Patient ID is required']);
        exit();
    }
    
    $patientId = (int)$_GET['id'];
    
    // Get patient details with branch validation
    $patientQuery = "SELECT 
                        p.*,
                        b.branch_name,
                        TIMESTAMPDIFF(YEAR, p.birthday, CURDATE()) as age
                     FROM patients p
                     LEFT JOIN branches b ON p.branch_id = b.branch_id
                     WHERE p.patient_id = ? AND p.branch_id = ?";
    $stmt = $conn->prepare($patientQuery);
    $stmt->bind_param("is", $patientId, $branchId);
    $stmt->execute();
    $patientResult = $stmt->get_result();
    
    if ($patientResult->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Patient not found or you don\'t have access']);
        exit();
    }
    
    $patient = $patientResult->fetch_assoc();
    
    // Get case information
    $caseQuery = "SELECT 
                     case_id,
                     case_status,
                     date_of_bite,
                     animal_type,
                     bite_location,
                     bite_category,
                     animal_status,
                     remarks as case_remarks,
                     created_at as case_created_at
                  FROM animal_bite_cases
                  WHERE patient_id = ? AND branch_id = ?
                  ORDER BY created_at DESC
                  LIMIT 1";
    $stmt = $conn->prepare($caseQuery);
    $stmt->bind_param("is", $patientId, $branchId);
    $stmt->execute();
    $caseResult = $stmt->get_result();
    $case = $caseResult->fetch_assoc();
    
    // Get vaccination records
    $vaccQuery = "SELECT 
                     vaccination_id,
                     dose_number,
                     date_administered,
                     scheduled_date,
                     administered_at,
                     vaccination_status,
                     is_final_dose,
                     remarks
                  FROM vaccination_records
                  WHERE patient_id = ? AND branch_id = ?
                  ORDER BY dose_number ASC";
    $stmt = $conn->prepare($vaccQuery);
    $stmt->bind_param("is", $patientId, $branchId);
    $stmt->execute();
    $vaccResult = $stmt->get_result();
    $vaccinations = [];
    while ($row = $vaccResult->fetch_assoc()) {
        $vaccinations[] = $row;
    }
    
    // Return JSON response
    echo json_encode([
        'success' => true,
        'patient' => $patient,
        'case' => $case,
        'vaccinations' => $vaccinations
    ]);
    exit();
}

// Handle filtering and searching
$search = isset($_GET['search']) ? $_GET['search'] : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Build the query for patients with their cases
$whereConditions = "p.branch_id = ?";
$params = [$branchId];
$types = "s";

if (!empty($search)) {
    $whereConditions .= " AND (p.full_name LIKE ? OR p.patient_id LIKE ? OR p.email LIKE ? OR p.contact_number LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "ssss";
}

if (!empty($statusFilter)) {
    $whereConditions .= " AND ac.case_status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

// Get total count for pagination
$countQuery = "SELECT COUNT(DISTINCT p.patient_id) as total 
               FROM patients p 
               LEFT JOIN animal_bite_cases ac ON p.patient_id = ac.patient_id 
               WHERE $whereConditions";
$stmt = $conn->prepare($countQuery);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$countResult = $stmt->get_result();
$totalRecords = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalRecords / $limit);

// Get patients data with their latest case
$query = "SELECT 
            p.patient_id,
            p.full_name,
            p.email,
            p.contact_number,
            p.gender,
            p.birthday,
            p.address,
            p.created_at as registration_date,
            b.branch_name,
            ac.case_id,
            ac.case_status,
            ac.date_of_bite,
            ac.created_at as case_created_at,
            ac.animal_type,
            ac.bite_location,
            ac.bite_category,
            ac.animal_status,
            ac.remarks as case_remarks,
            ac.admin_staff_id
          FROM patients p
          LEFT JOIN branches b ON p.branch_id = b.branch_id
          LEFT JOIN animal_bite_cases ac ON p.patient_id = ac.patient_id 
          WHERE $whereConditions
          GROUP BY p.patient_id
          ORDER BY p.created_at DESC
          LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$patients = $stmt->get_result();

// Get all distinct statuses for filter
$statusQuery = "SELECT DISTINCT case_status FROM animal_bite_cases WHERE case_status IS NOT NULL";
$statusResult = $conn->query($statusQuery);

// Get branch name
$branchQuery = "SELECT branch_name FROM branches WHERE branch_id = ?";
$stmt = $conn->prepare($branchQuery);
$stmt->bind_param("s", $branchId);
$stmt->execute();
$branchResult = $stmt->get_result();
$branchName = $branchResult->fetch_assoc()['branch_name'] ?? 'Unknown Branch';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Branch Admin Patient Monitoring - <?php echo htmlspecialchars($branchName); ?></title>
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
        .topbar h3 small {
            font-size: 16px;
            font-weight: 400;
            color: #666;
            margin-left: 10px;
        }

        .profile {
            font-weight: 600;
            color: var(--primary);
            cursor: pointer;
        }

        @media (max-width:991px) {
            .main {
                margin-left: 90px;
            }
        }

        .content-wrapper {
            padding: 28px 35px 40px 35px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            gap: 20px;
            flex-wrap: wrap;
        }

        .search-box {
            display: flex;
            align-items: center;
            background: white;
            border: 1px solid var(--primary);
            border-radius: 50px;
            padding: 8px 18px;
            flex: 1;
            max-width: 400px;
        }

        .search-box i {
            color: var(--primary);
            font-size: 18px;
            margin-right: 10px;
        }

        .search-box input {
            border: none;
            outline: none;
            background: transparent;
            color: black;
            width: 100%;
            font-size: 14px;
        }

        .search-box input::placeholder {
            color: rgba(0, 0, 0, 0.85);
        }

        .search-box form {
            width: 100%;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .search-box button {
            background: none;
            border: none;
            color: var(--primary);
            padding: 0 5px;
            font-size: 18px;
        }

        .filter-group {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-group select {
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid #d9dee8;
            background: white;
            color: #1e293b;
            font-size: 14px;
            min-width: 150px;
        }

        .filter-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(43, 58, 140, 0.1);
        }

        .btn-clear {
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid #d9dee8;
            background: white;
            color: #6b7280;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-clear:hover {
            background: #f1f2f6;
            color: #1e293b;
        }

        .btn-refresh {
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid var(--primary);
            background: white;
            color: var(--primary);
            text-decoration: none;
            font-size: 14px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-refresh:hover {
            background: var(--primary);
            color: white;
        }

        .table-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
            overflow: hidden;
        }

        .table-card .table {
            margin: 0;
            border: 1px solid #d9dee8;
        }

        .table-card .table thead th {
            background: var(--primary);
            color: #fff;
            padding: 16px 20px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: 2px solid #e9edf4;
        }

        .table-card .table tbody td {
            padding: 14px 20px;
            color: #1e293b;
            border-bottom: 1px solid #f0f2f7;
            vertical-align: middle;
        }

        .table-card .table tbody tr:hover {
            background: #f8faff;
        }

        .badge-status {
            font-weight: 600;
            font-size: 12px;
            padding: 5px 14px;
            border-radius: 20px;
            letter-spacing: 0.2px;
        }

        .badge-completed {
            background: #dff0e6;
            color: #0f7b3a;
        }

        .badge-ongoing {
            background: #fff3cd;
            color: #856404;
        }

        .badge-scheduled {
            background: #cce5ff;
            color: #004085;
        }

        .badge-missed {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-no-case {
            background: #f1f2f6;
            color: #6b7280;
        }

        .action-icon {
            color: var(--primary);
            font-size: 20px;
            opacity: 0.7;
            transition: opacity 0.2s, transform 0.15s;
            display: inline-block;
            text-decoration: none;
            padding: 5px;
            cursor: pointer;
        }

        .action-icon:hover {
            opacity: 1;
            transform: scale(1.1);
            color: var(--primary);
        }

        .pagination-wrap {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }

        .pagination-wrap .pagination {
            margin: 0;
            gap: 2px;
        }

        .pagination-wrap .page-link {
            border: none;
            color: #2d3a7a;
            font-weight: 600;
            font-size: 14px;
            padding: 8px 15px;
            border-radius: 8px;
            background: transparent;
            transition: background 0.15s, color 0.15s;
        }

        .pagination-wrap .page-link:hover {
            background: #eef2ff;
            color: var(--primary);
        }

        .pagination-wrap .page-item.active .page-link {
            background: var(--primary);
            color: #fff;
            border-radius: 8px;
        }

        .pagination-wrap .page-item.disabled .page-link {
            color: #b0b8c8;
            opacity: 0.6;
            cursor: not-allowed;
        }

        .pagination-wrap .page-item:first-child .page-link,
        .pagination-wrap .page-item:last-child .page-link {
            font-size: 16px;
            padding: 8px 12px;
        }

        .branch-info {
            background: #f8faff;
            padding: 12px 20px;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .branch-info i {
            color: var(--primary);
            font-size: 20px;
        }

        .branch-info span {
            color: #1e293b;
            font-weight: 500;
        }

        .total-patients {
            color: #6b7280;
            font-size: 14px;
        }

        .no-patients {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }

        .no-patients i {
            font-size: 56px;
            color: #d9dee8;
            display: block;
            margin-bottom: 16px;
        }

        .no-patients h5 {
            color: #1e293b;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .no-patients p {
            max-width: 400px;
            margin: 0 auto;
            color: #9ca3af;
        }

        .patient-detail {
            font-size: 13px;
            color: #6b7280;
            margin-top: 2px;
        }

        /* Modal Styles */
        .modal-content {
            border-radius: 14px;
            border: none;
        }

        .modal-header {
            background: var(--primary);
            color: white;
            border-radius: 14px 14px 0 0;
            padding: 20px 25px;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        .modal-body {
            padding: 25px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 15px 25px;
            border-top: 1px solid #e9edf4;
        }

        .detail-section {
            margin-bottom: 25px;
        }

        .detail-section:last-child {
            margin-bottom: 0;
        }

        .detail-section h6 {
            color: var(--primary);
            font-weight: 700;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #f0f2f7;
        }

        .detail-row {
            display: flex;
            padding: 6px 0;
            border-bottom: 1px solid #f8faff;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 600;
            color: #1e293b;
            width: 140px;
            flex-shrink: 0;
            font-size: 14px;
        }

        .detail-value {
            color: #4a5568;
            font-size: 14px;
            flex: 1;
        }

        .detail-value .badge {
            font-size: 12px;
            padding: 4px 12px;
        }

        .vaccination-card {
            background: #f8faff;
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 10px;
            border-left: 3px solid var(--primary);
        }

        .vaccination-card:last-child {
            margin-bottom: 0;
        }

        .vaccination-card .vaccine-dose {
            font-weight: 600;
            color: var(--primary);
        }

        .vaccination-card .vaccine-date {
            color: #6b7280;
            font-size: 13px;
        }

        .vaccination-card .vaccine-status {
            font-size: 12px;
        }

        .no-data {
            color: #9ca3af;
            font-style: italic;
            font-size: 14px;
        }

        .modal-dialog {
            max-width: 700px;
        }

        .btn-close-modal {
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px 24px;
            border-radius: 8px;
            font-weight: 600;
            transition: background 0.2s;
        }

        .btn-close-modal:hover {
            background: #1f2d6e;
            color: white;
        }

        @media (max-width: 768px) {
            .content-wrapper {
                padding: 18px 16px 30px 16px;
            }

            .page-header {
                flex-direction: column;
                align-items: stretch;
                gap: 14px;
            }

            .search-box {
                max-width: 100%;
            }

            .filter-group {
                flex-direction: column;
                width: 100%;
            }

            .filter-group select {
                width: 100%;
            }

            .table-card .table thead th,
            .table-card .table tbody td {
                padding: 12px 14px;
                font-size: 13px;
            }

            .pagination-wrap .page-link {
                padding: 6px 11px;
                font-size: 13px;
            }

            .branch-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .modal-dialog {
                margin: 10px;
            }

            .detail-row {
                flex-direction: column;
                padding: 8px 0;
            }

            .detail-label {
                width: 100%;
                margin-bottom: 2px;
                font-size: 13px;
            }

            .detail-value {
                font-size: 13px;
            }
        }

        @media (max-width: 576px) {
            .table-card .table thead th {
                font-size: 11px;
                padding: 10px 10px;
            }

            .table-card .table tbody td {
                font-size: 12px;
                padding: 10px 10px;
            }

            .badge-status {
                font-size: 10px;
                padding: 4px 10px;
            }
        }

        .admin-profile {
            font-weight: 700;
            color: var(--primary);
            cursor: default;
            font-size: 15px;
            letter-spacing: 0.3px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .admin-profile i {
            font-size: 12px;
            opacity: 0.7;
        }

        /* Loading spinner for modal */
        .modal-loading {
            text-align: center;
            padding: 40px 20px;
        }

        .modal-loading .spinner-border {
            color: var(--primary);
        }
    </style>
</head>
<body>
    <!-- SIDEBAR -->
    <div class="sidebar">
        <div class="logo-area">
            <div class="logo-frame">
                <img src="logo.png" alt="Smart Bite Care Logo" class="logo">
            </div>
            <div class="system-name">
                Smart Bite Care
            </div>
        </div>

        <nav class="nav-menu">
            <ul>
                <li><a href="BranchAdmin_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
                <li><a href="BranchAdmin_UserManagement.php"><i class="bi bi-people-fill"></i><span>User Management</span></a></li>
                <li><a class="active" href="BranchAdmin_PatientMonitoring.php"><i class="bi bi-heart-pulse-fill"></i><span>Patient Monitoring</span></a></li>
                <li><a href="BranchAdmin_MedicalSupplies.php"><i class="bi bi-box-seam"></i><span>Medical Supplies</span></a></li>
                <li><a href="BranchAdmin_PredictionModule.php"><i class="bi bi-graph-up-arrow"></i><span>Prediction Module</span></a></li>
                <li><a href="BranchAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
                <li><a href="BranchAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
                <li><a href="BranchAdmin_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
                <li><a href="BranchAdmin_Settings.php"><i class="bi bi-gear-fill"></i><span>Settings</span></a></li>
            </ul>
        </nav>

        <div class="logout">
            <a href="logout.php"> <i class="bi bi-box-arrow-right"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main">
        <!-- Top Header -->
        <div class="topbar">
            <h3>Patient Monitoring <small><?php echo htmlspecialchars($branchName); ?></small></h3>
            <div class="profile">
                <?php echo htmlspecialchars($userData['username'] ?? 'ADMIN'); ?> 
                <i class="bi bi-caret-down-fill"></i>
            </div>
        </div>

        <div class="content-wrapper">
            

            <!-- Filter Section -->
            <div class="page-header">
                <div class="search-box">
                    <i class="bi bi-search"></i>
                    <form method="GET" action="" id="searchForm">
                        <input type="text" name="search" placeholder="Search by name, ID, email or contact..." 
                               value="<?php echo htmlspecialchars($search); ?>" id="searchInput">
                        <?php if (!empty($statusFilter)): ?>
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                        <?php endif; ?>
                        <button type="submit"><i class="bi bi-arrow-right-circle"></i></button>
                    </form>
                </div>

                <div class="filter-group">
                    <form method="GET" action="" id="filterForm" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                        <?php if (!empty($search)): ?>
                            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                        <?php endif; ?>
                        <select name="status" onchange="this.form.submit()">
                            <option value="">All Statuses</option>
                            <?php while ($status = $statusResult->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($status['case_status']); ?>" 
                                    <?php echo $statusFilter == $status['case_status'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($status['case_status'] ?: 'No Status'); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <?php if (!empty($search) || !empty($statusFilter)): ?>
                            <a href="BranchAdmin_PatientMonitoring.php" class="btn-clear">
                                <i class="bi bi-x-circle"></i> Clear
                            </a>
                        <?php endif; ?>
                        <a href="BranchAdmin_PatientMonitoring.php" class="btn-refresh">
                            <i class="bi bi-arrow-clockwise"></i> Refresh
                        </a>
                    </form>
                </div>
            </div>

            <!-- Table -->
            <div class="table-card">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Patient Name</th>
                                <th>Contact</th>
                                <th>Registration Date</th>
                                <th>Case Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($patients->num_rows > 0): ?>
                                <?php while ($patient = $patients->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <span class="fw-bold">#<?php echo htmlspecialchars($patient['patient_id']); ?></span>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($patient['full_name']); ?></strong>
                                            <?php if (!empty($patient['email'])): ?>
                                                <div class="patient-detail">
                                                    <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($patient['email']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($patient['gender']) && !empty($patient['birthday'])): ?>
                                                <div class="patient-detail">
                                                    <i class="bi bi-person"></i> <?php echo htmlspecialchars($patient['gender']); ?> • 
                                                    <?php 
                                                    $birthday = new DateTime($patient['birthday']);
                                                    $today = new DateTime();
                                                    $age = $today->diff($birthday)->y;
                                                    echo $age . ' years old';
                                                    ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($patient['contact_number'])): ?>
                                                <div><i class="bi bi-phone"></i> <?php echo htmlspecialchars($patient['contact_number']); ?></div>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                            <?php if (!empty($patient['address'])): ?>
                                                <div class="patient-detail">
                                                    <i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars(substr($patient['address'], 0, 30)) . (strlen($patient['address']) > 30 ? '...' : ''); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div><?php echo date('Y-m-d', strtotime($patient['registration_date'])); ?></div>
                                            <div class="patient-detail">
                                                <i class="bi bi-clock"></i> <?php echo date('h:i A', strtotime($patient['registration_date'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php 
                                            $status = $patient['case_status'] ?? 'No Case';
                                            $badgeClass = 'badge-no-case';
                                            if ($status == 'Completed') $badgeClass = 'badge-completed';
                                            elseif ($status == 'Ongoing') $badgeClass = 'badge-ongoing';
                                            elseif ($status == 'Scheduled') $badgeClass = 'badge-scheduled';
                                            elseif ($status == 'Missed') $badgeClass = 'badge-missed';
                                            ?>
                                            <span class="badge-status <?php echo $badgeClass; ?>">
                                                <?php echo htmlspecialchars($status); ?>
                                            </span>
                                            <?php if (!empty($patient['date_of_bite'])): ?>
                                                <div class="patient-detail">
                                                    <i class="bi bi-calendar-event"></i> Bite: <?php echo date('Y-m-d', strtotime($patient['date_of_bite'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="#" class="action-icon view-patient" 
                                               data-patient-id="<?php echo $patient['patient_id']; ?>"
                                               data-bs-toggle="modal" 
                                               data-bs-target="#patientModal"
                                               title="View Patient Details">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="no-patients">
                                            <i class="bi bi-people"></i>
                                            <h5>No Patients Found</h5>
                                            <p>
                                                <?php if (!empty($search) || !empty($statusFilter)): ?>
                                                    No patients in <strong><?php echo htmlspecialchars($branchName); ?></strong> 
                                                    matching your search criteria.
                                                    <br><small>Try adjusting your search or filter criteria.</small>
                                                <?php else: ?>
                                                    No patients are currently registered in <strong><?php echo htmlspecialchars($branchName); ?></strong>.
                                                    <br><small>Patients will appear here once they are registered.</small>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination-wrap">
                <nav aria-label="Patient table pagination">
                    <ul class="pagination">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page-1; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?><?php echo !empty($statusFilter) ? '&status='.urlencode($statusFilter) : ''; ?>" aria-label="Previous">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        <?php 
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        if ($startPage > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=1<?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?><?php echo !empty($statusFilter) ? '&status='.urlencode($statusFilter) : ''; ?>">1</a>
                            </li>
                            <?php if ($startPage > 2): ?>
                                <li class="page-item disabled"><span class="page-link">…</span></li>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?><?php echo !empty($statusFilter) ? '&status='.urlencode($statusFilter) : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">…</span></li>
                            <?php endif; ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $totalPages; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?><?php echo !empty($statusFilter) ? '&status='.urlencode($statusFilter) : ''; ?>"><?php echo $totalPages; ?></a>
                            </li>
                        <?php endif; ?>
                        
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page+1; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?><?php echo !empty($statusFilter) ? '&status='.urlencode($statusFilter) : ''; ?>" aria-label="Next">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
            
            <!-- Results Info -->
            <?php if ($patients->num_rows > 0): ?>
            <div class="text-center text-muted small mt-3">
                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $totalRecords); ?> of <?php echo $totalRecords; ?> patients
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Patient Details Modal -->
    <div class="modal fade" id="patientModal" tabindex="-1" aria-labelledby="patientModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="patientModalLabel">
                        <i class="bi bi-person-badge me-2"></i> Patient Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="patientModalBody">
                    <div class="modal-loading">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-3 text-muted">Loading patient information...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-close-modal" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i> Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-submit search when typing stops (optional)
        let searchTimeout;
        document.getElementById('searchInput')?.addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                document.getElementById('searchForm').submit();
            }
        });

        // View Patient - Load data via AJAX
        document.addEventListener('DOMContentLoaded', function() {
            const viewButtons = document.querySelectorAll('.view-patient');
            const modalBody = document.getElementById('patientModalBody');
            
            viewButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const patientId = this.dataset.patientId;
                    
                    // Show loading
                    modalBody.innerHTML = `
                        <div class="modal-loading">
                            <div class="spinner-border" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-3 text-muted">Loading patient information...</p>
                        </div>
                    `;
                    
                    // Fetch patient data from same file with ajax parameter
                    fetch(`?ajax=get_patient&id=${patientId}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                renderPatientDetails(data);
                            } else {
                                modalBody.innerHTML = `
                                    <div class="alert alert-danger">
                                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                        ${data.message || 'Failed to load patient details.'}
                                    </div>
                                `;
                            }
                        })
                        .catch(error => {
                            modalBody.innerHTML = `
                                <div class="alert alert-danger">
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                    An error occurred while loading patient details.
                                </div>
                            `;
                            console.error('Error:', error);
                        });
                });
            });
        });

        function renderPatientDetails(data) {
            const patient = data.patient;
            const caseData = data.case;
            const vaccinations = data.vaccinations || [];
            
            // Status badge class
            let statusBadge = 'badge-no-case';
            let statusText = caseData ? caseData.case_status : 'No Case';
            if (statusText === 'Completed') statusBadge = 'badge-completed';
            else if (statusText === 'Ongoing') statusBadge = 'badge-ongoing';
            else if (statusText === 'Scheduled') statusBadge = 'badge-scheduled';
            else if (statusText === 'Missed') statusBadge = 'badge-missed';
            
            let html = '';
            
            // Patient Information Section
            html += `
                <div class="detail-section">
                    <h6><i class="bi bi-person-fill me-2"></i>Patient Information</h6>
                    <div class="detail-row">
                        <span class="detail-label">Patient ID</span>
                        <span class="detail-value"><strong>#${patient.patient_id}</strong></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Full Name</span>
                        <span class="detail-value"><strong>${patient.full_name}</strong></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Email</span>
                        <span class="detail-value">${patient.email || '<span class="no-data">Not provided</span>'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Contact Number</span>
                        <span class="detail-value">${patient.contact_number || '<span class="no-data">Not provided</span>'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Gender</span>
                        <span class="detail-value">${patient.gender || '<span class="no-data">Not provided</span>'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Birthday</span>
                        <span class="detail-value">${patient.birthday ? patient.birthday + ' (' + patient.age + ' years old)' : '<span class="no-data">Not provided</span>'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Address</span>
                        <span class="detail-value">${patient.address || '<span class="no-data">Not provided</span>'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Branch</span>
                        <span class="detail-value">${patient.branch_name}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Registration Date</span>
                        <span class="detail-value">${patient.registration_date}</span>
                    </div>
                </div>
            `;
            
            // Case Information Section
            html += `
                <div class="detail-section">
                    <h6><i class="bi bi-file-medical me-2"></i>Case Information</h6>
            `;
            
            if (caseData) {
                html += `
                    <div class="detail-row">
                        <span class="detail-label">Case ID</span>
                        <span class="detail-value"><strong>#${caseData.case_id}</strong></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Status</span>
                        <span class="detail-value"><span class="badge-status ${statusBadge}">${caseData.case_status || 'No Status'}</span></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Date of Bite</span>
                        <span class="detail-value">${caseData.date_of_bite || '<span class="no-data">Not recorded</span>'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Animal Type</span>
                        <span class="detail-value">${caseData.animal_type || '<span class="no-data">Not recorded</span>'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Bite Location</span>
                        <span class="detail-value">${caseData.bite_location || '<span class="no-data">Not recorded</span>'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Bite Category</span>
                        <span class="detail-value">${caseData.bite_category || '<span class="no-data">Not recorded</span>'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Animal Status</span>
                        <span class="detail-value">${caseData.animal_status || '<span class="no-data">Not recorded</span>'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Remarks</span>
                        <span class="detail-value">${caseData.case_remarks || '<span class="no-data">No remarks</span>'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Date Created</span>
                        <span class="detail-value">${caseData.case_created_at}</span>
                    </div>
                `;
            } else {
                html += `
                    <div class="text-center py-3">
                        <i class="bi bi-info-circle" style="font-size: 24px; color: #d9dee8;"></i>
                        <p class="no-data mt-2">No case record found for this patient.</p>
                    </div>
                `;
            }
            html += `</div>`;
            
            // Vaccination Records Section
            html += `
                <div class="detail-section">
                    <h6><i class="bi bi-shield-check me-2"></i>Vaccination Records</h6>
            `;
            
            if (vaccinations.length > 0) {
                vaccinations.forEach(vacc => {
                    let vaccStatusBadge = 'badge-no-case';
                    if (vacc.vaccination_status === 'Completed') vaccStatusBadge = 'badge-completed';
                    else if (vacc.vaccination_status === 'Scheduled') vaccStatusBadge = 'badge-scheduled';
                    else if (vacc.vaccination_status === 'Missed') vaccStatusBadge = 'badge-missed';
                    
                    html += `
                        <div class="vaccination-card">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <span class="vaccine-dose">Dose ${vacc.dose_number}</span>
                                    <div class="vaccine-date">
                                        <i class="bi bi-calendar3 me-1"></i>
                                        ${vacc.date_administered ? 'Administered: ' + vacc.date_administered : 'Scheduled: ' + vacc.scheduled_date}
                                    </div>
                                    ${vacc.administered_at ? `<div class="vaccine-date"><i class="bi bi-geo-alt me-1"></i>${vacc.administered_at}</div>` : ''}
                                    ${vacc.remarks ? `<div class="vaccine-date"><i class="bi bi-chat me-1"></i>${vacc.remarks}</div>` : ''}
                                </div>
                                <span class="badge-status ${vaccStatusBadge}">${vacc.vaccination_status || 'Pending'}</span>
                            </div>
                            ${vacc.is_final_dose ? '<span class="badge bg-primary ms-2" style="font-size: 10px;">Final Dose</span>' : ''}
                        </div>
                    `;
                });
            } else {
                html += `
                    <div class="text-center py-3">
                        <i class="bi bi-shield-slash" style="font-size: 24px; color: #d9dee8;"></i>
                        <p class="no-data mt-2">No vaccination records found for this patient.</p>
                    </div>
                `;
            }
            html += `</div>`;
            
            document.getElementById('patientModalBody').innerHTML = html;
        }
    </script>
</body>
</html>