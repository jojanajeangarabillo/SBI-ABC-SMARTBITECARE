<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Nurse - Medical Supplies Management</title>
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <!-- Reusable Sidebar CSS (simulated) -->
    <link rel="stylesheet" href="sidebar.css" />
    <style>
        /* =========================================
           INTERNAL CSS
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

        /* ---- section title ---- */
        .section-label {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 16px;
        }

        /* ---- stat cards ---- */
        .stat-card {
            background: var(--card-bg);
            border-radius: 18px;
            padding: 18px 20px;
            height: 100px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.06);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .stat-title {
            font-weight: 600;
            color: var(--primary);
            font-size: 14px;
            letter-spacing: 0.2px;
        }
        .stat-number {
            font-size: 34px;
            font-weight: 700;
            color: var(--primary);
            line-height: 1.1;
        }

        /* ---- function buttons ---- */
        .function-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 24px;
        }
        .btn-function {
            background: var(--card-bg);
            color: var(--primary);
            border: none;
            border-radius: 40px;
            padding: 8px 20px;
            font-weight: 600;
            font-size: 14px;
            transition: 0.15s;
        }
        .btn-function:hover {
            background: #d7def0;
        }
        .btn-function i {
            margin-right: 6px;
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

        .status-badge {
            display: inline-block;
            font-weight: 600;
            font-size: 13px;
            padding: 4px 16px;
            border-radius: 40px;
            letter-spacing: 0.2px;
        }
        .status-badge.normal {
            background: #d4f0d4;
            color: #1a6e1a;
        }
        .status-badge.low {
            background: #fde8b0;
            color: #8a6d00;
        }
        .status-badge.critical {
            background: #fde8e8;
            color: var(--accent);
        }

        .action-icons i {
            font-size: 18px;
            color: var(--primary);
            margin-right: 12px;
            cursor: pointer;
            opacity: 0.7;
            transition: 0.1s;
        }
        .action-icons i:hover {
            opacity: 1;
        }

        /* ---- search ---- */
        .search-wrap {
            position: relative;
            max-width: 380px;
            margin-bottom: 16px;
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
            padding: 10px 12px 10px 44px;
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

        /* ---- modal styles ---- */
        .modal-content {
            border-radius: 18px;
            border: none;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
        }
        .modal-header {
            border-bottom: 1px solid #edf1f8;
            padding: 20px 28px;
        }
        .modal-header .modal-title {
            font-weight: 700;
            color: var(--primary);
            font-size: 20px;
        }
        .modal-body {
            padding: 24px 28px;
        }
        .modal-footer {
            border-top: 1px solid #edf1f8;
            padding: 16px 28px;
        }
        .modal .form-label {
            font-weight: 600;
            color: var(--primary);
            font-size: 14px;
        }
        .modal .form-control,
        .modal .form-select {
            border-radius: 10px;
            border: 1px solid #d0d7e8;
            padding: 10px 16px;
        }
        .modal .form-control:focus,
        .modal .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.15);
        }
        .btn-save {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 10px 32px;
            font-weight: 600;
            transition: 0.15s;
        }
        .btn-save:hover {
            background: #1d2863;
            color: #fff;
        }
        .btn-cancel {
            background: var(--card-bg);
            color: var(--primary);
            border: none;
            border-radius: 40px;
            padding: 10px 28px;
            font-weight: 600;
            transition: 0.15s;
        }
        .btn-cancel:hover {
            background: #d7def0;
        }

        /* ---- consumption panel ---- */
        .consumption-panel {
            background: white;
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            padding: 22px 28px 26px;
            margin-top: 20px;
        }
        .consumption-panel .panel-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 16px;
        }
        .consumption-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px 20px;
            padding: 8px 0;
            border-bottom: 1px solid #edf1f8;
        }
        .consumption-row:last-child {
            border-bottom: none;
        }
        .consumption-row .item-label {
            font-weight: 600;
            color: var(--primary);
            min-width: 80px;
        }
        .consumption-row .item-input {
            width: 100px;
            border: 1px solid #d0d7e8;
            border-radius: 10px;
            padding: 6px 12px;
            text-align: center;
            outline: none;
        }
        .consumption-row .item-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.15);
        }
        .btn-save-consumption {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 10px 32px;
            font-weight: 600;
            transition: 0.15s;
            margin-top: 12px;
        }
        .btn-save-consumption:hover {
            background: #1d2863;
            color: #fff;
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
            .content {
                padding: 20px 16px;
            }
            .page-header h2 {
                font-size: 22px;
            }
            .stat-number {
                font-size: 28px;
            }
            .stat-card {
                height: 80px;
                padding: 14px;
            }
            .function-buttons {
                gap: 6px;
            }
            .btn-function {
                font-size: 12px;
                padding: 6px 14px;
            }
            .table-wrap {
                overflow-x: auto;
            }
            .table thead th,
            .table tbody td {
                padding: 10px 12px;
                font-size: 13px;
            }
            .search-wrap {
                max-width: 100%;
            }
            .consumption-panel {
                padding: 16px;
            }
            .consumption-row {
                flex-wrap: wrap;
            }
            .modal-body {
                padding: 16px;
            }
        }
    </style>
</head>
<body>

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
            <li><a href="Nurse_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="Nurse_Patients.php"><i class="bi bi-heart-pulse-fill"></i><span>Patients</span></a></li>
            <li><a class="active" href="Nurse_MedicalSuppliesManagement.php"><i class="bi bi-calendar-check"></i><span>Medical Supplies Management</span></a></li>
            <li><a href="Nurse_SupplyPrediction.php"><i class="bi bi-box-seam"></i><span>Supply Prediction</span></a></li>
            <li><a href="Nurse_Notifications.php"><i class="bi bi-graph-up-arrow"></i><span>Notification</span></a></li>
        </ul>
    </nav>

    <div class="logout">
        <a href="#"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </div>
</div>

<!-- ========== MAIN CONTENT ========== -->
<div class="main">

    <!-- TOP BAR -->
    <div class="topbar">
        <h3>Medical Supplies Management</h3>
        <div class="profile">NURSE <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="content">

        <!-- Function Buttons -->
        <div class="function-buttons">
            <button class="btn-function" data-bs-toggle="modal" data-bs-target="#recordUsageModal"><i class="bi bi-pencil-square"></i> Record Usage</button>
            <button class="btn-function" data-bs-toggle="modal" data-bs-target="#dailyConsumptionModal"><i class="bi bi-clipboard2-check"></i> Record Daily Consumption</button>
            <button class="btn-function" data-bs-toggle="modal" data-bs-target="#requestRestockModal"><i class="bi bi-box-arrow-up-right"></i> Request Restock</button>
            <button class="btn-function" data-bs-toggle="modal" data-bs-target="#expiringModal"><i class="bi bi-clock-history"></i> View Expiring</button>
            <button class="btn-function" data-bs-toggle="modal" data-bs-target="#usageHistoryModal"><i class="bi bi-clock"></i> Usage History</button>
        </div>

        <!-- Top Summary Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-title">Total Medical Supplies</div>
                    <div class="stat-number">25</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-title">Low Stocks</div>
                    <div class="stat-number">3</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-title">Expiring</div>
                    <div class="stat-number">2</div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-title">Today's Usage</div>
                    <div class="stat-number">18</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-title">Today's Patients</div>
                    <div class="stat-number">15</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-title">Restock Requests</div>
                    <div class="stat-number">2</div>
                </div>
            </div>
        </div>

        <!-- Search -->
        <div class="search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" placeholder="Search supplies..." />
        </div>

        <!-- Medical Supplies Table -->
        <div class="table-wrap">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Stock</th>
                        <th>Unit</th>
                        <th>Expiry</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>SPEEDA</strong></td>
                        <td>35</td>
                        <td>Vial</td>
                        <td>Dec 2027</td>
                        <td><span class="status-badge normal">Normal</span></td>
                        <td class="action-icons">
                            <i class="bi bi-pencil-square" data-bs-toggle="modal" data-bs-target="#recordUsageModal" title="Record Usage"></i>
                            <i class="bi bi-clock-history" data-bs-toggle="modal" data-bs-target="#usageHistoryModal" title="Usage History"></i>
                            <i class="bi bi-box-arrow-up-right" data-bs-toggle="modal" data-bs-target="#requestRestockModal" title="Request Restock"></i>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>BETT</strong></td>
                        <td>12</td>
                        <td>Amp</td>
                        <td>Nov 2026</td>
                        <td><span class="status-badge low">Low</span></td>
                        <td class="action-icons">
                            <i class="bi bi-pencil-square" data-bs-toggle="modal" data-bs-target="#recordUsageModal" title="Record Usage"></i>
                            <i class="bi bi-clock-history" data-bs-toggle="modal" data-bs-target="#usageHistoryModal" title="Usage History"></i>
                            <i class="bi bi-box-arrow-up-right" data-bs-toggle="modal" data-bs-target="#requestRestockModal" title="Request Restock"></i>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>ERIG</strong></td>
                        <td>5</td>
                        <td>Vial</td>
                        <td>Jan 2027</td>
                        <td><span class="status-badge low">Low</span></td>
                        <td class="action-icons">
                            <i class="bi bi-pencil-square" data-bs-toggle="modal" data-bs-target="#recordUsageModal" title="Record Usage"></i>
                            <i class="bi bi-clock-history" data-bs-toggle="modal" data-bs-target="#usageHistoryModal" title="Usage History"></i>
                            <i class="bi bi-box-arrow-up-right" data-bs-toggle="modal" data-bs-target="#requestRestockModal" title="Request Restock"></i>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>ATS</strong></td>
                        <td>28</td>
                        <td>Vial</td>
                        <td>Aug 2026</td>
                        <td><span class="status-badge normal">Normal</span></td>
                        <td class="action-icons">
                            <i class="bi bi-pencil-square" data-bs-toggle="modal" data-bs-target="#recordUsageModal" title="Record Usage"></i>
                            <i class="bi bi-clock-history" data-bs-toggle="modal" data-bs-target="#usageHistoryModal" title="Usage History"></i>
                            <i class="bi bi-box-arrow-up-right" data-bs-toggle="modal" data-bs-target="#requestRestockModal" title="Request Restock"></i>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>1CC Syringe</strong></td>
                        <td>45</td>
                        <td>Pcs</td>
                        <td>Mar 2027</td>
                        <td><span class="status-badge normal">Normal</span></td>
                        <td class="action-icons">
                            <i class="bi bi-pencil-square" data-bs-toggle="modal" data-bs-target="#recordUsageModal" title="Record Usage"></i>
                            <i class="bi bi-clock-history" data-bs-toggle="modal" data-bs-target="#usageHistoryModal" title="Usage History"></i>
                            <i class="bi bi-box-arrow-up-right" data-bs-toggle="modal" data-bs-target="#requestRestockModal" title="Request Restock"></i>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- ============================================================ -->
        <!-- RECORD USAGE MODAL -->
        <!-- ============================================================ -->
        <div class="modal fade" id="recordUsageModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Record Medical Supply Usage</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Patient</label>
                            <select class="form-select">
                                <option>Juan Dela Cruz</option>
                                <option>Maria Santos</option>
                                <option>Pedro Reyes</option>
                                <option>Ana Garcia</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Case</label>
                            <select class="form-select">
                                <option>Case #001</option>
                                <option>Case #002</option>
                                <option>Case #003</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Supply</label>
                            <select class="form-select">
                                <option>SPEEDA</option>
                                <option>BETT</option>
                                <option>ERIG</option>
                                <option>ATS</option>
                                <option>1CC Syringe</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Quantity Used</label>
                            <input type="number" class="form-control" value="1" min="1" />
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Reason</label>
                            <select class="form-select">
                                <option>Vaccination Dose 1</option>
                                <option>Vaccination Dose 2</option>
                                <option>Booster</option>
                                <option>Wound Care</option>
                                <option>Consultation</option>
                            </select>
                        </div>
                        <div class="mb-0">
                            <label class="form-label">Date</label>
                            <input type="text" class="form-control" value="Auto Today" disabled />
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn-save" data-bs-dismiss="modal"><i class="bi bi-check-lg me-2"></i>Save</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- DAILY CONSUMPTION MODAL -->
        <!-- ============================================================ -->
        <div class="modal fade" id="dailyConsumptionModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-clipboard2-check me-2"></i>Today's Consumption Entry</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="consumption-row">
                            <span class="item-label">SPEEDA</span>
                            <span>Used Today:</span>
                            <input type="number" class="item-input" value="8" />
                        </div>
                        <div class="consumption-row">
                            <span class="item-label">BETT</span>
                            <span>Used Today:</span>
                            <input type="number" class="item-input" value="3" />
                        </div>
                        <div class="consumption-row">
                            <span class="item-label">ERIG</span>
                            <span>Used Today:</span>
                            <input type="number" class="item-input" value="1" />
                        </div>
                        <div class="consumption-row">
                            <span class="item-label">ATS</span>
                            <span>Used Today:</span>
                            <input type="number" class="item-input" value="2" />
                        </div>
                        <div class="consumption-row">
                            <span class="item-label">1CC Syringe</span>
                            <span>Used Today:</span>
                            <input type="number" class="item-input" value="14" />
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn-save" data-bs-dismiss="modal"><i class="bi bi-check-lg me-2"></i>Save Daily Consumption</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- REQUEST RESTOCK MODAL -->
        <!-- ============================================================ -->
        <div class="modal fade" id="requestRestockModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-box-arrow-up-right me-2"></i>Request Restock</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Item</label>
                            <select class="form-select">
                                <option>SPEEDA</option>
                                <option>BETT</option>
                                <option>ERIG</option>
                                <option>ATS</option>
                                <option>1CC Syringe</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Current Stock</label>
                            <input type="text" class="form-control" value="15 Vials" disabled />
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Requested Quantity</label>
                            <input type="number" class="form-control" value="100" min="1" />
                        </div>
                        <div class="mb-0">
                            <label class="form-label">Reason</label>
                            <textarea class="form-control" rows="2">Low stock due to increasing cases</textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn-cancel" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn-save" data-bs-dismiss="modal"><i class="bi bi-send me-2"></i>Submit Request</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- EXPIRING VACCINES MODAL -->
        <!-- ============================================================ -->
        <div class="modal fade" id="expiringModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>Expiring Vaccines</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="table-wrap">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Batch</th>
                                        <th>Expiration</th>
                                        <th>Days Remaining</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>ERIG</strong></td>
                                        <td>B001</td>
                                        <td>Jul 20, 2026</td>
                                        <td><span class="status-badge critical">15 Days</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong>ATS</strong></td>
                                        <td>A101</td>
                                        <td>Aug 05, 2026</td>
                                        <td><span class="status-badge low">31 Days</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="alert alert-warning mt-2">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>ERIG</strong> expires in 15 days &bull; <strong>ATS</strong> expires in 31 days
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn-cancel" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- USAGE HISTORY MODAL -->
        <!-- ============================================================ -->
        <div class="modal fade" id="usageHistoryModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-clock me-2"></i>Usage History</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="d-flex gap-2 mb-3 flex-wrap">
                            <button class="btn-function" style="background: var(--primary); color: white;">Daily</button>
                            <button class="btn-function">Weekly</button>
                            <button class="btn-function">Monthly</button>
                        </div>
                        <div class="table-wrap">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Item</th>
                                        <th>Quantity Used</th>
                                        <th>Patients Served</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Jul 1</td>
                                        <td><strong>SPEEDA</strong></td>
                                        <td>10</td>
                                        <td>8</td>
                                    </tr>
                                    <tr>
                                        <td>Jul 1</td>
                                        <td><strong>BETT</strong></td>
                                        <td>3</td>
                                        <td>3</td>
                                    </tr>
                                    <tr>
                                        <td>Jul 2</td>
                                        <td><strong>SPEEDA</strong></td>
                                        <td>8</td>
                                        <td>6</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-2 text-muted small">
                            <i class="bi bi-info-circle"></i> This data is used for XGBoost prediction training.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn-cancel" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

    </div> <!-- /content -->
</div> <!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>