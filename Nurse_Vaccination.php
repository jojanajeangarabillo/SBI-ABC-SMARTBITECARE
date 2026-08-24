<?php
session_start();

// TURN OFF display_errors globally to prevent breaking JSON
error_reporting(E_ALL);
ini_set('display_errors', 0); 

require_once 'sources/db_connect.php';

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
            AND u.role_id IN (1, 2)  
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

// Function to get the next dose number for a patient
function getNextDoseNumber($conn, $patient_id, $case_id) {
    $sql = "SELECT MAX(dose_number) as max_dose 
            FROM vaccination_records 
            WHERE patient_id = ? 
            AND case_id = ? 
            AND is_archived = 0 
            AND vaccination_status IN ('Completed', 'Scheduled')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $patient_id, $case_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    $max_dose = $row['max_dose'] ?? 0;
    $next_dose = $max_dose + 1;
    if ($next_dose > 6) {
        $next_dose = 6;
    }
    return $next_dose;
}

// Handle AJAX request for getting available vaccines
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_vaccines') {
    header('Content-Type: application/json');
    
    $sql = "SELECT i.item_id, i.item_name, i.unit_id, u.unit_name, s.quantity_available 
            FROM inventory_items i
            JOIN units u ON i.unit_id = u.unit_id
            JOIN inventory_stocks s ON i.item_id = s.item_id
            WHERE s.branch_id = ? 
            AND s.quantity_available > 0
            AND i.category_id = 2
            AND i.item_name NOT LIKE '%Default%'
            ORDER BY i.item_name";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $branch_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $vaccines = $result->fetch_all(MYSQLI_ASSOC);
    
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
            LEFT JOIN animal_bite_cases a ON p.patient_id = a.patient_id 
                AND a.is_archived = 0
                AND a.case_status != 'Completed' 
            LEFT JOIN registry_records r ON a.case_id = r.case_id 
                AND r.is_archived = 0
            WHERE p.patient_id = ? AND p.is_archived = 0
            ORDER BY a.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $patient_id);
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
                vr.vaccination_status,
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
            AND vr.branch_id = ?";
    
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
                        'is_final_dose' => ($num >= 5) ? 1 : 0,
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
    
    $isComplete = false;
    $totalDoses = count($doses);
    $completedDoses = 0;
    foreach ($doses as $d) {
        if (($d['vaccination_status'] ?? '') === 'Completed') {
            $completedDoses++;
        }
    }
    if ($totalDoses >= 6 && $completedDoses >= 6) {
        $isComplete = true;
    }
    
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
    
    $checkSql = "SELECT COUNT(*) as total, 
                        SUM(CASE WHEN vaccination_status = 'Completed' THEN 1 ELSE 0 END) as completed
                 FROM vaccination_records 
                 WHERE patient_id = ? AND case_id = ? AND is_archived = 0";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("ii", $patient_id, $case_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $checkData = $checkResult->fetch_assoc();
    
    $isComplete = false;
    if ($checkData['total'] >= 6 && $checkData['completed'] >= 6) {
        $isComplete = true;
    }
    
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
    
    $patient_id = isset($_POST['patient_id']) ? intval($_POST['patient_id']) : 0;
    $case_id = isset($_POST['case_id']) ? intval($_POST['case_id']) : 0;
    $vaccine_items = isset($_POST['vaccine_items']) ? json_decode($_POST['vaccine_items'], true) : [];
    
    if ($patient_id <= 0 || $case_id <= 0 || empty($vaccine_items)) {
        echo json_encode(['success' => false, 'message' => 'Invalid data provided']);
        exit;
    }
    
    $conn->begin_transaction();
    
    try {
        $success_count = 0;
        $vaccination_details = [];
        $patient_name = '';
        
        $patientSql = "SELECT full_name FROM patients WHERE patient_id = ?";
        $patientStmt = $conn->prepare($patientSql);
        $patientStmt->bind_param("i", $patient_id);
        $patientStmt->execute();
        $patientResult = $patientStmt->get_result();
        if ($patientData = $patientResult->fetch_assoc()) {
            $patient_name = $patientData['full_name'];
        }
        
        foreach ($vaccine_items as $item) {
            $item_id            = intval($item['item_id']);
            $unit_id            = intval($item['unit_id']);
            $dose_number        = intval($item['dose_number']);
            $quantity           = intval($item['quantity']);
            $date_administered  = !empty($item['date_administered'])
                                    ? $item['date_administered']
                                    : date('Y-m-d');
            $vaccination_status = !empty($item['vaccine_status'])
                                    ? $item['vaccine_status']
                                    : 'Completed';
            $remarks            = !empty($item['remarks'])
                                    ? trim($item['remarks'])
                                    : '';
            
            if ($dose_number < 1 || $dose_number > 6) {
                throw new Exception("Invalid dose number: {$dose_number}. Dose must be between 1 and 6.");
            }
            
            $sql = "SELECT i.item_name, s.quantity_available 
                    FROM inventory_items i
                    INNER JOIN inventory_stocks s ON i.item_id = s.item_id
                    WHERE i.item_id = ? AND s.branch_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("is", $item_id, $branch_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows == 0) {
                throw new Exception("Selected vaccine not found.");
            }
            $vaccine = $result->fetch_assoc();
            $vaccine_name = $vaccine['item_name'];
            $current_stock = intval($vaccine['quantity_available']);
            
            if ($quantity > $current_stock) {
                throw new Exception("{$vaccine_name} only has {$current_stock} stock available.");
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
                    vaccination_status,
                    is_final_dose,
                    remarks,
                    nurse_id
                )
                VALUES
                (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?
                )
            ";
            $is_final_dose = ($dose_number >= 5) ? 1 : 0;
            
            $stmt = $conn->prepare($insertVaccination);
            $stmt->bind_param(
                "iiisssissssi", 
                $patient_id,
                $case_id,
                $item_id,
                $vaccine_name,
                $unit_id,
                $branch_id,
                $dose_number,
                $date_administered,
                $vaccination_status,
                $is_final_dose,
                $remarks,
                $user_id
            );
            if (!$stmt->execute()) {
                throw new Exception("Failed to save vaccination record: " . $conn->error);
            }
            $vaccination_id = $conn->insert_id;
            
            $updateStock = "UPDATE inventory_stocks 
                            SET quantity_available = quantity_available - ? 
                            WHERE item_id = ? AND branch_id = ?";
            $stmt = $conn->prepare($updateStock);
            $stmt->bind_param("iis", $quantity, $item_id, $branch_id);
            if (!$stmt->execute()) {
                throw new Exception("Failed to update stock.");
            }
            
            $transactionRemarks = "Vaccination | Patient ID: {$patient_id}" .
                                  " | Case ID: {$case_id}" .
                                  " | Vaccine: {$vaccine_name}" .
                                  " | Dose #: {$dose_number}" .
                                  " | Qty Used: {$quantity}" .
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
                    remarks
                )
                VALUES (?, ?, ?, ?, 'OUT', ?, ?)
            ";
            $stmt = $conn->prepare($insertTransaction);
            $stmt->bind_param("iiisis", $item_id, $user_id, $vaccination_id, $branch_id, $quantity, $transactionRemarks);
            if (!$stmt->execute()) {
                throw new Exception("Failed to save stock transaction.");
            }
            
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
                throw new Exception("Failed to save usage history.");
            }
            
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
            $updateReg->bind_param("iiiiiiii", 
                $dose_number, $dose_number, $dose_number, $dose_number, $dose_number, $dose_number,
                $user_id, $case_id
            );
            $updateReg->execute();
            
            $is_final_dose = ($dose_number >= 5) ? 1 : 0;
            if ($is_final_dose == 1) {
                $checkAllDoses = $conn->prepare("
                    SELECT COUNT(*) as total, 
                           SUM(CASE WHEN vaccination_status = 'Completed' THEN 1 ELSE 0 END) as completed
                    FROM vaccination_records 
                    WHERE case_id = ? AND is_archived = 0
                ");
                $checkAllDoses->bind_param("i", $case_id);
                $checkAllDoses->execute();
                $doseResult = $checkAllDoses->get_result();
                $doseData = $doseResult->fetch_assoc();
                
                // Check if at least 6 completed doses for the case
                if ($doseData['total'] >= 6 && $doseData['completed'] >= 6) {
                    $updateCase = "UPDATE animal_bite_cases SET case_status = 'Completed' WHERE case_id = ?";
                    $stmt = $conn->prepare($updateCase);
                    $stmt->bind_param("i", $case_id);
                    $stmt->execute();
                }
            }
            
            $auditAction = "Vaccination Administered - " .
                           $vaccine_name .
                           " Dose #" . $dose_number .
                           " (" . getDoseLabel($dose_number) . ")" .
                           " | Patient: {$patient_name} (ID: {$patient_id})" .
                           " | Case ID: {$case_id}" .
                           " | Quantity: {$quantity}" .
                           " | Status: {$vaccination_status}" .
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
            
            $notification_title = "New Vaccination Administered";
            $notification_message = 
                "A vaccination has been administered by Nurse {$username} at {$branch_name}.\n\n" .
                "Patient: {$patient_name} (ID: P" . str_pad($patient_id, 4, '0', STR_PAD_LEFT) . ")\n" .
                "Case ID: C" . str_pad($case_id, 4, '0', STR_PAD_LEFT) . "\n" .
                "Date: " . date('F d, Y H:i:s') . "\n\n" .
                "Vaccines Administered:\n" .
                $doseList .
                "\nTotal Doses: " . count($vaccination_details);
            
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
                "Vaccination Submitted Successfully",
                "You have successfully administered vaccination to {$patient_name} at {$branch_name}.\n" .
                "Total vaccines administered: " . count($vaccination_details) . "\n" .
                "Date: " . date('F d, Y H:i:s'),
                'vaccination'
            );
        }
        
        $conn->commit();
        echo json_encode([
            'success' => true,
            'message' => "Successfully administered " . $success_count . " vaccine(s). Notifications sent to administrative staff."
        ]);
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Get patient search and pagination
$search = isset($_GET['search']) ? $_GET['search'] : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Only get patients with ONGOING cases
$count_sql = "SELECT COUNT(DISTINCT p.patient_id) as total 
              FROM patients p 
              INNER JOIN animal_bite_cases a ON p.patient_id = a.patient_id 
              WHERE p.branch_id = ? AND p.is_archived = 0 
              AND a.is_archived = 0 AND a.case_status != 'Completed'";
if (!empty($search)) {
    $count_sql .= " AND (p.full_name LIKE ? OR p.email LIKE ? OR p.contact_number LIKE ?)";
}

$count_stmt = $conn->prepare($count_sql);
if (!empty($search)) {
    $search_param = "%" . $search . "%";
    $count_stmt->bind_param("ssss", $branch_id, $search_param, $search_param, $search_param);
} else {
    $count_stmt->bind_param("s", $branch_id);
}
$count_stmt->execute();
$total_result = $count_stmt->get_result();
$total_rows = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

$sql_patients = "SELECT p.*, a.case_id, a.case_status, a.date_of_bite, a.case_number,
                        r.registry_number
                 FROM patients p 
                 LEFT JOIN (
                     SELECT patient_id, case_id, case_status, date_of_bite, case_number,
                            ROW_NUMBER() OVER (PARTITION BY patient_id ORDER BY created_at DESC) as rn
                     FROM animal_bite_cases
                     WHERE is_archived = 0 AND case_status != 'Completed'
                 ) a ON p.patient_id = a.patient_id AND a.rn = 1
                 LEFT JOIN registry_records r ON a.case_id = r.case_id AND r.is_archived = 0
                 WHERE p.branch_id = ? AND p.is_archived = 0 AND a.patient_id IS NOT NULL";

if (!empty($search)) {
    $sql_patients .= " AND (p.full_name LIKE ? OR p.email LIKE ? OR p.contact_number LIKE ?)";
}

$sql_patients .= " ORDER BY p.patient_id DESC LIMIT ? OFFSET ?";

$stmt_patients = $conn->prepare($sql_patients);
if (!empty($search)) {
    $search_param = "%" . $search . "%";
    $stmt_patients->bind_param("ssssii", $branch_id, $search_param, $search_param, $search_param, $limit, $offset);
} else {
    $stmt_patients->bind_param("sii", $branch_id, $limit, $offset);
}
$stmt_patients->execute();
$patients = $stmt_patients->get_result()->fetch_all(MYSQLI_ASSOC);

function getStatusBadge($status) {
    $status = strtolower($status);
    $class = 'status-badge ';
    if ($status == 'ongoing' || $status == 'active' || $status == 'scheduled') {
        $class .= 'ongoing';
    } else {
        $class .= 'completed';
    }
    return $class;
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
        .profile { font-weight: 600; color: var(--primary); cursor: default; display: flex; align-items: center; gap: 6px; }
        .content { padding: 35px 35px 40px; }

        .page-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 28px; }
        .page-header h2 { font-size: 26px; font-weight: 700; color: var(--primary); margin: 0; }
        .page-header .badge-role { background: var(--primary); color: #fff; font-size: 14px; font-weight: 600; padding: 6px 16px; border-radius: 30px; letter-spacing: 0.3px; margin-left: 12px; }

        .search-wrap { position: relative; max-width: 420px; margin-bottom: 28px; }
        .search-wrap i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #7a85a8; font-size: 18px; }
        .search-wrap input { width: 100%; padding: 12px 12px 12px 44px; border: 1px solid #d0d7e8; border-radius: 10px; font-size: 15px; background: white; outline: none; transition: 0.15s; }
        .search-wrap input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.15); }

        .table-wrap { background: white; border-radius: 18px; box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05); overflow: hidden; padding: 0; margin-bottom: 20px; }
        .table { margin-bottom: 0; border-collapse: separate; border-spacing: 0; }
        .table thead th { background: var(--primary); color: white; font-weight: 700; font-size: 15px; padding: 16px 20px; border-bottom: 1px solid #e2e7f2; letter-spacing: 0.3px; }
        .table tbody td { padding: 16px 20px; vertical-align: middle; border-bottom: 1px solid #edf1f8; color: #1f2a4a; font-weight: 500; }
        .table tbody tr:last-child td { border-bottom: none; }
        .status-badge { display: inline-block; font-weight: 600; font-size: 13px; padding: 4px 16px; border-radius: 40px; letter-spacing: 0.2px; }
        .status-badge.ongoing { background: #fde8b0; color: #8a6d00; }
        .status-badge.completed { background: #d4f0d4; color: #1a6e1a; }
        .action-icon { font-size: 22px; color: var(--primary); cursor: pointer; opacity: 0.7; transition: 0.1s; text-decoration: none; padding: 0 4px; }
        .action-icon:hover { opacity: 1; }

        .pagination-wrap { display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 20px; }
        .pagination-wrap .page-link { color: var(--primary); border-radius: 8px; padding: 8px 16px; font-weight: 500; border: 1px solid #e2e7f2; }
        .pagination-wrap .page-link:hover { background: #f0f3fc; border-color: var(--primary); }
        .pagination-wrap .page-item.active .page-link { background: var(--primary); border-color: var(--primary); color: white; }
        .pagination-wrap .page-item.disabled .page-link { color: #b0b8c8; }
        .pagination-info { text-align: center; color: #7a85a8; font-size: 14px; margin-top: 12px; }

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
            .page-header h2 { font-size: 22px; }
            .table-wrap { overflow-x: auto; }
            .table thead th, .table tbody td { padding: 12px 14px; font-size: 14px; }
            .search-wrap { max-width: 100%; }
            .alert-toast { min-width: 90%; right: 5%; top: 10px; }
            .profile { font-size: 13px; }
            .vaccination-section { padding: 16px; }
            .patient-info-grid { grid-template-columns: 1fr; }
            #scheduledDosesTable { font-size: 12px; }
            #scheduledDosesTable th, #scheduledDosesTable td { padding: 8px 10px; }
            #scheduledDosesTable th { font-size: 10px; white-space: normal; }
            #scheduledDosesTable td { font-size: 11px; }
            .next-dose-indicator { flex-direction: column; align-items: stretch; text-align: center; }
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
        <div class="profile"><?php echo htmlspecialchars($username); ?></div>
    </div>

    <div class="content">
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

        <div class="page-header mt-4"><h2>Patient List</h2></div>

        <form method="GET" action="">
            <div class="search-wrap">
                <i class="bi bi-search"></i>
                <input type="text" name="search" placeholder="Search Patients" value="<?php echo htmlspecialchars($search); ?>" />
            </div>
        </form>

        <div class="table-wrap">
            <table class="table align-middle">
                <thead>
                    <tr><th>ID</th><th>Patient Name</th><th>Last Visit</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php if (count($patients) > 0): ?>
                        <?php foreach ($patients as $patient): ?>
                        <tr>
                            <td><strong>P<?php echo str_pad($patient['patient_id'], 4, '0', STR_PAD_LEFT); ?></strong></td>
                            <td><?php echo htmlspecialchars($patient['full_name']); ?></td>
                            <td><?php echo $patient['date_of_bite'] ? date('M d, Y', strtotime($patient['date_of_bite'])) : 'N/A'; ?></td>
                            <td><span class="<?php echo getStatusBadge($patient['case_status']); ?>"><?php echo $patient['case_status'] ?: 'N/A'; ?></span></td>
                            <td><a href="#" class="action-icon" onclick="selectPatientFromTable(<?php echo $patient['patient_id']; ?>)" title="Select Patient for Vaccination"><i class="bi bi-syringe"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center py-4">No patients found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination-wrap">
            <nav aria-label="Page navigation">
                <ul class="pagination">
                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>"><i class="bi bi-chevron-left"></i></a></li>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a></li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>"><i class="bi bi-chevron-right"></i></a></li>
                </ul>
            </nav>
        </div>
        <div class="pagination-info">Showing <?php echo ($offset + 1); ?> - <?php echo min($offset + $limit, $total_rows); ?> of <?php echo $total_rows; ?> patients</div>
        <?php endif; ?>
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

const DOSE_MAP = { 1: 'D0', 2: 'D3', 3: 'D7', 4: 'D14', 5: 'D21', 6: 'D28/30' };

function getDoseLabel(d) { return DOSE_MAP[d] || 'D' + d; }

document.addEventListener('DOMContentLoaded', function() { loadVaccines(); });

function loadVaccines() {
    fetch('Nurse_Vaccination.php?ajax=get_vaccines')
        .then(r => r.json()).then(data => { availableVaccines = data; })
        .catch(e => console.error('Error loading vaccines:', e));
}

function selectPatientFromTable(pid) {
    document.getElementById('patientSelect').value = pid;
    loadPatientData();
    document.getElementById('vaccinationSection').scrollIntoView({ behavior: 'smooth' });
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
                    <div class="patient-info-item"><label>Full Name</label><div class="value">${p.full_name}</div></div>
                    <div class="patient-info-item"><label>Contact</label><div class="value">${p.contact_number || 'N/A'}</div></div>
                    <div class="patient-info-item"><label>Gender</label><div class="value">${p.gender || 'N/A'}</div></div>
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
    var completedCount = 0, pendingCount = 0, missedCount = 0;
    
    // Group by dose_label
    var grouped = {};
    doses.forEach(function(d) {
        var key = d.dose_label || getDoseLabel(d.dose_number);
        if (!grouped[key]) grouped[key] = [];
        grouped[key].push(d);
    });

    // Sort keys naturally (D0, D3, D7...)
    var sortedKeys = Object.keys(grouped).sort((a, b) => {
        var numA = parseInt(a.replace(/\D/g, '')) || 0;
        var numB = parseInt(b.replace(/\D/g, '')) || 0;
        return numA - numB;
    });

    sortedKeys.forEach(function(doseLabel) {
        var dosesInGroup = grouped[doseLabel];
        // Count statuses for total summary
        dosesInGroup.forEach(function(d) {
            var status = d.vaccination_status || 'Scheduled';
            if (status === 'Completed') completedCount++; 
            else if (status === 'Scheduled') pendingCount++; 
            else if (status === 'Missed') missedCount++;
        });

        // Create a single row for the dose label, and list vaccines inside it
        var vaccineList = dosesInGroup.map(function(d) {
            var vName = d.vaccine_name || d.inventory_item_name || 'Unknown';
            var uName = d.unit_name || 'N/A';
            var status = d.vaccination_status || 'Scheduled';
            var statusIcon = status === 'Completed' ? '<i class="bi bi-check-circle-fill text-success"></i>' : status === 'Scheduled' ? '<i class="bi bi-clock-fill text-warning"></i>' : status === 'Missed' ? '<i class="bi bi-x-circle-fill text-danger"></i>' : '<i class="bi bi-dash-circle"></i>';
            var admBy = d.administered_by_name || d.administered_at || '—';
            var schedDate = d.scheduled_date ? new Date(d.scheduled_date).toLocaleDateString() : 'N/A';
            var admDate = d.date_administered ? new Date(d.date_administered).toLocaleDateString() : '—';
            
            return `<div style="border-bottom:1px solid #eee; padding:5px 0;">
                        <strong>${vName}</strong> (${uName}) <br>
                        <small style="color:#666">Sched: ${schedDate} | Admin: ${admDate} | By: ${admBy}</small> <br>
                        ${statusIcon} ${status}
                    </div>`;
        }).join('');

        html += `<tr>
                    <td><strong>${doseLabel}</strong></td>
                    <td colspan="7">${vaccineList}</td>
                </tr>`;
    });
    
    tbody.innerHTML = html;
    document.getElementById('doseCountBadge').textContent = doses.length + ' dose' + (doses.length !== 1 ? 's' : '');
    document.getElementById('totalDoses').textContent = doses.length;
    document.getElementById('completedDoses').textContent = completedCount;
    document.getElementById('pendingDoses').textContent = pendingCount;
    document.getElementById('missedDoses').textContent = missedCount;
}

function updateDoseSummary(doses) {
    var total = doses.length;
    var completed = doses.filter(d => (d.vaccination_status || d.status) === 'Completed').length;
    var pending = doses.filter(d => (d.vaccination_status || d.status) === 'Scheduled').length;
    var missed = doses.filter(d => (d.vaccination_status || d.status) === 'Missed').length;
    document.getElementById('totalDoses').textContent = total;
    document.getElementById('completedDoses').textContent = completed;
    document.getElementById('pendingDoses').textContent = pending;
    document.getElementById('missedDoses').textContent = missed;
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
            <div class="col-md-6"><label class="form-label fw-semibold">Status</label><select class="form-select status-select"><option value="Completed" selected>Completed</option><option value="Scheduled">Scheduled</option><option value="Missed">Missed</option></select></div>
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
    formData.append('patient_id', pid);
    formData.append('case_id', cid);
    formData.append('vaccine_items', JSON.stringify(vaccineItems));
    
    fetch('Nurse_Vaccination.php', { method: 'POST', body: formData })
        .then(r => r.json()).then(data => {
            if (data.success) {
                showAlert(data.message, 'success');
                setTimeout(() => { 
                    resetForm(); 
                    // Reload the page or re-fetch the patient list to exclude completed patients
                    if (currentPatientId) loadPatientData(); 
                    location.reload(); // Force refresh so the patient is filtered out of the list
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