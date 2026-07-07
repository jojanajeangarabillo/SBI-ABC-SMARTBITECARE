<?php
session_start();
require_once 'sources/db_connect.php';

// Check if user is logged in and is an admin staff
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 4
) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$branch_id = null;
$branch_name = '';
$username = '';

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
    $username = $userData['username'] ?? 'Admin Staff';
}

if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
}

// Configuration
define('UPLOAD_DIR', 'uploads/documents/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024);
define('ALLOWED_EXTENSIONS', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'txt']);

if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0777, true);
}

// Handle AJAX requests
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    $action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
    
    switch ($action) {
        case 'fetch_documents':
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $documentType = isset($_GET['document_type']) ? trim($_GET['document_type']) : '';
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = 10;
            $offset = ($page - 1) * $limit;
            
            $where = "WHERE branch_id = ?";
            $params = [$branch_id];
            $types = "s";
            
            if (!empty($search)) {
                $where .= " AND (document_name LIKE ? OR document_type LIKE ?)";
                $searchParam = "%$search%";
                $params[] = $searchParam;
                $params[] = $searchParam;
                $types .= "ss";
            }
            
            if (!empty($documentType)) {
                $where .= " AND document_type = ?";
                $params[] = $documentType;
                $types .= "s";
            }
            
            $countQuery = "SELECT COUNT(*) as total FROM medical_documents $where";
            $stmt = $conn->prepare($countQuery);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $countResult = $stmt->get_result();
            $totalRecords = $countResult->fetch_assoc()['total'] ?? 0;
            $totalPages = ceil($totalRecords / $limit);
            
            $query = "
                SELECT 
                    document_id, document_type, document_name, file_name, file_path,
                    file_size, description, uploaded_by,
                    DATE_FORMAT(uploaded_at, '%b %d, %Y %h:%i %p') as formatted_date,
                    (SELECT username FROM users WHERE user_id = medical_documents.uploaded_by) as uploaded_by_name
                FROM medical_documents
                $where
                ORDER BY uploaded_at DESC
                LIMIT ? OFFSET ?
            ";
            $stmt = $conn->prepare($query);
            $params[] = $limit;
            $params[] = $offset;
            $types .= "ii";
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $documents = [];
            while ($row = $result->fetch_assoc()) {
                $documents[] = $row;
            }
            
            echo json_encode([
                'success' => true,
                'documents' => $documents,
                'total' => $totalRecords,
                'pages' => $totalPages,
                'current_page' => $page
            ]);
            break;
            
        case 'upload_document':
            try {
                if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('No file uploaded.');
                }
                
                $file = $_FILES['document_file'];
                $documentType = isset($_POST['document_type']) ? $_POST['document_type'] : '';
                $documentName = isset($_POST['document_name']) ? trim($_POST['document_name']) : '';
                $description = isset($_POST['description']) ? trim($_POST['description']) : '';
                
                $validTypes = ['Medical Certificate', 'Vaccination Certificate', 'Referral Letter', 'Other'];
                if (!in_array($documentType, $validTypes)) {
                    throw new Exception('Invalid document type.');
                }
                
                if ($file['size'] > MAX_FILE_SIZE) {
                    throw new Exception('File size exceeds 10MB limit.');
                }
                
                $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($extension, ALLOWED_EXTENSIONS)) {
                    throw new Exception('File type not allowed.');
                }
                
                $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $file['name']);
                $filePath = UPLOAD_DIR . $fileName;
                
                if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                    throw new Exception('Failed to save file.');
                }
                
                if (empty($documentName)) {
                    $documentName = pathinfo($file['name'], PATHINFO_FILENAME);
                }
                
                $insertQuery = "
                    INSERT INTO medical_documents (
                        branch_id, document_type, document_name,
                        file_name, file_path, file_type, file_size, description, uploaded_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ";
                $stmt = $conn->prepare($insertQuery);
                $stmt->bind_param(
                    "ssssssisi",
                    $branch_id,
                    $documentType,
                    $documentName,
                    $file['name'],
                    $filePath,
                    $file['type'],
                    $file['size'],
                    $description,
                    $user_id
                );
                
                if (!$stmt->execute()) {
                    unlink($filePath);
                    throw new Exception('Failed to save record.');
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Document uploaded successfully.'
                ]);
                
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
            }
            break;
            
        case 'delete_document':
            try {
                $documentId = isset($_GET['document_id']) ? (int)$_GET['document_id'] : 0;
                if ($documentId <= 0) {
                    throw new Exception('Invalid document ID.');
                }
                
                $query = "SELECT file_path, document_name FROM medical_documents WHERE document_id = ? AND branch_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("is", $documentId, $branch_id);
                $stmt->execute();
                $document = $stmt->get_result()->fetch_assoc();
                
                if (!$document) {
                    throw new Exception('Document not found.');
                }
                
                if (file_exists($document['file_path'])) {
                    unlink($document['file_path']);
                }
                
                $deleteQuery = "DELETE FROM medical_documents WHERE document_id = ? AND branch_id = ?";
                $stmt = $conn->prepare($deleteQuery);
                $stmt->bind_param("is", $documentId, $branch_id);
                $stmt->execute();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Document deleted successfully.'
                ]);
                
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
            }
            break;
            
        case 'get_document':
            try {
                $documentId = isset($_GET['document_id']) ? (int)$_GET['document_id'] : 0;
                if ($documentId <= 0) {
                    throw new Exception('Invalid document ID.');
                }
                
                $query = "
                    SELECT 
                        document_id, document_type, document_name, file_name, file_path,
                        file_size, description, uploaded_by,
                        DATE_FORMAT(uploaded_at, '%b %d, %Y %h:%i %p') as formatted_date,
                        (SELECT username FROM users WHERE user_id = medical_documents.uploaded_by) as uploaded_by_name
                    FROM medical_documents
                    WHERE document_id = ? AND branch_id = ?
                ";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("is", $documentId, $branch_id);
                $stmt->execute();
                $document = $stmt->get_result()->fetch_assoc();
                
                if (!$document) {
                    throw new Exception('Document not found.');
                }
                
                echo json_encode([
                    'success' => true,
                    'document' => $document
                ]);
                
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
            }
            break;
            
        case 'update_document':
            try {
                $documentId = isset($_POST['document_id']) ? (int)$_POST['document_id'] : 0;
                if ($documentId <= 0) {
                    throw new Exception('Invalid document ID.');
                }
                
                $documentName = isset($_POST['document_name']) ? trim($_POST['document_name']) : '';
                $description = isset($_POST['description']) ? trim($_POST['description']) : '';
                $documentType = isset($_POST['document_type']) ? $_POST['document_type'] : '';
                
                $validTypes = ['Medical Certificate', 'Vaccination Certificate', 'Referral Letter', 'Other'];
                if (!in_array($documentType, $validTypes)) {
                    throw new Exception('Invalid document type.');
                }
                
                if (empty($documentName)) {
                    throw new Exception('Document name is required.');
                }
                
                $updateQuery = "
                    UPDATE medical_documents 
                    SET document_name = ?, document_type = ?, description = ?, updated_at = NOW()
                    WHERE document_id = ? AND branch_id = ?
                ";
                $stmt = $conn->prepare($updateQuery);
                $stmt->bind_param("sssis", $documentName, $documentType, $description, $documentId, $branch_id);
                $stmt->execute();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Document updated successfully.'
                ]);
                
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action.']);
            break;
    }
    exit();
}

// Get recent documents
$recentQuery = "
    SELECT 
        document_id, document_type, document_name, file_path,
        DATE_FORMAT(uploaded_at, '%b %d, %Y %h:%i %p') as formatted_date,
        (SELECT username FROM users WHERE user_id = medical_documents.uploaded_by) as uploaded_by_name
    FROM medical_documents
    WHERE branch_id = ?
    ORDER BY uploaded_at DESC
    LIMIT 10
";
$stmt = $conn->prepare($recentQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$recentResult = $stmt->get_result();
$recentDocuments = [];
while ($row = $recentResult->fetch_assoc()) {
    $recentDocuments[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Medical Documents - SmartBiteCare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="sidebar.css">

    <style>
        :root {
            --primary: #2B3A8C;
            --danger: #dc3545;
            --gray-100: #f8f9fc;
            --gray-500: #adb5bd;
            --gray-600: #6c757d;
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
            .topbar {
                padding: 0 16px;
                height: 64px;
            }
            .topbar h3 {
                font-size: 20px;
            }
        }

        .content {
            padding: 30px;
        }

        @media (max-width: 768px) {
            .content {
                padding: 16px;
            }
        }

        .section-card {
            background: #fff;
            border: 1px solid #e8e8e8;
            border-radius: 18px;
            padding: 24px;
            box-shadow: var(--shadow);
            margin-bottom: 24px;
        }

        .section-title {
            color: var(--primary);
            font-weight: 700;
            margin-bottom: 18px;
            font-size: 18px;
        }

        /* Document Grid */
        .document-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        @media (max-width: 992px) {
            .document-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 576px) {
            .document-grid {
                grid-template-columns: 1fr;
            }
        }

        .document-card {
            border: 1px solid #ececec;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            transition: var(--transition);
        }

        .document-card:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,.08);
            transform: translateY(-2px);
        }

        .document-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin-bottom: 12px;
        }

        .document-icon.medical {
            background: #EAF2FF;
            color: #2563EB;
        }

        .document-icon.vaccine {
            background: #E8FAF2;
            color: #1DBA6C;
        }

        .document-icon.referral {
            background: #F2EAFE;
            color: #7C4DFF;
        }

        .document-card h5 {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .document-card p {
            color: #666;
            font-size: 13px;
            margin-bottom: 14px;
        }

        .btn-doc {
            border: none;
            border-radius: 8px;
            padding: 8px 20px;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-doc.blue { background: #2563EB; color: #fff; }
        .btn-doc.blue:hover { background: #1d4ed8; color: #fff; }
        .btn-doc.green { background: #1DBA6C; color: #fff; }
        .btn-doc.green:hover { background: #16a34a; color: #fff; }
        .btn-doc.purple { background: #7C4DFF; color: #fff; }
        .btn-doc.purple:hover { background: #6d3bf5; color: #fff; }

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
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-box {
            width: 280px;
            height: 42px;
            border: 1px solid var(--primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            padding: 0 14px;
            background: #fff;
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
            padding: 9px 20px;
            font-size: 14px;
            font-weight: 600;
            transition: var(--transition);
        }

        .toolbar-btn:hover {
            background: #1f2d6b;
            transform: translateY(-2px);
        }

        .toolbar-btn i {
            margin-right: 6px;
        }

        .form-select-sm-custom {
            height: 42px;
            border-radius: 8px;
            border: 1px solid #ced4da;
            padding: 0 12px;
            font-size: 13px;
        }

        /* Table */
        .table-wrapper {
            background: #fff;
            border-radius: 18px;
            overflow: hidden;
            border: 1px solid #e8e8e8;
            box-shadow: var(--shadow);
        }

        .table {
            width: 100%;
            margin-bottom: 0;
            border-collapse: collapse;
        }

        .table thead {
            background: var(--primary);
            color: #fff;
        }

        .table thead th {
            border: none !important;
            padding: 12px 16px;
            text-align: center;
            font-size: 13px;
            font-weight: 600;
        }

        .table tbody td {
            text-align: center;
            vertical-align: middle;
            padding: 10px 16px;
            border-top: 1px solid #e9ecef;
            font-size: 14px;
        }

        .table tbody tr:hover {
            background: var(--gray-100);
        }

        .document-badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .document-badge.medical { background: #EAF2FF; color: #2563EB; }
        .document-badge.vaccine { background: #E8FAF2; color: #1DBA6C; }
        .document-badge.referral { background: #F2EAFE; color: #7C4DFF; }
        .document-badge.other { background: var(--gray-100); color: var(--gray-600); }

        /* Actions */
        .actions {
            display: flex;
            justify-content: center;
            gap: 4px;
        }

        .action-btn {
            width: 34px;
            height: 34px;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            border-radius: 50%;
            border: none;
            background: transparent;
            cursor: pointer;
            transition: var(--transition);
            font-size: 16px;
        }

        .action-btn.view { color: var(--primary); }
        .action-btn.view:hover { background: #EEF2FF; }
        .action-btn.download { color: #7C4DFF; }
        .action-btn.download:hover { background: #F1EEFF; }
        .action-btn.delete { color: var(--danger); }
        .action-btn.delete:hover { background: #fdecec; }

        /* Pagination */
        .pagination-area {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .page-item {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            border: 1px solid transparent;
            font-size: 14px;
            color: var(--gray-600);
            text-decoration: none;
            background: transparent;
        }

        .page-item:hover {
            background: var(--gray-100);
            border-color: #ddd;
        }

        .page-item.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .page-item.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Modal */
        .modal-content {
            border-radius: var(--radius);
            border: none;
        }

        .modal-header {
            background: var(--primary);
            color: #fff;
            border-radius: var(--radius) var(--radius) 0 0;
            padding: 18px 25px;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        .modal-body {
            padding: 25px;
        }

        .modal-footer {
            border-top: none;
            padding: 18px 25px 25px;
        }

        .form-label {
            font-weight: 600;
            font-size: 13px;
            color: #333;
        }

        .form-control, .form-select {
            border-radius: 8px;
            padding: 8px 14px;
            border: 1px solid #ced4da;
            font-size: 14px;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43,58,140,0.12);
        }

        .file-upload-area {
            border: 2px dashed #ced4da;
            border-radius: 10px;
            padding: 25px;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .file-upload-area:hover {
            border-color: var(--primary);
            background: var(--gray-100);
        }

        .file-upload-area i {
            font-size: 40px;
            color: var(--gray-500);
            display: block;
            margin-bottom: 8px;
        }

        .file-upload-area p {
            margin: 0;
            color: var(--gray-600);
            font-size: 14px;
        }

        .file-upload-area .file-name {
            font-weight: 600;
            color: var(--primary);
        }

        /* Toast */
        .toast-container-custom {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 9999;
            max-width: 380px;
        }

        .toast-custom {
            background: #fff;
            border-radius: 12px;
            padding: 14px 20px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            border-left: 5px solid #28a745;
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
            font-size: 22px;
            color: #28a745;
        }

        .toast-custom.error .toast-icon {
            color: var(--danger);
        }

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
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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

        .admin-profile {
            font-weight: 700;
            color: var(--primary);
            cursor: default;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
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
                <li><a href="AdminStaff_PhilhealthStatus.php"><i class="bi bi-check2-all"></i><span>PhilHealth Patient Status</span></a></li>
                <li><a class="active" href="AdminStaff_MedicalDocuments.php"><i class="bi bi-file-earmark-ruled"></i><span>Medical Documents</span></a></li>
                <li><a href="AdminStaff_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
            </ul>
        </nav>
        <div class="logout">
            <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main">
        <div class="topbar">
            <h3>Medical Documents</h3>
            <div class="profile">
                <?php echo htmlspecialchars($username); ?>
                <i class="bi bi-caret-down-fill"></i>
            </div>
        </div>

        <div class="content">

            <!-- Documents Table -->
            <div class="section-card">
                <div class="toolbar">
                    <div class="left-tools">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" id="searchInput" placeholder="Search documents...">
                        </div>
                        <select class="form-select-sm-custom" id="typeFilter">
                            <option value="">All Types</option>
                            <option value="Medical Certificate">Medical Certificate</option>
                            <option value="Vaccination Certificate">Vaccination Certificate</option>
                            <option value="Referral Letter">Referral Letter</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <button class="toolbar-btn" onclick="openUploadModal()">
                        <i class="bi bi-plus-circle"></i> Upload New
                    </button>
                </div>

                <div class="table-wrapper">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Document Type</th>
                                    <th>Document Name</th>
                                    <th>Uploaded By</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="documentsTableBody">
                                <?php if (empty($recentDocuments)): ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="no-records">
                                            <i class="bi bi-file-earmark-text"></i>
                                            <p>No documents uploaded yet.</p>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($recentDocuments as $doc): ?>
                                <tr>
                                    <td>
                                        <span class="document-badge <?php echo strtolower(str_replace(' ', '-', $doc['document_type'])); ?>">
                                            <?php echo htmlspecialchars($doc['document_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($doc['document_name']); ?></td>
                                    <td><?php echo htmlspecialchars($doc['uploaded_by_name'] ?? 'Unknown'); ?></td>
                                    <td><?php echo htmlspecialchars($doc['formatted_date']); ?></td>
                                    <td>
                                        <div class="actions">
                                            <button class="action-btn view" onclick="viewDocument(<?php echo $doc['document_id']; ?>)" title="View">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button class="action-btn download" onclick="downloadDocument(<?php echo $doc['document_id']; ?>)" title="Download">
                                                <i class="bi bi-download"></i>
                                            </button>
                                            <button class="action-btn delete" onclick="deleteDocument(<?php echo $doc['document_id']; ?>)" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="pagination-area" id="paginationArea"></div>
                <div class="text-center mt-2">
                    <small class="text-muted" id="recordCount">Loading...</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upload"></i> Upload Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="uploadForm" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">Document Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="docTypeSelect" name="document_type" required>
                                <option value="Medical Certificate">Medical Certificate</option>
                                <option value="Vaccination Certificate">Vaccination Certificate</option>
                                <option value="Referral Letter">Referral Letter</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Document Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="docName" name="document_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" id="docDescription" name="description" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">File <span class="text-danger">*</span></label>
                            <div class="file-upload-area" id="fileUploadArea">
                                <i class="bi bi-cloud-arrow-up"></i>
                                <p>Click or drag to upload</p>
                                <p class="text-muted" style="font-size:11px;">PDF, DOC, DOCX, JPG, PNG (Max 10MB)</p>
                                <div id="selectedFileName" class="file-name" style="display:none;"></div>
                                <input type="file" id="fileInput" name="document_file" style="display:none;" required>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="uploadBtn">
                        <i class="bi bi-cloud-upload"></i> Upload
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Modal -->
    <div class="modal fade" id="viewModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Document Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewModalBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="editDocBtn">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editForm">
                        <input type="hidden" id="editDocumentId" name="document_id">
                        <div class="mb-3">
                            <label class="form-label">Document Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="editDocType" name="document_type" required>
                                <option value="Medical Certificate">Medical Certificate</option>
                                <option value="Vaccination Certificate">Vaccination Certificate</option>
                                <option value="Referral Letter">Referral Letter</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Document Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="editDocName" name="document_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" id="editDescription" name="description" rows="2"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="updateDocBtn">
                        <i class="bi bi-save"></i> Update
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this document?</p>
                    <p class="text-danger"><small>This action cannot be undone.</small></p>
                    <p id="deleteDocName" class="fw-bold"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
                </div>
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
    // Toast
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

    function showLoading() {
        document.getElementById('loadingOverlay').classList.add('show');
    }

    function hideLoading() {
        document.getElementById('loadingOverlay').classList.remove('show');
    }

    // File upload
    const uploadArea = document.getElementById('fileUploadArea');
    const fileInput = document.getElementById('fileInput');

    uploadArea.addEventListener('click', () => fileInput.click());
    uploadArea.addEventListener('dragover', (e) => { e.preventDefault(); uploadArea.classList.add('dragover'); });
    uploadArea.addEventListener('dragleave', () => uploadArea.classList.remove('dragover'));
    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        if (e.dataTransfer.files.length) {
            fileInput.files = e.dataTransfer.files;
            handleFileSelect(e.dataTransfer.files[0]);
        }
    });
    fileInput.addEventListener('change', function() {
        if (this.files.length) handleFileSelect(this.files[0]);
    });

    function handleFileSelect(file) {
        const display = document.getElementById('selectedFileName');
        display.textContent = file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
        display.style.display = 'block';
    }

    // Upload Modal
    function openUploadModal(documentType) {
        const modal = new bootstrap.Modal(document.getElementById('uploadModal'));
        document.getElementById('uploadForm').reset();
        document.getElementById('selectedFileName').style.display = 'none';
        document.getElementById('fileInput').value = '';
        if (documentType) document.getElementById('docTypeSelect').value = documentType;
        modal.show();
    }

    // Upload
    document.getElementById('uploadBtn').addEventListener('click', function() {
        const file = fileInput.files[0];
        if (!file) { showToast('Error', 'Please select a file.', true); return; }
        if (file.size > 10 * 1024 * 1024) { showToast('Error', 'File exceeds 10MB limit.', true); return; }

        const formData = new FormData(document.getElementById('uploadForm'));
        formData.append('action', 'upload_document');

        showLoading();
        fetch(window.location.href, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('uploadModal')).hide();
                showToast('Document uploaded successfully');
                refreshDocuments();
            } else {
                showToast('Upload failed', data.error, true);
            }
        })
        .catch(error => { hideLoading(); showToast('Error', error.message, true); });
    });

    // View Document
    let currentViewId = null;

    function viewDocument(id) {
        currentViewId = id;
        showLoading();
        fetch(window.location.href + '?action=get_document&document_id=' + id, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                const d = data.document;
                document.getElementById('viewModalBody').innerHTML = `
                    <p><strong>Name:</strong> ${d.document_name}</p>
                    <p><strong>Type:</strong> ${d.document_type}</p>
                    <p><strong>File:</strong> ${d.file_name}</p>
                    <p><strong>Size:</strong> ${(d.file_size / 1024 / 1024).toFixed(2)} MB</p>
                    <p><strong>Uploaded By:</strong> ${d.uploaded_by_name || 'Unknown'}</p>
                    <p><strong>Date:</strong> ${d.formatted_date}</p>
                    <p><strong>Description:</strong> ${d.description || 'N/A'}</p>
                    <div class="mt-3">
                        <a href="${d.file_path}" target="_blank" class="btn btn-primary"><i class="bi bi-eye"></i> View</a>
                        <a href="${d.file_path}" download class="btn btn-success"><i class="bi bi-download"></i> Download</a>
                    </div>
                `;
                new bootstrap.Modal(document.getElementById('viewModal')).show();
            } else {
                showToast('Error', data.error, true);
            }
        })
        .catch(error => { hideLoading(); showToast('Error', error.message, true); });
    }

    // Edit
    document.getElementById('editDocBtn').addEventListener('click', function() {
        bootstrap.Modal.getInstance(document.getElementById('viewModal')).hide();
        showLoading();
        fetch(window.location.href + '?action=get_document&document_id=' + currentViewId, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                const d = data.document;
                document.getElementById('editDocumentId').value = d.document_id;
                document.getElementById('editDocType').value = d.document_type;
                document.getElementById('editDocName').value = d.document_name;
                document.getElementById('editDescription').value = d.description || '';
                new bootstrap.Modal(document.getElementById('editModal')).show();
            } else {
                showToast('Error', data.error, true);
            }
        })
        .catch(error => { hideLoading(); showToast('Error', error.message, true); });
    });

    // Update
    document.getElementById('updateDocBtn').addEventListener('click', function() {
        const formData = new FormData(document.getElementById('editForm'));
        formData.append('action', 'update_document');

        showLoading();
        fetch(window.location.href, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
                showToast('Document updated');
                refreshDocuments();
            } else {
                showToast('Update failed', data.error, true);
            }
        })
        .catch(error => { hideLoading(); showToast('Error', error.message, true); });
    });

    // Delete
    let deleteId = null;

    function deleteDocument(id) {
        deleteId = id;
        document.getElementById('deleteDocName').textContent = 'Document ID: ' + id;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }

    document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
        if (!deleteId) return;
        showLoading();
        fetch(window.location.href + '?action=delete_document&document_id=' + deleteId, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
                showToast('Document deleted');
                refreshDocuments();
            } else {
                showToast('Delete failed', data.error, true);
            }
        })
        .catch(error => { hideLoading(); showToast('Error', error.message, true); });
    });

    // Download
    function downloadDocument(id) {
        fetch(window.location.href + '?action=get_document&document_id=' + id, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const link = document.createElement('a');
                link.href = data.document.file_path;
                link.download = data.document.file_name;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                showToast('Download started');
            } else {
                showToast('Download failed', data.error, true);
            }
        })
        .catch(error => showToast('Error', error.message, true));
    }

    // Refresh
    let currentPage = 1;
    let totalPages = 1;

    function refreshDocuments() {
        const search = document.getElementById('searchInput').value.trim();
        const type = document.getElementById('typeFilter').value;

        showLoading();
        fetch(window.location.href + '?action=fetch_documents&search=' + encodeURIComponent(search) + '&document_type=' + encodeURIComponent(type) + '&page=' + currentPage, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                renderDocuments(data.documents);
                renderPagination(data);
                document.getElementById('recordCount').textContent = data.documents.length + ' of ' + data.total + ' documents';
            }
        })
        .catch(error => { hideLoading(); showToast('Error', error.message, true); });
    }

    function renderDocuments(docs) {
        const tbody = document.getElementById('documentsTableBody');
        if (!docs || docs.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5"><div class="no-records"><i class="bi bi-file-earmark-text"></i><p>No documents found.</p></div></td></tr>`;
            return;
        }
        let html = '';
        docs.forEach(d => {
            const badgeClass = d.document_type.toLowerCase().replace(/ /g, '-');
            html += `
                <tr>
                    <td><span class="document-badge ${badgeClass}">${d.document_type}</span></td>
                    <td>${d.document_name}</td>
                    <td>${d.uploaded_by_name || 'Unknown'}</td>
                    <td>${d.formatted_date}</td>
                    <td>
                        <div class="actions">
                            <button class="action-btn view" onclick="viewDocument(${d.document_id})"><i class="bi bi-eye"></i></button>
                            <button class="action-btn download" onclick="downloadDocument(${d.document_id})"><i class="bi bi-download"></i></button>
                            <button class="action-btn delete" onclick="deleteDocument(${d.document_id})"><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
    }

    function renderPagination(data) {
        const area = document.getElementById('paginationArea');
        totalPages = data.pages || 1;
        currentPage = data.current_page || 1;
        let html = '';
        html += `<a href="#" class="page-item ${currentPage <= 1 ? 'disabled' : ''}" onclick="goToPage(${currentPage - 1})"><i class="bi bi-chevron-left"></i></a>`;
        for (let i = 1; i <= totalPages; i++) {
            html += `<a href="#" class="page-item ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">${i}</a>`;
        }
        html += `<a href="#" class="page-item ${currentPage >= totalPages ? 'disabled' : ''}" onclick="goToPage(${currentPage + 1})"><i class="bi bi-chevron-right"></i></a>`;
        area.innerHTML = html;
    }

    function goToPage(page) {
        if (page < 1 || page > totalPages) return;
        currentPage = page;
        refreshDocuments();
    }

    // Search & Filter
    let searchTimeout = null;
    document.getElementById('searchInput').addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => { currentPage = 1; refreshDocuments(); }, 500);
    });
    document.getElementById('typeFilter').addEventListener('change', function() { currentPage = 1; refreshDocuments(); });

    // Auto-refresh
    setInterval(() => { if (!document.hidden) refreshDocuments(); }, 30000);
    document.addEventListener('visibilitychange', function() { if (!document.hidden) refreshDocuments(); });

    // Init
    refreshDocuments();
    </script>
</body>
</html>