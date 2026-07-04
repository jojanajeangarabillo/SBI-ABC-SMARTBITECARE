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

// Handle Add Branch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_branch'])) {
    $branch_id = $conn->real_escape_string($_POST['branch_id']);
    $branch_name = $conn->real_escape_string($_POST['branch_name']);
    $branch_address = $conn->real_escape_string($_POST['branch_address']);
    $contact_number = $conn->real_escape_string($_POST['contact_number']);
    $email = $conn->real_escape_string($_POST['email']);
    $status = $conn->real_escape_string($_POST['status']);

    $sql = "INSERT INTO branches (branch_id, branch_name, branch_address, contact_number, email, status) 
            VALUES ('$branch_id', '$branch_name', '$branch_address', '$contact_number', '$email', '$status')";

    if ($conn->query($sql) === TRUE) {
        $success_msg = "Branch added successfully!";
    } else {
        $error_msg = "Error adding branch: " . $conn->error;
    }
}

// Handle Edit Branch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_branch'])) {
    $branch_id = $conn->real_escape_string($_POST['edit_branch_id']);
    $branch_name = $conn->real_escape_string($_POST['edit_branch_name']);
    $branch_address = $conn->real_escape_string($_POST['edit_branch_address']);
    $contact_number = $conn->real_escape_string($_POST['edit_contact_number']);
    $email = $conn->real_escape_string($_POST['edit_email']);
    $status = $conn->real_escape_string($_POST['edit_status']);

    $sql = "UPDATE branches SET 
            branch_name = '$branch_name',
            branch_address = '$branch_address',
            contact_number = '$contact_number',
            email = '$email',
            status = '$status'
            WHERE branch_id = '$branch_id'";

    if ($conn->query($sql) === TRUE) {
        $success_msg = "Branch updated successfully!";
    } else {
        $error_msg = "Error updating branch: " . $conn->error;
    }
}

// Handle Archive Branch (set status to Inactive)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_branch'])) {
    $branch_id = $conn->real_escape_string($_POST['archive_branch_id']);
    $sql = "UPDATE branches SET status = 'Inactive' WHERE branch_id = '$branch_id'";
    
    if ($conn->query($sql) === TRUE) {
        $success_msg = "Branch archived successfully!";
    } else {
        $error_msg = "Error archiving branch: " . $conn->error;
    }
}

// Fetch all branches from the database
$sql = "SELECT * FROM branches ORDER BY branch_id";
$result = $conn->query($sql);

// Fetch single branch for view/edit
$view_branch = null;
if (isset($_GET['view_id'])) {
    $view_id = $conn->real_escape_string($_GET['view_id']);
    $view_sql = "SELECT * FROM branches WHERE branch_id = '$view_id'";
    $view_result = $conn->query($view_sql);
    if ($view_result && $view_result->num_rows > 0) {
        $view_branch = $view_result->fetch_assoc();
    }
}

$edit_branch = null;
if (isset($_GET['edit_id'])) {
    $edit_id = $conn->real_escape_string($_GET['edit_id']);
    $edit_sql = "SELECT * FROM branches WHERE branch_id = '$edit_id'";
    $edit_result = $conn->query($edit_sql);
    if ($edit_result && $edit_result->num_rows > 0) {
        $edit_branch = $edit_result->fetch_assoc();
    }
}

$archive_branch = null;
if (isset($_GET['archive_id'])) {
    $archive_id = $conn->real_escape_string($_GET['archive_id']);
    $archive_sql = "SELECT * FROM branches WHERE branch_id = '$archive_id'";
    $archive_result = $conn->query($archive_sql);
    if ($archive_result && $archive_result->num_rows > 0) {
        $archive_branch = $archive_result->fetch_assoc();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Super Admin - Branch Management</title>
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <!-- Reusable Sidebar CSS (simulated) -->
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

        .content {
            padding: 35px 35px 40px;
        }

        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: center;
            margin-bottom: 24px;
        }
        .search-wrap {
            position: relative;
            flex: 1 1 280px;
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
        .btn-add {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 12px 28px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            transition: 0.15s;
        }
        .btn-add:hover {
            background: #1d2863;
            color: #fff;
        }

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
        .status-badge {
            display: inline-block;
            background: #d4f0d4;
            color: #1a6e1a;
            font-weight: 600;
            font-size: 13px;
            padding: 4px 16px;
            border-radius: 40px;
            letter-spacing: 0.2px;
        }
        .status-badge.inactive {
            background: #f8d7da;
            color: #721c24;
        }
        .action-icons i {
            font-size: 20px;
            color: var(--primary);
            margin-right: 10px;
            cursor: pointer;
            opacity: 0.7;
            transition: 0.1s;
        }
        .action-icons i:hover {
            opacity: 1;
        }
        .action-icons i:last-child {
            margin-right: 0;
        }

        .pagination-wrap {
            display: flex;
            justify-content: flex-end;
            padding-top: 24px;
            align-items: center;
            gap: 6px;
        }
        .pagination-wrap .page-item .page-link {
            color: var(--primary);
            border: 1px solid #d7def0;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 600;
            background: white;
            margin: 0 2px;
            transition: 0.1s;
        }
        .pagination-wrap .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        .pagination-wrap .page-item .page-link:hover {
            background: #e7ecfc;
            border-color: var(--primary);
        }

        /* Toast / Alert styling */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }
        .toast-custom {
            background: white;
            border-radius: 12px;
            padding: 16px 24px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            border-left: 6px solid #28a745;
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 320px;
            animation: slideIn 0.4s ease;
        }
        .toast-custom.error {
            border-left-color: #dc3545;
        }
        .toast-custom .toast-icon {
            font-size: 28px;
            color: #28a745;
        }
        .toast-custom.error .toast-icon {
            color: #dc3545;
        }
        .toast-custom .toast-msg {
            font-weight: 500;
            color: #1f2a4a;
            flex: 1;
        }
        .toast-custom .toast-close {
            background: none;
            border: none;
            font-size: 22px;
            color: #999;
            cursor: pointer;
            padding: 0 4px;
        }
        .toast-custom .toast-close:hover {
            color: #333;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(40px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @media (max-width: 991px) {
            .main { margin-left: 90px; }
            .sidebar { width: 90px; padding: 16px 10px; }
            .system-name, .nav-menu span, .logout span { display: none; }
            .logo-area { justify-content: center; }
            .nav-menu a { justify-content: center; padding: 12px 8px; }
            .nav-menu a i { font-size: 26px; margin: 0; }
            .logout a { justify-content: center; }
        }

        @media (max-width: 576px) {
            .topbar { padding: 0 16px; height: 70px; }
            .content { padding: 20px 16px; }
            .page-header h2 { font-size: 22px; }
            .table-wrap { overflow-x: auto; }
        }
    </style>
</head>
<body>

<!-- ========== TOAST / ALERT CONTAINER ========== -->
<div class="toast-container" id="toastContainer"></div>

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
            <li><a class="active" href="SuperAdmin_BranchManagement.php"><i class="bi bi-people-fill"></i><span>Branch Management</span></a></li>
            <li><a href="SuperAdmin_BranchAdminManagement.php"><i class="bi bi-heart-pulse-fill"></i><span>Branch Admin Management</span></a></li>
            <li><a href="SuperAdmin_UserMonitoring.php"><i class="bi bi-box-seam"></i><span>User Monitoring</span></a></li>
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
        <h3>Branch Management</h3>
        <div class="profile">SUPER ADMIN <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="content">

        <!-- toolbar: search + add button -->
        <div class="toolbar">
            <div class="search-wrap">
                <i class="bi bi-search"></i>
                <input type="text" id="searchInput" placeholder="Search branch..." onkeyup="filterTable()" />
            </div>
            <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addBranchModal">
                <i class="bi bi-plus-circle"></i> Add Branch
            </button>
        </div>

        <!-- table -->
        <div class="table-wrap">
            <table class="table table-hover align-middle" id="branchTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Branch Name</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['branch_id']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['branch_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['branch_address']); ?></td>
                                <td>
                                    <span class="status-badge <?php echo strtolower($row['status']) === 'inactive' ? 'inactive' : ''; ?>">
                                        <?php echo htmlspecialchars($row['status']); ?>
                                    </span>
                                </td>
                                <td class="action-icons">
                                    <i class="bi bi-eye" title="View" onclick="viewBranch('<?php echo $row['branch_id']; ?>')"></i>
                                    <i class="bi bi-pencil-square" title="Edit" onclick="editBranch('<?php echo $row['branch_id']; ?>')"></i>
                                    <i class="bi bi-archive" title="Archive" onclick="archiveBranch('<?php echo $row['branch_id']; ?>')"></i>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center">No branches found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- pagination -->
        <div class="pagination-wrap">
            <nav aria-label="Branch pagination">
                <ul class="pagination">
                    <li class="page-item active"><a class="page-link" href="#">1</a></li>
                    <li class="page-item"><a class="page-link" href="#">2</a></li>
                    <li class="page-item"><a class="page-link" href="#">3</a></li>
                    <li class="page-item"><a class="page-link" href="#">4</a></li>
                    <li class="page-item"><a class="page-link" href="#">5</a></li>
                </ul>
            </nav>
        </div>

    </div> <!-- /content -->
</div> <!-- /main -->

<!-- ========== ADD BRANCH MODAL ========== -->
<div class="modal fade" id="addBranchModal" tabindex="-1" aria-labelledby="addBranchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--primary); color: white;">
                <h5 class="modal-title" id="addBranchModalLabel"><i class="bi bi-plus-circle"></i> Add New Branch</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="SuperAdmin_BranchManagement.php">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="branch_id" class="form-label">Branch ID</label>
                            <input type="text" class="form-control" id="branch_id" name="branch_id" required placeholder="e.g. SBI-002">
                        </div>
                        <div class="col-md-6">
                            <label for="branch_name" class="form-label">Branch Name</label>
                            <input type="text" class="form-control" id="branch_name" name="branch_name" required placeholder="e.g. Quezon City Branch">
                        </div>
                        <div class="col-12">
                            <label for="branch_address" class="form-label">Branch Address</label>
                            <textarea class="form-control" id="branch_address" name="branch_address" rows="2" placeholder="Full address"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label for="contact_number" class="form-label">Contact Number</label>
                            <input type="text" class="form-control" id="contact_number" name="contact_number" placeholder="e.g. 09123456789">
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" placeholder="branch@smartbitecare.com">
                        </div>
                        <div class="col-md-6">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="Active" selected>Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_branch" class="btn" style="background: var(--primary); color: white;">Add Branch</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========== VIEW BRANCH MODAL ========== -->
<div class="modal fade" id="viewBranchModal" tabindex="-1" aria-labelledby="viewBranchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--primary); color: white;">
                <h5 class="modal-title" id="viewBranchModalLabel"><i class="bi bi-eye"></i> View Branch Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if ($view_branch): ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="fw-bold">Branch ID</label>
                            <p class="form-control-plaintext"><?php echo htmlspecialchars($view_branch['branch_id']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold">Branch Name</label>
                            <p class="form-control-plaintext"><?php echo htmlspecialchars($view_branch['branch_name']); ?></p>
                        </div>
                        <div class="col-12">
                            <label class="fw-bold">Address</label>
                            <p class="form-control-plaintext"><?php echo htmlspecialchars($view_branch['branch_address']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold">Contact Number</label>
                            <p class="form-control-plaintext"><?php echo htmlspecialchars($view_branch['contact_number']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold">Email</label>
                            <p class="form-control-plaintext"><?php echo htmlspecialchars($view_branch['email']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold">Status</label>
                            <p class="form-control-plaintext">
                                <span class="status-badge <?php echo strtolower($view_branch['status']) === 'inactive' ? 'inactive' : ''; ?>">
                                    <?php echo htmlspecialchars($view_branch['status']); ?>
                                </span>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold">Created At</label>
                            <p class="form-control-plaintext"><?php echo htmlspecialchars($view_branch['created_at']); ?></p>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-center">Branch not found.</p>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ========== EDIT BRANCH MODAL ========== -->
<div class="modal fade" id="editBranchModal" tabindex="-1" aria-labelledby="editBranchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: var(--primary); color: white;">
                <h5 class="modal-title" id="editBranchModalLabel"><i class="bi bi-pencil-square"></i> Edit Branch</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="SuperAdmin_BranchManagement.php">
                <div class="modal-body">
                    <?php if ($edit_branch): ?>
                        <input type="hidden" name="edit_branch_id" value="<?php echo htmlspecialchars($edit_branch['branch_id']); ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="edit_branch_name" class="form-label">Branch Name</label>
                                <input type="text" class="form-control" id="edit_branch_name" name="edit_branch_name" value="<?php echo htmlspecialchars($edit_branch['branch_name']); ?>" required>
                            </div>
                            <div class="col-12">
                                <label for="edit_branch_address" class="form-label">Branch Address</label>
                                <textarea class="form-control" id="edit_branch_address" name="edit_branch_address" rows="2"><?php echo htmlspecialchars($edit_branch['branch_address']); ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_contact_number" class="form-label">Contact Number</label>
                                <input type="text" class="form-control" id="edit_contact_number" name="edit_contact_number" value="<?php echo htmlspecialchars($edit_branch['contact_number']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="edit_email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="edit_email" name="edit_email" value="<?php echo htmlspecialchars($edit_branch['email']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="edit_status" class="form-label">Status</label>
                                <select class="form-select" id="edit_status" name="edit_status">
                                    <option value="Active" <?php echo $edit_branch['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="Inactive" <?php echo $edit_branch['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="text-center">Branch not found.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_branch" class="btn" style="background: var(--primary); color: white;">Update Branch</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========== ARCHIVE CONFIRMATION MODAL ========== -->
<div class="modal fade" id="archiveBranchModal" tabindex="-1" aria-labelledby="archiveBranchModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background: #dc3545; color: white;">
                <h5 class="modal-title" id="archiveBranchModalLabel"><i class="bi bi-exclamation-triangle"></i> Archive Branch</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="SuperAdmin_BranchManagement.php">
                <div class="modal-body">
                    <?php if ($archive_branch): ?>
                        <input type="hidden" name="archive_branch_id" value="<?php echo htmlspecialchars($archive_branch['branch_id']); ?>">
                        <p class="fs-5">Are you sure you want to archive this branch?</p>
                        <div class="bg-light p-3 rounded">
                            <p class="mb-1"><strong>Branch ID:</strong> <?php echo htmlspecialchars($archive_branch['branch_id']); ?></p>
                            <p class="mb-1"><strong>Branch Name:</strong> <?php echo htmlspecialchars($archive_branch['branch_name']); ?></p>
                            <p class="mb-0"><strong>Status:</strong> 
                                <span class="status-badge <?php echo strtolower($archive_branch['status']) === 'inactive' ? 'inactive' : ''; ?>">
                                    <?php echo htmlspecialchars($archive_branch['status']); ?>
                                </span>
                            </p>
                        </div>
                        <p class="text-danger mt-3"><i class="bi bi-info-circle"></i> This will set the branch status to "Inactive".</p>
                    <?php else: ?>
                        <p class="text-center">Branch not found.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="archive_branch" class="btn btn-danger">Yes, Archive</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Filter table rows based on search input
    function filterTable() {
        const input = document.getElementById('searchInput');
        const filter = input.value.toLowerCase();
        const table = document.getElementById('branchTable');
        const rows = table.getElementsByTagName('tr');

        for (let i = 1; i < rows.length; i++) {
            const cells = rows[i].getElementsByTagName('td');
            let found = false;
            for (let j = 0; j < cells.length - 1; j++) {
                const text = cells[j].textContent.toLowerCase();
                if (text.indexOf(filter) > -1) {
                    found = true;
                    break;
                }
            }
            rows[i].style.display = found ? '' : 'none';
        }
    }

    // View Branch - redirect to page with view_id parameter
    function viewBranch(id) {
        window.location.href = 'SuperAdmin_BranchManagement.php?view_id=' + encodeURIComponent(id);
    }

    // Edit Branch - redirect to page with edit_id parameter
    function editBranch(id) {
        window.location.href = 'SuperAdmin_BranchManagement.php?edit_id=' + encodeURIComponent(id);
    }

    // Archive Branch - redirect to page with archive_id parameter
    function archiveBranch(id) {
        window.location.href = 'SuperAdmin_BranchManagement.php?archive_id=' + encodeURIComponent(id);
    }

    // Auto-open modals if parameters are present
    document.addEventListener('DOMContentLoaded', function() {
        // Open view modal
        <?php if (isset($_GET['view_id']) && $view_branch): ?>
            var viewModal = new bootstrap.Modal(document.getElementById('viewBranchModal'));
            viewModal.show();
        <?php endif; ?>

        // Open edit modal
        <?php if (isset($_GET['edit_id']) && $edit_branch): ?>
            var editModal = new bootstrap.Modal(document.getElementById('editBranchModal'));
            editModal.show();
        <?php endif; ?>

        // Open archive modal
        <?php if (isset($_GET['archive_id']) && $archive_branch): ?>
            var archiveModal = new bootstrap.Modal(document.getElementById('archiveBranchModal'));
            archiveModal.show();
        <?php endif; ?>

        // Show toast for success/error messages
        <?php if (isset($success_msg)): ?>
            showToast('<?php echo addslashes($success_msg); ?>', 'success');
        <?php endif; ?>
        <?php if (isset($error_msg)): ?>
            showToast('<?php echo addslashes($error_msg); ?>', 'error');
        <?php endif; ?>
    });

    // Toast notification function
    function showToast(message, type = 'success') {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = 'toast-custom' + (type === 'error' ? ' error' : '');
        const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-x-circle-fill';
        toast.innerHTML = `
            <span class="toast-icon"><i class="bi ${icon}"></i></span>
            <span class="toast-msg">${message}</span>
            <button class="toast-close" onclick="this.parentElement.remove()">&times;</button>
        `;
        container.appendChild(toast);
        // Auto remove after 5 seconds
        setTimeout(() => {
            if (toast.parentElement) toast.remove();
        }, 5000);
    }
</script>

<?php
// Close connection
$conn->close();
?>
</body>
</html>