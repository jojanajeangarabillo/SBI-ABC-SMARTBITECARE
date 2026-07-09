<?php
session_start();
require_once 'sources/db_connect.php';

// Check if user is logged in and is an admin staff
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 4 // role_id 4 is for Admin Staff
) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$branch_id = null;
$branch_name = '';
$username = '';
$role_name = 'Admin Staff';

// Get user's branch info
$userQuery = "SELECT u.branch_id, u.username, b.branch_name, r.role_name
              FROM users u 
              LEFT JOIN branches b ON u.branch_id = b.branch_id
              LEFT JOIN roles r ON u.role_id = r.role_id
              WHERE u.user_id = ?";
$stmt = $conn->prepare($userQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$userResult = $stmt->get_result();

if ($userResult->num_rows > 0) {
    $userData = $userResult->fetch_assoc();
    $branch_id = $userData['branch_id'];
    $branch_name = $userData['branch_name'] ?? 'Unknown Branch';
    $username = $userData['username'] ?? 'Admin Staff';
    $role_name = $userData['role_name'] ?? 'Admin Staff';
}

// If no branch assigned
if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
}

// ----------------------------------------------------------------------
// FETCH PHILHEALTH STATUS STATISTICS
// ----------------------------------------------------------------------

// Get PhilHealth status breakdown
$statusQuery = "
    SELECT 
        ph.status,
        COUNT(*) as count
    FROM animal_bite_cases c
    INNER JOIN philhealth_records ph ON c.case_id = ph.case_id
    WHERE c.branch_id = ?
    GROUP BY ph.status
";
$stmt = $conn->prepare($statusQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$statusResult = $stmt->get_result();

$philhealthStatus = [
    'For Writing' => 0,
    'For Screening' => 0,
    'For Signing' => 0,
    'For Transmittal' => 0,
    'Completed' => 0
];

while ($row = $statusResult->fetch_assoc()) {
    $status = $row['status'] ?? 'For Writing';
    if ($status == 'For Signing' || $status == 'For Transmittal') {
        $philhealthStatus['For Signing'] += (int)$row['count'];
    } else {
        $philhealthStatus[$status] = (int)$row['count'];
    }
}

// ----------------------------------------------------------------------
// FETCH PHILHEALTH PATIENT RECORDS WITH PAGINATION
// ----------------------------------------------------------------------

// Get current page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Build WHERE clause
$whereClauses = ["c.branch_id = ?"];
$params = [$branch_id];
$types = "s";

if (!empty($search)) {
    $whereClauses[] = "(p.full_name LIKE ? OR r.registry_number LIKE ? OR ph.philhealth_number LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
}

if (!empty($statusFilter)) {
    $whereClauses[] = "ph.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if (!empty($dateFrom)) {
    $whereClauses[] = "DATE(COALESCE(c.date_of_bite, c.created_at)) >= ?";
    $params[] = $dateFrom;
    $types .= "s";
}

if (!empty($dateTo)) {
    $whereClauses[] = "DATE(COALESCE(c.date_of_bite, c.created_at)) <= ?";
    $params[] = $dateTo;
    $types .= "s";
}

$whereSQL = implode(" AND ", $whereClauses);

// Get total count for pagination
$countQuery = "
    SELECT COUNT(*) as total
    FROM animal_bite_cases c
    INNER JOIN philhealth_records ph ON c.case_id = ph.case_id
    INNER JOIN patients p ON c.patient_id = p.patient_id
    LEFT JOIN registry_records r ON c.case_id = r.case_id
    WHERE $whereSQL
";
$stmt = $conn->prepare($countQuery);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$countResult = $stmt->get_result();
$totalRecords = $countResult->fetch_assoc()['total'] ?? 0;
$totalPages = ceil($totalRecords / $limit);

// Get records for current page
$recordsQuery = "
    SELECT 
        c.case_id,
        c.patient_id,
        p.full_name as patient_name,
        p.contact_number,
        DATE(COALESCE(c.date_of_bite, c.created_at)) as admission_date,
        DATE(c.created_at) as date_of_confinement,
        c.case_status,
        c.remarks as case_remarks,
        r.registry_number as case_no,
        r.remarks as registry_remarks,
        ph.philhealth_record_id,
        ph.philhealth_number,
        ph.status as philhealth_status,
        ph.remarks as philhealth_remarks,
        ph.updated_at
    FROM animal_bite_cases c
    INNER JOIN philhealth_records ph ON c.case_id = ph.case_id
    INNER JOIN patients p ON c.patient_id = p.patient_id
    LEFT JOIN registry_records r ON c.case_id = r.case_id
    WHERE $whereSQL
    ORDER BY c.created_at DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($recordsQuery);
$params[] = $limit;
$params[] = $offset;
$types .= "ii";
$stmt->bind_param($types, ...$params);
$stmt->execute();
$recordsResult = $stmt->get_result();

$philhealthRecords = [];
while ($row = $recordsResult->fetch_assoc()) {
    $philhealthRecords[] = $row;
}

// ----------------------------------------------------------------------
// HANDLE AJAX REQUESTS (for real-time updates)
// ----------------------------------------------------------------------
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    // Get updated stats
    $statusQuery = "
        SELECT 
            ph.status,
            COUNT(*) as count
        FROM animal_bite_cases c
        INNER JOIN philhealth_records ph ON c.case_id = ph.case_id
        WHERE c.branch_id = ?
        GROUP BY ph.status
    ";
    $stmt = $conn->prepare($statusQuery);
    $stmt->bind_param("s", $branch_id);
    $stmt->execute();
    $statusResult = $stmt->get_result();
    
    $updatedStatus = [
        'For Writing' => 0,
        'For Screening' => 0,
        'For Signing' => 0,
        'For Transmittal' => 0,
        'Completed' => 0
    ];
    
    while ($row = $statusResult->fetch_assoc()) {
        $status = $row['status'] ?? 'For Writing';
        if ($status == 'For Signing' || $status == 'For Transmittal') {
            $updatedStatus['For Signing'] += (int)$row['count'];
        } else {
            $updatedStatus[$status] = (int)$row['count'];
        }
    }
    
    // Get updated records (without pagination for simplicity)
    $recordsQuery = "
        SELECT 
            c.case_id,
            p.full_name as patient_name,
            DATE(COALESCE(c.date_of_bite, c.created_at)) as admission_date,
            DATE(c.created_at) as date_of_confinement,
            r.registry_number as case_no,
            ph.status as philhealth_status,
            ph.remarks as philhealth_remarks
        FROM animal_bite_cases c
        INNER JOIN philhealth_records ph ON c.case_id = ph.case_id
        INNER JOIN patients p ON c.patient_id = p.patient_id
        LEFT JOIN registry_records r ON c.case_id = r.case_id
        WHERE c.branch_id = ?
        ORDER BY c.created_at DESC
        LIMIT 10
    ";
    $stmt = $conn->prepare($recordsQuery);
    $stmt->bind_param("s", $branch_id);
    $stmt->execute();
    $recordsResult = $stmt->get_result();
    $records = [];
    while ($row = $recordsResult->fetch_assoc()) {
        $records[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'status' => $updatedStatus,
        'records' => $records
    ]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PhilHealth Patient Status - SmartBiteCare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="sidebar.css">

    <style>
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
            --bg: #F2F2F2;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --gray-100: #f8f9fc;
            --gray-200: #f1f3f5;
            --gray-500: #adb5bd;
            --gray-600: #6c757d;
            --gray-700: #495057;
            --gray-900: #212529;
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            --radius: 12px;
            --transition: all 0.25s ease;
        }

        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', sans-serif;
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
            background: #f0f2f5;
        }

        .topbar {
            background: white;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 35px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .08);
            margin-bottom: 0;
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
            display: flex;
            align-items: center;
            gap: 6px;
        }

        @media (max-width: 991px) {
            .main {
                margin-left: 90px;
            }
        }

        /* Content */
        .content {
            padding: 30px;
        }

        /* Statistics */
        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-box {
            background: #fff;
            border-radius: 18px;
            padding: 22px 28px;
            min-height: 125px;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
            transition: var(--transition);
        }

        .stat-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,.12);
        }

        .stat-box::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            width: 6px;
            height: 100%;
            background: var(--primary);
        }

        .stat-box.writing::before {
            background: #4E79A7;
        }
        .stat-box.screening::before {
            background: #F28E2B;
        }
        .stat-box.signing::before {
            background: #E15759;
        }
        .stat-box.completed::before {
            background: #76B7B2;
        }

        .stat-box h1 {
            margin: 0;
            font-size: 48px;
            font-weight: 700;
            line-height: 1;
            color: var(--primary);
        }

        .stat-box.writing h1 {
            color: #4E79A7;
        }
        .stat-box.screening h1 {
            color: #F28E2B;
        }
        .stat-box.signing h1 {
            color: #E15759;
        }
        .stat-box.completed h1 {
            color: #76B7B2;
        }

        .stat-box h6 {
            margin: 8px 0 4px;
            font-size: 20px;
            font-weight: 600;
            text-transform: uppercase;
            color: #4f6482;
        }

        .stat-box p {
            margin: 0;
            color: #9aa6b2;
            font-size: 14px;
        }

        /* Toolbar */
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .left-tools {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-box {
            width: 320px;
            height: 45px;
            border: 1px solid var(--primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            padding: 0 15px;
            background: #fff;
            transition: var(--transition);
        }

        .search-box:focus-within {
            box-shadow: 0 0 0 3px rgba(43,58,140,0.12);
        }

        .search-box i {
            color: var(--primary);
            margin-right: 10px;
        }

        .search-box input {
            width: 100%;
            border: none;
            outline: none;
            font-size: 13px;
            background: transparent;
        }

        .toolbar-btn {
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 22px;
            font-size: 14px;
            font-weight: 600;
            transition: var(--transition);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .toolbar-btn:hover {
            background: #1f2d6b;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(43,58,140,0.25);
        }

        .toolbar-btn i {
            margin-right: 4px;
        }

        .date-box {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 8px 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fff;
        }

        .date-box input {
            border: none;
            outline: none;
            font-size: 13px;
            background: transparent;
        }

        /* Status Filters */
        .status-filters {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .status-filter-btn {
            padding: 6px 16px;
            border-radius: 20px;
            border: 2px solid #e0e0e0;
            background: transparent;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            color: var(--gray-600);
        }

        .status-filter-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .status-filter-btn.active {
            border-color: var(--primary);
            background: var(--primary);
            color: white;
        }

        .status-filter-btn.writing.active {
            border-color: #4E79A7;
            background: #4E79A7;
            color: white;
        }
        .status-filter-btn.screening.active {
            border-color: #F28E2B;
            background: #F28E2B;
            color: white;
        }
        .status-filter-btn.signing.active {
            border-color: #E15759;
            background: #E15759;
            color: white;
        }
        .status-filter-btn.completed.active {
            border-color: #76B7B2;
            background: #76B7B2;
            color: white;
        }

        /* Table */
        .table-wrapper {
            background: #fff;
            border-radius: 20px;
            overflow: hidden;
            border: 1px solid #e9ecef;
            box-shadow: var(--shadow);
        }

        .table {
            width: 100%;
            margin-bottom: 0;
            border-collapse: collapse;
        }

        .table thead tr {
            background: var(--primary);
        }

        .table thead th {
            background: var(--primary) !important;
            color: #fff !important;
            border: none !important;
            padding: 16px;
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
        }

        .table tbody td {
            text-align: center;
            vertical-align: middle;
            padding: 14px;
            border-top: 1px solid #e9ecef;
            font-size: 14px;
        }

        .table tbody tr:hover {
            background: var(--gray-100);
        }

        .table td .action-link {
            color: var(--primary);
            margin: 0 4px;
            text-decoration: none;
            font-size: 16px;
            padding: 4px 6px;
            border-radius: 4px;
            transition: var(--transition);
        }

        .table td .action-link:hover {
            background: rgba(43,58,140,0.08);
        }

        .table td .action-link.delete:hover {
            color: var(--danger);
            background: rgba(220,53,69,0.08);
        }

        .no-records {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray-500);
        }

        .no-records i {
            font-size: 48px;
            display: block;
            margin-bottom: 12px;
            opacity: 0.4;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-badge.for-writing {
            background: #cce5ff;
            color: #004085;
        }

        .status-badge.for-screening {
            background: #ffe5cc;
            color: #853d04;
        }

        .status-badge.for-signing {
            background: #f8d7da;
            color: #721c24;
        }

        .status-badge.for-transmittal {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-badge.completed {
            background: #d4edda;
            color: #155724;
        }

        .status-badge.ongoing {
            background: #fff3cd;
            color: #856404;
        }

        /* Pagination */
        .pagination-area {
            margin-top: 30px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pagination-area .page-item {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            border: 1px solid transparent;
            font-size: 14px;
            color: var(--gray-700);
            text-decoration: none;
        }

        .pagination-area .page-item:hover {
            background: var(--gray-100);
            border-color: #ddd;
        }

        .pagination-area .page-item.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .pagination-area .page-item.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-area .page-item .bi {
            font-size: 16px;
        }

        /* Loading Spinner */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.8);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .loading-overlay.show {
            display: flex;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Toast Notifications */
        .toast-container-custom {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 9999;
            max-width: 380px;
        }

        .toast-custom {
            background: white;
            border-radius: 12px;
            padding: 16px 20px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            border-left: 5px solid var(--success);
            display: flex;
            align-items: center;
            gap: 12px;
            transform: translateX(120%);
            transition: transform 0.4s cubic-bezier(0.34,1.56,0.64,1);
            margin-bottom: 10px;
        }

        .toast-custom.show {
            transform: translateX(0);
        }

        .toast-custom.error {
            border-left-color: var(--danger);
        }

        .toast-custom .toast-icon {
            font-size: 24px;
            color: var(--success);
        }

        .toast-custom.error .toast-icon {
            color: var(--danger);
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .stats {
                grid-template-columns: 1fr;
            }

            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .left-tools {
                flex-direction: column;
                align-items: stretch;
            }

            .search-box {
                width: 100%;
            }

            .status-filters {
                justify-content: center;
            }

            .content {
                padding: 16px;
            }

            .table-wrapper {
                overflow-x: auto;
            }

            .table {
                font-size: 12px;
            }

            .table thead th,
            .table tbody td {
                padding: 8px 10px;
                font-size: 11px;
            }

            .topbar {
                padding: 0 16px;
                height: 64px;
            }

            .topbar h3 {
                font-size: 20px;
            }
        }

        @media (max-width: 400px) {
            .stat-box h1 {
                font-size: 36px;
            }
            .stat-box h6 {
                font-size: 16px;
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

        .branch-indicator {
            font-size: 13px;
            color: var(--gray-600);
            background: var(--gray-100);
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-block;
        }

        .action-group {
            display: flex;
            justify-content: center;
            gap: 4px;
            flex-wrap: wrap;
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
            <div class="system-name">Smart Bite Care</div>
        </div>

        <nav class="nav-menu">
            <ul>
                <li><a href="AdminStaff_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
                <li><a href="AdminStaff_Calendar.php"><i class="bi bi-calendar-fill"></i><span>Calendar</span></a></li>
                <li><a href="AdminStaff_PatientRecord.php"><i class="bi bi-people-fill"></i><span>Patient Record Management</span></a></li>
                <li><a class="active" href="AdminStaff_PhilhealthStatus.php"><i class="bi bi-check2-all"></i><span>PhilHealth Patient Status</span></a></li>
                <li><a href="AdminStaff_MedicalDocuments.php"><i class="bi bi-file-earmark-ruled"></i><span>Medical Documents</span></a></li>
                <li><a href="AdminStaff_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
            </ul>
        </nav>

        <div class="logout">
            <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main">
        <!-- Top Header -->
        <div class="topbar">
            <h3>PhilHealth Patient Status <span style="font-size:16px; color:#6c757d; font-weight:400; margin-left:8px;"> <?php echo htmlspecialchars($branch_name); ?> </span> </h3>
            <div class="profile">
                <?php echo htmlspecialchars($username); ?>
                <i class="bi bi-caret-down-fill"></i>
            </div>
        </div>

        <div class="content">
            <!-- Statistics -->
            <div class="stats">
                <div class="stat-box writing">
                    <h1 id="writingCount"><?php echo $philhealthStatus['For Writing']; ?></h1>
                    <h6>For Writing</h6>
                    <p>Waiting to be processed</p>
                </div>

                <div class="stat-box screening">
                    <h1 id="screeningCount"><?php echo $philhealthStatus['For Screening']; ?></h1>
                    <h6>For Screening</h6>
                    <p>Under verification</p>
                </div>

                <div class="stat-box signing">
                    <h1 id="signingCount"><?php echo $philhealthStatus['For Signing']; ?></h1>
                    <h6>For Signing/Transmittal</h6>
                    <p>Ready for approval</p>
                </div>

                <div class="stat-box completed">
                    <h1 id="completedCount"><?php echo $philhealthStatus['Completed']; ?></h1>
                    <h6>Completed</h6>
                    <p>Successfully processed</p>
                </div>
            </div>

            <!-- Controls -->
            <div class="toolbar">
                <div class="left-tools">
                    <div class="search-box">
                        <i class="bi bi-search"></i>
                        <input type="text" id="searchInput" placeholder="Search Patient, Case No., Status, etc..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <button class="toolbar-btn" id="clearFiltersBtn">
                        <i class="bi bi-eraser"></i> Clear
                    </button>

                    <button class="toolbar-btn" id="exportBtn">
                        <i class="bi bi-file-earmark-arrow-down"></i> Export
                    </button>
                </div>

                <div class="status-filters">
                    <button class="status-filter-btn <?php echo empty($statusFilter) ? 'active' : ''; ?>" data-status="">All</button>
                    <button class="status-filter-btn writing <?php echo $statusFilter == 'For Writing' ? 'active' : ''; ?>" data-status="For Writing">Writing</button>
                    <button class="status-filter-btn screening <?php echo $statusFilter == 'For Screening' ? 'active' : ''; ?>" data-status="For Screening">Screening</button>
                    <button class="status-filter-btn signing <?php echo $statusFilter == 'For Signing' ? 'active' : ''; ?>" data-status="For Signing">Signing</button>
                    <button class="status-filter-btn completed <?php echo $statusFilter == 'Completed' ? 'active' : ''; ?>" data-status="Completed">Completed</button>
                </div>
            </div>

            <!-- Table -->
            <div class="table-wrapper">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Case No.</th>
                            <th>Patient Name</th>
                            <th>Date of Confinement</th>
                            <th>Date of Discharge</th>
                            <th>Status</th>
                            <th>Remarks</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="recordsBody">
                        <?php if (empty($philhealthRecords)): ?>
                        <tr>
                            <td colspan="7">
                                <div class="no-records">
                                    <i class="bi bi-inbox"></i>
                                    <p>No PhilHealth records found.</p>
                                    <small class="text-muted">Patient records with PhilHealth coverage will appear here.</small>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($philhealthRecords as $record): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($record['case_no'] ?? 'N/A'); ?></strong></td>
                            <td><?php echo htmlspecialchars($record['patient_name']); ?></td>
                            <td><?php echo htmlspecialchars($record['date_of_confinement'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($record['admission_date'] ?? ''); ?></td>
                            <td>
                                <span class="status-badge <?php echo strtolower(str_replace(' ', '-', $record['philhealth_status'] ?? 'for-writing')); ?>">
                                    <?php echo htmlspecialchars($record['philhealth_status'] ?? 'N/A'); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($record['philhealth_remarks'] ?? $record['case_remarks'] ?? 'N/A'); ?></td>
                            <td>
                                <div class="action-group">
                                    <a href="AdminStaff_PatientRecord.php?action=view&case_id=<?php echo $record['case_id']; ?>" class="action-link" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="AdminStaff_PatientRecord.php?action=edit&case_id=<?php echo $record['case_id']; ?>" class="action-link" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="#" class="action-link delete" data-case-id="<?php echo $record['case_id']; ?>" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="pagination-area" id="paginationArea">
                <?php if ($totalPages > 1): ?>
                <a href="#" class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>" data-page="<?php echo $page - 1; ?>" id="prevPage">
                    <i class="bi bi-chevron-left"></i>
                </a>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="#" class="page-item <?php echo $i == $page ? 'active' : ''; ?>" data-page="<?php echo $i; ?>">
                    <?php echo $i; ?>
                </a>
                <?php endfor; ?>

                <a href="#" class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" data-page="<?php echo $page + 1; ?>" id="nextPage">
                    <i class="bi bi-chevron-right"></i>
                </a>
                <?php endif; ?>
            </div>

            <!-- Record count info -->
            <div class="text-center mt-3">
                <small class="text-muted">
                    Showing <?php echo count($philhealthRecords); ?> of <?php echo $totalRecords; ?> records
                    <?php if (!empty($branch_name)): ?>
                    • Branch: <?php echo htmlspecialchars($branch_name); ?>
                    <?php endif; ?>
                </small>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container-custom" id="toastContainer"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // ----------------------------------------------------------------
        // TOAST NOTIFICATIONS
        // ----------------------------------------------------------------
        function showToast(msg, sub = '', isError = false) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'toast-custom' + (isError ? ' error' : '');
            const icon = isError ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill';
            toast.innerHTML = `
                <span class="toast-icon"><i class="bi ${icon}"></i></span>
                <div class="toast-msg">${msg} ${sub ? '<small>' + sub + '</small>' : ''}</div>
            `;
            container.appendChild(toast);
            requestAnimationFrame(() => toast.classList.add('show'));
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 400);
            }, 3500);
        }

        // ----------------------------------------------------------------
        // LOADING OVERLAY
        // ----------------------------------------------------------------
        function showLoading() {
            document.getElementById('loadingOverlay').classList.add('show');
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').classList.remove('show');
        }

        // ----------------------------------------------------------------
        // SEARCH AND FILTER
        // ----------------------------------------------------------------
        let searchTimeout = null;

        function applyFilters() {
            const search = document.getElementById('searchInput').value.trim();
            const status = document.querySelector('.status-filter-btn.active')?.dataset.status || '';
            
            // Build URL with parameters
            let url = window.location.pathname + '?';
            if (search) url += 'search=' + encodeURIComponent(search) + '&';
            if (status) url += 'status=' + encodeURIComponent(status) + '&';
            url += 'page=1';
            
            window.location.href = url;
        }

        // Search with debounce
        document.getElementById('searchInput').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(applyFilters, 500);
        });

        // Enter key in search
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                clearTimeout(searchTimeout);
                applyFilters();
            }
        });

        // Status filter buttons
        document.querySelectorAll('.status-filter-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.status-filter-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                applyFilters();
            });
        });

        // Clear filters
        document.getElementById('clearFiltersBtn').addEventListener('click', function() {
            document.getElementById('searchInput').value = '';
            document.querySelectorAll('.status-filter-btn').forEach(b => b.classList.remove('active'));
            document.querySelector('.status-filter-btn[data-status=""]').classList.add('active');
            window.location.href = window.location.pathname;
        });

        // ----------------------------------------------------------------
        // PAGINATION
        // ----------------------------------------------------------------
        document.querySelectorAll('.page-item:not(.disabled)').forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                const page = this.dataset.page;
                if (page) {
                    const url = new URL(window.location.href);
                    url.searchParams.set('page', page);
                    window.location.href = url.toString();
                }
            });
        });

        // ----------------------------------------------------------------
        // EXPORT
        // ----------------------------------------------------------------
        document.getElementById('exportBtn').addEventListener('click', function() {
            showLoading();
            
            // Get current URL parameters
            const params = new URLSearchParams(window.location.search);
            const exportUrl = window.location.pathname + '?export=true&' + params.toString();
            
            fetch(exportUrl, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.blob())
            .then(blob => {
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = 'philhealth_records_' + new Date().toISOString().slice(0,10) + '.csv';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(link.href);
                hideLoading();
                showToast('Export completed successfully');
            })
            .catch(error => {
                console.error('Export error:', error);
                hideLoading();
                showToast('Export failed', error.message, true);
            });
        });

        // ----------------------------------------------------------------
        // DELETE PATIENT RECORD
        // ----------------------------------------------------------------
        document.querySelectorAll('.action-link.delete').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const caseId = this.dataset.caseId;
                if (confirm('Are you sure you want to delete this patient record? This action cannot be undone.')) {
                    showLoading();
                    
                    // Delete via AJAX to PatientRecord.php
                    fetch('AdminStaff_PatientRecord.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: 'action=delete&case_id=' + caseId
                    })
                    .then(response => response.json())
                    .then(data => {
                        hideLoading();
                        if (data.success) {
                            showToast('Record deleted successfully');
                            // Reload the page to refresh the list
                            setTimeout(() => window.location.reload(), 1000);
                        } else {
                            showToast('Delete failed', data.error || 'Unknown error', true);
                        }
                    })
                    .catch(error => {
                        console.error('Delete error:', error);
                        hideLoading();
                        showToast('Delete failed', error.message, true);
                    });
                }
            });
        });

        // ----------------------------------------------------------------
        // AUTO-REFRESH (every 30 seconds)
        // ----------------------------------------------------------------
        function refreshData() {
            if (document.hidden) return;

            fetch(window.location.href, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update stats
                    if (data.status) {
                        document.getElementById('writingCount').textContent = data.status['For Writing'] || 0;
                        document.getElementById('screeningCount').textContent = data.status['For Screening'] || 0;
                        document.getElementById('signingCount').textContent = data.status['For Signing'] || 0;
                        document.getElementById('completedCount').textContent = data.status['Completed'] || 0;
                    }

                    // Update records table (only if no search/filter is active)
                    if (data.records && !document.getElementById('searchInput').value.trim()) {
                        const tbody = document.getElementById('recordsBody');
                        let html = '';
                        if (data.records.length === 0) {
                            html = `
                                <tr>
                                    <td colspan="7">
                                        <div class="no-records">
                                            <i class="bi bi-inbox"></i>
                                            <p>No PhilHealth records found.</p>
                                        </div>
                                    </td>
                                </tr>
                            `;
                        } else {
                            data.records.forEach(record => {
                                const statusClass = (record.philhealth_status || 'for-writing').toLowerCase().replace(/\s+/g, '-');
                                html += `
                                    <tr>
                                        <td><strong>${record.case_no || 'N/A'}</strong></td>
                                        <td>${record.patient_name}</td>
                                        <td>${record.date_of_confinement || ''}</td>
                                        <td>${record.admission_date || ''}</td>
                                        <td>
                                            <span class="status-badge ${statusClass}">
                                                ${record.philhealth_status || 'N/A'}
                                            </span>
                                        </td>
                                        <td>${record.philhealth_remarks || 'N/A'}</td>
                                        <td>
                                            <div class="action-group">
                                                <a href="AdminStaff_PatientRecord.php?action=view&case_id=${record.case_id}" class="action-link" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="AdminStaff_PatientRecord.php?action=edit&case_id=${record.case_id}" class="action-link" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="#" class="action-link delete" data-case-id="${record.case_id}" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                `;
                            });
                        }
                        tbody.innerHTML = html;

                        // Re-bind delete events
                        document.querySelectorAll('.action-link.delete').forEach(link => {
                            link.addEventListener('click', function(e) {
                                e.preventDefault();
                                const caseId = this.dataset.caseId;
                                if (confirm('Are you sure you want to delete this patient record?')) {
                                    // Trigger delete via AJAX
                                    fetch('AdminStaff_PatientRecord.php', {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/x-www-form-urlencoded',
                                            'X-Requested-With': 'XMLHttpRequest'
                                        },
                                        body: 'action=delete&case_id=' + caseId
                                    })
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success) {
                                            showToast('Record deleted successfully');
                                            refreshData();
                                        } else {
                                            showToast('Delete failed', data.error || 'Unknown error', true);
                                        }
                                    })
                                    .catch(error => {
                                        showToast('Delete failed', error.message, true);
                                    });
                                }
                            });
                        });
                    }
                }
            })
            .catch(error => console.error('Refresh error:', error));
        }

        // Auto-refresh every 30 seconds
        setInterval(refreshData, 30000);

        // Refresh when tab becomes visible
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                refreshData();
            }
        });

        // ----------------------------------------------------------------
        // INITIAL PAGE LOAD
        // ----------------------------------------------------------------
        console.log('PhilHealth Patient Status page loaded successfully');
        console.log('Branch: <?php echo htmlspecialchars($branch_name); ?>');
        console.log('Total records: <?php echo $totalRecords; ?>');
    </script>
</body>
</html>