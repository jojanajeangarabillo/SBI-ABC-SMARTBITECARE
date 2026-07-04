<?php
// No PHP logic needed for this static page
?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Admin Staff Patient Record Management</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!--REUSABLE SIDEBAR CSS-->
    <link rel="stylesheet" href="sidebar.css">

    <style>
        
        /*=========================================
          INTERNAL CSS
        =========================================*/
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

        }


        @media (max-width: 991px) {

            .main {

                margin-left: 90px;

            }

        }

        .bi-pencil-square {
            margin-right: 12px;
        }

        .bi-trash3-fill {
            margin-left: 12px;
        }

        /* Toolbar */
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
            margin-top: 30px;
        }

        .search-area {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .search-area input {
            width: 320px;
            padding: 10px 15px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 14px;
            background: #fff;
        }

        .btn {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn:hover {
            background: #1f2d6b;
        }

        /* Three‑column grid */
        .record-container {
            display: grid;
            grid-template-columns: 220px 1fr 280px;
            gap: 20px;
        }

        @media (max-width: 1200px) {
            .record-container {
                grid-template-columns: 200px 1fr 250px;
                gap: 15px;
            }
        }

        @media (max-width: 992px) {
            .record-container {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }

        /* Left panel – Dates */
        .date-panel {
            background: #fff;
            border: 1px solid #cfcfcf;
            border-radius: 8px;
            overflow: hidden;
            height: fit-content;
        }

        .date-header {
            padding: 15px;
            border-bottom: 1px solid #ddd;
            font-weight: 700;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fafafa;
        }

        .date-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: 0.15s;
            font-size: 14px;
        }

        .date-item:hover {
            background: #f0f3ff;
        }

        .date-item.active {
            background: #e8efff;
            color: var(--primary);
            font-weight: 600;
        }

        .date-item .badge {
            background: #eef1ff;
            color: var(--primary);
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .date-item .today-tag {
            background: var(--accent);
            color: #fff;
            padding: 1px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            margin-left: 6px;
        }

        /* Center panel – Table */
        .table-panel {
            background: #fff;
            border: 1px solid #cfcfcf;
            border-radius: 8px;
            overflow: hidden;
        }

        .tabs {
            display: flex;
            align-items: center;
            border-bottom: 1px solid #ddd;
            background: #fafafa;
        }

        .tab {
            flex: 1;
            padding: 14px 16px;
            font-weight: 600;
            text-align: center;
            border-right: 1px solid #ddd;
            cursor: pointer;
            transition: 0.15s;
            font-size: 14px;
        }

        .tab:hover {
            background: #eef2ff;
        }

        .tab.active {
            background: #fff;
            color: var(--primary);
            border-bottom: 3px solid var(--primary);
        }

        .export-btn {
            padding: 8px 18px;
            background: var(--primary);
            color: #fff;
            border-radius: 6px;
            margin: 6px 12px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
        }

        .export-btn:hover {
            background: #1f2d6b;
        }

        /* Table */
        .table-responsive-custom {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        thead {
            background: var(--primary);
            color: white;
        }

        th {
            padding: 12px 14px;
            text-align: left;
            font-weight: 600;
            white-space: nowrap;
        }

        td {
            padding: 10px 14px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        tbody tr:hover {
            background: #f8f9fc;
        }

        .status-yes {
            color: #0d9c38;
            font-weight: 600;
        }

        .action-icons {
            display: flex;
            gap: 12px;
            font-size: 17px;
            color: var(--primary);
        }

        .action-icons i {
            cursor: pointer;
            transition: 0.2s;
        }

        .action-icons i:hover {
            color: var(--accent);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 6px;
            padding: 16px 0 4px;
        }

        .page {
            width: 30px;
            height: 30px;
            border-radius: 6px;
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            font-weight: 600;
            transition: 0.15s;
            border: 1px solid transparent;
        }

        .page:hover {
            background: #eef2ff;
        }

        .page.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Right panel – Patient Details */
        .details {
            background: #fff;
            border: 1px solid #cfcfcf;
            border-radius: 8px;
            overflow: hidden;
            height: fit-content;
        }

        .details-title {
            padding: 14px 16px;
            border-bottom: 1px solid #ddd;
            font-weight: 700;
            font-size: 16px;
            background: #fafafa;
        }

        .patient {
            padding: 16px;
            display: flex;
            gap: 14px;
            align-items: center;
            border-bottom: 1px solid #ddd;
        }

        .avatar {
            width: 48px;
            height: 48px;
            border: 2px solid #ccc;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 28px;
            color: #999;
            background: #f5f5f5;
        }

        .patient-name {
            font-weight: 700;
            font-size: 16px;
            color: var(--primary);
        }

        .case-no {
            font-size: 13px;
            color: #6c757d;
        }

        .info {
            padding: 16px;
            font-size: 14px;
            line-height: 2;
            border-bottom: 1px solid #ddd;
        }

        .info strong {
            color: var(--primary);
        }

        .status-row {
            padding: 14px 16px;
            border-bottom: 1px solid #ddd;
            font-size: 14px;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 13px;
        }

        .status-badge.completed {
            background: #8be28d;
            color: #0d5d19;
        }

        .status-badge.pending {
            background: var(--primary);
            color: white;
        }

        .vaccine {
            padding: 16px;
            font-size: 14px;
        }

        .vaccine-title {
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 8px;
        }

        .dose {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .dose:last-child {
            border-bottom: none;
        }

        .detail-buttons {
            padding: 16px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .detail-buttons button {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 6px;
            background: var(--primary);
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-size: 14px;
        }

        .detail-buttons button:hover {
            background: #1f2d6b;
        }

        .detail-buttons button.outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        .detail-buttons button.outline:hover {
            background: var(--primary);
            color: white;
        }

        /* Responsive tweaks */
        @media (max-width: 768px) {
            .search-area input {
                width: 100%;
            }
            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            .tabs {
                flex-wrap: wrap;
            }
            .tab {
                flex: 1 0 50%;
                border-right: none;
                border-bottom: 1px solid #ddd;
            }
            .export-btn {
                margin: 6px auto;
            }
            th, td {
                padding: 6px 10px;
                font-size: 12px;
            }
            .action-icons {
                font-size: 15px;
                gap: 8px;
            }
        }

        @media (max-width: 480px) {
            .tab {
                flex: 1 0 100%;
            }
        }
    </style>

</head>


<body>
    <!-- SIDEBAR  -->
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
                <li><a href="AdminStaff_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
                <li><a href="AdminStaff_Calendar.php"><i class="bi bi-calendar-fill"></i><span>Calendar</span></a></li>
                <li><a class="active" href="AdminStaff_PatientRecord.php"><i class="bi bi-people-fill"></i><span>Patient Record Management</span></a></li>
                <li><a href="AdminStaff_PhilhealthStatus.php"><i class="bi bi-check2-all"></i><span>PhilHealth Patient Status</span></a></li>
                <li><a href="AdminStaff_MedicalDocuments.php"><i class="bi bi-file-earmark-ruled"></i><span>Medical Documents</span></a></li>
                <li><a href="AdminStaff_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
            </ul>
        </nav>

        <div class="logout">
            <a href="#"> <i class="bi bi-box-arrow-right"></i>
                <span>Logout</span>
            </a>
        </div>

    </div>

    <!-- MAIN CONTENT -->
    <div class="main">

        <!-- Top Header -->
        <div class="topbar">
            <h3>Patient Record Management</h3>

            <div class="profile">
                ADMIN STAFF
                <i class="bi bi-caret-down-fill"></i>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="toolbar">
            <div class="search-area">
                <input type="text" placeholder="Search Patient, Case No., Status, etc.">
                <button class="btn">
                    <i class="bi bi-funnel"></i> Filters
                </button>
            </div>
            <button class="btn">
                <i class="bi bi-plus-circle"></i> Add New Patient
            </button>
        </div>

        <!-- Three‑column layout -->
        <div class="record-container">

            <!-- LEFT: Admission Dates -->
            <div class="date-panel">
                <div class="date-header">
                    Admission Dates
                    <i class="bi bi-calendar3"></i>
                </div>

                <div class="date-item active">
                    May 14, 2026 <span class="today-tag">Today</span>
                    <span class="badge">12</span>
                </div>
                <div class="date-item">
                    May 13, 2026
                    <span class="badge">18</span>
                </div>
                <div class="date-item">
                    May 12, 2026
                    <span class="badge">14</span>
                </div>
                <div class="date-item">
                    May 11, 2026
                    <span class="badge">9</span>
                </div>
                <div class="date-item">
                    May 10, 2026
                    <span class="badge">11</span>
                </div>
                <div class="date-item">
                    May 9, 2026
                    <span class="badge">15</span>
                </div>
                <div class="date-item">
                    May 8, 2026
                    <span class="badge">13</span>
                </div>
            </div>

            <!-- CENTER: Table -->
            <div>

                <div class="table-panel">

                    <div class="tabs">
                        <div class="tab active">New Patients for May 14, 2026 (12)</div>
                        <div class="tab">Follow Up Patients for May 14, 2026 (15)</div>
                        <button class="export-btn"><i class="bi bi-download"></i> Export</button>
                    </div>

                    <div class="table-responsive-custom">
                        <table>
                            <thead>
                                <tr>
                                    <th>PhilHealth</th>
                                    <th>Case No.</th>
                                    <th>Patient Name</th>
                                    <th>Age</th>
                                    <th>Gender</th>
                                    <th>Date of Bite</th>
                                    <th>Site of Bite</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="status-yes">Yes</td>
                                    <td>26-0001</td>
                                    <td>Dela Cruz, Juan</td>
                                    <td>25</td>
                                    <td>M</td>
                                    <td>June 29, 2026</td>
                                    <td>Left Thigh</td>
                                    <td>
                                        <div class="action-icons">
                                            <i class="bi bi-eye"></i>
                                            <i class="bi bi-pencil"></i>
                                            <i class="bi bi-trash"></i>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="status-yes">Yes</td>
                                    <td>26-0002</td>
                                    <td>Santos, Maria</td>
                                    <td>30</td>
                                    <td>F</td>
                                    <td>June 28, 2026</td>
                                    <td>Right Arm</td>
                                    <td>
                                        <div class="action-icons">
                                            <i class="bi bi-eye"></i>
                                            <i class="bi bi-pencil"></i>
                                            <i class="bi bi-trash"></i>
                                        </div>
                                    </td>
                                </tr>
                                <!-- Additional rows can be added here -->
                                <tr><td colspan="8" style="height:20px;"></td></tr>
                                <tr><td colspan="8" style="height:20px;"></td></tr>
                                <tr><td colspan="8" style="height:20px;"></td></tr>
                                <tr><td colspan="8" style="height:20px;"></td></tr>
                                <tr><td colspan="8" style="height:20px;"></td></tr>
                                <tr><td colspan="8" style="height:20px;"></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="pagination">
                        <div class="page active">1</div>
                        <div class="page">2</div>
                        <div class="page">3</div>
                        <div class="page">4</div>
                        <div class="page">5</div>
                        <div class="page">6</div>
                        <div class="page">7</div>
                        <div class="page">8</div>
                    </div>

                </div>

            </div>

            <!-- RIGHT: Patient Details -->
            <div class="details">

                <div class="details-title">
                    Patient Details
                </div>

                <div class="patient">
                    <div class="avatar">
                        <i class="bi bi-person"></i>
                    </div>
                    <div>
                        <div class="patient-name">Dela Cruz, Juan Miguel</div>
                        <div class="case-no">Case No: 26-0001</div>
                    </div>
                </div>

                <div class="info">
                    <strong>Age/Gender:</strong> 25 / M<br>
                    <strong>Contact Number:</strong> 099938468136<br>
                    <strong>Date of Bite:</strong> June 29, 2026<br>
                    <strong>Site of Bite:</strong> Left Thigh<br>
                    <strong>Category:</strong> 3<br>
                    <strong>Diagnosis:</strong> Abrasion wound with bleeding on Left Thigh
                </div>

                <div class="status-row">
                    PhilHealth Status:
                    <span class="status-badge completed">Completed</span>
                </div>

                <div class="vaccine">
                    <div class="vaccine-title">Vaccination Details</div>
                    <div class="dose">
                        Day 0: July 1, 2026
                        <span class="status-badge completed">Completed</span>
                    </div>
                    <div class="dose">
                        Day 3: July 4, 2026
                        <span class="status-badge pending">Pending</span>
                    </div>
                    <div class="dose">
                        Day 7: July 8, 2026
                        <span class="status-badge pending">Pending</span>
                    </div>
                    <div class="dose">
                        Day 30: July 30, 2026
                        <span class="status-badge pending">Pending</span>
                    </div>
                </div>

                <div class="detail-buttons">
                    <button><i class="bi bi-pencil"></i> Update Record</button>
                    <button class="outline"><i class="bi bi-file-earmark-text"></i> View Case History</button>
                </div>

            </div>

        </div><!-- /record-container -->

    </div><!-- /main -->


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
    </script>

</body>
</html>