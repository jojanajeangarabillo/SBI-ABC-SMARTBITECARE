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

if (empty($_SESSION['admin_patient_record_csrf'])) {
    $_SESSION['admin_patient_record_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['admin_patient_record_csrf'];

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

function caseNoExists(mysqli $conn, string $caseNo, string $branchId, ?int $excludeCaseId = null): bool {
    $sql = "SELECT case_id
            FROM animal_bite_cases
            WHERE case_number = ?
              AND branch_id = ?
              AND is_archived = 0";
    $params = [$caseNo, $branchId];
    $types = "ss";

    if ($excludeCaseId) {
        $sql .= " AND case_id != ?";
        $params[] = $excludeCaseId;
        $types .= "i";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists;
}

/**
 * Admin Staff creates schedule-only vaccination rows.
 * Product/item selection belongs to the Nurse during actual administration.
 * item_id, vaccine_name, unit_id and nurse_id remain NULL for Admin Staff schedules.
 */
function doseNumberToKey(int $doseNumber): ?string {
    $map = [
        1 => 'd0',
        2 => 'd3',
        3 => 'd7',
        4 => 'd14',
        5 => 'd21',
        6 => 'd28'
    ];
    return $map[$doseNumber] ?? null;
}

function doseKeyToNumber(string $doseKey): ?int {
    $map = [
        'd0' => 1,
        'd3' => 2,
        'd7' => 3,
        'd14' => 4,
        'd21' => 5,
        'd28' => 6
    ];
    return $map[$doseKey] ?? null;
}

function inferScheduleProfile(array $doseNumbers): array {
    return [
        'category' => 'Post-Exposure Prophylaxis (PEP)',
        'route' => 'Intradermal (ID)'
    ];
}

/**
 * Removes only schedule rows that belong to Admin Staff.
 * Nurse-entered Scheduled/Completed/Missed records are never deleted here.
 */
function archiveAdminScheduleRows(
    mysqli $conn,
    int $caseId,
    string $branchId,
    int $doseNumber,
    int $userId
): void {
    $stmt = $conn->prepare("
        UPDATE vaccination_records vr
        LEFT JOIN users owner_user
          ON owner_user.user_id = vr.nurse_id
        SET vr.is_archived = 1,
            vr.archived_at = NOW(),
            vr.archived_by = ?
        WHERE vr.case_id = ?
          AND vr.branch_id = ?
          AND vr.dose_number = ?
          AND vr.vaccination_status = 'Scheduled'
          AND vr.is_archived = 0
          AND (
                vr.nurse_id IS NULL
                OR owner_user.role_id = 4
              )
    ");
    if (!$stmt) {
        throw new Exception('Unable to prepare schedule update.');
    }

    $stmt->bind_param("iisi", $userId, $caseId, $branchId, $doseNumber);
    if (!$stmt->execute()) {
        throw new Exception('Unable to update the vaccination schedule.');
    }
    $stmt->close();
}

function loadDoseStageData(mysqli $conn, int $caseId, string $branchId): array {
    $stmt = $conn->prepare("
        SELECT
            vr.dose_number,
            vr.scheduled_date,
            vr.date_administered,
            vr.vaccination_status,
            vr.remarks,
            vr.nurse_id
        FROM vaccination_records vr
        WHERE vr.case_id = ?
          AND vr.branch_id = ?
          AND vr.is_archived = 0
          AND vr.dose_number BETWEEN 1 AND 6
        ORDER BY vr.dose_number ASC, vr.vaccination_id ASC
    ");
    if (!$stmt) {
        throw new Exception('Unable to load vaccination schedule.');
    }

    $stmt->bind_param("is", $caseId, $branchId);
    $stmt->execute();
    $result = $stmt->get_result();

    $stages = [];
    $doseNumbers = [];
    $scheduleRemarks = '';

    while ($row = $result->fetch_assoc()) {
        $doseNumber = (int)$row['dose_number'];
        $key = doseNumberToKey($doseNumber);
        if (!$key) continue;

        $doseNumbers[] = $doseNumber;

        if (!isset($stages[$key])) {
            $stages[$key] = [
                'scheduled_date' => '',
                'administered_date' => '',
                'status' => '',
                'locked' => false
            ];
        }

        $status = (string)($row['vaccination_status'] ?? 'Scheduled');
        $scheduled = dbToFrontDate($row['scheduled_date'] ?? null);
        $administered = dbToFrontDate($row['date_administered'] ?? null);

        // Actual completed administration always wins over schedule/missed rows.
        $isValidCompleted = (
            $status === 'Completed'
            && !empty($row['date_administered'])
            && $row['date_administered'] <= date('Y-m-d')
        );

        if ($isValidCompleted) {
            $stages[$key]['status'] = 'Administered';
            $stages[$key]['administered_date'] = $administered;
            $stages[$key]['locked'] = true;

            if ($scheduled !== '' && $stages[$key]['scheduled_date'] === '') {
                $stages[$key]['scheduled_date'] = $scheduled;
            }
        } elseif ($status === 'Scheduled' && $stages[$key]['status'] !== 'Administered') {
            // A rescheduled stage should display Scheduled instead of an older Missed row.
            $stages[$key]['status'] = 'Scheduled';

            if ($scheduled !== '') {
                if (
                    $stages[$key]['scheduled_date'] === ''
                    || frontToDbDate($scheduled) < frontToDbDate($stages[$key]['scheduled_date'])
                ) {
                    $stages[$key]['scheduled_date'] = $scheduled;
                }
            }
        } elseif ($status === 'Missed' && !in_array($stages[$key]['status'], ['Administered', 'Scheduled'], true)) {
            $stages[$key]['status'] = 'Missed';
        } elseif ($status === 'Missed' && $stages[$key]['scheduled_date'] === '') {
            $stages[$key]['status'] = 'Missed';
            if ($scheduled !== '') {
                $stages[$key]['scheduled_date'] = $scheduled;
            }
        }

        if ($scheduleRemarks === '' && trim((string)($row['remarks'] ?? '')) !== '') {
            $scheduleRemarks = trim((string)$row['remarks']);
        }
    }
    $stmt->close();

    foreach ($stages as $key => $stage) {
        if (($stage['status'] ?? '') === '') {
            $stages[$key]['status'] = 'Scheduled';
        }
    }

    $doseNumbers = array_values(array_unique($doseNumbers));
    sort($doseNumbers);

    $total = count($stages);
    $completed = 0;
    foreach ($stages as $stage) {
        if (($stage['status'] ?? '') === 'Administered') {
            $completed++;
        }
    }

    $overall = 'Pending';
    if ($total > 0 && $completed === $total) {
        $overall = 'Completed';
    } elseif ($completed > 0) {
        $overall = 'In Progress';
    }

    return [
        'stages' => $stages,
        'dose_numbers' => $doseNumbers,
        'profile' => inferScheduleProfile($doseNumbers),
        'overall_status' => $overall,
        'completed_count' => $completed,
        'total_count' => $total,
        'remarks' => $scheduleRemarks
    ];
}

function validatePatientData(array $data): array {
    $errors = [];

    if (empty(trim((string)($data['case_no'] ?? '')))) {
        $errors[] = "Case number is required.";
    }
    if (empty(trim((string)($data['patient_name'] ?? '')))) {
        $errors[] = "Patient name is required.";
    }
    if (empty(trim((string)($data['address'] ?? '')))) {
        $errors[] = "Address is required.";
    }
    if (empty(trim((string)($data['contact_number'] ?? '')))) {
        $errors[] = "Contact number is required.";
    }

    $requiredDates = [
        'dob' => 'Date of birth',
        'admission_date' => 'Admission date',
        'date_of_bite' => 'Date of bite'
    ];

    foreach ($requiredDates as $key => $label) {
        $value = trim((string)($data[$key] ?? ''));
        if ($value === '') {
            $errors[] = "{$label} is required.";
            continue;
        }
        if (!frontToDbDate($value)) {
            $errors[] = "Invalid {$label} format.";
        }
    }

    $gender = trim((string)($data['gender'] ?? ''));
    if (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
        $errors[] = "Please select a valid gender.";
    }

    if (empty(trim((string)($data['site_of_bite'] ?? '')))) {
        $errors[] = "Site of bite is required.";
    }
    if (empty(trim((string)($data['biting_animal'] ?? '')))) {
        $errors[] = "Biting animal is required.";
    }
    if (
        trim((string)($data['biting_animal'] ?? '')) === 'Others'
        && empty(trim((string)($data['custom_animal'] ?? '')))
    ) {
        $errors[] = "Please specify the biting animal.";
    }
    if (empty(trim((string)($data['animal_status'] ?? '')))) {
        $errors[] = "Animal status is required.";
    }
    if (empty(trim((string)($data['active_regimen'] ?? '')))) {
        $errors[] = "Active regimen is required.";
    }
    if (empty(trim((string)($data['vacc_category'] ?? '')))) {
        $errors[] = "Vaccination category is required.";
    }

    return $errors;
}

function getDosesForCategory(string $category, string $route): array {
    // SmartBiteCare's Nurse Vaccination module tracks six dose stages:
    // D0, D3, D7, D14, D21 and D28/30.
    // Admin Staff assigns those same six schedule stages.
    if ($category === 'Others') {
        return [];
    }

    return ['d0', 'd3', 'd7', 'd14', 'd21', 'd28'];
}

/**
 * Calculate scheduled date based on date of bite and dose key
 */
function calculateScheduledDate(?string $dateOfBite, string $doseKey, array $doseData): ?string {
    // If scheduled date is provided in form, use it
    if (!empty($doseData['scheduled_date'])) {
        return frontToDbDate($doseData['scheduled_date']);
    }
    
    // Otherwise calculate from date of bite
    $daysMap = ['d0' => 0, 'd3' => 3, 'd7' => 7, 'd14' => 14, 'd21' => 21, 'd28' => 28];
    $days = $daysMap[$doseKey] ?? 0;
    
    if (!empty($dateOfBite)) {
        return date('Y-m-d', strtotime($dateOfBite . ' + ' . $days . ' days'));
    }
    
    return date('Y-m-d', strtotime('+ ' . $days . ' days'));
}

/**
 * Send notification to nurses when a new patient is added
 */
function notifyNursesOnNewPatient(mysqli $conn, int $caseId, string $patientName, string $caseNumber, string $branchId, int $adminStaffId): void {
    try {
        // Get all nurses (role_id = 3) in the same branch
        $stmt = $conn->prepare("
            SELECT user_id 
            FROM users 
            WHERE role_id = 3 AND branch_id = ? AND status = 'Active'
        ");
        $stmt->bind_param("s", $branchId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            // No nurses found in this branch
            return;
        }
        
        // Create notification title and message
        $title = "New Patient Added";
        $message = "Administrative staff has added a new patient: {$patientName} (Case: {$caseNumber}). Please review the vaccination schedule.";
        $notificationType = "new_patient";
        
        // Insert notification for each nurse
        $insertStmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, notification_type, is_read, created_at)
            VALUES (?, ?, ?, ?, 0, NOW())
        ");
        
        while ($row = $result->fetch_assoc()) {
            $nurseId = (int) $row['user_id'];
            $insertStmt->bind_param("isss", $nurseId, $title, $message, $notificationType);
            $insertStmt->execute();
        }
        
        // Also log that notifications were sent
        auditLog($conn, $adminStaffId, $branchId, "Sent new patient notification to nurses for: {$patientName}", 'Notification');
        
    } catch (Exception $e) {
        // Silently fail - notification should not break the main process
        error_log("Failed to send nurse notification: " . $e->getMessage());
    }
}

// Function to archive a case with all related records
function archiveCase(mysqli $conn, int $caseId, int $userId, string $branchId, string $archiveReason = 'Archived by user'): bool {
    try {
        $conn->begin_transaction();
        
        // 1. Archive animal_bite_cases
        $stmt = $conn->prepare("
            INSERT INTO animal_bite_cases_archive 
            (case_id, case_number, patient_id, branch_id, animal_type, bite_location, bite_category, 
             animal_status, date_of_bite, case_status, remarks, admin_staff_id, created_at, 
             archived_at, archived_by, archive_reason, original_case_id)
            SELECT case_id, case_number, patient_id, branch_id, animal_type, bite_location, bite_category,
                   animal_status, date_of_bite, case_status, remarks, admin_staff_id, created_at,
                   NOW(), ?, ?, case_id
            FROM animal_bite_cases 
            WHERE case_id = ? AND branch_id = ?
        ");
        $stmt->bind_param("isis", $userId, $archiveReason, $caseId, $branchId);
        $stmt->execute();
        
        // 2. Archive registry_records
        $stmt = $conn->prepare("
            INSERT INTO registry_records_archive 
            (registry_id, case_id, branch_id, created_by, created_at, registry_number, status_of_biting_animal,
             erig, ats, tt, active_regimen, vaccine_item_id, vaccine_unit_id, dose_d0, dose_d3, dose_d7,
             dose_d14, dose_d21, dose_d28_30, booster, contact_number, remarks, updated_by, updated_at,
             archived_at, archived_by, archive_reason, original_registry_id)
            SELECT registry_id, case_id, branch_id, created_by, created_at, registry_number, status_of_biting_animal,
                   erig, ats, tt, active_regimen, vaccine_item_id, vaccine_unit_id, dose_d0, dose_d3, dose_d7,
                   dose_d14, dose_d21, dose_d28_30, booster, contact_number, remarks, updated_by, updated_at,
                   NOW(), ?, ?, registry_id
            FROM registry_records 
            WHERE case_id = ?
        ");
        $stmt->bind_param("isi", $userId, $archiveReason, $caseId);
        $stmt->execute();
        
        // 3. Archive vaccination_records
        $stmt = $conn->prepare("
            INSERT INTO vaccination_records_archive 
            (vaccination_id, patient_id, case_id, item_id, vaccine_name, unit_id, branch_id,
             dose_number, date_administered, scheduled_date, administered_at, next_schedule,
             vaccination_status, is_final_dose, remarks, nurse_id, created_at,
             archived_at, archived_by, archive_reason, original_vaccination_id)
            SELECT vaccination_id, patient_id, case_id, item_id, vaccine_name, unit_id, branch_id,
                   dose_number, date_administered, scheduled_date, CAST(administered_datetime AS CHAR), next_schedule,
                   vaccination_status, is_final_dose, remarks, nurse_id, created_at,
                   NOW(), ?, ?, vaccination_id
            FROM vaccination_records 
            WHERE case_id = ? AND branch_id = ?
        ");
        $stmt->bind_param("isis", $userId, $archiveReason, $caseId, $branchId);
        $stmt->execute();
        
        // 4. Archive philhealth_records
        $stmt = $conn->prepare("
            INSERT INTO philhealth_records_archive 
            (philhealth_record_id, case_id, has_philhealth, philhealth_membership, status,
             remarks, updated_by, updated_at, archived_at, archived_by, archive_reason,
             original_philhealth_record_id)
            SELECT philhealth_record_id, case_id, has_philhealth, philhealth_membership, status,
                   remarks, updated_by, updated_at, NOW(), ?, ?, philhealth_record_id
            FROM philhealth_records 
            WHERE case_id = ?
        ");
        $stmt->bind_param("isi", $userId, $archiveReason, $caseId);
        $stmt->execute();
        
        // 5. Archive registry_patients
        $stmt = $conn->prepare("
            INSERT INTO registry_patients_archive 
            (registry_patient_id, registry_id, patient_id, case_id, relationship_type,
             created_at, archived_at, archived_by, archive_reason, original_registry_patient_id)
            SELECT registry_patient_id, registry_id, patient_id, case_id, relationship_type,
                   created_at, NOW(), ?, ?, registry_patient_id
            FROM registry_patients 
            WHERE case_id = ?
        ");
        $stmt->bind_param("isi", $userId, $archiveReason, $caseId);
        $stmt->execute();
        
        // 6. Archive registry_vaccination_doses
        $stmt = $conn->prepare("
            INSERT INTO registry_vaccination_doses_archive 
            (dose_id, registry_id, patient_id, vaccination_id, dose_number, vaccine_name,
             vaccine_item_id, unit_id, scheduled_date, date_administered, administered_by,
             status, remarks, created_at, updated_at, archived_at, archived_by, archive_reason,
             original_dose_id)
            SELECT dose_id, registry_id, patient_id, vaccination_id, dose_number, vaccine_name,
                   vaccine_item_id, unit_id, scheduled_date, date_administered, administered_by,
                   status, remarks, created_at, updated_at, NOW(), ?, ?, dose_id
            FROM registry_vaccination_doses 
            WHERE registry_id IN (SELECT registry_id FROM registry_records WHERE case_id = ?)
        ");
        $stmt->bind_param("isi", $userId, $archiveReason, $caseId);
        $stmt->execute();
        
        // 7. Archive the patient ONLY if this is their last active case.
        // A patient may have multiple bite cases. Archiving one old case must not
        // hide the patient while another active case still exists.
        $patientStmt = $conn->prepare("
            SELECT patient_id
            FROM animal_bite_cases
            WHERE case_id = ?
              AND branch_id = ?
            LIMIT 1
        ");
        $patientStmt->bind_param("is", $caseId, $branchId);
        $patientStmt->execute();
        $patientRow = $patientStmt->get_result()->fetch_assoc();
        $patientStmt->close();

        $patientId = (int)($patientRow['patient_id'] ?? 0);

        if ($patientId > 0) {
            $otherCasesStmt = $conn->prepare("
                SELECT COUNT(*) AS active_cases
                FROM animal_bite_cases
                WHERE patient_id = ?
                  AND branch_id = ?
                  AND case_id != ?
                  AND is_archived = 0
            ");
            $otherCasesStmt->bind_param("isi", $patientId, $branchId, $caseId);
            $otherCasesStmt->execute();
            $otherActiveCases = (int)($otherCasesStmt->get_result()->fetch_assoc()['active_cases'] ?? 0);
            $otherCasesStmt->close();

            if ($otherActiveCases === 0) {
                $checkStmt = $conn->prepare("
                    SELECT is_archived
                    FROM patients
                    WHERE patient_id = ?
                      AND branch_id = ?
                    LIMIT 1
                ");
                $checkStmt->bind_param("is", $patientId, $branchId);
                $checkStmt->execute();
                $checkRow = $checkStmt->get_result()->fetch_assoc();
                $checkStmt->close();

                if ($checkRow && (int)$checkRow['is_archived'] === 0) {
                    $stmt = $conn->prepare("
                        INSERT INTO patients_archive
                        (patient_id, full_name, email, contact_number, birthday, gender, address,
                         branch_id, created_at, archived_at, archived_by, archive_reason,
                         original_patient_id)
                        SELECT patient_id, full_name, email, contact_number, birthday, gender, address,
                               branch_id, created_at, NOW(), ?, ?, patient_id
                        FROM patients
                        WHERE patient_id = ?
                          AND branch_id = ?
                    ");
                    $stmt->bind_param("isis", $userId, $archiveReason, $patientId, $branchId);
                    $stmt->execute();
                    $stmt->close();

                    $updateStmt = $conn->prepare("
                        UPDATE patients
                        SET is_archived = 1,
                            archived_at = NOW(),
                            archived_by = ?
                        WHERE patient_id = ?
                          AND branch_id = ?
                    ");
                    $updateStmt->bind_param("iis", $userId, $patientId, $branchId);
                    $updateStmt->execute();
                    $updateStmt->close();
                }
            }
        }

        // 8. Mark all related records as archived in the main tables
        // Update animal_bite_cases
        $updateStmt = $conn->prepare("
            UPDATE animal_bite_cases 
            SET is_archived = 1, archived_at = NOW(), archived_by = ? 
            WHERE case_id = ? AND branch_id = ?
        ");
        $updateStmt->bind_param("iis", $userId, $caseId, $branchId);
        $updateStmt->execute();
        
        // Update registry_records
        $updateStmt = $conn->prepare("
            UPDATE registry_records 
            SET is_archived = 1, archived_at = NOW(), archived_by = ? 
            WHERE case_id = ?
        ");
        $updateStmt->bind_param("ii", $userId, $caseId);
        $updateStmt->execute();
        
        // Update vaccination_records
        $updateStmt = $conn->prepare("
            UPDATE vaccination_records 
            SET is_archived = 1, archived_at = NOW(), archived_by = ? 
            WHERE case_id = ? AND branch_id = ?
        ");
        $updateStmt->bind_param("iis", $userId, $caseId, $branchId);
        $updateStmt->execute();
        
        // Update philhealth_records
        $updateStmt = $conn->prepare("
            UPDATE philhealth_records 
            SET is_archived = 1, archived_at = NOW(), archived_by = ? 
            WHERE case_id = ?
        ");
        $updateStmt->bind_param("ii", $userId, $caseId);
        $updateStmt->execute();
        
        // Update registry_patients
        $updateStmt = $conn->prepare("
            UPDATE registry_patients 
            SET is_archived = 1, archived_at = NOW(), archived_by = ? 
            WHERE case_id = ?
        ");
        $updateStmt->bind_param("ii", $userId, $caseId);
        $updateStmt->execute();
        
        // Update registry_vaccination_doses
        $updateStmt = $conn->prepare("
            UPDATE registry_vaccination_doses 
            SET is_archived = 1, archived_at = NOW(), archived_by = ? 
            WHERE registry_id IN (SELECT registry_id FROM registry_records WHERE case_id = ?)
        ");
        $updateStmt->bind_param("ii", $userId, $caseId);
        $updateStmt->execute();
        
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
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
                $filter = $_GET['filter'] ?? 'all';
                $sort = $_GET['sort'] ?? 'desc';
                
                $where = "WHERE c.branch_id = ? AND c.is_archived = 0 AND p.is_archived = 0";
                $params = [$logged_branch_id];
                $types = "s";
                
                // For 'all' filter, show ALL patients regardless of date
                $isFilterAll = ($filter === 'all');
                
                if (!$isFilterAll && !empty($date)) {
                    // Only apply date filter when NOT using the filter dropdown
                    $where .= " AND DATE(c.created_at) = ?";
                    $params[] = $date;
                    $types .= "s";
                }
                
                if ($search !== '') {
                    $where .= " AND (p.full_name LIKE ? OR c.case_number LIKE ? OR r.registry_number LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $types .= "sss";
                }

                $orderBy = ($sort === 'asc') ? 'ASC' : 'DESC';

                $sql = "
                    SELECT 
                        c.case_id,
                        c.case_number,
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
                    LEFT JOIN registry_records r ON c.case_id = r.case_id AND r.is_archived = 0
                    LEFT JOIN philhealth_records ph ON c.case_id = ph.case_id AND ph.is_archived = 0
                    $where
                    ORDER BY c.created_at $orderBy
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
                    $doseInfo = loadDoseStageData(
                        $conn,
                        (int)$row['case_id'],
                        (string)$logged_branch_id
                    );

                    $schedule = [
                        'd0' => '',
                        'd3' => '',
                        'd7' => '',
                        'd14' => '',
                        'd21' => '',
                        'd28' => ''
                    ];
                    $scheduleStatus = [
                        'd0' => '',
                        'd3' => '',
                        'd7' => '',
                        'd14' => '',
                        'd21' => '',
                        'd28' => ''
                    ];

                    foreach ($doseInfo['stages'] as $key => $stage) {
                        $schedule[$key] = $stage['administered_date'] !== ''
                            ? $stage['administered_date']
                            : ($stage['scheduled_date'] ?? '');
                        $scheduleStatus[$key] = $stage['status'] ?? 'Scheduled';
                    }

                    $vaccStatus = $doseInfo['overall_status'];

                    $hasPhilhealth = $row['has_philhealth'] ?? 'No';
                    $philhealthYes = ($hasPhilhealth === 'Yes') ? 'Yes' : 'No';

                    $resultArray[] = [
                        'case_id' => $row['case_id'],
                        'patient_id' => $row['patient_id'],
                        'case_no' => $row['case_number'] ?? $row['registry_number'] ?? '',
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
                        'route' => $doseInfo['profile']['route'],
                        'vacc_category' => $doseInfo['profile']['category'],
                        'schedule' => $schedule,
                        'schedule_status' => $scheduleStatus,
                        'vaccination_status' => $vaccStatus,
                        'philhealth' => $philhealthYes,
                        'philhealth_type' => $row['philhealth_membership'] ?? '',
                        'status' => $row['philhealth_status'] ?? '',
                        'remarks' => $row['case_remarks'] ?? $row['registry_remarks'] ?? '',
                        'created_at' => $row['created_at'],
                    ];
                }

                if ($filter === 'pending') {
                    $resultArray = array_filter($resultArray, function($item) {
                        return $item['vaccination_status'] === 'Pending' || $item['vaccination_status'] === 'In Progress';
                    });
                } elseif ($filter === 'completed') {
                    $resultArray = array_filter($resultArray, function($item) {
                        return $item['vaccination_status'] === 'Completed';
                    });
                }

                $resultArray = array_values($resultArray);
                jsonResponse($resultArray);
            } catch (Exception $e) {
                jsonResponse(['error' => 'Failed to fetch patients: ' . $e->getMessage()], 500);
            }
            break;

        case 'view':
            try {
                $caseId = (int)($_GET['case_id'] ?? 0);
                if ($caseId <= 0) {
                    jsonResponse(['error' => 'Invalid case ID'], 400);
                }

                $stmt = $conn->prepare("
                    SELECT
                        c.case_id,
                        c.case_number,
                        c.patient_id,
                        c.branch_id AS case_branch_id,
                        c.animal_type,
                        c.bite_location,
                        c.bite_category,
                        c.animal_status,
                        c.date_of_bite,
                        c.case_status,
                        c.remarks AS case_remarks,
                        c.created_at AS case_created_at,

                        p.full_name,
                        p.email,
                        p.contact_number,
                        p.birthday,
                        p.gender,
                        p.address,

                        r.registry_id,
                        r.registry_number,
                        r.erig,
                        r.ats,
                        r.tt,
                        r.active_regimen,
                        r.remarks AS registry_remarks,

                        ph.has_philhealth,
                        ph.philhealth_membership,
                        ph.status AS philhealth_status,
                        ph.remarks AS philhealth_remarks

                    FROM animal_bite_cases c
                    INNER JOIN patients p
                      ON c.patient_id = p.patient_id
                     AND p.branch_id = c.branch_id
                    LEFT JOIN registry_records r
                      ON c.case_id = r.case_id
                     AND r.is_archived = 0
                    LEFT JOIN philhealth_records ph
                      ON c.case_id = ph.case_id
                     AND ph.is_archived = 0
                    WHERE c.case_id = ?
                      AND c.branch_id = ?
                      AND c.is_archived = 0
                      AND p.is_archived = 0
                    LIMIT 1
                ");
                $stmt->bind_param("is", $caseId, $logged_branch_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$row) {
                    jsonResponse(['error' => 'Record not found'], 404);
                }

                $doseInfo = loadDoseStageData($conn, $caseId, (string)$logged_branch_id);

                $historyStmt = $conn->prepare("
                    SELECT
                        c.case_id,
                        c.case_number,
                        r.registry_number AS registry_number,
                        DATE(c.created_at) AS admit_date,
                        c.case_status
                    FROM animal_bite_cases c
                    LEFT JOIN registry_records r
                      ON c.case_id = r.case_id
                     AND r.is_archived = 0
                    WHERE c.patient_id = ?
                      AND c.branch_id = ?
                      AND c.case_id != ?
                      AND c.is_archived = 0
                    ORDER BY c.created_at DESC
                ");
                $historyStmt->bind_param("isi", $row['patient_id'], $logged_branch_id, $caseId);
                $historyStmt->execute();
                $historyResult = $historyStmt->get_result();
                $history = [];
                while ($histRow = $historyResult->fetch_assoc()) {
                    $history[] = [
                        'case_id' => (int)$histRow['case_id'],
                        'case_no' => $histRow['case_number'] ?: ($histRow['registry_number'] ?? ''),
                        'admit_date' => dbToFrontDate($histRow['admit_date']),
                        'status' => $histRow['case_status'] ?? 'Ongoing'
                    ];
                }
                $historyStmt->close();

                $hasPhilhealth = $row['has_philhealth'] ?? 'No';

                $details = [
                    'case_id' => (int)$row['case_id'],
                    'patient_id' => (int)$row['patient_id'],
                    'case_no' => $row['case_number'] ?: ($row['registry_number'] ?? ''),
                    'patient_name' => $row['full_name'],
                    'address' => $row['address'] ?? '',
                    'dob' => dbToFrontDate($row['birthday']),
                    'age' => calcAge($row['birthday']),
                    'gender' => $row['gender'] ?? '',
                    'has_philhealth' => $hasPhilhealth,
                    'philhealth_membership' => $row['philhealth_membership'] ?? '',
                    'contact_number' => $row['contact_number'] ?? '',
                    'admission_date' => dbToFrontDate(substr((string)$row['case_created_at'], 0, 10)),
                    'date_of_bite' => dbToFrontDate($row['date_of_bite']),
                    'site_of_bite' => $row['bite_location'] ?? '',
                    'biting_animal' => $row['animal_type'] ?? '',
                    'bite_category' => $row['bite_category'] ?? '',
                    'animal_status' => $row['animal_status'] ?? '',
                    // Treatment values are read-only here. Nurses own actual administration.
                    'erig_ml' => $row['erig'] ?? 0,
                    'ats' => (bool)$row['ats'],
                    'tt' => (bool)$row['tt'],
                    'active_regimen' => $row['active_regimen'] ?? '',
                    'route' => $doseInfo['profile']['route'],
                    'vacc_category' => $doseInfo['profile']['category'],
                    'vaccination_doses' => $doseInfo['stages'],
                    'vaccination_status' => $doseInfo['overall_status'],
                    'vaccination_remarks' => $doseInfo['remarks'],
                    'status' => $row['philhealth_status'] ?? '',
                    'remarks' => $row['case_remarks'] ?: ($row['philhealth_remarks'] ?? ''),
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

                if (!is_array($input)) {
                    jsonResponse(['error' => 'Invalid JSON input'], 400);
                }

                $postedCsrf = (string)($input['csrf_token'] ?? '');
                if ($postedCsrf === '' || !hash_equals($csrfToken, $postedCsrf)) {
                    jsonResponse(['error' => 'Invalid request token. Please refresh the page and try again.'], 403);
                }

                $validationErrors = validatePatientData($input);
                if (!empty($validationErrors)) {
                    jsonResponse(['error' => implode(' ', $validationErrors)], 400);
                }

                $caseId = !empty($input['case_id']) ? (int)$input['case_id'] : null;
                $patientId = !empty($input['patient_id']) ? (int)$input['patient_id'] : null;

                $caseNo = trim((string)($input['case_no'] ?? ''));
                $fullName = trim((string)($input['patient_name'] ?? ''));
                $dob = frontToDbDate((string)($input['dob'] ?? ''));
                $gender = trim((string)($input['gender'] ?? ''));
                $address = trim((string)($input['address'] ?? ''));
                $contact = trim((string)($input['contact_number'] ?? ''));

                $admissionDate = frontToDbDate((string)($input['admission_date'] ?? ''));
                $biteDate = frontToDbDate((string)($input['date_of_bite'] ?? ''));
                $siteBite = trim((string)($input['site_of_bite'] ?? ''));

                $animal = trim((string)($input['biting_animal'] ?? ''));
                $customAnimal = trim((string)($input['custom_animal'] ?? ''));
                if ($animal === 'Others' && $customAnimal !== '') {
                    $animal = $customAnimal;
                }

                $animalStat = trim((string)($input['animal_status'] ?? ''));
                $regimen = trim((string)($input['active_regimen'] ?? ''));
                $vaccCategory = trim((string)($input['vacc_category'] ?? ''));
                $route = trim((string)($input['route'] ?? ''));

                $status = trim((string)($input['status'] ?? 'For Writing'));
                $hasPhilhealth = trim((string)($input['philhealth'] ?? 'No'));
                $philhealthMembership = trim((string)($input['philhealth_type'] ?? ''));

                $vaccinationDoses = is_array($input['vaccination_doses'] ?? null)
                    ? $input['vaccination_doses']
                    : [];
                $scheduleRemarks = trim((string)($input['vaccination_remarks'] ?? ''));

                if (caseNoExists($conn, $caseNo, (string)$logged_branch_id, $caseId)) {
                    throw new Exception("Case number '{$caseNo}' already exists in this branch.");
                }

                $conn->begin_transaction();

                /* -----------------------------------------------------
                   1. PATIENT INFORMATION
                   ----------------------------------------------------- */
                if ($patientId) {
                    $checkStmt = $conn->prepare("
                        SELECT patient_id
                        FROM patients
                        WHERE patient_id = ?
                          AND branch_id = ?
                          AND is_archived = 0
                        LIMIT 1
                    ");
                    $checkStmt->bind_param("is", $patientId, $logged_branch_id);
                    $checkStmt->execute();
                    $patientExists = $checkStmt->get_result()->fetch_assoc();
                    $checkStmt->close();

                    if (!$patientExists) {
                        throw new Exception("Patient not found or access denied.");
                    }

                    $upd = $conn->prepare("
                        UPDATE patients
                        SET full_name = ?,
                            contact_number = ?,
                            birthday = ?,
                            gender = ?,
                            address = ?
                        WHERE patient_id = ?
                          AND branch_id = ?
                          AND is_archived = 0
                    ");
                    $upd->bind_param(
                        "sssssis",
                        $fullName,
                        $contact,
                        $dob,
                        $gender,
                        $address,
                        $patientId,
                        $logged_branch_id
                    );
                    if (!$upd->execute()) {
                        throw new Exception('Unable to update patient information.');
                    }
                    $upd->close();
                } else {
                    $ins = $conn->prepare("
                        INSERT INTO patients
                        (full_name, contact_number, birthday, gender, address, branch_id)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $ins->bind_param(
                        "ssssss",
                        $fullName,
                        $contact,
                        $dob,
                        $gender,
                        $address,
                        $logged_branch_id
                    );
                    if (!$ins->execute()) {
                        throw new Exception('Unable to create patient record.');
                    }
                    $patientId = (int)$conn->insert_id;
                    $ins->close();
                }

                /* -----------------------------------------------------
                   2. ANIMAL BITE CASE
                   created_at is the existing field this module uses as
                   the admission date, so preserve that behavior.
                   ----------------------------------------------------- */
                $isNewCase = false;

                if ($caseId) {
                    $checkCase = $conn->prepare("
                        SELECT case_id
                        FROM animal_bite_cases
                        WHERE case_id = ?
                          AND branch_id = ?
                          AND is_archived = 0
                        LIMIT 1
                    ");
                    $checkCase->bind_param("is", $caseId, $logged_branch_id);
                    $checkCase->execute();
                    $caseExists = $checkCase->get_result()->fetch_assoc();
                    $checkCase->close();

                    if (!$caseExists) {
                        throw new Exception("Case not found or access denied.");
                    }

                    $updCase = $conn->prepare("
                        UPDATE animal_bite_cases
                        SET case_number = ?,
                            patient_id = ?,
                            animal_type = ?,
                            bite_location = ?,
                            animal_status = ?,
                            date_of_bite = ?,
                            admin_staff_id = ?,
                            created_at = CONCAT(?, ' ', TIME(created_at))
                        WHERE case_id = ?
                          AND branch_id = ?
                          AND is_archived = 0
                    ");
                    $updCase->bind_param(
                        "sissssisis",
                        $caseNo,
                        $patientId,
                        $animal,
                        $siteBite,
                        $animalStat,
                        $biteDate,
                        $logged_user_id,
                        $admissionDate,
                        $caseId,
                        $logged_branch_id
                    );
                    if (!$updCase->execute()) {
                        throw new Exception('Unable to update bite case.');
                    }
                    $updCase->close();
                } else {
                    $isNewCase = true;
                    $caseStatus = 'Ongoing';

                    $insCase = $conn->prepare("
                        INSERT INTO animal_bite_cases
                        (
                            case_number,
                            patient_id,
                            branch_id,
                            animal_type,
                            bite_location,
                            animal_status,
                            date_of_bite,
                            case_status,
                            admin_staff_id,
                            created_at
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CONCAT(?, ' 00:00:00'))
                    ");
                    $insCase->bind_param(
                        "sissssssis",
                        $caseNo,
                        $patientId,
                        $logged_branch_id,
                        $animal,
                        $siteBite,
                        $animalStat,
                        $biteDate,
                        $caseStatus,
                        $logged_user_id,
                        $admissionDate
                    );
                    if (!$insCase->execute()) {
                        throw new Exception('Unable to create bite case.');
                    }
                    $caseId = (int)$conn->insert_id;
                    $insCase->close();
                }

                /* -----------------------------------------------------
                   3. REGISTRY INFORMATION
                   Admin Staff maintains planning/registry fields only.
                   Existing dose-completion flags and treatment values
                   (ERIG/ATS/TT) are NOT overwritten here.
                   ----------------------------------------------------- */
                $regExists = $conn->prepare("
                    SELECT registry_id
                    FROM registry_records
                    WHERE case_id = ?
                      AND is_archived = 0
                    LIMIT 1
                ");
                $regExists->bind_param("i", $caseId);
                $regExists->execute();
                $regRow = $regExists->get_result()->fetch_assoc();
                $regExists->close();

                $registryId = $regRow['registry_id'] ?? null;

                if ($registryId) {
                    $updReg = $conn->prepare("
                        UPDATE registry_records
                        SET registry_number = ?,
                            status_of_biting_animal = ?,
                            active_regimen = ?,
                            contact_number = ?,
                            updated_by = ?,
                            updated_at = NOW()
                        WHERE registry_id = ?
                          AND is_archived = 0
                    ");
                    $updReg->bind_param(
                        "ssssii",
                        $caseNo,
                        $animalStat,
                        $regimen,
                        $contact,
                        $logged_user_id,
                        $registryId
                    );
                    if (!$updReg->execute()) {
                        throw new Exception('Unable to update registry information.');
                    }
                    $updReg->close();
                } else {
                    $insReg = $conn->prepare("
                        INSERT INTO registry_records
                        (
                            case_id,
                            branch_id,
                            created_by,
                            registry_number,
                            status_of_biting_animal,
                            active_regimen,
                            contact_number,
                            updated_by,
                            updated_at
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $insReg->bind_param(
                        "isissssi",
                        $caseId,
                        $logged_branch_id,
                        $logged_user_id,
                        $caseNo,
                        $animalStat,
                        $regimen,
                        $contact,
                        $logged_user_id
                    );
                    if (!$insReg->execute()) {
                        throw new Exception('Unable to create registry information.');
                    }
                    $registryId = (int)$conn->insert_id;
                    $insReg->close();
                }

                /* -----------------------------------------------------
                   4. PHILHEALTH
                   ----------------------------------------------------- */
                $phExists = $conn->prepare("
                    SELECT philhealth_record_id
                    FROM philhealth_records
                    WHERE case_id = ?
                      AND is_archived = 0
                    LIMIT 1
                ");
                $phExists->bind_param("i", $caseId);
                $phExists->execute();
                $phRow = $phExists->get_result()->fetch_assoc();
                $phExists->close();

                $phRecId = $phRow['philhealth_record_id'] ?? null;
                $dbHasPhilhealth = ($hasPhilhealth === 'Yes') ? 'Yes' : 'No';
                $dbPhilhealthMembership = ($dbHasPhilhealth === 'Yes')
                    ? ($philhealthMembership !== '' ? $philhealthMembership : null)
                    : null;

                $allowedPhilhealthStatuses = [
                    'For Writing',
                    'For Screening',
                    'For Signing/Transmittal',
                    'Completed'
                ];
                $dbPhilhealthStatus = ($dbHasPhilhealth === 'Yes' && in_array($status, $allowedPhilhealthStatuses, true))
                    ? $status
                    : null;

                if ($phRecId) {
                    $updPh = $conn->prepare("
                        UPDATE philhealth_records
                        SET has_philhealth = ?,
                            philhealth_membership = ?,
                            status = ?,
                            updated_by = ?,
                            updated_at = NOW()
                        WHERE philhealth_record_id = ?
                          AND is_archived = 0
                    ");
                    $updPh->bind_param(
                        "sssii",
                        $dbHasPhilhealth,
                        $dbPhilhealthMembership,
                        $dbPhilhealthStatus,
                        $logged_user_id,
                        $phRecId
                    );
                    if (!$updPh->execute()) {
                        throw new Exception('Unable to update PhilHealth information.');
                    }
                    $updPh->close();
                } else {
                    $insPh = $conn->prepare("
                        INSERT INTO philhealth_records
                        (case_id, has_philhealth, philhealth_membership, status, updated_by, updated_at)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $insPh->bind_param(
                        "isssi",
                        $caseId,
                        $dbHasPhilhealth,
                        $dbPhilhealthMembership,
                        $dbPhilhealthStatus,
                        $logged_user_id
                    );
                    if (!$insPh->execute()) {
                        throw new Exception('Unable to create PhilHealth information.');
                    }
                    $insPh->close();
                }

                /* -----------------------------------------------------
                   5. VACCINATION SCHEDULE ONLY
                   IMPORTANT:
                   - Admin Staff does not mark a dose Completed.
                   - Admin Staff does not deduct inventory.
                   - Admin Staff does not set nurse_id.
                   - item_id, vaccine_name and unit_id stay NULL until Nurse administration.
                   - Completed/Missed/Nurse-created records are preserved.
                   ----------------------------------------------------- */
                $requiredDoseKeys = getDosesForCategory($vaccCategory, $route);
                $requiredDoseNumbers = [];
                foreach ($requiredDoseKeys as $key) {
                    $num = doseKeyToNumber($key);
                    if ($num) $requiredDoseNumbers[] = $num;
                }
                for ($doseNumber = 1; $doseNumber <= 6; $doseNumber++) {
                    $doseKey = doseNumberToKey($doseNumber);
                    if (!$doseKey) continue;

                    // Soft-archive only old Admin Staff schedule placeholders.
                    archiveAdminScheduleRows(
                        $conn,
                        (int)$caseId,
                        (string)$logged_branch_id,
                        $doseNumber,
                        (int)$logged_user_id
                    );

                    if (!in_array($doseNumber, $requiredDoseNumbers, true)) {
                        continue;
                    }

                    // A dose already completed by a nurse must never be recreated
                    // as a pending schedule by Admin Staff.
                    $completedStmt = $conn->prepare("
                        SELECT vaccination_id
                        FROM vaccination_records
                        WHERE case_id = ?
                          AND branch_id = ?
                          AND dose_number = ?
                          AND vaccination_status = 'Completed'
                          AND date_administered IS NOT NULL
                          AND date_administered <= CURDATE()
                          AND is_archived = 0
                        LIMIT 1
                    ");
                    $completedStmt->bind_param(
                        "isi",
                        $caseId,
                        $logged_branch_id,
                        $doseNumber
                    );
                    $completedStmt->execute();
                    $alreadyCompleted = $completedStmt->get_result()->num_rows > 0;
                    $completedStmt->close();

                    if ($alreadyCompleted) {
                        continue;
                    }

                    // If a Nurse already created a schedule for this stage,
                    // preserve it rather than creating a duplicate.
                    $nurseScheduleStmt = $conn->prepare("
                        SELECT vr.vaccination_id
                        FROM vaccination_records vr
                        INNER JOIN users schedule_user
                          ON schedule_user.user_id = vr.nurse_id
                        WHERE vr.case_id = ?
                          AND vr.branch_id = ?
                          AND vr.dose_number = ?
                          AND vr.vaccination_status = 'Scheduled'
                          AND vr.is_archived = 0
                          AND schedule_user.role_id = 3
                        LIMIT 1
                    ");
                    $nurseScheduleStmt->bind_param(
                        "isi",
                        $caseId,
                        $logged_branch_id,
                        $doseNumber
                    );
                    $nurseScheduleStmt->execute();
                    $nurseOwnsSchedule = $nurseScheduleStmt->get_result()->num_rows > 0;
                    $nurseScheduleStmt->close();

                    if ($nurseOwnsSchedule) {
                        continue;
                    }

                    $dosePayload = is_array($vaccinationDoses[$doseKey] ?? null)
                        ? $vaccinationDoses[$doseKey]
                        : [];

                    $scheduledDate = null;
                    if (!empty($dosePayload['scheduled_date'])) {
                        $scheduledDate = frontToDbDate((string)$dosePayload['scheduled_date']);
                    }

                    if (!$scheduledDate) {
                        $scheduledDate = calculateScheduledDate(
                            $biteDate,
                            $doseKey,
                            $dosePayload
                        );
                    }

                    if (!$scheduledDate) {
                        throw new Exception(
                            'Please assign a valid scheduled date for ' . strtoupper($doseKey) . '.'
                        );
                    }

                    // Admin Staff assigns only the dose-stage schedule.
                    // The actual product, unit and nurse are recorded later by the Nurse.
                    $isFinalDose = ($doseNumber === 6) ? 1 : 0;

                    $insertSchedule = $conn->prepare("
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
                            scheduled_date,
                            vaccination_status,
                            is_final_dose,
                            remarks,
                            nurse_id
                        )
                        VALUES (?, ?, NULL, NULL, NULL, ?, ?, NULL, ?, 'Scheduled', ?, ?, NULL)
                    ");
                    $insertSchedule->bind_param(
                        "iisisis",
                        $patientId,
                        $caseId,
                        $logged_branch_id,
                        $doseNumber,
                        $scheduledDate,
                        $isFinalDose,
                        $scheduleRemarks
                    );
                    if (!$insertSchedule->execute()) {
                        throw new Exception(
                            'Unable to save the vaccination schedule for dose stage ' . $doseNumber . '.'
                        );
                    }
                    $insertSchedule->close();
                }

                if ($isNewCase) {
                    notifyNursesOnNewPatient(
                        $conn,
                        (int)$caseId,
                        $fullName,
                        $caseNo,
                        (string)$logged_branch_id,
                        (int)$logged_user_id
                    );
                }

                $actionText = $isNewCase
                    ? "Created patient record and vaccination schedule: {$fullName} (Case: {$caseNo})"
                    : "Updated patient information/schedule: {$fullName} (Case: {$caseNo})";

                auditLog(
                    $conn,
                    (int)$logged_user_id,
                    (string)$logged_branch_id,
                    $actionText,
                    'Patient Record'
                );

                $conn->commit();

                jsonResponse([
                    'success' => true,
                    'case_id' => (int)$caseId,
                    'case_no' => $caseNo
                ]);

            } catch (Exception $e) {
                try {
                    $conn->rollback();
                } catch (Throwable $ignored) {
                }

                error_log("Error saving patient record: " . $e->getMessage());
                jsonResponse(['error' => $e->getMessage()], 500);
            }
            break;

        case 'archive':
            try {
                $rawInput = file_get_contents('php://input');
                $input = json_decode($rawInput, true);
                if (!is_array($input)) {
                    jsonResponse(['error' => 'Invalid archive request.'], 400);
                }

                $postedCsrf = (string)($input['csrf_token'] ?? '');
                if ($postedCsrf === '' || !hash_equals($csrfToken, $postedCsrf)) {
                    jsonResponse(['error' => 'Invalid request token. Please refresh the page and try again.'], 403);
                }

                $caseId = (int)($input['case_id'] ?? 0);
                $archiveReason = trim((string)($input['reason'] ?? 'Archived by user'));
                if ($archiveReason === '') {
                    $archiveReason = 'Archived by user';
                }

                if ($caseId <= 0) {
                    jsonResponse(['error' => 'Invalid case ID'], 400);
                }

                $caseStmt = $conn->prepare("
                    SELECT
                        c.case_id,
                        p.full_name,
                        c.case_number,
                        r.registry_number
                    FROM animal_bite_cases c
                    INNER JOIN patients p
                      ON c.patient_id = p.patient_id
                     AND p.branch_id = c.branch_id
                    LEFT JOIN registry_records r
                      ON c.case_id = r.case_id
                     AND r.is_archived = 0
                    WHERE c.case_id = ?
                      AND c.branch_id = ?
                      AND c.is_archived = 0
                    LIMIT 1
                ");
                $caseStmt->bind_param("is", $caseId, $logged_branch_id);
                $caseStmt->execute();
                $caseData = $caseStmt->get_result()->fetch_assoc();
                $caseStmt->close();

                if (!$caseData) {
                    throw new Exception("Record not found or already archived.");
                }

                archiveCase(
                    $conn,
                    $caseId,
                    (int)$logged_user_id,
                    (string)$logged_branch_id,
                    $archiveReason
                );

                $caseNumber = !empty($caseData['case_number'])
                    ? $caseData['case_number']
                    : ($caseData['registry_number'] ?? '');

                auditLog(
                    $conn,
                    (int)$logged_user_id,
                    (string)$logged_branch_id,
                    "Archived patient case: {$caseData['full_name']} (Case: {$caseNumber})",
                    'Patient Record'
                );

                jsonResponse(['success' => true]);

            } catch (Exception $e) {
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
                $exists = caseNoExists($conn, $caseNo, (string)$logged_branch_id, $excludeId);
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
                    SELECT c.case_id, c.case_number, r.registry_number AS case_no, 
                           DATE(c.created_at) AS admit_date, c.case_status
                    FROM animal_bite_cases c
                    LEFT JOIN registry_records r ON c.case_id = r.case_id
                    WHERE c.patient_id = ? AND c.branch_id = ? AND c.is_archived = 0
                    ORDER BY c.created_at DESC
                ");
                $stmt->bind_param("is", $patientId, $logged_branch_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $rows = [];
                while ($row = $result->fetch_assoc()) {
                    $rows[] = [
                        'case_id' => $row['case_id'],
                        'case_no' => $row['case_number'] ?? $row['case_no'] ?? '',
                        'admit_date' => dbToFrontDate($row['admit_date']),
                        'status' => $row['case_status'] ?? 'Ongoing',
                    ];
                }
                jsonResponse($rows);
            } catch (Exception $e) {
                jsonResponse(['error' => 'Failed to fetch history: ' . $e->getMessage()], 500);
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
    * {
        box-sizing: border-box;
    }

    html,
    body {
        width: 100%;
        max-width: 100%;
        margin: 0;
        padding: 0;
        overflow-x: hidden;
    }

        body { 
            background: #f0f2f5; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .main {
            margin-left: 260px;
            width: calc(100% - 260px);
            min-height: 100vh;
            padding: 0 30px 30px 30px;
            background: #f0f2f5;
            box-sizing: border-box;
            overflow-x: hidden;;
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

        .btn-danger {
            background: #dc3545;
        }
        .btn-danger:hover { background: #b02a37; }

        .sort-area {
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .sort-btn {
            background: transparent;
            border: 2px solid var(--gray-300);
            color: var(--gray-600);
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .sort-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: rgba(43,58,140,0.05);
        }

        .sort-btn.active {
            border-color: var(--primary);
            background: var(--primary);
            color: white;
        }

        .sort-btn .sort-icon {
            font-size: 14px;
        }

        .record-container {
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr);
            gap: 20px;
            width: 100%;
            max-width: 100%;
            min-width: 0;
        }

      @media (max-width: 1200px) {
            .record-container {
                grid-template-columns: 280px minmax(0, 1fr);
                gap: 16px;
            }
            .search-area .search-wrapper input {
                width: 280px;
            }
            .topbar h3 {
                margin-left: 0;
                font-size: 24px;
            }
        }
        @media (max-width: 900px) {
            .record-container {
                grid-template-columns: 1fr;
            }

            .calendar-panel {
                width: 100%;
            }

            .table-panel {
                width: 100%;
                min-width: 0;
            }

            .toolbar {
                display: flex;
                flex-wrap: wrap;
            }

            .search-area {
                width: 100%;
            }

            .search-area .search-wrapper {
                flex: 1;
                min-width: 0;
            }

            .search-area .search-wrapper input {
                width: 100%;
            }

            .sort-area {
                width: 100%;
            }

            .sort-btn {
                flex: 1;
            }

            .toolbar > .btn {
                width: 100%;
                justify-content: center;
            }
}
        @media (max-width: 991px) {
            .main {
                margin-left: 112px;
                width: calc(100% - 112px);
                padding: 0 18px 30px 18px;
            }

            .topbar {
                padding: 0 20px;
            }

            .topbar h3 {
                margin-left: 0;
                font-size: 20px;
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
            color: #fff;
        }

        .calendar-panel .flatpickr-calendar.inline .flatpickr-day.today {
            border-color: var(--primary);
            color: #fff;
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
            color: var(--gray-700);
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
            color: var(--gray-700);
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

        .vacc-status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .vacc-status-badge.pending {
            background: #fff3cd;
            color: #856404;
        }

        .vacc-status-badge.in-progress {
            background: #e6ecff;
            color: var(--primary);
        }

        .vacc-status-badge.completed {
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
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
            padding: 0 4px;
            box-sizing: border-box;
        }

        table {
            width: 100%;
            min-width: 950px;
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

        .action-icons i.bi-archive:hover {
            color: #6c757d;
            background: rgba(108,117,125,0.12);
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

        #customVaccCategoryContainer {
            margin-top: 8px;
        }

        .filter-dropdown-container {
            position: relative;
            display: inline-block;
        }

        .filter-dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            background: white;
            min-width: 200px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
            border-radius: 8px;
            padding: 8px 0;
            z-index: 1000;
            margin-top: 4px;
            border: 1px solid #e0e0e0;
        }

        .filter-dropdown-menu.show {
            display: block;
        }

        .filter-dropdown-menu .filter-item {
            padding: 10px 20px;
            cursor: pointer;
            transition: background 0.15s;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            color: var(--gray-700);
        }

        .filter-dropdown-menu .filter-item:hover {
            background: var(--gray-100);
        }

        .filter-dropdown-menu .filter-item.active {
            background: var(--primary);
            color: white;
        }

        .filter-dropdown-menu .filter-item .filter-icon {
            font-size: 16px;
        }

        .filter-dropdown-menu .filter-item .filter-check {
            margin-left: auto;
            color: var(--primary);
        }

        .filter-dropdown-menu .filter-item.active .filter-check {
            color: white;
        }

        .filter-badge {
            display: inline-block;
            background: var(--primary);
            color: white;
            border-radius: 20px;
            padding: 2px 10px;
            font-size: 11px;
            font-weight: 700;
            margin-left: 6px;
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
            
            .filter-dropdown-menu {
                min-width: 160px;
                right: 0;
                left: auto;
            }
            
            .sort-area {
                flex-wrap: wrap;
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
            <i class="bi bi-person-circle"></i>
            <?php echo htmlspecialchars($logged_username); ?>
            <span style="font-size:12px; color:#adb5bd; font-weight:400; margin-left:4px;">| Admin Staff</span>
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
                <!-- Filter Button with Dropdown -->
                <div class="filter-dropdown-container">
                    <button class="btn btn-outline" id="FilterBtn">
                        <i class="bi bi-funnel-fill"></i> Filter <span id="filterBadge" class="filter-badge">All</span>
                    </button>
                    <div class="filter-dropdown-menu" id="filterDropdown">
                        <div class="filter-item active" data-filter="all">
                            <span class="filter-icon"><i class="bi bi-list-ul"></i></span>
                            All Patients
                            <span class="filter-check"><i class="bi bi-check-lg"></i></span>
                        </div>
                        <div class="filter-item" data-filter="pending">
                            <span class="filter-icon"><i class="bi bi-clock-history"></i></span>
                            Pending Vaccination
                            <span class="filter-check"><i class="bi bi-check-lg"></i></span>
                        </div>
                        <div class="filter-item" data-filter="completed">
                            <span class="filter-icon"><i class="bi bi-check-circle"></i></span>
                            Completed Vaccination
                            <span class="filter-check"><i class="bi bi-check-lg"></i></span>
                        </div>
                    </div>
                </div>
                <!-- Sort Buttons -->
                <div class="sort-area">
                    <button class="sort-btn active" id="sortDesc" data-sort="desc">
                        <span class="sort-icon"><i class="bi bi-sort-down"></i></span> Newest First
                    </button>
                    <button class="sort-btn" id="sortAsc" data-sort="asc">
                        <span class="sort-icon"><i class="bi bi-sort-up"></i></span> Oldest First
                    </button>
                </div>
            </div>
            <button class="btn" id="addPatientBtn">
                <i class="bi bi-plus-circle"></i> Add New Patient
            </button>
        </div>

        <div class="record-container">
            <!-- Calendar Panel -->
            <div class="calendar-panel">
                <div class="panel-header">
                    Follow-Up Calendar
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
                        <div class="section-title">Vaccination Scheduling</div>

                        <div class="alert alert-info border-0 mb-3" style="background:#eef3ff;color:#2B3A8C;">
                            <i class="bi bi-info-circle-fill me-2"></i>
                            Admin Staff assigns the patient's vaccination schedule only.
                            Actual vaccine products, quantities, administration dates, and completed doses are recorded by the Nurse.
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <input type="hidden" id="route" value="Intradermal (ID)">

                                <div class="mb-3">
                                    <label class="form-label">Active Regimen <span class="text-danger">*</span></label>
                                    <select class="form-select" id="activeRegimen" required>
                                        <option value="PVRV TRC SPEEDA">PVRV TRC SPEEDA</option>
                                        <option value="PVRV TRC ABHAYRAB">PVRV TRC ABHAYRAB</option>
                                        <option value="OTHER">OTHER</option>
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Vaccination Category <span class="text-danger">*</span></label>
                                    <select class="form-select" id="vaccCategory" required>
                                        <option value="Post-Exposure Prophylaxis (PEP)" selected>Post-Exposure Prophylaxis (PEP)</option>
                                    </select>
                                </div>

                                <div id="customVaccCategoryContainer" style="display:none;">
                                    <input type="hidden" id="customVaccCategory" value="">
                                </div>
                            </div>
                        </div>

                        <!-- Vaccination Schedule Section -->
                        <div id="scheduleSection">
                            <div class="section-title">Vaccination Schedule</div>

                            <div class="schedule-table-wrapper">
                                <table class="schedule-table">
                                    <thead>
                                        <tr>
                                            <th>Dose Stage</th>
                                            <th>Scheduled Date</th>
                                            <th>Current Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="scheduleTableBody">
                                        <!-- Rows populated by JavaScript -->
                                    </tbody>
                                </table>
                            </div>

                            <div class="vaccination-summary">
                                <div class="summary-header">
                                    <strong>Schedule Progress</strong>
                                    <span class="summary-badge" id="vaccStatusBadge">Pending</span>
                                </div>
                                <div class="summary-progress">
                                    <span id="progressText">0 of 0 stages completed by Nurse</span>
                                    <div class="progress-bar-wrapper">
                                        <div class="progress-bar-fill" id="progressBarFill" style="width: 0%;"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="schedule-remarks">
                                <label class="form-label">Schedule Remarks</label>
                                <textarea class="form-control" id="vaccinationRemarks" rows="2"
                                          placeholder="Enter schedule remarks (optional)..."></textarea>
                            </div>
                        </div>

                        <hr>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label">Philhealth Status <span class="text-danger">*</span></label>
                                    <select class="form-select" id="status" required>
                                        <option value="">Not Applicable</option>
                                        <option value="For Writing">For Writing</option>
                                        <option value="For Screening">For Screening</option>
                                        <option value="For Signing/Transmittal">For Signing/Transmittal</option>
                                        <option value="Completed">Completed</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="savePatientBtn">Save Patient & Schedule</button>
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

    <!-- Archive Confirmation Modal -->
    <div class="modal fade" id="archiveModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Archive</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to archive this record?</p>
                    <p class="text-danger" id="archivePatientName"></p>
                    <div class="mb-3">
                        <label class="form-label">Archive Reason</label>
                        <input type="text" class="form-control" id="archiveReason" placeholder="Optional reason for archiving" value="Archived by user">
                    </div>
                    <p class="text-warning"><small>This record will be moved to archive and can be restored later if needed.</small></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" id="confirmArchiveBtn">Archive</button>
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
    const csrfToken = <?php echo json_encode($csrfToken); ?>;

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
    let currentAdmissionDate = '<?php echo date('m/d/Y'); ?>';
    let currentPage = 1;
    const pageSize = 8;
    let searchTerm = '';
    let archiveTargetCaseId = null;
    let allPatients = [];
    let isCheckingCaseNo = false;
    let currentFilter = 'all';
    let currentSort = 'desc';

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

    function frontToApi(dateStr) {
        if (!dateStr) return '';
        const parts = dateStr.split('/');
        if (parts.length !== 3) return '';
        return `${parts[2]}-${parts[0].padStart(2,'0')}-${parts[1].padStart(2,'0')}`;
    }

    function apiToFront(dateStr) {
        if (!dateStr) return '';
        const parts = dateStr.split('-');
        if (parts.length !== 3) return '';
        return `${parts[1]}/${parts[2]}/${parts[0]}`;
    }

    function getDoseKeysForCategory(category, route) {
        if (category === 'Others') return [];
        return ['d0', 'd3', 'd7', 'd14', 'd21', 'd28'];
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

        const oldData = getCurrentDoseData();
        currentDoseKeys = getDoseKeysForCategory(category, route);

        const newDoseData = {};
        currentDoseKeys.forEach(key => {
            newDoseData[key] = oldData[key] || {
                scheduled_date: '',
                administered_date: '',
                status: 'Scheduled',
                locked: false
            };
        });

        renderScheduleTable(newDoseData);

        const scheduleSection = document.getElementById('scheduleSection');
        scheduleSection.style.display = category === 'Others' ? 'none' : 'block';
    }

    function scheduleStatusBadge(status) {
        if (status === 'Administered') {
            return '<span class="badge bg-success">Completed by Nurse</span>';
        }
        if (status === 'Missed') {
            return '<span class="badge bg-danger">Missed</span>';
        }
        return '<span class="badge bg-primary">Scheduled</span>';
    }

    function renderScheduleTable(doseData = null) {
        const tbody = document.getElementById('scheduleTableBody');

        if (!doseData) {
            doseData = {};
            currentDoseKeys.forEach(key => {
                doseData[key] = {
                    scheduled_date: '',
                    administered_date: '',
                    status: 'Scheduled',
                    locked: false
                };
            });
        }

        currentDoseData = doseData;

        let html = '';
        DOSE_CONFIG.forEach(d => {
            if (!currentDoseKeys.includes(d.key)) return;

            const data = doseData[d.key] || {
                scheduled_date: '',
                administered_date: '',
                status: 'Scheduled',
                locked: false
            };

            const completed = data.status === 'Administered' || data.locked === true;
            const value = escapeHtml(data.scheduled_date || '');
            const statusHtml = scheduleStatusBadge(data.status || 'Scheduled');

            html += `
                <tr data-dose="${d.key}">
                    <td class="dose-label">${escapeHtml(d.label)}</td>
                    <td>
                        <input
                            type="text"
                            class="form-control form-control-sm schedule-date-input flatpickr-date"
                            id="sched_${d.key}"
                            value="${value}"
                            placeholder="mm/dd/yyyy"
                            data-dose="${d.key}"
                            ${completed ? 'disabled' : ''}
                        >
                        ${completed && data.administered_date
                            ? `<small class="text-success d-block mt-1">Administered: ${escapeHtml(data.administered_date)}</small>`
                            : ''}
                    </td>
                    <td>${statusHtml}</td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
        initScheduleFlatpickrs();

        document.querySelectorAll('.schedule-date-input').forEach(input => {
            if (input.disabled) return;

            input.addEventListener('change', function() {
                const doseKey = this.dataset.dose;
                if (doseKey === 'd0') {
                    autoCalculateSchedules(this.value);
                }
            });

            input.addEventListener('input', function() {
                this.dataset.autoCalculated = 'false';
            });
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

            if (!el.disabled) {
                flatpickr(el, config);
            }
        });
    }

    function autoCalculateSchedules(day0Date) {
        if (!day0Date) return;

        const autoSchedules = calculateAutoSchedule(day0Date);
        if (Object.keys(autoSchedules).length === 0) return;

        DOSE_CONFIG.forEach(d => {
            if (d.key === 'd0' || !currentDoseKeys.includes(d.key)) return;

            const input = document.getElementById(`sched_${d.key}`);
            if (!input || input.disabled || !autoSchedules[d.key]) return;

            if (!input.value || input.dataset.autoCalculated !== 'false') {
                input.value = autoSchedules[d.key];
                input.dataset.autoCalculated = 'true';

                if (input._flatpickr) {
                    input._flatpickr.setDate(autoSchedules[d.key], false, 'm/d/Y');
                }
            }
        });

        updateVaccinationSummary();
    }

    function getCurrentDoseData() {
        const data = {};

        currentDoseKeys.forEach(key => {
            const existing = currentDoseData[key] || {};
            const input = document.getElementById(`sched_${key}`);

            data[key] = {
                scheduled_date: input ? input.value : (existing.scheduled_date || ''),
                administered_date: existing.administered_date || '',
                status: existing.status || 'Scheduled',
                locked: existing.locked === true || existing.status === 'Administered'
            };
        });

        return data;
    }

    function updateVaccinationSummary() {
        const doseData = getCurrentDoseData();
        const total = currentDoseKeys.length;
        let completed = 0;

        currentDoseKeys.forEach(key => {
            if ((doseData[key]?.status || '') === 'Administered') {
                completed++;
            }
        });

        const progressText = document.getElementById('progressText');
        const progressBarFill = document.getElementById('progressBarFill');
        const statusBadge = document.getElementById('vaccStatusBadge');

        progressText.textContent = `${completed} of ${total} stages completed by Nurse`;

        const percentage = total > 0 ? (completed / total) * 100 : 0;
        progressBarFill.style.width = `${percentage}%`;

        if (total > 0 && completed === total) {
            statusBadge.textContent = 'Completed';
            statusBadge.className = 'summary-badge completed';
            progressBarFill.className = 'progress-bar-fill completed';
        } else if (completed > 0) {
            statusBadge.textContent = 'In Progress';
            statusBadge.className = 'summary-badge';
            progressBarFill.className = 'progress-bar-fill';
        } else {
            statusBadge.textContent = total > 0 ? 'Scheduled' : 'No Schedule';
            statusBadge.className = 'summary-badge';
            progressBarFill.className = 'progress-bar-fill';
        }
    }

    function loadDoseDataForEdit(doseData) {
        renderScheduleTable(doseData || {});
    }

    function initNewSchedule() {
        const category = document.getElementById('vaccCategory').value;
        const route = document.getElementById('route').value;

        currentDoseKeys = getDoseKeysForCategory(category, route);

        const scheduleData = {};
        currentDoseKeys.forEach(key => {
            scheduleData[key] = {
                scheduled_date: '',
                administered_date: '',
                status: 'Scheduled',
                locked: false
            };
        });

        renderScheduleTable(scheduleData);
        document.getElementById('vaccinationRemarks').value = '';

        const scheduleSection = document.getElementById('scheduleSection');
        scheduleSection.style.display = category === 'Others' ? 'none' : 'block';
    }

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
        url += `&filter=${encodeURIComponent(currentFilter)}`;
        url += `&sort=${encodeURIComponent(currentSort)}`;
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
            
            if (currentFilter === 'all' && !searchTerm.trim()) {
                dateApi = '';
            } else if (currentFilter !== 'all' && !searchTerm.trim()) {
                dateApi = '';
            } else if (currentFilter === 'all' && searchTerm.trim()) {
                dateApi = '';
            }
            
            allPatients = await fetchPatients(dateApi, searchTerm.trim());

            const total = allPatients.length;
            const totalPages = Math.ceil(total / pageSize) || 1;
            if (currentPage > totalPages) currentPage = totalPages;
            const start = (currentPage - 1) * pageSize;
            const items = allPatients.slice(start, start + pageSize);

            document.getElementById('dateCountBadge').textContent = total + ' patient' + (total !== 1 ? 's' : '');
            document.getElementById('patientCountBadge').textContent = total;
            
            let displayText = '';
            if (currentFilter === 'all') {
                displayText = 'All Patients';
            } else if (currentFilter === 'pending') {
                displayText = 'Pending Vaccination';
            } else if (currentFilter === 'completed') {
                displayText = 'Completed Vaccination';
            } else {
                displayText = 'All Patients';
            }
            
            document.getElementById('selectedDateDisplay').textContent = displayText;
            document.getElementById('selectedDateDisplay2').textContent = displayText;

            const tbody = document.getElementById('patientTableBody');
            if (items.length === 0) {
                let message = 'No records found.';
                if (currentFilter === 'pending') message = 'No patients with pending vaccination.';
                if (currentFilter === 'completed') message = 'No patients with completed vaccination.';
                tbody.innerHTML = `
                    <tr>
                        <td colspan="10">
                            <div class="no-records-msg">
                                <i class="bi bi-inbox"></i>
                                <p>${message}</p>
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
                    const vaccBadge = p.vaccination_status === 'Completed'
                        ? '<span class="vacc-status-badge completed">Completed</span>'
                        : (p.vaccination_status === 'In Progress'
                            ? '<span class="vacc-status-badge in-progress">In Progress</span>'
                            : '<span class="vacc-status-badge pending">Pending</span>');
                    html += `
                        <tr>
                            <td><strong>${escapeHtml(p.case_no)}</strong></td>
                            <td>${escapeHtml(p.patient_name)}</td>
                            <td>${philBadge}</td>
                            <td>${escapeHtml(p.philhealth_type || '')}</td>
                            <td>${escapeHtml(p.dob)}</td>
                            <td>${escapeHtml(p.age ?? '')}</td>
                            <td>${escapeHtml(p.gender || '')}</td>
                            <td>${escapeHtml(p.address || '')}</td>
                            <td>${vaccBadge}</td>
                            <td>
                                <div class="action-icons">
                                    <i class="bi bi-eye" data-action="view" data-case="${p.case_id}" title="View"></i>
                                    <i class="bi bi-pencil" data-action="edit" data-case="${p.case_id}" title="Edit"></i>
                                    <i class="bi bi-archive" data-action="archive" data-case="${p.case_id}" title="Archive"></i>
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
                        else if (action === 'archive') confirmArchive(caseId);
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
                if (dateStr && currentFilter === 'all') {
                    currentAdmissionDate = dateStr;
                    currentPage = 1;
                    renderTable();
                } else if (dateStr && currentFilter !== 'all') {
                    showToast('Filter is active. Click "All Patients" to view by date.', '', true);
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
                ['Active Regimen', data.active_regimen || ''],
                ['Vaccination Category', data.vacc_category || ''],
                ['Vaccination Status', data.vaccination_status || 'Pending'],
                ['Record Status', data.has_philhealth === 'Yes' ? (data.status || 'For Writing') : 'Not Applicable'],
                ['Remarks', data.remarks || '']
            ];

            let html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 20px;">';
            fields.forEach((f, index) => {
                html += `
                    <div class="view-detail-row" style="${index % 2 === 0 ? '' : 'border-bottom:none;'}">
                        <span class="view-detail-label">${escapeHtml(f[0])}</span>
                        <span class="view-detail-value">${escapeHtml(f[1])}</span>
                    </div>
                `;
            });
            html += '</div>';

            if (data.vaccination_doses && Object.keys(data.vaccination_doses).length > 0) {
                html += `
                    <hr>
                    <h6 class="fw-bold" style="color:var(--primary);">Vaccination Schedule</h6>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Dose Stage</th>
                                    <th>Scheduled Date</th>
                                    <th>Administered Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                `;

                DOSE_CONFIG.forEach(d => {
                    const stage = data.vaccination_doses[d.key];
                    if (!stage) return;

                    html += `
                        <tr>
                            <td>${escapeHtml(d.label)}</td>
                            <td>${escapeHtml(stage.scheduled_date || '—')}</td>
                            <td>${escapeHtml(stage.administered_date || '—')}</td>
                            <td>${scheduleStatusBadge(stage.status || 'Scheduled')}</td>
                        </tr>
                    `;
                });

                html += `
                            </tbody>
                        </table>
                    </div>
                `;
            }

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
            const knownAnimals = ['Dog', 'Cat', 'Not Applicable'];
            if (knownAnimals.includes(data.biting_animal)) {
                document.getElementById('bitingAnimal').value = data.biting_animal;
                document.getElementById('customAnimal').value = '';
                document.getElementById('customAnimalContainer').style.display = 'none';
            } else {
                document.getElementById('bitingAnimal').value = 'Others';
                document.getElementById('customAnimal').value = data.biting_animal || '';
                document.getElementById('customAnimalContainer').style.display = 'block';
            }
            document.getElementById('animalStatus').value = data.animal_status || '';
            document.getElementById('activeRegimen').value = data.active_regimen || '';
            document.getElementById('vaccCategory').value = data.vacc_category || 'Post-Exposure Prophylaxis (PEP)';
            document.getElementById('route').value = data.route || 'Intradermal (ID)';
            document.getElementById('status').value =
                data.has_philhealth === 'Yes'
                    ? (data.status || 'For Writing')
                    : '';

            const category = document.getElementById('vaccCategory').value;
            const route = document.getElementById('route').value;

            const returnedDoseKeys = data.vaccination_doses
                ? Object.keys(data.vaccination_doses)
                : [];

            currentDoseKeys = returnedDoseKeys.length > 0
                ? DOSE_CONFIG.filter(d => returnedDoseKeys.includes(d.key)).map(d => d.key)
                : getDoseKeysForCategory(category, route);

            if (data.vaccination_doses && Object.keys(data.vaccination_doses).length > 0) {
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
                                <span>${escapeHtml(h.case_no || 'N/A')} (${escapeHtml(h.admit_date || '')})</span>
                                <span class="badge bg-${h.status === 'Completed' ? 'success' : 'warning'}">${escapeHtml(h.status || 'Ongoing')}</span>
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

    function confirmArchive(caseId) {
        archiveTargetCaseId = caseId;
        const patient = allPatients.find(p => p.case_id === caseId);
        document.getElementById('archivePatientName').textContent = patient ? 
            `Patient: ${patient.patient_name} (Case: ${patient.case_no})` : 
            `Case ID: ${caseId}`;
        document.getElementById('archiveReason').value = 'Archived by user';
        new bootstrap.Modal(document.getElementById('archiveModal')).show();
    }

    document.getElementById('confirmArchiveBtn').addEventListener('click', async function() {
        if (!archiveTargetCaseId) return;
        
        const reason = document.getElementById('archiveReason').value.trim() || 'Archived by user';
        
        try {
            const res = await fetch(`${apiBase}?action=archive`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    case_id: archiveTargetCaseId,
                    reason: reason
                })
            });
            const data = await res.json();
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('archiveModal')).hide();
                showToast('Record archived successfully');
                renderTable();
            } else {
                throw new Error(data.error || 'Archive failed');
            }
        } catch (e) {
            showToast('Error archiving record', e.message, true);
        } finally {
            archiveTargetCaseId = null;
        }
    });

    function togglePhilhealthStatus() {
        const philhealth = document.getElementById('philhealth').value;
        const statusSelect = document.getElementById('status');

        if (philhealth === 'No') {
            statusSelect.disabled = true;
            statusSelect.value = '';
        } else {
            statusSelect.disabled = false;
            if (!statusSelect.value) {
                statusSelect.value = 'For Writing';
            }
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
            csrf_token: csrfToken,
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
            custom_animal: document.getElementById('customAnimal').value.trim(),
            animal_status: document.getElementById('animalStatus').value,
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
        if (formData.biting_animal === 'Others' && !formData.custom_animal) {
            showToast('Error', 'Please specify the biting animal.', true);
            document.getElementById('customAnimal').focus();
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

        document.getElementById('customAnimal').value = '';
        document.getElementById('customAnimalContainer').style.display = 'none';

        togglePhilhealthStatus();
        initNewSchedule();
        
        const category = document.getElementById('vaccCategory').value;
        const scheduleSection = document.getElementById('scheduleSection');
        if (category === 'Others') {
            scheduleSection.style.display = 'none';
        } else {
            scheduleSection.style.display = 'block';
        }
        
        new bootstrap.Modal(document.getElementById('patientModal')).show();
    });

    document.getElementById('searchInput').addEventListener('input', function() {
        searchTerm = this.value;
        currentPage = 1;
        renderTable();
    });

    // Filter Button Functionality
    const filterBtn = document.getElementById('FilterBtn');
    const filterDropdown = document.getElementById('filterDropdown');
    const filterBadge = document.getElementById('filterBadge');
    
    filterBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        filterDropdown.classList.toggle('show');
    });
    
    document.addEventListener('click', function(e) {
        if (!filterBtn.contains(e.target) && !filterDropdown.contains(e.target)) {
            filterDropdown.classList.remove('show');
        }
    });
    
    document.querySelectorAll('.filter-item').forEach(item => {
        item.addEventListener('click', function() {
            const filterValue = this.dataset.filter;
            
            document.querySelectorAll('.filter-item').forEach(el => {
                el.classList.remove('active');
            });
            this.classList.add('active');
            
            const filterText = this.textContent.trim();
            filterBadge.textContent = filterText;
            
            currentFilter = filterValue;
            
            filterDropdown.classList.remove('show');
            
            currentPage = 1;
            renderTable();
            
            const statusMap = {
                'all': 'Showing all patients (all dates)',
                'pending': 'Showing pending vaccination patients (all dates)',
                'completed': 'Showing completed vaccination patients (all dates)'
            };
            showToast(statusMap[filterValue] || filterValue);
        });
    });

    // Sort Button Functionality
    const sortAscBtn = document.getElementById('sortAsc');
    const sortDescBtn = document.getElementById('sortDesc');

    function updateSortButtons(sortValue) {
        sortAscBtn.classList.remove('active');
        sortDescBtn.classList.remove('active');
        
        if (sortValue === 'asc') {
            sortAscBtn.classList.add('active');
        } else {
            sortDescBtn.classList.add('active');
        }
    }

    sortAscBtn.addEventListener('click', function() {
        if (currentSort === 'asc') return;
        currentSort = 'asc';
        updateSortButtons('asc');
        currentPage = 1;
        renderTable();
        showToast('Sorting: Oldest First');
    });

    sortDescBtn.addEventListener('click', function() {
        if (currentSort === 'desc') return;
        currentSort = 'desc';
        updateSortButtons('desc');
        currentPage = 1;
        renderTable();
        showToast('Sorting: Newest First');
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
                    p.philhealth === 'Yes' ? (p.status || 'For Writing') : 'Not Applicable'
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

    document.getElementById('dateOfBite').addEventListener('change', function() {
        if (!this.value || !currentDoseKeys.includes('d0')) return;

        const d0Input = document.getElementById('sched_d0');
        if (d0Input && !d0Input.disabled && !d0Input.value) {
            d0Input.value = this.value;
            if (d0Input._flatpickr) {
                d0Input._flatpickr.setDate(this.value, false, 'm/d/Y');
            }
            autoCalculateSchedules(this.value);
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
    });
    </script>
</body>
</html>