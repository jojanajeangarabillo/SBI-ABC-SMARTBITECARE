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
            cursor: default;
            opacity: 0.7;
            transition: 0.1s;
        }
        .action-icon:hover {
            opacity: 1;
        }

        /* ---- action buttons row ---- */
        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 28px;
        }
        .btn-outline-primary-custom {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
            border-radius: 40px;
            padding: 10px 28px;
            font-weight: 600;
            transition: 0.15s;
        }
        .btn-outline-primary-custom:hover {
            background: var(--primary);
            color: #fff;
        }
        .btn-primary-custom {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 10px 28px;
            font-weight: 600;
            transition: 0.15s;
        }
        .btn-primary-custom:hover {
            background: #1d2863;
            color: #fff;
        }

        /* ---- case summary card ---- */
        .summary-card {
            background: white;
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            padding: 28px 30px 30px;
            margin-top: 10px;
        }
        .summary-card .summary-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 14px;
        }
        .summary-card .summary-text {
            color: #2a3a5a;
            font-size: 15px;
            line-height: 1.7;
            margin-bottom: 20px;
        }
        .summary-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        .summary-actions .btn-sm-custom {
            background: var(--card-bg);
            color: var(--primary);
            border: none;
            border-radius: 40px;
            padding: 8px 24px;
            font-weight: 600;
            font-size: 14px;
            transition: 0.15s;
        }
        .summary-actions .btn-sm-custom:hover {
            background: #d7def0;
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
            .summary-card {
                padding: 18px;
            }
            .action-buttons {
                flex-direction: column;
            }
            .action-buttons .btn {
                width: 100%;
                justify-content: center;
            }
            .summary-actions {
                flex-direction: column;
            }
            .summary-actions .btn-sm-custom {
                width: 100%;
                text-align: center;
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
            <li><a class="active" href="Nurse_Patients.php"><i class="bi bi-heart-pulse-fill"></i><span>Patients</span></a></li>
            <li><a href="Nurse_MedicalSuppliesManagement.php"><i class="bi bi-calendar-check"></i><span>Medical Supplies Management</span></a></li>
            <li><a href="Nurse_SupplyPrediction.php"><i class="bi bi-box-seam"></i><span>Supply Prediction</span></a></li>
            <li><a href="Nurse_Notification.php"><i class="bi bi-graph-up-arrow"></i><span>Notification</span></a></li>
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
        <h3>Patient Module</h3>
        <div class="profile">NURSE <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="content">

        <!-- Search -->
        <div class="search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" placeholder="Search Patients" />
        </div>

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
                    <tr>
                        <td><strong>C205-001</strong></td>
                        <td>Ang, Jake Quellin</td>
                        <td>June 16, 2026</td>
                        <td><span class="status-badge ongoing">Ongoing</span></td>
                        <td><i class="bi bi-clipboard action-icon"></i></td>
                    </tr>
                    <tr>
                        <td><strong>C205-002</strong></td>
                        <td>Apolinario, Mark Vicente</td>
                        <td>June 13, 2026</td>
                        <td><span class="status-badge ongoing">Ongoing</span></td>
                        <td><i class="bi bi-clipboard action-icon"></i></td>
                    </tr>
                    <tr>
                        <td><strong>C205-003</strong></td>
                        <td>Balboga, Chelsea Thea D.</td>
                        <td>June 10, 2026</td>
                        <td><span class="status-badge completed">Completed</span></td>
                        <td><i class="bi bi-clipboard action-icon"></i></td>
                    </tr>
                    <tr>
                        <td><strong>C205-004</strong></td>
                        <td>Calumpang, Darrelyn</td>
                        <td>June 07, 2026</td>
                        <td><span class="status-badge ongoing">Ongoing</span></td>
                        <td><i class="bi bi-clipboard action-icon"></i></td>
                    </tr>
                    <tr>
                        <td><strong>C205-005</strong></td>
                        <td>Leming, Corazon F.</td>
                        <td>June 03, 2026</td>
                        <td><span class="status-badge completed">Completed</span></td>
                        <td><i class="bi bi-clipboard action-icon"></i></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Action Buttons: View All Patients | Add New Patients -->
        <div class="action-buttons">
            <button class="btn-outline-primary-custom"><i class="bi bi-eye me-2"></i> View All Patients</button>
            <button class="btn-primary-custom"><i class="bi bi-plus-circle me-2"></i> Add New Patients</button>
        </div>

        <!-- Case Summary -->
        <div class="summary-card">
            <div class="summary-title">Case Summary</div>
            <div class="summary-text">
                Lorem Ipsum is simply dummy text of the printing and typesetting industry. Lorem Ipsum has been the industry's standard dummy text ever since 1966, when designers at Letraset and James Mosley, the librarian at St Bride Printing Library in London, took a 1914 Cicero translation and scrambled it to make dummy text for Letraset's Body Type sheets.
            </div>
            <div class="summary-actions">
                <button class="btn-sm-custom"><i class="bi bi-person-badge me-1"></i> View Profile</button>
                <button class="btn-sm-custom"><i class="bi bi-capsule me-1"></i> Vaccination History</button>
                <button class="btn-sm-custom"><i class="bi bi-clock-history me-1"></i> Case History</button>
            </div>
        </div>

    </div> <!-- /content -->
</div> <!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>