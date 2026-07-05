<?php
session_start();
require_once 'sources/db_connect.php';

// ============================================
// AUDIT LOG FUNCTION
// ============================================
function addAuditLog($conn, $user_id, $action, $module = 'Reports') {
    // Get user's branch_id
    $branch_id = null;
    $user_sql = "SELECT branch_id FROM users WHERE user_id = ?";
    $user_stmt = $conn->prepare($user_sql);
    if ($user_stmt) {
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_row = $user_result->fetch_assoc()) {
            $branch_id = $user_row['branch_id'];
        }
        $user_stmt->close();
    }
    
    // Insert audit log
    $log_sql = "INSERT INTO audit_logs (user_id, branch_id, action, module) VALUES (?, ?, ?, ?)";
    $log_stmt = $conn->prepare($log_sql);
    if ($log_stmt) {
        $log_stmt->bind_param("isss", $user_id, $branch_id, $action, $module);
        $result = $log_stmt->execute();
        $log_stmt->close();
        return $result;
    }
    return false;
}

// Check if user is logged in and is super admin
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 1
) {
    header("Location: login.php");
    exit();
}

// ============================================
// FPDF CLASS (Embedded)
// ============================================
require_once('fpdf/fpdf.php');

class PDF extends FPDF
{
    private $title;
    private $subtitle;
    private $branchName;
    private $dateRange;

    function __construct($title, $subtitle = '', $branchName = '', $dateRange = '')
    {
        parent::__construct('L', 'mm', 'A4');
        $this->title = $title;
        $this->subtitle = $subtitle;
        $this->branchName = $branchName;
        $this->dateRange = $dateRange;
        $this->SetMargins(15, 15, 15);
        $this->SetAutoPageBreak(true, 20);
    }

    function Header()
    {
        $this->SetY(10);
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(43, 58, 140);
        $this->Cell(0, 10, 'Smart Bite Care - Report', 0, 1, 'C');
        
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 10, $this->title, 0, 1, 'C');
        
        if ($this->subtitle) {
            $this->SetFont('Arial', '', 11);
            $this->SetTextColor(80, 80, 80);
            $this->Cell(0, 7, $this->subtitle, 0, 1, 'C');
        }
        
        if ($this->branchName) {
            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(80, 80, 80);
            $this->Cell(0, 6, 'Branch: ' . $this->branchName, 0, 1, 'C');
        }
        
        if ($this->dateRange) {
            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(80, 80, 80);
            $this->Cell(0, 6, 'Date Range: ' . $this->dateRange, 0, 1, 'C');
        }
        
        $this->Ln(5);
        $this->SetDrawColor(43, 58, 140);
        $this->Line(15, $this->GetY(), 275, $this->GetY());
        $this->Ln(5);
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(128, 128, 128);
        $this->Cell(0, 10, 'Generated on: ' . date('Y-m-d H:i:s') . ' | Page ' . $this->PageNo(), 0, 0, 'C');
    }

    function CreateTable($headers, $data, $columnWidths = null, $fontSize = 10)
    {
        if ($columnWidths === null) {
            $columnWidths = array_fill(0, count($headers), 40);
        }
        
        $this->SetFont('Arial', 'B', $fontSize);
        $this->SetFillColor(43, 58, 140);
        $this->SetTextColor(255, 255, 255);
        
        foreach ($headers as $i => $header) {
            $this->Cell($columnWidths[$i], 10, $header, 1, 0, 'C', true);
        }
        $this->Ln();
        
        $this->SetFont('Arial', '', $fontSize - 1);
        $this->SetTextColor(0, 0, 0);
        $fill = false;
        
        foreach ($data as $row) {
            foreach ($row as $i => $cell) {
                $this->Cell($columnWidths[$i], 8, $cell, 1, 0, 'L', $fill);
            }
            $this->Ln();
            $fill = !$fill;
        }
    }

    function CreateStatsBox($stats)
    {
        $this->SetFont('Arial', 'B', 11);
        $this->SetFillColor(236, 238, 247);
        $this->SetTextColor(43, 58, 140);
        
        $x = $this->GetX();
        $y = $this->GetY();
        $boxWidth = 260;
        
        $this->Rect($x, $y, $boxWidth, 30);
        $this->SetXY($x + 5, $y + 5);
        
        $count = 0;
        foreach ($stats as $key => $value) {
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(50, 8, $key . ':', 0, 0);
            $this->SetFont('Arial', '', 10);
            $this->Cell(60, 8, $value, 0, 0, 'L');
            $count++;
            if ($count % 2 == 0) {
                $this->SetXY($x + 5 + ($count/2) * 130, $y + 5);
            }
        }
        
        $this->SetY($y + 32);
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function getBranchName($branchId) {
    if (!$branchId) return 'All Branches';
    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE branch_id = ?");
    $stmt->execute([$branchId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['branch_name'] : 'Unknown Branch';
}

function getBranches() {
    $pdo = getConnection();
    $stmt = $pdo->query("SELECT branch_id, branch_name FROM branches WHERE status = 'Active' ORDER BY branch_name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// DATABASE CONNECTION (Direct Integration)
// ============================================
function getConnection() {
    $host = 'localhost';
    $dbname = 'smartbitecare';
    $username = 'root';
    $password = '';
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch(PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
}

// ============================================
// REPORT GENERATION FUNCTIONS
// ============================================
function generateUserReport($branchId = null, $startDate = null, $endDate = null) {
    $pdo = getConnection();
    $params = [];
    
    $query = "SELECT u.user_id, u.username, u.email, u.status, u.last_login, u.created_at, 
              r.role_name, b.branch_name 
              FROM users u 
              LEFT JOIN roles r ON u.role_id = r.role_id 
              LEFT JOIN branches b ON u.branch_id = b.branch_id 
              WHERE u.status != 'Deleted'";
    
    if ($branchId) {
        $query .= " AND u.branch_id = ?";
        $params[] = $branchId;
    }
    
    if ($startDate && $endDate) {
        $query .= " AND DATE(u.created_at) BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
    }
    
    $query .= " ORDER BY u.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $pdf = new PDF('User Report', 'Detailed user information report', getBranchName($branchId), 
                   ($startDate && $endDate) ? $startDate . ' to ' . $endDate : 'All Time');
    $pdf->AddPage();
    
    $activeCount = array_reduce($users, function($carry, $user) {
        return $carry + ($user['status'] == 'Active' ? 1 : 0);
    }, 0);
    
    $pdf->CreateStatsBox([
        'Total Users' => count($users),
        'Active Users' => $activeCount,
        'Inactive Users' => count($users) - $activeCount,
        'Generated' => date('Y-m-d H:i')
    ]);
    
    $pdf->Ln(10);
    
    $headers = ['ID', 'Username', 'Email', 'Role', 'Branch', 'Status', 'Created'];
    $columnWidths = [18, 35, 50, 30, 40, 25, 32];
    
    $data = array_map(function($user) {
        return [
            $user['user_id'],
            $user['username'],
            substr($user['email'], 0, 25) . (strlen($user['email']) > 25 ? '...' : ''),
            $user['role_name'] ?: 'N/A',
            $user['branch_name'] ?: 'N/A',
            $user['status'],
            date('Y-m-d', strtotime($user['created_at']))
        ];
    }, $users);
    
    $pdf->CreateTable($headers, $data, $columnWidths, 9);
    
    return $pdf;
}

function generateBranchAdminReport($branchId = null, $startDate = null, $endDate = null) {
    $pdo = getConnection();
    $params = [];
    
    $query = "SELECT u.user_id, u.username, u.email, u.status, u.last_login, u.created_at,
              b.branch_name, b.branch_address, b.contact_number 
              FROM users u 
              INNER JOIN roles r ON u.role_id = r.role_id 
              INNER JOIN branches b ON u.branch_id = b.branch_id 
              WHERE r.role_name = 'Branch Admin' AND u.status != 'Deleted'";
    
    if ($branchId) {
        $query .= " AND u.branch_id = ?";
        $params[] = $branchId;
    }
    
    if ($startDate && $endDate) {
        $query .= " AND DATE(u.created_at) BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
    }
    
    $query .= " ORDER BY u.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $pdf = new PDF('Branch Admin Report', 'Detailed branch administrator information', getBranchName($branchId),
                   ($startDate && $endDate) ? $startDate . ' to ' . $endDate : 'All Time');
    $pdf->AddPage();
    
    $activeCount = array_reduce($admins, function($carry, $admin) {
        return $carry + ($admin['status'] == 'Active' ? 1 : 0);
    }, 0);
    
    $uniqueBranches = count(array_unique(array_column($admins, 'branch_name')));
    
    $pdf->CreateStatsBox([
        'Total Admins' => count($admins),
        'Active Admins' => $activeCount,
        'Branches' => $uniqueBranches,
        'Generated' => date('Y-m-d H:i')
    ]);
    
    $pdf->Ln(10);
    
    $headers = ['ID', 'Username', 'Email', 'Branch', 'Contact', 'Status'];
    $columnWidths = [18, 35, 50, 40, 35, 22];
    
    $data = array_map(function($admin) {
        return [
            $admin['user_id'],
            $admin['username'],
            substr($admin['email'], 0, 25) . (strlen($admin['email']) > 25 ? '...' : ''),
            $admin['branch_name'],
            $admin['contact_number'] ?: 'N/A',
            $admin['status']
        ];
    }, $admins);
    
    $pdf->CreateTable($headers, $data, $columnWidths, 9);
    
    return $pdf;
}

function generateAuditLogsReport($branchId = null, $startDate = null, $endDate = null) {
    $pdo = getConnection();
    $params = [];
    
    $query = "SELECT al.log_id, al.action, al.module, al.created_at,
              u.username, u.email, b.branch_name 
              FROM audit_logs al 
              LEFT JOIN users u ON al.user_id = u.user_id 
              LEFT JOIN branches b ON al.branch_id = b.branch_id 
              WHERE 1=1";
    
    if ($branchId) {
        $query .= " AND al.branch_id = ?";
        $params[] = $branchId;
    }
    
    if ($startDate && $endDate) {
        $query .= " AND DATE(al.created_at) BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
    }
    
    $query .= " ORDER BY al.created_at DESC LIMIT 1000";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $pdf = new PDF('Audit Logs Report', 'System activity audit trail', getBranchName($branchId),
                   ($startDate && $endDate) ? $startDate . ' to ' . $endDate : 'All Time');
    $pdf->AddPage();
    
    $moduleCount = array_count_values(array_column($logs, 'module'));
    arsort($moduleCount);
    $topModules = array_slice($moduleCount, 0, 3);
    $topModulesStr = implode(', ', array_map(function($k, $v) { return "$k ($v)"; }, 
                                             array_keys($topModules), $topModules));
    
    $pdf->CreateStatsBox([
        'Total Logs' => count($logs),
        'Modules Used' => count($moduleCount),
        'Top Modules' => $topModulesStr ?: 'None',
        'Generated' => date('Y-m-d H:i')
    ]);
    
    $pdf->Ln(10);
    
    $headers = ['ID', 'User', 'Module', 'Action', 'Branch', 'Date/Time'];
    $columnWidths = [18, 30, 35, 65, 35, 35];
    
    $data = array_map(function($log) {
        return [
            $log['log_id'],
            $log['username'] ?: 'System',
            $log['module'] ?: 'N/A',
            substr($log['action'], 0, 45) . (strlen($log['action']) > 45 ? '...' : ''),
            $log['branch_name'] ?: 'N/A',
            date('Y-m-d H:i', strtotime($log['created_at']))
        ];
    }, $logs);
    
    $pdf->CreateTable($headers, $data, $columnWidths, 8);
    
    return $pdf;
}

function generateBranchPerformanceReport($branchId = null, $startDate = null, $endDate = null) {
    $pdo = getConnection();
    $params = [];
    
    $query = "SELECT b.branch_id, b.branch_name, b.branch_address, b.contact_number,
              COUNT(DISTINCT abc.case_id) as total_cases,
              COUNT(DISTINCT p.patient_id) as total_patients,
              COUNT(DISTINCT vr.vaccination_id) as total_vaccinations,
              COUNT(DISTINCT u.user_id) as total_staff,
              COUNT(DISTINCT al.log_id) as total_activities
              FROM branches b 
              LEFT JOIN animal_bite_cases abc ON b.branch_id = abc.branch_id 
              LEFT JOIN patients p ON abc.patient_id = p.patient_id 
              LEFT JOIN vaccination_records vr ON b.branch_id = vr.branch_id 
              LEFT JOIN users u ON b.branch_id = u.branch_id 
              LEFT JOIN audit_logs al ON b.branch_id = al.branch_id 
              WHERE 1=1";
    
    if ($branchId) {
        $query .= " AND b.branch_id = ?";
        $params[] = $branchId;
    }
    
    if ($startDate && $endDate) {
        $query .= " AND DATE(b.created_at) BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
    }
    
    $query .= " GROUP BY b.branch_id, b.branch_name, b.branch_address, b.contact_number 
                ORDER BY total_cases DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $pdf = new PDF('Branch Performance Report', 'Key performance metrics by branch', 
                   $branchId ? getBranchName($branchId) : 'All Branches',
                   ($startDate && $endDate) ? $startDate . ' to ' . $endDate : 'All Time');
    $pdf->AddPage();
    
    $totalCases = array_sum(array_column($branches, 'total_cases'));
    $totalPatients = array_sum(array_column($branches, 'total_patients'));
    $totalVaccinations = array_sum(array_column($branches, 'total_vaccinations'));
    
    $pdf->CreateStatsBox([
        'Total Cases' => $totalCases,
        'Total Patients' => $totalPatients,
        'Total Vaccinations' => $totalVaccinations,
        'Active Branches' => count($branches)
    ]);
    
    $pdf->Ln(10);
    
    $headers = ['Branch', 'Cases', 'Patients', 'Vaccinations', 'Staff', 'Activities'];
    $columnWidths = [45, 30, 30, 35, 30, 35];
    
    $data = array_map(function($branch) {
        return [
            $branch['branch_name'],
            $branch['total_cases'] ?: 0,
            $branch['total_patients'] ?: 0,
            $branch['total_vaccinations'] ?: 0,
            $branch['total_staff'] ?: 0,
            $branch['total_activities'] ?: 0
        ];
    }, $branches);
    
    $pdf->CreateTable($headers, $data, $columnWidths, 10);
    
    if (count($branches) > 0) {
        $pdf->Ln(10);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, 'Performance Summary', 0, 1, 'C');
        
        $pdf->SetFont('Arial', '', 10);
        $bestBranch = $branches[0];
        $pdf->Cell(0, 8, 'Top Performing Branch: ' . $bestBranch['branch_name'] . 
                   ' with ' . ($bestBranch['total_cases'] ?: 0) . ' cases', 0, 1, 'L');
        
        if (count($branches) > 1) {
            $worstBranch = end($branches);
            $pdf->Cell(0, 8, 'Branch needing improvement: ' . $worstBranch['branch_name'] . 
                       ' with ' . ($worstBranch['total_cases'] ?: 0) . ' cases', 0, 1, 'L');
        }
    }
    
    return $pdf;
}

// ============================================
// HANDLE REPORT GENERATION REQUEST
// ============================================
if (isset($_GET['generate_report']) && isset($_GET['report_type'])) {
    $reportType = $_GET['report_type'];
    $branchId = isset($_GET['branch_id']) && $_GET['branch_id'] ? $_GET['branch_id'] : null;
    $startDate = isset($_GET['start_date']) && $_GET['start_date'] ? $_GET['start_date'] : null;
    $endDate = isset($_GET['end_date']) && $_GET['end_date'] ? $_GET['end_date'] : null;
    
    // Log the report generation
    $branchName = $branchId ? getBranchName($branchId) : 'All Branches';
    $dateRange = ($startDate && $endDate) ? "$startDate to $endDate" : 'All Time';
    $actionDetail = "Generated $reportType report - Branch: $branchName, Date Range: $dateRange";
    addAuditLog($conn, $_SESSION['user_id'], $actionDetail, 'Reports');
    
    $pdf = null;
    $filename = '';
    
    switch ($reportType) {
        case 'user':
            $pdf = generateUserReport($branchId, $startDate, $endDate);
            $filename = 'User_Report_' . date('Y-m-d') . '.pdf';
            break;
        case 'branch_admin':
            $pdf = generateBranchAdminReport($branchId, $startDate, $endDate);
            $filename = 'Branch_Admin_Report_' . date('Y-m-d') . '.pdf';
            break;
        case 'audit_logs':
            $pdf = generateAuditLogsReport($branchId, $startDate, $endDate);
            $filename = 'Audit_Logs_Report_' . date('Y-m-d') . '.pdf';
            break;
        case 'branch_performance':
            $pdf = generateBranchPerformanceReport($branchId, $startDate, $endDate);
            $filename = 'Branch_Performance_Report_' . date('Y-m-d') . '.pdf';
            break;
        default:
            die('Invalid report type');
    }
    
    if ($pdf) {
        // Force download to user's device
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        header('Content-Transfer-Encoding: binary');
        
        $pdf->Output('D', $filename);
        exit;
    }
}

// ============================================
// GET BRANCHES FOR DROPDOWN
// ============================================
$branches = getBranches();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Super Admin - Reports</title>
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

        .report-card {
            background: white;
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            padding: 28px 30px 30px;
            margin-bottom: 28px;
        }
        .report-card .form-select-custom {
            border: 1px solid #d0d7e8;
            border-radius: 10px;
            padding: 10px 16px;
            font-weight: 500;
            color: #1f2a4a;
            background: white;
            width: 100%;
            max-width: 280px;
            outline: none;
            transition: 0.15s;
        }
        .report-card .form-select-custom:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.15);
        }
        .report-card .date-input {
            border: 1px solid #d0d7e8;
            border-radius: 10px;
            padding: 10px 16px;
            font-weight: 500;
            color: #1f2a4a;
            background: white;
            outline: none;
            transition: 0.15s;
            width: 160px;
        }
        .report-card .date-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.15);
        }
        .btn-generate {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 12px 36px;
            font-weight: 600;
            transition: 0.15s;
            white-space: nowrap;
        }
        .btn-generate:hover {
            background: #1d2863;
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(43, 58, 140, 0.3);
        }
        .btn-generate:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        .report-row {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 20px 40px;
        }
        .report-row .field-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .report-row .field-group label {
            font-weight: 600;
            color: var(--primary);
            font-size: 14px;
            letter-spacing: 0.2px;
        }
        .date-range-group {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .date-range-group span {
            font-weight: 500;
            color: #4a5a8c;
        }

        .table-wrap {
            background: white;
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            padding: 6px 0;
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
        .table tbody tr:hover {
            background: #f8f9ff;
        }
        .action-icons .btn-download {
            background: var(--primary);
            border: none;
            color: white;
            padding: 6px 20px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            transition: 0.15s;
        }
        .action-icons .btn-download:hover {
            background: #1d2863;
            transform: scale(1.05);
        }
        .report-name {
            font-weight: 600;
            color: var(--primary);
        }
        .report-name i {
            font-size: 18px;
        }
        .report-desc {
            color: #6c7a9a;
            font-weight: 400;
            font-size: 14px;
        }

        .loader {
            display: none;
            text-align: center;
            padding: 20px;
        }
        .loader i {
            font-size: 30px;
            color: var(--primary);
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .toast-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
        }

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
            .topbar h3 {
                font-size: 22px;
            }
            .content {
                padding: 20px 16px;
            }
            .page-header h2 {
                font-size: 22px;
            }
            .report-card {
                padding: 18px;
            }
            .report-row {
                flex-direction: column;
                align-items: stretch;
                gap: 14px;
            }
            .report-row .field-group {
                width: 100%;
            }
            .report-card .form-select-custom {
                max-width: 100%;
            }
            .date-range-group {
                flex-wrap: wrap;
            }
            .date-range-group .date-input {
                width: 100%;
            }
            .btn-generate {
                width: 100%;
                justify-content: center;
            }
            .table-wrap {
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>

<!-- SIDEBAR -->
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
            <li><a href="SuperAdmin_BranchManagement.php"><i class="bi bi-people-fill"></i><span>Branch Management</span></a></li>
            <li><a href="SuperAdmin_BranchAdminManagement.php"><i class="bi bi-heart-pulse-fill"></i><span>Branch Admin Management</span></a></li>
            <li><a href="SuperAdmin_UserMonitoring.php"><i class="bi bi-box-seam"></i><span>User Monitoring</span></a></li>
            <li><a href="SuperAdmin_BranchPerformanceMonitoring.php"><i class="bi bi-graph-up-arrow"></i><span>Branch Performance Monitoring</span></a></li>
            <li><a class="active" href="SuperAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
            <li><a href="SuperAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
            <li><a href="SuperAdmin_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
        </ul>
    </nav>

    <div class="logout">
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main">
    <div class="topbar">
        <h3>Reports</h3>
        <div class="profile">SUPER ADMIN <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <div class="content">
        <!-- Report Generator Card -->
        <div class="report-card">
            <form id="reportForm" method="GET" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                <div class="report-row">
                    <div class="field-group">
                        <label>Select Report Type</label>
                        <select name="report_type" id="reportType" class="form-select-custom" required>
                            <option value="">-- Select Report --</option>
                            <option value="user">User Report</option>
                            <option value="branch_admin">Branch Admin Report</option>
                            <option value="audit_logs">Audit Logs Report</option>
                            <option value="branch_performance">Branch Performance Report</option>
                        </select>
                    </div>

                    <div class="field-group" id="branchGroup">
                        <label>Branch (Optional)</label>
                        <select name="branch_id" class="form-select-custom">
                            <option value="">All Branches</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo $branch['branch_id']; ?>">
                                    <?php echo htmlspecialchars($branch['branch_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field-group">
                        <label>Date Range</label>
                        <div class="date-range-group">
                            <input type="date" name="start_date" class="date-input" id="startDate" />
                            <span>to</span>
                            <input type="date" name="end_date" class="date-input" id="endDate" />
                        </div>
                    </div>

                    <div class="field-group" style="justify-content: flex-end; flex: 1;">
                        <input type="hidden" name="generate_report" value="1" />
                        <button type="submit" class="btn-generate" id="generateBtn">
                            <i class="bi bi-file-earmark-pdf me-2"></i> Generate Report
                        </button>
                    </div>
                </div>
            </form>
            
            <div class="loader" id="loader">
                <i class="bi bi-arrow-repeat"></i>
                <p class="mt-2">Generating report, please wait...</p>
            </div>
        </div>

        <!-- Report List -->
        <div class="table-wrap">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Report Name</th>
                        <th>Description</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="report-name"><i class="bi bi-people me-2"></i>User Report</td>
                        <td class="report-desc">Complete user list with roles, status, and branch assignments</td>
                        <td class="action-icons">
                            <button class="btn-download" onclick="quickGenerate('user')">
                                <i class="bi bi-download me-1"></i> Generate
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td class="report-name"><i class="bi bi-person-badge me-2"></i>Branch Admin Report</td>
                        <td class="report-desc">Detailed branch administrator information and status</td>
                        <td class="action-icons">
                            <button class="btn-download" onclick="quickGenerate('branch_admin')">
                                <i class="bi bi-download me-1"></i> Generate
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td class="report-name"><i class="bi bi-clock-history me-2"></i>Audit Logs Report</td>
                        <td class="report-desc">Complete system audit trail with user activities</td>
                        <td class="action-icons">
                            <button class="btn-download" onclick="quickGenerate('audit_logs')">
                                <i class="bi bi-download me-1"></i> Generate
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td class="report-name"><i class="bi bi-graph-up me-2"></i>Branch Performance Report</td>
                        <td class="report-desc">Key performance metrics by branch (cases, patients, vaccinations)</td>
                        <td class="action-icons">
                            <button class="btn-download" onclick="quickGenerate('branch_performance')">
                                <i class="bi bi-download me-1"></i> Generate
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Set default date range
document.addEventListener('DOMContentLoaded', function() {
    const today = new Date();
    const thirtyDaysAgo = new Date();
    thirtyDaysAgo.setDate(today.getDate() - 30);
    
    const formatDate = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };
    
    document.getElementById('startDate').value = formatDate(thirtyDaysAgo);
    document.getElementById('endDate').value = formatDate(today);
});

// Quick generate with specific report type
function quickGenerate(reportType) {
    document.getElementById('reportType').value = reportType;
    document.getElementById('reportForm').submit();
    showLoader();
}

// Show loader and disable button
function showLoader() {
    document.getElementById('loader').style.display = 'block';
    document.getElementById('generateBtn').disabled = true;
    document.getElementById('generateBtn').innerHTML = '<i class="bi bi-arrow-repeat me-2 spinner"></i> Generating...';
}

// Handle form submission
document.getElementById('reportForm').addEventListener('submit', function(e) {
    if (!this.report_type.value) {
        e.preventDefault();
        alert('Please select a report type.');
        return false;
    }
    showLoader();
});

// Reset loader state when page loads
window.addEventListener('load', function() {
    document.getElementById('loader').style.display = 'none';
    document.getElementById('generateBtn').disabled = false;
    document.getElementById('generateBtn').innerHTML = '<i class="bi bi-file-earmark-pdf me-2"></i> Generate Report';
});

// Close loader after 10 seconds as fallback
setTimeout(function() {
    document.getElementById('loader').style.display = 'none';
    document.getElementById('generateBtn').disabled = false;
    document.getElementById('generateBtn').innerHTML = '<i class="bi bi-file-earmark-pdf me-2"></i> Generate Report';
}, 10000);
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>