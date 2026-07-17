<?php
session_start();
require_once 'sources/db_connect.php';

// Check if user is logged in and is a branch admin
checkUserRole([2]); // role_id 2 = Branch Admin

// Get user data
$userData = getUserData($conn, $_SESSION['user_id']);
$branchId = $userData['branch_id'];

// Handle AJAX request for getting branch details
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_branch') {
    header('Content-Type: application/json');
    
    $branchQuery = "SELECT * FROM branches WHERE branch_id = ?";
    $stmt = $conn->prepare($branchQuery);
    $stmt->bind_param("s", $branchId);
    $stmt->execute();
    $branchResult = $stmt->get_result();
    
    if ($branchResult->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Branch not found']);
        exit();
    }
    
    $branch = $branchResult->fetch_assoc();
    echo json_encode(['success' => true, 'branch' => $branch]);
    exit();
}

// Handle AJAX request for updating branch
if (isset($_POST['ajax']) && $_POST['ajax'] == 'update_branch') {
    header('Content-Type: application/json');
    
    // Validate inputs
    $branchName = trim($_POST['branch_name']);
    $branchAddress = trim($_POST['branch_address']);
    $contactNumber = trim($_POST['contact_number']);
    $email = trim($_POST['email']);
    $status = trim($_POST['status']);
    
    if (empty($branchName) || empty($branchAddress)) {
        echo json_encode(['success' => false, 'message' => 'Branch name and address are required']);
        exit();
    }
    
    // Update branch
    $updateQuery = "UPDATE branches SET 
                    branch_name = ?,
                    branch_address = ?,
                    contact_number = ?,
                    email = ?,
                    status = ?
                    WHERE branch_id = ?";
    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("ssssss", $branchName, $branchAddress, $contactNumber, $email, $status, $branchId);
    
    if ($stmt->execute()) {
        // Log the action
        $logStmt = $conn->prepare("INSERT INTO audit_logs (user_id, branch_id, action, module) VALUES (?, ?, ?, ?)");
        $action = "Updated branch information: $branchName";
        $module = "Settings";
        $logStmt->bind_param("isss", $_SESSION['user_id'], $branchId, $action, $module);
        $logStmt->execute();
        
        echo json_encode(['success' => true, 'message' => 'Branch information updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update branch: ' . $conn->error]);
    }
    exit();
}

// Get branch information
$branchQuery = "SELECT * FROM branches WHERE branch_id = ?";
$stmt = $conn->prepare($branchQuery);
$stmt->bind_param("s", $branchId);
$stmt->execute();
$branchResult = $stmt->get_result();
$branch = $branchResult->fetch_assoc();

if (!$branch) {
    // Handle case where branch doesn't exist
    $branch = [
        'branch_id' => $branchId,
        'branch_name' => 'Unknown Branch',
        'branch_address' => '',
        'contact_number' => '',
        'email' => '',
        'status' => 'Active',
        'created_at' => date('Y-m-d H:i:s')
    ];
}

// Get last updated info from audit logs - FIXED ambiguous column
$logQuery = "SELECT 
                al.action,
                al.created_at as log_created_at,
                u.username
             FROM audit_logs al
             LEFT JOIN users u ON al.user_id = u.user_id
             WHERE al.branch_id = ? 
             AND al.module = 'Settings'
             AND al.action LIKE 'Updated branch information%'
             ORDER BY al.created_at DESC
             LIMIT 1";
$stmt = $conn->prepare($logQuery);
$stmt->bind_param("s", $branchId);
$stmt->execute();
$logResult = $stmt->get_result();
$lastUpdate = $logResult->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Branch Admin Settings</title>
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

        @media (max-width:991px) {
            .main {
                margin-left: 90px;
            }
        }

        .content-wrapper {
            padding: 28px 35px 40px 35px;
        }

        /* Settings Page Styles */
        .settings-card {
            background: #fff;
            border: 1px solid #e6e9f2;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 3px 10px rgba(0,0,0,.05);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .settings-card:hover {
            box-shadow: 0 6px 20px rgba(0,0,0,.08);
        }

        .settings-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
        }

        .settings-header .settings-info {
            flex: 1;
        }

        .settings-info h5 {
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 6px;
        }

        .settings-info p {
            color: #6b7280;
            margin: 0;
        }

        .settings-icon {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            flex-shrink: 0;
        }

        .settings-icon.blue {
            background: #eef3ff;
            color: #2B3A8C;
        }

        .settings-icon.green {
            background: #ecfff4;
            color: #18a558;
        }

        .settings-icon.orange {
            background: #fff4e7;
            color: #ff8c00;
        }

        .settings-icon.red {
            background: #ffe7e7;
            color: #dc3545;
        }

        .btn-settings {
            border: 2px solid #d7dff8;
            background: #fff;
            color: #2B3A8C;
            font-weight: 600;
            padding: 10px 18px;
            border-radius: 8px;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .btn-settings:hover {
            background: #2B3A8C;
            color: #fff;
            border-color: #2B3A8C;
        }

        .btn-settings i {
            margin-right: 6px;
        }

        .settings-details {
            margin-top: 25px;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            border: 1px solid #edf0f7;
            border-radius: 10px;
            overflow: hidden;
        }

        .settings-details.three-col {
            grid-template-columns: repeat(3, 1fr);
        }

        .settings-details div {
            padding: 18px 22px;
            border-right: 1px solid #edf0f7;
        }

        .settings-details div:last-child {
            border-right: none;
        }

        .settings-details small {
            color: #6b7280;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .settings-details h6 {
            margin-top: 8px;
            font-weight: 600;
            color: #1e293b;
            font-size: 14px;
            word-break: break-word;
        }

        .info-banner {
            background: #eef4ff;
            border: 1px solid #d7e3ff;
            color: #2B3A8C;
            padding: 18px 22px;
            border-radius: 12px;
            font-weight: 600;
        }

        .info-banner i {
            margin-right: 10px;
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
        }

        .modal-footer {
            padding: 15px 25px;
            border-top: 1px solid #e9edf4;
        }

        .form-label {
            font-weight: 600;
            color: #1e293b;
            font-size: 14px;
        }

        .form-control {
            border-radius: 8px;
            border: 1px solid #d9dee8;
            padding: 10px 14px;
            font-size: 14px;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(43, 58, 140, 0.1);
        }

        .btn-primary {
            background: var(--primary);
            border: none;
            padding: 10px 24px;
            font-weight: 600;
            border-radius: 8px;
            transition: background 0.2s;
        }

        .btn-primary:hover {
            background: #1f2d6e;
        }

        .btn-secondary {
            padding: 10px 24px;
            font-weight: 600;
            border-radius: 8px;
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

        @media (max-width: 768px) {
            .content-wrapper {
                padding: 18px 16px 30px 16px;
            }

            .settings-header {
                flex-direction: column;
            }

            .btn-settings {
                width: 100%;
                justify-content: center;
            }

            .settings-details,
            .settings-details.three-col {
                grid-template-columns: 1fr;
            }

            .settings-details div {
                border-right: none;
                border-bottom: 1px solid #edf0f7;
            }

            .settings-details div:last-child {
                border-bottom: none;
            }

            .modal-dialog {
                margin: 10px;
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

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-active {
            background: #dff0e6;
            color: #0f7b3a;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        /* Loading spinner */
        .modal-loading {
            text-align: center;
            padding: 40px 20px;
        }

        .modal-loading .spinner-border {
            color: var(--primary);
        }

        .text-muted-small {
            font-size: 12px;
            color: #9ca3af;
        }

        .branch-id-badge {
            background: #f1f2f6;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 12px;
            color: #6b7280;
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
                <li><a href="BranchAdmin_PatientMonitoring.php"><i class="bi bi-heart-pulse-fill"></i><span>Patient Monitoring</span></a></li>
                <li><a href="BranchAdmin_MedicalSupplies.php"><i class="bi bi-box-seam"></i><span>Medical Supplies</span></a></li>
                <li><a href="BranchAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
                <li><a href="BranchAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
                <li><a href="BranchAdmin_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
                <li><a class="active" href="BranchAdmin_Settings.php"><i class="bi bi-gear-fill"></i><span>Settings</span></a></li>
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
            <h3>Settings</h3>
            <div class="profile">
                <?php echo htmlspecialchars($userData['username'] ?? 'ADMIN'); ?> 
                <i class="bi bi-caret-down-fill"></i>
            </div>
        </div>

        <!-- Toast Container -->
        <div class="toast-container">
            <div id="toastMessage" class="toast" role="alert" aria-live="polite" aria-atomic="true">
                <div class="toast-body" id="toastBody"></div>
            </div>
        </div>

        <div class="content-wrapper">
            <!-- Branch Information -->
            <div class="settings-card">
                <div class="settings-header">
                    <div class="settings-icon orange">
                        <i class="bi bi-building"></i>
                    </div>

                    <div class="settings-info">
                        <h5>Update Branch Information</h5>
                        <p>Update the branch details and address information.</p>
                        <span class="branch-id-badge">ID: <?php echo htmlspecialchars($branchId); ?></span>
                    </div>

                    <button class="btn btn-settings" id="editBranchBtn">
                        <i class="bi bi-pencil-square"></i>
                        Update Branch Info
                    </button>
                </div>

                <div class="settings-details three-col">
                    <div>
                        <small>Branch Name</small>
                        <h6 id="displayBranchName"><?php echo htmlspecialchars($branch['branch_name']); ?></h6>
                    </div>

                    <div>
                        <small>Address</small>
                        <h6 id="displayBranchAddress"><?php echo htmlspecialchars($branch['branch_address'] ?? 'Not set'); ?></h6>
                    </div>

                    <div>
                        <small>Last Updated</small>
                        <h6 id="displayLastUpdated">
                            <?php 
                            if ($lastUpdate) {
                                echo date('M d, Y • h:i A', strtotime($lastUpdate['log_created_at']));
                                echo '<br><span class="text-muted-small">by ' . htmlspecialchars($lastUpdate['username'] ?? 'Unknown') . '</span>';
                            } else {
                                echo 'Never updated';
                            }
                            ?>
                        </h6>
                    </div>
                </div>

                <div class="settings-details three-col" style="margin-top: 0; border-top: none; border-radius: 0 0 10px 10px;">
                    <div>
                        <small>Contact Number</small>
                        <h6 id="displayContactNumber"><?php echo htmlspecialchars($branch['contact_number'] ?? 'Not set'); ?></h6>
                    </div>

                    <div>
                        <small>Email Address</small>
                        <h6 id="displayEmail"><?php echo htmlspecialchars($branch['email'] ?? 'Not set'); ?></h6>
                    </div>

                    <div>
                        <small>Status</small>
                        <h6>
                            <span class="status-badge <?php echo ($branch['status'] ?? 'Active') == 'Active' ? 'status-active' : 'status-inactive'; ?>">
                                <?php echo htmlspecialchars($branch['status'] ?? 'Active'); ?>
                            </span>
                        </h6>
                    </div>
                </div>
            </div>

            <!-- Information Banner -->
           
        </div>
    </div>

    <!-- Edit Branch Modal -->
    <div class="modal fade" id="editBranchModal" tabindex="-1" aria-labelledby="editBranchModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editBranchModalLabel">
                        <i class="bi bi-building me-2"></i> Update Branch Information
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="editBranchModalBody">
                    <div class="modal-loading">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-3 text-muted">Loading branch information...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveBranchBtn">
                        <i class="bi bi-check-circle me-1"></i> Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const editBranchBtn = document.getElementById('editBranchBtn');
            const saveBranchBtn = document.getElementById('saveBranchBtn');
            const modalBody = document.getElementById('editBranchModalBody');
            const modal = new bootstrap.Modal(document.getElementById('editBranchModal'));
            const toast = new bootstrap.Toast(document.getElementById('toastMessage'), {
                delay: 5000
            });

            // Show toast message
            function showToast(message, type = 'success') {
                const toastEl = document.getElementById('toastMessage');
                const toastBody = document.getElementById('toastBody');
                toastEl.className = `toast toast-${type}`;
                toastBody.innerHTML = message;
                toast.show();
            }

            // Load branch data into modal
            function loadBranchData() {
                modalBody.innerHTML = `
                    <div class="modal-loading">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-3 text-muted">Loading branch information...</p>
                    </div>
                `;

                fetch('?ajax=get_branch')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const branch = data.branch;
                            modalBody.innerHTML = `
                                <form id="branchForm">
                                    <div class="mb-3">
                                        <label for="branch_name" class="form-label">Branch Name *</label>
                                        <input type="text" class="form-control" id="branch_name" 
                                               value="${escapeHtml(branch.branch_name)}" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="branch_address" class="form-label">Branch Address *</label>
                                        <textarea class="form-control" id="branch_address" rows="3" required>${escapeHtml(branch.branch_address || '')}</textarea>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="contact_number" class="form-label">Contact Number</label>
                                                <input type="text" class="form-control" id="contact_number" 
                                                       value="${escapeHtml(branch.contact_number || '')}">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="email" class="form-label">Email Address</label>
                                                <input type="email" class="form-control" id="email" 
                                                       value="${escapeHtml(branch.email || '')}">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="status" class="form-label">Status</label>
                                        <select class="form-select" id="status">
                                            <option value="Active" ${branch.status === 'Active' ? 'selected' : ''}>Active</option>
                                            <option value="Inactive" ${branch.status === 'Inactive' ? 'selected' : ''}>Inactive</option>
                                        </select>
                                    </div>
                                    <div class="alert alert-info mt-2">
                                        <i class="bi bi-info-circle me-1"></i>
                                        <small>Branch ID: <strong>${escapeHtml(branch.branch_id)}</strong> (cannot be changed)</small>
                                    </div>
                                </form>
                            `;
                        } else {
                            modalBody.innerHTML = `
                                <div class="alert alert-danger">
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                    ${data.message || 'Failed to load branch information.'}
                                </div>
                            `;
                        }
                    })
                    .catch(error => {
                        modalBody.innerHTML = `
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                An error occurred while loading branch information.
                            </div>
                        `;
                        console.error('Error:', error);
                    });
            }

            // Escape HTML
            function escapeHtml(text) {
                if (!text) return '';
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            // Edit button click
            editBranchBtn.addEventListener('click', function(e) {
                e.preventDefault();
                loadBranchData();
                modal.show();
            });

            // Save branch changes
            saveBranchBtn.addEventListener('click', function() {
                const form = document.getElementById('branchForm');
                if (!form) return;

                const branchName = document.getElementById('branch_name').value.trim();
                const branchAddress = document.getElementById('branch_address').value.trim();
                const contactNumber = document.getElementById('contact_number').value.trim();
                const email = document.getElementById('email').value.trim();
                const status = document.getElementById('status').value;

                // Validate
                if (!branchName || !branchAddress) {
                    showToast('Branch name and address are required.', 'error');
                    return;
                }

                // Show loading state
                saveBranchBtn.disabled = true;
                saveBranchBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

                // Prepare data
                const formData = new FormData();
                formData.append('ajax', 'update_branch');
                formData.append('branch_name', branchName);
                formData.append('branch_address', branchAddress);
                formData.append('contact_number', contactNumber);
                formData.append('email', email);
                formData.append('status', status);

                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    saveBranchBtn.disabled = false;
                    saveBranchBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Save Changes';

                    if (data.success) {
                        // Update display
                        document.getElementById('displayBranchName').textContent = branchName;
                        document.getElementById('displayBranchAddress').textContent = branchAddress;
                        document.getElementById('displayContactNumber').textContent = contactNumber || 'Not set';
                        document.getElementById('displayEmail').textContent = email || 'Not set';
                        
                        // Update last updated
                        const now = new Date();
                        const dateStr = now.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
                        const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                        document.getElementById('displayLastUpdated').innerHTML = 
                            `${dateStr} • ${timeStr}<br><span class="text-muted-small">by <?php echo htmlspecialchars($userData['username'] ?? 'You'); ?></span>`;
                        
                        // Update status badge
                        const statusBadge = document.querySelector('.settings-details.three-col:last-child .status-badge');
                        if (statusBadge) {
                            statusBadge.textContent = status;
                            statusBadge.className = `status-badge ${status === 'Active' ? 'status-active' : 'status-inactive'}`;
                        }

                        modal.hide();
                        showToast('Branch information updated successfully!', 'success');
                    } else {
                        showToast(data.message || 'Failed to update branch information.', 'error');
                    }
                })
                .catch(error => {
                    saveBranchBtn.disabled = false;
                    saveBranchBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Save Changes';
                    showToast('An error occurred while saving.', 'error');
                    console.error('Error:', error);
                });
            });

            // Reset button state when modal is hidden
            document.getElementById('editBranchModal').addEventListener('hidden.bs.modal', function() {
                saveBranchBtn.disabled = false;
                saveBranchBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Save Changes';
            });
        });
    </script>
</body>
</html>