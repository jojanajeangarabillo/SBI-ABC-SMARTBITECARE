<?php
session_start();

// TURN OFF display_errors globally to prevent breaking JSON
error_reporting(E_ALL);
ini_set('display_errors', 0); 

require_once 'sources/db_connect.php';

// CSRF protection for vaccination submissions
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Global Exception Handler: Catch all errors and output them as JSON
set_exception_handler(function($e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'System Error: ' . $e->getMessage()]);
    exit;
});

// Check if user is logged in and is a nurse
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 3) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$branch_id = null;
$branch_name = '';
$username = '';

// Get user's branch info
$userQuery = "SELECT u.branch_id, u.username, b.branch_name 
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
    $username = $userData['username'] ?? 'Nurse';
}

if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
}

// Function to create notification
function createNotification($conn, $user_id, $title, $message, $notification_type = 'system') {
    $sql = "INSERT INTO notifications (user_id, title, message, notification_type, is_read, created_at) 
            VALUES (?, ?, ?, ?, 0, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isss", $user_id, $title, $message, $notification_type);
    return $stmt->execute();
}

// Function to get administrative staff in a branch
function getAdminStaffByBranch($conn, $branch_id) {
    $sql = "SELECT u.user_id, u.username, u.email 
            FROM users u 
            WHERE u.branch_id = ? 
            AND u.role_id IN (1, 2, 5)  
            AND u.status = 'Active'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $branch_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Function to log audit
function logAudit($conn, $user_id, $branch_id, $action, $module) {
    $sql = "INSERT INTO audit_logs (user_id, branch_id, action, module, created_at) 
            VALUES (?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isss", $user_id, $branch_id, $action, $module);
    return $stmt->execute();
}

// Function to get dose label
function getDoseLabel($dose_number) {
    $doseMap = [
        1 => 'D0',
        2 => 'D3',
        3 => 'D7',
        4 => 'D14',
        5 => 'D21',
        6 => 'D28/30'
    ];
    return $doseMap[$dose_number] ?? 'D' . $dose_number;
}

// Get completed dose-stage flags for a patient/case.
// A dose stage can contain multiple vaccine/products. Those products remain
// separate vaccination_records rows, but they count as ONE dose stage.
function getCompletedDoseStages($conn, $patient_id, $case_id) {
    $stages = [
        1 => false, // D0
        2 => false, // D3
        3 => false, // D7
        4 => false, // D14
        5 => false, // D21
        6 => false  // D28/30
    ];

    // Registry dose flags are the stage-level record.
    $regSql = "SELECT dose_d0, dose_d3, dose_d7, dose_d14, dose_d21, dose_d28_30
               FROM registry_records
               WHERE case_id = ? AND is_archived = 0
               LIMIT 1";
    $regStmt = $conn->prepare($regSql);
    $regStmt->bind_param("i", $case_id);
    $regStmt->execute();
    $reg = $regStmt->get_result()->fetch_assoc();
    $regStmt->close();

    if ($reg) {
        $stages[1] = ((int)($reg['dose_d0'] ?? 0) === 1);
        $stages[2] = ((int)($reg['dose_d3'] ?? 0) === 1);
        $stages[3] = ((int)($reg['dose_d7'] ?? 0) === 1);
        $stages[4] = ((int)($reg['dose_d14'] ?? 0) === 1);
        $stages[5] = ((int)($reg['dose_d21'] ?? 0) === 1);
        $stages[6] = ((int)($reg['dose_d28_30'] ?? 0) === 1);
    }

    // Also recognize legitimate completed records. This supports older cases
    // whose registry flags were not populated yet. Future-dated and Default
    // placeholder records are deliberately ignored.
    $vaccSql = "SELECT DISTINCT dose_number
                FROM vaccination_records
                WHERE patient_id = ?
                  AND case_id = ?
                  AND is_archived = 0
                  AND vaccination_status = 'Completed'
                  AND date_administered IS NOT NULL
                  AND date_administered <= CURDATE()
                  AND COALESCE(vaccine_name, '') NOT LIKE '%Default%'
                  AND dose_number BETWEEN 1 AND 6";
    $vaccStmt = $conn->prepare($vaccSql);
    $vaccStmt->bind_param("ii", $patient_id, $case_id);
    $vaccStmt->execute();
    $vaccResult = $vaccStmt->get_result();
    while ($row = $vaccResult->fetch_assoc()) {
        $dose = (int)$row['dose_number'];
        if ($dose >= 1 && $dose <= 6) {
            $stages[$dose] = true;
        }
    }
    $vaccStmt->close();

    return $stages;
}

function getNextDoseNumber($conn, $patient_id, $case_id) {
    $stages = getCompletedDoseStages($conn, $patient_id, $case_id);

    for ($dose = 1; $dose <= 6; $dose++) {
        if (empty($stages[$dose])) {
            return $dose;
        }
    }

    // All stages are complete. Return the final stage only for display fallback.
    return 6;
}

function isVaccinationComplete($conn, $patient_id, $case_id) {
    $stages = getCompletedDoseStages($conn, $patient_id, $case_id);

    for ($dose = 1; $dose <= 6; $dose++) {
        if (empty($stages[$dose])) {
            return false;
        }
    }

    return true;
}

// Validate YYYY-MM-DD date
function validYmdDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

// Get a Medical Supplies inventory item. Unit is loaded from the database
// rather than trusted from browser-submitted data.
function getMedicalSupplyItem($conn, $item_id) {
    $sql = "SELECT i.item_id, i.item_name, i.unit_id, u.unit_name, c.category_name
            FROM inventory_items i
            INNER JOIN units u ON i.unit_id = u.unit_id
            INNER JOIN inventory_categories c ON i.category_id = c.category_id
            WHERE i.item_id = ?
              AND c.category_name = 'Medical Supplies'
              AND i.item_name NOT LIKE '%Default%'
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// Deduct a completed vaccination from inventory using FEFO.
// Only positive, non-expired stock is eligible.
// Must be called inside an active database transaction.
function deductVaccineStockFEFO($conn, $item_id, $branch_id, $quantity_needed) {
    if ($quantity_needed <= 0) {
        throw new Exception('Quantity must be greater than zero.');
    }

    $sql = "SELECT stock_id, batch_lot_no, quantity_available, expiration_date
            FROM inventory_stocks
            WHERE item_id = ?
              AND branch_id = ?
              AND quantity_available > 0
              AND (expiration_date IS NULL OR expiration_date >= CURDATE())
            ORDER BY
                CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END ASC,
                expiration_date ASC,
                stock_id ASC
            FOR UPDATE";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $item_id, $branch_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $stocks = [];
    $total_available = 0;
    while ($row = $result->fetch_assoc()) {
        $stocks[] = $row;
        $total_available += (int)$row['quantity_available'];
    }
    $stmt->close();

    if ($total_available < $quantity_needed) {
        throw new Exception("Insufficient non-expired stock. Available: {$total_available}.");
    }

    $remaining = $quantity_needed;
    $used_batches = [];

    foreach ($stocks as $stock) {
        if ($remaining <= 0) break;

        $take = min((int)$stock['quantity_available'], $remaining);
        $stock_id = (int)$stock['stock_id'];

        $update = $conn->prepare("UPDATE inventory_stocks
                                  SET quantity_available = quantity_available - ?,
                                      last_updated = CURRENT_TIMESTAMP
                                  WHERE stock_id = ?");
        $update->bind_param("ii", $take, $stock_id);
        if (!$update->execute()) {
            throw new Exception('Failed to update vaccine batch stock.');
        }
        $update->close();

        $used_batches[] = [
            'batch_lot_no' => $stock['batch_lot_no'] ?: 'N/A',
            'quantity' => $take,
            'expiration_date' => $stock['expiration_date']
        ];

        $remaining -= $take;
    }

    return $used_batches;
}

function vaccineBatchSummary($batches) {
    $parts = [];
    foreach ($batches as $batch) {
        $label = $batch['batch_lot_no'] . ': ' . (int)$batch['quantity'];
        if (!empty($batch['expiration_date'])) {
            $label .= ' (exp ' . $batch['expiration_date'] . ')';
        }
        $parts[] = $label;
    }
    return implode(', ', $parts);
}

// Handle AJAX request for getting available vaccines
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_vaccines') {
    header('Content-Type: application/json');

    // One row per Medical Supplies item. Stock is summed across all
    // positive, non-expired batches in the nurse's branch.
    $sql = "SELECT
                i.item_id,
                i.item_name,
                i.unit_id,
                u.unit_name,
                COALESCE(SUM(s.quantity_available), 0) AS quantity_available,
                MIN(s.expiration_date) AS nearest_expiration
            FROM inventory_items i
            INNER JOIN inventory_categories c ON i.category_id = c.category_id
            INNER JOIN units u ON i.unit_id = u.unit_id
            INNER JOIN inventory_stocks s
                ON i.item_id = s.item_id
               AND s.branch_id = ?
               AND s.quantity_available > 0
               AND (s.expiration_date IS NULL OR s.expiration_date >= CURDATE())
            WHERE c.category_name = 'Medical Supplies'
              AND i.item_name NOT LIKE '%Default%'
            GROUP BY i.item_id, i.item_name, i.unit_id, u.unit_name
            HAVING COALESCE(SUM(s.quantity_available), 0) > 0
            ORDER BY i.item_name";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $branch_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $vaccines = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode($vaccines);
    exit;
}

// Handle AJAX for getting patient cases
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_patient_cases') {
    header('Content-Type: application/json');
    $patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;
    
    if ($patient_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid patient ID']);
        exit;
    }
    
    $sql = "SELECT 
                p.*,
                a.case_id, 
                a.case_status, 
                a.animal_type, 
                a.date_of_bite,
                a.bite_location,
                a.case_number,
                r.registry_id,
                r.registry_number
            FROM patients p
            INNER JOIN animal_bite_cases a ON p.patient_id = a.patient_id 
                AND a.is_archived = 0
                AND a.case_status != 'Completed' 
            LEFT JOIN registry_records r ON a.case_id = r.case_id 
                AND r.is_archived = 0
            WHERE p.patient_id = ?
              AND p.branch_id = ?
              AND p.is_archived = 0
              AND (
                    SELECT COUNT(DISTINCT vr_done.dose_number)
                    FROM vaccination_records vr_done
                    WHERE vr_done.patient_id = p.patient_id
                      AND vr_done.case_id = a.case_id
                      AND vr_done.is_archived = 0
                      AND vr_done.vaccination_status = 'Completed'
                      AND vr_done.date_administered IS NOT NULL
                      AND vr_done.date_administered <= CURDATE()
                      AND COALESCE(vr_done.vaccine_name, '') NOT LIKE '%Default%'
                  ) < 6
              AND NOT EXISTS (
                    SELECT 1
                    FROM registry_records rr_done
                    WHERE rr_done.case_id = a.case_id
                      AND rr_done.is_archived = 0
                      AND rr_done.dose_d0 = 1
                      AND rr_done.dose_d3 = 1
                      AND rr_done.dose_d7 = 1
                      AND rr_done.dose_d14 = 1
                      AND rr_done.dose_d21 = 1
                      AND rr_done.dose_d28_30 = 1
                  )
            ORDER BY a.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $patient_id, $branch_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    
    if (count($data) > 0) {
        echo json_encode(['success' => true, 'data' => $data]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Patient not found']);
    }
    exit;
}

// Handle AJAX for getting scheduled vaccination doses 
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_scheduled_doses') {
    header('Content-Type: application/json');
    $case_id = isset($_GET['case_id']) ? intval($_GET['case_id']) : 0;
    $patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;
    
    if ($case_id <= 0 && $patient_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid case or patient ID']);
        exit;
    }
    
    $sql = "SELECT 
                vr.vaccination_id,
                vr.patient_id,
                vr.case_id,
                vr.item_id,
                COALESCE(vr.vaccine_name, i.item_name, 'Unknown Vaccine') as vaccine_name,
                vr.unit_id,
                COALESCE(u.unit_name, 'N/A') as unit_name,
                vr.branch_id,
                vr.dose_number,
                vr.date_administered,
                vr.scheduled_date,
                CASE
                    WHEN vr.vaccination_status = 'Completed'
                         AND vr.date_administered IS NOT NULL
                         AND vr.date_administered > CURDATE()
                        THEN 'Invalid Future Date'
                    WHEN TRIM(COALESCE(vr.vaccination_status, '')) <> ''
                        THEN vr.vaccination_status
                    WHEN vr.date_administered IS NOT NULL
                         AND vr.date_administered <= CURDATE()
                        THEN 'Completed'
                    WHEN vr.scheduled_date IS NOT NULL
                        THEN 'Scheduled'
                    ELSE 'N/A'
                END AS vaccination_status,
                vr.is_final_dose,
                vr.remarks,
                vr.nurse_id as administered_by_id,
                COALESCE(u2.username, 'N/A') as administered_by_name,
                vr.created_at,
                i.item_name as inventory_item_name,
                CONCAT('D', 
                    CASE 
                        WHEN vr.dose_number = 1 THEN '0'
                        WHEN vr.dose_number = 2 THEN '3'
                        WHEN vr.dose_number = 3 THEN '7'
                        WHEN vr.dose_number = 4 THEN '14'
                        WHEN vr.dose_number = 5 THEN '21'
                        WHEN vr.dose_number = 6 THEN '28/30'
                        ELSE vr.dose_number
                    END
                ) as dose_label
            FROM vaccination_records vr
            LEFT JOIN units u ON vr.unit_id = u.unit_id
            LEFT JOIN inventory_items i ON vr.item_id = i.item_id
            LEFT JOIN users u2 ON vr.nurse_id = u2.user_id
            WHERE vr.is_archived = 0
            AND vr.branch_id = ?
            AND COALESCE(vr.vaccine_name, i.item_name, '') NOT LIKE '%Default%'";
    
    $params = [$branch_id];
    $types = "s";
    
    if ($case_id > 0) {
        $sql .= " AND vr.case_id = ?";
        $params[] = $case_id;
        $types .= "i";
    } else if ($patient_id > 0) {
        $sql .= " AND vr.patient_id = ?";
        $params[] = $patient_id;
        $types .= "i";
    }
    
    $sql .= " ORDER BY vr.dose_number ASC, vr.created_at ASC";
    
    $stmt = $conn->prepare($sql);
    
    if (empty($branch_id)) {
        echo json_encode(['success' => false, 'message' => 'No branch assigned to nurse']);
        exit;
    }

    $stmt->bind_param($types, ...$params);
    
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'SQL Error: ' . $stmt->error]);
        exit;
    }
    
    $result = $stmt->get_result();
    $doses = $result->fetch_all(MYSQLI_ASSOC);
    
    if (empty($doses) && $case_id > 0) {
        $regSql = "SELECT dose_d0, dose_d3, dose_d7, dose_d14, dose_d21, dose_d28_30, 
                          active_regimen, registry_number
                   FROM registry_records 
                   WHERE case_id = ? AND is_archived = 0";
        $regStmt = $conn->prepare($regSql);
        $regStmt->bind_param("i", $case_id);
        
        if (!$regStmt->execute()) {
            echo json_encode(['success' => false, 'message' => 'Registry SQL Error: ' . $regStmt->error]);
            exit;
        }

        $regResult = $regStmt->get_result();
        if ($regRow = $regResult->fetch_assoc()) {
            $doseMap = [
                'dose_d0' => 1,
                'dose_d3' => 2,
                'dose_d7' => 3,
                'dose_d14' => 4,
                'dose_d21' => 5,
                'dose_d28_30' => 6
            ];
            $doseLabels = [
                1 => 'D0',
                2 => 'D3',
                3 => 'D7',
                4 => 'D14',
                5 => 'D21',
                6 => 'D28/30'
            ];
            
            foreach ($doseMap as $field => $num) {
                if ($regRow[$field] == 1) {
                    $doses[] = [
                        'vaccination_id' => null,
                        'patient_id' => $patient_id,
                        'case_id' => $case_id,
                        'item_id' => null,
                        'vaccine_name' => 'Vaccine',
                        'unit_id' => null,
                        'unit_name' => 'N/A',
                        'branch_id' => $branch_id,
                        'dose_number' => $num,
                        'date_administered' => null,
                        'scheduled_date' => null,
                        'vaccination_status' => 'Completed',
                        'is_final_dose' => ($num === 6) ? 1 : 0,
                        'remarks' => 'Completed (from registry)',
                        'administered_by_id' => null,
                        'administered_by_name' => 'Nurse',
                        'created_at' => null,
                        'inventory_item_name' => null,
                        'dose_label' => $doseLabels[$num]
                    ];
                }
            }
        }
    }
    
    // Get next dose number
    $patientIdForNext = ($patient_id > 0) ? $patient_id : ($doses[0]['patient_id'] ?? 0);
    $caseIdForNext = ($case_id > 0) ? $case_id : ($doses[0]['case_id'] ?? 0);
    $next_dose = getNextDoseNumber($conn, $patientIdForNext, $caseIdForNext);
    
    // Completion is stage-based, not row-based. Multiple vaccine/products may
    // be administered under the same D0/D3/etc. stage.
    $isComplete = isVaccinationComplete($conn, $patientIdForNext, $caseIdForNext);
    
    echo json_encode([
        'success' => true, 
        'data' => $doses,
        'next_dose' => $next_dose,
        'next_dose_label' => getDoseLabel($next_dose),
        'is_complete' => $isComplete
    ]);
    exit;
}

// Handle AJAX for getting next dose number
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_next_dose') {
    header('Content-Type: application/json');
    $patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;
    $case_id = isset($_GET['case_id']) ? intval($_GET['case_id']) : 0;
    
    if ($patient_id <= 0 || $case_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid patient or case ID']);
        exit;
    }
    
    $next_dose = getNextDoseNumber($conn, $patient_id, $case_id);
    
    $isComplete = isVaccinationComplete($conn, $patient_id, $case_id);
    
    echo json_encode([
        'success' => true,
        'next_dose' => $next_dose,
        'next_dose_label' => getDoseLabel($next_dose),
        'is_complete' => $isComplete
    ]);
    exit;
}

// Handle vaccination submission
if (isset($_POST['submit_vaccination'])) {
    header('Content-Type: application/json');

    // CSRF validation
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
        echo json_encode(['success' => false, 'message' => 'Invalid request token. Please refresh the page and try again.']);
        exit;
    }

    $patient_id = isset($_POST['patient_id']) ? intval($_POST['patient_id']) : 0;
    $case_id = isset($_POST['case_id']) ? intval($_POST['case_id']) : 0;
    $vaccine_items = isset($_POST['vaccine_items']) ? json_decode($_POST['vaccine_items'], true) : [];

    if ($patient_id <= 0 || $case_id <= 0 || empty($vaccine_items) || !is_array($vaccine_items)) {
        echo json_encode(['success' => false, 'message' => 'Invalid data provided']);
        exit;
    }

    // Validate that patient and case belong to the nurse's branch.
    $caseCheck = $conn->prepare("SELECT p.full_name
                                FROM patients p
                                INNER JOIN animal_bite_cases a ON p.patient_id = a.patient_id
                                WHERE p.patient_id = ?
                                  AND p.branch_id = ?
                                  AND p.is_archived = 0
                                  AND a.case_id = ?
                                  AND a.branch_id = ?
                                  AND a.is_archived = 0
                                  AND a.case_status != 'Completed'
                                LIMIT 1");
    $caseCheck->bind_param("isis", $patient_id, $branch_id, $case_id, $branch_id);
    $caseCheck->execute();
    $caseData = $caseCheck->get_result()->fetch_assoc();
    $caseCheck->close();

    if (!$caseData) {
        echo json_encode(['success' => false, 'message' => 'The selected patient/case is not an active case in your branch.']);
        exit;
    }

    $patient_name = $caseData['full_name'];

    $conn->begin_transaction();

    try {
        $success_count = 0;
        $vaccination_details = [];

        foreach ($vaccine_items as $item) {
            $item_id = intval($item['item_id'] ?? 0);
            $dose_number = intval($item['dose_number'] ?? 0);
            $quantity = intval($item['quantity'] ?? 0);
            $date_administered = !empty($item['date_administered'])
                ? trim($item['date_administered'])
                : date('Y-m-d');
            $vaccination_status = !empty($item['vaccine_status'])
                ? trim($item['vaccine_status'])
                : 'Completed';
            $remarks = !empty($item['remarks']) ? trim($item['remarks']) : '';

            if ($item_id <= 0) {
                throw new Exception('Please select a valid vaccine.');
            }
            if ($dose_number < 1 || $dose_number > 6) {
                throw new Exception("Invalid dose number: {$dose_number}. Dose must be between 1 and 6.");
            }
            if ($quantity <= 0) {
                throw new Exception('Quantity must be greater than zero.');
            }
            if (!validYmdDate($date_administered)) {
                throw new Exception('Invalid vaccination date.');
            }

            // Administrative Staff owns scheduling. The Nurse can only record
            // an administered dose or mark an existing schedule as missed.
            $allowed_statuses = ['Completed', 'Missed'];
            if (!in_array($vaccination_status, $allowed_statuses, true)) {
                throw new Exception('Invalid vaccination status.');
            }

            // A completed vaccination cannot be recorded before it happens.
            if ($vaccination_status === 'Completed' && $date_administered > date('Y-m-d')) {
                throw new Exception('A completed vaccination cannot have a future administration date. Please ask Administrative Staff to update the schedule.');
            }

            // Do not trust unit_id from JavaScript. Load item/unit from DB.
            $vaccine = getMedicalSupplyItem($conn, $item_id);
            if (!$vaccine) {
                throw new Exception('Selected vaccine was not found under Medical Supplies.');
            }

            $vaccine_name = $vaccine['item_name'];
            $unit_id = (int)$vaccine['unit_id'];
            $is_final_dose = ($dose_number === 6) ? 1 : 0;

            // Different products may be administered under the same dose stage
            // (for example Rabies Vaccine + ERIG + ATS on D0). What we prevent is
            // accidentally saving the SAME product as Completed twice for the
            // same patient, case, and dose stage. Use quantity > 1 when multiple
            // units of the same product were actually used.
            if ($vaccination_status === 'Completed') {
                $duplicateStmt = $conn->prepare("SELECT vaccination_id
                                                  FROM vaccination_records
                                                  WHERE patient_id = ?
                                                    AND case_id = ?
                                                    AND item_id = ?
                                                    AND dose_number = ?
                                                    AND vaccination_status = 'Completed'
                                                    AND is_archived = 0
                                                  LIMIT 1");
                $duplicateStmt->bind_param("iiii", $patient_id, $case_id, $item_id, $dose_number);
                $duplicateStmt->execute();
                $duplicateExists = $duplicateStmt->get_result()->num_rows > 0;
                $duplicateStmt->close();

                if ($duplicateExists) {
                    throw new Exception(
                        $vaccine_name . ' has already been recorded as Completed for ' .
                        getDoseLabel($dose_number) . ' in this case.'
                    );
                }
            }

            // Completed records use the submitted date as the administration
            // date. Additional products in the same dose stage inherit the
            // original Administrative Staff schedule date below.
            $completed_date = ($vaccination_status === 'Completed') ? $date_administered : null;
            $scheduled_date = ($vaccination_status !== 'Completed') ? $date_administered : null;

            // Find and lock the active schedule placeholder created by
            // Administrative Staff for this exact patient/case/dose stage.
            // The first product submitted for the stage updates this row.
            // Additional products under the same stage are inserted separately.
            $findSchedule = $conn->prepare("
                SELECT vaccination_id, scheduled_date
                FROM vaccination_records
                WHERE patient_id = ?
                  AND case_id = ?
                  AND branch_id = ?
                  AND dose_number = ?
                  AND vaccination_status = 'Scheduled'
                  AND is_archived = 0
                  AND nurse_id IS NULL
                  AND item_id IS NULL
                ORDER BY vaccination_id ASC
                LIMIT 1
                FOR UPDATE
            ");
            $findSchedule->bind_param(
                "iisi",
                $patient_id,
                $case_id,
                $branch_id,
                $dose_number
            );
            if (!$findSchedule->execute()) {
                throw new Exception('Failed to locate the scheduled vaccination.');
            }
            $scheduleRow = $findSchedule->get_result()->fetch_assoc();
            $findSchedule->close();

            $admin_schedule_id = $scheduleRow
                ? (int)$scheduleRow['vaccination_id']
                : null;

            if ($scheduleRow && !empty($scheduleRow['scheduled_date'])) {
                $scheduled_date = $scheduleRow['scheduled_date'];
            }

            $vaccination_id = 0;

            if ($admin_schedule_id !== null && $vaccination_status === 'Completed') {
                // Convert the Admin Staff schedule into the completed Nurse
                // record. scheduled_date is intentionally preserved so the
                // original appointment and actual administration dates remain.
                $updateVaccination = $conn->prepare("
                    UPDATE vaccination_records
                    SET item_id = ?,
                        vaccine_name = ?,
                        unit_id = ?,
                        date_administered = ?,
                        administered_datetime = NOW(),
                        vaccination_status = 'Completed',
                        is_final_dose = ?,
                        remarks = ?,
                        nurse_id = ?
                    WHERE vaccination_id = ?
                      AND vaccination_status = 'Scheduled'
                      AND is_archived = 0
                ");
                $updateVaccination->bind_param(
                    "isisisii",
                    $item_id,
                    $vaccine_name,
                    $unit_id,
                    $date_administered,
                    $is_final_dose,
                    $remarks,
                    $user_id,
                    $admin_schedule_id
                );
                if (!$updateVaccination->execute() || $updateVaccination->affected_rows !== 1) {
                    throw new Exception('Failed to update the scheduled vaccination.');
                }
                $updateVaccination->close();
                $vaccination_id = $admin_schedule_id;
            } elseif ($admin_schedule_id !== null && $vaccination_status === 'Missed') {
                // Keep the scheduled date and turn the existing placeholder
                // into a missed appointment instead of creating a duplicate.
                $updateVaccination = $conn->prepare("
                    UPDATE vaccination_records
                    SET vaccination_status = 'Missed',
                        remarks = ?,
                        nurse_id = ?
                    WHERE vaccination_id = ?
                      AND vaccination_status = 'Scheduled'
                      AND is_archived = 0
                ");
                $updateVaccination->bind_param(
                    "sii",
                    $remarks,
                    $user_id,
                    $admin_schedule_id
                );
                if (!$updateVaccination->execute() || $updateVaccination->affected_rows !== 1) {
                    throw new Exception('Failed to mark the scheduled vaccination as missed.');
                }
                $updateVaccination->close();
                $vaccination_id = $admin_schedule_id;
            } else {
                // There is no available Admin Staff placeholder. This is valid
                // for an extra vaccine/product administered under the same dose
                // stage, or for migrated cases without a schedule placeholder.
                // For an extra product, copy the schedule date from the first
                // active record in this same patient/case/dose stage so every
                // vaccine displays the same original appointment date.
                $findStageSchedule = $conn->prepare("
                    SELECT scheduled_date
                    FROM vaccination_records
                    WHERE patient_id = ?
                      AND case_id = ?
                      AND branch_id = ?
                      AND dose_number = ?
                      AND scheduled_date IS NOT NULL
                      AND is_archived = 0
                    ORDER BY
                        CASE WHEN vaccination_status = 'Completed' THEN 0 ELSE 1 END,
                        vaccination_id ASC
                    LIMIT 1
                    FOR UPDATE
                ");
                $findStageSchedule->bind_param(
                    "iisi",
                    $patient_id,
                    $case_id,
                    $branch_id,
                    $dose_number
                );
                if (!$findStageSchedule->execute()) {
                    throw new Exception('Failed to load the dose schedule date.');
                }
                $stageScheduleRow = $findStageSchedule->get_result()->fetch_assoc();
                $findStageSchedule->close();

                if ($stageScheduleRow && !empty($stageScheduleRow['scheduled_date'])) {
                    $scheduled_date = $stageScheduleRow['scheduled_date'];
                }

                $insertVaccination = "
                    INSERT INTO vaccination_records
                    (
                        patient_id,
                        case_id,
                        item_id,
                        vaccine_name,
                        unit_id,
                        branch_id,
                        dose_number,
                        date_administered,
                        administered_datetime,
                        scheduled_date,
                        vaccination_status,
                        is_final_dose,
                        remarks,
                        nurse_id
                    )
                    VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?,
                        CASE WHEN ? = 'Completed' THEN NOW() ELSE NULL END,
                        ?, ?, ?, ?, ?
                    )
                ";

                $stmt = $conn->prepare($insertVaccination);
                $stmt->bind_param(
                    "iiisisissssisi",
                    $patient_id,
                    $case_id,
                    $item_id,
                    $vaccine_name,
                    $unit_id,
                    $branch_id,
                    $dose_number,
                    $completed_date,
                    $vaccination_status,
                    $scheduled_date,
                    $vaccination_status,
                    $is_final_dose,
                    $remarks,
                    $user_id
                );

                if (!$stmt->execute()) {
                    throw new Exception('Failed to save vaccination record.');
                }
                $vaccination_id = $conn->insert_id;
                $stmt->close();
            }

            $batch_summary = 'No stock deducted';

            // Only a COMPLETED vaccination physically consumes vaccine stock.
            if ($vaccination_status === 'Completed') {
                $used_batches = deductVaccineStockFEFO(
                    $conn,
                    $item_id,
                    $branch_id,
                    $quantity
                );
                $batch_summary = vaccineBatchSummary($used_batches);

                $transactionRemarks = "Vaccination | Patient ID: {$patient_id}" .
                                      " | Case ID: {$case_id}" .
                                      " | Vaccine: {$vaccine_name}" .
                                      " | Dose #: {$dose_number}" .
                                      " | Qty Used: {$quantity}" .
                                      " | Batch(es): {$batch_summary}" .
                                      " | Date: {$date_administered}";

                $insertTransaction = "
                    INSERT INTO stock_transactions
                    (
                        item_id,
                        user_id,
                        vaccination_id,
                        branch_id,
                        transaction_type,
                        quantity,
                        remarks,
                        transaction_date
                    )
                    VALUES (?, ?, ?, ?, 'OUT', ?, ?, ?)
                ";

                $transactionDate = $date_administered . ' ' . date('H:i:s');
                $stmt = $conn->prepare($insertTransaction);
                $stmt->bind_param(
                    "iiisiss",
                    $item_id,
                    $user_id,
                    $vaccination_id,
                    $branch_id,
                    $quantity,
                    $transactionRemarks,
                    $transactionDate
                );
                if (!$stmt->execute()) {
                    throw new Exception('Failed to save stock transaction.');
                }
                $stmt->close();

                $insertUsage = "
                    INSERT INTO inventory_usage_history
                    (
                        item_id,
                        branch_id,
                        usage_date,
                        quantity_used,
                        patient_count
                    )
                    VALUES (?, ?, ?, ?, 1)
                ";
                $stmt = $conn->prepare($insertUsage);
                $stmt->bind_param("issi", $item_id, $branch_id, $date_administered, $quantity);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to save usage history.');
                }
                $stmt->close();

                // Registry dose flags represent completed doses only.
                $updateReg = $conn->prepare("
                    UPDATE registry_records
                    SET dose_d0 = CASE WHEN ? = 1 THEN 1 ELSE dose_d0 END,
                        dose_d3 = CASE WHEN ? = 2 THEN 1 ELSE dose_d3 END,
                        dose_d7 = CASE WHEN ? = 3 THEN 1 ELSE dose_d7 END,
                        dose_d14 = CASE WHEN ? = 4 THEN 1 ELSE dose_d14 END,
                        dose_d21 = CASE WHEN ? = 5 THEN 1 ELSE dose_d21 END,
                        dose_d28_30 = CASE WHEN ? = 6 THEN 1 ELSE dose_d28_30 END,
                        updated_by = ?,
                        updated_at = NOW()
                    WHERE case_id = ? AND is_archived = 0
                ");
                $updateReg->bind_param(
                    "iiiiiiii",
                    $dose_number,
                    $dose_number,
                    $dose_number,
                    $dose_number,
                    $dose_number,
                    $dose_number,
                    $user_id,
                    $case_id
                );
                if (!$updateReg->execute()) {
                    throw new Exception('Failed to update registry dose status.');
                }
                $updateReg->close();

                // The CASE is complete only when all six dose STAGES are complete.
                // Multiple products under the same stage never count as extra doses.
                if (isVaccinationComplete($conn, $patient_id, $case_id)) {
                    $updateCase = $conn->prepare("UPDATE animal_bite_cases
                                                  SET case_status = 'Completed'
                                                  WHERE case_id = ? AND branch_id = ?");
                    $updateCase->bind_param("is", $case_id, $branch_id);
                    if (!$updateCase->execute()) {
                        throw new Exception('Failed to mark the animal bite case as completed.');
                    }
                    $updateCase->close();
                }
            }

            $auditAction = ($vaccination_status === 'Completed' ? 'Vaccination Administered - ' : 'Vaccination ' . $vaccination_status . ' - ') .
                           $vaccine_name .
                           ' Dose #' . $dose_number .
                           ' (' . getDoseLabel($dose_number) . ')' .
                           " | Patient: {$patient_name} (ID: {$patient_id})" .
                           " | Case ID: {$case_id}" .
                           " | Quantity: {$quantity}" .
                           " | Status: {$vaccination_status}" .
                           " | Batch(es): {$batch_summary}" .
                           " | Date: {$date_administered}";

            logAudit($conn, $user_id, $branch_id, $auditAction, 'Vaccination');

            $vaccination_details[] = [
                'vaccine_name' => $vaccine_name,
                'dose_number' => $dose_number,
                'quantity' => $quantity,
                'status' => $vaccination_status
            ];

            $success_count++;
        }

        $adminStaff = getAdminStaffByBranch($conn, $branch_id);

        if (!empty($adminStaff) && !empty($vaccination_details)) {
            $doseList = '';
            foreach ($vaccination_details as $detail) {
                $doseLabel = getDoseLabel($detail['dose_number']);
                $doseList .= "• {$detail['vaccine_name']} - {$doseLabel} ({$detail['status']})\n";
            }

            $notification_title = 'Vaccination Record Updated';
            $notification_message =
                "Vaccination information was recorded by Nurse {$username} at {$branch_name}.\n\n" .
                "Patient: {$patient_name} (ID: P" . str_pad($patient_id, 4, '0', STR_PAD_LEFT) . ")\n" .
                "Case ID: C" . str_pad($case_id, 4, '0', STR_PAD_LEFT) . "\n" .
                'Date: ' . date('F d, Y H:i:s') . "\n\n" .
                "Vaccination Entries:\n" .
                $doseList .
                "\nTotal Entries: " . count($vaccination_details);

            foreach ($adminStaff as $staff) {
                createNotification(
                    $conn,
                    $staff['user_id'],
                    $notification_title,
                    $notification_message,
                    'vaccination'
                );
            }

            createNotification(
                $conn,
                $user_id,
                'Vaccination Submitted Successfully',
                "Vaccination information for {$patient_name} was saved successfully at {$branch_name}.\n" .
                'Total entries: ' . count($vaccination_details) . "\n" .
                'Date: ' . date('F d, Y H:i:s'),
                'vaccination'
            );
        }

        $conn->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Successfully saved ' . $success_count . ' vaccination entr' . ($success_count === 1 ? 'y' : 'ies') . '.'
        ]);
        exit;

    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Vaccination submission error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ============================================================
// GET PATIENTS ELIGIBLE FOR VACCINATION DROPDOWN
// ============================================================
// Only patients in the nurse's branch who still have at least one
// active case with fewer than six DISTINCT completed dose stages
// are shown. Patients who already completed D0-D28/30 disappear
// from the Select Patient dropdown after the page reloads.

$sql_patients = "SELECT DISTINCT
                    p.patient_id,
                    p.full_name
                 FROM patients p
                 INNER JOIN animal_bite_cases a
                    ON p.patient_id = a.patient_id
                   AND a.is_archived = 0
                   AND a.case_status != 'Completed'
                 WHERE p.branch_id = ?
                   AND p.is_archived = 0
                   AND (
                        SELECT COUNT(DISTINCT vr_done.dose_number)
                        FROM vaccination_records vr_done
                        WHERE vr_done.patient_id = p.patient_id
                          AND vr_done.case_id = a.case_id
                          AND vr_done.is_archived = 0
                          AND vr_done.vaccination_status = 'Completed'
                          AND vr_done.date_administered IS NOT NULL
                          AND vr_done.date_administered <= CURDATE()
                          AND COALESCE(vr_done.vaccine_name, '') NOT LIKE '%Default%'
                   ) < 6
                   AND NOT EXISTS (
                        SELECT 1
                        FROM registry_records rr_done
                        WHERE rr_done.case_id = a.case_id
                          AND rr_done.is_archived = 0
                          AND rr_done.dose_d0 = 1
                          AND rr_done.dose_d3 = 1
                          AND rr_done.dose_d7 = 1
                          AND rr_done.dose_d14 = 1
                          AND rr_done.dose_d21 = 1
                          AND rr_done.dose_d28_30 = 1
                   )
                 ORDER BY p.full_name ASC";

$stmt_patients = $conn->prepare($sql_patients);
$stmt_patients->bind_param("s", $branch_id);
$stmt_patients->execute();
$patients = $stmt_patients->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_patients->close();

// ============================================================
// PATIENT LIST TAB
// ============================================================
// This is separate from the Select Patient dropdown so the nurse can
// browse/search eligible patients without placing a long table underneath
// the vaccination form.

$active_tab = (isset($_GET['tab']) && $_GET['tab'] === 'patients')
    ? 'patients'
    : 'vaccination';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Count only patients who still have at least one vaccination-eligible case.
$count_sql = "SELECT COUNT(*) AS total
              FROM patients p
              WHERE p.branch_id = ?
                AND p.is_archived = 0
                AND EXISTS (
                    SELECT 1
                    FROM animal_bite_cases a
                    WHERE a.patient_id = p.patient_id
                      AND a.branch_id = p.branch_id
                      AND a.is_archived = 0
                      AND a.case_status != 'Completed'
                      AND (
                            SELECT COUNT(DISTINCT vr_done.dose_number)
                            FROM vaccination_records vr_done
                            WHERE vr_done.patient_id = p.patient_id
                              AND vr_done.case_id = a.case_id
                              AND vr_done.is_archived = 0
                              AND vr_done.vaccination_status = 'Completed'
                              AND vr_done.date_administered IS NOT NULL
                              AND vr_done.date_administered <= CURDATE()
                              AND COALESCE(vr_done.vaccine_name, '') NOT LIKE '%Default%'
                              AND vr_done.dose_number BETWEEN 1 AND 6
                      ) < 6
                      AND NOT EXISTS (
                            SELECT 1
                            FROM registry_records rr_done
                            WHERE rr_done.case_id = a.case_id
                              AND rr_done.is_archived = 0
                              AND rr_done.dose_d0 = 1
                              AND rr_done.dose_d3 = 1
                              AND rr_done.dose_d7 = 1
                              AND rr_done.dose_d14 = 1
                              AND rr_done.dose_d21 = 1
                              AND rr_done.dose_d28_30 = 1
                      )
                )";

if ($search !== '') {
    $count_sql .= " AND (
        p.full_name LIKE ?
        OR p.email LIKE ?
        OR p.contact_number LIKE ?
    )";
}

$count_stmt = $conn->prepare($count_sql);

if ($search !== '') {
    $search_param = '%' . $search . '%';
    $count_stmt->bind_param(
        "ssss",
        $branch_id,
        $search_param,
        $search_param,
        $search_param
    );
} else {
    $count_stmt->bind_param("s", $branch_id);
}

$count_stmt->execute();
$total_rows = (int)($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$count_stmt->close();

$total_pages = max(1, (int)ceil($total_rows / $limit));

if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $limit;
}

// Get the latest vaccination-eligible active case for every patient.
$patient_list_sql = "SELECT
                        p.patient_id,
                        p.full_name,
                        p.email,
                        p.contact_number,
                        a.case_id,
                        a.case_status,
                        a.date_of_bite,
                        a.case_number
                     FROM patients p
                     INNER JOIN (
                         SELECT
                            abc.patient_id,
                            abc.branch_id,
                            abc.case_id,
                            abc.case_status,
                            abc.date_of_bite,
                            abc.case_number,
                            ROW_NUMBER() OVER (
                                PARTITION BY abc.patient_id, abc.branch_id
                                ORDER BY abc.created_at DESC
                            ) AS rn
                         FROM animal_bite_cases abc
                         WHERE abc.is_archived = 0
                           AND abc.case_status != 'Completed'
                           AND (
                                SELECT COUNT(DISTINCT vr_done.dose_number)
                                FROM vaccination_records vr_done
                                WHERE vr_done.patient_id = abc.patient_id
                                  AND vr_done.case_id = abc.case_id
                                  AND vr_done.is_archived = 0
                                  AND vr_done.vaccination_status = 'Completed'
                                  AND vr_done.date_administered IS NOT NULL
                                  AND vr_done.date_administered <= CURDATE()
                                  AND COALESCE(vr_done.vaccine_name, '') NOT LIKE '%Default%'
                                  AND vr_done.dose_number BETWEEN 1 AND 6
                           ) < 6
                           AND NOT EXISTS (
                                SELECT 1
                                FROM registry_records rr_done
                                WHERE rr_done.case_id = abc.case_id
                                  AND rr_done.is_archived = 0
                                  AND rr_done.dose_d0 = 1
                                  AND rr_done.dose_d3 = 1
                                  AND rr_done.dose_d7 = 1
                                  AND rr_done.dose_d14 = 1
                                  AND rr_done.dose_d21 = 1
                                  AND rr_done.dose_d28_30 = 1
                           )
                     ) a
                        ON p.patient_id = a.patient_id
                       AND p.branch_id = a.branch_id
                       AND a.rn = 1
                     WHERE p.branch_id = ?
                       AND p.is_archived = 0";

if ($search !== '') {
    $patient_list_sql .= " AND (
        p.full_name LIKE ?
        OR p.email LIKE ?
        OR p.contact_number LIKE ?
    )";
}

$patient_list_sql .= " ORDER BY p.patient_id DESC LIMIT ? OFFSET ?";

$patient_list_stmt = $conn->prepare($patient_list_sql);

if ($search !== '') {
    $search_param = '%' . $search . '%';
    $patient_list_stmt->bind_param(
        "ssssii",
        $branch_id,
        $search_param,
        $search_param,
        $search_param,
        $limit,
        $offset
    );
} else {
    $patient_list_stmt->bind_param(
        "sii",
        $branch_id,
        $limit,
        $offset
    );
}

$patient_list_stmt->execute();
$patient_list = $patient_list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$patient_list_stmt->close();

function getStatusBadge($status)
{
    $status = strtolower(trim((string)$status));

    if (in_array($status, ['ongoing', 'active', 'scheduled'], true)) {
        return 'status-badge ongoing';
    }

    return 'status-badge completed';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Nurse - Vaccination Administration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="sidebar.css" />
    <style>
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
            --bg: #F2F2F2;
            --card-bg: #ECEEF7;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
        }
        * { box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Roboto, system-ui, sans-serif; margin: 0; padding: 0; }

        .main { margin-left: 260px; min-height: 100vh; background: #f9faff; }
        .topbar { background: white; height: 80px; display: flex; align-items: center; justify-content: space-between; padding: 0 35px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06); border-bottom: 1px solid #e9edf5; }
        .topbar h3 { font-size: 28px; font-weight: 700; color: var(--primary); margin: 0; letter-spacing: -0.3px; }
        .topbar h3 small { font-size: 16px; font-weight: 400; color: #666; margin-left: 10px; }
        .profile {
            color: var(--primary);
            cursor: default;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 16px;
            white-space: nowrap;
        }
        .profile i {
            font-size: 16px;
            color: var(--primary);
        }
        .profile-name {
            font-weight: 600;
        }
        .profile-separator {
            font-weight: 400;
            color: var(--primary);
            margin: 0 1px;
        }
        .profile-role {
            font-weight: 400;
            color: var(--primary);
        }
        .content { padding: 35px 35px 40px; }



        .table-wrap { background: white; border-radius: 18px; box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05); overflow: hidden; padding: 0; margin-bottom: 20px; }
        .table { margin-bottom: 0; border-collapse: separate; border-spacing: 0; }
        .table thead th { background: var(--primary); color: white; font-weight: 700; font-size: 15px; padding: 16px 20px; border-bottom: 1px solid #e2e7f2; letter-spacing: 0.3px; }
        .table tbody td { padding: 16px 20px; vertical-align: middle; border-bottom: 1px solid #edf1f8; color: #1f2a4a; font-weight: 500; }
        .table tbody tr:last-child td { border-bottom: none; }


        /* Vaccination Form Styles */
        .vaccination-section { background: white; border-radius: 18px; box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05); padding: 24px; margin-bottom: 30px; }
        .vaccination-section .section-title { color: var(--primary); font-weight: 700; font-size: 20px; margin-bottom: 20px; }
        
        .patient-info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px; }
        .patient-info-item { background: #f8f9ff; padding: 12px 16px; border-radius: 12px; }
        .patient-info-item label { font-size: 12px; color: #7a85a8; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 4px; }
        .patient-info-item .value { font-weight: 600; color: #1f2a4a; font-size: 15px; }

        .next-dose-indicator { background: #e8f5e9; border: 2px solid #4caf50; border-radius: 12px; padding: 12px 20px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
        .next-dose-indicator .dose-label { font-size: 24px; font-weight: 700; color: #2e7d32; }
        .next-dose-indicator .dose-info { font-size: 14px; color: #555; }
        .next-dose-indicator .vaccine-complete { background: #4caf50; color: white; padding: 6px 16px; border-radius: 20px; font-weight: 600; }

        .vaccine-entry { background: #f8f9ff; border: 1px solid #e2e7f2; border-radius: 12px; padding: 16px; margin-bottom: 12px; position: relative; }
        .vaccine-entry .remove-btn { position: absolute; top: 8px; right: 8px; background: none; border: none; color: #dc3545; font-size: 20px; cursor: pointer; }
        .vaccine-entry .remove-btn:hover { color: #a71d2a; }
        .vaccine-entry .entry-number { font-weight: 600; color: var(--primary); margin-bottom: 12px; font-size: 14px; }

        #scheduledDosesDisplay { background: #f8faff; border-radius: 12px; padding: 16px; border: 1px solid #e2e7f2; margin-top: 20px; }
        #scheduledDosesTable th { background-color: #e8ecf5 !important; color: #1f2a4a !important; font-size: 12px; font-weight: 700; border-bottom: 2px solid #d0d7e8; padding: 10px 12px; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; }
        #scheduledDosesTable td { font-size: 13px; vertical-align: middle; padding: 10px 12px; border-bottom: 1px solid #eef2f8; color: #1f2a4a; }
        #scheduledDosesTable tbody tr:nth-child(even) { background-color: #f8f9ff; }
        #scheduledDosesTable tbody tr:hover { background-color: #e8edf8; }

        .alert-toast { position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; }
        .btn-primary { background-color: var(--primary); border-color: var(--primary); }
        .btn-primary:hover { background-color: #1f2a6b; border-color: #1f2a6b; }
        .btn-success { background-color: #28a745; border-color: #28a745; }
        .btn-success:hover { background-color: #1e7e34; border-color: #1e7e34; }
        .dose-suggestion { background: #e3f2fd; border: 1px solid #90caf9; border-radius: 8px; padding: 8px 14px; font-size: 13px; color: #1565c0; display: inline-flex; align-items: center; gap: 8px; }
        .dose-suggestion i { font-size: 16px; }

        /* Module Tabs */
        .module-tabs {
            border-bottom: 1px solid #dfe5f0;
            margin-bottom: 24px;
            gap: 8px;
        }

        .module-tabs .nav-link {
            border: none;
            border-radius: 12px 12px 0 0;
            color: #66728d;
            background: transparent;
            padding: 12px 20px;
            font-weight: 700;
            font-size: 15px;
            transition: 0.15s ease;
        }

        .module-tabs .nav-link:hover {
            color: var(--primary);
            background: #eef1fa;
        }

        .module-tabs .nav-link.active {
            background: var(--primary);
            color: #fff;
        }

        .module-tabs .nav-link .tab-count {
            margin-left: 7px;
            font-size: 11px;
            padding: 3px 7px;
            border-radius: 20px;
            background: #e8ecf7;
            color: var(--primary);
        }

        .module-tabs .nav-link.active .tab-count {
            background: rgba(255,255,255,0.2);
            color: #fff;
        }

        .patients-tab-card {
            background: white;
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            padding: 24px;
            margin-bottom: 30px;
        }

        .patients-tab-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }

        .patients-tab-header h5 {
            color: var(--primary);
            font-size: 20px;
            font-weight: 700;
            margin: 0;
        }

        .search-wrap {
            position: relative;
            max-width: 420px;
            margin-bottom: 20px;
        }

        .search-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #7a85a8;
            font-size: 18px;
        }

        .search-wrap input {
            width: 100%;
            padding: 12px 12px 12px 44px;
            border: 1px solid #d0d7e8;
            border-radius: 10px;
            font-size: 15px;
            background: white;
            outline: none;
            transition: 0.15s;
        }

        .search-wrap input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.15);
        }

        .status-badge {
            display: inline-block;
            font-weight: 600;
            font-size: 13px;
            padding: 4px 16px;
            border-radius: 40px;
            letter-spacing: 0.2px;
        }

        .status-badge.ongoing {
            background: #fde8b0;
            color: #8a6d00;
        }

        .status-badge.completed {
            background: #d4f0d4;
            color: #1a6e1a;
        }

        .action-icon-btn {
            border: 0;
            background: transparent;
            color: var(--primary);
            font-size: 21px;
            line-height: 1;
            padding: 6px 8px;
            border-radius: 8px;
            opacity: 0.75;
            transition: 0.15s ease;
        }

        .action-icon-btn:hover {
            opacity: 1;
            background: #eef1fa;
        }

        .pagination-wrap {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 20px;
        }

        .pagination-wrap .page-link {
            color: var(--primary);
            border-radius: 8px;
            padding: 8px 14px;
            font-weight: 500;
            border: 1px solid #e2e7f2;
        }

        .pagination-wrap .page-link:hover {
            background: #f0f3fc;
            border-color: var(--primary);
        }

        .pagination-wrap .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .pagination-wrap .page-item.disabled .page-link {
            color: #b0b8c8;
        }

        .pagination-info {
            text-align: center;
            color: #7a85a8;
            font-size: 14px;
            margin-top: 12px;
        }

        @media (max-width: 991px) {
            .main { margin-left: 90px; }
            .sidebar { width: 90px; padding: 16px 10px; }
            .system-name, .nav-menu span, .logout span { display: none; }
            .logo-area { justify-content: center; }
            .nav-menu a { justify-content: center; padding: 12px 8px; }
            .nav-menu a i { font-size: 26px; margin: 0; }
            .logout a { justify-content: center; }
            .topbar h3 { font-size: 22px; }
        }

        @media (max-width: 576px) {
            .topbar { padding: 0 16px; height: auto; min-height: 70px; flex-wrap: wrap; gap: 8px; padding: 12px 16px; }
            .topbar h3 { font-size: 18px; }
            .content { padding: 20px 16px; }
            .alert-toast { min-width: 90%; right: 5%; top: 10px; }
            .profile { font-size: 13px; }
            .vaccination-section { padding: 16px; }
            .patient-info-grid { grid-template-columns: 1fr; }
            #scheduledDosesTable { font-size: 12px; }
            #scheduledDosesTable th, #scheduledDosesTable td { padding: 8px 10px; }
            #scheduledDosesTable th { font-size: 10px; white-space: normal; }
            #scheduledDosesTable td { font-size: 11px; }
            .next-dose-indicator { flex-direction: column; align-items: stretch; text-align: center; }
            .module-tabs {
                flex-wrap: nowrap;
                overflow-x: auto;
                padding-bottom: 2px;
            }
            .module-tabs .nav-link {
                white-space: nowrap;
                padding: 10px 14px;
                font-size: 13px;
            }
            .patients-tab-card {
                padding: 16px;
            }
        }
    </style>
</head>
<body>

<div id="alertContainer"></div>

<div class="sidebar">
    <div class="logo-area">
        <div class="logo-frame"><img src="logo.png" alt="Smart Bite Care Logo" class="logo" /></div>
        <div class="system-name">Smart Bite Care</div>
    </div>
    <nav class="nav-menu">
        <ul>
            <li><a href="Nurse_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="Nurse_Patients.php"><i class="bi bi-heart-pulse-fill"></i><span>Patients</span></a></li>
            <li><a class="active" href="Nurse_Vaccination.php"><i class="bi-shield-plus"></i><span>Vaccination</span></a></li>
            <li><a href="Nurse_MedicalSuppliesManagement.php"><i class="bi bi-calendar-check"></i><span>Medical Supplies Management</span></a></li>
            <li><a href="Nurse_SupplyPrediction.php"><i class="bi bi-box-seam"></i><span>Supply Prediction</span></a></li>
            <li><a href="Nurse_Notification.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
        </ul>
    </nav>
    <div class="logout"><a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></div>
</div>

<div class="main">
    <div class="topbar">
        <h3>Vaccination Administration <small><?php echo htmlspecialchars($branch_name); ?></small></h3>
        <div class="profile">
            <i class="bi bi-person-circle"></i>
            <span class="profile-name"><?php echo htmlspecialchars($username); ?></span>
            <span class="profile-separator">|</span>
            <span class="profile-role">Nurse</span>
        </div>
    </div>

    <div class="content">

        <!-- ================================================= -->
        <!-- VACCINATION MODULE TABS -->
        <!-- ================================================= -->
        <ul class="nav module-tabs" id="vaccinationModuleTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button
                    class="nav-link <?php echo $active_tab === 'vaccination' ? 'active' : ''; ?>"
                    id="vaccination-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#vaccination-pane"
                    data-tab-name="vaccination"
                    type="button"
                    role="tab"
                    aria-controls="vaccination-pane"
                    aria-selected="<?php echo $active_tab === 'vaccination' ? 'true' : 'false'; ?>"
                >
                    <i class="bi bi-shield-plus me-2"></i>
                    Administer Vaccination
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button
                    class="nav-link <?php echo $active_tab === 'patients' ? 'active' : ''; ?>"
                    id="patients-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#patients-pane"
                    data-tab-name="patients"
                    type="button"
                    role="tab"
                    aria-controls="patients-pane"
                    aria-selected="<?php echo $active_tab === 'patients' ? 'true' : 'false'; ?>"
                >
                    <i class="bi bi-people me-2"></i>
                    Patient List
                    <span class="tab-count"><?php echo (int)$total_rows; ?></span>
                </button>
            </li>
        </ul>

        <div class="tab-content" id="vaccinationModuleTabContent">

            <!-- ADMINISTER VACCINATION TAB -->
            <div
                class="tab-pane fade <?php echo $active_tab === 'vaccination' ? 'show active' : ''; ?>"
                id="vaccination-pane"
                role="tabpanel"
                aria-labelledby="vaccination-tab"
                tabindex="0"
            >
        <div class="vaccination-section" id="vaccinationSection">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="section-title"><i class="bi bi-syringe me-2"></i>Administer Vaccination</h5>
                <span class="badge bg-primary rounded-pill px-3 py-2" id="selectedPatientBadge">No patient selected</span>
            </div>

            <div class="row mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-bold">Select Patient <span class="text-danger">*</span></label>
                    <select class="form-select" id="patientSelect" onchange="loadPatientData()">
                        <option value="">-- Select Patient --</option>
                        <?php foreach ($patients as $patient): ?>
                            <option value="<?php echo $patient['patient_id']; ?>"><?php echo htmlspecialchars($patient['full_name']); ?> (P<?php echo str_pad($patient['patient_id'], 4, '0', STR_PAD_LEFT); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="patientInfoDisplay" style="display:none;">
                <div class="patient-info-grid" id="patientInfoGrid"></div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Select Case <span class="text-danger">*</span></label>
                        <select class="form-select" id="caseSelect" onchange="onCaseChange()">
                            <option value="">-- Select a case --</option>
                        </select>
                    </div>
                </div>

                <hr>

                <div id="nextDoseIndicator" style="display:none;">
                    <div class="next-dose-indicator">
                        <div>
                            <span class="dose-label" id="nextDoseLabel">D0</span>
                            <span class="dose-info" id="nextDoseInfo">Next dose to administer</span>
                        </div>
                        <div>
                            <span class="dose-suggestion" id="doseSuggestion">
                                <i class="bi bi-lightbulb-fill"></i>
                                <span id="doseSuggestionText">This is the next scheduled dose</span>
                            </span>
                        </div>
                        <div>
                            <span class="vaccine-complete" id="vaccinationCompleteBadge" style="display:none;">
                                <i class="bi bi-check-circle-fill me-1"></i> Vaccination Complete!
                            </span>
                        </div>
                    </div>
                </div>

                <div id="scheduledDosesDisplay" style="display:none;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold text-success mb-0"><i class="bi bi-calendar-check me-2"></i>Scheduled Vaccination Doses</h6>
                        <span class="badge bg-success rounded-pill px-3 py-2" id="doseCountBadge">0 doses</span>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-sm table-hover" id="scheduledDosesTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Dose #</th><th>Vaccine</th><th>Unit</th><th>Scheduled Date</th>
                                    <th>Administered Date</th><th>Status</th><th>Remarks</th><th>Administered By</th>
                                </tr>
                            </thead>
                            <tbody id="scheduledDosesBody">
                                <tr><td colspan="8" class="text-center text-muted py-3"><i class="bi bi-inbox me-2"></i> No scheduled doses found for this case.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="mt-3 p-3 bg-light rounded">
                        <div class="row">
                            <div class="col-md-3 col-6"><div class="text-center"><div class="h5 mb-0" id="totalDoses">0</div><small class="text-muted">Total Doses</small></div></div>
                            <div class="col-md-3 col-6"><div class="text-center"><div class="h5 mb-0 text-success" id="completedDoses">0</div><small class="text-muted">Completed</small></div></div>
                            <div class="col-md-3 col-6"><div class="text-center"><div class="h5 mb-0 text-warning" id="pendingDoses">0</div><small class="text-muted">Pending</small></div></div>
                            <div class="col-md-3 col-6"><div class="text-center"><div class="h5 mb-0 text-danger" id="missedDoses">0</div><small class="text-muted">Missed</small></div></div>
                        </div>
                    </div>
                </div>

                <hr>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-primary mb-0"><i class="bi bi-list-check me-2"></i>Vaccines to Administer</h6>
                    <button type="button" class="btn btn-success btn-sm" onclick="addVaccineEntry()"><i class="bi bi-plus-circle me-1"></i> Add Vaccine</button>
                </div>

                <div id="vaccineEntries"></div>

                <div class="mt-3 text-muted small"><i class="bi bi-info-circle me-1"></i> You can administer multiple vaccines. Select the correct Dose # for each vaccine (e.g., both ERIG and Rabies Vaccine can be set to Dose # 1).</div>

                <div class="mt-4">
                    <button type="button" class="btn btn-primary btn-lg px-5" onclick="submitVaccination()" id="submitVaccinationBtn"><i class="bi bi-check-circle me-2"></i> Save Vaccination</button>
                    <button type="button" class="btn btn-secondary btn-lg px-4 ms-2" onclick="resetForm()"><i class="bi bi-arrow-counterclockwise me-2"></i> Reset</button>
                </div>
            </div>

            <div id="noPatientSelected" class="text-center py-4">
                <i class="bi bi-person-plus" style="font-size: 48px; color: #d0d7e8;"></i>
                <p class="text-muted mt-3">Select a patient from the dropdown above to start administering vaccines.</p>
            </div>
        </div>


            </div>

            <!-- PATIENT LIST TAB -->
            <div
                class="tab-pane fade <?php echo $active_tab === 'patients' ? 'show active' : ''; ?>"
                id="patients-pane"
                role="tabpanel"
                aria-labelledby="patients-tab"
                tabindex="0"
            >
                <div class="patients-tab-card">

                    <div class="patients-tab-header">
                        <div>
                            <h5>
                                <i class="bi bi-people me-2"></i>
                                Patients for Vaccination
                            </h5>
                            <div class="text-muted small mt-1">
                                Only patients with an active case and unfinished vaccination dose stages are shown.
                            </div>
                        </div>

                        <span class="badge bg-primary rounded-pill px-3 py-2">
                            <?php echo (int)$total_rows; ?>
                            eligible patient<?php echo ((int)$total_rows === 1) ? '' : 's'; ?>
                        </span>
                    </div>

                    <form method="GET" action="Nurse_Vaccination.php">
                        <input type="hidden" name="tab" value="patients">

                        <div class="search-wrap">
                            <i class="bi bi-search"></i>
                            <input
                                type="text"
                                name="search"
                                placeholder="Search patients..."
                                value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                            >
                        </div>
                    </form>

                    <div class="table-wrap">
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Patient Name</th>
                                        <th>Last Bite / Visit</th>
                                        <th>Case</th>
                                        <th>Status</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if (!empty($patient_list)): ?>

                                        <?php foreach ($patient_list as $patient): ?>
                                            <tr>
                                                <td>
                                                    <strong>
                                                        P<?php echo str_pad((string)$patient['patient_id'], 4, '0', STR_PAD_LEFT); ?>
                                                    </strong>
                                                </td>

                                                <td>
                                                    <?php echo htmlspecialchars($patient['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </td>

                                                <td>
                                                    <?php
                                                    echo !empty($patient['date_of_bite'])
                                                        ? date('M d, Y', strtotime($patient['date_of_bite']))
                                                        : 'N/A';
                                                    ?>
                                                </td>

                                                <td>
                                                    <?php if (!empty($patient['case_id'])): ?>
                                                        <?php
                                                        $caseLabel = !empty($patient['case_number'])
                                                            ? $patient['case_number']
                                                            : 'Case #' . $patient['case_id'];
                                                        ?>
                                                        <?php echo htmlspecialchars($caseLabel, ENT_QUOTES, 'UTF-8'); ?>
                                                    <?php else: ?>
                                                        N/A
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <span class="<?php echo getStatusBadge($patient['case_status'] ?? ''); ?>">
                                                        <?php echo htmlspecialchars($patient['case_status'] ?: 'Ongoing', ENT_QUOTES, 'UTF-8'); ?>
                                                    </span>
                                                </td>

                                                <td class="text-center">
                                                    <button
                                                        type="button"
                                                        class="action-icon-btn"
                                                        onclick="selectPatientFromTable(<?php echo (int)$patient['patient_id']; ?>)"
                                                        title="Open patient in Administer Vaccination"
                                                    >
                                                        <i class="bi bi-syringe"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>

                                    <?php else: ?>

                                        <tr>
                                            <td colspan="6" class="text-center py-5 text-muted">
                                                <i class="bi bi-inbox fs-2 d-block mb-2"></i>

                                                <?php if ($search !== ''): ?>
                                                    No eligible patients matched your search.
                                                <?php else: ?>
                                                    No patients currently require vaccination.
                                                <?php endif; ?>
                                            </td>
                                        </tr>

                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-wrap">
                            <nav aria-label="Patient list pagination">
                                <ul class="pagination mb-0">

                                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                        <a
                                            class="page-link"
                                            href="?tab=patients&page=<?php echo max(1, $page - 1); ?>&search=<?php echo urlencode($search); ?>"
                                            aria-label="Previous"
                                        >
                                            <i class="bi bi-chevron-left"></i>
                                        </a>
                                    </li>

                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                                            <a
                                                class="page-link"
                                                href="?tab=patients&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>"
                                            >
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>

                                    <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                        <a
                                            class="page-link"
                                            href="?tab=patients&page=<?php echo min($total_pages, $page + 1); ?>&search=<?php echo urlencode($search); ?>"
                                            aria-label="Next"
                                        >
                                            <i class="bi bi-chevron-right"></i>
                                        </a>
                                    </li>

                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>

                    <div class="pagination-info">
                        <?php if ($total_rows > 0): ?>
                            Showing
                            <?php echo $offset + 1; ?>
                            -
                            <?php echo min($offset + $limit, $total_rows); ?>
                            of
                            <?php echo $total_rows; ?>
                            eligible patients
                        <?php else: ?>
                            0 eligible patients
                        <?php endif; ?>
                    </div>

                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let availableVaccines = [];
let doseCounter = 0;
let currentPatientId = null;
let currentCaseId = null;
let nextDoseNumber = 1;
let isVaccinationComplete = false;
const csrfToken = <?php echo json_encode($_SESSION['csrf_token']); ?>;

const DOSE_MAP = { 1: 'D0', 2: 'D3', 3: 'D7', 4: 'D14', 5: 'D21', 6: 'D28/30' };

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function getDoseLabel(d) { return DOSE_MAP[d] || 'D' + d; }

document.addEventListener('DOMContentLoaded', function() { loadVaccines(); });

function loadVaccines() {
    fetch('Nurse_Vaccination.php?ajax=get_vaccines')
        .then(r => r.json()).then(data => { availableVaccines = data; })
        .catch(e => console.error('Error loading vaccines:', e));
}

// Keep the selected tab in the URL without reloading the page.
// Search and pagination explicitly use tab=patients.
document.querySelectorAll('#vaccinationModuleTabs [data-bs-toggle="tab"]').forEach(function(tabButton) {
    tabButton.addEventListener('shown.bs.tab', function(event) {
        const tabName = event.target.getAttribute('data-tab-name') || 'vaccination';
        const url = new URL(window.location.href);

        url.searchParams.set('tab', tabName);

        if (tabName === 'vaccination') {
            url.searchParams.delete('page');
            url.searchParams.delete('search');
        }

        window.history.replaceState({}, '', url);
    });
});

function selectPatientFromTable(pid) {
    const vaccinationTabButton = document.getElementById('vaccination-tab');

    if (vaccinationTabButton) {
        bootstrap.Tab.getOrCreateInstance(vaccinationTabButton).show();
    }

    const patientSelect = document.getElementById('patientSelect');

    if (!patientSelect) {
        return;
    }

    patientSelect.value = String(pid);

    if (patientSelect.value !== String(pid)) {
        showAlert('This patient is no longer eligible for vaccination. Please refresh the page.', 'warning');
        return;
    }

    loadPatientData();

    setTimeout(function() {
        const section = document.getElementById('vaccinationSection');
        if (section) {
            section.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    }, 120);
}


function loadPatientData() {
    var pid = document.getElementById('patientSelect').value;
    if (!pid) {
        document.getElementById('noPatientSelected').style.display = 'block';
        document.getElementById('patientInfoDisplay').style.display = 'none';
        document.getElementById('scheduledDosesDisplay').style.display = 'none';
        document.getElementById('nextDoseIndicator').style.display = 'none';
        document.getElementById('selectedPatientBadge').textContent = 'No patient selected';
        return;
    }
    
    currentPatientId = pid;
    document.getElementById('noPatientSelected').style.display = 'none';
    document.getElementById('patientInfoDisplay').style.display = 'block';
    document.getElementById('selectedPatientBadge').textContent = 'Loading...';
    document.getElementById('patientInfoGrid').innerHTML = `<div class="text-center py-3"><div class="spinner-border text-primary"></div></div>`;
    document.getElementById('scheduledDosesDisplay').style.display = 'none';
    document.getElementById('nextDoseIndicator').style.display = 'none';
    
    fetch('Nurse_Vaccination.php?ajax=get_patient_cases&patient_id=' + pid)
        .then(r => r.json()).then(data => {
            if (data.success && data.data.length > 0) {
                var p = data.data[0];
                document.getElementById('selectedPatientBadge').textContent = p.full_name;
                document.getElementById('patientInfoGrid').innerHTML = `
                    <div class="patient-info-item"><label>Full Name</label><div class="value">${escapeHtml(p.full_name)}</div></div>
                    <div class="patient-info-item"><label>Contact</label><div class="value">${escapeHtml(p.contact_number || 'N/A')}</div></div>
                    <div class="patient-info-item"><label>Gender</label><div class="value">${escapeHtml(p.gender || 'N/A')}</div></div>
                    <div class="patient-info-item"><label>Birthday</label><div class="value">${p.birthday ? new Date(p.birthday).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'}) : 'N/A'}</div></div>
                `;
                
                var cs = document.getElementById('caseSelect');
                cs.innerHTML = '<option value="">-- Select a case --</option>';
                var hasCases = false;
                data.data.forEach(function(row) {
                    if (row.case_id) {
                        hasCases = true;
                        cs.innerHTML += `<option value="${row.case_id}">Case #${row.case_id} - ${row.animal_type || 'Unknown'} (${row.case_status || 'N/A'})</option>`;
                    }
                });
                if (!hasCases) cs.innerHTML += '<option value="">No active cases found</option>';
                if (hasCases && cs.options.length === 2) { cs.value = cs.options[1].value; onCaseChange(); }
                
                document.getElementById('vaccineEntries').innerHTML = '';
                doseCounter = 0;
                addVaccineEntry(true);
            } else {
                document.getElementById('selectedPatientBadge').textContent = 'Patient not found';
                document.getElementById('patientInfoGrid').innerHTML = `<div class="alert alert-warning">No patient data found.</div>`;
            }
        }).catch(e => console.error('Error:', e));
}

function onCaseChange() {
    var cid = document.getElementById('caseSelect').value;
    currentCaseId = cid;
    if (cid && currentPatientId) {
        loadScheduledDoses(cid);
        loadNextDose(currentPatientId, cid);
    } else {
        document.getElementById('scheduledDosesDisplay').style.display = 'none';
        document.getElementById('nextDoseIndicator').style.display = 'none';
    }
}

function loadNextDose(pid, cid) {
    if (!pid || !cid) return;
    fetch(`Nurse_Vaccination.php?ajax=get_next_dose&patient_id=${pid}&case_id=${cid}`)
        .then(r => r.json()).then(data => {
            if (data.success) {
                nextDoseNumber = data.next_dose;
                isVaccinationComplete = data.is_complete || false;
                updateNextDoseIndicator();
                var firstEntry = document.querySelector('.vaccine-entry');
                if (firstEntry && !isVaccinationComplete) {
                    var doseInput = firstEntry.querySelector('.dose-number');
                    if (doseInput) { doseInput.value = nextDoseNumber; }
                }
            }
        }).catch(e => console.error('Error loading next dose:', e));
}

function updateNextDoseIndicator() {
    var indicator = document.getElementById('nextDoseIndicator');
    var label = document.getElementById('nextDoseLabel');
    var info = document.getElementById('nextDoseInfo');
    var suggestion = document.getElementById('doseSuggestionText');
    var completeBadge = document.getElementById('vaccinationCompleteBadge');
    
    if (isVaccinationComplete) {
        indicator.style.display = 'block';
        label.textContent = '✅ Complete';
        label.style.color = '#2e7d32';
        info.textContent = 'All doses have been administered';
        suggestion.textContent = 'No more doses needed';
        completeBadge.style.display = 'inline-block';
        document.getElementById('doseSuggestion').style.display = 'none';
    } else {
        indicator.style.display = 'block';
        label.textContent = getDoseLabel(nextDoseNumber);
        label.style.color = '#2e7d32';
        info.textContent = 'Next dose to administer';
        suggestion.textContent = 'Dose ' + getDoseLabel(nextDoseNumber) + ' is the next scheduled dose';
        completeBadge.style.display = 'none';
        document.getElementById('doseSuggestion').style.display = 'inline-flex';
    }
}

function loadScheduledDoses(cid) {
    if (!cid) return;
    document.getElementById('scheduledDosesDisplay').style.display = 'block';
    document.getElementById('scheduledDosesBody').innerHTML = `<tr><td colspan="8" class="text-center text-muted py-3"><div class="spinner-border spinner-border-sm text-primary"></div> Loading scheduled doses...</td></tr>`;
    
    fetch(`Nurse_Vaccination.php?ajax=get_scheduled_doses&case_id=${cid}&patient_id=${currentPatientId}`)
        .then(r => r.json()).then(data => {
            if (data.success && data.data.length > 0) {
                renderScheduledDoses(data.data);
                if (data.next_dose) { nextDoseNumber = data.next_dose; isVaccinationComplete = data.is_complete || false; updateNextDoseIndicator(); }
            } else {
                document.getElementById('scheduledDosesBody').innerHTML = `<tr><td colspan="8" class="text-center text-muted py-3"><i class="bi bi-inbox me-2"></i> No scheduled doses found.</td></tr>`;
                updateDoseSummary([]);
            }
        }).catch(e => {
            console.error('Error:', e);
            document.getElementById('scheduledDosesBody').innerHTML = `<tr><td colspan="8" class="text-center text-danger py-3">Error loading scheduled doses.</td></tr>`;
        });
}

// FIXED: Groups multiple vaccines under the same dose label (D0)
function renderScheduledDoses(doses) {
    var tbody = document.getElementById('scheduledDosesBody');
    var html = '';

    // Group products by the vaccination stage. A stage may contain several
    // different products, but still counts as one dose stage.
    var grouped = {};
    doses.forEach(function(d) {
        var doseNumber = parseInt(d.dose_number || 0, 10);
        var key = doseNumber >= 1 && doseNumber <= 6
            ? String(doseNumber)
            : String(d.dose_label || '0');

        if (!grouped[key]) grouped[key] = [];
        grouped[key].push(d);
    });

    var sortedKeys = Object.keys(grouped).sort((a, b) => parseInt(a, 10) - parseInt(b, 10));

    var completedStages = 0;
    var pendingStages = 0;
    var missedStages = 0;

    sortedKeys.forEach(function(key) {
        var dosesInGroup = grouped[key];
        var doseNumber = parseInt(key, 10) || parseInt(dosesInGroup[0]?.dose_number || 0, 10);
        var doseLabel = getDoseLabel(doseNumber);

        var statuses = dosesInGroup.map(d => (d.vaccination_status || 'Scheduled'));
        if (statuses.some(s => s === 'Completed')) {
            completedStages++;
        } else if (statuses.some(s => s === 'Scheduled')) {
            pendingStages++;
        } else if (statuses.some(s => s === 'Missed')) {
            missedStages++;
        }

        var vaccineList = dosesInGroup.map(function(d) {
            var vName = d.vaccine_name || d.inventory_item_name || 'Unknown';
            var uName = d.unit_name || 'N/A';
            var status = d.vaccination_status || 'Scheduled';
            var statusIcon = status === 'Completed'
                ? '<i class="bi bi-check-circle-fill text-success"></i>'
                : status === 'Scheduled'
                    ? '<i class="bi bi-clock-fill text-warning"></i>'
                    : status === 'Missed'
                        ? '<i class="bi bi-x-circle-fill text-danger"></i>'
                        : '<i class="bi bi-dash-circle"></i>';
            var admBy = d.administered_by_name || '—';
            var schedDate = d.scheduled_date ? new Date(d.scheduled_date).toLocaleDateString() : 'N/A';
            var admDate = d.date_administered ? new Date(d.date_administered).toLocaleDateString() : '—';
            var remarks = d.remarks || '—';

            return `<div style="border-bottom:1px solid #eee; padding:7px 0;">
                        <strong>${escapeHtml(vName)}</strong> (${escapeHtml(uName)})<br>
                        <small style="color:#666">Scheduled: ${escapeHtml(schedDate)} | Administered: ${escapeHtml(admDate)} | By: ${escapeHtml(admBy)}</small><br>
                        ${statusIcon} ${escapeHtml(status)}
                        <small class="d-block text-muted mt-1">${escapeHtml(remarks)}</small>
                    </div>`;
        }).join('');

        html += `<tr>
                    <td><strong>${escapeHtml(doseLabel)}</strong><br><small class="text-muted">Dose ${doseNumber}</small></td>
                    <td colspan="7">${vaccineList}</td>
                </tr>`;
    });

    tbody.innerHTML = html;
    document.getElementById('doseCountBadge').textContent = sortedKeys.length + ' dose stage' + (sortedKeys.length !== 1 ? 's' : '');
    document.getElementById('totalDoses').textContent = sortedKeys.length;
    document.getElementById('completedDoses').textContent = completedStages;
    document.getElementById('pendingDoses').textContent = pendingStages;
    document.getElementById('missedDoses').textContent = missedStages;
}

function updateDoseSummary(doses) {
    var grouped = {};

    doses.forEach(function(d) {
        var doseNumber = parseInt(d.dose_number || 0, 10);
        if (doseNumber < 1 || doseNumber > 6) return;
        if (!grouped[doseNumber]) grouped[doseNumber] = [];
        grouped[doseNumber].push(d);
    });

    var keys = Object.keys(grouped);
    var completed = 0;
    var pending = 0;
    var missed = 0;

    keys.forEach(function(key) {
        var statuses = grouped[key].map(d => d.vaccination_status || d.status || 'Scheduled');
        if (statuses.some(s => s === 'Completed')) completed++;
        else if (statuses.some(s => s === 'Scheduled')) pending++;
        else if (statuses.some(s => s === 'Missed')) missed++;
    });

    document.getElementById('totalDoses').textContent = keys.length;
    document.getElementById('completedDoses').textContent = completed;
    document.getElementById('pendingDoses').textContent = pending;
    document.getElementById('missedDoses').textContent = missed;
    document.getElementById('doseCountBadge').textContent = keys.length + ' dose stage' + (keys.length !== 1 ? 's' : '');
}

// FIXED: Copies the Dose # from the first card when adding a second vaccine
function addVaccineEntry(autoSuggest = false) {
    var container = document.getElementById('vaccineEntries');
    if (!container) return;
    
    doseCounter++;
    var entryId = 'entry_' + doseCounter;
    
    var vaccineOptions = availableVaccines.map(v => 
        `<option value="${v.item_id}" data-unit-id="${v.unit_id}" data-unit-name="${v.unit_name}" data-stock="${v.quantity_available}">${v.item_name} (${v.unit_name}) - ${v.quantity_available} available</option>`
    ).join('');
    
    // Determine suggested dose:
    var suggestedDose = 1;
    if (autoSuggest) {
        suggestedDose = nextDoseNumber;
    } else {
        // If adding a new vaccine, copy the dose from the previous card to allow stacking (D0 + D0)
        var prevEntry = container.querySelector('.vaccine-entry:last-child .dose-number');
        if (prevEntry) suggestedDose = parseInt(prevEntry.value) || 1;
    }
    
    if (suggestedDose < 1) suggestedDose = 1;
    if (suggestedDose > 6) suggestedDose = 6;
    
    var entry = document.createElement('div');
    entry.className = 'vaccine-entry';
    entry.id = entryId;
    entry.innerHTML = `
        <button type="button" class="remove-btn" onclick="removeVaccineEntry('${entryId}')" title="Remove"><i class="bi bi-x-circle"></i></button>
        <div class="entry-number">Vaccine #${doseCounter}</div>
        <div class="row g-3">
            <div class="col-md-5"><label class="form-label fw-semibold">Vaccine <span class="text-danger">*</span></label><select class="form-select vaccine-select" onchange="updateVaccineUnit(this)" required><option value="">-- Select Vaccine --</option>${vaccineOptions}</select></div>
            <div class="col-md-2"><label class="form-label fw-semibold">Unit</label><input type="text" class="form-control unit-display" readonly value="Select vaccine"></div>
            <div class="col-md-2"><label class="form-label fw-semibold">Dose # <span class="text-danger">*</span></label><input type="number" class="form-control dose-number" min="1" max="6" value="${suggestedDose}" required><small class="text-muted dose-label-text">Dose ${getDoseLabel(suggestedDose)}</small></div>
            <div class="col-md-3"><label class="form-label fw-semibold">Quantity <span class="text-danger">*</span></label><input type="number" class="form-control quantity-input" min="1" value="1" required></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Date Administered</label><input type="date" class="form-control date-administered" value="${new Date().toISOString().split('T')[0]}"></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Status</label><select class="form-select status-select"><option value="Completed" selected>Completed</option><option value="Missed">Missed</option></select></div>
        </div>
        <input type="hidden" class="vaccine-item-id" value=""><input type="hidden" class="vaccine-unit-id" value="">
    `;
    
    container.appendChild(entry);
    
    if (availableVaccines.length > 0) {
        var select = entry.querySelector('.vaccine-select');
        if (select) { select.selectedIndex = 1; updateVaccineUnit(select); }
    }
}

function removeVaccineEntry(entryId) {
    var entry = document.getElementById(entryId);
    if (entry) {
        var container = document.getElementById('vaccineEntries');
        if (container.children.length > 1) entry.remove();
        else showAlert('At least one vaccine entry is required.', 'warning');
    }
}

function updateVaccineUnit(select) {
    var entry = select.closest('.vaccine-entry');
    if (!entry) return;
    var opt = select.options[select.selectedIndex];
    entry.querySelector('.unit-display').value = opt.getAttribute('data-unit-name') || 'Unknown';
    entry.querySelector('.vaccine-item-id').value = opt.value;
    entry.querySelector('.vaccine-unit-id').value = opt.getAttribute('data-unit-id') || '';
}

function showAlert(message, type = 'success') {
    var container = document.getElementById('alertContainer');
    var alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show alert-toast`;
    alertDiv.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle-fill' : type === 'danger' ? 'exclamation-circle-fill' : 'info-circle-fill'} me-2"></i> ${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
    container.appendChild(alertDiv);
    setTimeout(() => { var bsAlert = bootstrap.Alert.getInstance(alertDiv); if (bsAlert) bsAlert.close(); }, 5000);
}

function submitVaccination() {
    var pid = document.getElementById('patientSelect').value;
    var cid = document.getElementById('caseSelect').value;
    
    if (!pid) { showAlert('Please select a patient.', 'warning'); return; }
    if (!cid) { showAlert('Please select a case.', 'warning'); document.getElementById('caseSelect').focus(); return; }
    
    var entries = document.querySelectorAll('.vaccine-entry');
    var vaccineItems = [];
    var hasError = false;
    
    entries.forEach(entry => {
        var vSelect = entry.querySelector('.vaccine-select');
        var dNum = entry.querySelector('.dose-number');
        var qty = entry.querySelector('.quantity-input');
        var dAdmin = entry.querySelector('.date-administered');
        var statusSel = entry.querySelector('.status-select');
        var itemId = entry.querySelector('.vaccine-item-id');
        var unitId = entry.querySelector('.vaccine-unit-id');
        
        if (!vSelect || !vSelect.value) { showAlert('Please select a vaccine for all entries.', 'warning'); hasError = true; return; }
        if (!dNum || !dNum.value || parseInt(dNum.value) < 1 || parseInt(dNum.value) > 6) { showAlert('Please enter a valid dose number (1-6).', 'warning'); hasError = true; return; }
        if (!qty || !qty.value || parseInt(qty.value) < 1) { showAlert('Please enter a valid quantity.', 'warning'); hasError = true; return; }
        
        vaccineItems.push({
            item_id: itemId.value,
            unit_id: unitId.value,
            dose_number: dNum.value,
            quantity: qty.value,
            date_administered: dAdmin.value || new Date().toISOString().split('T')[0],
            vaccine_status: statusSel.value || 'Completed'
        });
    });
    
    if (hasError || vaccineItems.length === 0) {
        if (vaccineItems.length === 0) showAlert('Please add at least one vaccine.', 'warning');
        return;
    }
    
    var btn = document.getElementById('submitVaccinationBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...'; }
    
    var formData = new FormData();
    formData.append('submit_vaccination', '1');
    formData.append('csrf_token', csrfToken);
    formData.append('patient_id', pid);
    formData.append('case_id', cid);
    formData.append('vaccine_items', JSON.stringify(vaccineItems));
    
    fetch('Nurse_Vaccination.php', { method: 'POST', body: formData })
        .then(r => r.json()).then(data => {
            if (data.success) {
                showAlert(data.message, 'success');
                setTimeout(() => { 
                    resetForm(); 
                    // Reload so a vaccination-complete patient is immediately
                    // removed from the Select Patient dropdown.
                    location.reload();
                }, 1500);
            } else showAlert(data.message || 'Error saving vaccination records.', 'danger');
        }).catch(e => { showAlert('Error saving vaccination records. Please try again.', 'danger'); console.error(e); })
        .finally(() => { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-circle me-2"></i> Save Vaccination'; } });
}

function resetForm() {
    document.getElementById('patientSelect').value = '';
    document.getElementById('caseSelect').innerHTML = '<option value="">-- Select a case --</option>';
    document.getElementById('vaccineEntries').innerHTML = '';
    document.getElementById('patientInfoDisplay').style.display = 'none';
    document.getElementById('scheduledDosesDisplay').style.display = 'none';
    document.getElementById('nextDoseIndicator').style.display = 'none';
    document.getElementById('noPatientSelected').style.display = 'block';
    document.getElementById('selectedPatientBadge').textContent = 'No patient selected';
    doseCounter = 0; currentPatientId = null; currentCaseId = null; nextDoseNumber = 1; isVaccinationComplete = false;
}
</script>
</body>
</html>
