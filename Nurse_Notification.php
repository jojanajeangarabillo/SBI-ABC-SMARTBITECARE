<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Nurse - Notifications</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
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
            background: #f3f4f6; 
            font-family: 'Segoe UI', Roboto, system-ui, sans-serif;
            margin: 0;
            padding: 0;
        }

        /* ---- main content ---- */
        .main {
            margin-left: 260px;
            min-height: 100vh;
            background: #f3f4f6;
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

        /* ---- Toolbar (Matching Reference) ---- */
        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .toolbar-left {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .search-box {
            display: flex;
            align-items: center;
            background: white;
            border: 1.5px solid #64748b;
            border-radius: 30px;
            padding: 0 16px;
            height: 42px;
            width: 320px;
            transition: border 0.2s;
        }
        .search-box:focus-within {
            border: 1.5px solid var(--primary);
            box-shadow: 0 0 0 2px rgba(43,58,140,0.1);
        }
        .search-box i {
            color: #94a3b8;
            font-size: 1.1rem;
            margin-right: 8px;
        }
        .search-box input {
            border: none;
            outline: none;
            width: 100%;
            font-size: 14px;
            background: transparent;
            color: #334155;
        }
        .search-box input::placeholder {
            color: #94a3b8;
            font-weight: 500;
        }

        .btn-filter {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0 18px;
            height: 42px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            font-size: 14px;
        }
        .btn-filter i {
            font-size: 0.9rem;
        }

        .branch-text {
            color: #64748b;
            font-size: 14px;
            font-weight: 500;
        }

        .btn-mark-all {
            background: #22c55e; /* Reference Green */
            color: white;
            border: none;
            padding: 0 22px;
            height: 42px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            font-size: 14px;
            transition: background 0.2s;
        }
        .btn-mark-all:hover {
            background: #16a34a;
        }

        /* ---- DATE GROUP HEADER ---- */
        .date-header {
            color: var(--primary);
            font-size: 18px;
            font-weight: 700;
            margin: 24px 0 16px 0;
        }
        .date-header:first-of-type {
            margin-top: 0;
        }

        /* ---- NOTIFICATION CARD (Exact Layout) ---- */
        .notif-item {
            display: flex;
            background: white;
            border-radius: 12px;
            margin-bottom: 18px;
            padding: 18px 20px;
            border: 1px solid #e2e8f0;
            position: relative;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
            transition: box-shadow 0.2s, border-color 0.2s;
        }
        .notif-item:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        /* Left Accent Borders Based on Type */
        .border-followup { border-left: 6px solid #f59e0b; }
        .border-low       { border-left: 6px solid #ef4444; }
        .border-expiring  { border-left: 6px solid #f59e0b; }

        .notif-icon-wrap {
            display: flex;
            align-items: flex-start;
            padding-right: 16px;
        }

        .notif-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%; /* Circle */
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }
        .icon-followup { background: #fef3c7; color: #b45309; }
        .icon-low       { background: #fee2e2; color: #dc2626; }
        .icon-expiring  { background: #fef3c7; color: #b45309; }

        .notif-content {
            flex: 1;
            padding: 2px 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .notif-title {
            font-weight: 700;
            color: #0f172a;
            font-size: 15px;
            margin-bottom: 3px;
        }

        .notif-desc {
            font-size: 14px;
            color: #475569;
            margin-bottom: 4px;
            line-height: 1.4;
        }

        .notif-time {
            font-size: 12px;
            color: #94a3b8;
        }

        .notif-actions {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            justify-content: center;
            padding-left: 16px;
            gap: 8px;
        }

        /* Badge Pill */
        .badge-status-pill {
            padding: 4px 14px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .badge-followup { background: #fef3c7; color: #b45309; }
        .badge-low      { background: #fee2e2; color: #dc2626; }
        .badge-expiring { background: #fef3c7; color: #b45309; }

        /* Action Button */
        .notif-action-btn {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 6px 16px;
            font-size: 13px;
            font-weight: 500;
            transition: background 0.2s;
        }
        .notif-action-btn:hover {
            background: #1d2863;
        }

        /* Mark Read Toggle */
        .mark-read-row {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: #64748b;
            cursor: pointer;
            margin-top: 2px;
        }
        .mark-read-row .circle-icon {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2px solid #cbd5e1;
            display: inline-block;
            transition: border-color 0.2s;
        }
        .mark-read-row:hover .circle-icon {
            border-color: var(--primary);
        }

        /* responsive */
        @media (max-width: 991px) {
            .main { margin-left: 90px; }
            .sidebar { width: 90px; padding: 16px 10px; }
            .system-name, .nav-menu span, .logout span { display: none; }
            .logo-area { justify-content: center; }
            .nav-menu a { justify-content: center; padding: 12px 8px; }
            .nav-menu a i { font-size: 26px; margin: 0; }
            .logout a { justify-content: center; }
            .search-box { width: 100%; max-width: 300px; }
            .toolbar-left { width: 100%; }
            .btn-mark-all { width: 100%; justify-content: center; }
        }

        @media (max-width: 576px) {
            .topbar { padding: 0 16px; height: 70px; }
            .content { padding: 20px 16px; }
            .notif-item { padding: 14px 16px; gap: 12px; }
            .notif-icon { width: 34px; height: 34px; }
            .notif-icon i { font-size: 16px; }
            .notif-title { font-size: 14px; }
            .notif-desc { font-size: 13px; }
            .notif-actions { align-items: flex-start; margin-top: 8px; width: 100%; }
            .notif-actions .badge-status-pill { align-self: flex-start; }
            .notif-item { flex-wrap: wrap; }
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
            <li><a href="Nurse_Vaccination.php"><i class="bi-shield-plus"></i><span>Vaccination</span></a></li>
            <li><a href="Nurse_MedicalSuppliesManagement.php"><i class="bi bi-calendar-check"></i><span>Medical Supplies Management</span></a></li>
            <li><a href="Nurse_SupplyPrediction.php"><i class="bi bi-box-seam"></i><span>Supply Prediction</span></a></li>
            <li><a class="active" href="Nurse_Notification.php"><i class="bi bi-graph-up-arrow"></i><span>Notification</span></a></li>
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
        <h3>Notifications</h3>
        <div class="profile">NURSE <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="content">

        <!-- ========== UPDATED TOOLBAR ========== -->
        <div class="toolbar">
            <div class="toolbar-left">
                <div class="search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" placeholder="Search Notifications...">
                </div>
                <button class="btn-filter">
                    <i class="bi bi-funnel-fill"></i> Filters <i class="bi bi-caret-down-fill" style="font-size: 10px;"></i>
                </button>
                <span class="branch-text">Cainta Branch</span>
            </div>
            <button class="btn-mark-all">
                <i class="bi bi-check-lg"></i> Mark All as Read
            </button>
        </div>

        <!-- ========== GROUP: TODAY ========== -->
        <div class="date-header">Today</div>

        <!-- Follow-Up Due: Imelda Castor -->
        <div class="notif-item border-followup">
            <div class="notif-icon-wrap">
                <div class="notif-icon icon-followup">
                    <i class="bi bi-clock"></i>
                </div>
            </div>
            <div class="notif-content">
                <div class="notif-title">Follow-Up Due</div>
                <div class="notif-desc">Imelda Castor is due for follow-up consultation.</div>
                <div class="notif-time">Today, 08:00 AM</div>
            </div>
            <div class="notif-actions">
                <span class="badge-status-pill badge-followup">Pending</span>
                <button class="notif-action-btn">View Patient</button>
                <div class="mark-read-row">
                    <span class="circle-icon"></span> Mark Read
                </div>
            </div>
        </div>

        <!-- Follow-Up Due: Ariana Garden -->
        <div class="notif-item border-followup">
            <div class="notif-icon-wrap">
                <div class="notif-icon icon-followup">
                    <i class="bi bi-clock"></i>
                </div>
            </div>
            <div class="notif-content">
                <div class="notif-title">Follow-Up Due</div>
                <div class="notif-desc">Ariana Garden is due for wound care follow-up.</div>
                <div class="notif-time">Today, 09:00 AM</div>
            </div>
            <div class="notif-actions">
                <span class="badge-status-pill badge-followup">Pending</span>
                <button class="notif-action-btn">View Patient</button>
                <div class="mark-read-row">
                    <span class="circle-icon"></span> Mark Read
                </div>
            </div>
        </div>

        <!-- ========== GROUP: TOMORROW ========== -->
        <div class="date-header">Tomorrow</div>

        <!-- Follow-Up Due: Mimi Dominico -->
        <div class="notif-item border-followup">
            <div class="notif-icon-wrap">
                <div class="notif-icon icon-followup">
                    <i class="bi bi-clock"></i>
                </div>
            </div>
            <div class="notif-content">
                <div class="notif-title">Follow-Up Due</div>
                <div class="notif-desc">Mimi Dominico is due for vaccination follow-up.</div>
                <div class="notif-time">Tomorrow, 10:00 AM</div>
            </div>
            <div class="notif-actions">
                <span class="badge-status-pill badge-followup">Pending</span>
                <button class="notif-action-btn">View Patient</button>
                <div class="mark-read-row">
                    <span class="circle-icon"></span> Mark Read
                </div>
            </div>
        </div>

        <!-- ========== GROUP: EARLIER ========== -->
        <div class="date-header">May 15, 2025</div>

        <!-- Low Stock Alert: Syringes -->
        <div class="notif-item border-low">
            <div class="notif-icon-wrap">
                <div class="notif-icon icon-low">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
            </div>
            <div class="notif-content">
                <div class="notif-title">Low Stock Alert</div>
                <div class="notif-desc">Syringes (2ml) are running low. Only 15 units remaining.</div>
                <div class="notif-time">May 15, 2025 08:45 AM</div>
            </div>
            <div class="notif-actions">
                <span class="badge-status-pill badge-low">Low Stock</span>
                <button class="notif-action-btn">View Supply</button>
                <div class="mark-read-row">
                    <span class="circle-icon"></span> Mark Read
                </div>
            </div>
        </div>

        <!-- Low Stock Alert: SPEEDA -->
        <div class="notif-item border-low">
            <div class="notif-icon-wrap">
                <div class="notif-icon icon-low">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
            </div>
            <div class="notif-content">
                <div class="notif-title">Low Stock Alert</div>
                <div class="notif-desc">SPEEDA vaccine stock is critically low. Only 8 vials remaining.</div>
                <div class="notif-time">May 15, 2025 07:30 AM</div>
            </div>
            <div class="notif-actions">
                <span class="badge-status-pill badge-low">Low Stock</span>
                <button class="notif-action-btn">View Supply</button>
                <div class="mark-read-row">
                    <span class="circle-icon"></span> Mark Read
                </div>
            </div>
        </div>

        <div class="date-header">May 14, 2025</div>

        <!-- Expiring Vaccine Alert: ERIG -->
        <div class="notif-item border-expiring">
            <div class="notif-icon-wrap">
                <div class="notif-icon icon-expiring">
                    <i class="bi bi-clock-history"></i>
                </div>
            </div>
            <div class="notif-content">
                <div class="notif-title">Expiring Vaccine Alert</div>
                <div class="notif-desc">ERIG vaccine (5 vials) expires on May 20, 2026.</div>
                <div class="notif-time">May 14, 2025 04:15 PM</div>
            </div>
            <div class="notif-actions">
                <span class="badge-status-pill badge-expiring">Expiring</span>
                <button class="notif-action-btn">View Details</button>
                <div class="mark-read-row">
                    <span class="circle-icon"></span> Mark Read
                </div>
            </div>
        </div>

        <!-- Expiring Vaccine Alert: BETT -->
        <div class="notif-item border-expiring">
            <div class="notif-icon-wrap">
                <div class="notif-icon icon-expiring">
                    <i class="bi bi-clock-history"></i>
                </div>
            </div>
            <div class="notif-content">
                <div class="notif-title">Expiring Vaccine Alert</div>
                <div class="notif-desc">BETT vaccine (3 vials) expires on May 20, 2026.</div>
                <div class="notif-time">May 14, 2025 04:10 PM</div>
            </div>
            <div class="notif-actions">
                <span class="badge-status-pill badge-expiring">Expiring</span>
                <button class="notif-action-btn">View Details</button>
                <div class="mark-read-row">
                    <span class="circle-icon"></span> Mark Read
                </div>
            </div>
        </div>

    </div> <!-- /content -->
</div> <!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>