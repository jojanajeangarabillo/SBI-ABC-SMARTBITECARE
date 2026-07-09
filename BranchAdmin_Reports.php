<?php
session_start();
require_once 'sources/db_connect.php';

// ============================================
// AUDIT LOG FUNCTION
// ============================================

/**
 * Add an audit log entry
 *
 * @param mysqli $conn Database connection
 * @param int $user_id User ID
 * @param string $action Action description
 * @param string $module Module name
 * @return bool Success status
 */
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

if (!$branch_id) {
    $error_message = "No branch assigned to this user account.";
}

// ============================================
// FPDF CLASS WITH BEAUTIFUL DESIGN
// ============================================
require_once('fpdf/fpdf.php');

class PDF extends FPDF
{
    /** @var string Report title */
    private $title;
    
    /** @var string Report subtitle */
    private $subtitle;
    
    /** @var string Branch name */
    private $branchName;
    
    /** @var string Date range */
    private $dateRange;
    
    /** @var string Logo file path */
    private $logoPath;

    /**
     * PDF constructor
     *
     * @param string $title Report title
     * @param string $subtitle Report subtitle
     * @param string $branchName Branch name
     * @param string $dateRange Date range
     */
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
        // Header background gradient effect (light blue)
        $this->SetFillColor(240, 244, 255);
        $this->Rect(0, 0, 210, 45, 'F');
        
        // Logo - Left side
        if (file_exists($this->logoPath)) {
            // Cast height to int to fix float warning (FPDF expects int|string)
            $this->Image($this->logoPath, 12, 6, (int)25);
        }
        
        // Clinic Name - Center
        $this->SetY((float)8);
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(43, 58, 140);
        $this->Cell(0, 7, 'SBI MEDICAL AND ANIMAL BITE CENTER', 0, 1, 'C');
        
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(0, 6, 'AND VACCINATION CLINIC', 0, 1, 'C');
        
        // Decorative line
        $this->SetDrawColor(43, 58, 140);
        $this->SetLineWidth((float)0.5);
        $this->Line(40, 28, 170, 28);
        
        // Report Title
        $this->SetY((float)33);
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(43, 58, 140);
        $this->Cell(0, 8, $this->title, 0, 1, 'C');
        
        // Subtitle
        if ($this->subtitle) {
            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(100, 100, 100);
            $this->Cell(0, 5, $this->subtitle, 0, 1, 'C');
        }
        
        // Branch and Date Range
        $this->SetY((float)46);
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
        
        // Second decorative line
        $this->SetY((float)54);
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth((float)0.3);
        $this->Line(15, 54, 195, 54);
        
        $this->SetY((float)58);
    }

    function Footer()
    {
        $this->SetY((float)-18);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        
        // Left side - Generation info
        $this->Cell(0, 5, 'Generated on: ' . date('Y-m-d H:i:s'), 0, 0, 'L');
        
        // Right side - Page number
        $this->Cell(0, 5, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
        
        // Bottom line
        $this->SetY((float)-15);
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth((float)0.2);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
    }

    /**
     * Create a statistics box with key metrics
     *
     * @param array<string, int|string> $stats Associative array of stat key => value
     */
    function CreateStatsBox($stats)
    {
        // Box height based on number of stats
        $boxHeight = count($stats) <= 4 ? 32 : 44;
        
        $this->SetFont('Arial', 'B', 11);
        $this->SetFillColor(240, 244, 255);
        $this->SetDrawColor(43, 58, 140);
        $this->SetLineWidth((float)0.5);
        
        $x = $this->GetX();
        $y = $this->GetY();
        $boxWidth = 180;
        
        // Draw rounded rectangle (simulated with standard rect)
        $this->Rect($x, $y, $boxWidth, $boxHeight);
        
        // Title inside box
        $this->SetXY($x + 5, $y + 2);
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(43, 58, 140);
        $this->Cell(0, 5, 'REPORT SUMMARY', 0, 1, 'L');
        
        // Stats
        $count = 0;
        $maxPerRow = 3;
        $currentX = $x + 5;
        $currentY = $y + 10;
        
        foreach ($stats as $key => $value) {
            $this->SetXY($currentX, $currentY);
            
            // Key
            $this->SetFont('Arial', 'B', 8);
            $this->SetTextColor(80, 80, 80);
            $this->Cell(45, 6, $key . ':', 0, 0, 'L');
            
            // Value
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor(43, 58, 140);
            $this->Cell(10, 6, (string)$value, 0, 0, 'L');
            
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

    /**
     * Create a formatted table
     *
     * @param array<int, string> $headers Table headers
     * @param array<int, array<int, string>> $data Table data rows
     * @param array<int, int|float>|null $columnWidths Column widths
     * @param int|float $fontSize Font size
     */
    function CreateTable($headers, $data, $columnWidths = null, $fontSize = 9)
    {
        if ($columnWidths === null) {
            $columnWidths = array_fill(0, count($headers), 25);
        }
        
        // Calculate total width
        $totalWidth = array_sum($columnWidths);
        if ($totalWidth > 180) {
            $scale = 180 / $totalWidth;
            $columnWidths = array_map(function($w) use ($scale) { return $w * $scale; }, $columnWidths);
        }
        
        // Table header with gradient effect
        $this->SetFont('Arial', 'B', (int)$fontSize);
        $this->SetFillColor(43, 58, 140);
        $this->SetTextColor(255, 255, 255);
        $this->SetDrawColor(43, 58, 140);
        $this->SetLineWidth((float)0.3);
        
        foreach ($headers as $i => $header) {
            $this->Cell($columnWidths[$i], 9, $header, 1, 0, 'C', true);
        }
        $this->Ln();
        
        // Table data with alternating row colors
        $this->SetFont('Arial', '', (int)$fontSize - 1);
        $this->SetTextColor(40, 40, 40);
        $this->SetDrawColor(220, 220, 220);
        $fill = false;
        $rowCount = 0;
        
        foreach ($data as $row) {
            $rowHeight = 7;
            
            // Alternate row colors
            if ($rowCount % 2 == 0) {
                $this->SetFillColor(248, 250, 255);
                $fill = true;
            } else {
                $this->SetFillColor(255, 255, 255);
                $fill = false;
            }
            
            foreach ($row as $i => $cell) {
                $this->Cell($columnWidths[$i], $rowHeight, (string)$cell, 1, 0, 'L', $fill);
            }
            $this->Ln();
            $rowCount++;
        }
        
        $this->Ln(3);
    }

    function AddWatermark()
    {
        // Optional watermark - uncomment to add
        // $this->SetFont('Arial', 'B', 60);
        // $this->SetTextColor(230, 230, 240);
        // $this->SetXY(30, 130);
        // $this->Cell(0, 0, 'SBI', 0, 0, 'C');
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Get branch name by ID
 *
 * @param string|null $branchId Branch ID
 * @return string Branch name or 'All Branches' if null
 */
function getBranchName($branchId) {
    if (!$branchId) return 'All Branches';
    global $conn;
    $stmt = $conn->prepare("SELECT branch_name FROM branches WHERE branch_id = ?");
    $stmt->bind_param("s", $branchId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row ? $row['branch_name'] : 'Unknown Branch';
}

// ============================================
// REPORT GENERATION FUNCTIONS
// ============================================

/**
 * Generate Patient Report PDF
 *
 * @param string $branchId Branch ID
 * @param string|null $startDate Start date
 * @param string|null $endDate End date
 * @return PDF Generated PDF
 */
function generatePatientReport($branchId, $startDate = null, $endDate = null) {
    global $conn;
    
    $params = [];
    $query = "SELECT p.patient_id, p.full_name, p.email, p.contact_number, 
              p.birthday, p.gender, p.address, p.created_at,
              COUNT(DISTINCT abc.case_id) as total_cases,
              COUNT(DISTINCT vr.vaccination_id) as total_vaccinations
              FROM patients p 
              LEFT JOIN animal_bite_cases abc ON p.patient_id = abc.patient_id 
              LEFT JOIN vaccination_records vr ON p.patient_id = vr.patient_id 
              WHERE p.branch_id = ?";
    $params[] = $branchId;
    
    if ($startDate && $endDate) {
        $query .= " AND DATE(p.created_at) BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
    }
    
    $query .= " GROUP BY p.patient_id ORDER BY p.created_at DESC";
    
    $stmt = $conn->prepare($query);
    $types = "s";
    if ($startDate && $endDate) {
        $types .= "ss";
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $patients = $result->fetch_all(MYSQLI_ASSOC);
    
    $pdf = new PDF('PATIENT REPORT', 'Detailed patient information and case history', 
                   getBranchName($branchId), 
                   ($startDate && $endDate) ? $startDate . ' to ' . $endDate : 'All Time');
    
    $totalPatients = count($patients);
    $totalCases = array_sum(array_column($patients, 'total_cases'));
    $totalVaccinations = array_sum(array_column($patients, 'total_vaccinations'));
    $maleCount = array_reduce($patients, function($carry, $p) {
        return $carry + ($p['gender'] == 'Male' ? 1 : 0);
    }, 0);
    $femaleCount = $totalPatients - $maleCount;
    
    $pdf->CreateStatsBox([
        'Total Patients' => $totalPatients,
        'Total Cases' => $totalCases,
        'Total Vaccinations' => $totalVaccinations,
        'Male' => $maleCount,
        'Female' => $femaleCount
    ]);
    
    $headers = ['ID', 'Name', 'Contact', 'Gender', 'Cases', 'Vacc.', 'Registered'];
    $columnWidths = [18, 45, 28, 22, 18, 22, 25];
    
    $data = array_map(function($patient) {
        return [
            $patient['patient_id'],
            substr($patient['full_name'], 0, 22) . (strlen($patient['full_name']) > 22 ? '...' : ''),
            $patient['contact_number'] ?: 'N/A',
            $patient['gender'] ?: 'N/A',
            $patient['total_cases'] ?: 0,
            $patient['total_vaccinations'] ?: 0,
            date('Y-m-d', strtotime($patient['created_at']))
        ];
    }, $patients);
    
    $pdf->CreateTable($headers, $data, $columnWidths, 8);
    
    return $pdf;
}

/**
 * Generate Case Report PDF
 *
 * @param string $branchId Branch ID
 * @param string|null $startDate Start date
 * @param string|null $endDate End date
 * @return PDF Generated PDF
 */
function generateCaseReport($branchId, $startDate = null, $endDate = null) {
    global $conn;
    
    $params = [];
    $query = "SELECT abc.case_id, p.full_name as patient_name, abc.animal_type, 
              abc.bite_location, abc.bite_category, abc.case_status, 
              abc.date_of_bite, abc.created_at,
              COUNT(DISTINCT rr.registry_id) as has_registry,
              COUNT(DISTINCT vr.vaccination_id) as has_vaccinations
              FROM animal_bite_cases abc 
              LEFT JOIN patients p ON abc.patient_id = p.patient_id 
              LEFT JOIN registry_records rr ON abc.case_id = rr.case_id 
              LEFT JOIN vaccination_records vr ON abc.case_id = vr.case_id 
              WHERE abc.branch_id = ?";
    $params[] = $branchId;
    
    if ($startDate && $endDate) {
        $query .= " AND DATE(abc.created_at) BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
    }
    
    $query .= " GROUP BY abc.case_id ORDER BY abc.created_at DESC";
    
    $stmt = $conn->prepare($query);
    $types = "s";
    if ($startDate && $endDate) {
        $types .= "ss";
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $cases = $result->fetch_all(MYSQLI_ASSOC);
    
    $pdf = new PDF('CASE REPORT', 'Detailed animal bite case information', 
                   getBranchName($branchId),
                   ($startDate && $endDate) ? $startDate . ' to ' . $endDate : 'All Time');
    
    $totalCases = count($cases);
    $ongoing = array_reduce($cases, function($carry, $c) {
        return $carry + ($c['case_status'] == 'Ongoing' ? 1 : 0);
    }, 0);
    $completed = $totalCases - $ongoing;
    $withRegistry = array_reduce($cases, function($carry, $c) {
        return $carry + ($c['has_registry'] > 0 ? 1 : 0);
    }, 0);
    
    $pdf->CreateStatsBox([
        'Total Cases' => $totalCases,
        'Ongoing' => $ongoing,
        'Completed' => $completed,
        'With Registry' => $withRegistry
    ]);
    
    $headers = ['ID', 'Patient', 'Animal', 'Location', 'Status', 'Date'];
    $columnWidths = [18, 40, 28, 32, 22, 28];
    
    $data = array_map(function($case) {
        return [
            $case['case_id'],
            substr($case['patient_name'] ?: 'Unknown', 0, 20),
            $case['animal_type'] ?: 'N/A',
            substr($case['bite_location'] ?: 'N/A', 0, 20),
            $case['case_status'] ?: 'N/A',
            date('Y-m-d', strtotime($case['date_of_bite'] ?: $case['created_at']))
        ];
    }, $cases);
    
    $pdf->CreateTable($headers, $data, $columnWidths, 8);
    
    return $pdf;
}

/**
 * Generate Inventory Report PDF
 *
 * @param string $branchId Branch ID
 * @param string|null $startDate Start date
 * @param string|null $endDate End date
 * @return PDF Generated PDF
 */
function generateInventoryReport($branchId, $startDate = null, $endDate = null) {
    global $conn;
    
    $params = [];
    $query = "SELECT i.item_id, i.item_name, ic.category_name, u.unit_name,
              s.quantity_available, s.expiration_date, s.last_updated,
              i.minimum_stock,
              (SELECT SUM(quantity_used) FROM inventory_usage_history 
               WHERE item_id = i.item_id AND branch_id = ? 
               AND DATE(usage_date) BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND CURDATE()) as monthly_usage
              FROM inventory_items i 
              LEFT JOIN inventory_categories ic ON i.category_id = ic.category_id 
              LEFT JOIN units u ON i.unit_id = u.unit_id 
              LEFT JOIN inventory_stocks s ON i.item_id = s.item_id AND s.branch_id = ?
              WHERE s.branch_id = ?";
    $params[] = $branchId;
    $params[] = $branchId;
    $params[] = $branchId;
    
    if ($startDate && $endDate) {
        $query .= " AND DATE(s.last_updated) BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
    }
    
    $query .= " ORDER BY i.item_name";
    
    $stmt = $conn->prepare($query);
    $types = "sss";
    if ($startDate && $endDate) {
        $types .= "ss";
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = $result->fetch_all(MYSQLI_ASSOC);
    
    $pdf = new PDF('INVENTORY REPORT', 'Current inventory status and usage', 
                   getBranchName($branchId),
                   ($startDate && $endDate) ? $startDate . ' to ' . $endDate : 'All Time');
    
    $totalItems = count($items);
    $lowStock = array_reduce($items, function($carry, $item) {
        return $carry + ($item['quantity_available'] < $item['minimum_stock'] ? 1 : 0);
    }, 0);
    $expiring = array_reduce($items, function($carry, $item) {
        if ($item['expiration_date']) {
            $daysUntilExpiry = (strtotime($item['expiration_date']) - time()) / (60*60*24);
            return $carry + ($daysUntilExpiry <= 30 && $daysUntilExpiry >= 0 ? 1 : 0);
        }
        return $carry;
    }, 0);
    $totalStock = array_sum(array_column($items, 'quantity_available'));
    
    $pdf->CreateStatsBox([
        'Total Items' => $totalItems,
        'Low Stock' => $lowStock,
        'Expiring Soon' => $expiring,
        'Total Stock' => $totalStock
    ]);
    
    $headers = ['Item', 'Category', 'Qty', 'Min', 'Status', 'Expiry'];
    $columnWidths = [45, 30, 18, 16, 22, 25];
    
    $data = array_map(function($item) {
        $status = $item['quantity_available'] < $item['minimum_stock'] ? 'Low Stock' : 'OK';
        $expiry = $item['expiration_date'] ? date('Y-m-d', strtotime($item['expiration_date'])) : 'N/A';
        
        if ($item['expiration_date']) {
            $daysUntilExpiry = (strtotime($item['expiration_date']) - time()) / (60*60*24);
            if ($daysUntilExpiry <= 30 && $daysUntilExpiry >= 0) {
                $status = 'Expiring';
            }
        }
        
        return [
            substr($item['item_name'] ?: 'N/A', 0, 25),
            $item['category_name'] ?: 'N/A',
            $item['quantity_available'] ?: 0,
            $item['minimum_stock'] ?: 0,
            $status,
            $expiry
        ];
    }, $items);
    
    $pdf->CreateTable($headers, $data, $columnWidths, 8);
    
    return $pdf;
}

/**
 * Generate Vaccination Report PDF
 *
 * @param string $branchId Branch ID
 * @param string|null $startDate Start date
 * @param string|null $endDate End date
 * @return PDF Generated PDF
 */
function generateVaccinationReport($branchId, $startDate = null, $endDate = null) {
    global $conn;
    
    $params = [];
    $query = "SELECT vr.vaccination_id, p.full_name as patient_name, 
              i.item_name as vaccine_name, vr.dose_number, vr.date_administered,
              vr.scheduled_date, vr.vaccination_status, vr.is_final_dose,
              vr.created_at, abc.case_id
              FROM vaccination_records vr 
              LEFT JOIN patients p ON vr.patient_id = p.patient_id 
              LEFT JOIN inventory_items i ON vr.item_id = i.item_id 
              LEFT JOIN animal_bite_cases abc ON vr.case_id = abc.case_id 
              WHERE vr.branch_id = ?";
    $params[] = $branchId;
    
    if ($startDate && $endDate) {
        $query .= " AND DATE(vr.created_at) BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
    }
    
    $query .= " ORDER BY vr.created_at DESC LIMIT 50";
    
    $stmt = $conn->prepare($query);
    $types = "s";
    if ($startDate && $endDate) {
        $types .= "ss";
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $vaccinations = $result->fetch_all(MYSQLI_ASSOC);
    
    $pdf = new PDF('VACCINATION REPORT', 'Complete vaccination records', 
                   getBranchName($branchId),
                   ($startDate && $endDate) ? $startDate . ' to ' . $endDate : 'All Time');
    
    $total = count($vaccinations);
    $completed = array_reduce($vaccinations, function($carry, $v) {
        return $carry + ($v['vaccination_status'] == 'Completed' ? 1 : 0);
    }, 0);
    $scheduled = array_reduce($vaccinations, function($carry, $v) {
        return $carry + ($v['vaccination_status'] == 'Scheduled' ? 1 : 0);
    }, 0);
    $missed = $total - $completed - $scheduled;
    $finalDoses = array_reduce($vaccinations, function($carry, $v) {
        return $carry + ($v['is_final_dose'] ? 1 : 0);
    }, 0);
    
    $pdf->CreateStatsBox([
        'Total Vaccinations' => $total,
        'Completed' => $completed,
        'Scheduled' => $scheduled,
        'Missed' => $missed,
        'Final Doses' => $finalDoses
    ]);
    
    $headers = ['Patient', 'Vaccine', 'Dose', 'Administered', 'Status', 'Final'];
    $columnWidths = [35, 35, 22, 26, 22, 18];
    
    $data = array_map(function($v) {
        return [
            substr($v['patient_name'] ?: 'Unknown', 0, 18),
            substr($v['vaccine_name'] ?: 'N/A', 0, 20),
            'Dose ' . ($v['dose_number'] ?: 'N/A'),
            $v['date_administered'] ? date('Y-m-d', strtotime($v['date_administered'])) : 'Pending',
            $v['vaccination_status'] ?: 'N/A',
            $v['is_final_dose'] ? 'Yes' : 'No'
        ];
    }, $vaccinations);
    
    $pdf->CreateTable($headers, $data, $columnWidths, 8);
    
    return $pdf;
}

// ============================================
// HANDLE REPORT GENERATION REQUEST
// ============================================
if (isset($_GET['generate_report']) && isset($_GET['report_type'])) {
    $reportType = $_GET['report_type'];
    $startDate = isset($_GET['start_date']) && $_GET['start_date'] ? $_GET['start_date'] : null;
    $endDate = isset($_GET['end_date']) && $_GET['end_date'] ? $_GET['end_date'] : null;
    
    $dateRange = ($startDate && $endDate) ? "$startDate to $endDate" : 'All Time';
    $actionDetail = "Generated $reportType report - Branch: $branch_name, Date Range: $dateRange";
    addAuditLog($conn, $_SESSION['user_id'], $actionDetail, 'Reports');
    
    $pdf = null;
    $filename = '';
    
    switch ($reportType) {
        case 'patient':
            $pdf = generatePatientReport($branch_id, $startDate, $endDate);
            $filename = 'Patient_Report_' . date('Y-m-d') . '.pdf';
            break;
        case 'case':
            $pdf = generateCaseReport($branch_id, $startDate, $endDate);
            $filename = 'Case_Report_' . date('Y-m-d') . '.pdf';
            break;
        case 'inventory':
            $pdf = generateInventoryReport($branch_id, $startDate, $endDate);
            $filename = 'Inventory_Report_' . date('Y-m-d') . '.pdf';
            break;
        case 'vaccination':
            $pdf = generateVaccinationReport($branch_id, $startDate, $endDate);
            $filename = 'Vaccination_Report_' . date('Y-m-d') . '.pdf';
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

// Get report statistics for the dashboard
$stats = [];

$statQuery = "SELECT COUNT(*) as count FROM patients WHERE branch_id = ?";
$stmt = $conn->prepare($statQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$result = $stmt->get_result();
$stats['total_patients'] = $result->fetch_assoc()['count'] ?? 0;

$statQuery = "SELECT COUNT(*) as count FROM animal_bite_cases WHERE branch_id = ?";
$stmt = $conn->prepare($statQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$result = $stmt->get_result();
$stats['total_cases'] = $result->fetch_assoc()['count'] ?? 0;

$statQuery = "SELECT COUNT(*) as count FROM inventory_stocks WHERE branch_id = ?";
$stmt = $conn->prepare($statQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$result = $stmt->get_result();
$stats['total_inventory'] = $result->fetch_assoc()['count'] ?? 0;

$statQuery = "SELECT COUNT(*) as count FROM vaccination_records WHERE branch_id = ?";
$stmt = $conn->prepare($statQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$result = $stmt->get_result();
$stats['total_vaccinations'] = $result->fetch_assoc()['count'] ?? 0;

$statQuery = "SELECT COUNT(*) as count 
              FROM inventory_stocks s 
              JOIN inventory_items i ON s.item_id = i.item_id 
              WHERE s.branch_id = ? AND s.quantity_available < i.minimum_stock";
$stmt = $conn->prepare($statQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$result = $stmt->get_result();
$stats['low_stock'] = $result->fetch_assoc()['count'] ?? 0;
?>

<!-- HTML PAGE (unchanged) -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Branch Admin - Reports</title>
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
        .topbar h3 small {
            font-size: 16px;
            font-weight: 400;
            color: #666;
            margin-left: 10px;
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 14px;
            padding: 18px 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
        }
        .stat-card .number {
            font-size: 32px;
            font-weight: 700;
            color: var(--primary);
        }
        .stat-card .label {
            font-size: 13px;
            color: #666;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 5px;
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

        @media (max-width: 991px) {
            .main {
                margin-left: 90px;
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
            .btn-generate {
                width: 100%;
                justify-content: center;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
            <li><a href="BranchAdmin_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="BranchAdmin_UserManagement.php"><i class="bi bi-people-fill"></i><span>User Management</span></a></li>
            <li><a href="BranchAdmin_PatientMonitoring.php"><i class="bi bi-heart-pulse-fill"></i><span>Patient Monitoring</span></a></li>
            <li><a href="BranchAdmin_MedicalSupplies.php"><i class="bi bi-box-seam"></i><span>Medical Supplies</span></a></li>
            <li><a href="BranchAdmin_PredictionModule.php"><i class="bi bi-graph-up-arrow"></i><span>Prediction Module</span></a></li>
            <li><a class="active" href="BranchAdmin_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a></li>
            <li><a href="BranchAdmin_AuditLogs.php"><i class="bi bi-clock-history"></i><span>Audit Logs</span></a></li>
            <li><a href="BranchAdmin_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
            <li><a href="BranchAdmin_Settings.php"><i class="bi bi-gear-fill"></i><span>Settings</span></a></li>
        </ul>
    </nav>

    <div class="logout">
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main">
    <div class="topbar">
        <h3>Reports <small><?php echo htmlspecialchars($branch_name); ?></small></h3>
        <div class="profile">
            <i class="bi bi-person-circle"></i>
            <?php echo htmlspecialchars($username); ?>
            <i class="bi bi-caret-down-fill"></i>
        </div>
    </div>

    <div class="content">
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?php echo number_format($stats['total_patients']); ?></div>
                <div class="label">Total Patients</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo number_format($stats['total_cases']); ?></div>
                <div class="label">Total Cases</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo number_format($stats['total_inventory']); ?></div>
                <div class="label">Inventory Items</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo number_format($stats['total_vaccinations']); ?></div>
                <div class="label">Vaccinations</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: <?php echo $stats['low_stock'] > 0 ? '#dc3545' : '#28a745'; ?>;">
                    <?php echo number_format($stats['low_stock']); ?>
                </div>
                <div class="label">Low Stock Items</div>
            </div>
        </div>

        <!-- Report Generator Card -->
        <div class="report-card">
            <form id="reportForm" method="GET" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                <div class="report-row">
                    <div class="field-group">
                        <label>Select Report Type</label>
                        <select name="report_type" id="reportType" class="form-select-custom" required>
                            <option value="">-- Select Report --</option>
                            <option value="patient">Patient Report</option>
                            <option value="case">Case Report</option>
                            <option value="inventory">Inventory Report</option>
                            <option value="vaccination">Vaccination Report</option>
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
                        <td class="report-name"><i class="bi bi-people me-2"></i>Patient Report</td>
                        <td class="report-desc">Complete patient list with case history and vaccination records</td>
                        <td class="action-icons">
                            <button class="btn-download" onclick="quickGenerate('patient')">
                                <i class="bi bi-download me-1"></i> Generate
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td class="report-name"><i class="bi bi-clipboard2-pulse me-2"></i>Case Report</td>
                        <td class="report-desc">Detailed animal bite case information and status</td>
                        <td class="action-icons">
                            <button class="btn-download" onclick="quickGenerate('case')">
                                <i class="bi bi-download me-1"></i> Generate
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td class="report-name"><i class="bi bi-box-seam me-2"></i>Inventory Report</td>
                        <td class="report-desc">Current inventory status, low stock alerts, and usage trends</td>
                        <td class="action-icons">
                            <button class="btn-download" onclick="quickGenerate('inventory')">
                                <i class="bi bi-download me-1"></i> Generate
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td class="report-name"><i class="bi bi-syringe me-2"></i>Vaccination Report</td>
                        <td class="report-desc">Complete vaccination records and schedule status</td>
                        <td class="action-icons">
                            <button class="btn-download" onclick="quickGenerate('vaccination')">
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

function quickGenerate(reportType) {
    document.getElementById('reportType').value = reportType;
    document.getElementById('reportForm').submit();
    showLoader();
}

function showLoader() {
    document.getElementById('loader').style.display = 'block';
    document.getElementById('generateBtn').disabled = true;
    document.getElementById('generateBtn').innerHTML = '<i class="bi bi-arrow-repeat me-2 spinner"></i> Generating...';
}

document.getElementById('reportForm').addEventListener('submit', function(e) {
    if (!this.report_type.value) {
        e.preventDefault();
        alert('Please select a report type.');
        return false;
    }
    showLoader();
});

window.addEventListener('load', function() {
    document.getElementById('loader').style.display = 'none';
    document.getElementById('generateBtn').disabled = false;
    document.getElementById('generateBtn').innerHTML = '<i class="bi bi-file-earmark-pdf me-2"></i> Generate Report';
});

setTimeout(function() {
    document.getElementById('loader').style.display = 'none';
    document.getElementById('generateBtn').disabled = false;
    document.getElementById('generateBtn').innerHTML = '<i class="bi bi-file-earmark-pdf me-2"></i> Generate Report';
}, 10000);
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>