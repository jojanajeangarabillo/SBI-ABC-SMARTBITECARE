<?php
session_start();
require_once 'sources/db_connect.php';

// Check if user is logged in and is a nurse
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 3) {
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
    $username = $userData['username'] ?? 'Nurse';
}

// If no branch assigned
if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
}

// Handle AJAX requests for patient data
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_patient') {
    header('Content-Type: application/json');
    $patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;
    
    if ($patient_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid patient ID']);
        exit;
    }
    
    // Get patient details
    $sql_patient = "SELECT * FROM patients WHERE patient_id = ?";
    $stmt_patient = $conn->prepare($sql_patient);
    $stmt_patient->bind_param("i", $patient_id);
    $stmt_patient->execute();
    $patient = $stmt_patient->get_result()->fetch_assoc();
    
    if (!$patient) {
        echo json_encode(['success' => false, 'message' => 'Patient not found']);
        exit;
    }
    
    // Get patient cases
    $sql_cases = "SELECT * FROM animal_bite_cases WHERE patient_id = ? ORDER BY created_at DESC";
    $stmt_cases = $conn->prepare($sql_cases);
    $stmt_cases->bind_param("i", $patient_id);
    $stmt_cases->execute();
    $cases = $stmt_cases->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Get vaccination history
    $sql_vacc = "SELECT v.*, i.item_name FROM vaccination_records v 
                 JOIN inventory_items i ON v.item_id = i.item_id 
                 WHERE v.patient_id = ? ORDER BY v.date_administered DESC";
    $stmt_vacc = $conn->prepare($sql_vacc);
    $stmt_vacc->bind_param("i", $patient_id);
    $stmt_vacc->execute();
    $vaccinations = $stmt_vacc->get_result()->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode([
        'success' => true,
        'patient' => $patient,
        'cases' => $cases,
        'vaccinations' => $vaccinations
    ]);
    exit;
}

// Handle AJAX request for latest case
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_latest_case') {
    header('Content-Type: application/json');
    $patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;
    
    if ($patient_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid patient ID']);
        exit;
    }
    
    $sql = "SELECT case_id FROM animal_bite_cases WHERE patient_id = ? ORDER BY created_at DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $case = $result->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'case_id' => $case ? $case['case_id'] : null
    ]);
    exit;
}

// Handle document generation
if (isset($_POST['generate_document'])) {
    $patient_id = $_POST['patient_id'];
    $case_id = $_POST['case_id'];
    $document_type = $_POST['document_type'];
    
    // Get patient and case details
    $sql = "SELECT p.*, a.animal_type, a.bite_location, a.bite_category, a.date_of_bite, a.case_status 
            FROM patients p 
            JOIN animal_bite_cases a ON p.patient_id = a.patient_id 
            WHERE p.patient_id = ? AND a.case_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $patient_id, $case_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    
    if ($data) {
        // Generate document content based on type
        $document_content = generateDocument($data, $document_type, $branch_id);
        
        // Save to medical_documents table
        $document_name = $document_type . "_" . $data['full_name'] . "_" . date('Y-m-d');
        $file_path = "documents/" . $document_name . ".pdf";
        
        $sql_insert = "INSERT INTO medical_documents (branch_id, case_id, patient_id, document_type, document_name, file_name, file_path, uploaded_by) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_insert = $conn->prepare($sql_insert);
        $file_name = $document_name . ".pdf";
        $stmt_insert->bind_param("siissssi", $branch_id, $case_id, $patient_id, $document_type, $document_name, $file_name, $file_path, $user_id);
        
        if ($stmt_insert->execute()) {
            $success_message = $document_type . " generated successfully!";
        } else {
            $error_message = "Error saving document: " . $conn->error;
        }
    }
}

// Handle patient search and pagination
$search = isset($_GET['search']) ? $_GET['search'] : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM patients WHERE branch_id = ?";
if (!empty($search)) {
    $count_sql .= " AND (full_name LIKE ? OR email LIKE ? OR contact_number LIKE ?)";
}

$count_stmt = $conn->prepare($count_sql);
if (!empty($search)) {
    $search_param = "%" . $search . "%";
    $count_stmt->bind_param("ssss", $branch_id, $search_param, $search_param, $search_param);
} else {
    $count_stmt->bind_param("s", $branch_id);
}
$count_stmt->execute();
$total_result = $count_stmt->get_result();
$total_rows = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

// Get patients list with search and pagination
$sql_patients = "SELECT p.*, a.case_id, a.case_status, a.date_of_bite 
                 FROM patients p 
                 LEFT JOIN (
                     SELECT patient_id, case_id, case_status, date_of_bite, 
                            ROW_NUMBER() OVER (PARTITION BY patient_id ORDER BY created_at DESC) as rn
                     FROM animal_bite_cases
                 ) a ON p.patient_id = a.patient_id AND a.rn = 1
                 WHERE p.branch_id = ?";

if (!empty($search)) {
    $sql_patients .= " AND (p.full_name LIKE ? OR p.email LIKE ? OR p.contact_number LIKE ?)";
}

$sql_patients .= " ORDER BY p.patient_id DESC LIMIT ? OFFSET ?";

$stmt_patients = $conn->prepare($sql_patients);
if (!empty($search)) {
    $search_param = "%" . $search . "%";
    $stmt_patients->bind_param("ssssii", $branch_id, $search_param, $search_param, $search_param, $limit, $offset);
} else {
    $stmt_patients->bind_param("sii", $branch_id, $limit, $offset);
}

$stmt_patients->execute();
$patients = $stmt_patients->get_result()->fetch_all(MYSQLI_ASSOC);

// Function to generate document content
function generateDocument($data, $type, $branch_id) {
    global $conn;
    $content = "";
    $date = date('F d, Y');
    $branch = getBranchInfo($branch_id);
    
    switch($type) {
        case 'Medical Certificate':
            $content = "MEDICAL CERTIFICATE\n\n";
            $content .= "Date: " . $date . "\n";
            $content .= "Patient: " . $data['full_name'] . "\n";
            $content .= "Address: " . $data['address'] . "\n";
            $content .= "Animal Type: " . $data['animal_type'] . "\n";
            $content .= "Bite Location: " . $data['bite_location'] . "\n";
            $content .= "Bite Category: " . $data['bite_category'] . "\n";
            $content .= "Date of Bite: " . $data['date_of_bite'] . "\n";
            $content .= "Case Status: " . $data['case_status'] . "\n\n";
            $content .= "This certifies that the above patient has been examined and treated for animal bite injuries.\n";
            $content .= "The patient is under observation and following the prescribed treatment protocol.\n\n";
            $content .= "Issued by:\n";
            $content .= $branch['branch_name'] . "\n";
            $content .= $branch['branch_address'] . "\n";
            $content .= "Contact: " . $branch['contact_number'];
            break;
            
        case 'Vaccination Certificate':
            $content = "VACCINATION CERTIFICATE\n\n";
            $content .= "Date: " . $date . "\n";
            $content .= "Patient: " . $data['full_name'] . "\n";
            $content .= "Animal Type: " . $data['animal_type'] . "\n";
            $content .= "Date of Bite: " . $data['date_of_bite'] . "\n\n";
            $content .= "Vaccination History:\n";
            $content .= "The patient has received the following vaccines:\n";
            $content .= "Anti-Rabies Vaccine (ARV)\n";
            $content .= "Tetanus Toxoid (TT)\n";
            $content .= "Anti-Tetanus Serum (ATS)\n\n";
            $content .= "Next schedule: " . date('F d, Y', strtotime('+7 days')) . "\n\n";
            $content .= "Issued by:\n";
            $content .= $branch['branch_name'] . "\n";
            $content .= $branch['branch_address'];
            break;
            
        case 'Referral Letter':
            $content = "REFERRAL LETTER\n\n";
            $content .= "Date: " . $date . "\n";
            $content .= "To: Medical Officer\n\n";
            $content .= "Patient: " . $data['full_name'] . "\n";
            $content .= "Address: " . $data['address'] . "\n";
            $content .= "Referred for: Animal Bite Management\n\n";
            $content .= "Details:\n";
            $content .= "Animal: " . $data['animal_type'] . "\n";
            $content .= "Bite Location: " . $data['bite_location'] . "\n";
            $content .= "Category: " . $data['bite_category'] . "\n";
            $content .= "Date of Incident: " . $data['date_of_bite'] . "\n\n";
            $content .= "Reason for Referral:\n";
            $content .= "Patient requires specialized care for animal bite management.\n\n";
            $content .= "Sincerely,\n";
            $content .= $branch['branch_name'] . "\n";
            $content .= $branch['branch_address'] . "\n";
            $content .= "Contact: " . $branch['contact_number'];
            break;
    }
    return $content;
}

function getBranchInfo($branch_id) {
    global $conn;
    $sql = "SELECT * FROM branches WHERE branch_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $branch_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// Get status badge class
function getStatusBadge($status) {
    $status = strtolower($status);
    $class = 'status-badge ';
    if ($status == 'ongoing' || $status == 'active' || $status == 'scheduled') {
        $class .= 'ongoing';
    } else {
        $class .= 'completed';
    }
    return $class;
}

// Get user data for display
$userData = getUserData($conn, $_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Nurse - Patient Module</title>
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
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
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
        .topbar h3 small {
            font-size: 16px;
            font-weight: 400;
            color: #666;
            margin-left: 10px;
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

        /* ---- search ---- */
        .search-wrap {
            position: relative;
            max-width: 420px;
            margin-bottom: 28px;
        }
        .search-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #7a85a8;
            font-size: 18px;
        }
        .search-wrap input {
            width: 100%;
            padding: 12px 12px 12px 44px;
            border: 1px solid #d0d7e8;
            border-radius: 40px;
            font-size: 15px;
            background: white;
            outline: none;
            transition: 0.15s;
        }
        .search-wrap input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.15);
        }

        /* ---- table ---- */
        .table-wrap {
            background: white;
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            padding: 6px 0 6px 0;
            margin-bottom: 20px;
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
        .status-badge {
            display: inline-block;
            font-weight: 600;
            font-size: 13px;
            padding: 4px 16px;
            border-radius: 40px;
            letter-spacing: 0.2px;
        }
        .status-badge.ongoing {
            background: #fde8b0;
            color: #8a6d00;
        }
        .status-badge.completed {
            background: #d4f0d4;
            color: #1a6e1a;
        }
        .action-icon {
            font-size: 22px;
            color: var(--primary);
            cursor: pointer;
            opacity: 0.7;
            transition: 0.1s;
            text-decoration: none;
            padding: 0 4px;
        }
        .action-icon:hover {
            opacity: 1;
        }

        /* Pagination */
        .pagination-wrap {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 20px;
        }
        .pagination-wrap .page-link {
            color: var(--primary);
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 500;
            border: 1px solid #e2e7f2;
        }
        .pagination-wrap .page-link:hover {
            background: #f0f3fc;
            border-color: var(--primary);
        }
        .pagination-wrap .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        .pagination-wrap .page-item.disabled .page-link {
            color: #b0b8c8;
        }
        .pagination-info {
            text-align: center;
            color: #7a85a8;
            font-size: 14px;
            margin-top: 12px;
        }

        /* Modal styles */
        .modal-content {
            border-radius: 18px;
        }
        .modal-header {
            border-bottom: 2px solid #f0f3fc;
            padding: 20px 24px;
        }
        .modal-header .modal-title {
            color: var(--primary);
            font-weight: 700;
        }
        .modal-body {
            padding: 24px;
        }
        .modal-footer {
            border-top: 2px solid #f0f3fc;
            padding: 16px 24px;
        }
        .document-option {
            padding: 15px 20px;
            border: 2px solid #e2e7f2;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.15s;
            margin-bottom: 10px;
        }
        .document-option:hover {
            border-color: var(--primary);
            background: #f8f9ff;
        }
        .document-option.selected {
            border-color: var(--primary);
            background: #e8ebf8;
        }
        .document-option i {
            font-size: 28px;
            color: var(--primary);
            margin-right: 12px;
        }
        .document-option .doc-title {
            font-weight: 600;
            color: #1f2a4a;
        }
        .document-option .doc-desc {
            font-size: 13px;
            color: #7a85a8;
        }

        /* Patient info display in modal */
        .patient-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        .patient-info-item {
            background: #f8f9ff;
            padding: 12px 16px;
            border-radius: 12px;
        }
        .patient-info-item label {
            font-size: 12px;
            color: #7a85a8;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: block;
            margin-bottom: 4px;
        }
        .patient-info-item .value {
            font-weight: 600;
            color: #1f2a4a;
            font-size: 15px;
        }

        /* Toast/Alert */
        .alert-toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
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
            .topbar h3 {
                font-size: 22px;
            }
            .branch-indicator {
                font-size: 12px;
                padding: 4px 14px 4px 12px;
            }
        }

        @media (max-width: 576px) {
            .topbar {
                padding: 0 16px;
                height: auto;
                min-height: 70px;
                flex-wrap: wrap;
                gap: 8px;
                padding: 12px 16px;
            }
            .topbar h3 {
                font-size: 18px;
            }
            .topbar-left {
                flex-wrap: wrap;
                gap: 10px;
            }
            .branch-indicator {
                font-size: 11px;
                padding: 4px 12px 4px 10px;
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
            .table thead th,
            .table tbody td {
                padding: 12px 14px;
                font-size: 14px;
            }
            .search-wrap {
                max-width: 100%;
            }
            .alert-toast {
                min-width: 90%;
                right: 5%;
                top: 10px;
            }
            .pagination-wrap .page-link {
                padding: 6px 12px;
                font-size: 13px;
            }
            .profile {
                font-size: 13px;
            }
        }
    </style>
</head>
<body>

<!-- ========== ALERT TOAST ========== -->
<?php if (isset($success_message)): ?>
<div class="alert-toast alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i> <?php echo $success_message; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (isset($error_message)): ?>
<div class="alert-toast alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-circle-fill me-2"></i> <?php echo $error_message; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ========== SIDEBAR (Nurse) ========== -->
<div class="sidebar">
    <div class="logo-area">
        <div class="logo-frame">
            <img src="logo.png" alt="Smart Bite Care Logo" class="logo" />
        </div>
        <div class="system-name">Smart Bite Care</div>
    </div>

    <nav class="nav-menu">
        <ul>
            <li><a  href="Nurse_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a class="active" href="Nurse_Patients.php"><i class="bi bi-heart-pulse-fill"></i><span>Patients</span></a></li>
            <li><a href="Nurse_Vaccination.php"><i class="bi-shield-plus"></i><span>Vaccination</span></a></li>
            <li><a href="Nurse_MedicalSuppliesManagement.php"><i class="bi bi-calendar-check"></i><span>Medical Supplies Management</span></a></li>
            <li><a href="Nurse_SupplyPrediction.php"><i class="bi bi-box-seam"></i><span>Supply Prediction</span></a></li>
            <li><a href="Nurse_Notification.php"><i class="bi bi-graph-up-arrow"></i><span>Notification</span></a></li>
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
        <h3>Dashboard <small><?php echo htmlspecialchars($branch_name); ?></small></h3>
        <div class="profile"><?php echo htmlspecialchars($username); ?> <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="content">

        <!-- Search -->
        <form method="GET" action="">
            <div class="search-wrap">
                <i class="bi bi-search"></i>
                <input type="text" name="search" placeholder="Search Patients" value="<?php echo htmlspecialchars($search); ?>" />
            </div>
        </form>

        <!-- Recent Patients Table -->
        <div class="table-wrap">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Patient Name</th>
                        <th>Last Visit</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($patients) > 0): ?>
                        <?php foreach ($patients as $patient): ?>
                        <tr>
                            <td><strong>P<?php echo str_pad($patient['patient_id'], 4, '0', STR_PAD_LEFT); ?></strong></td>
                            <td><?php echo htmlspecialchars($patient['full_name']); ?></td>
                            <td><?php echo $patient['date_of_bite'] ? date('M d, Y', strtotime($patient['date_of_bite'])) : 'N/A'; ?></td>
                            <td><span class="<?php echo getStatusBadge($patient['case_status']); ?>"><?php echo $patient['case_status'] ?: 'N/A'; ?></span></td>
                            <td>
                                <a href="#" class="action-icon me-2" onclick="viewPatient(<?php echo $patient['patient_id']; ?>)" title="View Patient">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="#" class="action-icon" onclick="openDocumentModal(<?php echo $patient['patient_id']; ?>)" title="Generate Document">
                                    <i class="bi bi-file-earmark-text"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-4">No patients found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination-wrap">
            <nav aria-label="Page navigation">
                <ul class="pagination">
                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <div class="pagination-info">
            Showing <?php echo ($offset + 1); ?> - <?php echo min($offset + $limit, $total_rows); ?> of <?php echo $total_rows; ?> patients
        </div>
        <?php endif; ?>

    </div> <!-- /content -->
</div> <!-- /main -->

<!-- ========== VIEW PATIENT MODAL ========== -->
<div class="modal fade" id="viewPatientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-badge me-2"></i>Patient Information</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="patientInfoBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ========== DOCUMENT GENERATION MODAL ========== -->
<div class="modal fade" id="documentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Generate Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <p class="text-muted">Select the type of document to generate.</p>
                    
                    <div class="document-option" onclick="selectDocument(this, 'Medical Certificate')">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-file-earmark-check"></i>
                            <div>
                                <div class="doc-title">Medical Certificate</div>
                                <div class="doc-desc">Certificate for medical treatment and fitness</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="document-option" onclick="selectDocument(this, 'Referral Letter')">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-file-earmark-arrow-up"></i>
                            <div>
                                <div class="doc-title">Referral Letter</div>
                                <div class="doc-desc">Letter for patient referral to specialist</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="document-option" onclick="selectDocument(this, 'Vaccination Certificate')">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-file-earmark-check"></i>
                            <div>
                                <div class="doc-title">Vaccination Certificate</div>
                                <div class="doc-desc">Certificate for vaccination records</div>
                            </div>
                        </div>
                    </div>
                    
                    <input type="hidden" name="patient_id" id="doc_patient_id" value="">
                    <input type="hidden" name="case_id" id="doc_case_id" value="">
                    <input type="hidden" name="document_type" id="doc_type" value="">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="generate_document" class="btn btn-primary" id="generateBtn" disabled>Generate Document</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// View patient function - loads data via AJAX
function viewPatient(patientId) {
    var modal = new bootstrap.Modal(document.getElementById('viewPatientModal'));
    var body = document.getElementById('patientInfoBody');
    
    // Show loading
    body.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>';
    modal.show();
    
    // Fetch patient data
    fetch('Nurse_Patients.php?ajax=get_patient&patient_id=' + patientId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                var html = '';
                var p = data.patient;
                var cases = data.cases || [];
                var vaccines = data.vaccinations || [];
                
                // Patient info
                html += '<div class="patient-info-grid">';
                html += '<div class="patient-info-item"><label>Full Name</label><div class="value">' + p.full_name + '</div></div>';
                html += '<div class="patient-info-item"><label>Email</label><div class="value">' + (p.email || 'N/A') + '</div></div>';
                html += '<div class="patient-info-item"><label>Contact</label><div class="value">' + (p.contact_number || 'N/A') + '</div></div>';
                html += '<div class="patient-info-item"><label>Gender</label><div class="value">' + (p.gender || 'N/A') + '</div></div>';
                html += '<div class="patient-info-item"><label>Birthday</label><div class="value">' + (p.birthday ? new Date(p.birthday).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'}) : 'N/A') + '</div></div>';
                html += '<div class="patient-info-item"><label>Address</label><div class="value">' + (p.address || 'N/A') + '</div></div>';
                html += '</div>';
                
                // Cases
                if (cases.length > 0) {
                    html += '<h6 class="fw-bold text-primary mt-3">Case History</h6>';
                    cases.forEach(function(c) {
                        var statusClass = (c.case_status && c.case_status.toLowerCase() === 'ongoing') ? 'ongoing' : 'completed';
                        html += '<div class="border p-3 rounded mb-2 bg-light">';
                        html += '<div class="row">';
                        html += '<div class="col-md-3"><strong>Case ID:</strong> C' + String(c.case_id).padStart(4, '0') + '</div>';
                        html += '<div class="col-md-3"><strong>Animal:</strong> ' + (c.animal_type || 'N/A') + '</div>';
                        html += '<div class="col-md-3"><strong>Bite Location:</strong> ' + (c.bite_location || 'N/A') + '</div>';
                        html += '<div class="col-md-3"><strong>Status:</strong> <span class="status-badge ' + statusClass + '">' + (c.case_status || 'N/A') + '</span></div>';
                        html += '</div>';
                        html += '</div>';
                    });
                }
                
                // Vaccinations
                if (vaccines.length > 0) {
                    html += '<h6 class="fw-bold text-primary mt-3">Vaccination History</h6>';
                    html += '<div class="table-responsive">';
                    html += '<table class="table table-sm table-bordered">';
                    html += '<thead><tr><th>Vaccine</th><th>Dose</th><th>Date Administered</th><th>Status</th></tr></thead>';
                    html += '<tbody>';
                    vaccines.forEach(function(v) {
                        var statusClass = (v.vaccination_status && v.vaccination_status.toLowerCase() === 'scheduled') ? 'ongoing' : 'completed';
                        html += '<tr>';
                        html += '<td>' + (v.item_name || 'N/A') + '</td>';
                        html += '<td>Dose ' + (v.dose_number || '') + '</td>';
                        html += '<td>' + (v.date_administered ? new Date(v.date_administered).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'}) : 'N/A') + '</td>';
                        html += '<td><span class="status-badge ' + statusClass + '">' + (v.vaccination_status || 'N/A') + '</span></td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table></div>';
                }
                
                if (cases.length === 0 && vaccines.length === 0) {
                    html += '<p class="text-muted text-center mt-3">No case history or vaccination records found.</p>';
                }
                
                body.innerHTML = html;
            } else {
                body.innerHTML = '<div class="alert alert-danger">Error loading patient data: ' + data.message + '</div>';
            }
        })
        .catch(error => {
            body.innerHTML = '<div class="alert alert-danger">Error loading patient data. Please try again.</div>';
            console.error('Error:', error);
        });
}

// Document modal
function openDocumentModal(patientId) {
    // Get the latest case ID for this patient
    fetch('Nurse_Patients.php?ajax=get_latest_case&patient_id=' + patientId)
        .then(response => response.json())
        .then(data => {
            document.getElementById('doc_patient_id').value = patientId;
            document.getElementById('doc_case_id').value = data.case_id || 0;
            document.getElementById('doc_type').value = '';
            document.getElementById('generateBtn').disabled = true;
            
            // Reset selection
            document.querySelectorAll('.document-option').forEach(el => el.classList.remove('selected'));
            
            var modal = new bootstrap.Modal(document.getElementById('documentModal'));
            modal.show();
        })
        .catch(error => {
            alert('Error loading patient data. Please try again.');
        });
}

function selectDocument(element, type) {
    // Remove selection from all
    document.querySelectorAll('.document-option').forEach(el => el.classList.remove('selected'));
    
    // Select this
    element.classList.add('selected');
    
    // Set type
    document.getElementById('doc_type').value = type;
    document.getElementById('generateBtn').disabled = false;
}

// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        var alerts = document.querySelectorAll('.alert-toast');
        alerts.forEach(function(alert) {
            var bsAlert = bootstrap.Alert.getInstance(alert);
            if (bsAlert) {
                bsAlert.close();
            }
        });
    }, 5000);
});
</script>
</body>
</html>