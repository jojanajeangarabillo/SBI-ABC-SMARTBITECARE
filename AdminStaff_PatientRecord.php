<?php
// ----------------------------------------------------------------------
// 1. SESSION & AUTH
// ----------------------------------------------------------------------
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['branch_id'])) {
    // Not logged in – redirect to login page
    header('Location: login.php');
    exit;
}

$logged_user_id   = (int) $_SESSION['user_id'];
$logged_branch_id = $_SESSION['branch_id'];
$logged_username  = $_SESSION['username'] ?? 'Unknown User';
$logged_role      = $_SESSION['role_name'] ?? 'Admin Staff';

// ----------------------------------------------------------------------
// 2. DATABASE CONFIGURATION
// ----------------------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'smartbitecare');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// ----------------------------------------------------------------------
// 3. HELPER FUNCTIONS
// ----------------------------------------------------------------------

/**
 * Convert a date from m/d/y (frontend format) to Y-m-d (MySQL format).
 */
function frontToDbDate(?string $date): ?string {
    if (empty($date)) return null;
    $parts = explode('/', $date);
    if (count($parts) !== 3) return null;
    $m  = (int)$parts[0];
    $d  = (int)$parts[1];
    $y  = 2000 + (int)$parts[2];
    if ($y < 1900 || $y > 2100) return null;
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

/**
 * Convert a date from Y-m-d (MySQL) to m/d/y for frontend display.
 */
function dbToFrontDate(?string $date): string {
    if (empty($date)) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt ? $dt->format('m/d/y') : '';
}

/**
 * Calculate age in years from birthday (Y-m-d).
 */
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

/**
 * Log an action to audit_logs.
 */
function auditLog(PDO $pdo, int $userId, string $branchId, string $action, string $module = 'Patient Record') {
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, branch_id, action, module) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $branchId, $action, $module]);
    } catch (Exception $e) {
        // Silently fail - logging shouldn't break the main operation
    }
}

/**
 * Send a JSON response and exit.
 */
function jsonResponse($data, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Auto-generate case number (branch-specific)
 */
function generateCaseNo(PDO $pdo, string $branchId): string {
    $year = date('y');
    // Get the branch prefix based on branch_id
    $branchPrefix = $branchId;
    
    $stmt = $pdo->prepare("
        SELECT MAX(CAST(SUBSTRING(registry_number, LOCATE('-', registry_number) + 1) AS UNSIGNED))
        FROM registry_records r
        JOIN animal_bite_cases c ON r.case_id = c.case_id
        WHERE c.branch_id = ? AND r.registry_number LIKE ?
    ");
    $stmt->execute([$branchId, $year . '-%']);
    $max = $stmt->fetchColumn();
    $next = ($max ? (int)$max + 1 : 1);
    return $year . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
}

/**
 * Get a default vaccine item ID for vaccination records
 * If no vaccine exists, create a default one
 */
function getDefaultVaccineItemId(PDO $pdo): int {
    // Try to find an existing vaccine item
    $stmt = $pdo->prepare("
        SELECT item_id FROM inventory_items 
        WHERE item_name LIKE '%vaccine%' OR item_name LIKE '%rabies%' 
        LIMIT 1
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    
    if ($result) {
        return (int) $result['item_id'];
    }
    
    // If no vaccine exists, try to get any item
    $stmt = $pdo->prepare("SELECT item_id FROM inventory_items LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch();
    
    if ($result) {
        return (int) $result['item_id'];
    }
    
    // If no items exist at all, we need to create a default one
    // First, check if we have a category and unit
    $catStmt = $pdo->prepare("SELECT category_id FROM inventory_categories LIMIT 1");
    $catStmt->execute();
    $category = $catStmt->fetch();
    
    $unitStmt = $pdo->prepare("SELECT unit_id FROM units LIMIT 1");
    $unitStmt->execute();
    $unit = $unitStmt->fetch();
    
    $categoryId = $category ? (int)$category['category_id'] : 1;
    $unitId = $unit ? (int)$unit['unit_id'] : 1;
    
    // Insert a default vaccine item
    $insert = $pdo->prepare("
        INSERT INTO inventory_items (category_id, unit_id, item_name, minimum_stock, description, is_predictable) 
        VALUES (?, ?, 'Rabies Vaccine (Default)', 10, 'Default vaccine item for vaccination records', 1)
    ");
    $insert->execute([$categoryId, $unitId]);
    
    return (int) $pdo->lastInsertId();
}

/**
 * Validate patient data
 */
function validatePatientData(array $data): array {
    $errors = [];
    
    if (empty($data['patient_name'])) {
        $errors[] = "Patient name is required.";
    }
    
    if (!empty($data['dob'])) {
        $dob = frontToDbDate($data['dob']);
        if (!$dob) {
            $errors[] = "Invalid date of birth format. Please use MM/DD/YY format.";
        }
    }
    
    if (!empty($data['admission_date'])) {
        $admit = frontToDbDate($data['admission_date']);
        if (!$admit) {
            $errors[] = "Invalid admission date format. Please use MM/DD/YY format.";
        }
    }
    
    return $errors;
}

// ----------------------------------------------------------------------
// 4. DETERMINE REQUEST TYPE (AJAX or FULL PAGE)
// ----------------------------------------------------------------------
$action = $_GET['action'] ?? ($_POST['action'] ?? null);

if ($action) {
    // --------------- AJAX HANDLERS ---------------
    header('Content-Type: application/json');

    switch ($action) {

        // ----------------------------------------------------------
        // FETCH PATIENT LIST
        // ----------------------------------------------------------
        case 'fetch':
            try {
                $date   = $_GET['date'] ?? null;   // Y-m-d format from frontend
                $search = trim($_GET['search'] ?? '');

                $where  = "WHERE c.branch_id = :branch_id";
                $params = ['branch_id' => $logged_branch_id];

                // If date is provided, filter by admission date (created_at)
                if (!empty($date)) {
                    $where .= " AND DATE(c.created_at) = :admit_date";
                    $params['admit_date'] = $date;
                }

                // Search by case number or patient name
                if ($search !== '') {
                    $where .= " AND (p.full_name LIKE :search_name OR r.registry_number LIKE :search_num)";
                    $params['search_name'] = "%$search%";
                    $params['search_num']  = "%$search%";
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
                        ph.philhealth_number,
                        ph.status AS philhealth_status
                    FROM animal_bite_cases c
                    JOIN patients p ON c.patient_id = p.patient_id
                    LEFT JOIN registry_records r ON c.case_id = r.case_id
                    LEFT JOIN philhealth_records ph ON c.case_id = ph.case_id
                    $where
                    ORDER BY c.created_at DESC
                ";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll();

                // Enrich with vaccination schedule and status
                $result = [];
                foreach ($rows as $row) {
                    // Fetch vaccination schedule (doses)
                    $doseStmt = $pdo->prepare("
                        SELECT dose_number, date_administered, vaccination_status
                        FROM vaccination_records
                        WHERE case_id = ? AND branch_id = ?
                        ORDER BY dose_number
                    ");
                    $doseStmt->execute([$row['case_id'], $logged_branch_id]);
                    $doses = $doseStmt->fetchAll();

                    $schedule = [
                        'd0' => '', 'd3' => '', 'd7' => '',
                        'd14'=> '', 'd21'=> '', 'd28'=> ''
                    ];
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

                    // Determine vaccination status from registry records
                    $vaccStatus = 'Pending';
                    if (!empty($schedule['d0']) && !empty($schedule['d3']) && 
                        !empty($schedule['d7']) && !empty($schedule['d14'])) {
                        $vaccStatus = 'Completed';
                    }

                    $philhealthYes = !empty($row['philhealth_number']) ? 'Yes' : 'No';

                    $result[] = [
                        'case_id'           => $row['case_id'],
                        'patient_id'        => $row['patient_id'],
                        'case_no'           => $row['registry_number'] ?? '',
                        'patient_name'      => $row['patient_name'],
                        'contact_number'    => $row['contact_number'] ?? '',
                        'dob'               => dbToFrontDate($row['birthday']),
                        'age'               => calcAge($row['birthday']),
                        'gender'            => $row['gender'] ?? '',
                        'address'           => $row['address'] ?? '',
                        'admission_date'    => dbToFrontDate($row['date_of_bite'] ?? $row['created_at']),
                        'date_of_bite'      => dbToFrontDate($row['date_of_bite']),
                        'site_of_bite'      => $row['bite_location'] ?? '',
                        'biting_animal'     => $row['animal_type'] ?? '',
                        'animal_status'     => $row['animal_status'] ?? '',
                        'erig'              => $row['erig'] ? 'Yes' : 'No',
                        'ats'               => (bool) $row['ats'],
                        'tt'                => (bool) $row['tt'],
                        'active_regimen'    => $row['active_regimen'] ?? '',
                        'route'             => '',
                        'vacc_category'     => 'Post-Exposure Prophylaxis (PEP)',
                        'schedule'          => $schedule,
                        'vaccination_status'=> $vaccStatus,
                        'philhealth'        => $philhealthYes,
                        'philhealth_type'   => $row['philhealth_number'] ?? '',
                        'status'            => $row['philhealth_status'] ?? 'For Writing',
                        'remarks'           => $row['case_remarks'] ?? $row['registry_remarks'] ?? '',
                    ];
                }

                jsonResponse($result);
            } catch (Exception $e) {
                jsonResponse(['error' => 'Failed to fetch patients: ' . $e->getMessage()], 500);
            }
            break;

        // ----------------------------------------------------------
        // VIEW SINGLE PATIENT (full details)
        // ----------------------------------------------------------
        case 'view':
            try {
                $caseId = (int) ($_GET['case_id'] ?? 0);
                if ($caseId <= 0) {
                    jsonResponse(['error' => 'Invalid case ID'], 400);
                }

                $stmt = $pdo->prepare("
                    SELECT 
                        c.*, 
                        p.*, 
                        r.*, 
                        ph.*
                    FROM animal_bite_cases c
                    JOIN patients p ON c.patient_id = p.patient_id
                    LEFT JOIN registry_records r ON c.case_id = r.case_id
                    LEFT JOIN philhealth_records ph ON c.case_id = ph.case_id
                    WHERE c.case_id = ? AND c.branch_id = ?
                ");
                $stmt->execute([$caseId, $logged_branch_id]);
                $row = $stmt->fetch();

                if (!$row) {
                    jsonResponse(['error' => 'Record not found'], 404);
                }

                // Fetch vaccination doses
                $doseStmt = $pdo->prepare("
                    SELECT dose_number, date_administered, vaccination_status 
                    FROM vaccination_records 
                    WHERE case_id = ? AND branch_id = ?
                    ORDER BY dose_number
                ");
                $doseStmt->execute([$caseId, $logged_branch_id]);
                $doses = $doseStmt->fetchAll();
                
                $schedule = ['d0'=>'','d3'=>'','d7'=>'','d14'=>'','d21'=>'','d28'=>''];
                $vaccStatus = 'Pending';
                foreach ($doses as $d) {
                    $k = '';
                    switch ((int)$d['dose_number']) {
                        case 1: $k='d0'; break; 
                        case 2: $k='d3'; break; 
                        case 3: $k='d7'; break;
                        case 4: $k='d14'; break; 
                        case 5: $k='d21'; break; 
                        case 6: $k='d28'; break;
                    }
                    if ($k) {
                        $schedule[$k] = dbToFrontDate($d['date_administered']);
                    }
                }
                
                if (!empty($schedule['d0']) && !empty($schedule['d3']) && 
                    !empty($schedule['d7']) && !empty($schedule['d14'])) {
                    $vaccStatus = 'Completed';
                }

                // Get patient history (other cases for same patient)
                $historyStmt = $pdo->prepare("
                    SELECT c.case_id, r.registry_number AS case_no, c.created_at, 
                           DATE(c.created_at) AS admit_date, c.case_status
                    FROM animal_bite_cases c
                    LEFT JOIN registry_records r ON c.case_id = r.case_id
                    WHERE c.patient_id = ? AND c.case_id != ?
                    ORDER BY c.created_at DESC
                ");
                $historyStmt->execute([$row['patient_id'], $caseId]);
                $history = $historyStmt->fetchAll();

                $details = [
                    'case_id'        => $row['case_id'],
                    'patient_id'     => $row['patient_id'],
                    'case_no'        => $row['registry_number'] ?? '',
                    'patient_name'   => $row['full_name'],
                    'address'        => $row['address'] ?? '',
                    'dob'            => dbToFrontDate($row['birthday']),
                    'age'            => calcAge($row['birthday']),
                    'gender'         => $row['gender'] ?? '',
                    'philhealth'     => !empty($row['philhealth_number']) ? 'Yes' : 'No',
                    'philhealth_type'=> $row['philhealth_number'] ?? '',
                    'contact_number' => $row['contact_number'] ?? '',
                    'admission_date' => dbToFrontDate($row['date_of_bite'] ?? $row['created_at']),
                    'date_of_bite'   => dbToFrontDate($row['date_of_bite']),
                    'site_of_bite'   => $row['bite_location'] ?? '',
                    'biting_animal'  => $row['animal_type'] ?? '',
                    'animal_status'  => $row['animal_status'] ?? '',
                    'erig'           => $row['erig'] ? 'Yes' : 'No',
                    'ats'            => (bool) $row['ats'],
                    'tt'             => (bool) $row['tt'],
                    'active_regimen' => $row['active_regimen'] ?? '',
                    'route'          => '',
                    'vacc_category'  => 'Post-Exposure Prophylaxis (PEP)',
                    'schedule'       => $schedule,
                    'vaccination_status' => $vaccStatus,
                    'status'         => $row['philhealth_status'] ?? 'For Writing',
                    'remarks'        => $row['case_remarks'] ?? $row['registry_remarks'] ?? '',
                    'history'        => $history,
                ];
                jsonResponse($details);
            } catch (Exception $e) {
                jsonResponse(['error' => 'Failed to view patient: ' . $e->getMessage()], 500);
            }
            break;

        // ----------------------------------------------------------
        // SAVE (CREATE / UPDATE)
        // ----------------------------------------------------------
        case 'save':
            try {
                $input = json_decode(file_get_contents('php://input'), true);
                if (!$input) {
                    jsonResponse(['error' => 'Invalid JSON input'], 400);
                }

                // Validate data
                $validationErrors = validatePatientData($input);
                if (!empty($validationErrors)) {
                    jsonResponse(['error' => implode(' ', $validationErrors)], 400);
                }

                $pdo->beginTransaction();

                $caseId     = !empty($input['case_id']) ? (int) $input['case_id'] : null;
                $patientId  = !empty($input['patient_id']) ? (int) $input['patient_id'] : null;
                $fullName   = trim($input['patient_name'] ?? '');
                $dob        = frontToDbDate($input['dob'] ?? '');
                $gender     = $input['gender'] ?? '';
                $address    = trim($input['address'] ?? '');
                $contact    = trim($input['contact_number'] ?? '');
                $admitDate  = frontToDbDate($input['admission_date'] ?? '');
                $biteDate   = frontToDbDate($input['date_of_bite'] ?? '');
                $siteBite   = trim($input['site_of_bite'] ?? '');
                $animal     = trim($input['biting_animal'] ?? '');
                $animalStat = trim($input['animal_status'] ?? '');
                $erig       = !empty($input['erig']) && $input['erig'] !== 'No' ? 1 : 0;
                $ats        = !empty($input['ats']);
                $tt         = !empty($input['tt']);
                $regimen    = trim($input['active_regimen'] ?? '');
                $vaccCat    = trim($input['vacc_category'] ?? '');
                $route      = trim($input['route'] ?? '');
                $schedule   = $input['schedule'] ?? [];
                $remarks    = trim($input['remarks'] ?? '');
                $status     = trim($input['status'] ?? 'For Writing');
                $caseNo     = trim($input['case_no'] ?? '');
                $philhealth = trim($input['philhealth'] ?? 'No');
                $philType   = trim($input['philhealth_type'] ?? '');

                // Validate required fields
                if (empty($fullName)) {
                    throw new Exception("Patient name is required.");
                }

                // 1) Handle patient
                if ($patientId) {
                    // Check if patient belongs to this branch
                    $checkStmt = $pdo->prepare("SELECT patient_id FROM patients WHERE patient_id = ? AND branch_id = ?");
                    $checkStmt->execute([$patientId, $logged_branch_id]);
                    if (!$checkStmt->fetch()) {
                        throw new Exception("Patient not found or access denied.");
                    }
                    
                    // Update existing patient
                    $upd = $pdo->prepare("
                        UPDATE patients 
                        SET full_name=?, contact_number=?, birthday=?, gender=?, address=? 
                        WHERE patient_id=? AND branch_id=?
                    ");
                    $upd->execute([$fullName, $contact, $dob, $gender, $address, $patientId, $logged_branch_id]);
                } else {
                    // Insert new patient
                    $ins = $pdo->prepare("
                        INSERT INTO patients (full_name, contact_number, birthday, gender, address, branch_id) 
                        VALUES (?,?,?,?,?,?)
                    ");
                    $ins->execute([$fullName, $contact, $dob, $gender, $address, $logged_branch_id]);
                    $patientId = (int) $pdo->lastInsertId();
                }

                // 2) Handle animal_bite_cases
                if ($caseId) {
                    // Check if case belongs to this branch
                    $checkCase = $pdo->prepare("SELECT case_id FROM animal_bite_cases WHERE case_id = ? AND branch_id = ?");
                    $checkCase->execute([$caseId, $logged_branch_id]);
                    if (!$checkCase->fetch()) {
                        throw new Exception("Case not found or access denied.");
                    }
                    
                    // Update existing case
                    $updCase = $pdo->prepare("
                        UPDATE animal_bite_cases 
                        SET animal_type=?, bite_location=?, animal_status=?, date_of_bite=?, remarks=?,
                            admin_staff_id=?
                        WHERE case_id=? AND branch_id=?
                    ");
                    $updCase->execute([
                        $animal, $siteBite, $animalStat, $biteDate, $remarks, 
                        $logged_user_id, $caseId, $logged_branch_id
                    ]);
                } else {
                    // Insert new case
                    $insCase = $pdo->prepare("
                        INSERT INTO animal_bite_cases 
                        (patient_id, branch_id, animal_type, bite_location, animal_status, date_of_bite, 
                         case_status, remarks, admin_staff_id) 
                        VALUES (?,?,?,?,?,?,?,?,?)
                    ");
                    $insCase->execute([
                        $patientId, $logged_branch_id, $animal, $siteBite, $animalStat, 
                        $biteDate, 'Ongoing', $remarks, $logged_user_id
                    ]);
                    $caseId = (int) $pdo->lastInsertId();
                }

                // 3) Handle registry_records
                $regExists = $pdo->prepare("SELECT registry_id FROM registry_records WHERE case_id=?");
                $regExists->execute([$caseId]);
                $registryId = $regExists->fetchColumn();

                // Auto-generate case number if empty
                if (empty($caseNo)) {
                    $caseNo = generateCaseNo($pdo, $logged_branch_id);
                }

                $doseD0  = !empty($schedule['d0']) ? 1 : 0;
                $doseD3  = !empty($schedule['d3']) ? 1 : 0;
                $doseD7  = !empty($schedule['d7']) ? 1 : 0;
                $doseD14 = !empty($schedule['d14']) ? 1 : 0;
                $doseD21 = !empty($schedule['d21']) ? 1 : 0;
                $doseD28 = !empty($schedule['d28']) ? 1 : 0;

                if ($registryId) {
                    $updReg = $pdo->prepare("
                        UPDATE registry_records 
                        SET registry_number=?, status_of_biting_animal=?, erig=?, ats=?, tt=?, 
                            active_regimen=?, dose_d0=?, dose_d3=?, dose_d7=?, dose_d14=?, 
                            dose_d28_30=?, contact_number=?, remarks=?, updated_by=?, updated_at=NOW() 
                        WHERE registry_id=?
                    ");
                    $updReg->execute([
                        $caseNo, $animalStat, $erig, $ats, $tt, $regimen, 
                        $doseD0, $doseD3, $doseD7, $doseD14, $doseD28, 
                        $contact, $remarks, $logged_user_id, $registryId
                    ]);
                } else {
                    $insReg = $pdo->prepare("
                        INSERT INTO registry_records 
                        (case_id, registry_number, status_of_biting_animal, erig, ats, tt, active_regimen, 
                         dose_d0, dose_d3, dose_d7, dose_d14, dose_d28_30, contact_number, remarks, updated_by, updated_at) 
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                    ");
                    $insReg->execute([
                        $caseId, $caseNo, $animalStat, $erig, $ats, $tt, $regimen,
                        $doseD0, $doseD3, $doseD7, $doseD14, $doseD28,
                        $contact, $remarks, $logged_user_id
                    ]);
                }

                // 4) Handle philhealth_records
                $phExists = $pdo->prepare("SELECT philhealth_record_id FROM philhealth_records WHERE case_id=?");
                $phExists->execute([$caseId]);
                $phRecId = $phExists->fetchColumn();
                $phNumber = ($philhealth === 'Yes') ? $philType : null;

                if ($phRecId) {
                    $updPh = $pdo->prepare("
                        UPDATE philhealth_records 
                        SET philhealth_number=?, status=?, remarks=?, updated_by=?, updated_at=NOW() 
                        WHERE philhealth_record_id=?
                    ");
                    $updPh->execute([$phNumber, $status, $remarks, $logged_user_id, $phRecId]);
                } else {
                    $insPh = $pdo->prepare("
                        INSERT INTO philhealth_records 
                        (case_id, philhealth_number, status, remarks, updated_by, updated_at) 
                        VALUES (?,?,?,?,?,NOW())
                    ");
                    $insPh->execute([$caseId, $phNumber, $status, $remarks, $logged_user_id]);
                }

                // 5) Handle vaccination schedule (vaccination_records)
                // Delete existing vaccination records for this case
                $pdo->prepare("DELETE FROM vaccination_records WHERE case_id=? AND branch_id=?")
                     ->execute([$caseId, $logged_branch_id]);
                
                // Get a valid vaccine item ID
                $vaccineItemId = getDefaultVaccineItemId($pdo);
                
                $doseMap = [
                    1 => $schedule['d0'] ?? '',
                    2 => $schedule['d3'] ?? '',
                    3 => $schedule['d7'] ?? '',
                    4 => $schedule['d14'] ?? '',
                    5 => $schedule['d21'] ?? '',
                    6 => $schedule['d28'] ?? '',
                ];
                
                $insertVacc = $pdo->prepare("
                    INSERT INTO vaccination_records 
                    (patient_id, case_id, item_id, branch_id, dose_number, date_administered, 
                     vaccination_status, nurse_id) 
                    VALUES (?,?,?,?,?,?,?,?)
                ");
                
                foreach ($doseMap as $doseNum => $dateStr) {
                    if (!empty($dateStr)) {
                        $dbDate = frontToDbDate($dateStr);
                        if ($dbDate) {
                            $insertVacc->execute([
                                $patientId, $caseId, $vaccineItemId, $logged_branch_id, $doseNum, 
                                $dbDate, 'Completed', $logged_user_id
                            ]);
                        }
                    }
                }

                // Audit log
                $actionText = $caseId ? "Updated patient record: {$fullName} (Case: {$caseNo})" 
                                      : "Created new patient record: {$fullName} (Case: {$caseNo})";
                auditLog($pdo, $logged_user_id, $logged_branch_id, $actionText, 'Patient Record');

                $pdo->commit();
                jsonResponse(['success' => true, 'case_id' => $caseId, 'case_no' => $caseNo]);

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                jsonResponse(['error' => $e->getMessage()], 500);
            }
            break;

        // ----------------------------------------------------------
        // DELETE CASE
        // ----------------------------------------------------------
        case 'delete':
            try {
                $caseId = (int) ($_GET['case_id'] ?? 0);
                if ($caseId <= 0) {
                    jsonResponse(['error' => 'Invalid case ID'], 400);
                }

                $pdo->beginTransaction();

                // Get case details for audit log
                $caseStmt = $pdo->prepare("
                    SELECT c.case_id, p.full_name, r.registry_number 
                    FROM animal_bite_cases c
                    JOIN patients p ON c.patient_id = p.patient_id
                    LEFT JOIN registry_records r ON c.case_id = r.case_id
                    WHERE c.case_id = ? AND c.branch_id = ?
                ");
                $caseStmt->execute([$caseId, $logged_branch_id]);
                $caseData = $caseStmt->fetch();
                
                if (!$caseData) {
                    throw new Exception("Record not found or access denied.");
                }

                // Delete related records (order matters due to foreign keys)
                $pdo->prepare("DELETE FROM document_tracking WHERE case_id=?")->execute([$caseId]);
                $pdo->prepare("DELETE FROM vaccination_records WHERE case_id=? AND branch_id=?")
                     ->execute([$caseId, $logged_branch_id]);
                $pdo->prepare("DELETE FROM philhealth_records WHERE case_id=?")->execute([$caseId]);
                $pdo->prepare("DELETE FROM registry_records WHERE case_id=?")->execute([$caseId]);
                $pdo->prepare("DELETE FROM animal_bite_cases WHERE case_id=? AND branch_id=?")
                     ->execute([$caseId, $logged_branch_id]);

                $actionText = "Deleted patient record: {$caseData['full_name']} (Case: {$caseData['registry_number']})";
                auditLog($pdo, $logged_user_id, $logged_branch_id, $actionText, 'Patient Record');

                $pdo->commit();
                jsonResponse(['success' => true]);

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                jsonResponse(['error' => $e->getMessage()], 500);
            }
            break;

        // ----------------------------------------------------------
        // GENERATE NEW CASE NUMBER
        // ----------------------------------------------------------
        case 'generate_case_no':
            try {
                $newNo = generateCaseNo($pdo, $logged_branch_id);
                jsonResponse(['case_no' => $newNo]);
            } catch (Exception $e) {
                jsonResponse(['error' => 'Failed to generate case number: ' . $e->getMessage()], 500);
            }
            break;

        // ----------------------------------------------------------
        // PATIENT HISTORY (by patient ID)
        // ----------------------------------------------------------
        case 'patient_history':
            try {
                $patientId = (int) ($_GET['patient_id'] ?? 0);
                if ($patientId <= 0) {
                    jsonResponse([]);
                }
                
                $stmt = $pdo->prepare("
                    SELECT 
                        c.case_id, 
                        r.registry_number AS case_no, 
                        DATE(c.created_at) AS admit_date,
                        c.case_status
                    FROM animal_bite_cases c
                    LEFT JOIN registry_records r ON c.case_id = r.case_id
                    WHERE c.patient_id = ? AND c.branch_id = ?
                    ORDER BY c.created_at DESC
                ");
                $stmt->execute([$patientId, $logged_branch_id]);
                $rows = $stmt->fetchAll();
                
                $result = [];
                foreach ($rows as $r) {
                    $result[] = [
                        'case_id' => $r['case_id'],
                        'case_no' => $r['case_no'] ?? '',
                        'admit_date' => dbToFrontDate($r['admit_date']),
                        'status' => $r['case_status'] ?? 'Ongoing',
                    ];
                }
                jsonResponse($result);
            } catch (Exception $e) {
                jsonResponse(['error' => 'Failed to fetch history: ' . $e->getMessage()], 500);
            }
            break;

        // ----------------------------------------------------------
        // SEARCH PATIENTS BY NAME (for auto-complete)
        // ----------------------------------------------------------
        case 'search_patients':
            try {
                $search = trim($_GET['q'] ?? '');
                if (empty($search)) {
                    jsonResponse([]);
                }
                
                $stmt = $pdo->prepare("
                    SELECT patient_id, full_name, contact_number
                    FROM patients
                    WHERE branch_id = ? AND full_name LIKE ?
                    ORDER BY full_name
                    LIMIT 20
                ");
                $stmt->execute([$logged_branch_id, "%$search%"]);
                $rows = $stmt->fetchAll();
                
                $result = [];
                foreach ($rows as $r) {
                    $result[] = [
                        'id' => $r['patient_id'],
                        'name' => $r['full_name'],
                        'contact' => $r['contact_number'] ?? '',
                    ];
                }
                jsonResponse($result);
            } catch (Exception $e) {
                jsonResponse(['error' => 'Search failed: ' . $e->getMessage()], 500);
            }
            break;

        default:
            jsonResponse(['error' => 'Unknown action: ' . $action], 400);
    }
    exit; // End AJAX handling
}

// ----------------------------------------------------------------------
// 6. OUTPUT FULL HTML PAGE (only if no AJAX action)
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
            padding: 0;
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
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
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
            .main { margin-left: 90px; padding: 0 ; }
            .topbar { padding: 0 16px; height: 64px; }
            .topbar h3 { font-size: 20px; }
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

        .section-title {
            font-weight: 700;
            color: var(--primary);
            margin-top: 18px;
            margin-bottom: 14px;
            border-bottom: 2px solid var(--gray-200);
            padding-bottom: 8px;
            font-size: 15px;
        }

        .schedule-fields {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 6px;
        }

        .schedule-fields .schedule-item {
            flex: 0 0 auto;
            min-width: 85px;
        }

        .schedule-fields .schedule-item label {
            font-size: 11px;
            font-weight: 600;
            color: #555;
            margin-bottom: 2px;
            display: block;
        }

        .schedule-fields .schedule-item input {
            width: 100%;
            padding: 5px 8px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            font-size: 13px;
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

        @media (max-width: 768px) {
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

    <!-- Main content -->
    <div class="main">
        <div class="topbar">
            <h3>Patient Record Management</h3>
            <div class="profile">
                <?php echo htmlspecialchars($logged_username); ?> 
                <i class="bi bi-caret-down-fill"></i>
            </div>
        </div>

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

                    <form id="patientForm">
                        <input type="hidden" id="editId" value="">
                        <input type="hidden" id="editPatientId" value="">

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Case No. <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="caseNo" required readonly>
                                    <small class="text-muted">Auto-generated</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Patient's Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="patientName" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Address</label>
                                    <input type="text" class="form-control" id="address">
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <label class="form-label">Date of Birth</label>
                                        <input type="text" class="form-control flatpickr-date" id="dob">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Age</label>
                                        <input type="number" class="form-control" id="age" readonly>
                                    </div>
                                </div>
                                <div class="mb-3 mt-2">
                                    <label class="form-label">Gender</label>
                                    <div class="d-flex gap-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="gender" id="genderMale" value="Male">
                                            <label class="form-check-label" for="genderMale">Male</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="gender" id="genderFemale" value="Female">
                                            <label class="form-check-label" for="genderFemale">Female</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="gender" id="genderOther" value="Other">
                                            <label class="form-check-label" for="genderOther">Other</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">PhilHealth</label>
                                    <select class="form-select" id="philhealth">
                                        <option value="Yes">Yes</option>
                                        <option value="No" selected>No</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">PhilHealth Membership Type</label>
                                    <input type="text" class="form-control" id="philhealthType" placeholder="e.g., Sponsored, Indigent, etc.">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Contact Number</label>
                                    <input type="text" class="form-control" id="contactNumber">
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
                                    <label class="form-label">Date of Bite</label>
                                    <input type="text" class="form-control flatpickr-date" id="dateOfBite">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Site of Bite</label>
                                    <input type="text" class="form-control" id="siteOfBite" placeholder="e.g., Right arm, Left leg">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Biting Animal</label>
                                    <select class="form-select" id="bitingAnimal">
                                        <option value="Dog">Dog</option>
                                        <option value="Cat">Cat</option>
                                        <option value="Bat">Bat</option>
                                        <option value="Monkey">Monkey</option>
                                        <option value="Others">Others</option>
                                    </select>
                                </div>
                                <div id="customAnimalContainer" style="display:none;">
                                    <label class="form-label">Specify Animal</label>
                                    <input type="text" class="form-control" id="customAnimal">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Status of the Biting Animal</label>
                                    <select class="form-select" id="animalStatus">
                                        <option value="Alive/Healthy">Alive/Healthy</option>
                                        <option value="Sick">Sick</option>
                                        <option value="Died">Died</option>
                                        <option value="Unknown">Unknown</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <hr>
                        <div class="section-title">Vaccination & Treatment</div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">ERIG</label>
                                    <select class="form-select" id="erig">
                                        <option value="No">No</option>
                                        <option value="Yes">Yes</option>
                                    </select>
                                </div>
                                <div class="mb-3 inline-check">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="ats">
                                        <label class="form-check-label" for="ats">ATS</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="tt">
                                        <label class="form-check-label" for="tt">TT</label>
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
                                    <label class="form-label">Active Regimen</label>
                                    <select class="form-select" id="activeRegimen">
                                        <option value="PVRV TRC SPEEDA">PVRV TRC SPEEDA</option>
                                        <option value="PVRV TRC ABHAYRAB">PVRV TRC ABHAYRAB</option>
                                        <option value="OTHER">OTHER</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Vaccination Category</label>
                                    <select class="form-select" id="vaccCategory">
                                        <option value="Pre-Exposure Prophylaxis (PrEP)">Pre-Exposure Prophylaxis (PrEP)</option>
                                        <option value="Post-Exposure Prophylaxis (PEP)" selected>Post-Exposure Prophylaxis (PEP)</option>
                                        <option value="Booster Dose">Booster Dose</option>
                                        <option value="Others">Others</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div id="scheduleSection">
                            <div class="section-title">Vaccination Schedule</div>
                            <div class="schedule-fields" id="scheduleContainer">
                                <div class="schedule-item">
                                    <label>D0</label>
                                    <input type="text" class="form-control form-control-sm flatpickr-date" id="d0">
                                </div>
                                <div class="schedule-item">
                                    <label>D3</label>
                                    <input type="text" class="form-control form-control-sm flatpickr-date" id="d3">
                                </div>
                                <div class="schedule-item">
                                    <label>D7</label>
                                    <input type="text" class="form-control form-control-sm flatpickr-date" id="d7">
                                </div>
                                <div class="schedule-item">
                                    <label>D14</label>
                                    <input type="text" class="form-control form-control-sm flatpickr-date" id="d14">
                                </div>
                                <div class="schedule-item">
                                    <label>D21</label>
                                    <input type="text" class="form-control form-control-sm flatpickr-date" id="d21">
                                </div>
                                <div class="schedule-item">
                                    <label>D28</label>
                                    <input type="text" class="form-control form-control-sm flatpickr-date" id="d28">
                                </div>
                            </div>
                        </div>

                        <hr>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Vaccination Status</label>
                                    <input type="text" class="form-control" id="vaccinationStatus" readonly style="background:#f8f9fc; font-weight:600;">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Remarks</label>
                                    <textarea class="form-control" id="remarks" rows="2"></textarea>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Record Status</label>
                                    <select class="form-select" id="status">
                                        <option value="For Writing">For Writing</option>
                                        <option value="For Screening">For Screening</option>
                                        <option value="For Signing">For Signing</option>
                                        <option value="For Transmittal">For Transmittal</option>
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
    let currentAdmissionDate = '<?php echo date('m/d/y'); ?>';
    let currentPage = 1;
    const pageSize = 8;
    let searchTerm = '';
    let deleteTargetCaseId = null;
    let allPatients = [];

    // Flatpickr instances
    let flatpickrInstances = [];

    function initFlatpickrs() {
        const config = {
            dateFormat: 'm/d/y',
            allowInput: true,
            altInput: true,
            altFormat: 'm/d/y'
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

    // Convert frontend date (m/d/y) to Y-m-d for API
    function frontToApi(dateStr) {
        if (!dateStr) return '';
        const parts = dateStr.split('/');
        if (parts.length !== 3) return '';
        return `20${parts[2]}-${parts[0].padStart(2,'0')}-${parts[1].padStart(2,'0')}`;
    }

    // Fetch patient list
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

    // Render table
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

            // Update stats
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

                // Bind action icons
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

            // Pagination
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

    // Initialize Calendar
    function initCalendar() {
        const calendarEl = document.getElementById('calendarInline');
        flatpickr(calendarEl, {
            inline: true,
            dateFormat: 'm/d/y',
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

    // View patient
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
                ['PhilHealth', data.philhealth || 'No'],
                ['PhilHealth Type', data.philhealth_type || ''],
                ['Contact', data.contact_number || ''],
                ['Admission Date', data.admission_date || ''],
                ['Date of Bite', data.date_of_bite || ''],
                ['Site of Bite', data.site_of_bite || ''],
                ['Biting Animal', data.biting_animal || ''],
                ['Animal Status', data.animal_status || ''],
                ['ERIG', data.erig || 'No'],
                ['ATS', data.ats ? 'Yes' : 'No'],
                ['TT', data.tt ? 'Yes' : 'No'],
                ['Active Regimen', data.active_regimen || ''],
                ['Vaccination Category', data.vacc_category || ''],
                ['Route', data.route || ''],
                ['Vaccination Status', data.vaccination_status || 'Pending'],
                ['Record Status', data.status || 'For Writing'],
                ['Remarks', data.remarks || '']
            ];

            // Schedule
            if (data.schedule) {
                const sched = data.schedule;
                fields.push(['Schedule', 
                    `D0: ${sched.d0 || '-'} | D3: ${sched.d3 || '-'} | D7: ${sched.d7 || '-'} | D14: ${sched.d14 || '-'} | D21: ${sched.d21 || '-'} | D28: ${sched.d28 || '-'}`
                ]);
            }

            let html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 20px;">';
            fields.forEach(f => {
                html += `
                    <div class="view-detail-row" style="${fields.indexOf(f) % 2 === 0 ? '' : 'border-bottom:none;'}">
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

    // Edit patient
    async function editPatient(caseId) {
        try {
            const res = await fetch(`${apiBase}?action=view&case_id=${caseId}`);
            const data = await res.json();

            document.getElementById('editId').value = data.case_id;
            document.getElementById('editPatientId').value = data.patient_id;
            document.getElementById('modalTitle').textContent = 'Edit Patient';
            document.getElementById('caseNo').value = data.case_no || '';
            document.getElementById('patientName').value = data.patient_name || '';
            document.getElementById('address').value = data.address || '';
            document.getElementById('dob').value = data.dob || '';
            document.getElementById('age').value = data.age ?? '';
            
            // Gender
            document.querySelectorAll('input[name="gender"]').forEach(el => {
                el.checked = el.value === data.gender;
            });
            
            document.getElementById('philhealth').value = data.philhealth === 'Yes' ? 'Yes' : 'No';
            document.getElementById('philhealthType').value = data.philhealth_type || '';
            document.getElementById('contactNumber').value = data.contact_number || '';
            document.getElementById('admissionDate').value = data.admission_date || '';
            document.getElementById('dateOfBite').value = data.date_of_bite || '';
            document.getElementById('siteOfBite').value = data.site_of_bite || '';
            document.getElementById('bitingAnimal').value = data.biting_animal || 'Dog';
            document.getElementById('animalStatus').value = data.animal_status || '';
            document.getElementById('erig').value = data.erig === 'Yes' ? 'Yes' : 'No';
            document.getElementById('ats').checked = data.ats || false;
            document.getElementById('tt').checked = data.tt || false;
            document.getElementById('activeRegimen').value = data.active_regimen || '';
            document.getElementById('vaccCategory').value = data.vacc_category || 'Post-Exposure Prophylaxis (PEP)';
            document.getElementById('route').value = data.route || 'Intradermal (ID)';
            document.getElementById('vaccinationStatus').value = data.vaccination_status || 'Pending';
            document.getElementById('remarks').value = data.remarks || '';
            document.getElementById('status').value = data.status || 'For Writing';

            // Schedule
            if (data.schedule) {
                ['d0','d3','d7','d14','d21','d28'].forEach(k => {
                    document.getElementById(k).value = data.schedule[k] || '';
                });
            }

            // History
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

            new bootstrap.Modal(document.getElementById('patientModal')).show();
        } catch (e) {
            showToast('Error loading patient', e.message, true);
        }
    }

    // Confirm delete
    function confirmDelete(caseId) {
        deleteTargetCaseId = caseId;
        const patient = allPatients.find(p => p.case_id === caseId);
        document.getElementById('deletePatientName').textContent = patient ? 
            `Patient: ${patient.patient_name} (Case: ${patient.case_no})` : 
            `Case ID: ${caseId}`;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }

    // Delete confirmation
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

    // Save patient
    document.getElementById('savePatientBtn').addEventListener('click', async function() {
        const formData = {
            case_id: parseInt(document.getElementById('editId').value) || null,
            patient_id: parseInt(document.getElementById('editPatientId').value) || null,
            case_no: document.getElementById('caseNo').value,
            patient_name: document.getElementById('patientName').value,
            address: document.getElementById('address').value,
            dob: document.getElementById('dob').value,
            age: document.getElementById('age').value || null,
            gender: document.querySelector('input[name="gender"]:checked')?.value || '',
            philhealth: document.getElementById('philhealth').value,
            philhealth_type: document.getElementById('philhealthType').value,
            contact_number: document.getElementById('contactNumber').value,
            admission_date: document.getElementById('admissionDate').value,
            date_of_bite: document.getElementById('dateOfBite').value,
            site_of_bite: document.getElementById('siteOfBite').value,
            biting_animal: document.getElementById('bitingAnimal').value,
            animal_status: document.getElementById('animalStatus').value,
            erig: document.getElementById('erig').value,
            ats: document.getElementById('ats').checked,
            tt: document.getElementById('tt').checked,
            active_regimen: document.getElementById('activeRegimen').value,
            vacc_category: document.getElementById('vaccCategory').value,
            route: document.getElementById('route').value,
            schedule: {
                d0: document.getElementById('d0').value,
                d3: document.getElementById('d3').value,
                d7: document.getElementById('d7').value,
                d14: document.getElementById('d14').value,
                d21: document.getElementById('d21').value,
                d28: document.getElementById('d28').value
            },
            remarks: document.getElementById('remarks').value,
            status: document.getElementById('status').value,
            vaccination_status: document.getElementById('vaccinationStatus').value
        };

        // Validation
        if (!formData.patient_name.trim()) {
            showToast('Error', 'Patient name is required.', true);
            return;
        }
        if (!formData.admission_date) {
            showToast('Error', 'Admission date is required.', true);
            return;
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
                showToast('Record saved successfully', `Case #${data.case_no}`);
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

    // Add new patient
    document.getElementById('addPatientBtn').addEventListener('click', async function() {
        document.getElementById('editId').value = '';
        document.getElementById('editPatientId').value = '';
        document.getElementById('modalTitle').textContent = 'Add New Patient';
        document.getElementById('patientForm').reset();
        document.getElementById('admissionDate').value = currentAdmissionDate;
        document.getElementById('vaccinationStatus').value = 'Pending';
        document.getElementById('historyPanel').style.display = 'none';
        
        // Generate new case number
        try {
            const res = await fetch(`${apiBase}?action=generate_case_no`);
            const data = await res.json();
            document.getElementById('caseNo').value = data.case_no || '';
        } catch (e) {
            document.getElementById('caseNo').value = '';
        }
        
        // Reset schedule fields
        ['d0','d3','d7','d14','d21','d28'].forEach(id => {
            document.getElementById(id).value = '';
        });
        
        // Reset gender radios
        document.querySelectorAll('input[name="gender"]').forEach(el => {
            el.checked = false;
        });
        
        new bootstrap.Modal(document.getElementById('patientModal')).show();
    });

    // Search input
    document.getElementById('searchInput').addEventListener('input', function() {
        searchTerm = this.value;
        currentPage = 1;
        renderTable();
    });

    // Clear search
    document.getElementById('clearSearchBtn').addEventListener('click', () => {
        document.getElementById('searchInput').value = '';
        searchTerm = '';
        currentPage = 1;
        renderTable();
    });

    // Export CSV
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

    // Auto-calculate age from DOB
    document.getElementById('dob').addEventListener('change', function() {
        const dob = this.value;
        if (dob) {
            const parts = dob.split('/');
            if (parts.length === 3) {
                const birth = new Date(2000 + parseInt(parts[2]), parseInt(parts[0]) - 1, parseInt(parts[1]));
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

    // Show custom animal input
    document.getElementById('bitingAnimal').addEventListener('change', function() {
        document.getElementById('customAnimalContainer').style.display = 
            this.value === 'Others' ? 'block' : 'none';
    });

    // Update vaccination status based on schedule
    function updateVaccStatus() {
        const d0 = document.getElementById('d0').value;
        const d3 = document.getElementById('d3').value;
        const d7 = document.getElementById('d7').value;
        const d14 = document.getElementById('d14').value;
        
        if (d0 && d3 && d7 && d14) {
            document.getElementById('vaccinationStatus').value = 'Completed';
        } else {
            document.getElementById('vaccinationStatus').value = 'Pending';
        }
    }

    // Add change listeners to schedule fields
    ['d0','d3','d7','d14','d21','d28'].forEach(id => {
        document.getElementById(id).addEventListener('change', updateVaccStatus);
    });

    // Handle PhilHealth toggle
    document.getElementById('philhealth').addEventListener('change', function() {
        document.getElementById('philhealthType').disabled = this.value === 'No';
        if (this.value === 'No') {
            document.getElementById('philhealthType').value = '';
        }
    });

    // Initial load
    document.addEventListener('DOMContentLoaded', () => {
        initFlatpickrs();
        initCalendar();
        renderTable();
        
        // Set initial case number on page load
        fetch(`${apiBase}?action=generate_case_no`)
            .then(res => res.json())
            .then(data => {
                if (data.case_no) {
                    document.getElementById('caseNo').value = data.case_no;
                }
            })
            .catch(console.error);
    });
    </script>
</body>
</html>