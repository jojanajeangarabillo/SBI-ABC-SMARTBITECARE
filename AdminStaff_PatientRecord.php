<?php
// Enable output buffering to prevent stray output
ob_start();

// Disable error display for AJAX
if (!empty($_GET['action']) || !empty($_POST['action'])) {
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
}

session_start();
require_once 'sources/db_connect.php';

// Check if user is logged in and is an admin staff
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 4) {
    header("Location: login.php");
    exit();
}

$logged_user_id = $_SESSION['user_id'];
$logged_branch_id = null;
$branch_name = '';
$logged_username = '';
$role_name = 'Admin Staff';

// Get user's branch info
$userQuery = "SELECT u.branch_id, u.username, b.branch_name, r.role_name
              FROM users u 
              LEFT JOIN branches b ON u.branch_id = b.branch_id
              LEFT JOIN roles r ON u.role_id = r.role_id
              WHERE u.user_id = ?";
$stmt = $conn->prepare($userQuery);
$stmt->bind_param("i", $logged_user_id);
$stmt->execute();
$userResult = $stmt->get_result();

if ($userResult->num_rows > 0) {
    $userData = $userResult->fetch_assoc();
    $logged_branch_id = $userData['branch_id'];
    $branch_name = $userData['branch_name'] ?? 'Unknown Branch';
    $logged_username = $userData['username'] ?? 'Admin Staff';
    $role_name = $userData['role_name'] ?? 'Admin Staff';
}

if (!$logged_branch_id) {
    $branch_name = 'No Branch Assigned';
}

// ----------------------------------------------------------------------
// HELPER FUNCTIONS (MUST BE DEFINED BEFORE AJAX HANDLERS)
// ----------------------------------------------------------------------

function frontToDbDate(?string $date): ?string {
    if (empty($date)) return null;
    $parts = explode('/', trim($date));
    if (count($parts) !== 3) return null;
    $m = (int)$parts[0];
    $d = (int)$parts[1];
    $y = (int)$parts[2];
    if (strlen((string)$parts[2]) === 2) {
        $y += ($y <= (int)date('y')) ? 2000 : 1900;
    }
    if (!checkdate($m, $d, $y)) return null;
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

function dbToFrontDate(?string $date): string {
    if (empty($date)) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt ? $dt->format('m/d/Y') : '';
}

function calcAge(?string $birthday): ?int {
    if (empty($birthday)) return null;
    try {
        $birth = new DateTime($birthday);
        $today = new DateTime();
        return $birth->diff($today)->y;
    } catch (Exception $e) {
        return null;
    }
}

function auditLog(mysqli $conn, int $userId, string $branchId, string $action, string $module = 'Patient Record') {
    try {
        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, branch_id, action, module) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $userId, $branchId, $action, $module);
        $stmt->execute();
    } catch (Exception $e) {
        // Silently fail
    }
}

function jsonResponse($data, int $code = 200) {
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function caseNoExists(mysqli $conn, string $caseNo, ?int $excludeCaseId = null): bool {
    $sql = "SELECT r.registry_id FROM registry_records r 
            JOIN animal_bite_cases c ON r.case_id = c.case_id
            WHERE r.registry_number = ?";
    $params = [$caseNo];
    $types = "s";
    
    if ($excludeCaseId) {
        $sql .= " AND c.case_id != ?";
        $params[] = $excludeCaseId;
        $types .= "i";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

function getDefaultVaccineItemId(mysqli $conn): int {
    $stmt = $conn->prepare("SELECT item_id FROM inventory_items LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    if ($row) {
        return (int) $row['item_id'];
    }
    return 1;
}

function validatePatientData(array $data): array {
    $errors = [];
    if (empty($data['case_no'])) $errors[] = "Case number is required.";
    if (empty($data['patient_name'])) $errors[] = "Patient name is required.";
    if (!empty($data['dob'])) {
        $dob = frontToDbDate($data['dob']);
        if (!$dob) $errors[] = "Invalid date of birth format.";
    }
    if (!empty($data['admission_date'])) {
        $admit = frontToDbDate($data['admission_date']);
        if (!$admit) $errors[] = "Invalid admission date format.";
    }
    return $errors;
}

function getDosesForCategory(string $category, string $route): array {
    switch ($category) {
        case 'Pre-Exposure Prophylaxis (PrEP)':
            return ['d0', 'd7', 'd21'];
        case 'Post-Exposure Prophylaxis (PEP)':
            if ($route === 'Intradermal (ID)') {
                return ['d0', 'd3', 'd7', 'd28'];
            } else {
                return ['d0', 'd3', 'd7', 'd14', 'd28'];
            }
        case 'Booster Dose':
            return ['d0', 'd3'];
        case 'Others':
            return [];
        default:
            return ['d0', 'd3', 'd7', 'd14', 'd28'];
    }
}

// ============================================
// STOCK DEDUCTION FOR VACCINATION (FIFO)
// ============================================

function deductVaccineStock($conn, $item_id, $branch_id, $quantity, $user_id, $case_id, $dose_number, $remarks = '') {
    // Check if quantity is valid
    if ($quantity <= 0) {
        return ['success' => false, 'error' => 'Quantity must be greater than 0.'];
    }
    
    // Get total available stock
    $total_sql = "SELECT COALESCE(SUM(quantity_available), 0) as total FROM inventory_stocks 
                  WHERE item_id = ? AND branch_id = ? AND is_active = 1 AND quantity_available > 0";
    $total_stmt = $conn->prepare($total_sql);
    if (!$total_stmt) {
        return ['success' => false, 'error' => 'Database error.'];
    }
    $total_stmt->bind_param("is", $item_id, $branch_id);
    $total_stmt->execute();
    $total_result = $total_stmt->get_result();
    $total_data = $total_result->fetch_assoc();
    $total_stmt->close();
    
    if ($total_data['total'] < $quantity) {
        return ['success' => false, 'error' => 'Insufficient stock. Available: ' . $total_data['total'] . ', Requested: ' . $quantity];
    }
    
    // Get batches ordered by expiration date (FIFO)
    $batch_sql = "SELECT stock_id, quantity_available, expiration_date, batch_number 
                  FROM inventory_stocks 
                  WHERE item_id = ? AND branch_id = ? AND is_active = 1 AND quantity_available > 0
                  ORDER BY expiration_date ASC, stock_id ASC";
    $batch_stmt = $conn->prepare($batch_sql);
    if (!$batch_stmt) {
        return ['success' => false, 'error' => 'Database error.'];
    }
    $batch_stmt->bind_param("is", $item_id, $branch_id);
    $batch_stmt->execute();
    $batch_result = $batch_stmt->get_result();
    
    $remaining = $quantity;
    $deducted_batches = [];
    $last_stock_id = null;
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        while ($batch = $batch_result->fetch_assoc()) {
            if ($remaining <= 0) break;
            
            $stock_id = (int)$batch['stock_id'];
            $available = (int)$batch['quantity_available'];
            
            if ($available <= 0) continue;
            
            $deduct_amount = min($remaining, $available);
            $new_quantity = $available - $deduct_amount;
            
            // Update stock
            $update_sql = "UPDATE inventory_stocks SET quantity_available = ? WHERE stock_id = ?";
            $update_stmt = $conn->prepare($update_sql);
            if (!$update_stmt) {
                throw new Exception('Database error during update.');
            }
            $update_stmt->bind_param("ii", $new_quantity, $stock_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            $deducted_batches[] = [
                'stock_id' => $stock_id,
                'batch_number' => $batch['batch_number'] ?? 'N/A',
                'deducted' => $deduct_amount,
                'new_quantity' => $new_quantity
            ];
            
            $last_stock_id = $stock_id;
            $remaining -= $deduct_amount;
        }
        
        if ($remaining > 0) {
            throw new Exception('Could not deduct full quantity. Remaining: ' . $remaining);
        }
        
        // Create stock transaction
        $trx_sql = "INSERT INTO stock_transactions 
                     (item_id, stock_id, user_id, branch_id, transaction_type, quantity, remarks, vaccination_id) 
                     VALUES (?, ?, ?, ?, 'OUT', ?, ?, ?)";
        $trx_stmt = $conn->prepare($trx_sql);
        if (!$trx_stmt) {
            throw new Exception('Database error.');
        }
        $full_remarks = "Vaccination Dose #$dose_number - Case ID: $case_id" . ($remarks ? " - $remarks" : "");
        $trx_stmt->bind_param("iiisssi", $item_id, $last_stock_id, $user_id, $branch_id, $quantity, $full_remarks, $case_id);
        $trx_stmt->execute();
        $trx_stmt->close();
        
        // Record in usage history
        $usage_sql = "INSERT INTO inventory_usage_history (item_id, branch_id, usage_date, quantity_used, patient_count) 
                      VALUES (?, ?, CURDATE(), ?, 1)";
        $usage_stmt = $conn->prepare($usage_sql);
        if ($usage_stmt) {
            $usage_stmt->bind_param("isi", $item_id, $branch_id, $quantity);
            $usage_stmt->execute();
            $usage_stmt->close();
        }
        
        // Log the action
        $batch_details = array_map(function($b) {
            return "Batch " . ($b['batch_number'] ?? 'N/A') . ": -" . $b['deducted'];
        }, $deducted_batches);
        $action = "Vaccination Stock Deduction: Item ID $item_id, Qty $quantity, Dose #$dose_number, Case ID: $case_id, " . implode(', ', $batch_details);
        auditLog($conn, $user_id, $action, 'Vaccination Stock');
        
        $conn->commit();
        return ['success' => true, 'deducted_batches' => $deducted_batches];
        
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// ============================================
// GET ITEM ID FOR VACCINE
// ============================================

function getVaccineItemId($conn, $vaccine_name, $branch_id = null) {
    // Try to find by exact name match first
    $sql = "SELECT item_id, item_name FROM inventory_items WHERE item_name LIKE ? AND is_predictable = 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $search = "%" . $vaccine_name . "%";
    $stmt->bind_param("s", $search);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    if ($row) {
        return (int)$row['item_id'];
    }
    return null;
}

// ============================================
// GET AVAILABLE VACCINE STOCK
// ============================================

function getVaccineStock($conn, $item_id, $branch_id) {
    $sql = "SELECT COALESCE(SUM(quantity_available), 0) as total, 
                    COUNT(*) as batch_count,
                    MIN(expiration_date) as earliest_expiry
             FROM inventory_stocks 
             WHERE item_id = ? AND branch_id = ? AND is_active = 1 AND quantity_available > 0";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return ['total' => 0, 'batch_count' => 0];
    $stmt->bind_param("is", $item_id, $branch_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    return [
        'total' => (int)($data['total'] ?? 0),
        'batch_count' => (int)($data['batch_count'] ?? 0),
        'earliest_expiry' => $data['earliest_expiry'] ?? null
    ];
}

// ----------------------------------------------------------------------
// AJAX HANDLERS
// ----------------------------------------------------------------------
$action = $_GET['action'] ?? ($_POST['action'] ?? null);

if ($action) {
    switch ($action) {
        case 'fetch':
            try {
                $date = $_GET['date'] ?? null;
                $search = trim($_GET['search'] ?? '');
                $where = "WHERE c.branch_id = ?";
                $params = [$logged_branch_id];
                $types = "s";
                
                if (!empty($date)) {
                    $where .= " AND DATE(c.created_at) = ?";
                    $params[] = $date;
                    $types .= "s";
                }
                
                if ($search !== '') {
                    $where .= " AND (p.full_name LIKE ? OR r.registry_number LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $types .= "ss";
                }

                $sql = "
                    SELECT 
                        c.case_id,
                        p.patient_id,
                        p.full_name AS patient_name,
                        p.contact_number,
                        p.birthday,
                        p.gender,
                        p.address,
                        c.animal_type,
                        c.bite_location,
                        c.animal_status,
                        c.date_of_bite,
                        c.case_status,
                        c.remarks AS case_remarks,
                        c.created_at,
                        r.registry_number,
                        r.erig,
                        r.ats,
                        r.tt,
                        r.active_regimen,
                        r.remarks AS registry_remarks,
                        ph.has_philhealth,
                        ph.philhealth_membership,
                        ph.status AS philhealth_status
                    FROM animal_bite_cases c
                    JOIN patients p ON c.patient_id = p.patient_id
                    LEFT JOIN registry_records r ON c.case_id = r.case_id
                    LEFT JOIN philhealth_records ph ON c.case_id = ph.case_id
                    $where
                    ORDER BY c.created_at DESC
                ";

                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                $rows = [];
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }

                $resultArray = [];
                foreach ($rows as $row) {
                    $doseStmt = $conn->prepare("
                        SELECT dose_number, date_administered, vaccination_status
                        FROM vaccination_records
                        WHERE case_id = ? AND branch_id = ?
                        ORDER BY dose_number
                    ");
                    $doseStmt->bind_param("is", $row['case_id'], $logged_branch_id);
                    $doseStmt->execute();
                    $doseResult = $doseStmt->get_result();
                    $doses = [];
                    while ($doseRow = $doseResult->fetch_assoc()) {
                        $doses[] = $doseRow;
                    }

                    $schedule = ['d0' => '', 'd3' => '', 'd7' => '', 'd14'=> '', 'd21'=> '', 'd28'=> ''];
                    foreach ($doses as $d) {
                        $key = '';
                        switch ((int)$d['dose_number']) {
                            case 1: $key = 'd0'; break;
                            case 2: $key = 'd3'; break;
                            case 3: $key = 'd7'; break;
                            case 4: $key = 'd14'; break;
                            case 5: $key = 'd21'; break;
                            case 6: $key = 'd28'; break;
                        }
                        if ($key) {
                            $schedule[$key] = dbToFrontDate($d['date_administered']);
                        }
                    }

                    $vaccStatus = 'Pending';
                    if (!empty($schedule['d0']) && !empty($schedule['d3']) && 
                        !empty($schedule['d7']) && !empty($schedule['d14'])) {
                        $vaccStatus = 'Completed';
                    }

                    $hasPhilhealth = $row['has_philhealth'] ?? 'No';
                    $philhealthYes = ($hasPhilhealth === 'Yes') ? 'Yes' : 'No';

                    $resultArray[] = [
                        'case_id' => $row['case_id'],
                        'patient_id' => $row['patient_id'],
                        'case_no' => $row['registry_number'] ?? '',
                        'patient_name' => $row['patient_name'],
                        'contact_number' => $row['contact_number'] ?? '',
                        'dob' => dbToFrontDate($row['birthday']),
                        'age' => calcAge($row['birthday']),
                        'gender' => $row['gender'] ?? '',
                        'address' => $row['address'] ?? '',
                        'admission_date' => dbToFrontDate($row['date_of_bite'] ?? $row['created_at']),
                        'date_of_bite' => dbToFrontDate($row['date_of_bite']),
                        'site_of_bite' => $row['bite_location'] ?? '',
                        'biting_animal' => $row['animal_type'] ?? '',
                        'animal_status' => $row['animal_status'] ?? '',
                        'erig' => $row['erig'] ? 'Yes' : 'No',
                        'ats' => (bool) $row['ats'],
                        'tt' => (bool) $row['tt'],
                        'active_regimen' => $row['active_regimen'] ?? '',
                        'route' => '',
                        'vacc_category' => 'Post-Exposure Prophylaxis (PEP)',
                        'schedule' => $schedule,
                        'vaccination_status' => $vaccStatus,
                        'philhealth' => $philhealthYes,
                        'philhealth_type' => $row['philhealth_membership'] ?? '',
                        'status' => $row['philhealth_status'] ?? 'For Writing',
                        'remarks' => $row['case_remarks'] ?? $row['registry_remarks'] ?? '',
                    ];
                }
                jsonResponse($resultArray);
            } catch (Exception $e) {
                jsonResponse(['error' => 'Failed to fetch patients: ' . $e->getMessage()], 500);
            }
            break;

        case 'view':
            try {
                $caseId = (int) ($_GET['case_id'] ?? 0);
                if ($caseId <= 0) {
                    jsonResponse(['error' => 'Invalid case ID'], 400);
                }

                $stmt = $conn->prepare("
                    SELECT 
                        c.*, 
                        p.*, 
                        r.*, 
                        ph.has_philhealth,
                        ph.philhealth_membership,
                        ph.status AS philhealth_status,
                        ph.remarks AS philhealth_remarks
                    FROM animal_bite_cases c
                    JOIN patients p ON c.patient_id = p.patient_id
                    LEFT JOIN registry_records r ON c.case_id = r.case_id
                    LEFT JOIN philhealth_records ph ON c.case_id = ph.case_id
                    WHERE c.case_id = ? AND c.branch_id = ?
                ");
                $stmt->bind_param("is", $caseId, $logged_branch_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();

                if (!$row) {
                    jsonResponse(['error' => 'Record not found'], 404);
                }

                $doseStmt = $conn->prepare("
                    SELECT dose_number, scheduled_date, date_administered, vaccination_status 
                    FROM vaccination_records 
                    WHERE case_id = ? AND branch_id = ?
                    ORDER BY dose_number
                ");
                $doseStmt->bind_param("is", $caseId, $logged_branch_id);
                $doseStmt->execute();
                $doseResult = $doseStmt->get_result();
                
                $doseMap = ['d0' => 1, 'd3' => 2, 'd7' => 3, 'd14' => 4, 'd21' => 5, 'd28' => 6];
                $doseData = [];
                foreach ($doseMap as $key => $num) {
                    $doseData[$key] = [
                        'scheduled_date' => '',
                        'administered_date' => '',
                        'status' => 'Pending'
                    ];
                }
                
                while ($doseRow = $doseResult->fetch_assoc()) {
                    $key = array_search((int)$doseRow['dose_number'], $doseMap);
                    if ($key) {
                        $doseData[$key]['scheduled_date'] = dbToFrontDate($doseRow['scheduled_date']);
                        $doseData[$key]['administered_date'] = dbToFrontDate($doseRow['date_administered']);
                        $doseData[$key]['status'] = $doseRow['vaccination_status'] === 'Completed' ? 'Administered' : 'Pending';
                    }
                }

                $completedDoses = 0;
                foreach ($doseData as $d) {
                    if ($d['status'] === 'Administered') $completedDoses++;
                }
                $vaccStatus = $completedDoses >= 6 ? 'Completed' : 'In Progress';

                $historyStmt = $conn->prepare("
                    SELECT c.case_id, r.registry_number AS case_no, c.created_at, 
                           DATE(c.created_at) AS admit_date, c.case_status
                    FROM animal_bite_cases c
                    LEFT JOIN registry_records r ON c.case_id = r.case_id
                    WHERE c.patient_id = ? AND c.case_id != ?
                    ORDER BY c.created_at DESC
                ");
                $historyStmt->bind_param("ii", $row['patient_id'], $caseId);
                $historyStmt->execute();
                $historyResult = $historyStmt->get_result();
                $history = [];
                while ($histRow = $historyResult->fetch_assoc()) {
                    $history[] = $histRow;
                }

                $hasPhilhealth = $row['has_philhealth'] ?? 'No';

                $details = [
                    'case_id' => $row['case_id'],
                    'patient_id' => $row['patient_id'],
                    'case_no' => $row['registry_number'] ?? '',
                    'patient_name' => $row['full_name'],
                    'address' => $row['address'] ?? '',
                    'dob' => dbToFrontDate($row['birthday']),
                    'age' => calcAge($row['birthday']),
                    'gender' => $row['gender'] ?? '',
                    'has_philhealth' => $hasPhilhealth,
                    'philhealth_membership' => $row['philhealth_membership'] ?? '',
                    'contact_number' => $row['contact_number'] ?? '',
                    'admission_date' => dbToFrontDate($row['date_of_bite'] ?? $row['created_at']),
                    'date_of_bite' => dbToFrontDate($row['date_of_bite']),
                    'site_of_bite' => $row['bite_location'] ?? '',
                    'biting_animal' => $row['animal_type'] ?? '',
                    'animal_status' => $row['animal_status'] ?? '',
                    'erig_ml' => $row['erig'] ?? 0,
                    'ats' => (bool) $row['ats'],
                    'tt' => (bool) $row['tt'],
                    'active_regimen' => $row['active_regimen'] ?? '',
                    'route' => '',
                    'vacc_category' => 'Post-Exposure Prophylaxis (PEP)',
                    'vaccination_doses' => $doseData,
                    'vaccination_status' => $vaccStatus,
                    'status' => $row['philhealth_status'] ?? 'For Writing',
                    'remarks' => $row['case_remarks'] ?? $row['philhealth_remarks'] ?? '',
                    'history' => $history,
                ];
                jsonResponse($details);
            } catch (Exception $e) {
                jsonResponse(['error' => 'Failed to view patient: ' . $e->getMessage()], 500);
            }
            break;

        case 'save':
            try {
                $rawInput = file_get_contents('php://input');
                $input = json_decode($rawInput, true);
                if (!$input) {
                    jsonResponse(['error' => 'Invalid JSON input'], 400);
                }

                $validationErrors = validatePatientData($input);
                if (!empty($validationErrors)) {
                    jsonResponse(['error' => implode(' ', $validationErrors)], 400);
                }

                $conn->begin_transaction();

                $caseId = !empty($input['case_id']) ? (int) $input['case_id'] : null;
                $patientId = !empty($input['patient_id']) ? (int) $input['patient_id'] : null;
                $caseNo = trim($input['case_no'] ?? '');
                $fullName = trim($input['patient_name'] ?? '');
                $dob = frontToDbDate($input['dob'] ?? '');
                $gender = $input['gender'] ?? '';
                $address = trim($input['address'] ?? '');
                $contact = trim($input['contact_number'] ?? '');
                $admitDate = frontToDbDate($input['admission_date'] ?? '');
                $biteDate = frontToDbDate($input['date_of_bite'] ?? '');
                $siteBite = trim($input['site_of_bite'] ?? '');
                $animal = trim($input['biting_animal'] ?? '');
                $animalStat = trim($input['animal_status'] ?? '');
                $erigMl = isset($input['erig_ml']) ? floatval($input['erig_ml']) : 0;
                $ats = !empty($input['ats']);
                $tt = !empty($input['tt']);
                $regimen = trim($input['active_regimen'] ?? '');
                $vaccCat = trim($input['vacc_category'] ?? '');
                $route = trim($input['route'] ?? '');
                $status = trim($input['status'] ?? 'For Writing');
                $hasPhilhealth = trim($input['philhealth'] ?? 'No');
                $philhealthMembership = trim($input['philhealth_type'] ?? '');
                $vaccinationDoses = $input['vaccination_doses'] ?? [];
                $vaccinationRemarks = trim($input['vaccination_remarks'] ?? '');
                $customVaccCategory = trim($input['custom_vacc_category'] ?? '');

                if (empty($caseNo)) throw new Exception("Case number is required.");
                if (empty($fullName)) throw new Exception("Patient name is required.");

                if (caseNoExists($conn, $caseNo, $caseId)) {
                    throw new Exception("Case number '{$caseNo}' already exists.");
                }

                // 1) Handle patient
                if ($patientId) {
                    $checkStmt = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ? AND branch_id = ?");
                    $checkStmt->bind_param("is", $patientId, $logged_branch_id);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    if (!$checkResult->fetch_assoc()) {
                        throw new Exception("Patient not found or access denied.");
                    }
                    
                    $upd = $conn->prepare("
                        UPDATE patients 
                        SET full_name=?, contact_number=?, birthday=?, gender=?, address=? 
                        WHERE patient_id=? AND branch_id=?
                    ");
                    $upd->bind_param("sssssis", $fullName, $contact, $dob, $gender, $address, $patientId, $logged_branch_id);
                    $upd->execute();
                } else {
                    $ins = $conn->prepare("
                        INSERT INTO patients (full_name, contact_number, birthday, gender, address, branch_id) 
                        VALUES (?,?,?,?,?,?)
                    ");
                    $ins->bind_param("ssssss", $fullName, $contact, $dob, $gender, $address, $logged_branch_id);
                    $ins->execute();
                    $patientId = (int) $conn->insert_id;
                }

                // 2) Handle animal_bite_cases
                if ($caseId) {
                    $checkCase = $conn->prepare("SELECT case_id FROM animal_bite_cases WHERE case_id = ? AND branch_id = ?");
                    $checkCase->bind_param("is", $caseId, $logged_branch_id);
                    $checkCase->execute();
                    $checkCaseResult = $checkCase->get_result();
                    if (!$checkCaseResult->fetch_assoc()) {
                        throw new Exception("Case not found or access denied.");
                    }
                    
                    $updCase = $conn->prepare("
                        UPDATE animal_bite_cases 
                        SET animal_type=?, bite_location=?, animal_status=?, date_of_bite=?,
                            admin_staff_id=?
                        WHERE case_id=? AND branch_id=?
                    ");
                    $updCase->bind_param("sssssis", $animal, $siteBite, $animalStat, $biteDate, 
                        $logged_user_id, $caseId, $logged_branch_id);
                    $updCase->execute();
                } else {
                    $insCase = $conn->prepare("
                        INSERT INTO animal_bite_cases 
                        (patient_id, branch_id, animal_type, bite_location, animal_status, date_of_bite, 
                         case_status, admin_staff_id) 
                        VALUES (?,?,?,?,?,?,?,?)
                    ");
                    $caseStatus = 'Ongoing';
                    $insCase->bind_param("issssssi", 
                        $patientId, $logged_branch_id, $animal, $siteBite, $animalStat, 
                        $biteDate, $caseStatus, $logged_user_id);
                    $insCase->execute();
                    $caseId = (int) $conn->insert_id;
                }

                // 3) Handle registry_records
                $regExists = $conn->prepare("SELECT registry_id FROM registry_records WHERE case_id=?");
                $regExists->bind_param("i", $caseId);
                $regExists->execute();
                $regResult = $regExists->get_result();
                $regRow = $regResult->fetch_assoc();
                $registryId = $regRow['registry_id'] ?? null;

                $doseMap = ['d0' => 1, 'd3' => 2, 'd7' => 3, 'd14' => 4, 'd21' => 5, 'd28' => 6];
                $doseFlags = [];
                foreach ($doseMap as $key => $num) {
                    $doseData = $vaccinationDoses[$key] ?? ['status' => 'Pending'];
                    $doseFlags[$key] = ($doseData['status'] === 'Administered') ? 1 : 0;
                }

                $booster = $doseFlags['d28'] ?? 0;

                if ($registryId) {
                    $updReg = $conn->prepare("
                        UPDATE registry_records 
                        SET registry_number=?, status_of_biting_animal=?, erig=?, ats=?, tt=?, 
                            active_regimen=?, dose_d0=?, dose_d3=?, dose_d7=?, dose_d14=?, 
                            dose_d21=?, dose_d28_30=?, booster=?, contact_number=?, 
                            updated_by=?, updated_at=NOW() 
                        WHERE registry_id=?
                    ");
                    $updReg->bind_param("ssdiisiiiiiiissi", 
                        $caseNo, $animalStat, $erigMl, $ats, $tt, $regimen, 
                        $doseFlags['d0'], $doseFlags['d3'], $doseFlags['d7'], $doseFlags['d14'], 
                        $doseFlags['d21'], $doseFlags['d28'], $booster, $contact, 
                        $logged_user_id, $registryId);
                    $updReg->execute();
                } else {
                    $insReg = $conn->prepare("
                        INSERT INTO registry_records 
                        (case_id, registry_number, status_of_biting_animal, erig, ats, tt, active_regimen, 
                         dose_d0, dose_d3, dose_d7, dose_d14, dose_d21, dose_d28_30, booster, 
                         contact_number, updated_by, updated_at) 
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                    ");
                    $insReg->bind_param("issdiisiiiiiiiss", 
                        $caseId, $caseNo, $animalStat, $erigMl, $ats, $tt, $regimen,
                        $doseFlags['d0'], $doseFlags['d3'], $doseFlags['d7'], $doseFlags['d14'], 
                        $doseFlags['d21'], $doseFlags['d28'], $booster, $contact, $logged_user_id);
                    $insReg->execute();
                }

                // 4) Handle philhealth_records
                $phExists = $conn->prepare("SELECT philhealth_record_id FROM philhealth_records WHERE case_id=?");
                $phExists->bind_param("i", $caseId);
                $phExists->execute();
                $phResult = $phExists->get_result();
                $phRow = $phResult->fetch_assoc();
                $phRecId = $phRow['philhealth_record_id'] ?? null;
                
                $dbHasPhilhealth = ($hasPhilhealth === 'Yes') ? 'Yes' : 'No';
                $dbPhilhealthMembership = ($dbHasPhilhealth === 'Yes') ? $philhealthMembership : null;

                if ($phRecId) {
                    $updPh = $conn->prepare("
                        UPDATE philhealth_records 
                        SET has_philhealth=?, philhealth_membership=?, status=?, 
                            updated_by=?, updated_at=NOW() 
                        WHERE philhealth_record_id=?
                    ");
                    $updPh->bind_param("sssii", $dbHasPhilhealth, $dbPhilhealthMembership, $status, 
                        $logged_user_id, $phRecId);
                    $updPh->execute();
                } else {
                    $insPh = $conn->prepare("
                        INSERT INTO philhealth_records 
                        (case_id, has_philhealth, philhealth_membership, status, updated_by, updated_at) 
                        VALUES (?,?,?,?,?,NOW())
                    ");
                    $insPh->bind_param("isssi", $caseId, $dbHasPhilhealth, $dbPhilhealthMembership, 
                        $status, $logged_user_id);
                    $insPh->execute();
                }

                // 5) Handle vaccination_records with stock deduction
                $delVacc = $conn->prepare("DELETE FROM vaccination_records WHERE case_id=? AND branch_id=?");
                $delVacc->bind_param("is", $caseId, $logged_branch_id);
                $delVacc->execute();

                // Map vaccine names to items
                $vaccineItemMap = [
                    'PVRV TRC SPEEDA' => 'SPEEDA',
                    'PVRV TRC ABHAYRAB' => 'ABHAYRAB',
                    'PVRV TRC ERIG' => 'ERIG',
                    'ERIG' => 'ERIG',
                    'ATS' => 'ATS',
                    'BETT' => 'BETT',
                    'ABHAYTOX' => 'ABHAYTOX'
                ];

                // Determine which vaccine is being used based on regimen
                $vaccine_name = $vaccineItemMap[$regimen] ?? null;

                // If regimen contains SPEEDA, use SPEEDA
                if (stripos($regimen, 'SPEEDA') !== false) {
                    $vaccine_name = 'SPEEDA';
                } elseif (stripos($regimen, 'ABHAYRAB') !== false) {
                    $vaccine_name = 'ABHAYRAB';
                }

                $insertVacc = $conn->prepare("
                    INSERT INTO vaccination_records 
                    (patient_id, case_id, item_id, branch_id, dose_number, 
                     scheduled_date, date_administered, vaccination_status, remarks, nurse_id) 
                    VALUES (?,?,?,?,?,?,?,?,?,?)
                ");

                $dosesToSave = getDosesForCategory($vaccCat, $route);
                $stock_deduction_errors = [];

                foreach ($dosesToSave as $key) {
                    $doseNum = $doseMap[$key];
                    $doseData = $vaccinationDoses[$key] ?? ['scheduled_date' => '', 'administered_date' => '', 'status' => 'Pending'];
                    
                    $scheduledDate = !empty($doseData['scheduled_date']) ? frontToDbDate($doseData['scheduled_date']) : null;
                    $administeredDate = !empty($doseData['administered_date']) ? frontToDbDate($doseData['administered_date']) : null;
                    
                    $vaccStatus = $doseData['status'] === 'Administered' ? 'Completed' : 'Scheduled';
                    
                    // Get item_id for this vaccination
                    $vaccineItemId = null;
                    
                    // If dose is administered, try to deduct stock
                    if ($vaccStatus === 'Completed' && $vaccine_name) {
                        // Try to find the vaccine in inventory
                        $vaccineItemId = getVaccineItemId($conn, $vaccine_name);
                        
                        // If found, deduct stock (1 vial per dose)
                        if ($vaccineItemId) {
                            $deductResult = deductVaccineStock(
                                $conn, 
                                $vaccineItemId, 
                                $logged_branch_id, 
                                1, // 1 vial per dose
                                $logged_user_id, 
                                $caseId, 
                                $doseNum,
                                $vaccine_name . " Dose #$doseNum"
                            );
                            
                            if (!$deductResult['success']) {
                                // Log the error but don't block vaccination recording
                                $stock_deduction_errors[] = $vaccine_name . " Dose #$doseNum: " . $deductResult['error'];
                                error_log("Stock deduction failed for vaccine $vaccine_name: " . $deductResult['error']);
                                auditLog($conn, $logged_user_id, "WARNING: Stock deduction failed for $vaccine_name - " . $deductResult['error'], 'Vaccination Stock');
                            }
                        } else {
                            $stock_deduction_errors[] = $vaccine_name . " not found in inventory system";
                            auditLog($conn, $logged_user_id, "WARNING: Vaccine $vaccine_name not found in inventory", 'Vaccination Stock');
                        }
                    }
                    
                    // If vaccine item not found or not administered, use default
                    if (!$vaccineItemId) {
                        $vaccineItemId = 1; // Default fallback
                    }
                    
                    $insertVacc->bind_param(
                        "iiisississ",
                        $patientId,
                        $caseId,
                        $vaccineItemId,
                        $logged_branch_id,
                        $doseNum,
                        $scheduledDate,
                        $administeredDate,
                        $vaccStatus,
                        $vaccinationRemarks,
                        $logged_user_id
                    );
                    $insertVacc->execute();
                }

                // Log stock deduction errors if any
                if (!empty($stock_deduction_errors)) {
                    $error_summary = "Stock deduction warnings: " . implode("; ", $stock_deduction_errors);
                    auditLog($conn, $logged_user_id, $error_summary, 'Vaccination Stock Warnings');
                }

                $actionText = $caseId ? "Updated patient record: {$fullName} (Case: {$caseNo})" 
                                      : "Created new patient record: {$fullName} (Case: {$caseNo})";
                auditLog($conn, $logged_user_id, $logged_branch_id, $actionText, 'Patient Record');

                $conn->commit();
                
                $response = ['success' => true, 'case_id' => $caseId, 'case_no' => $caseNo];
                if (!empty($stock_deduction_errors)) {
                    $response['stock_warnings'] = $stock_deduction_errors;
                }
                jsonResponse($response);

            } catch (Exception $e) {
                $conn->rollback();
                jsonResponse(['error' => $e->getMessage()], 500);
            }
            break;

        case 'delete':
            try {
                $caseId = (int) ($_GET['case_id'] ?? 0);
                if ($caseId <= 0) {
                    jsonResponse(['error' => 'Invalid case ID'], 400);
                }

                $conn->begin_transaction();

                $caseStmt = $conn->prepare("
                    SELECT c.case_id, p.full_name, r.registry_number 
                    FROM animal_bite_cases c
                    JOIN patients p ON c.patient_id = p.patient_id
                    LEFT JOIN registry_records r ON c.case_id = r.case_id
                    WHERE c.case_id = ? AND c.branch_id = ?
                ");
                $caseStmt->bind_param("is", $caseId, $logged_branch_id);
                $caseStmt->execute();
                $caseResult = $caseStmt->get_result();
                $caseData = $caseResult->fetch_assoc();
                
                if (!$caseData) {
                    throw new Exception("Record not found or access denied.");
                }

                $delDoc = $conn->prepare("DELETE FROM document_tracking WHERE case_id=?");
                $delDoc->bind_param("i", $caseId);
                $delDoc->execute();

                $delVacc = $conn->prepare("DELETE FROM vaccination_records WHERE case_id=? AND branch_id=?");
                $delVacc->bind_param("is", $caseId, $logged_branch_id);
                $delVacc->execute();

                $delPhil = $conn->prepare("DELETE FROM philhealth_records WHERE case_id=?");
                $delPhil->bind_param("i", $caseId);
                $delPhil->execute();

                $delReg = $conn->prepare("DELETE FROM registry_records WHERE case_id=?");
                $delReg->bind_param("i", $caseId);
                $delReg->execute();

                $delCase = $conn->prepare("DELETE FROM animal_bite_cases WHERE case_id=? AND branch_id=?");
                $delCase->bind_param("is", $caseId, $logged_branch_id);
                $delCase->execute();

                $actionText = "Deleted patient record: {$caseData['full_name']} (Case: {$caseData['registry_number']})";
                auditLog($conn, $logged_user_id, $logged_branch_id, $actionText, 'Patient Record');

                $conn->commit();
                jsonResponse(['success' => true]);

            } catch (Exception $e) {
                $conn->rollback();
                jsonResponse(['error' => $e->getMessage()], 500);
            }
            break;

        case 'check_case_no':
            try {
                $caseNo = trim($_GET['case_no'] ?? '');
                $excludeId = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : null;
                if (empty($caseNo)) {
                    jsonResponse(['exists' => false]);
                }
                $exists = caseNoExists($conn, $caseNo, $excludeId);
                jsonResponse(['exists' => $exists]);
            } catch (Exception $e) {
                jsonResponse(['error' => 'Failed to check case number: ' . $e->getMessage()], 500);
            }
            break;

        case 'patient_history':
            try {
                $patientId = (int) ($_GET['patient_id'] ?? 0);
                if ($patientId <= 0) {
                    jsonResponse([]);
                }
                $stmt = $conn->prepare("
                    SELECT c.case_id, r.registry_number AS case_no, 
                           DATE(c.created_at) AS admit_date, c.case_status
                    FROM animal_bite_cases c
                    LEFT JOIN registry_records r ON c.case_id = r.case_id
                    WHERE c.patient_id = ? AND c.branch_id = ?
                    ORDER BY c.created_at DESC
                ");
                $stmt->bind_param("is", $patientId, $logged_branch_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $rows = [];
                while ($row = $result->fetch_assoc()) {
                    $rows[] = [
                        'case_id' => $row['case_id'],
                        'case_no' => $row['case_no'] ?? '',
                        'admit_date' => dbToFrontDate($row['admit_date']),
                        'status' => $row['case_status'] ?? 'Ongoing',
                    ];
                }
                jsonResponse($rows);
            } catch (Exception $e) {
                jsonResponse(['error' => 'Failed to fetch history: ' . $e->getMessage()], 500);
            }
            break;

        case 'check_vaccine_stock':
            try {
                $vaccine_name = trim($_GET['vaccine_name'] ?? '');
                $dose_count = (int)($_GET['dose_count'] ?? 1);
                
                if (empty($vaccine_name)) {
                    jsonResponse(['error' => 'Vaccine name is required'], 400);
                }
                
                // Map vaccine names
                $vaccineMap = [
                    'SPEEDA' => 'SPEEDA',
                    'ABHAYRAB' => 'ABHAYRAB',
                    'ERIG' => 'ERIG',
                    'ATS' => 'ATS',
                    'BETT' => 'BETT',
                    'ABHAYTOX' => 'ABHAYTOX'
                ];
                
                $search_name = $vaccineMap[$vaccine_name] ?? $vaccine_name;
                
                // Find the item
                $item_sql = "SELECT item_id, item_name, minimum_stock FROM inventory_items WHERE item_name LIKE ? AND is_predictable = 1";
                $item_stmt = $conn->prepare($item_sql);
                $search_param = "%" . $search_name . "%";
                $item_stmt->bind_param("s", $search_param);
                $item_stmt->execute();
                $item_result = $item_stmt->get_result();
                $item = $item_result->fetch_assoc();
                $item_stmt->close();
                
                if (!$item) {
                    jsonResponse(['success' => false, 'error' => 'Vaccine not found in inventory', 'available_stock' => 0]);
                }
                
                // Get total stock
                $stock_sql = "SELECT COALESCE(SUM(quantity_available), 0) as total, 
                                     COUNT(*) as batch_count,
                                     MIN(expiration_date) as earliest_expiry
                              FROM inventory_stocks 
                              WHERE item_id = ? AND branch_id = ? AND is_active = 1 AND quantity_available > 0";
                $stock_stmt = $conn->prepare($stock_sql);
                $stock_stmt->bind_param("is", $item['item_id'], $logged_branch_id);
                $stock_stmt->execute();
                $stock_result = $stock_stmt->get_result();
                $stock_data = $stock_result->fetch_assoc();
                $stock_stmt->close();
                
                $available = (int)($stock_data['total'] ?? 0);
                $batch_count = (int)($stock_data['batch_count'] ?? 0);
                $needed = $dose_count;
                $min_stock = (int)($item['minimum_stock'] ?? 0);
                
                jsonResponse([
                    'success' => true,
                    'item_id' => $item['item_id'],
                    'item_name' => $item['item_name'],
                    'available_stock' => $available,
                    'batch_count' => $batch_count,
                    'needed' => $needed,
                    'minimum_stock' => $min_stock,
                    'has_sufficient_stock' => $available >= $needed,
                    'is_low_stock' => $available > 0 && $available <= $min_stock,
                    'earliest_expiry' => $stock_data['earliest_expiry'] ?? null
                ]);
            } catch (Exception $e) {
                jsonResponse(['error' => 'Failed to check stock: ' . $e->getMessage()], 500);
            }
            break;

        default:
            jsonResponse(['error' => 'Unknown action: ' . $action], 400);
    }
    exit;
}

// ----------------------------------------------------------------------
// HTML OUTPUT (only if not AJAX)
// ----------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Patient Record Management - SmartBiteCare</title>
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Flatpickr -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <!-- Sidebar CSS -->
    <link rel="stylesheet" href="sidebar.css">

    <style>
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
            --bg: #F2F2F2;
            --gray-100: #f8f9fc;
            --gray-200: #f1f3f5;
            --gray-300: #e9ecef;
            --gray-500: #adb5bd;
            --gray-600: #6c757d;
            --gray-700: #495057;
            --gray-900: #212529;
            --green: #28a745;
            --yellow: #ffc107;
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            --radius: 12px;
            --transition: all 0.25s ease;
        }

        body { 
            background: #f0f2f5; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
            padding: 0 30px 30px 30px;
            background: #f0f2f5;
        }

        .topbar {
            background: white;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 35px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }

        .topbar h3 {
            margin-left: 250px;
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
        }

        .profile {
            font-weight: 600;
            color: var(--primary);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        @media (max-width: 991px) {
            .main { margin-left: 90px; padding: 0 15px 15px 15px; }
            .topbar { padding: 0 16px; height: 64px; }
            .topbar h3 { font-size: 20px; margin-left: 100px; }
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }

        .search-area {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .search-area .search-wrapper {
            position: relative;
        }

        .search-area .search-wrapper input {
            width: 340px;
            padding: 10px 15px 10px 38px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            background: #fff;
            transition: var(--transition);
        }

        .search-area .search-wrapper input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43,58,140,0.12);
            outline: none;
        }

        .search-area .search-wrapper i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-500);
            font-size: 16px;
        }

        .btn {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 10px 22px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
        }

        .btn:hover {
            background: #1f2d6b;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(43,58,140,0.25);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 12px rgba(43,58,140,0.25);
        }

        .btn-success {
            background: var(--green);
        }
        .btn-success:hover { background: #1e7e34; }

        .record-container {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 24px;
        }

        @media (max-width: 992px) {
            .record-container {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }

        .calendar-panel {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            height: fit-content;
        }

        .calendar-panel .panel-header {
            padding: 16px 20px;
            border-bottom: 1px solid #ddd;
            font-weight: 700;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fafafa;
            font-size: 15px;
            color: var(--gray-900);
        }

        .calendar-panel .panel-header i {
            color: var(--primary);
            font-size: 18px;
        }

        .calendar-panel .flatpickr-calendar.inline {
            box-shadow: none;
            border: none;
            width: 100%;
            background: transparent;
            padding: 8px 0;
        }

        .calendar-panel .flatpickr-calendar.inline .flatpickr-month {
            background: transparent;
            color: var(--primary);
            font-weight: 700;
        }

        .calendar-panel .flatpickr-calendar.inline .flatpickr-day.selected {
            background: var(--primary);
            border-color: var(--primary);
        }

        .calendar-panel .flatpickr-calendar.inline .flatpickr-day.today {
            border-color: var(--primary);
            color: var(--primary);
        }

        .calendar-panel .flatpickr-calendar.inline .flatpickr-day:hover {
            background: var(--gray-100);
        }

        .calendar-panel .date-stats {
            padding: 12px 20px 16px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            color: var(--gray-600);
        }

        .calendar-panel .date-stats .stat-badge {
            background: var(--primary);
            color: white;
            padding: 2px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .calendar-panel .date-stats .stat-badge.empty {
            background: var(--gray-300);
            color: var(--gray-600);
        }

        .table-panel {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .tabs {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #ddd;
            background: #fafafa;
            flex-wrap: wrap;
            padding: 8px 20px;
        }

        .tab {
            padding: 10px 0;
            font-weight: 600;
            font-size: 15px;
            cursor: default;
            color: var(--gray-700);
        }

        .tab span {
            color: var(--primary);
        }

        .tab .vacc-status-badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            margin-left: 8px;
        }

        .tab .vacc-status-badge.pending {
            background: #fff3cd;
            color: #856404;
        }

        .tab .vacc-status-badge.completed {
            background: #d4edda;
            color: #155724;
        }

        .export-btn {
            padding: 8px 18px;
            background: var(--primary);
            color: #fff;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            transition: var(--transition);
        }

        .export-btn:hover {
            background: #1f2d6b;
        }

        .table-responsive-custom {
            overflow-x: auto;
            padding: 0 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        thead {
            background: var(--primary);
            color: white;
        }

        th {
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            white-space: nowrap;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        td {
            padding: 12px 16px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        tbody tr:hover {
            background: var(--gray-100);
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        .status-yes {
            color: #0d9c38;
            font-weight: 600;
        }
        .status-no {
            color: #a71d2a;
        }

        .action-icons {
            display: flex;
            gap: 12px;
            font-size: 18px;
            color: var(--primary);
        }

        .action-icons i {
            cursor: pointer;
            transition: 0.2s;
            padding: 4px;
            border-radius: 4px;
        }

        .action-icons i:hover {
            color: var(--accent);
            background: rgba(242,29,47,0.08);
        }

        .action-icons i.bi-eye:hover {
            color: var(--primary);
            background: rgba(43,58,140,0.08);
        }

        .action-icons i.bi-pencil:hover {
            color: #ffc107;
            background: rgba(255,193,7,0.12);
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 6px;
            padding: 16px 0 12px;
        }

        .page {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            font-weight: 600;
            transition: 0.15s;
            border: 1px solid transparent;
            font-size: 14px;
        }

        .page:hover {
            background: #eef2ff;
        }

        .page.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Modal Styles */
        .modal-content {
            border-radius: var(--radius);
            border: none;
        }

        .modal-header {
            background: var(--primary);
            color: white;
            border-radius: var(--radius) var(--radius) 0 0;
            padding: 20px 30px;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        .modal-body {
            padding: 30px 35px;
        }

        .modal-footer {
            border-top: none;
            padding: 20px 30px 30px;
        }

        .form-label {
            font-weight: 600;
            font-size: 13px;
            color: #333;
        }

        .form-label .text-danger {
            font-weight: 700;
        }

        .form-control, .form-select {
            border-radius: 8px;
            padding: 9px 14px;
            border: 1px solid #ced4da;
            transition: var(--transition);
            font-size: 14px;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43,58,140,0.12);
        }

        .form-control.is-invalid, .form-select.is-invalid {
            border-color: #dc3545;
        }

        .form-control.is-valid, .form-select.is-valid {
            border-color: var(--green);
        }

        .section-title {
            font-weight: 700;
            color: var(--primary);
            margin-top: 18px;
            margin-bottom: 14px;
            border-bottom: 2px solid var(--gray-200);
            padding-bottom: 8px;
            font-size: 15px;
        }

        .history-panel {
            background: var(--gray-100);
            border-radius: var(--radius);
            padding: 16px 20px;
            margin-bottom: 16px;
            border-left: 4px solid var(--primary);
        }

        .history-panel .history-title {
            font-weight: 700;
            color: var(--primary);
            font-size: 14px;
            margin-bottom: 8px;
        }

        .history-panel .history-item {
            font-size: 13px;
            color: var(--gray-700);
            padding: 4px 0;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .history-panel .history-item .case-link {
            color: var(--primary);
            font-weight: 600;
            cursor: pointer;
            text-decoration: underline;
        }

        .toast-container-custom {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 9999;
            max-width: 380px;
        }

        .toast-custom {
            background: white;
            border-radius: 12px;
            padding: 16px 20px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            border-left: 5px solid var(--green);
            display: flex;
            align-items: center;
            gap: 12px;
            transform: translateX(120%);
            transition: transform 0.4s cubic-bezier(0.34,1.56,0.64,1);
            margin-bottom: 10px;
        }

        .toast-custom.show {
            transform: translateX(0);
        }

        .toast-custom.error {
            border-left-color: #dc3545;
        }

        .toast-custom .toast-icon {
            font-size: 24px;
            color: var(--green);
        }

        .toast-custom.error .toast-icon {
            color: #dc3545;
        }

        .no-records-msg {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray-500);
        }

        .no-records-msg i {
            font-size: 48px;
            display: block;
            margin-bottom: 12px;
            opacity: 0.4;
        }

        .inline-check {
            display: flex;
            gap: 20px;
            padding-top: 4px;
            flex-wrap: wrap;
        }

        .view-detail-row {
            display: flex;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .view-detail-label {
            font-weight: 600;
            width: 150px;
            flex-shrink: 0;
            color: var(--gray-700);
        }

        .view-detail-value {
            color: var(--gray-900);
        }

        /* Vaccination Schedule Styles */
        .schedule-table-wrapper {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 18px;
        }

        .schedule-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .schedule-table thead {
            background: #f8f9fc;
        }

        .schedule-table thead th {
            padding: 12px 16px;
            text-align: left;
            font-weight: 700;
            color: var(--gray-700);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e9ecef;
        }

        .schedule-table tbody td {
            padding: 10px 16px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        .schedule-table tbody tr:last-child td {
            border-bottom: none;
        }

        .schedule-table tbody tr:hover {
            background: #fafbff;
        }

        .schedule-table .dose-label {
            font-weight: 600;
            color: var(--primary);
        }

        .schedule-table .schedule-date-input {
            padding: 6px 10px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            font-size: 13px;
            width: 130px;
            background: #fff;
            transition: var(--transition);
        }

        .schedule-table .schedule-date-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.12);
            outline: none;
        }

        .schedule-table .schedule-date-input:disabled {
            background: #f5f5f5;
            opacity: 0.7;
        }

        .schedule-table .status-select {
            padding: 6px 10px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            font-size: 13px;
            background: #fff;
            min-width: 120px;
            transition: var(--transition);
        }

        .schedule-table .status-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.12);
            outline: none;
        }

        .schedule-table .status-select.status-pending {
            border-color: #ffc107;
            background: #fff8e1;
            color: #856404;
        }

        .schedule-table .status-select.status-administered {
            border-color: #28a745;
            background: #e8f5e9;
            color: #155724;
        }

        .vaccination-summary {
            background: #f8f9fc;
            border-radius: 8px;
            padding: 14px 20px;
            margin-bottom: 14px;
            border-left: 4px solid var(--primary);
        }

        .summary-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
        }

        .summary-header strong {
            font-size: 14px;
            color: var(--gray-900);
        }

        .summary-badge {
            font-size: 12px;
            font-weight: 700;
            padding: 2px 14px;
            border-radius: 20px;
            background: #fff3cd;
            color: #856404;
        }

        .summary-badge.completed {
            background: #d4edda;
            color: #155724;
        }

        .summary-progress {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .summary-progress #progressText {
            font-size: 13px;
            color: var(--gray-600);
            white-space: nowrap;
            min-width: 140px;
        }

        .progress-bar-wrapper {
            flex: 1;
            min-width: 120px;
            height: 6px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), #4a6cf7);
            border-radius: 10px;
            transition: width 0.4s ease;
            width: 0%;
        }

        .progress-bar-fill.completed {
            background: linear-gradient(90deg, #28a745, #20c997);
        }

        .schedule-remarks {
            margin-top: 4px;
        }

        .schedule-remarks .form-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 4px;
        }

        .schedule-remarks textarea {
            border-radius: 8px;
            font-size: 13px;
            resize: vertical;
        }

        .stock-status-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 6px;
        }

        .stock-status-badge.available {
            background: #d4edda;
            color: #155724;
        }

        .stock-status-badge.low {
            background: #fff3cd;
            color: #856404;
        }

        .stock-status-badge.out-of-stock {
            background: #f8d7da;
            color: #721c24;
        }

        #customVaccCategoryContainer {
            margin-top: 8px;
        }

        .stock-warning-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 16px;
            display: none;
        }

        .stock-warning-box.show {
            display: block;
        }

        .stock-warning-box .warning-title {
            font-weight: 700;
            color: #856404;
            font-size: 14px;
        }

        .stock-warning-box .warning-detail {
            font-size: 13px;
            color: #856404;
            margin-top: 4px;
        }

        .stock-check-btn {
            font-size: 12px;
            padding: 2px 10px;
            border-radius: 12px;
            background: var(--gray-100);
            border: 1px solid var(--gray-300);
            color: var(--gray-700);
            cursor: pointer;
            transition: var(--transition);
        }

        .stock-check-btn:hover {
            background: var(--gray-200);
        }

        .stock-check-btn.has-stock {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }

        .stock-check-btn.no-stock {
            background: #f8d7da;
            border-color: #dc3545;
            color: #721c24;
        }

        @media (max-width: 768px) {
            .schedule-table .schedule-date-input {
                width: 100px;
            }
            
            .schedule-table .status-select {
                min-width: 90px;
                font-size: 12px;
            }
            
            .summary-progress {
                flex-direction: column;
                align-items: flex-start;
                gap: 6px;
            }
            
            .summary-progress #progressText {
                min-width: auto;
            }
            
            .progress-bar-wrapper {
                width: 100%;
            }
            
            .topbar h3 {
                margin-left: 100px;
                font-size: 20px;
            }
            
            .search-area .search-wrapper input {
                width: 100%;
            }
            .toolbar {
                flex-direction: column;
            }
            .record-container {
                grid-template-columns: 1fr;
            }
            .modal-body {
                padding: 20px 16px;
            }
            .view-detail-row {
                flex-direction: column;
                padding: 6px 0;
            }
            .view-detail-label {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo-area">
            <div class="logo-frame">
                <img src="logo.png" alt="Smart Bite Care Logo" class="logo">
            </div>
            <div class="system-name">Smart Bite Care</div>
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
            <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
        </div>
    </div>

    <div class="topbar">
        <h3>Patient Record Management<span style="font-size:16px; color:#6c757d; font-weight:400; margin-left:8px;"> <?php echo htmlspecialchars($branch_name); ?> </span> </h3>
        <div class="profile">
            <?php echo htmlspecialchars($logged_username); ?> 
            <i class="bi bi-caret-down-fill"></i>
        </div>
    </div>

    <!-- Main content -->
    <div class="main">
        <div class="toolbar">
            <div class="search-area">
                <div class="search-wrapper">
                    <i class="bi bi-search"></i>
                    <input type="text" id="searchInput" placeholder="Search by Case No. or Patient Name...">
                </div>
                <button class="btn btn-outline" id="clearSearchBtn">
                    <i class="bi bi-x-circle"></i> Clear
                </button>
            </div>
            <button class="btn" id="addPatientBtn">
                <i class="bi bi-plus-circle"></i> Add New Patient
            </button>
        </div>

        <div class="record-container">
            <!-- Calendar Panel -->
            <div class="calendar-panel">
                <div class="panel-header">
                    Admission Calendar 
                    <i class="bi bi-calendar3"></i>
                </div>
                <div id="calendarInline" style="padding: 8px 12px 4px;"></div>
                <div class="date-stats" id="dateStats">
                    <span id="selectedDateDisplay">Select a date</span>
                    <span class="stat-badge" id="dateCountBadge">0 patients</span>
                </div>
            </div>

            <!-- Table Panel -->
            <div>
                <div class="table-panel">
                    <div class="tabs">
                        <div class="tab">
                            Patients for <span id="selectedDateDisplay2">Today</span> 
                            <span id="patientCountBadge" class="vacc-status-badge pending">0</span>
                        </div>
                        <button class="export-btn" id="exportBtn">
                            <i class="bi bi-download"></i> Export
                        </button>
                    </div>
                    <div class="table-responsive-custom">
                        <table>
                            <thead>
                                <tr>
                                    <th>Case No.</th>
                                    <th>Patient Name</th>
                                    <th>PhilHealth</th>
                                    <th>PhilHealth Type</th>
                                    <th>Birth Date</th>
                                    <th>Age</th>
                                    <th>Gender</th>
                                    <th>Address</th>
                                    <th>Vaccination Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="patientTableBody">
                                <tr>
                                    <td colspan="10">
                                        <div class="no-records-msg">
                                            <i class="bi bi-inbox"></i>
                                            <p>Loading records...</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination" id="paginationControls"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Patient Modal (Add/Edit) -->
    <div class="modal fade" id="patientModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add New Patient</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="historyPanel" class="history-panel" style="display:none;">
                        <div class="history-title">
                            <i class="bi bi-clock-history"></i> Patient History
                        </div>
                        <div id="historyList"></div>
                        <div style="margin-top:8px;font-size:13px;color:var(--gray-600);">
                            <i class="bi bi-info-circle"></i> Adding a new case will create a new record for this patient.
                        </div>
                    </div>

                    <!-- Stock Warning Box -->
                    <div id="stockWarningBox" class="stock-warning-box">
                        <div class="warning-title">
                            <i class="bi bi-exclamation-triangle-fill"></i> Stock Warning
                        </div>
                        <div class="warning-detail" id="stockWarningDetail"></div>
                    </div>

                    <form id="patientForm">
                        <input type="hidden" id="editId" value="">
                        <input type="hidden" id="editPatientId" value="">

                        <div class="section-title">Patient Information</div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Case No. <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="caseNo" required>
                                    <div id="caseNoFeedback" class="invalid-feedback" style="display:none;"></div>
                                    <small class="text-muted">Enter a unique case number</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Patient's Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="patientName" placeholder="Last Name, First Name, Middle Initial" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Address <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="address" placeholder="Street Address" required>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <label class="form-label">Date of Birth <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control flatpickr-date" id="dob" placeholder="mm/dd/yyyy" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Age</label>
                                        <input type="number" class="form-control" id="age" readonly>
                                    </div>
                                </div>
                                <div class="mb-3 mt-2">
                                    <label class="form-label">Gender <span class="text-danger">*</span></label>
                                    <div class="d-flex gap-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="gender" id="genderMale" value="Male" required>
                                            <label class="form-check-label" for="genderMale">Male</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="gender" id="genderFemale" value="Female">
                                            <label class="form-check-label" for="genderFemale">Female</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">PhilHealth <span class="text-danger">*</span></label>
                                    <select class="form-select" id="philhealth" required>
                                        <option value="Yes">Yes</option>
                                        <option value="No" selected>No</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">PhilHealth Membership Type</label>
                                    <input type="text" class="form-control" id="philhealthType" placeholder="e.g., Sponsored, Indigent, etc.">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="contactNumber" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Admission Date <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control flatpickr-date" id="admissionDate" required>
                                </div>
                            </div>
                        </div>

                        <hr>
                        <div class="section-title">Bite & Animal Details</div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Date of Bite <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control flatpickr-date" id="dateOfBite" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Site of Bite <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="siteOfBite" placeholder="e.g., Right arm, Left leg" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Biting Animal <span class="text-danger">*</span></label>
                                    <select class="form-select" id="bitingAnimal" required>
                                        <option value="Dog">Dog</option>
                                        <option value="Cat">Cat</option>
                                        <option value="Not Applicable">Not Applicable</option>
                                        <option value="Others">Others</option>
                                    </select>
                                </div>
                                <div id="customAnimalContainer" style="display:none;">
                                    <label class="form-label">Specify Animal <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="customAnimal" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Status of the Biting Animal <span class="text-danger">*</span></label>
                                    <select class="form-select" id="animalStatus" required>
                                        <option value="Alive/Healthy">Alive/Healthy</option>
                                        <option value="Sick">Sick</option>
                                        <option value="Died">Died</option>
                                        <option value="Unknown">Unknown</option>
                                        <option value="Not Applicable">Not Applicable</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <hr>
                        <div class="section-title">Vaccination & Treatment</div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">ERIG (mL)</label>
                                    <input type="number" class="form-control" id="erigMl" step="0.1" min="0" placeholder="eg. .5mL, 1mL, 2mL, etc">
                                </div>
                                <div class="mb-3 inline-check">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="ats">
                                        <label class="form-check-label" for="ats">Anti-Tetanus Serum (ATS)</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="tt">
                                        <label class="form-check-label" for="tt">Tetanus-Toxoid</label>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Route</label>
                                    <select class="form-select" id="route">
                                        <option value="Intradermal (ID)">Intradermal (ID)</option>
                                        <option value="Intramuscular (IM)">Intramuscular (IM)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Active Regimen <span class="text-danger">*</span></label>
                                    <select class="form-select" id="activeRegimen" required>
                                        <option value="PVRV TRC SPEEDA">PVRV TRC SPEEDA</option>
                                        <option value="PVRV TRC ABHAYRAB">PVRV TRC ABHAYRAB</option>
                                        <option value="OTHER">OTHER</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Vaccination Category <span class="text-danger">*</span></label>
                                    <select class="form-select" id="vaccCategory" required>
                                        <option value="Pre-Exposure Prophylaxis (PrEP)">Pre-Exposure Prophylaxis (PrEP)</option>
                                        <option value="Post-Exposure Prophylaxis (PEP)" selected>Post-Exposure Prophylaxis (PEP)</option>
                                        <option value="Booster Dose">Booster Dose</option>
                                        <option value="Others">Others</option>
                                    </select>
                                </div>
                                <div id="customVaccCategoryContainer" style="display:none;">
                                    <label class="form-label">Specify Vaccination Category <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="customVaccCategory" placeholder="Enter custom category">
                                </div>
                            </div>
                        </div>

                        <!-- Vaccination Schedule Section -->
                        <div id="scheduleSection">
                            <div class="section-title">
                                Vaccination Schedule
                                <button type="button" class="btn btn-sm btn-outline-primary ms-2 stock-check-btn" id="checkStockBtn" title="Check vaccine stock availability">
                                    <i class="bi bi-box-seam"></i> Check Stock
                                </button>
                                <span id="stockStatusBadge" style="display:none;"></span>
                            </div>
                            
                            <div class="schedule-table-wrapper">
                                <table class="schedule-table">
                                    <thead>
                                        <tr>
                                            <th>Dose</th>
                                            <th>Scheduled Date</th>
                                            <th>Administered Date</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="scheduleTableBody">
                                        <!-- Rows will be populated by JavaScript -->
                                    </tbody>
                                </table>
                            </div>

                            <!-- Vaccination Status Summary -->
                            <div class="vaccination-summary">
                                <div class="summary-header">
                                    <strong>Vaccination Status (Overall)</strong>
                                    <span class="summary-badge" id="vaccStatusBadge">In Progress</span>
                                </div>
                                <div class="summary-progress">
                                    <span id="progressText">0 of 0 doses completed</span>
                                    <div class="progress-bar-wrapper">
                                        <div class="progress-bar-fill" id="progressBarFill" style="width: 0%;"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Remarks -->
                            <div class="schedule-remarks">
                                <label class="form-label">Remarks</label>
                                <textarea class="form-control" id="vaccinationRemarks" rows="2" placeholder="Enter remarks (optional)..."></textarea>
                            </div>
                        </div>

                        <hr>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label">Philhealth Status <span class="text-danger">*</span></label>
                                    <select class="form-select" id="status" required>
                                        <option value="Awaiting Processing">Awaiting Processing</option>
                                        <option value="For Writing">For Writing</option>
                                        <option value="For Screening">For Screening</option>
                                        <option value="For Signing">For Signing/Transmittal</option>
                                        <option value="Completed">Completed</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="savePatientBtn">Save Patient</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Modal -->
    <div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Patient Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewModalBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this record?</p>
                    <p class="text-danger" id="deletePatientName"></p>
                    <p class="text-warning"><small>This action cannot be undone.</small></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container-custom" id="toastContainer"></div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    // ----------------------------------------------------------------
    // FRONTEND JAVASCRIPT
    // ----------------------------------------------------------------
    const apiBase = window.location.href.split('?')[0];
    let currentAdmissionDate = '<?php echo date('m/d/Y'); ?>';
    let currentPage = 1;
    const pageSize = 8;
    let searchTerm = '';
    let deleteTargetCaseId = null;
    let allPatients = [];
    let isCheckingCaseNo = false;
    let currentStockCheck = null;

    // Flatpickr instances
    let flatpickrInstances = [];

    // Define dose configurations with labels
    const DOSE_CONFIG = [
        { key: 'd0', label: 'D0', doseNum: 1 },
        { key: 'd3', label: 'D3', doseNum: 2 },
        { key: 'd7', label: 'D7', doseNum: 3 },
        { key: 'd14', label: 'D14', doseNum: 4 },
        { key: 'd21', label: 'D21', doseNum: 5 },
        { key: 'd28', label: 'D28/30', doseNum: 6 }
    ];

    // Store dose data for current patient
    let currentDoseData = {};
    let currentDoseKeys = [];

    function initFlatpickrs() {
        const config = {
            dateFormat: 'm/d/Y',
            allowInput: true,
            altInput: true,
            altFormat: 'm/d/Y'
        };
        document.querySelectorAll('.flatpickr-date').forEach(el => {
            const fp = flatpickr(el, config);
            flatpickrInstances.push(fp);
        });
    }

    // Toast notification
    function showToast(msg, sub = '', isError = false) {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = 'toast-custom' + (isError ? ' error' : '');
        const icon = isError ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill';
        toast.innerHTML = `
            <span class="toast-icon"><i class="bi ${icon}"></i></span>
            <div class="toast-msg">${msg} ${sub ? '<small>' + sub + '</small>' : ''}</div>
        `;
        container.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('show'));
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 400);
        }, 3500);
    }

    // Convert frontend date (m/d/Y) to Y-m-d for API
    function frontToApi(dateStr) {
        if (!dateStr) return '';
        const parts = dateStr.split('/');
        if (parts.length !== 3) return '';
        return `${parts[2]}-${parts[0].padStart(2,'0')}-${parts[1].padStart(2,'0')}`;
    }

    // Convert Y-m-d to m/d/Y
    function apiToFront(dateStr) {
        if (!dateStr) return '';
        const parts = dateStr.split('-');
        if (parts.length !== 3) return '';
        return `${parts[1]}/${parts[2]}/${parts[0]}`;
    }

    // ============================================================
    // STOCK CHECK FUNCTION
    // ============================================================

    async function checkVaccineStock(vaccineName, doseCount = 1) {
        if (!vaccineName) return null;
        try {
            const url = `${apiBase}?action=check_vaccine_stock&vaccine_name=${encodeURIComponent(vaccineName)}&dose_count=${doseCount}`;
            const res = await fetch(url);
            const data = await res.json();
            return data;
        } catch (e) {
            console.error('Error checking stock:', e);
            return null;
        }
    }

    function updateStockStatusDisplay(stockData) {
        const badge = document.getElementById('stockStatusBadge');
        const warningBox = document.getElementById('stockWarningBox');
        const warningDetail = document.getElementById('stockWarningDetail');
        
        if (!stockData || !stockData.success) {
            badge.style.display = 'none';
            warningBox.classList.remove('show');
            return;
        }
        
        badge.style.display = 'inline-block';
        
        if (stockData.has_sufficient_stock) {
            if (stockData.is_low_stock) {
                badge.innerHTML = `<span class="stock-status-badge low">⚠️ Low Stock (${stockData.available_stock} left)</span>`;
                warningBox.classList.add('show');
                warningDetail.innerHTML = `
                    <strong>Low Stock Warning:</strong> ${stockData.item_name} has only ${stockData.available_stock} units available. 
                    Minimum threshold is ${stockData.minimum_stock}. Please reorder soon.
                    ${stockData.earliest_expiry ? ` Earliest expiry: ${apiToFront(stockData.earliest_expiry)}` : ''}
                `;
            } else {
                badge.innerHTML = `<span class="stock-status-badge available">✅ In Stock (${stockData.available_stock} available)</span>`;
                warningBox.classList.remove('show');
            }
        } else {
            badge.innerHTML = `<span class="stock-status-badge out-of-stock">❌ Out of Stock</span>`;
            warningBox.classList.add('show');
            warningDetail.innerHTML = `
                <strong>Out of Stock Warning:</strong> ${stockData.item_name} has only ${stockData.available_stock} units available. 
                You need ${stockData.needed} units for the administered doses.
                ${stockData.earliest_expiry ? ` Earliest expiry: ${apiToFront(stockData.earliest_expiry)}` : ''}
            `;
        }
    }

    // ============================================================
    // VACCINATION SCHEDULE FUNCTIONS
    // ============================================================

    function getDoseKeysForCategory(category, route) {
        switch (category) {
            case 'Pre-Exposure Prophylaxis (PrEP)':
                return ['d0', 'd7', 'd21'];
            case 'Post-Exposure Prophylaxis (PEP)':
                if (route === 'Intradermal (ID)') {
                    return ['d0', 'd3', 'd7', 'd28'];
                } else {
                    return ['d0', 'd3', 'd7', 'd14', 'd28'];
                }
            case 'Booster Dose':
                return ['d0', 'd3'];
            case 'Others':
                return [];
            default:
                return ['d0', 'd3', 'd7', 'd14', 'd28'];
        }
    }

    function calculateAutoSchedule(day0Date) {
        if (!day0Date) return {};
        const baseDate = new Date(day0Date);
        if (isNaN(baseDate.getTime())) return {};
        const schedules = {};
        const doseMap = {
            'd0': 0, 'd3': 3, 'd7': 7, 'd14': 14, 'd21': 21, 'd28': 28
        };
        for (const [key, days] of Object.entries(doseMap)) {
            const date = new Date(baseDate);
            date.setDate(date.getDate() + days);
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            const year = date.getFullYear();
            schedules[key] = `${month}/${day}/${year}`;
        }
        return schedules;
    }

    function updateScheduleBasedOnCategory() {
        const category = document.getElementById('vaccCategory').value;
        const route = document.getElementById('route').value;
        
        const customContainer = document.getElementById('customVaccCategoryContainer');
        if (category === 'Others') {
            customContainer.style.display = 'block';
            document.getElementById('customVaccCategory').setAttribute('required', 'required');
        } else {
            customContainer.style.display = 'none';
            document.getElementById('customVaccCategory').removeAttribute('required');
        }
        
        const doseKeys = getDoseKeysForCategory(category, route);
        currentDoseKeys = doseKeys;
        
        const currentData = getCurrentDoseData();
        const newDoseData = {};
        DOSE_CONFIG.forEach(d => {
            if (doseKeys.includes(d.key)) {
                newDoseData[d.key] = currentData[d.key] || {
                    scheduled_date: '',
                    administered_date: '',
                    status: 'Pending'
                };
            }
        });
        
        renderScheduleTable(newDoseData);
        
        const scheduleSection = document.getElementById('scheduleSection');
        if (category === 'Others') {
            scheduleSection.style.display = 'none';
        } else {
            scheduleSection.style.display = 'block';
        }
        
        // Clear stock status when category changes
        currentStockCheck = null;
        document.getElementById('stockStatusBadge').style.display = 'none';
        document.getElementById('stockWarningBox').classList.remove('show');
    }

    function renderScheduleTable(doseData = null) {
        const tbody = document.getElementById('scheduleTableBody');
        
        if (!doseData) {
            doseData = {};
            currentDoseKeys.forEach(key => {
                doseData[key] = {
                    scheduled_date: '',
                    administered_date: '',
                    status: 'Pending'
                };
            });
        }
        
        currentDoseData = doseData;
        
        let html = '';
        DOSE_CONFIG.forEach(d => {
            if (!currentDoseKeys.includes(d.key)) return;
            
            const data = doseData[d.key] || { scheduled_date: '', administered_date: '', status: 'Pending' };
            const statusClass = data.status === 'Administered' ? 'status-administered' : 'status-pending';
            
            html += `
                <tr data-dose="${d.key}">
                    <td class="dose-label">${d.label}</td>
                    <td>
                        <input type="text" class="form-control form-control-sm schedule-date-input flatpickr-date" 
                               id="sched_${d.key}" value="${data.scheduled_date || ''}" 
                               placeholder="mm/dd/yyyy"
                               data-dose="${d.key}">
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm schedule-date-input flatpickr-date" 
                               id="admin_${d.key}" value="${data.administered_date || ''}" 
                               placeholder="mm/dd/yyyy"
                               data-dose="${d.key}"
                               ${data.status === 'Pending' ? '' : 'disabled'}>
                    </td>
                    <td>
                        <select class="form-select form-select-sm status-select ${statusClass}" 
                                id="status_${d.key}" 
                                data-dose="${d.key}">
                            <option value="Pending" ${data.status === 'Pending' ? 'selected' : ''}>Pending</option>
                            <option value="Administered" ${data.status === 'Administered' ? 'selected' : ''}>Administered</option>
                        </select>
                    </td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
        
        initScheduleFlatpickrs();
        
        document.querySelectorAll('.status-select').forEach(sel => {
            sel.addEventListener('change', function() {
                const doseKey = this.dataset.dose;
                const isAdministered = this.value === 'Administered';
                const adminInput = document.getElementById(`admin_${doseKey}`);
                adminInput.disabled = !isAdministered;
                if (isAdministered && !adminInput.value) {
                    const today = new Date();
                    const month = String(today.getMonth() + 1).padStart(2, '0');
                    const day = String(today.getDate()).padStart(2, '0');
                    const year = today.getFullYear();
                    adminInput.value = `${month}/${day}/${year}`;
                }
                this.className = `form-select form-select-sm status-select ${isAdministered ? 'status-administered' : 'status-pending'}`;
                updateVaccinationSummary();
                // Re-check stock when status changes
                checkStockForCurrentRegimen();
            });
        });
        
        document.querySelectorAll('.schedule-date-input').forEach(input => {
            if (input.id.startsWith('sched_')) {
                input.addEventListener('change', function() {
                    const doseKey = this.dataset.dose;
                    if (doseKey === 'd0') {
                        autoCalculateSchedules(this.value);
                    }
                });
            }
        });
        
        updateVaccinationSummary();
    }

    function initScheduleFlatpickrs() {
        const config = {
            dateFormat: 'm/d/Y',
            allowInput: true,
            altInput: true,
            altFormat: 'm/d/Y'
        };
        document.querySelectorAll('.schedule-date-input').forEach(el => {
            if (el._flatpickr) {
                el._flatpickr.destroy();
            }
            flatpickr(el, config);
        });
    }

    function autoCalculateSchedules(day0Date) {
        if (!day0Date) return;
        const autoSchedules = calculateAutoSchedule(day0Date);
        if (Object.keys(autoSchedules).length === 0) return;
        DOSE_CONFIG.forEach(d => {
            if (d.key === 'd0') return;
            if (!currentDoseKeys.includes(d.key)) return;
            const input = document.getElementById(`sched_${d.key}`);
            if (input && autoSchedules[d.key]) {
                if (!input.value || input.dataset.autoCalculated !== 'false') {
                    input.value = autoSchedules[d.key];
                    input.dataset.autoCalculated = 'true';
                }
            }
        });
        const updatedData = getCurrentDoseData();
        renderScheduleTable(updatedData);
    }

    function getCurrentDoseData() {
        const data = {};
        DOSE_CONFIG.forEach(d => {
            if (currentDoseKeys.includes(d.key)) {
                data[d.key] = {
                    scheduled_date: document.getElementById(`sched_${d.key}`)?.value || '',
                    administered_date: document.getElementById(`admin_${d.key}`)?.value || '',
                    status: document.getElementById(`status_${d.key}`)?.value || 'Pending'
                };
            }
        });
        return data;
    }

    function updateVaccinationSummary() {
        const doseData = getCurrentDoseData();
        let completed = 0;
        const total = currentDoseKeys.length;
        
        currentDoseKeys.forEach(key => {
            if (doseData[key] && doseData[key].status === 'Administered') {
                completed++;
            }
        });
        
        const progressText = document.getElementById('progressText');
        const progressBarFill = document.getElementById('progressBarFill');
        const statusBadge = document.getElementById('vaccStatusBadge');
        
        progressText.textContent = `${completed} of ${total} doses completed`;
        const percentage = total > 0 ? (completed / total) * 100 : 0;
        progressBarFill.style.width = `${percentage}%`;
        
        if (total > 0 && completed === total) {
            statusBadge.textContent = 'Completed';
            statusBadge.className = 'summary-badge completed';
            progressBarFill.className = 'progress-bar-fill completed';
        } else {
            statusBadge.textContent = total > 0 ? 'In Progress' : 'No Doses';
            statusBadge.className = 'summary-badge';
            progressBarFill.className = 'progress-bar-fill';
        }
    }

    function loadDoseDataForEdit(doseData) {
        if (!doseData) {
            renderScheduleTable();
            return;
        }
        renderScheduleTable(doseData);
    }

    function initNewSchedule() {
        const category = document.getElementById('vaccCategory').value;
        const route = document.getElementById('route').value;
        currentDoseKeys = getDoseKeysForCategory(category, route);
        renderScheduleTable();
        document.getElementById('vaccinationRemarks').value = '';
        const scheduleSection = document.getElementById('scheduleSection');
        if (category === 'Others') {
            scheduleSection.style.display = 'none';
        } else {
            scheduleSection.style.display = 'block';
        }
        currentStockCheck = null;
        document.getElementById('stockStatusBadge').style.display = 'none';
        document.getElementById('stockWarningBox').classList.remove('show');
    }

    // ============================================================
    // STOCK CHECK FOR CURRENT REGIMEN
    // ============================================================

    async function checkStockForCurrentRegimen() {
        const regimen = document.getElementById('activeRegimen').value;
        const vaccineMap = {
            'PVRV TRC SPEEDA': 'SPEEDA',
            'PVRV TRC ABHAYRAB': 'ABHAYRAB'
        };
        const vaccineName = vaccineMap[regimen] || null;
        
        if (!vaccineName) {
            document.getElementById('stockStatusBadge').style.display = 'none';
            document.getElementById('stockWarningBox').classList.remove('show');
            return;
        }
        
        // Count administered doses
        const doseData = getCurrentDoseData();
        const administeredDoses = Object.values(doseData).filter(d => d.status === 'Administered').length;
        
        if (administeredDoses === 0) {
            document.getElementById('stockStatusBadge').style.display = 'none';
            document.getElementById('stockWarningBox').classList.remove('show');
            return;
        }
        
        const stockData = await checkVaccineStock(vaccineName, administeredDoses);
        currentStockCheck = stockData;
        updateStockStatusDisplay(stockData);
    }

    // ============================================================
    // END VACCINATION SCHEDULE FUNCTIONS
    // ============================================================

    async function checkCaseNo(caseNo, excludeId = null) {
        if (!caseNo || caseNo.trim() === '') return false;
        let url = `${apiBase}?action=check_case_no&case_no=${encodeURIComponent(caseNo.trim())}`;
        if (excludeId) url += `&exclude_id=${excludeId}`;
        try {
            const res = await fetch(url);
            const data = await res.json();
            return data.exists || false;
        } catch (e) {
            return false;
        }
    }

    document.getElementById('caseNo').addEventListener('blur', async function() {
        const caseNo = this.value.trim();
        const excludeId = parseInt(document.getElementById('editId').value) || null;
        if (!caseNo) {
            this.classList.remove('is-valid', 'is-invalid');
            document.getElementById('caseNoFeedback').style.display = 'none';
            return;
        }
        isCheckingCaseNo = true;
        const exists = await checkCaseNo(caseNo, excludeId);
        isCheckingCaseNo = false;
        if (exists) {
            this.classList.add('is-invalid');
            this.classList.remove('is-valid');
            const feedback = document.getElementById('caseNoFeedback');
            feedback.textContent = 'This case number already exists. Please use a unique case number.';
            feedback.style.display = 'block';
        } else {
            this.classList.remove('is-invalid');
            this.classList.add('is-valid');
            document.getElementById('caseNoFeedback').style.display = 'none';
        }
    });

    async function fetchPatients(dateApi = null, search = '') {
        let url = `${apiBase}?action=fetch`;
        if (dateApi) url += `&date=${encodeURIComponent(dateApi)}`;
        if (search) url += `&search=${encodeURIComponent(search)}`;
        const res = await fetch(url);
        if (!res.ok) {
            const data = await res.json();
            throw new Error(data.error || 'Fetch failed');
        }
        return await res.json();
    }

    async function renderTable() {
        try {
            let dateApi = '';
            if (!searchTerm.trim()) {
                dateApi = frontToApi(currentAdmissionDate);
            }
            allPatients = await fetchPatients(dateApi, searchTerm.trim());

            const total = allPatients.length;
            const totalPages = Math.ceil(total / pageSize) || 1;
            if (currentPage > totalPages) currentPage = totalPages;
            const start = (currentPage - 1) * pageSize;
            const items = allPatients.slice(start, start + pageSize);

            document.getElementById('dateCountBadge').textContent = total + ' patient' + (total !== 1 ? 's' : '');
            document.getElementById('patientCountBadge').textContent = total;
            
            const displayDate = dateApi ? new Date(dateApi + 'T00:00:00') : new Date();
            const dateStr = displayDate.toLocaleDateString('en-US', { month:'long', day:'numeric', year:'numeric' });
            document.getElementById('selectedDateDisplay').textContent = dateStr || 'Select a date';
            document.getElementById('selectedDateDisplay2').textContent = dateStr || 'Today';

            const tbody = document.getElementById('patientTableBody');
            if (items.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="10">
                            <div class="no-records-msg">
                                <i class="bi bi-inbox"></i>
                                <p>No records found for this date.</p>
                            </div>
                        </td>
                    </tr>
                `;
            } else {
                let html = '';
                items.forEach(p => {
                    const philBadge = p.philhealth === 'Yes' ? 
                        '<span class="status-yes">Yes</span>' : 
                        '<span class="status-no">No</span>';
                    const vaccBadge = p.vaccination_status === 'Completed' ? 
                        '<span class="vacc-status-badge completed">Completed</span>' : 
                        '<span class="vacc-status-badge pending">Pending</span>';
                    html += `
                        <tr>
                            <td><strong>${p.case_no}</strong></td>
                            <td>${p.patient_name}</td>
                            <td>${philBadge}</td>
                            <td>${p.philhealth_type || ''}</td>
                            <td>${p.dob}</td>
                            <td>${p.age ?? ''}</td>
                            <td>${p.gender || ''}</td>
                            <td>${p.address || ''}</td>
                            <td>${vaccBadge}</td>
                            <td>
                                <div class="action-icons">
                                    <i class="bi bi-eye" data-action="view" data-case="${p.case_id}" title="View"></i>
                                    <i class="bi bi-pencil" data-action="edit" data-case="${p.case_id}" title="Edit"></i>
                                    <i class="bi bi-trash" data-action="delete" data-case="${p.case_id}" title="Delete"></i>
                                </div>
                            </td>
                        </tr>
                    `;
                });
                tbody.innerHTML = html;

                document.querySelectorAll('[data-action]').forEach(el => {
                    el.addEventListener('click', function() {
                        const action = this.dataset.action;
                        const caseId = parseInt(this.dataset.case);
                        if (action === 'view') viewPatient(caseId);
                        else if (action === 'edit') editPatient(caseId);
                        else if (action === 'delete') confirmDelete(caseId);
                    });
                });
            }

            let pagHtml = '';
            if (totalPages > 1) {
                for (let i = 1; i <= totalPages; i++) {
                    pagHtml += `<div class="page ${i === currentPage ? 'active' : ''}" data-page="${i}">${i}</div>`;
                }
            }
            document.getElementById('paginationControls').innerHTML = pagHtml;
            document.querySelectorAll('.page').forEach(el => {
                el.addEventListener('click', function() {
                    currentPage = parseInt(this.dataset.page);
                    renderTable();
                });
            });
        } catch (err) {
            console.error(err);
            showToast('Error loading records', err.message, true);
        }
    }

    function initCalendar() {
        const calendarEl = document.getElementById('calendarInline');
        flatpickr(calendarEl, {
            inline: true,
            dateFormat: 'm/d/Y',
            defaultDate: currentAdmissionDate,
            onChange: function(selectedDates, dateStr) {
                if (dateStr) {
                    currentAdmissionDate = dateStr;
                    currentPage = 1;
                    renderTable();
                }
            },
            monthSelectorType: 'dropdown',
            yearSelectorType: 'dropdown'
        });
    }

    async function viewPatient(caseId) {
        try {
            const res = await fetch(`${apiBase}?action=view&case_id=${caseId}`);
            if (!res.ok) throw new Error('Not found');
            const data = await res.json();

            const fields = [
                ['Case No.', data.case_no || ''],
                ['Patient Name', data.patient_name || ''],
                ['Address', data.address || ''],
                ['Date of Birth', data.dob || ''],
                ['Age', data.age ?? ''],
                ['Gender', data.gender || ''],
                ['PhilHealth', data.has_philhealth || 'No'],
                ['PhilHealth Type', data.philhealth_membership || ''],
                ['Contact', data.contact_number || ''],
                ['Admission Date', data.admission_date || ''],
                ['Date of Bite', data.date_of_bite || ''],
                ['Site of Bite', data.site_of_bite || ''],
                ['Biting Animal', data.biting_animal || ''],
                ['Animal Status', data.animal_status || ''],
                ['ERIG (mL)', data.erig_ml || '0'],
                ['Anti-Tetanus Serum (ATS)', data.ats ? 'Yes' : 'No'],
                ['Tetanus-Toxoid', data.tt ? 'Yes' : 'No'],
                ['Active Regimen', data.active_regimen || ''],
                ['Vaccination Category', data.vacc_category || ''],
                ['Vaccination Status', data.vaccination_status || 'Pending'],
                ['Record Status', data.status || 'For Writing'],
                ['Remarks', data.remarks || '']
            ];

            let html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 20px;">';
            fields.forEach((f, index) => {
                html += `
                    <div class="view-detail-row" style="${index % 2 === 0 ? '' : 'border-bottom:none;'}">
                        <span class="view-detail-label">${f[0]}</span>
                        <span class="view-detail-value">${f[1]}</span>
                    </div>
                `;
            });
            html += '</div>';

            document.getElementById('viewModalBody').innerHTML = html;
            new bootstrap.Modal(document.getElementById('viewModal')).show();
        } catch (e) {
            showToast('Error viewing patient', e.message, true);
        }
    }

    async function editPatient(caseId) {
        try {
            const res = await fetch(`${apiBase}?action=view&case_id=${caseId}`);
            const data = await res.json();

            document.getElementById('editId').value = data.case_id;
            document.getElementById('editPatientId').value = data.patient_id;
            document.getElementById('modalTitle').textContent = 'Edit Patient';
            document.getElementById('caseNo').value = data.case_no || '';
            document.getElementById('caseNo').classList.remove('is-valid', 'is-invalid');
            document.getElementById('caseNoFeedback').style.display = 'none';
            
            document.getElementById('patientName').value = data.patient_name || '';
            document.getElementById('address').value = data.address || '';
            document.getElementById('dob').value = data.dob || '';
            document.getElementById('age').value = data.age ?? '';
            
            document.querySelectorAll('input[name="gender"]').forEach(el => {
                el.checked = el.value === data.gender;
            });
            
            document.getElementById('philhealth').value = data.has_philhealth === 'Yes' ? 'Yes' : 'No';
            document.getElementById('philhealthType').value = data.philhealth_membership || '';
            document.getElementById('contactNumber').value = data.contact_number || '';
            document.getElementById('admissionDate').value = data.admission_date || '';
            document.getElementById('dateOfBite').value = data.date_of_bite || '';
            document.getElementById('siteOfBite').value = data.site_of_bite || '';
            document.getElementById('bitingAnimal').value = data.biting_animal || 'Dog';
            document.getElementById('animalStatus').value = data.animal_status || '';
            document.getElementById('erigMl').value = data.erig_ml || '';
            document.getElementById('ats').checked = data.ats || false;
            document.getElementById('tt').checked = data.tt || false;
            document.getElementById('activeRegimen').value = data.active_regimen || '';
            document.getElementById('vaccCategory').value = data.vacc_category || 'Post-Exposure Prophylaxis (PEP)';
            document.getElementById('route').value = data.route || 'Intradermal (ID)';
            document.getElementById('status').value = data.status || 'For Writing';

            const category = document.getElementById('vaccCategory').value;
            const route = document.getElementById('route').value;
            currentDoseKeys = getDoseKeysForCategory(category, route);
            
            if (data.vaccination_doses) {
                loadDoseDataForEdit(data.vaccination_doses);
            } else {
                renderScheduleTable();
            }

            document.getElementById('vaccinationRemarks').value = data.vaccination_remarks || '';

            const scheduleSection = document.getElementById('scheduleSection');
            if (category === 'Others') {
                scheduleSection.style.display = 'none';
            } else {
                scheduleSection.style.display = 'block';
            }

            togglePhilhealthStatus();

            if (data.patient_id) {
                const histRes = await fetch(`${apiBase}?action=patient_history&patient_id=${data.patient_id}`);
                const hist = await histRes.json();
                if (hist && hist.length > 0) {
                    let histHtml = '';
                    hist.forEach(h => {
                        histHtml += `
                            <div class="history-item">
                                <span>${h.case_no || 'N/A'} (${h.admit_date || ''})</span>
                                <span class="badge bg-${h.status === 'Completed' ? 'success' : 'warning'}">${h.status || 'Ongoing'}</span>
                            </div>
                        `;
                    });
                    document.getElementById('historyList').innerHTML = histHtml;
                    document.getElementById('historyPanel').style.display = 'block';
                } else {
                    document.getElementById('historyPanel').style.display = 'none';
                }
            }

            // Check stock when editing
            setTimeout(() => checkStockForCurrentRegimen(), 500);

            new bootstrap.Modal(document.getElementById('patientModal')).show();
        } catch (e) {
            showToast('Error loading patient', e.message, true);
        }
    }

    function confirmDelete(caseId) {
        deleteTargetCaseId = caseId;
        const patient = allPatients.find(p => p.case_id === caseId);
        document.getElementById('deletePatientName').textContent = patient ? 
            `Patient: ${patient.patient_name} (Case: ${patient.case_no})` : 
            `Case ID: ${caseId}`;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }

    document.getElementById('confirmDeleteBtn').addEventListener('click', async function() {
        if (!deleteTargetCaseId) return;
        try {
            const res = await fetch(`${apiBase}?action=delete&case_id=${deleteTargetCaseId}`, { method: 'POST' });
            const data = await res.json();
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
                showToast('Record deleted successfully');
                renderTable();
            } else {
                throw new Error(data.error || 'Delete failed');
            }
        } catch (e) {
            showToast('Error deleting record', e.message, true);
        } finally {
            deleteTargetCaseId = null;
        }
    });

    function togglePhilhealthStatus() {
        const philhealth = document.getElementById('philhealth').value;
        const statusSelect = document.getElementById('status');
        if (philhealth === 'No') {
            statusSelect.disabled = true;
            statusSelect.value = 'Awaiting Processing';
        } else {
            statusSelect.disabled = false;
        }
    }

    document.getElementById('savePatientBtn').addEventListener('click', async function() {
        const caseNo = document.getElementById('caseNo').value.trim();
        if (!caseNo) {
            showToast('Error', 'Case number is required.', true);
            document.getElementById('caseNo').focus();
            return;
        }
        
        const excludeId = parseInt(document.getElementById('editId').value) || null;
        const exists = await checkCaseNo(caseNo, excludeId);
        if (exists) {
            showToast('Error', 'Case number already exists.', true);
            document.getElementById('caseNo').focus();
            return;
        }

        const formData = {
            case_id: parseInt(document.getElementById('editId').value) || null,
            patient_id: parseInt(document.getElementById('editPatientId').value) || null,
            case_no: caseNo,
            patient_name: document.getElementById('patientName').value.trim(),
            address: document.getElementById('address').value.trim(),
            dob: document.getElementById('dob').value,
            age: document.getElementById('age').value || null,
            gender: document.querySelector('input[name="gender"]:checked')?.value || '',
            philhealth: document.getElementById('philhealth').value,
            philhealth_type: document.getElementById('philhealthType').value.trim(),
            contact_number: document.getElementById('contactNumber').value.trim(),
            admission_date: document.getElementById('admissionDate').value,
            date_of_bite: document.getElementById('dateOfBite').value,
            site_of_bite: document.getElementById('siteOfBite').value.trim(),
            biting_animal: document.getElementById('bitingAnimal').value,
            animal_status: document.getElementById('animalStatus').value,
            erig_ml: parseFloat(document.getElementById('erigMl').value) || 0,
            ats: document.getElementById('ats').checked,
            tt: document.getElementById('tt').checked,
            active_regimen: document.getElementById('activeRegimen').value,
            vacc_category: document.getElementById('vaccCategory').value,
            route: document.getElementById('route').value,
            vaccination_doses: getCurrentDoseData(),
            vaccination_remarks: document.getElementById('vaccinationRemarks').value,
            status: document.getElementById('status').value,
            custom_vacc_category: document.getElementById('customVaccCategory').value.trim()
        };

        if (formData.vacc_category === 'Others') {
            const customCat = formData.custom_vacc_category;
            if (!customCat) {
                showToast('Error', 'Please specify the vaccination category.', true);
                document.getElementById('customVaccCategory').focus();
                return;
            }
            formData.vacc_category = customCat;
        }

        if (!formData.patient_name) {
            showToast('Error', 'Patient name is required.', true);
            document.getElementById('patientName').focus();
            return;
        }
        if (!formData.address) {
            showToast('Error', 'Address is required.', true);
            document.getElementById('address').focus();
            return;
        }
        if (!formData.dob) {
            showToast('Error', 'Date of birth is required.', true);
            document.getElementById('dob').focus();
            return;
        }
        if (!formData.gender) {
            showToast('Error', 'Please select a gender.', true);
            return;
        }
        if (!formData.contact_number) {
            showToast('Error', 'Contact number is required.', true);
            document.getElementById('contactNumber').focus();
            return;
        }
        if (!formData.admission_date) {
            showToast('Error', 'Admission date is required.', true);
            document.getElementById('admissionDate').focus();
            return;
        }
        if (!formData.date_of_bite) {
            showToast('Error', 'Date of bite is required.', true);
            document.getElementById('dateOfBite').focus();
            return;
        }
        if (!formData.site_of_bite) {
            showToast('Error', 'Site of bite is required.', true);
            document.getElementById('siteOfBite').focus();
            return;
        }
        if (!formData.biting_animal) {
            showToast('Error', 'Biting animal is required.', true);
            document.getElementById('bitingAnimal').focus();
            return;
        }
        if (!formData.animal_status) {
            showToast('Error', 'Animal status is required.', true);
            document.getElementById('animalStatus').focus();
            return;
        }
        if (!formData.active_regimen) {
            showToast('Error', 'Active regimen is required.', true);
            document.getElementById('activeRegimen').focus();
            return;
        }
        if (!formData.vacc_category) {
            showToast('Error', 'Vaccination category is required.', true);
            document.getElementById('vaccCategory').focus();
            return;
        }

        // Check stock before saving
        const vaccineMap = {
            'PVRV TRC SPEEDA': 'SPEEDA',
            'PVRV TRC ABHAYRAB': 'ABHAYRAB'
        };
        const vaccineName = vaccineMap[formData.active_regimen] || null;
        
        // Count administered doses
        const doseData = formData.vaccination_doses || {};
        const administeredDoses = Object.values(doseData).filter(d => d.status === 'Administered').length;
        
        if (vaccineName && administeredDoses > 0) {
            const stockCheck = await checkVaccineStock(vaccineName, administeredDoses);
            if (stockCheck && stockCheck.success) {
                if (!stockCheck.has_sufficient_stock) {
                    const confirmSave = confirm(
                        `⚠️ INSUFFICIENT STOCK!\n\n` +
                        `Vaccine: ${vaccineName}\n` +
                        `Available: ${stockCheck.available_stock} units\n` +
                        `Needed: ${administeredDoses} doses\n` +
                        `\nDo you want to continue saving the record anyway?\n` +
                        `(Stock will NOT be deducted if insufficient)`
                    );
                    if (!confirmSave) {
                        return;
                    }
                } else if (stockCheck.is_low_stock) {
                    const confirmSave = confirm(
                        `⚠️ LOW STOCK WARNING!\n\n` +
                        `Vaccine: ${vaccineName}\n` +
                        `Available: ${stockCheck.available_stock} units\n` +
                        `Needed: ${administeredDoses} doses\n` +
                        `Minimum threshold: ${stockCheck.minimum_stock}\n` +
                        `\nDo you want to continue?`
                    );
                    if (!confirmSave) {
                        return;
                    }
                }
            }
        }

        const saveBtn = this;
        const originalText = saveBtn.innerHTML;
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Saving...';

        try {
            const res = await fetch(`${apiBase}?action=save`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(formData)
            });
            const data = await res.json();
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('patientModal')).hide();
                let message = `Record saved successfully - Case #${data.case_no}`;
                if (data.stock_warnings && data.stock_warnings.length > 0) {
                    message += ' (⚠️ Stock warnings: ' + data.stock_warnings.join('; ') + ')';
                }
                showToast(message);
                renderTable();
            } else {
                throw new Error(data.error || 'Save failed');
            }
        } catch (e) {
            showToast('Error saving record', e.message, true);
        } finally {
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalText;
        }
    });

    document.getElementById('addPatientBtn').addEventListener('click', function() {
        document.getElementById('editId').value = '';
        document.getElementById('editPatientId').value = '';
        document.getElementById('modalTitle').textContent = 'Add New Patient';
        document.getElementById('patientForm').reset();
        document.getElementById('caseNo').value = '';
        document.getElementById('caseNo').classList.remove('is-valid', 'is-invalid');
        document.getElementById('caseNoFeedback').style.display = 'none';
        document.getElementById('admissionDate').value = currentAdmissionDate;
        document.getElementById('historyPanel').style.display = 'none';
        
        document.querySelectorAll('input[name="gender"]').forEach(el => {
            el.checked = false;
        });
        
        document.getElementById('erigMl').value = '';
        document.getElementById('ats').checked = false;
        document.getElementById('tt').checked = false;
        
        togglePhilhealthStatus();
        initNewSchedule();
        
        const category = document.getElementById('vaccCategory').value;
        const scheduleSection = document.getElementById('scheduleSection');
        if (category === 'Others') {
            scheduleSection.style.display = 'none';
        } else {
            scheduleSection.style.display = 'block';
        }
        
        currentStockCheck = null;
        document.getElementById('stockStatusBadge').style.display = 'none';
        document.getElementById('stockWarningBox').classList.remove('show');
        
        new bootstrap.Modal(document.getElementById('patientModal')).show();
    });

    // Stock check button
    document.getElementById('checkStockBtn').addEventListener('click', async function() {
        const btn = this;
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Checking...';
        btn.disabled = true;
        
        await checkStockForCurrentRegimen();
        
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        
        if (currentStockCheck) {
            if (currentStockCheck.has_sufficient_stock) {
                showToast('Stock check complete', `${currentStockCheck.available_stock} units available`);
            } else {
                showToast('⚠️ Insufficient stock!', `${currentStockCheck.available_stock} units available, ${currentStockCheck.needed} needed`, true);
            }
        }
    });

    document.getElementById('searchInput').addEventListener('input', function() {
        searchTerm = this.value;
        currentPage = 1;
        renderTable();
    });

    document.getElementById('clearSearchBtn').addEventListener('click', () => {
        document.getElementById('searchInput').value = '';
        searchTerm = '';
        currentPage = 1;
        renderTable();
    });

    document.getElementById('exportBtn').addEventListener('click', async function() {
        try {
            let data = allPatients;
            if (!data || data.length === 0) {
                showToast('No data to export', '', true);
                return;
            }

            const headers = [
                'Case No.', 'Patient Name', 'PhilHealth', 'PhilHealth Type', 
                'Birth Date', 'Age', 'Gender', 'Address', 
                'Vaccination Status', 'Record Status'
            ];
            
            let csv = headers.join(',') + '\n';
            data.forEach(p => {
                const row = [
                    `"${(p.case_no || '').replace(/"/g, '""')}"`,
                    `"${(p.patient_name || '').replace(/"/g, '""')}"`,
                    p.philhealth || 'No',
                    `"${(p.philhealth_type || '').replace(/"/g, '""')}"`,
                    p.dob || '',
                    p.age ?? '',
                    p.gender || '',
                    `"${(p.address || '').replace(/"/g, '""')}"`,
                    p.vaccination_status || 'Pending',
                    p.status || 'For Writing'
                ];
                csv += row.join(',') + '\n';
            });

            const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `patients_${new Date().toISOString().slice(0,10)}.csv`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(link.href);
            
            showToast('Export successful', `${data.length} records exported`);
        } catch (e) {
            showToast('Export failed', e.message, true);
        }
    });

    document.getElementById('dob').addEventListener('change', function() {
        const dob = this.value;
        if (dob) {
            const parts = dob.split('/');
            if (parts.length === 3) {
                const birth = new Date(parseInt(parts[2]), parseInt(parts[0]) - 1, parseInt(parts[1]));
                const today = new Date();
                let age = today.getFullYear() - birth.getFullYear();
                const m = today.getMonth() - birth.getMonth();
                if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) {
                    age--;
                }
                if (age >= 0 && age < 120) {
                    document.getElementById('age').value = age;
                }
            }
        }
    });

    document.getElementById('bitingAnimal').addEventListener('change', function() {
        document.getElementById('customAnimalContainer').style.display = 
            this.value === 'Others' ? 'block' : 'none';
    });

    document.getElementById('philhealth').addEventListener('change', function() {
        togglePhilhealthStatus();
        document.getElementById('philhealthType').disabled = this.value === 'No';
        if (this.value === 'No') {
            document.getElementById('philhealthType').value = '';
        }
    });

    document.getElementById('vaccCategory').addEventListener('change', function() {
        const customContainer = document.getElementById('customVaccCategoryContainer');
        if (this.value === 'Others') {
            customContainer.style.display = 'block';
            document.getElementById('customVaccCategory').setAttribute('required', 'required');
        } else {
            customContainer.style.display = 'none';
            document.getElementById('customVaccCategory').removeAttribute('required');
        }
        updateScheduleBasedOnCategory();
    });

    document.getElementById('route').addEventListener('change', function() {
        updateScheduleBasedOnCategory();
    });

    document.getElementById('activeRegimen').addEventListener('change', function() {
        // Check stock when regimen changes
        setTimeout(() => checkStockForCurrentRegimen(), 300);
    });

    document.addEventListener('DOMContentLoaded', () => {
        initFlatpickrs();
        initCalendar();
        renderTable();
        
        const category = document.getElementById('vaccCategory').value;
        const route = document.getElementById('route').value;
        currentDoseKeys = getDoseKeysForCategory(category, route);
        renderScheduleTable();
        togglePhilhealthStatus();
        
        const customContainer = document.getElementById('customVaccCategoryContainer');
        if (category === 'Others') {
            customContainer.style.display = 'block';
            document.getElementById('customVaccCategory').setAttribute('required', 'required');
            document.getElementById('scheduleSection').style.display = 'none';
        } else {
            customContainer.style.display = 'none';
            document.getElementById('customVaccCategory').removeAttribute('required');
            document.getElementById('scheduleSection').style.display = 'block';
        }
        
        // Initial stock check
        setTimeout(() => checkStockForCurrentRegimen(), 1000);
    });
    </script>
</body>
</html>