<?php
session_start();
require_once 'sources/db_connect.php';
require_once('fpdf/fpdf.php');

// Check if user is logged in and is branch admin
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 2
) {
    header("Location: login.php");
    exit();
}

// Get user branch information
$user_id = $_SESSION['user_id'];
$branch_id = null;
$branch_name = '';
$username = '';

// Fetch user's branch info
$userQuery = "SELECT u.branch_id, b.branch_name, u.username 
              FROM users u 
              LEFT JOIN branches b ON u.branch_id = b.branch_id 
              WHERE u.user_id = ?";
$stmt = $conn->prepare($userQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$userResult = $stmt->get_result();

if ($userResult->num_rows > 0) {
    $userData = $userResult->fetch_assoc();
    $branch_id = $userData['branch_id'];
    $branch_name = $userData['branch_name'] ?? 'Unknown Branch';
    $username = $userData['username'] ?? 'Admin';
}

// If no branch assigned, show error or redirect
if (!$branch_id) {
    $error_message = "No branch assigned to this user account.";
}

// ============================================
// GENERATE PDF REPORT - BRANCH ADMIN VERSION
// ============================================
if (isset($_GET['generate_pdf']) && $_GET['generate_pdf'] == '1') {
    // Clear all output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Start clean output buffer
    ob_start();
    
    try {
        // Get filter parameters
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $module_filter = isset($_GET['module']) ? trim($_GET['module']) : '';
        $action_filter = isset($_GET['action']) ? trim($_GET['action']) : '';
        $date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
        
        // Build WHERE clause for branch admin
        $where_conditions = ["al.branch_id = ?"];
        $params = [$branch_id];
        $types = "s";
        
        if (!empty($search)) {
            $where_conditions[] = "(al.action LIKE ? OR al.module LIKE ? OR u.username LIKE ?)";
            $search_param = "%$search%";
            $types .= "sss";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
        }
        
        if (!empty($module_filter)) {
            $where_conditions[] = "al.module = ?";
            $types .= "s";
            $params[] = $module_filter;
        }
        
        if (!empty($action_filter)) {
            $where_conditions[] = "al.action LIKE ?";
            $types .= "s";
            $params[] = "%$action_filter%";
        }
        
        if (!empty($date_from)) {
            $where_conditions[] = "DATE(al.created_at) >= ?";
            $types .= "s";
            $params[] = $date_from;
        }
        
        if (!empty($date_to)) {
            $where_conditions[] = "DATE(al.created_at) <= ?";
            $types .= "s";
            $params[] = $date_to;
        }
        
        $where_clause = implode(" AND ", $where_conditions);
        
        $query = "SELECT al.log_id, al.action, al.module, al.created_at,
                  u.user_id, u.username, u.email, u.role_id, r.role_name,
                  b.branch_id, b.branch_name 
                  FROM audit_logs al 
                  LEFT JOIN users u ON al.user_id = u.user_id 
                  LEFT JOIN roles r ON u.role_id = r.role_id
                  LEFT JOIN branches b ON al.branch_id = b.branch_id 
                  WHERE $where_clause
                  ORDER BY al.created_at DESC 
                  LIMIT 2000";
        
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
        
        // PDF Class for Branch Admin
        class PDF_BranchAuditReport extends FPDF
        {
            private $filters;
            private $branch_name;
            
            function __construct($filters = [], $branch_name = '')
            {
                parent::__construct('L', 'mm', 'A4');
                $this->filters = $filters;
                $this->branch_name = $branch_name;
                $this->SetMargins(15, 15, 15);
                $this->SetAutoPageBreak(true, 25);
                $this->AddPage();
            }

            function Header()
            {
                $this->SetFillColor(240, 244, 255);
                $this->Rect(0, 0, 297, 55, 'F');
                
                // Logo
                $logoPath = 'logo.png';
                if (file_exists($logoPath)) {
                    $this->Image($logoPath, 15, 6, 25);
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
                $this->Cell(0, 7, 'BRANCH AUDIT LOGS REPORT', 0, 1, 'C');
                
                // Branch name
                $this->SetY(41);
                $this->SetFont('Arial', 'B', 10);
                $this->SetTextColor(43, 58, 140);
                $this->Cell(0, 5, 'Branch: ' . $this->branch_name, 0, 1, 'C');
                
                // Filter summary
                $this->SetY(48);
                $this->SetFont('Arial', '', 8);
                $this->SetTextColor(80, 80, 80);
                
                $filterText = '';
                if (!empty($this->filters['date_from']) && !empty($this->filters['date_to'])) {
                    $filterText .= 'Date: ' . $this->filters['date_from'] . ' to ' . $this->filters['date_to'];
                } else if (!empty($this->filters['date_from'])) {
                    $filterText .= 'Date From: ' . $this->filters['date_from'];
                } else if (!empty($this->filters['date_to'])) {
                    $filterText .= 'Date To: ' . $this->filters['date_to'];
                }
                if (!empty($this->filters['module'])) {
                    if ($filterText) $filterText .= ' | ';
                    $filterText .= 'Module: ' . $this->filters['module'];
                }
                if (!empty($this->filters['action'])) {
                    if ($filterText) $filterText .= ' | ';
                    $filterText .= 'Action: ' . $this->filters['action'];
                }
                if (!empty($this->filters['search'])) {
                    if ($filterText) $filterText .= ' | ';
                    $filterText .= 'Search: "' . $this->filters['search'] . '"';
                }
                if (empty($filterText)) {
                    $filterText = 'All Logs';
                }
                
                $this->Cell(0, 4, 'Filters: ' . $filterText, 0, 1, 'C');
                
                $this->SetY(58);
                $this->SetDrawColor(200, 200, 200);
                $this->SetLineWidth(0.3);
                $this->Line(15, 58, 282, 58);
                $this->SetY(63);
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
                $this->Cell(0, 5, 'BRANCH SUMMARY', 0, 1, 'L');
                
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
                    $this->Cell(45, (int)6, $items[$i]['key'] . ':', 0, 0, 'L');
                    
                    $this->SetFont('Arial', 'B', 9);
                    $this->SetTextColor(43, 58, 140);
                    $this->Cell($col_width - 50, (int)6, $items[$i]['value'], 0, 0, 'L');
                }
                
                // Column 2
                for ($i = $half; $i < count($items); $i++) {
                    $idx = $i - $half;
                    $this->SetXY($col2_x, $currentY + ($idx * $row_height));
                    $this->SetFont('Arial', 'B', 8);
                    $this->SetTextColor(80, 80, 80);
                    $this->Cell(45, (int)6, $items[$i]['key'] . ':', 0, 0, 'L');
                    
                    $this->SetFont('Arial', 'B', 9);
                    $this->SetTextColor(43, 58, 140);
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
        
        // Prepare filter display
        $filterDisplay = [];
        if ($date_from && $date_to) {
            $filterDisplay['date_from'] = $date_from;
            $filterDisplay['date_to'] = $date_to;
        } else if ($date_from) {
            $filterDisplay['date_from'] = $date_from;
        } else if ($date_to) {
            $filterDisplay['date_to'] = $date_to;
        }
        if ($module_filter) {
            $filterDisplay['module'] = $module_filter;
        }
        if ($action_filter) {
            $filterDisplay['action'] = $action_filter;
        }
        if ($search) {
            $filterDisplay['search'] = $search;
        }
        
        $pdf = new PDF_BranchAuditReport($filterDisplay, $branch_name);
        
        // Calculate stats
        $moduleList = array_filter(array_column($logs, 'module'), function($val) {
            return $val !== null && $val !== '';
        });
        $moduleCount = array_count_values($moduleList);
        arsort($moduleCount);
        $topModules = array_slice($moduleCount, 0, 3);
        
        $topModulesStr = '';
        if (!empty($topModules)) {
            $topModulesParts = array_map(function($k, $v) { 
                return "$k ($v)"; 
            }, array_keys($topModules), $topModules);
            $topModulesStr = implode(', ', $topModulesParts);
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
        
        // Get action types count
        $actionTypes = [];
        foreach ($logs as $log) {
            $action = $log['action'] ?: 'Unknown';
            $actionWords = explode(' ', $action);
            $actionType = $actionWords[0] ?? 'Unknown';
            if (!isset($actionTypes[$actionType])) {
                $actionTypes[$actionType] = 0;
            }
            $actionTypes[$actionType]++;
        }
        arsort($actionTypes);
        $topActions = array_slice($actionTypes, 0, 3);
        $topActionsStr = '';
        if (!empty($topActions)) {
            $topActionsParts = array_map(function($k, $v) { 
                return "$k ($v)"; 
            }, array_keys($topActions), $topActions);
            $topActionsStr = implode(', ', $topActionsParts);
            if (strlen($topActionsStr) > 40) {
                $topActionsStr = substr($topActionsStr, 0, 37) . '...';
            }
        } else {
            $topActionsStr = 'None';
        }
        
        $pdf->CreateStatsBox([
            'Total Logs' => count($logs),
            'Unique Users' => $uniqueUsers,
            'Modules Used' => count($moduleCount),
            'Top Actions' => $topActionsStr
        ]);
        
        // Table columns - adjusted for branch admin view
        $headers = ['Date/Time', 'User', 'Role', 'Module', 'Action'];
        $columnWidths = [35, 35, 28, 38, 110];
        
        $data = array_map(function($log) {
            $action = $log['action'] ?: 'N/A';
            if (strlen($action) > 80) {
                $action = wordwrap($action, 75, "\n", true);
            }
            
            $module = $log['module'] ?: 'N/A';
            if (strlen($module) > 20) {
                $module = wordwrap($module, 18, "\n", true);
            }
            
            return [
                date('Y-m-d H:i', strtotime($log['created_at'])),
                substr($log['username'] ?: 'System', 0, 15),
                $log['role_name'] ?: 'N/A',
                $module,
                $action
            ];
        }, $logs);
        
        if (empty($data)) {
            $data = [['No records found', '', '', '', '']];
        }
        
        $pdf->CreateTable($headers, $data, $columnWidths, 7);
        
        $pdf->AliasNbPages();
        
        // Clean buffer and output PDF
        ob_clean();
        $pdf->Output('D', 'Branch_Audit_Logs_' . date('Y-m-d') . '.pdf');
        exit;
        
    } catch (Exception $e) {
        ob_clean();
        die('PDF Generation Error: ' . $e->getMessage());
    }
}

// ============================================
// CSV EXPORT - BRANCH ADMIN VERSION
// ============================================
if (isset($_GET['export_csv']) && $_GET['export_csv'] == '1') {
    // Clear all output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    try {
        // Get filter parameters
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $module_filter = isset($_GET['module']) ? trim($_GET['module']) : '';
        $action_filter = isset($_GET['action']) ? trim($_GET['action']) : '';
        $date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
        
        // Build WHERE clause for branch admin
        $where_conditions = ["al.branch_id = ?"];
        $params = [$branch_id];
        $types = "s";
        
        if (!empty($search)) {
            $where_conditions[] = "(al.action LIKE ? OR al.module LIKE ? OR u.username LIKE ?)";
            $search_param = "%$search%";
            $types .= "sss";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
        }
        
        if (!empty($module_filter)) {
            $where_conditions[] = "al.module = ?";
            $types .= "s";
            $params[] = $module_filter;
        }
        
        if (!empty($action_filter)) {
            $where_conditions[] = "al.action LIKE ?";
            $types .= "s";
            $params[] = "%$action_filter%";
        }
        
        if (!empty($date_from)) {
            $where_conditions[] = "DATE(al.created_at) >= ?";
            $types .= "s";
            $params[] = $date_from;
        }
        
        if (!empty($date_to)) {
            $where_conditions[] = "DATE(al.created_at) <= ?";
            $types .= "s";
            $params[] = $date_to;
        }
        
        $where_clause = implode(" AND ", $where_conditions);
        
        $query = "SELECT al.log_id, al.action, al.module, al.created_at,
                  u.username, u.role_id, r.role_name,
                  b.branch_name 
                  FROM audit_logs al 
                  LEFT JOIN users u ON al.user_id = u.user_id 
                  LEFT JOIN roles r ON u.role_id = r.role_id
                  LEFT JOIN branches b ON al.branch_id = b.branch_id 
                  WHERE $where_clause
                  ORDER BY al.created_at DESC 
                  LIMIT 10000";
        
        $stmt = $conn->prepare($query);
        if (!empty($params) && $types) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        // Set CSV headers
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Branch_Audit_Logs_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        
        // Headers
        fputcsv($output, ['#', 'Date/Time', 'User', 'Role', 'Module', 'Action', 'Branch']);
        
        // Data
        $counter = 1;
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                $counter++,
                date('Y-m-d H:i:s', strtotime($row['created_at'])),
                $row['username'] ?: 'System',
                $row['role_name'] ?: 'N/A',
                $row['module'] ?: 'N/A',
                $row['action'] ?: 'N/A',
                $row['branch_name'] ?: 'N/A'
            ]);
        }
        
        fclose($output);
        exit;
        
    } catch (Exception $e) {
        die('CSV Export Error: ' . $e->getMessage());
    }
}

// Pagination settings
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Filter settings
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_module = isset($_GET['module']) ? trim($_GET['module']) : '';
$filter_action = isset($_GET['action']) ? trim($_GET['action']) : '';
$filter_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filter_date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Build WHERE clause for filtering
$where_conditions = ["a.branch_id = ?"];
$params = [$branch_id];
$types = "s";

if (!empty($search)) {
    $where_conditions[] = "(a.action LIKE ? OR a.module LIKE ? OR u.username LIKE ?)";
    $search_param = "%$search%";
    $types .= "sss";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($filter_module)) {
    $where_conditions[] = "a.module = ?";
    $types .= "s";
    $params[] = $filter_module;
}

if (!empty($filter_action)) {
    $where_conditions[] = "a.action LIKE ?";
    $types .= "s";
    $params[] = "%$filter_action%";
}

if (!empty($filter_date_from)) {
    $where_conditions[] = "DATE(a.created_at) >= ?";
    $types .= "s";
    $params[] = $filter_date_from;
}

if (!empty($filter_date_to)) {
    $where_conditions[] = "DATE(a.created_at) <= ?";
    $types .= "s";
    $params[] = $filter_date_to;
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count for pagination
$countQuery = "SELECT COUNT(*) as total 
               FROM audit_logs a
               LEFT JOIN users u ON a.user_id = u.user_id
               WHERE $where_clause";

$stmt = $conn->prepare($countQuery);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$countResult = $stmt->get_result();
$total_records = $countResult->fetch_assoc()['total'] ?? 0;
$total_pages = ceil($total_records / $per_page);

// Fetch audit logs for current page with branch filtering
$query = "SELECT a.*, u.username 
          FROM audit_logs a
          LEFT JOIN users u ON a.user_id = u.user_id
          WHERE $where_clause
          ORDER BY a.created_at DESC
          LIMIT ? OFFSET ?";

$stmt = $conn->prepare($query);
// Add limit and offset to params
$types_limit = $types . "ii";
$params_limit = array_merge($params, [$per_page, $offset]);

if (!empty($params_limit)) {
    $stmt->bind_param($types_limit, ...$params_limit);
}
$stmt->execute();
$result = $stmt->get_result();

$audit_logs = [];
while ($row = $result->fetch_assoc()) {
    $audit_logs[] = $row;
}

// Get unique modules for filter dropdown (with standardization)
$moduleQuery = "SELECT DISTINCT 
                CASE 
                    WHEN LOWER(module) LIKE '%patient%' THEN 'Patient Records'
                    WHEN LOWER(module) LIKE '%inventory%' THEN 'Inventory Management'
                    WHEN LOWER(module) LIKE '%user%' OR LOWER(module) LIKE '%staff%' THEN 'User Management'
                    WHEN LOWER(module) LIKE '%prediction%' THEN 'Prediction Module'
                    WHEN LOWER(module) LIKE '%vaccination%' OR LOWER(module) LIKE '%registry%' THEN 'Vaccination Records'
                    WHEN LOWER(module) LIKE '%document%' THEN 'Document Management'
                    WHEN LOWER(module) LIKE '%philhealth%' THEN 'PhilHealth Records'
                    WHEN LOWER(module) LIKE '%audit%' THEN 'Audit System'
                    WHEN LOWER(module) LIKE '%login%' OR LOWER(module) LIKE '%system%' THEN 'System'
                    ELSE module 
                END as module_standardized
                FROM audit_logs 
                WHERE branch_id = ? 
                GROUP BY module_standardized
                ORDER BY module_standardized";

$stmt = $conn->prepare($moduleQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$moduleResult = $stmt->get_result();
$modules = [];
while ($row = $moduleResult->fetch_assoc()) {
    if (!empty($row['module_standardized'])) {
        $modules[] = $row['module_standardized'];
    }
}

// Get recent activity summary for the branch
$summaryQuery = "SELECT 
                    COUNT(*) as total_activities,
                    COUNT(DISTINCT user_id) as unique_users,
                    MAX(created_at) as last_activity
                 FROM audit_logs 
                 WHERE branch_id = ?";
$stmt = $conn->prepare($summaryQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$summaryResult = $stmt->get_result();
$summary = $summaryResult->fetch_assoc();

// Get module distribution for this branch (with standardization)
$moduleDistQuery = "SELECT 
                    CASE 
                        WHEN LOWER(module) LIKE '%patient%' THEN 'Patient Records'
                        WHEN LOWER(module) LIKE '%inventory%' THEN 'Inventory Management'
                        WHEN LOWER(module) LIKE '%user%' OR LOWER(module) LIKE '%staff%' THEN 'User Management'
                        WHEN LOWER(module) LIKE '%prediction%' THEN 'Prediction Module'
                        WHEN LOWER(module) LIKE '%vaccination%' OR LOWER(module) LIKE '%registry%' THEN 'Vaccination Records'
                        WHEN LOWER(module) LIKE '%document%' THEN 'Document Management'
                        WHEN LOWER(module) LIKE '%philhealth%' THEN 'PhilHealth Records'
                        WHEN LOWER(module) LIKE '%audit%' THEN 'Audit System'
                        WHEN LOWER(module) LIKE '%login%' OR LOWER(module) LIKE '%system%' THEN 'System'
                        ELSE module 
                    END as module_standardized, 
                    COUNT(*) as count 
                    FROM audit_logs 
                    WHERE branch_id = ? 
                    GROUP BY module_standardized 
                    ORDER BY count DESC 
                    LIMIT 5";
$stmt = $conn->prepare($moduleDistQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$moduleDistResult = $stmt->get_result();
$moduleDistribution = [];
while ($row = $moduleDistResult->fetch_assoc()) {
    if (!empty($row['module_standardized'])) {
        $moduleDistribution[] = ['module' => $row['module_standardized'], 'count' => $row['count']];
    }
}

// Action types for filter dropdown
$actionTypes = ['Create', 'Update', 'Delete', 'Login', 'Logout', 'View', 'Export', 'Import'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Branch Admin - Audit Logs</title>
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

        .topbar h3 small {
            font-size: 16px;
            font-weight: 400;
            color: #666;
            margin-left: 10px;
        }

        .profile {
            font-weight: 600;
            color: var(--primary);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .content-wrapper {
            padding: 28px 35px 40px 35px;
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: #ECEEF7;
            border-radius: 14px;
            padding: 18px 22px;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }

        .summary-card .label {
            font-size: 13px;
            color: #666;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .summary-card .value {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin-top: 5px;
        }

        .summary-card .sub {
            font-size: 12px;
            color: #888;
            margin-top: 3px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .search-box {
            display: flex;
            align-items: center;
            background: white;
            border: 2px solid var(--primary);
            border-radius: 50px;
            padding: 6px 18px;
            min-width: 250px;
        }

        .search-box i {
            color: var(--primary);
            font-size: 18px;
            margin-right: 10px;
        }

        .search-box input {
            border: none;
            outline: none;
            background: transparent;
            color: black;
            width: 100%;
            font-size: 14px;
        }

        .search-box input::placeholder {
            color: rgba(0, 0, 0, 0.5);
        }

        .btn-filter {
            background: var(--primary);
            color: #fff;
            border: 2px solid var(--primary);
            border-radius: 8px;
            padding: 8px 18px;
            font-weight: 600;
            transition: .2s;
        }

        .btn-filter:hover,
        .btn-filter:focus,
        .btn-filter:active,
        .btn-filter.show {
            background: #1f2d6e;
            color: #fff;
            border-color: #1f2d6e;
            box-shadow: none;
        }

        .btn-filter i {
            margin-right: 8px;
        }

        .btn-filter-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
            border-radius: 8px;
            padding: 8px 18px;
            font-weight: 600;
            transition: .2s;
            text-decoration: none;
        }

        .btn-filter-outline:hover {
            background: var(--primary);
            color: #fff;
            text-decoration: none;
        }

        .filter-dropdown {
            border: 1px solid #d9dee8;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,.15);
            padding: 10px;
            min-width: 200px;
        }

        .filter-dropdown .dropdown-item {
            padding: 10px 15px;
            border-radius: 8px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-dropdown .dropdown-item:hover {
            background: #f3f5fb;
        }

        .filter-dropdown i {
            color: var(--primary);
            font-size: 16px;
        }

        .filter-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: none;
            border: 1px solid #e9edf4;
        }

        .filter-section.show {
            display: block;
        }

        .filter-section .row {
            align-items: end;
        }

        .filter-section label {
            font-weight: 600;
            font-size: 13px;
            color: var(--primary);
            margin-bottom: 5px;
        }

        .filter-section .form-control,
        .filter-section .form-select {
            border: 2px solid #d9dee8;
            border-radius: 8px;
            padding: 8px 14px;
            font-size: 14px;
        }

        .filter-section .form-control:focus,
        .filter-section .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(43, 58, 140, 0.15);
        }

        .table-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
            overflow: hidden;
        }

        .table-card .table {
            margin: 0;
            border: 1px solid #d9dee8;
        }

        .table-card .table thead th {
            background: var(--primary);
            color: #fff;
            padding: 14px 18px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: 2px solid #e9edf4;
            white-space: nowrap;
        }

        .table-card .table tbody td {
            padding: 12px 18px;
            color: #1e293b;
            border-bottom: 1px solid #f0f2f7;
            vertical-align: middle;
        }

        .table-card .table tbody tr:hover {
            background: #f8faff;
        }

        .badge-module {
            background: #eef2ff;
            color: var(--primary);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-action {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-action.create {
            background: #d4edda;
            color: #155724;
        }

        .badge-action.update {
            background: #fff3cd;
            color: #856404;
        }

        .badge-action.delete {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-action.login,
        .badge-action.logout {
            background: #d1ecf1;
            color: #0c5460;
        }

        .badge-action.view {
            background: #e2e3e5;
            color: #383d41;
        }

        .badge-action.export,
        .badge-action.import {
            background: #d6d8db;
            color: #1b1e21;
        }

        .pagination-wrap {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .pagination-wrap .info-text {
            color: #666;
            font-size: 14px;
        }

        .pagination-wrap .pagination {
            margin: 0;
            gap: 2px;
        }

        .pagination-wrap .page-link {
            border: none;
            color: #2d3a7a;
            font-weight: 600;
            font-size: 14px;
            padding: 8px 15px;
            border-radius: 8px;
            background: transparent;
            transition: background 0.15s, color 0.15s;
        }

        .pagination-wrap .page-link:hover {
            background: #eef2ff;
            color: var(--primary);
        }

        .pagination-wrap .page-item.active .page-link {
            background: var(--primary);
            color: #fff;
            border-radius: 8px;
        }

        .pagination-wrap .page-item.disabled .page-link {
            color: #b0b8c8;
            opacity: 0.6;
        }

        .pagination-wrap .page-item:first-child .page-link,
        .pagination-wrap .page-item:last-child .page-link {
            font-size: 16px;
            padding: 8px 12px;
        }

        .no-records {
            text-align: center;
            padding: 40px 20px;
            color: #888;
        }

        .no-records i {
            font-size: 48px;
            color: #ddd;
            margin-bottom: 15px;
            display: block;
        }

        .module-distribution {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .module-tag {
            background: #eef2ff;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            color: var(--primary);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .module-tag .count {
            background: var(--primary);
            color: white;
            border-radius: 50%;
            padding: 0 8px;
            font-size: 11px;
            font-weight: 700;
        }

        @media (max-width: 768px) {
            .main {
                margin-left: 90px;
            }

            .content-wrapper {
                padding: 18px 16px 30px 16px;
            }

            .page-header {
                flex-direction: column;
                align-items: stretch;
                gap: 14px;
            }

            .header-left {
                flex-direction: column;
                width: 100%;
            }

            .search-box {
                width: 100%;
                min-width: unset;
            }

            .summary-cards {
                grid-template-columns: repeat(2, 1fr);
            }

            .table-card .table thead th,
            .table-card .table tbody td {
                padding: 10px 12px;
                font-size: 12px;
            }

            .pagination-wrap {
                flex-direction: column;
                align-items: center;
            }
        }

        @media (max-width: 576px) {
            .summary-cards {
                grid-template-columns: 1fr;
            }

            .table-card .table thead th {
                font-size: 10px;
                padding: 8px 8px;
            }

            .table-card .table tbody td {
                font-size: 11px;
                padding: 8px 8px;
            }

            .filter-section .row > div {
                margin-bottom: 10px;
            }
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
                <li><a href="BranchAdmin_PredictionModule.php"><i class="bi bi-graph-up-arrow"></i><span>Prediction Module</span></a></li>
                <li><a href="BranchAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
                <li><a class="active" href="BranchAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
                <li><a href="BranchAdmin_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
                <li><a href="BranchAdmin_Settings.php"><i class="bi bi-gear-fill"></i><span>Settings</span></a></li>
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
            <h3>Audit Logs <small><?php echo htmlspecialchars($branch_name); ?></small></h3>
            <div class="profile">
                <i class="bi bi-person-circle"></i>
                <?php echo htmlspecialchars($username); ?>
                <i class="bi bi-caret-down-fill"></i>
            </div>
        </div>

        <div class="content-wrapper">
            <!-- Summary Cards -->
            <div class="summary-cards">
                <div class="summary-card">
                    <div class="label">Total Activities</div>
                    <div class="value"><?php echo number_format($summary['total_activities'] ?? 0); ?></div>
                    <div class="sub">All time for this branch</div>
                </div>
                <div class="summary-card">
                    <div class="label">Unique Users</div>
                    <div class="value"><?php echo number_format($summary['unique_users'] ?? 0); ?></div>
                    <div class="sub">Users who performed actions</div>
                </div>
                <div class="summary-card">
                    <div class="label">Last Activity</div>
                    <div class="value" style="font-size: 20px;">
                        <?php 
                        if (!empty($summary['last_activity'])) {
                            echo date('M d, Y', strtotime($summary['last_activity']));
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </div>
                    <div class="sub">
                        <?php 
                        if (!empty($summary['last_activity'])) {
                            echo date('h:i A', strtotime($summary['last_activity']));
                        }
                        ?>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="label">Modules Used</div>
                    <div class="value"><?php echo number_format(count($modules)); ?></div>
                    <div class="sub">Active modules in this branch</div>
                </div>
            </div>

            <!-- Module Distribution -->
            <?php if (!empty($moduleDistribution)): ?>
            <div class="module-distribution">
                <?php foreach ($moduleDistribution as $mod): ?>
                <span class="module-tag">
                    <?php echo htmlspecialchars($mod['module']); ?>
                    <span class="count"><?php echo $mod['count']; ?></span>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="page-header">
                <div class="header-left">
                    <!-- Search -->
                    <form method="GET" action="" style="display: flex; gap: 8px; flex-wrap: wrap; width: 100%;" id="searchForm">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" name="search" placeholder="Search logs..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <!-- Toggle Filter Button -->
                        <button type="button" class="btn-filter-outline" onclick="toggleFilters()">
                            <i class="bi bi-funnel"></i> Filters
                        </button>
                        
                        <button type="submit" class="btn-filter" style="padding: 8px 20px;">
                            <i class="bi bi-search"></i> Search
                        </button>
                        
                        <?php if (!empty($search) || !empty($filter_module) || !empty($filter_action) || 
                                  !empty($filter_date_from) || !empty($filter_date_to)): ?>
                        <a href="BranchAdmin_AuditLogs.php" class="btn-filter-outline" style="text-decoration: none;">
                            <i class="bi bi-x-circle"></i> Clear
                        </a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Export -->
                <div class="dropdown">
                    <button class="btn btn-filter dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-file-earmark-arrow-down-fill"></i>
                        Export
                    </button>
                    <ul class="dropdown-menu filter-dropdown">
                        <li>
                            <a class="dropdown-item" href="#" onclick="exportLogs('csv')">
                                <i class="bi bi-filetype-csv"></i>
                                Export as CSV
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="#" onclick="exportLogs('pdf')">
                                <i class="bi bi-filetype-pdf"></i>
                                Export as PDF
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Advanced Filters Section -->
            <div class="filter-section <?php echo (!empty($filter_module) || !empty($filter_action) || 
                                             !empty($filter_date_from) || !empty($filter_date_to)) ? 'show' : ''; ?>" 
                 id="filterSection">
                <form method="GET" action="" id="filterForm">
                    <?php if (!empty($search)): ?>
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <?php endif; ?>
                    
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label>Module</label>
                            <select name="module" class="form-select">
                                <option value="">All Modules</option>
                                <?php foreach ($modules as $mod): ?>
                                <option value="<?php echo htmlspecialchars($mod); ?>" 
                                        <?php echo ($filter_module == $mod) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($mod); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label>Action</label>
                            <select name="action" class="form-select">
                                <option value="">All Actions</option>
                                <?php foreach ($actionTypes as $action): ?>
                                <option value="<?php echo $action; ?>" 
                                        <?php echo ($filter_action == $action) ? 'selected' : ''; ?>>
                                    <?php echo $action; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>Date From</label>
                            <input type="date" name="date_from" class="form-control" 
                                   value="<?php echo htmlspecialchars($filter_date_from); ?>">
                        </div>
                        <div class="col-md-2">
                            <label>Date To</label>
                            <input type="date" name="date_to" class="form-control" 
                                   value="<?php echo htmlspecialchars($filter_date_to); ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn-filter" style="width: 100%;">
                                <i class="bi bi-filter"></i> Apply Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Table -->
            <div class="table-card">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>User</th>
                                <th>Module</th>
                                <th>Action</th>
                                <th>Date & Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($audit_logs)): ?>
                            <tr>
                                <td colspan="5">
                                    <div class="no-records">
                                        <i class="bi bi-inbox"></i>
                                        <h5>No audit logs found</h5>
                                        <p>No activities have been recorded for this branch yet.</p>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php 
                                $counter = $offset + 1;
                                foreach ($audit_logs as $log): 
                                    $action_lower = strtolower($log['action']);
                                    $action_class = '';
                                    if (strpos($action_lower, 'create') !== false) $action_class = 'create';
                                    elseif (strpos($action_lower, 'update') !== false) $action_class = 'update';
                                    elseif (strpos($action_lower, 'delete') !== false) $action_class = 'delete';
                                    elseif (strpos($action_lower, 'login') !== false) $action_class = 'login';
                                    elseif (strpos($action_lower, 'logout') !== false) $action_class = 'logout';
                                    elseif (strpos($action_lower, 'view') !== false) $action_class = 'view';
                                    elseif (strpos($action_lower, 'export') !== false || strpos($action_lower, 'import') !== false) 
                                        $action_class = 'export';
                                    
                                    // Standardize module name for display
                                    $display_module = $log['module'] ?? 'N/A';
                                    $module_lower = strtolower($display_module);
                                    if (strpos($module_lower, 'patient') !== false) {
                                        $display_module = 'Patient Records';
                                    } elseif (strpos($module_lower, 'inventory') !== false) {
                                        $display_module = 'Inventory Management';
                                    } elseif (strpos($module_lower, 'user') !== false || strpos($module_lower, 'staff') !== false) {
                                        $display_module = 'User Management';
                                    } elseif (strpos($module_lower, 'prediction') !== false) {
                                        $display_module = 'Prediction Module';
                                    } elseif (strpos($module_lower, 'vaccination') !== false || strpos($module_lower, 'registry') !== false) {
                                        $display_module = 'Vaccination Records';
                                    } elseif (strpos($module_lower, 'document') !== false) {
                                        $display_module = 'Document Management';
                                    } elseif (strpos($module_lower, 'philhealth') !== false) {
                                        $display_module = 'PhilHealth Records';
                                    } elseif (strpos($module_lower, 'audit') !== false) {
                                        $display_module = 'Audit System';
                                    } elseif (strpos($module_lower, 'login') !== false || strpos($module_lower, 'system') !== false) {
                                        $display_module = 'System';
                                    }
                                ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($log['username'] ?? 'Unknown User'); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge-module"><?php echo htmlspecialchars($display_module); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge-action <?php echo $action_class; ?>">
                                            <?php echo htmlspecialchars($log['action']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <div class="pagination-wrap">
                <div class="info-text">
                    Showing <?php echo $offset + 1; ?> - 
                    <?php echo min($offset + $per_page, $total_records); ?> 
                    of <?php echo number_format($total_records); ?> entries
                </div>
                
                <nav aria-label="Audit log pagination">
                    <ul class="pagination">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&module=<?php echo urlencode($filter_module); ?>&action=<?php echo urlencode($filter_action); ?>&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>" aria-label="Previous">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        
                        <?php 
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        if ($start_page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=1&search=<?php echo urlencode($search); ?>&module=<?php echo urlencode($filter_module); ?>&action=<?php echo urlencode($filter_action); ?>&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>">1</a>
                            </li>
                            <?php if ($start_page > 2): ?>
                                <li class="page-item disabled"><span class="page-link">…</span></li>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&module=<?php echo urlencode($filter_module); ?>&action=<?php echo urlencode($filter_action); ?>&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">…</span></li>
                            <?php endif; ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $total_pages; ?>&search=<?php echo urlencode($search); ?>&module=<?php echo urlencode($filter_module); ?>&action=<?php echo urlencode($filter_action); ?>&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>"><?php echo $total_pages; ?></a>
                            </li>
                        <?php endif; ?>
                        
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&module=<?php echo urlencode($filter_module); ?>&action=<?php echo urlencode($filter_action); ?>&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>" aria-label="Next">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleFilters() {
            const filterSection = document.getElementById('filterSection');
            filterSection.classList.toggle('show');
        }

        function exportLogs(format) {
            // Get current URL parameters
            const params = new URLSearchParams(window.location.search);
            
            if (format === 'csv') {
                params.set('export_csv', '1');
                params.delete('generate_pdf');
            } else if (format === 'pdf') {
                params.set('generate_pdf', '1');
                params.delete('export_csv');
            }
            
            // Redirect to the same page with export parameter
            window.location.href = window.location.pathname + '?' + params.toString();
        }

        // Auto-submit filters on change
        document.querySelectorAll('#filterForm select, #filterForm input[type="date"]').forEach(el => {
            el.addEventListener('change', function() {
                document.getElementById('filterForm').submit();
            });
        });

        // Check if export was triggered and show success message
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('export_csv') || urlParams.has('generate_pdf')) {
                // Show a success message or handle as needed
                console.log('Export completed');
            }
        });
    </script>
</body>
</html>