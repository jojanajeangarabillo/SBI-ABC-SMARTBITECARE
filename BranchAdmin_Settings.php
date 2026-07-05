<?php
?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Branch Admin Dashboard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">


    <!--REUSABLE SIDEBAR CSS-->
    <link rel="stylesheet" href="sidebar.css">

    <style>

        /*=========================================
          INTERNAL CSS
        =========================================*/
        :root{

            --primary:#2B3A8C;
            --accent:#F21D2F;
            --bg:#F2F2F2;

        }

        body{

            background:white;

            font-family:'Segoe UI',sans-serif;

        }

        .main{

            margin-left:260px;

            min-height:100vh;

        }

        .topbar{

            background:white;

            height:80px;

            display:flex;

            align-items:center;

            justify-content:space-between;

            padding:0 35px;

            box-shadow:0 2px 8px rgba(0,0,0,.08);

        }

        .topbar h3{

            font-size:28px;

            font-weight:700;

            color:var(--primary);

            margin:0;

        }

        .profile{

            font-weight:600;

            color:var(--primary);

            cursor:pointer;

        }


        @media(max-width:991px){

            .main{

                margin-left:90px;

            }

        }

        .bi-pencil-square{
            margin-right: 12px;
    }
        .bi-trash3-fill{
            margin-left: 12px;
    }

        /* =========================================
           NEW MAIN CONTENT STYLES
           ========================================= */
    .content-wrapper {
            padding: 28px 35px 40px 35px;
        }

    .page-header{
        display:flex;
        justify-content:space-between;
        align-items:center;
        margin-bottom:25px;
    }

    .header-left{
        display:flex;
        align-items:center;
        gap:15px;
    }

/*=========================================
 SETTINGS PAGE
=========================================*/

    .settings-card{

        background:#fff;

        border:1px solid #e6e9f2;

        border-radius:15px;

        padding:25px;

        margin-bottom:25px;

        box-shadow:0 3px 10px rgba(0,0,0,.05);

    }

    .settings-header{

        display:flex;

        align-items:flex-start;

        justify-content:space-between;

        gap:20px;

    }

    .settings-header .settings-info{

        flex:1;

    }

    .settings-info h5{

        font-weight:700;

        color:#1e293b;

        margin-bottom:6px;

    }

    .settings-info p{

        color:#6b7280;

        margin:0;

    }

    .settings-icon{

        width:58px;

        height:58px;

        border-radius:50%;

        display:flex;

        align-items:center;

        justify-content:center;

        font-size:26px;

    }

    .settings-icon.blue{

        background:#eef3ff;

        color:#2B3A8C;

    }

    .settings-icon.green{

        background:#ecfff4;

        color:#18a558;

    }

    .settings-icon.orange{

        background:#fff4e7;

        color:#ff8c00;

    }

    .btn-settings{

        border:2px solid #d7dff8;

        background:#fff;

        color:#2B3A8C;

        font-weight:600;

        padding:10px 18px;

        border-radius:8px;

    }

    .btn-settings:hover{

        background:#2B3A8C;

        color:#fff;

        border-color:#2B3A8C;

    }

    .settings-details{

        margin-top:25px;

        display:grid;

        grid-template-columns:repeat(2,1fr);

        border:1px solid #edf0f7;

        border-radius:10px;

        overflow:hidden;

    }

    .settings-details.three-col{

        grid-template-columns:repeat(3,1fr);

    }

    .settings-details div{

        padding:18px 22px;

        border-right:1px solid #edf0f7;

    }

    .settings-details div:last-child{

        border-right:none;

    }

    .settings-details small{

        color:#6b7280;

        font-weight:600;

    }

    .settings-details h6{

        margin-top:8px;

        font-weight:600;

        color:#1e293b;

    }

    .info-banner{

        background:#eef4ff;

        border:1px solid #d7e3ff;

        color:#2B3A8C;

        padding:18px 22px;

        border-radius:12px;

        font-weight:600;

    }

    .info-banner i{

        margin-right:10px;

    }

    @media(max-width:768px){

    .settings-header{

        flex-direction:column;

    }

    .btn-settings{

        width:100%;

    }

    .settings-details,

    .settings-details.three-col{

        grid-template-columns:1fr;

    }

    .settings-details div{

        border-right:none;

        border-bottom:1px solid #edf0f7;

    }

    .settings-details div:last-child{

        border-bottom:none;

    }

    }


    /* responsive tweaks */
 @media (max-width: 768px) {
        .content-wrapper {
            padding: 18px 16px 30px 16px;
        }

        }

    /* topbar profile dropdown */
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

    </style>

</head>


<body>
    <!-- SIDEBAR LOGO-->

    <div class="sidebar">

        <div class="logo-area">

            <div class="logo-frame">
                <img src="logo.png" alt="Smart Bite Care Logo" class="logo">
            </div>

            <div class="system-name">
                Smart Bite Care
            </div>

        </div>

        <!-- SIDEBAR NAVIGATION -->

        <nav class="nav-menu">

            <ul>

                <li><a href="BranchAdmin_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>

                <li><a href="BranchAdmin_UserManagement.php"><i class="bi bi-people-fill"></i><span>User Management</span></a></li>

                <li><a href="BranchAdmin_PatientMonitoring.php"><i class="bi bi-heart-pulse-fill"></i><span>Patient Monitoring</span></a></li>

                <li><a href="BranchAdmin_MedicalSupplies.php"><i class="bi bi-box-seam"></i><span>Medical Supplies</span></a></li>

                <li><a href="BranchAdmin_PredictionModule.php"><i class="bi bi-graph-up-arrow"></i><span>Prediction Module</span></a></li>

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
            <div class="profile"> ADMIN <i class="bi bi-caret-down-fill"></i> </div>
        </div>

        <div class="content-wrapper">

    <!-- Homepage Content -->
        <div class="settings-card">

            <div class="settings-header">
                <div class="settings-icon blue">
                    <i class="bi bi-pencil-square"></i>
                </div>

                <div class="settings-info">
                    <h5>Edit Homepage Content</h5>
                    <p>Update text, images, and other content shown on the homepage.</p>
                </div>

                <button class="btn btn-settings">
                    <i class="bi bi-pencil-square"></i>
                    Edit Homepage
                </button>
            </div>

            <div class="settings-details">
                <div>
                    <small>Last Updated</small>
                    <h6>May 27, 2025 • 10:15 AM</h6>
                </div>

                <div>
                    <small>Updated By</small>
                    <h6>Admin</h6>
                </div>
            </div>

        </div>

        <!-- Contact Information -->
        <div class="settings-card">

            <div class="settings-header">
                <div class="settings-icon green">
                    <i class="bi bi-telephone"></i>
                </div>

                <div class="settings-info">
                    <h5>Update Contact Information</h5>
                    <p>Manage contact details displayed to the public.</p>
                </div>

                <button class="btn btn-settings">
                    <i class="bi bi-telephone"></i>
                    Update Contact Info
                </button>
            </div>

            <div class="settings-details three-col">

                <div>
                    <small>Phone Number</small>
                    <h6>(+63) 912 345 6789</h6>
                </div>

                <div>
                    <small>Email Address</small>
                    <h6>sbicaintaabc@gmail.com</h6>
                </div>

                <div>
                    <small>Last Updated</small>
                    <h6>May 25, 2025 • 02:45 PM</h6>
                </div>

            </div>

        </div>

        <!-- Branch Information -->
        <div class="settings-card">

            <div class="settings-header">
                <div class="settings-icon orange">
                    <i class="bi bi-building"></i>
                </div>

                <div class="settings-info">
                    <h5>Update Branch Information</h5>
                    <p>Update the branch details and address information.</p>
                </div>

                <button class="btn btn-settings">
                    <i class="bi bi-building"></i>
                    Update Branch Info
                </button>
            </div>

            <div class="settings-details three-col">

                <div>
                    <small>Branch Name</small>
                    <h6>SBI ABC- Cainta Branch</h6>
                </div>

                <div>
                    <small>Address</small>
                    <h6>05 Parola St. Brgy. San Roque Cainta Rizal</h6>
                </div>

                <div>
                    <small>Last Updated</small>
                    <h6>May 20, 2026 • 11:30 AM</h6>
                </div>

            </div>

        </div>

        <!-- Information -->
        <div class="info-banner">
            <i class="bi bi-info-circle-fill"></i>
            Changes made here will be reflected on the public landing page.
        </div>

    </div>

    
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
    </script>

</body>
</html>