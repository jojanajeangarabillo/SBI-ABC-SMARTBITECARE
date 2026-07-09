<?php
session_start();
require_once 'sources/db_connect.php';

// ============================================
// FPDF CLASS WITH BETTER FORMATTING
// ============================================
require_once('fpdf/fpdf.php');

// Turn off error display for PDF generation
error_reporting(0);

class PDF_AuditReport extends FPDF
{
    /** @var array<string, string> Filter parameters */
    private $filters;
    
    /** @var string Logo file path */
    private $logoPath;

    /**
     * PDF_AuditReport constructor
     *
     * @param array<string, string> $filters Filter parameters for display
     */
    function __construct($filters = [])
    {
        parent::__construct('L', 'mm', 'A4');
        $this->filters = $filters;
        $this->logoPath = 'C:/xampp/htdocs/SBI-ABC-SMARTBITECARE/logo.png';
        $this->SetMargins(15, 15, 15);
        $this->SetAutoPageBreak(true, 25);
        $this->AddPage();
    }

    function Header()
    {
        $this->SetFillColor(240, 244, 255);
        $this->Rect(0, 0, 297, 50, 'F');
        
        if (file_exists($this->logoPath)) {
            $this->Image($this->logoPath, 15, 6, 25);
        }
        
        $this->SetY(8);
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(43, 58, 140);
        $this->Cell(0, 6, 'SBI MEDICAL AND ANIMAL BITE CENTER', 0, 1, 'C');
        
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(0, 5, 'AND VACCINATION CLINIC', 0, 1, 'C');
        
        $this->SetDrawColor(43, 58, 140);
        $this->SetLineWidth(0.5);
        $this->Line(50, 28, 247, 28);
        
        $this->SetY(33);
        $this->SetFont('Arial', 'B', 15);
        $this->SetTextColor(43, 58, 140);
        $this->Cell(0, 7, 'AUDIT LOGS REPORT', 0, 1, 'C');
        
        // Filter summary
        $this->SetY(43);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(80, 80, 80);
        
        $filterText = '';
        if (!empty($this->filters['date_from']) && !empty($this->filters['date_to'])) {
            $filterText .= 'Date: ' . $this->filters['date_from'] . ' to ' . $this->filters['date_to'];
        }
        if (!empty($this->filters['module'])) {
            if ($filterText) $filterText .= ' | ';
            $filterText .= 'Module: ' . $this->filters['module'];
        }
        if (!empty($this->filters['username'])) {
            if ($filterText) $filterText .= ' | ';
            $filterText .= 'User: ' . $this->filters['username'];
        }
        if (!empty($this->filters['branch_name'])) {
            if ($filterText) $filterText .= ' | ';
            $filterText .= 'Branch: ' . $this->filters['branch_name'];
        }
        if (!empty($this->filters['action_type'])) {
            if ($filterText) $filterText .= ' | ';
            $filterText .= 'Action: ' . $this->filters['action_type'];
        }
        if (!empty($this->filters['search'])) {
            if ($filterText) $filterText .= ' | ';
            $filterText .= 'Search: "' . $this->filters['search'] . '"';
        }
        if (empty($filterText)) {
            $filterText = 'All Logs';
        }
        
        $this->Cell(0, 4, 'Filters: ' . $filterText, 0, 1, 'C');
        
        $this->SetY(55);
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.3);
        $this->Line(15, 55, 282, 55);
        $this->SetY(60);
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
        $this->Line(15, $this->GetY(), 282, $this->GetY());
    }

    /**
     * Create a statistics box with key metrics
     *
     * @param array<string, int|string> $stats Associative array of stat key => value
     */
    function CreateStatsBox($stats)
    {
        $boxHeight = 45;
        $this->SetFont('Arial', 'B', 11);
        $this->SetFillColor(240, 244, 255);
        $this->SetDrawColor(43, 58, 140);
        $this->SetLineWidth(0.5);
        
        $x = $this->GetX();
        $y = $this->GetY();
        $boxWidth = 267;
        
        $this->Rect($x, $y, $boxWidth, $boxHeight);
        
        $this->SetXY($x + 5, $y + 3);
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(43, 58, 140);
        $this->Cell(0, 5, 'REPORT SUMMARY', 0, 1, 'L');
        
        // Layout: 2 columns
        $col1_x = $x + 5;
        $col2_x = $x + 140;
        $col_width = 120;
        $row_height = 10;
        
        $items = [];
        foreach ($stats as $key => $value) {
            $items[] = ['key' => $key, 'value' => $value];
        }
        
        $currentY = $y + 12;
        $half = (int)ceil(count($items) / 2);
        
        // Column 1
        for ($i = 0; $i < $half && $i < count($items); $i++) {
            $this->SetXY($col1_x, $currentY + ($i * $row_height));
            $this->SetFont('Arial', 'B', 8);
            $this->SetTextColor(80, 80, 80);
            // Fix: Cast height parameter to int
            $this->Cell(45, (int)6, $items[$i]['key'] . ':', 0, 0, 'L');
            
            $this->SetFont('Arial', 'B', 9);
            $this->SetTextColor(43, 58, 140);
            // Fix: Cast height parameter to int
            $this->Cell($col_width - 50, (int)6, $items[$i]['value'], 0, 0, 'L');
        }
        
        // Column 2
        for ($i = $half; $i < count($items); $i++) {
            $idx = $i - $half;
            $this->SetXY($col2_x, $currentY + ($idx * $row_height));
            $this->SetFont('Arial', 'B', 8);
            $this->SetTextColor(80, 80, 80);
            // Fix: Cast height parameter to int
            $this->Cell(45, (int)6, $items[$i]['key'] . ':', 0, 0, 'L');
            
            $this->SetFont('Arial', 'B', 9);
            $this->SetTextColor(43, 58, 140);
            // Fix: Cast height parameter to int
            $this->Cell($col_width - 50, (int)6, $items[$i]['value'], 0, 0, 'L');
        }
        
        $this->SetY($y + $boxHeight + 8);
    }

    function CreateTable($headers, $data, $columnWidths = null, $fontSize = 7)
    {
        if ($columnWidths === null) {
            $columnWidths = array_fill(0, count($headers), 40);
        }
        
        $totalWidth = array_sum($columnWidths);
        if ($totalWidth > 267) {
            $scale = 267 / $totalWidth;
            $columnWidths = array_map(function($w) use ($scale) { return $w * $scale; }, $columnWidths);
        }
        
        // Header
        $this->SetFont('Arial', 'B', (int)$fontSize + 1);
        $this->SetFillColor(43, 58, 140);
        $this->SetTextColor(255, 255, 255);
        $this->SetDrawColor(43, 58, 140);
        $this->SetLineWidth(0.3);
        
        foreach ($headers as $i => $header) {
            $this->Cell($columnWidths[$i], 8, $header, 1, 0, 'C', true);
        }
        $this->Ln();
        
        // Data
        $this->SetFont('Arial', '', (int)$fontSize);
        $this->SetTextColor(40, 40, 40);
        $this->SetDrawColor(220, 220, 220);
        $fill = false;
        $rowCount = 0;
        
        foreach ($data as $row) {
            if ($rowCount % 2 == 0) {
                $this->SetFillColor(248, 250, 255);
                $fill = true;
            } else {
                $this->SetFillColor(255, 255, 255);
                $fill = false;
            }
            
            // Calculate max height for this row
            $maxLines = 1;
            foreach ($row as $cell) {
                $lines = substr_count($cell, "\n") + 1;
                if ($lines > $maxLines) $maxLines = $lines;
            }
            $rowHeight = max(6, $maxLines * 5);
            
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
// CHECK USER PERMISSIONS
// ============================================
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 1
) {
    header("Location: login.php");
    exit();
}

// ============================================
// AUDIT LOG FUNCTION
// ============================================
function addAuditLog($conn, $user_id, $action, $module = 'Audit Logs') {
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

// ============================================
// GENERATE PDF REPORT
// ============================================
if (isset($_GET['generate_pdf']) && $_GET['generate_pdf'] == '1') {
    // Clear all output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Start clean output buffer
    ob_start();
    
    try {
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $module_filter = isset($_GET['module']) ? trim($_GET['module']) : '';
        $user_filter = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        $branch_filter = isset($_GET['branch_id']) ? trim($_GET['branch_id']) : '';
        $action_type = isset($_GET['action_type']) ? trim($_GET['action_type']) : '';
        $date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
        
        $query = "SELECT al.log_id, al.action, al.module, al.created_at,
                  u.user_id, u.username, u.email, u.role_id, r.role_name,
                  b.branch_id, b.branch_name 
                  FROM audit_logs al 
                  LEFT JOIN users u ON al.user_id = u.user_id 
                  LEFT JOIN roles r ON u.role_id = r.role_id
                  LEFT JOIN branches b ON al.branch_id = b.branch_id 
                  WHERE 1=1";
        
        $params = [];
        $types = "";
        
        if ($search) {
            $search_param = "%$search%";
            $query .= " AND (al.action LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR al.module LIKE ? OR b.branch_name LIKE ?)";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $types .= "sssss";
        }
        
        if ($module_filter) {
            $query .= " AND al.module = ?";
            $params[] = $module_filter;
            $types .= "s";
        }
        
        if ($user_filter > 0) {
            $query .= " AND al.user_id = ?";
            $params[] = $user_filter;
            $types .= "i";
        }
        
        if ($branch_filter) {
            $query .= " AND al.branch_id = ?";
            $params[] = $branch_filter;
            $types .= "s";
        }
        
        if ($action_type) {
            $query .= " AND al.action LIKE ?";
            $params[] = "%$action_type%";
            $types .= "s";
        }
        
        if ($date_from) {
            $query .= " AND DATE(al.created_at) >= ?";
            $params[] = $date_from;
            $types .= "s";
        }
        
        if ($date_to) {
            $query .= " AND DATE(al.created_at) <= ?";
            $params[] = $date_to;
            $types .= "s";
        }
        
        $query .= " ORDER BY al.created_at DESC LIMIT 2000";
        
        $stmt = $conn->prepare($query);
        if (!empty($params) && $types) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }
        $stmt->close();
        
        // Get filter names for display
        $filterDisplay = [];
        if ($date_from && $date_to) {
            $filterDisplay['date_from'] = $date_from;
            $filterDisplay['date_to'] = $date_to;
        }
        if ($module_filter) {
            $filterDisplay['module'] = $module_filter;
        }
        if ($user_filter > 0) {
            $userName = '';
            $userStmt = $conn->prepare("SELECT username FROM users WHERE user_id = ?");
            $userStmt->bind_param("i", $user_filter);
            $userStmt->execute();
            $userResult = $userStmt->get_result();
            if ($userRow = $userResult->fetch_assoc()) {
                $userName = $userRow['username'];
            }
            $userStmt->close();
            $filterDisplay['username'] = $userName;
        }
        if ($branch_filter) {
            $branchName = '';
            $branchStmt = $conn->prepare("SELECT branch_name FROM branches WHERE branch_id = ?");
            $branchStmt->bind_param("s", $branch_filter);
            $branchStmt->execute();
            $branchResult = $branchStmt->get_result();
            if ($branchRow = $branchResult->fetch_assoc()) {
                $branchName = $branchRow['branch_name'];
            }
            $branchStmt->close();
            $filterDisplay['branch_name'] = $branchName;
        }
        if ($action_type) {
            $filterDisplay['action_type'] = $action_type;
        }
        if ($search) {
            $filterDisplay['search'] = $search;
        }
        
        $pdf = new PDF_AuditReport($filterDisplay);
        
        // Calculate stats
        $moduleList = array_filter(array_column($logs, 'module'), function($val) {
            return $val !== null && $val !== '';
        });
        $moduleCount = array_count_values($moduleList);
        arsort($moduleCount);
        $topModules = array_slice($moduleCount, 0, 3);
        
        // Format top modules with line breaks if too long
        $topModulesStr = '';
        if (!empty($topModules)) {
            $topModulesParts = array_map(function($k, $v) { 
                return "$k ($v)"; 
            }, array_keys($topModules), $topModules);
            $topModulesStr = implode(', ', $topModulesParts);
            // Truncate if too long
            if (strlen($topModulesStr) > 50) {
                $topModulesStr = substr($topModulesStr, 0, 47) . '...';
            }
        } else {
            $topModulesStr = 'None';
        }
        
        $userList = array_filter(array_column($logs, 'username'), function($val) {
            return $val !== null;
        });
        $uniqueUsers = count(array_unique($userList));
        
        $pdf->CreateStatsBox([
            'Total Logs' => count($logs),
            'Modules Used' => count($moduleCount),
            'Top Modules' => $topModulesStr,
            'Unique Users' => $uniqueUsers
        ]);
        
        // Better column widths - increased action column
        $headers = ['Date/Time', 'User', 'Role', 'Module', 'Action', 'Branch'];
        $columnWidths = [35, 30, 25, 32, 90, 35];
        
        $data = array_map(function($log) {
            // Format action to wrap
            $action = $log['action'] ?: 'N/A';
            if (strlen($action) > 65) {
                $action = wordwrap($action, 60, "\n", true);
            }
            
            return [
                date('Y-m-d H:i', strtotime($log['created_at'])),
                substr($log['username'] ?: 'System', 0, 12),
                $log['role_name'] ?: 'N/A',
                $log['module'] ?: 'N/A',
                $action,
                $log['branch_name'] ?: 'N/A'
            ];
        }, $logs);
        
        $pdf->CreateTable($headers, $data, $columnWidths, 7);
        
        $pdf->AliasNbPages();
        
        // Clean buffer and output PDF
        ob_clean();
        $pdf->Output('D', 'Audit_Logs_Report_' . date('Y-m-d') . '.pdf');
        exit;
        
    } catch (Exception $e) {
        // If there's an error, show it
        ob_clean();
        die('PDF Generation Error: ' . $e->getMessage());
    }
}

// ============================================
// MAIN PAGE - GET FILTER PARAMETERS
// ============================================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$module_filter = isset($_GET['module']) ? trim($_GET['module']) : '';
$user_filter = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$branch_filter = isset($_GET['branch_id']) ? trim($_GET['branch_id']) : '';
$action_type = isset($_GET['action_type']) ? trim($_GET['action_type']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build base query
$base_sql = "SELECT al.log_id, al.action, al.module, al.created_at,
             u.user_id, u.username, u.email, u.role_id, r.role_name,
             b.branch_id, b.branch_name 
             FROM audit_logs al 
             LEFT JOIN users u ON al.user_id = u.user_id 
             LEFT JOIN roles r ON u.role_id = r.role_id
             LEFT JOIN branches b ON al.branch_id = b.branch_id 
             WHERE 1=1";

$count_sql = "SELECT COUNT(*) as total 
              FROM audit_logs al 
              LEFT JOIN users u ON al.user_id = u.user_id 
              LEFT JOIN roles r ON u.role_id = r.role_id
              LEFT JOIN branches b ON al.branch_id = b.branch_id 
              WHERE 1=1";

$params = [];
$types = "";

if ($search) {
    $search_param = "%$search%";
    $base_sql .= " AND (al.action LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR al.module LIKE ? OR b.branch_name LIKE ?)";
    $count_sql .= " AND (al.action LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR al.module LIKE ? OR b.branch_name LIKE ?)";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sssss";
}

if ($module_filter) {
    $base_sql .= " AND al.module = ?";
    $count_sql .= " AND al.module = ?";
    $params[] = $module_filter;
    $types .= "s";
}

if ($user_filter > 0) {
    $base_sql .= " AND al.user_id = ?";
    $count_sql .= " AND al.user_id = ?";
    $params[] = $user_filter;
    $types .= "i";
}

if ($branch_filter) {
    $base_sql .= " AND al.branch_id = ?";
    $count_sql .= " AND al.branch_id = ?";
    $params[] = $branch_filter;
    $types .= "s";
}

if ($action_type) {
    $base_sql .= " AND al.action LIKE ?";
    $count_sql .= " AND al.action LIKE ?";
    $params[] = "%$action_type%";
    $types .= "s";
}

if ($date_from) {
    $base_sql .= " AND DATE(al.created_at) >= ?";
    $count_sql .= " AND DATE(al.created_at) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if ($date_to) {
    $base_sql .= " AND DATE(al.created_at) <= ?";
    $count_sql .= " AND DATE(al.created_at) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

// Order and pagination
$base_sql .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

// Get total count
$stmt = $conn->prepare($count_sql);
if (!empty($params) && $types) {
    $count_params = array_slice($params, 0, count($params) - 2);
    $count_types = substr($types, 0, -2);
    if (!empty($count_params)) {
        $stmt->bind_param($count_types, ...$count_params);
    }
}
$stmt->execute();
$count_result = $stmt->get_result();
$total_rows = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $per_page);

// Get data
$stmt = $conn->prepare($base_sql);
if (!empty($params) && $types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$logs = [];
while ($row = $result->fetch_assoc()) {
    $logs[] = $row;
}

// Get distinct modules for filter dropdown
$module_sql = "SELECT DISTINCT module FROM audit_logs WHERE module IS NOT NULL AND module != '' ORDER BY module";
$module_result = $conn->query($module_sql);

// Get users for filter dropdown
$user_sql = "SELECT DISTINCT u.user_id, u.username, r.role_name 
             FROM audit_logs al 
             INNER JOIN users u ON al.user_id = u.user_id 
             LEFT JOIN roles r ON u.role_id = r.role_id
             ORDER BY u.username";
$user_result = $conn->query($user_sql);

// Get branches for filter dropdown
$branch_sql = "SELECT DISTINCT b.branch_id, b.branch_name 
               FROM audit_logs al 
               INNER JOIN branches b ON al.branch_id = b.branch_id 
               ORDER BY b.branch_name";
$branch_result = $conn->query($branch_sql);

// Get action types for filter dropdown
$action_types = ['Add', 'Update', 'Delete', 'Archive', 'Login', 'Logout', 'Create', 'Edit', 'Remove', 'View'];

// Get statistics
$stats_sql = "SELECT 
               COUNT(*) as total_logs,
               COUNT(DISTINCT user_id) as unique_users,
               COUNT(DISTINCT module) as unique_modules,
               MAX(created_at) as latest_activity,
               MIN(created_at) as earliest_activity
              FROM audit_logs";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get module breakdown
$module_breakdown_sql = "SELECT module, COUNT(*) as count FROM audit_logs WHERE module IS NOT NULL AND module != '' GROUP BY module ORDER BY count DESC";
$module_breakdown_result = $conn->query($module_breakdown_sql);

// Build filter query string for PDF generation
$filter_params = [];
if ($search) $filter_params['search'] = $search;
if ($module_filter) $filter_params['module'] = $module_filter;
if ($user_filter > 0) $filter_params['user_id'] = $user_filter;
if ($branch_filter) $filter_params['branch_id'] = $branch_filter;
if ($action_type) $filter_params['action_type'] = $action_type;
if ($date_from) $filter_params['date_from'] = $date_from;
if ($date_to) $filter_params['date_to'] = $date_to;
$filter_params['generate_pdf'] = '1';
$pdf_url = '?' . http_build_query($filter_params);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Super Admin - Audit Logs</title>
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

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: white;
            padding: 16px 20px;
            border-radius: 14px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border-left: 4px solid var(--primary);
        }
        .stat-card h6 {
            color: #7a85a8;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }
        .stat-card h2 {
            color: var(--primary);
            font-weight: 700;
            margin: 0;
            font-size: 26px;
        }
        .stat-card .stat-sub {
            font-size: 11px;
            color: #8a96b8;
        }
        .stat-card .text-success { border-left-color: #28a745; }
        .stat-card .text-info { border-left-color: #17a2b8; }
        .stat-card .text-warning { border-left-color: #ffc107; }

        .filter-section {
            background: white;
            padding: 20px 24px;
            border-radius: 14px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 24px;
        }
        .filter-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr auto;
            gap: 14px;
            align-items: flex-end;
        }
        .filter-group label {
            font-weight: 600;
            color: var(--primary);
            font-size: 12px;
            margin-bottom: 3px;
            display: block;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .filter-group .form-control,
        .filter-group .form-select {
            border-radius: 10px;
            border: 1px solid #d0d7e8;
            padding: 8px 12px;
            font-size: 13px;
            background: white;
            outline: none;
            transition: 0.15s;
            width: 100%;
        }
        .filter-group .form-control:focus,
        .filter-group .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.12);
        }
        .filter-group .search-wrap {
            position: relative;
        }
        .filter-group .search-wrap i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #7a85a8;
            font-size: 14px;
        }
        .filter-group .search-wrap input {
            padding-left: 34px;
        }
        .btn-filter {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 8px 22px;
            font-weight: 600;
            font-size: 13px;
            transition: 0.15s;
            cursor: pointer;
            white-space: nowrap;
            height: 40px;
        }
        .btn-filter:hover {
            background: #1d2863;
            color: white;
        }
        .btn-filter-outline {
            background: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
            border-radius: 10px;
            padding: 8px 18px;
            font-weight: 600;
            font-size: 13px;
            transition: 0.15s;
            cursor: pointer;
            white-space: nowrap;
            height: 40px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }
        .btn-filter-outline:hover {
            background: var(--primary);
            color: white;
            text-decoration: none;
        }
        .btn-reset {
            background: #f0f3fc;
            color: var(--primary);
            border: 1px solid #d0d7e8;
            border-radius: 10px;
            padding: 8px 18px;
            font-weight: 600;
            font-size: 13px;
            transition: 0.15s;
            cursor: pointer;
            white-space: nowrap;
            height: 40px;
            display: inline-flex;
            align-items: center;
            text-decoration: none;
        }
        .btn-reset:hover {
            background: #e2e7f2;
            color: var(--primary);
            text-decoration: none;
        }
        .filter-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .module-breakdown {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 20px;
        }
        .module-pill {
            background: #f0f3fc;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            color: var(--primary);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .module-pill .count {
            background: var(--primary);
            color: white;
            border-radius: 50%;
            padding: 0 6px;
            font-size: 10px;
            min-width: 18px;
            text-align: center;
        }

        .table-wrap {
            background: white;
            border-radius: 14px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        .table {
            margin-bottom: 0;
        }
        .table thead th {
            background: #f0f3fc;
            color: var(--primary);
            font-weight: 700;
            font-size: 12px;
            padding: 12px 16px;
            border-bottom: 1px solid #e2e7f2;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }
        .table tbody td {
            padding: 12px 16px;
            vertical-align: middle;
            border-bottom: 1px solid #edf1f8;
            color: #1f2a4a;
            font-size: 13px;
        }
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        .table tbody tr:hover {
            background: #f8faff;
        }
        .user-tag {
            font-weight: 600;
            color: var(--primary);
        }
        .module-tag {
            display: inline-block;
            background: #e7ecfc;
            color: var(--primary);
            font-weight: 600;
            font-size: 11px;
            padding: 2px 12px;
            border-radius: 40px;
            white-space: nowrap;
        }
        .action-text {
            max-width: 350px;
            word-wrap: break-word;
            font-size: 12px;
        }
        .branch-tag {
            font-size: 11px;
            color: #6c7a9a;
            background: #f5f7fc;
            padding: 2px 10px;
            border-radius: 12px;
            display: inline-block;
        }
        .log-time {
            font-size: 12px;
            white-space: nowrap;
            color: #4a5a8c;
        }
        .role-tag {
            font-size: 10px;
            background: #e8f0fe;
            color: #1a56db;
            padding: 1px 8px;
            border-radius: 10px;
            display: inline-block;
        }

        .pagination-wrap {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 16px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .pagination-info {
            color: #6c7a9a;
            font-size: 13px;
        }
        .pagination-info strong {
            color: var(--primary);
        }
        .pagination-wrap .pagination {
            margin: 0;
        }
        .pagination-wrap .page-item .page-link {
            color: var(--primary);
            border: 1px solid #d7def0;
            border-radius: 8px;
            padding: 6px 12px;
            font-weight: 600;
            background: white;
            margin: 0 2px;
            transition: 0.1s;
            font-size: 13px;
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
        .pagination-wrap .page-item.disabled .page-link {
            color: #b0bcd6;
            background: #f5f7fc;
        }

        .no-results {
            padding: 40px 20px;
            text-align: center;
            color: #8a96b8;
        }
        .no-results i {
            font-size: 48px;
            display: block;
            margin-bottom: 12px;
            opacity: 0.5;
        }

        .report-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 16px;
        }

        @media (max-width: 1200px) {
            .filter-row {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media (max-width: 768px) {
            .filter-row {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            .filter-actions {
                display: flex;
                gap: 8px;
            }
            .filter-actions .btn-filter,
            .filter-actions .btn-reset {
                flex: 1;
                text-align: center;
                justify-content: center;
            }
            .pagination-wrap {
                flex-direction: column;
                align-items: center;
            }
            .pagination-info {
                text-align: center;
            }
            .report-actions {
                flex-direction: column;
                width: 100%;
            }
            .report-actions .btn-filter-outline {
                width: 100%;
                justify-content: center;
            }
        }
        @media (max-width: 576px) {
            .topbar {
                padding: 0 16px;
                height: 70px;
            }
            .topbar h3 {
                font-size: 20px;
            }
            .content {
                padding: 16px;
            }
            .stats-row {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
            .stat-card {
                padding: 12px 14px;
            }
            .stat-card h2 {
                font-size: 20px;
            }
            .table-wrap {
                overflow-x: auto;
            }
            .action-text {
                max-width: 120px;
            }
            .main {
                margin-left: 0;
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
            <li><a href="SuperAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
            <li><a class="active" href="SuperAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
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
        <h3>Audit Logs</h3>
        <div class="profile">SUPER ADMIN <i class="bi bi-caret-down-fill"></i></div>
    </div>

    <div class="content">
        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-card">
                <h6>Total Logs</h6>
                <h2><?php echo number_format($stats['total_logs']); ?></h2>
                <div class="stat-sub">All time</div>
            </div>
            <div class="stat-card text-success">
                <h6>Unique Users</h6>
                <h2><?php echo $stats['unique_users']; ?></h2>
                <div class="stat-sub">Who performed actions</div>
            </div>
            <div class="stat-card text-info">
                <h6>Modules</h6>
                <h2><?php echo $stats['unique_modules']; ?></h2>
                <div class="stat-sub">System modules</div>
            </div>
            <div class="stat-card text-warning">
                <h6>Earliest Activity</h6>
                <h2 style="font-size:18px;"><?php echo $stats['earliest_activity'] ? date('M d, Y', strtotime($stats['earliest_activity'])) : 'N/A'; ?></h2>
                <div class="stat-sub">First recorded log</div>
            </div>
        </div>

        <!-- Module Breakdown -->
        <?php if ($module_breakdown_result && $module_breakdown_result->num_rows > 0): ?>
        <div class="module-breakdown">
            <span style="font-weight:600;color:var(--primary);font-size:12px;margin-right:8px;">Modules:</span>
            <?php while ($row = $module_breakdown_result->fetch_assoc()): ?>
                <span class="module-pill">
                    <?php echo htmlspecialchars($row['module']); ?>
                    <span class="count"><?php echo $row['count']; ?></span>
                </span>
            <?php endwhile; ?>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="filter-section">
            <form method="GET" action="<?php echo $_SERVER['PHP_SELF']; ?>" id="filterForm">
                <div class="filter-row">
                    <div class="filter-group">
                        <label><i class="bi bi-search"></i> Search</label>
                        <div class="search-wrap">
                            <input type="text" name="search" class="form-control" placeholder="Search everything..." 
                                   value="<?php echo htmlspecialchars($search); ?>" />
                        </div>
                    </div>

                    <div class="filter-group">
                        <label>Module</label>
                        <select name="module" class="form-select" onchange="this.form.submit()">
                            <option value="">All Modules</option>
                            <?php while ($row = $module_result->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($row['module']); ?>" 
                                    <?php echo $module_filter == $row['module'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['module']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>User</label>
                        <select name="user_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">All Users</option>
                            <?php while ($row = $user_result->fetch_assoc()): ?>
                                <option value="<?php echo $row['user_id']; ?>" 
                                    <?php echo $user_filter == $row['user_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['username']); ?>
                                    <?php if ($row['role_name']): ?>
                                        (<?php echo htmlspecialchars($row['role_name']); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Branch</label>
                        <select name="branch_id" class="form-select" onchange="this.form.submit()">
                            <option value="">All Branches</option>
                            <?php while ($row = $branch_result->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($row['branch_id']); ?>" 
                                    <?php echo $branch_filter == $row['branch_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['branch_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Action Type</label>
                        <select name="action_type" class="form-select" onchange="this.form.submit()">
                            <option value="">All Actions</option>
                            <?php foreach ($action_types as $type): ?>
                                <option value="<?php echo $type; ?>" 
                                    <?php echo $action_type == $type ? 'selected' : ''; ?>>
                                    <?php echo $type; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group" style="display:flex; flex-direction:column; gap:6px;">
                        <div style="display:flex; gap:6px;">
                            <input type="date" name="date_from" class="form-control" style="flex:1;" 
                                   value="<?php echo htmlspecialchars($date_from); ?>" />
                            <span style="align-self:center;color:#8a96b8;">to</span>
                            <input type="date" name="date_to" class="form-control" style="flex:1;" 
                                   value="<?php echo htmlspecialchars($date_to); ?>" />
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn-filter">
                                <i class="bi bi-funnel me-1"></i> Apply
                            </button>
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn-reset">
                                <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Report Actions -->
        <div class="report-actions">
            <a href="<?php echo $pdf_url; ?>" class="btn-filter-outline" target="_blank">
                <i class="bi bi-file-earmark-pdf"></i> Generate PDF Report
            </a>
            <span style="font-size:12px;color:#8a96b8;">
                <i class="bi bi-info-circle"></i> 
                <?php if ($search || $module_filter || $user_filter > 0 || $branch_filter || $action_type || $date_from || $date_to): ?>
                    Report includes current filters (<?php echo number_format($total_rows); ?> records)
                <?php else: ?>
                    Report includes all <?php echo number_format($total_rows); ?> records
                <?php endif; ?>
            </span>
        </div>

        <!-- Table -->
        <div class="table-wrap">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th style="width:150px;">Date &amp; Time</th>
                        <th style="width:130px;">User / Role</th>
                        <th style="width:120px;">Module</th>
                        <th>Action Details</th>
                        <th style="width:110px;">Branch</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($logs) > 0): ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="log-time">
                                    <?php echo date('M d, Y', strtotime($log['created_at'])); ?>
                                    <br />
                                    <small><?php echo date('h:i A', strtotime($log['created_at'])); ?></small>
                                </td>
                                <td>
                                    <?php if ($log['username']): ?>
                                        <span class="user-tag"><?php echo htmlspecialchars($log['username']); ?></span>
                                        <br />
                                        <span class="role-tag"><?php echo htmlspecialchars($log['role_name'] ?? 'Unknown'); ?></span>
                                        <br />
                                        <small style="color:#8a96b8;font-size:10px;">ID: <?php echo $log['user_id']; ?></small>
                                    <?php else: ?>
                                        <span style="color:#8a96b8;font-size:12px;">System</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="module-tag"><?php echo htmlspecialchars($log['module'] ?: 'N/A'); ?></span>
                                </td>
                                <td class="action-text">
                                    <?php echo htmlspecialchars($log['action']); ?>
                                </td>
                                <td>
                                    <?php if ($log['branch_name']): ?>
                                        <span class="branch-tag">
                                            <i class="bi bi-building me-1"></i>
                                            <?php echo htmlspecialchars($log['branch_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#b0bcd6;font-size:11px;">N/A</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5">
                                <div class="no-results">
                                    <i class="bi bi-inbox"></i>
                                    <p>No audit logs found matching your filters.</p>
                                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i> Clear Filters
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination-wrap">
                <div class="pagination-info">
                    Showing <strong><?php echo ($offset + 1); ?></strong> to 
                    <strong><?php echo min($offset + $per_page, $total_rows); ?></strong> 
                    of <strong><?php echo number_format($total_rows); ?></strong> entries
                </div>
                <nav aria-label="Audit logs pagination">
                    <ul class="pagination">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link"><i class="bi bi-chevron-left"></i></span>
                            </li>
                        <?php endif; ?>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        if ($start_page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">1</a>
                            </li>
                            <?php if ($start_page > 2): ?>
                                <li class="page-item disabled"><span class="page-link">…</span></li>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">…</span></li>
                            <?php endif; ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">
                                    <?php echo $total_pages; ?>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link"><i class="bi bi-chevron-right"></i></span>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        <?php else: ?>
            <?php if ($total_rows > 0): ?>
                <div class="pagination-wrap">
                    <div class="pagination-info">
                        Showing <strong>1</strong> to <strong><?php echo $total_rows; ?></strong> 
                        of <strong><?php echo number_format($total_rows); ?></strong> entries
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.filter-group select, .filter-group input[type="date"]').forEach(function(element) {
    element.addEventListener('change', function() {
        if (this.type !== 'date') {
            document.getElementById('filterForm').submit();
        }
    });
});

let dateTimeout;
document.querySelectorAll('.filter-group input[type="date"]').forEach(function(element) {
    element.addEventListener('change', function() {
        clearTimeout(dateTimeout);
        dateTimeout = setTimeout(function() {
            document.getElementById('filterForm').submit();
        }, 500);
    });
});

document.querySelector('.search-wrap input')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        document.getElementById('filterForm').submit();
    }
});
</script>
</body>
</html>