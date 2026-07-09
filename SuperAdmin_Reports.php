<?php
session_start();
require_once 'sources/db_connect.php';

// ============================================
// AUDIT LOG FUNCTION
// ============================================
function addAuditLog($conn, $user_id, $action, $module = 'Reports') {
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
    
    if (empty($branch_id)) {
        $default_branch_sql = "SELECT branch_id FROM branches LIMIT 1";
        $default_stmt = $conn->prepare($default_branch_sql);
        if ($default_stmt) {
            $default_stmt->execute();
            $default_result = $default_stmt->get_result();
            if ($default_row = $default_result->fetch_assoc()) {
                $branch_id = $default_row['branch_id'];
            }
            $default_stmt->close();
        }
    }
    
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
// FPDF CLASS WITH BEAUTIFUL DESIGN
// ============================================
require_once('fpdf/fpdf.php');

class PDF extends FPDF
{
    private $title;
    private $subtitle;
    private $branchName;
    private $dateRange;
    private $logoPath;

    function __construct($title, $subtitle = '', $branchName = '', $dateRange = '')
    {
        parent::__construct('P', 'mm', 'A4');
        $this->title = $title;
        $this->subtitle = $subtitle;
        $this->branchName = $branchName;
        $this->dateRange = $dateRange;
        $this->logoPath = 'C:/xampp/htdocs/SBI-ABC-SMARTBITECARE/logo.png';
        $this->SetMargins(15, 15, 15);
        $this->SetAutoPageBreak(true, 25);
        $this->AddPage();
    }

    function Header()
    {
        $this->SetFillColor(240, 244, 255);
        $this->Rect(0, 0, 210, 45, 'F');
        
        if (file_exists($this->logoPath)) {
            $this->Image($this->logoPath, 12, 6, 25);
        }
        
        $this->SetY(8);
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(43, 58, 140);
        $this->Cell(0, 7, 'SBI MEDICAL AND ANIMAL BITE CENTER', 0, 1, 'C');
        
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(0, 6, 'AND VACCINATION CLINIC', 0, 1, 'C');
        
        $this->SetDrawColor(43, 58, 140);
        $this->SetLineWidth(0.5);
        $this->Line(40, 28, 170, 28);
        
        $this->SetY(33);
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(43, 58, 140);
        $this->Cell(0, 8, $this->title, 0, 1, 'C');
        
        if ($this->subtitle) {
            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(100, 100, 100);
            $this->Cell(0, 5, $this->subtitle, 0, 1, 'C');
        }
        
        $this->SetY(46);
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(43, 58, 140);
        
        $info = '';
        if ($this->branchName) {
            $info .= 'Branch: ' . $this->branchName;
        }
        if ($this->dateRange) {
            if ($info) $info .= ' | ';
            $info .= 'Date Range: ' . $this->dateRange;
        }
        
        if ($info) {
            $this->Cell(0, 5, $info, 0, 1, 'C');
        }
        
        $this->SetY(54);
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.3);
        $this->Line(15, 54, 195, 54);
        
        $this->SetY(58);
    }

    function Footer()
    {
        $this->SetY(-18);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        
        $this->Cell(0, 5, 'Generated on: ' . date('Y-m-d H:i:s'), 0, 0, 'L');
        $this->Cell(0, 5, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
        
        $this->SetY(-15);
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.2);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
    }

    function CreateStatsBox($stats)
    {
        $boxHeight = count($stats) <= 4 ? 32 : 44;
        
        $this->SetFont('Arial', 'B', 11);
        $this->SetFillColor(240, 244, 255);
        $this->SetDrawColor(43, 58, 140);
        $this->SetLineWidth(0.5);
        
        $x = $this->GetX();
        $y = $this->GetY();
        $boxWidth = 180;
        
        $this->Rect($x, $y, $boxWidth, $boxHeight);
        
        $this->SetXY($x + 5, $y + 2);
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(43, 58, 140);
        $this->Cell(0, 5, 'REPORT SUMMARY', 0, 1, 'L');
        
        $count = 0;
        $maxPerRow = 3;
        $currentX = $x + 5;
        $currentY = $y + 10;
        
        foreach ($stats as $key => $value) {
            $this->SetXY($currentX, $currentY);
            
            $this->SetFont('Arial', 'B', 8);
            $this->SetTextColor(80, 80, 80);
            $this->Cell(45, 6, $key . ':', 0, 0, 'L');
            
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor(43, 58, 140);
            $this->Cell(10, 6, $value, 0, 0, 'L');
            
            $count++;
            
            if ($count % $maxPerRow == 0) {
                $currentX = $x + 5;
                $currentY += 8;
            } else {
                $currentX += 60;
            }
        }
        
        $this->SetY($y + $boxHeight + 6);
    }

    function CreateTable($headers, $data, $columnWidths = null, $fontSize = 9)
    {
        if ($columnWidths === null) {
            $columnWidths = array_fill(0, count($headers), 25);
        }
        
        $totalWidth = array_sum($columnWidths);
        if ($totalWidth > 180) {
            $scale = 180 / $totalWidth;
            $columnWidths = array_map(function($w) use ($scale) { return $w * $scale; }, $columnWidths);
        }
        
        $this->SetFont('Arial', 'B', $fontSize);
        $this->SetFillColor(43, 58, 140);
        $this->SetTextColor(255, 255, 255);
        $this->SetDrawColor(43, 58, 140);
        $this->SetLineWidth(0.3);
        
        foreach ($headers as $i => $header) {
            $this->Cell($columnWidths[$i], 9, $header, 1, 0, 'C', true);
        }
        $this->Ln();
        
        $this->SetFont('Arial', '', $fontSize - 1);
        $this->SetTextColor(40, 40, 40);
        $this->SetDrawColor(220, 220, 220);
        $fill = false;
        $rowCount = 0;
        
        foreach ($data as $row) {
            $rowHeight = 7;
            
            if ($rowCount % 2 == 0) {
                $this->SetFillColor(248, 250, 255);
                $fill = true;
            } else {
                $this->SetFillColor(255, 255, 255);
                $fill = false;
            }
            
            foreach ($row as $i => $cell) {
                $this->Cell($columnWidths[$i], $rowHeight, $cell, 1, 0, 'L', $fill);
            }
            $this->Ln();
            $rowCount++;
        }
        
        $this->Ln(3);
    }
}

// ============================================
// CSV EXPORT FUNCTIONS
// ============================================
function exportUsersCSV($branchId = null, $startDate = null, $endDate = null) {
    global $conn;
    $params = [];
    $types = "";
    
    $query = "SELECT u.user_id, u.username, u.email, u.status, u.last_login, u.created_at, 
              r.role_name, b.branch_name 
              FROM users u 
              LEFT JOIN roles r ON u.role_id = r.role_id 
              LEFT JOIN branches b ON u.branch_id = b.branch_id 
              WHERE u.status != 'Deleted'";
    
    if (!empty($branchId)) {
        $query .= " AND u.branch_id = ?";
        $params[] = $branchId;
        $types .= "s";
    }
    
    if (!empty($startDate) && !empty($endDate)) {
        $query .= " AND DATE(u.created_at) BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
        $types .= "ss";
    }
    
    $query .= " ORDER BY u.created_at DESC";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="User_Report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    fputcsv($output, ['ID', 'Username', 'Email', 'Role', 'Branch', 'Status', 'Last Login', 'Created At']);
    
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['user_id'],
            $row['username'],
            $row['email'],
            $row['role_name'] ?: 'N/A',
            $row['branch_name'] ?: 'N/A',
            $row['status'],
            $row['last_login'] ? date('Y-m-d H:i:s', strtotime($row['last_login'])) : 'Never',
            date('Y-m-d H:i:s', strtotime($row['created_at']))
        ]);
    }
    
    fclose($output);
    exit;
}

function exportBranchAdminsCSV($branchId = null, $startDate = null, $endDate = null) {
    global $conn;
    $params = [];
    $types = "";
    
    $query = "SELECT u.user_id, u.username, u.email, u.status, u.last_login, u.created_at,
              b.branch_name, b.branch_address, b.contact_number 
              FROM users u 
              INNER JOIN roles r ON u.role_id = r.role_id 
              INNER JOIN branches b ON u.branch_id = b.branch_id 
              WHERE r.role_name = 'Branch Admin' AND u.status != 'Deleted'";
    
    if (!empty($branchId)) {
        $query .= " AND u.branch_id = ?";
        $params[] = $branchId;
        $types .= "s";
    }
    
    if (!empty($startDate) && !empty($endDate)) {
        $query .= " AND DATE(u.created_at) BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
        $types .= "ss";
    }
    
    $query .= " ORDER BY u.created_at DESC";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Branch_Admin_Report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    fputcsv($output, ['ID', 'Username', 'Email', 'Branch', 'Address', 'Contact', 'Status', 'Created At']);
    
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['user_id'],
            $row['username'],
            $row['email'],
            $row['branch_name'],
            $row['branch_address'] ?: 'N/A',
            $row['contact_number'] ?: 'N/A',
            $row['status'],
            date('Y-m-d H:i:s', strtotime($row['created_at']))
        ]);
    }
    
    fclose($output);
    exit;
}

function exportBranchPerformanceCSV($branchId = null, $startDate = null, $endDate = null) {
    global $conn;
    $params = [];
    $types = "";
    
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
    
    if (!empty($branchId)) {
        $query .= " AND b.branch_id = ?";
        $params[] = $branchId;
        $types .= "s";
    }
    
    if (!empty($startDate) && !empty($endDate)) {
        $query .= " AND DATE(b.created_at) BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
        $types .= "ss";
    }
    
    $query .= " GROUP BY b.branch_id, b.branch_name, b.branch_address, b.contact_number 
                ORDER BY total_cases DESC";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Branch_Performance_Report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    fputcsv($output, ['Branch', 'Address', 'Contact', 'Total Cases', 'Total Patients', 'Total Vaccinations', 'Total Staff', 'Total Activities']);
    
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['branch_name'],
            $row['branch_address'] ?: 'N/A',
            $row['contact_number'] ?: 'N/A',
            $row['total_cases'] ?: 0,
            $row['total_patients'] ?: 0,
            $row['total_vaccinations'] ?: 0,
            $row['total_staff'] ?: 0,
            $row['total_activities'] ?: 0
        ]);
    }
    
    fclose($output);
    exit;
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function getBranchName($branchId) {
    global $conn;
    if (!$branchId || empty($branchId)) return 'All Branches';
    $stmt = $conn->prepare("SELECT branch_name FROM branches WHERE branch_id = ?");
    if (!$stmt) return 'Unknown';
    $stmt->bind_param("s", $branchId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? $row['branch_name'] : 'Unknown Branch';
}

function getBranches() {
    global $conn;
    $result = $conn->query("SELECT branch_id, branch_name FROM branches WHERE status = 'Active' ORDER BY branch_name");
    $branches = [];
    while ($row = $result->fetch_assoc()) {
        $branches[] = $row;
    }
    return $branches;
}

// ============================================
// GENERATE USER REPORT (PDF)
// ============================================
function generateUserReport($branchId = null, $startDate = null, $endDate = null) {
    global $conn;
    $params = [];
    $types = "";
    
    $query = "SELECT u.user_id, u.username, u.email, u.status, u.last_login, u.created_at, 
              r.role_name, b.branch_name 
              FROM users u 
              LEFT JOIN roles r ON u.role_id = r.role_id 
              LEFT JOIN branches b ON u.branch_id = b.branch_id 
              WHERE u.status != 'Deleted'";
    
    if (!empty($branchId)) {
        $query .= " AND u.branch_id = ?";
        $params[] = $branchId;
        $types .= "s";
    }
    
    if (!empty($startDate) && !empty($endDate)) {
        $query .= " AND DATE(u.created_at) BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
        $types .= "ss";
    }
    
    $query .= " ORDER BY u.created_at DESC";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();
    
    $branchName = !empty($branchId) ? getBranchName($branchId) : 'All Branches';
    $dateRangeText = (!empty($startDate) && !empty($endDate)) ? $startDate . ' to ' . $endDate : 'All Time';
    
    $pdf = new PDF('USER REPORT', 'Detailed user information report', $branchName, $dateRangeText);
    
    $activeCount = array_reduce($users, function($carry, $user) {
        return $carry + ($user['status'] == 'Active' ? 1 : 0);
    }, 0);
    
    $pdf->CreateStatsBox([
        'Total Users' => count($users),
        'Active Users' => $activeCount,
        'Inactive Users' => count($users) - $activeCount,
        'Roles Count' => count(array_unique(array_column($users, 'role_name')))
    ]);
    
    $headers = ['ID', 'Username', 'Email', 'Role', 'Branch', 'Status', 'Created'];
    $columnWidths = [16, 32, 45, 28, 35, 20, 28];
    
    $data = array_map(function($user) {
        return [
            $user['user_id'],
            substr($user['username'], 0, 15),
            substr($user['email'], 0, 22) . (strlen($user['email']) > 22 ? '...' : ''),
            $user['role_name'] ?: 'N/A',
            $user['branch_name'] ?: 'N/A',
            $user['status'],
            date('Y-m-d', strtotime($user['created_at']))
        ];
    }, $users);
    
    $pdf->CreateTable($headers, $data, $columnWidths, 8);
    
    return $pdf;
}

// ============================================
// GENERATE BRANCH ADMIN REPORT (PDF)
// ============================================
function generateBranchAdminReport($branchId = null, $startDate = null, $endDate = null) {
    global $conn;
    $params = [];
    $types = "";
    
    $query = "SELECT u.user_id, u.username, u.email, u.status, u.last_login, u.created_at,
              b.branch_name, b.branch_address, b.contact_number 
              FROM users u 
              INNER JOIN roles r ON u.role_id = r.role_id 
              INNER JOIN branches b ON u.branch_id = b.branch_id 
              WHERE r.role_name = 'Branch Admin' AND u.status != 'Deleted'";
    
    if (!empty($branchId)) {
        $query .= " AND u.branch_id = ?";
        $params[] = $branchId;
        $types .= "s";
    }
    
    if (!empty($startDate) && !empty($endDate)) {
        $query .= " AND DATE(u.created_at) BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
        $types .= "ss";
    }
    
    $query .= " ORDER BY u.created_at DESC";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $admins = [];
    while ($row = $result->fetch_assoc()) {
        $admins[] = $row;
    }
    $stmt->close();
    
    $branchName = !empty($branchId) ? getBranchName($branchId) : 'All Branches';
    $dateRangeText = (!empty($startDate) && !empty($endDate)) ? $startDate . ' to ' . $endDate : 'All Time';
    
    $pdf = new PDF('BRANCH ADMIN REPORT', 'Detailed branch administrator information', $branchName, $dateRangeText);
    
    $activeCount = array_reduce($admins, function($carry, $admin) {
        return $carry + ($admin['status'] == 'Active' ? 1 : 0);
    }, 0);
    
    $uniqueBranches = count(array_unique(array_column($admins, 'branch_name')));
    
    $pdf->CreateStatsBox([
        'Total Admins' => count($admins),
        'Active Admins' => $activeCount,
        'Branches' => $uniqueBranches,
        'Inactive Admins' => count($admins) - $activeCount
    ]);
    
    $headers = ['ID', 'Username', 'Email', 'Branch', 'Contact', 'Status'];
    $columnWidths = [16, 32, 45, 38, 30, 20];
    
    $data = array_map(function($admin) {
        return [
            $admin['user_id'],
            substr($admin['username'], 0, 15),
            substr($admin['email'], 0, 22) . (strlen($admin['email']) > 22 ? '...' : ''),
            $admin['branch_name'],
            $admin['contact_number'] ?: 'N/A',
            $admin['status']
        ];
    }, $admins);
    
    $pdf->CreateTable($headers, $data, $columnWidths, 8);
    
    return $pdf;
}

// ============================================
// GENERATE BRANCH PERFORMANCE REPORT (PDF)
// ============================================
function generateBranchPerformanceReport($branchId = null, $startDate = null, $endDate = null) {
    global $conn;
    $params = [];
    $types = "";
    
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
    
    if (!empty($branchId)) {
        $query .= " AND b.branch_id = ?";
        $params[] = $branchId;
        $types .= "s";
    }
    
    if (!empty($startDate) && !empty($endDate)) {
        $query .= " AND DATE(b.created_at) BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
        $types .= "ss";
    }
    
    $query .= " GROUP BY b.branch_id, b.branch_name, b.branch_address, b.contact_number 
                ORDER BY total_cases DESC";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $branches = [];
    while ($row = $result->fetch_assoc()) {
        $branches[] = $row;
    }
    $stmt->close();
    
    $branchName = !empty($branchId) ? getBranchName($branchId) : 'All Branches';
    $dateRangeText = (!empty($startDate) && !empty($endDate)) ? $startDate . ' to ' . $endDate : 'All Time';
    
    $pdf = new PDF('BRANCH PERFORMANCE REPORT', 'Key performance metrics by branch', $branchName, $dateRangeText);
    
    $totalCases = array_sum(array_column($branches, 'total_cases'));
    $totalPatients = array_sum(array_column($branches, 'total_patients'));
    $totalVaccinations = array_sum(array_column($branches, 'total_vaccinations'));
    
    $pdf->CreateStatsBox([
        'Total Cases' => $totalCases,
        'Total Patients' => $totalPatients,
        'Total Vaccinations' => $totalVaccinations,
        'Active Branches' => count($branches)
    ]);
    
    $headers = ['Branch', 'Cases', 'Patients', 'Vaccinations', 'Staff', 'Activities'];
    $columnWidths = [40, 28, 28, 32, 25, 30];
    
    $data = array_map(function($branch) {
        return [
            substr($branch['branch_name'], 0, 20),
            $branch['total_cases'] ?: 0,
            $branch['total_patients'] ?: 0,
            $branch['total_vaccinations'] ?: 0,
            $branch['total_staff'] ?: 0,
            $branch['total_activities'] ?: 0
        ];
    }, $branches);
    
    $pdf->CreateTable($headers, $data, $columnWidths, 9);
    
    if (count($branches) > 0) {
        $pdf->Ln(8);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor(43, 58, 140);
        $pdf->Cell(0, 10, 'PERFORMANCE INSIGHTS', 0, 1, 'C');
        
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(40, 40, 40);
        
        $bestBranch = $branches[0];
        $pdf->Cell(0, 7, 'Top Performing Branch: ' . $bestBranch['branch_name'] . 
                   ' with ' . ($bestBranch['total_cases'] ?: 0) . ' cases', 0, 1, 'L');
        
        if (count($branches) > 1) {
            $worstBranch = end($branches);
            $pdf->Cell(0, 7, 'Branch needing improvement: ' . $worstBranch['branch_name'] . 
                       ' with ' . ($worstBranch['total_cases'] ?: 0) . ' cases', 0, 1, 'L');
            
            $avgCases = round($totalCases / count($branches), 1);
            $pdf->Cell(0, 7, 'Average cases per branch: ' . $avgCases, 0, 1, 'L');
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
    $exportFormat = isset($_GET['export_format']) ? $_GET['export_format'] : 'pdf';
    
    $branchName = $branchId ? getBranchName($branchId) : 'All Branches';
    $dateRange = ($startDate && $endDate) ? "$startDate to $endDate" : 'All Time';
    $actionDetail = "Generated $reportType report ($exportFormat) - Branch: $branchName, Date Range: $dateRange";
    addAuditLog($conn, $_SESSION['user_id'], $actionDetail, 'Reports');
    
    // Handle CSV exports
    if ($exportFormat === 'csv') {
        switch ($reportType) {
            case 'user':
                exportUsersCSV($branchId, $startDate, $endDate);
                break;
            case 'branch_admin':
                exportBranchAdminsCSV($branchId, $startDate, $endDate);
                break;
            case 'branch_performance':
                exportBranchPerformanceCSV($branchId, $startDate, $endDate);
                break;
            default:
                die('Invalid report type');
        }
        exit;
    }
    
    // Handle PDF exports
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
        case 'branch_performance':
            $pdf = generateBranchPerformanceReport($branchId, $startDate, $endDate);
            $filename = 'Branch_Performance_Report_' . date('Y-m-d') . '.pdf';
            break;
        default:
            die('Invalid report type');
    }
    
    if ($pdf) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        header('Content-Transfer-Encoding: binary');
        
        $pdf->AliasNbPages();
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
        
        /* Equal sized buttons */
        .btn-generate {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 12px 36px;
            font-weight: 600;
            transition: 0.15s;
            white-space: nowrap;
            min-width: 180px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
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
        
        .btn-export-csv {
            background: #17a2b8;
            color: white;
            border: none;
            border-radius: 40px;
            padding: 12px 36px;
            font-weight: 600;
            transition: 0.15s;
            white-space: nowrap;
            min-width: 180px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-export-csv:hover {
            background: #117a8b;
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(23, 162, 184, 0.3);
        }
        .btn-export-csv:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-group-export {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
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
        .action-icons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .action-icons .btn-download-pdf {
            background: var(--primary);
            border: none;
            color: white;
            padding: 8px 24px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            transition: 0.15s;
            min-width: 80px;
            text-align: center;
        }
        .action-icons .btn-download-pdf:hover {
            background: #1d2863;
            transform: scale(1.05);
        }
        .action-icons .btn-download-csv {
            background: #17a2b8;
            border: none;
            color: white;
            padding: 8px 24px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            transition: 0.15s;
            min-width: 80px;
            text-align: center;
        }
        .action-icons .btn-download-csv:hover {
            background: #117a8b;
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
            .btn-generate, .btn-export-csv {
                width: 100%;
                justify-content: center;
                min-width: unset;
            }
            .btn-group-export {
                flex-direction: column;
                width: 100%;
            }
            .btn-group-export .btn-generate,
            .btn-group-export .btn-export-csv {
                width: 100%;
            }
            .table-wrap {
                overflow-x: auto;
            }
            .action-icons {
                flex-direction: column;
                width: 100%;
            }
            .action-icons .btn-download-pdf,
            .action-icons .btn-download-csv {
                width: 100%;
                text-align: center;
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
        <div class="profile">
            <i class="bi bi-person-circle"></i>
            SUPER ADMIN
            <i class="bi bi-caret-down-fill"></i>
        </div>
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
                        <div class="btn-group-export">
                            <button type="submit" name="export_format" value="pdf" class="btn-generate" id="generateBtnPDF">
                                <i class="bi bi-file-earmark-pdf"></i> Export PDF
                            </button>
                            <button type="submit" name="export_format" value="csv" class="btn-export-csv" id="generateBtnCSV">
                                <i class="bi bi-file-earmark-excel"></i> Export CSV
                            </button>
                        </div>
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
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="report-name"><i class="bi bi-people me-2"></i>User Report</td>
                        <td class="report-desc">Complete user list with roles, status, and branch assignments</td>
                        <td class="action-icons">
                            <button class="btn-download-pdf" onclick="quickGenerate('user', 'pdf')">
                                <i class="bi bi-file-earmark-pdf me-1"></i> PDF
                            </button>
                            <button class="btn-download-csv" onclick="quickGenerate('user', 'csv')">
                                <i class="bi bi-file-earmark-excel me-1"></i> CSV
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td class="report-name"><i class="bi bi-person-badge me-2"></i>Branch Admin Report</td>
                        <td class="report-desc">Detailed branch administrator information and status</td>
                        <td class="action-icons">
                            <button class="btn-download-pdf" onclick="quickGenerate('branch_admin', 'pdf')">
                                <i class="bi bi-file-earmark-pdf me-1"></i> PDF
                            </button>
                            <button class="btn-download-csv" onclick="quickGenerate('branch_admin', 'csv')">
                                <i class="bi bi-file-earmark-excel me-1"></i> CSV
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td class="report-name"><i class="bi bi-graph-up me-2"></i>Branch Performance Report</td>
                        <td class="report-desc">Key performance metrics by branch (cases, patients, vaccinations)</td>
                        <td class="action-icons">
                            <button class="btn-download-pdf" onclick="quickGenerate('branch_performance', 'pdf')">
                                <i class="bi bi-file-earmark-pdf me-1"></i> PDF
                            </button>
                            <button class="btn-download-csv" onclick="quickGenerate('branch_performance', 'csv')">
                                <i class="bi bi-file-earmark-excel me-1"></i> CSV
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
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

function quickGenerate(reportType, format) {
    document.getElementById('reportType').value = reportType;
    
    // Get current form
    const form = document.getElementById('reportForm');
    
    // Remove any existing hidden inputs for export_format and generate_report
    const existingExport = form.querySelector('input[name="export_format"]');
    if (existingExport) existingExport.remove();
    const existingGenerate = form.querySelector('input[name="generate_report"]');
    if (existingGenerate) existingGenerate.remove();
    
    // Create hidden input for export format
    const hiddenInput = document.createElement('input');
    hiddenInput.type = 'hidden';
    hiddenInput.name = 'export_format';
    hiddenInput.value = format;
    form.appendChild(hiddenInput);
    
    // Create hidden input for generate_report
    const generateInput = document.createElement('input');
    generateInput.type = 'hidden';
    generateInput.name = 'generate_report';
    generateInput.value = '1';
    form.appendChild(generateInput);
    
    form.submit();
    showLoader();
}

function showLoader() {
    document.getElementById('loader').style.display = 'block';
    document.getElementById('generateBtnPDF').disabled = true;
    document.getElementById('generateBtnCSV').disabled = true;
    document.getElementById('generateBtnPDF').innerHTML = '<i class="bi bi-arrow-repeat spinner"></i> Generating...';
    document.getElementById('generateBtnCSV').innerHTML = '<i class="bi bi-arrow-repeat spinner"></i> Generating...';
}

document.getElementById('reportForm').addEventListener('submit', function(e) {
    if (!this.report_type.value) {
        e.preventDefault();
        alert('Please select a report type.');
        return false;
    }
    showLoader();
});

// Reset buttons after load
window.addEventListener('load', function() {
    document.getElementById('loader').style.display = 'none';
    document.getElementById('generateBtnPDF').disabled = false;
    document.getElementById('generateBtnCSV').disabled = false;
    document.getElementById('generateBtnPDF').innerHTML = '<i class="bi bi-file-earmark-pdf"></i> Export PDF';
    document.getElementById('generateBtnCSV').innerHTML = '<i class="bi bi-file-earmark-excel"></i> Export CSV';
});

// Safety timeout
setTimeout(function() {
    document.getElementById('loader').style.display = 'none';
    document.getElementById('generateBtnPDF').disabled = false;
    document.getElementById('generateBtnCSV').disabled = false;
    document.getElementById('generateBtnPDF').innerHTML = '<i class="bi bi-file-earmark-pdf"></i> Export PDF';
    document.getElementById('generateBtnCSV').innerHTML = '<i class="bi bi-file-earmark-excel"></i> Export CSV';
}, 10000);
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>