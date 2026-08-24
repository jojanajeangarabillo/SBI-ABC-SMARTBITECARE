<?php
session_start();
require_once 'sources/db_connect.php';

// Check if user is logged in and is an admin staff
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    $_SESSION['role_id'] != 4
) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$branch_id = null;
$branch_name = '';
$username = '';
$role_name = 'Admin Staff';

// Get user's branch info
$userQuery = "SELECT u.branch_id, u.username, b.branch_name, r.role_name
              FROM users u 
              LEFT JOIN branches b ON u.branch_id = b.branch_id
              LEFT JOIN roles r ON u.role_id = r.role_id
              WHERE u.user_id = ?";
$stmt = $conn->prepare($userQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$userResult = $stmt->get_result();

if ($userResult->num_rows > 0) {
    $userData = $userResult->fetch_assoc();
    $branch_id = $userData['branch_id'];
    $branch_name = $userData['branch_name'] ?? 'Unknown Branch';
    $username = $userData['username'] ?? 'Admin Staff';
    $role_name = $userData['role_name'] ?? 'Admin Staff';
}

if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
}

// ----------------------------------------------------------------------
// GET FILTER PARAMETERS
// ----------------------------------------------------------------------
$currentMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$currentYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$selectedDate = isset($_GET['date']) ? $_GET['date'] : '';
$filterStatus = isset($_GET['status']) ? $_GET['status'] : 'all';
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';

// Validate month/year
if ($currentMonth < 1) $currentMonth = 1;
if ($currentMonth > 12) $currentMonth = 12;
if ($currentYear < 2000) $currentYear = 2000;
if ($currentYear > 2100) $currentYear = 2100;

// ----------------------------------------------------------------------
// GET ALL FOLLOW-UP RECORDS WITH FILTERS
// ----------------------------------------------------------------------

// Base query for all follow-ups
$allFollowUpsQuery = "
    SELECT 
        c.case_id,
        c.case_number,
        c.patient_id,
        p.full_name as patient_name,
        p.gender,
        p.birthday,
        p.contact_number,
        p.address,
        c.animal_type,
        c.bite_location,
        c.bite_category,
        c.date_of_bite,
        c.case_status,
        r.registry_number as case_no,
        v.vaccination_id,
        v.dose_number,
        v.vaccine_name,
        v.date_administered,
        v.next_schedule,
        v.vaccination_status,
        v.is_final_dose,
        v.remarks as vaccination_remarks,
        v.created_at as vaccination_created_at,
        v.item_id,
        v.unit_id,
        TIMESTAMPDIFF(YEAR, p.birthday, CURDATE()) as age,
        CASE 
            WHEN v.dose_number = 1 THEN 'Day 0 (1st Dose)'
            WHEN v.dose_number = 2 THEN 'Day 3 (2nd Dose)'
            WHEN v.dose_number = 3 THEN 'Day 7 (3rd Dose)'
            WHEN v.dose_number = 4 THEN 'Day 14 (4th Dose)'
            WHEN v.dose_number = 5 THEN 'Day 21 (5th Dose)'
            WHEN v.dose_number = 6 THEN 'Day 28 (6th Dose)'
            ELSE CONCAT('Day ', v.dose_number)
        END as dose_label,
        CASE 
            WHEN v.vaccination_status = 'Completed' THEN 'Completed'
            WHEN v.next_schedule < CURDATE() AND v.vaccination_status = 'Scheduled' THEN 'Overdue'
            WHEN v.vaccination_status = 'Scheduled' AND v.next_schedule = CURDATE() THEN 'Today'
            WHEN v.vaccination_status = 'Scheduled' AND v.next_schedule > CURDATE() THEN 'Pending'
            ELSE v.vaccination_status
        END as display_status,
        CASE 
            WHEN v.vaccination_status = 'Completed' THEN 1
            WHEN v.next_schedule < CURDATE() AND v.vaccination_status = 'Scheduled' THEN 2
            WHEN v.vaccination_status = 'Scheduled' AND v.next_schedule = CURDATE() THEN 3
            WHEN v.vaccination_status = 'Scheduled' AND v.next_schedule > CURDATE() THEN 4
            ELSE 5
        END as status_order
    FROM animal_bite_cases c
    INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
    INNER JOIN patients p ON c.patient_id = p.patient_id
    LEFT JOIN registry_records r ON c.case_id = r.case_id
    WHERE c.branch_id = ?
    AND v.next_schedule IS NOT NULL
";

// Apply filters
$whereConditions = [];
$params = [$branch_id];
$types = "s";

// Date filter (specific day)
if (!empty($selectedDate)) {
    $whereConditions[] = "DATE(v.next_schedule) = ?";
    $params[] = $selectedDate;
    $types .= "s";
}

// Month/Year filter (if no specific date, filter by month/year)
if (empty($selectedDate)) {
    $whereConditions[] = "YEAR(v.next_schedule) = ?";
    $params[] = $currentYear;
    $types .= "i";
    $whereConditions[] = "MONTH(v.next_schedule) = ?";
    $params[] = $currentMonth;
    $types .= "i";
}

// Status filter
if ($filterStatus != 'all') {
    if ($filterStatus == 'completed') {
        $whereConditions[] = "v.vaccination_status = 'Completed'";
    } elseif ($filterStatus == 'overdue') {
        $whereConditions[] = "v.next_schedule < CURDATE() AND v.vaccination_status = 'Scheduled'";
    } elseif ($filterStatus == 'today') {
        $whereConditions[] = "v.next_schedule = CURDATE() AND v.vaccination_status = 'Scheduled'";
    } elseif ($filterStatus == 'pending') {
        $whereConditions[] = "v.next_schedule > CURDATE() AND v.vaccination_status = 'Scheduled'";
    }
}

// Search filter
if (!empty($searchQuery)) {
    $searchTerm = "%$searchQuery%";
    $whereConditions[] = "(p.full_name LIKE ? OR r.registry_number LIKE ? OR c.case_number LIKE ? OR v.vaccine_name LIKE ?)";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "ssss";
}

if (!empty($whereConditions)) {
    $allFollowUpsQuery .= " AND " . implode(" AND ", $whereConditions);
}

$allFollowUpsQuery .= " ORDER BY status_order ASC, v.next_schedule ASC, p.full_name ASC";

// Execute main query
$stmt = $conn->prepare($allFollowUpsQuery);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$allFollowUpsResult = $stmt->get_result();

$followUpRecords = [];
while ($row = $allFollowUpsResult->fetch_assoc()) {
    $followUpRecords[] = $row;
}

// ----------------------------------------------------------------------
// GET STATISTICS FOR ALL RECORDS
// ----------------------------------------------------------------------

// Total counts
$statsQuery = "
    SELECT 
        COUNT(DISTINCT c.case_id) as total,
        SUM(CASE WHEN v.vaccination_status = 'Completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN v.next_schedule < CURDATE() AND v.vaccination_status = 'Scheduled' THEN 1 ELSE 0 END) as overdue,
        SUM(CASE WHEN v.vaccination_status = 'Scheduled' AND v.next_schedule = CURDATE() THEN 1 ELSE 0 END) as today,
        SUM(CASE WHEN v.vaccination_status = 'Scheduled' AND v.next_schedule > CURDATE() THEN 1 ELSE 0 END) as pending
    FROM animal_bite_cases c
    INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
    WHERE c.branch_id = ?
    AND v.next_schedule IS NOT NULL
";
$stmt = $conn->prepare($statsQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$statsResult = $stmt->get_result();
$stats = $statsResult->fetch_assoc();

$totalCount = $stats['total'] ?? 0;
$completedCount = $stats['completed'] ?? 0;
$overdueCount = $stats['overdue'] ?? 0;
$todayCount = $stats['today'] ?? 0;
$pendingCount = $stats['pending'] ?? 0;

// ----------------------------------------------------------------------
// GET CALENDAR DATA FOR CURRENT MONTH
// ----------------------------------------------------------------------
$calQuery = "
    SELECT 
        DATE(v.next_schedule) as schedule_date,
        COUNT(DISTINCT c.case_id) as count,
        SUM(CASE WHEN v.next_schedule < CURDATE() AND v.vaccination_status = 'Scheduled' THEN 1 ELSE 0 END) as overdue_count,
        SUM(CASE WHEN v.vaccination_status = 'Completed' THEN 1 ELSE 0 END) as completed_count
    FROM animal_bite_cases c
    INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
    WHERE c.branch_id = ?
    AND v.next_schedule IS NOT NULL
    AND YEAR(v.next_schedule) = ?
    AND MONTH(v.next_schedule) = ?
    GROUP BY DATE(v.next_schedule)
    ORDER BY schedule_date
";
$stmt = $conn->prepare($calQuery);
$stmt->bind_param("sii", $branch_id, $currentYear, $currentMonth);
$stmt->execute();
$calResult = $stmt->get_result();

$calendarData = [];
$totalEvents = 0;
while ($row = $calResult->fetch_assoc()) {
    $calendarData[$row['schedule_date']] = [
        'count' => (int)$row['count'],
        'overdue_count' => (int)$row['overdue_count'],
        'completed_count' => (int)$row['completed_count']
    ];
    $totalEvents += (int)$row['count'];
}

// ----------------------------------------------------------------------
// GET UPCOMING FOLLOW-UPS
// ----------------------------------------------------------------------
$upcomingQuery = "
    SELECT 
        DATE(v.next_schedule) as schedule_date,
        COUNT(DISTINCT c.case_id) as count
    FROM animal_bite_cases c
    INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
    WHERE c.branch_id = ?
    AND v.next_schedule IS NOT NULL
    AND v.vaccination_status = 'Scheduled'
    AND v.next_schedule >= CURDATE()
    AND v.next_schedule <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(v.next_schedule)
    ORDER BY schedule_date
";
$stmt = $conn->prepare($upcomingQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$upcomingResult = $stmt->get_result();
$upcomingData = [];
while ($row = $upcomingResult->fetch_assoc()) {
    $upcomingData[$row['schedule_date']] = (int)$row['count'];
}

// ----------------------------------------------------------------------
// HANDLE EXPORT
// ----------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] == 'true') {
    $exportDate = isset($_GET['date']) ? $_GET['date'] : '';
    $exportStatus = isset($_GET['status']) ? $_GET['status'] : 'all';
    
    $exportQuery = "
        SELECT 
            r.registry_number as case_no,
            c.case_number,
            p.full_name as patient_name,
            p.gender,
            TIMESTAMPDIFF(YEAR, p.birthday, CURDATE()) as age,
            p.contact_number,
            c.animal_type,
            c.bite_location,
            v.vaccine_name,
            v.dose_number,
            DATE(v.next_schedule) as scheduled_date,
            DATE(v.date_administered) as date_administered,
            v.vaccination_status,
            CASE 
                WHEN v.vaccination_status = 'Completed' THEN 'Completed'
                WHEN v.next_schedule < CURDATE() AND v.vaccination_status = 'Scheduled' THEN 'Overdue'
                WHEN v.vaccination_status = 'Scheduled' AND v.next_schedule = CURDATE() THEN 'Today'
                WHEN v.vaccination_status = 'Scheduled' AND v.next_schedule > CURDATE() THEN 'Pending'
                ELSE v.vaccination_status
            END as display_status
        FROM animal_bite_cases c
        INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
        INNER JOIN patients p ON c.patient_id = p.patient_id
        LEFT JOIN registry_records r ON c.case_id = r.case_id
        WHERE c.branch_id = ?
        AND v.next_schedule IS NOT NULL
    ";
    
    $exportParams = [$branch_id];
    $exportTypes = "s";
    
    if (!empty($exportDate)) {
        $exportQuery .= " AND DATE(v.next_schedule) = ?";
        $exportParams[] = $exportDate;
        $exportTypes .= "s";
    }
    
    if ($exportStatus != 'all') {
        if ($exportStatus == 'completed') {
            $exportQuery .= " AND v.vaccination_status = 'Completed'";
        } elseif ($exportStatus == 'overdue') {
            $exportQuery .= " AND v.next_schedule < CURDATE() AND v.vaccination_status = 'Scheduled'";
        } elseif ($exportStatus == 'today') {
            $exportQuery .= " AND v.next_schedule = CURDATE() AND v.vaccination_status = 'Scheduled'";
        } elseif ($exportStatus == 'pending') {
            $exportQuery .= " AND v.next_schedule > CURDATE() AND v.vaccination_status = 'Scheduled'";
        }
    }
    
    $exportQuery .= " ORDER BY v.next_schedule ASC";
    
    $stmt = $conn->prepare($exportQuery);
    $stmt->bind_param($exportTypes, ...$exportParams);
    $stmt->execute();
    $exportResult = $stmt->get_result();
    
    $filename = 'follow_ups_' . date('Y-m-d');
    if (!empty($exportDate)) {
        $filename .= '_' . $exportDate;
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Case No.', 'Case Number', 'Patient Name', 'Gender', 'Age', 'Contact', 'Animal Type', 'Bite Location', 'Vaccine', 'Dose', 'Scheduled Date', 'Administered Date', 'Status']);
    
    while ($row = $exportResult->fetch_assoc()) {
        fputcsv($output, [
            $row['case_no'] ?? 'N/A',
            $row['case_number'] ?? 'N/A',
            $row['patient_name'],
            $row['gender'] ?? 'N/A',
            $row['age'] ?? 'N/A',
            $row['contact_number'] ?? 'N/A',
            $row['animal_type'] ?? 'N/A',
            $row['bite_location'] ?? 'N/A',
            $row['vaccine_name'] ?? 'N/A',
            $row['dose_number'] ?? 'N/A',
            $row['scheduled_date'],
            $row['date_administered'] ?? 'N/A',
            $row['display_status'] ?? $row['vaccination_status']
        ]);
    }
    fclose($output);
    exit();
}

// ----------------------------------------------------------------------
// HANDLE AJAX REQUESTS
// ----------------------------------------------------------------------
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $action = isset($_GET['ajax_action']) ? $_GET['ajax_action'] : '';
    
    switch ($action) {
        case 'get_stats':
            $statsQuery = "
                SELECT 
                    COUNT(DISTINCT c.case_id) as total,
                    SUM(CASE WHEN v.vaccination_status = 'Completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN v.next_schedule < CURDATE() AND v.vaccination_status = 'Scheduled' THEN 1 ELSE 0 END) as overdue,
                    SUM(CASE WHEN v.vaccination_status = 'Scheduled' AND v.next_schedule = CURDATE() THEN 1 ELSE 0 END) as today,
                    SUM(CASE WHEN v.vaccination_status = 'Scheduled' AND v.next_schedule > CURDATE() THEN 1 ELSE 0 END) as pending
                FROM animal_bite_cases c
                INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
                WHERE c.branch_id = ?
                AND v.next_schedule IS NOT NULL
            ";
            $stmt = $conn->prepare($statsQuery);
            $stmt->bind_param("s", $branch_id);
            $stmt->execute();
            $statsResult = $stmt->get_result();
            $stats = $statsResult->fetch_assoc();
            
            echo json_encode([
                'success' => true,
                'total' => $stats['total'] ?? 0,
                'completed' => $stats['completed'] ?? 0,
                'overdue' => $stats['overdue'] ?? 0,
                'today' => $stats['today'] ?? 0,
                'pending' => $stats['pending'] ?? 0
            ]);
            break;
            
        case 'get_calendar':
            $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
            $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
            
            $calQuery = "
                SELECT 
                    DATE(v.next_schedule) as schedule_date,
                    COUNT(DISTINCT c.case_id) as count,
                    SUM(CASE WHEN v.next_schedule < CURDATE() AND v.vaccination_status = 'Scheduled' THEN 1 ELSE 0 END) as overdue_count,
                    SUM(CASE WHEN v.vaccination_status = 'Completed' THEN 1 ELSE 0 END) as completed_count
                FROM animal_bite_cases c
                INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
                WHERE c.branch_id = ?
                AND v.next_schedule IS NOT NULL
                AND YEAR(v.next_schedule) = ?
                AND MONTH(v.next_schedule) = ?
                GROUP BY DATE(v.next_schedule)
            ";
            $stmt = $conn->prepare($calQuery);
            $stmt->bind_param("sii", $branch_id, $year, $month);
            $stmt->execute();
            $calResult = $stmt->get_result();
            
            $calData = [];
            while ($row = $calResult->fetch_assoc()) {
                $calData[$row['schedule_date']] = [
                    'count' => (int)$row['count'],
                    'overdue_count' => (int)$row['overdue_count'],
                    'completed_count' => (int)$row['completed_count']
                ];
            }
            
            echo json_encode([
                'success' => true,
                'data' => $calData,
                'month' => $month,
                'year' => $year
            ]);
            break;
            
        case 'get_records':
            $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
            $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
            $date = isset($_GET['date']) ? $_GET['date'] : '';
            $status = isset($_GET['status']) ? $_GET['status'] : 'all';
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            
            $query = "
                SELECT 
                    c.case_id,
                    c.case_number,
                    p.full_name as patient_name,
                    p.gender,
                    p.birthday,
                    c.animal_type,
                    c.bite_location,
                    r.registry_number as case_no,
                    v.vaccination_id,
                    v.dose_number,
                    v.vaccine_name,
                    v.date_administered,
                    v.next_schedule,
                    v.vaccination_status,
                    v.is_final_dose,
                    TIMESTAMPDIFF(YEAR, p.birthday, CURDATE()) as age,
                    CASE 
                        WHEN v.dose_number = 1 THEN 'Day 0 (1st Dose)'
                        WHEN v.dose_number = 2 THEN 'Day 3 (2nd Dose)'
                        WHEN v.dose_number = 3 THEN 'Day 7 (3rd Dose)'
                        WHEN v.dose_number = 4 THEN 'Day 14 (4th Dose)'
                        WHEN v.dose_number = 5 THEN 'Day 21 (5th Dose)'
                        WHEN v.dose_number = 6 THEN 'Day 28 (6th Dose)'
                        ELSE CONCAT('Day ', v.dose_number)
                    END as dose_label,
                    CASE 
                        WHEN v.vaccination_status = 'Completed' THEN 'Completed'
                        WHEN v.next_schedule < CURDATE() AND v.vaccination_status = 'Scheduled' THEN 'Overdue'
                        WHEN v.vaccination_status = 'Scheduled' AND v.next_schedule = CURDATE() THEN 'Today'
                        WHEN v.vaccination_status = 'Scheduled' AND v.next_schedule > CURDATE() THEN 'Pending'
                        ELSE v.vaccination_status
                    END as display_status,
                    CASE 
                        WHEN v.vaccination_status = 'Completed' THEN 1
                        WHEN v.next_schedule < CURDATE() AND v.vaccination_status = 'Scheduled' THEN 2
                        WHEN v.vaccination_status = 'Scheduled' AND v.next_schedule = CURDATE() THEN 3
                        WHEN v.vaccination_status = 'Scheduled' AND v.next_schedule > CURDATE() THEN 4
                        ELSE 5
                    END as status_order
                FROM animal_bite_cases c
                INNER JOIN vaccination_records v ON c.case_id = v.case_id AND c.branch_id = v.branch_id
                INNER JOIN patients p ON c.patient_id = p.patient_id
                LEFT JOIN registry_records r ON c.case_id = r.case_id
                WHERE c.branch_id = ?
                AND v.next_schedule IS NOT NULL
            ";
            
            $params = [$branch_id];
            $types = "s";
            
            if (!empty($date)) {
                $query .= " AND DATE(v.next_schedule) = ?";
                $params[] = $date;
                $types .= "s";
            } else {
                $query .= " AND YEAR(v.next_schedule) = ? AND MONTH(v.next_schedule) = ?";
                $params[] = $year;
                $params[] = $month;
                $types .= "ii";
            }
            
            if ($status != 'all') {
                if ($status == 'completed') {
                    $query .= " AND v.vaccination_status = 'Completed'";
                } elseif ($status == 'overdue') {
                    $query .= " AND v.next_schedule < CURDATE() AND v.vaccination_status = 'Scheduled'";
                } elseif ($status == 'today') {
                    $query .= " AND v.next_schedule = CURDATE() AND v.vaccination_status = 'Scheduled'";
                } elseif ($status == 'pending') {
                    $query .= " AND v.next_schedule > CURDATE() AND v.vaccination_status = 'Scheduled'";
                }
            }
            
            if (!empty($search)) {
                $searchTerm = "%$search%";
                $query .= " AND (p.full_name LIKE ? OR r.registry_number LIKE ? OR c.case_number LIKE ? OR v.vaccine_name LIKE ?)";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $types .= "ssss";
            }
            
            $query .= " ORDER BY status_order ASC, v.next_schedule ASC, p.full_name ASC";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $records = [];
            while ($row = $result->fetch_assoc()) {
                $records[] = $row;
            }
            
            echo json_encode([
                'success' => true,
                'records' => $records,
                'count' => count($records)
            ]);
            break;
            
        case 'mark_completed':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'Invalid request method']);
                break;
            }
            
            $vaccination_id = isset($_POST['vaccination_id']) ? (int)$_POST['vaccination_id'] : 0;
            $case_id = isset($_POST['case_id']) ? (int)$_POST['case_id'] : 0;
            $administered_date = isset($_POST['administered_date']) ? $_POST['administered_date'] : date('Y-m-d');
            $remarks = isset($_POST['remarks']) ? $_POST['remarks'] : '';
            
            if (!$vaccination_id || !$case_id) {
                echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
                break;
            }
            
            $conn->begin_transaction();
            
            try {
                $getVaccineQuery = "SELECT item_id, branch_id, dose_number, patient_id FROM vaccination_records WHERE vaccination_id = ?";
                $stmt = $conn->prepare($getVaccineQuery);
                $stmt->bind_param("i", $vaccination_id);
                $stmt->execute();
                $vaccineResult = $stmt->get_result();
                $vaccineData = $vaccineResult->fetch_assoc();
                
                if (!$vaccineData) {
                    throw new Exception('Vaccination record not found');
                }
                
                $updateVaccination = "
                    UPDATE vaccination_records 
                    SET vaccination_status = 'Completed',
                        date_administered = ?
                    WHERE vaccination_id = ?
                    AND case_id = ?
                ";
                $stmt = $conn->prepare($updateVaccination);
                $stmt->bind_param("sii", $administered_date, $vaccination_id, $case_id);
                $stmt->execute();
                
                $deductStock = "
                    UPDATE inventory_stocks 
                    SET quantity_available = quantity_available - 1,
                        last_updated = NOW()
                    WHERE item_id = ? 
                    AND branch_id = ?
                    AND quantity_available > 0
                ";
                $stmt = $conn->prepare($deductStock);
                $stmt->bind_param("is", $vaccineData['item_id'], $vaccineData['branch_id']);
                $stmt->execute();
                
                if ($stmt->affected_rows == 0) {
                    throw new Exception('Insufficient stock available for this vaccine');
                }
                
                $logTransaction = "
                    INSERT INTO stock_transactions 
                    (item_id, user_id, vaccination_id, branch_id, transaction_type, quantity, remarks, transaction_date)
                    VALUES (?, ?, ?, ?, 'OUT', 1, ?, NOW())
                ";
                $remarkText = "Vaccination completed - Dose " . $vaccineData['dose_number'] . " administered on " . $administered_date;
                $stmt = $conn->prepare($logTransaction);
                $stmt->bind_param("iiiss", 
                    $vaccineData['item_id'],
                    $user_id,
                    $vaccination_id,
                    $vaccineData['branch_id'],
                    $remarkText
                );
                $stmt->execute();
                
                $usageQuery = "
                    INSERT INTO inventory_usage_history 
                    (item_id, branch_id, usage_date, quantity_used, patient_count)
                    VALUES (?, ?, ?, 1, 1)
                ";
                $stmt = $conn->prepare($usageQuery);
                $stmt->bind_param("iss", 
                    $vaccineData['item_id'],
                    $vaccineData['branch_id'],
                    $administered_date
                );
                $stmt->execute();
                
                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Vaccination marked as completed']);
                
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Follow-up Calendar - SmartBiteCare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="sidebar.css">

    <style>
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
            --bg: #F2F2F2;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --gray-100: #f8f9fc;
            --gray-200: #f1f3f5;
            --gray-500: #adb5bd;
            --gray-600: #6c757d;
            --gray-700: #495057;
            --gray-900: #212529;
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            --radius: 12px;
            --transition: all 0.25s ease;
        }

        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', sans-serif;
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
            background: #f0f2f5;
        }

        .topbar {
            background: white;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 35px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .08);
            margin-bottom: 0;
            position: sticky;
            top: 0;
            z-index: 100;
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
            display: flex;
            align-items: center;
            gap: 6px;
        }

        @media (max-width: 991px) {
            .main {
                margin-left: 90px;
            }
        }

        .dashboard-content {
            padding: 30px 35px 50px;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px 24px;
            box-shadow: var(--shadow);
            border-left: 5px solid var(--primary);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .stat-card:hover {
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
        }

        .stat-card .stat-number {
            font-size: 36px;
            font-weight: 700;
            color: var(--primary);
            line-height: 1.2;
        }

        .stat-card .stat-label {
            font-size: 14px;
            font-weight: 600;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .stat-sub {
            font-size: 12px;
            color: #adb5bd;
            margin-top: 2px;
        }

        .stat-card .stat-icon {
            position: absolute;
            right: 15px;
            top: 15px;
            font-size: 32px;
            opacity: 0.15;
        }

        .stat-card.overdue {
            border-left-color: var(--accent);
        }
        .stat-card.overdue .stat-number {
            color: var(--accent);
        }

        .stat-card.pending {
            border-left-color: var(--warning);
        }
        .stat-card.pending .stat-number {
            color: #e6a800;
        }

        .stat-card.completed {
            border-left-color: var(--success);
        }
        .stat-card.completed .stat-number {
            color: var(--success);
        }

        .stat-card.total {
            border-left-color: var(--info);
        }
        .stat-card.total .stat-number {
            color: var(--info);
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 24px;
            margin-bottom: 30px;
        }

        .calendar-wrapper {
            background: white;
            border-radius: 16px;
            padding: 20px 24px 24px;
            box-shadow: var(--shadow);
        }

        .calendar-wrapper .cal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
        }

        .calendar-wrapper .cal-header h5 {
            font-weight: 700;
            color: var(--primary);
            font-size: 18px;
            margin: 0;
        }

        .calendar-wrapper .cal-header .cal-nav {
            display: flex;
            gap: 8px;
        }

        .calendar-wrapper .cal-header .cal-nav button {
            background: none;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #495057;
            font-size: 14px;
            transition: var(--transition);
            cursor: pointer;
        }

        .calendar-wrapper .cal-header .cal-nav button:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .calendar-wrapper .cal-header .cal-nav button#todayBtn {
            width: auto;
            padding: 0 14px;
            font-size: 12px;
            font-weight: 600;
        }

        .calendar-wrapper table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .calendar-wrapper table th {
            text-align: center;
            font-weight: 600;
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 6px 0;
        }

        .calendar-wrapper table td {
            text-align: center;
            padding: 4px 0;
            font-weight: 500;
            color: #212529;
            cursor: default;
        }

        .calendar-wrapper table td .day-cell {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            transition: var(--transition);
            font-size: 14px;
            position: relative;
            cursor: pointer;
        }

        .calendar-wrapper table td .day-cell:hover {
            background: var(--gray-100);
        }

        .calendar-wrapper table td .day-cell.today {
            background: var(--primary);
            color: white;
            font-weight: 700;
        }

        .calendar-wrapper table td .day-cell.has-event {
            background: #eef2ff;
            color: var(--primary);
            font-weight: 600;
        }

        .calendar-wrapper table td .day-cell.has-event.today {
            background: var(--primary);
            color: white;
        }

        .calendar-wrapper table td .day-cell.has-event::after {
            content: '';
            position: absolute;
            bottom: 2px;
            left: 50%;
            transform: translateX(-50%);
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--primary);
        }

        .calendar-wrapper table td .day-cell.has-event.today::after {
            background: white;
        }

        .calendar-wrapper table td .day-cell.has-event.overdue::after {
            background: var(--accent);
        }

        .calendar-wrapper table td .day-cell.other-month {
            color: #ced4da;
        }

        .calendar-wrapper .cal-footer {
            margin-top: 12px;
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: #6c757d;
            border-top: 1px solid #f1f3f5;
            padding-top: 12px;
        }

        .calendar-wrapper .cal-footer span i {
            margin-right: 4px;
        }

        .legend-wrapper {
            background: white;
            border-radius: 16px;
            padding: 20px 24px;
            box-shadow: var(--shadow);
        }

        .legend-wrapper h5 {
            font-weight: 700;
            color: var(--primary);
            font-size: 16px;
            margin-bottom: 16px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 0;
            font-size: 14px;
            color: #212529;
        }

        .legend-item .dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .legend-item .dot.today-dot {
            background: var(--primary);
        }
        .legend-item .dot.event-dot {
            background: #eef2ff;
            border: 2px solid var(--primary);
        }
        .legend-item .dot.overdue-dot {
            background: var(--accent);
        }
        .legend-item .dot.pending-dot {
            background: var(--warning);
        }
        .legend-item .dot.completed-dot {
            background: var(--success);
        }

        .legend-divider {
            border: none;
            border-top: 1px solid #f1f3f5;
            margin: 10px 0 12px;
        }

        .legend-upcoming {
            margin-top: 8px;
            max-height: 200px;
            overflow-y: auto;
        }

        .legend-upcoming .upcoming-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            padding: 4px 0;
            color: var(--gray-700);
            border-bottom: 1px solid #f8f9fc;
        }

        .legend-upcoming .upcoming-item .date-label {
            font-weight: 500;
        }

        .legend-upcoming .upcoming-item .count-badge {
            background: var(--gray-100);
            padding: 0 12px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 12px;
            color: var(--primary);
        }

        .legend-upcoming .no-upcoming {
            font-size: 13px;
            color: #adb5bd;
            text-align: center;
            padding: 20px 0;
        }

        .filter-section {
            background: white;
            border-radius: 16px;
            padding: 16px 24px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }

        .filter-section .filter-group {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-section .filter-group label {
            font-weight: 600;
            font-size: 13px;
            color: #6c757d;
            margin: 0;
        }

        .filter-section .filter-group .form-control,
        .filter-section .filter-group .form-select {
            border-radius: 10px;
            border: 1px solid #e9ecef;
            padding: 8px 14px;
            font-size: 14px;
            min-width: 150px;
        }

        .filter-section .filter-group .form-control:focus,
        .filter-section .filter-group .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.1);
        }

        .filter-section .btn-filter {
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px 24px;
            border-radius: 10px;
            font-weight: 600;
            transition: var(--transition);
            cursor: pointer;
        }

        .filter-section .btn-filter:hover {
            background: #1f2d6b;
        }

        .filter-section .btn-clear {
            background: #f1f3f5;
            color: #6c757d;
            border: none;
            padding: 8px 20px;
            border-radius: 10px;
            font-weight: 600;
            transition: var(--transition);
            cursor: pointer;
        }

        .filter-section .btn-clear:hover {
            background: #e9ecef;
        }

        .table-wrapper {
            background: white;
            border-radius: 16px;
            padding: 20px 24px 24px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }

        .table-wrapper .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
        }

        .table-wrapper .table-header .filter-tabs {
            display: flex;
            gap: 4px;
            background: #f1f3f5;
            border-radius: 10px;
            padding: 3px;
            flex-wrap: wrap;
        }

        .table-wrapper .table-header .filter-tabs .tab-btn {
            border: none;
            background: transparent;
            padding: 6px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #6c757d;
            transition: var(--transition);
            cursor: pointer;
        }

        .table-wrapper .table-header .filter-tabs .tab-btn:hover {
            color: var(--primary);
        }

        .table-wrapper .table-header .filter-tabs .tab-btn.active {
            background: white;
            color: var(--primary);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
        }

        .table-wrapper .table-header .filter-tabs .tab-btn .badge-count {
            display: inline-block;
            background: var(--primary);
            color: white;
            border-radius: 20px;
            padding: 0 8px;
            font-size: 11px;
            font-weight: 700;
            margin-left: 4px;
            line-height: 18px;
        }

        .table-wrapper .table-header .filter-tabs .tab-btn .badge-count.overdue-badge {
            background: var(--accent);
        }
        .table-wrapper .table-header .filter-tabs .tab-btn .badge-count.pending-badge {
            background: var(--warning);
        }
        .table-wrapper .table-header .filter-tabs .tab-btn .badge-count.today-badge {
            background: var(--info);
        }

        .table-wrapper .table-header .record-info {
            font-size: 13px;
            color: #6c757d;
        }

        .table-wrapper .table-header .record-info strong {
            color: var(--gray-900);
        }

        .table-responsive {
            overflow-x: auto;
        }

        .table-wrapper table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .table-wrapper table thead th {
            background: #f8f9fc;
            padding: 12px 14px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #495057;
            border-bottom: 2px solid #e9ecef;
            white-space: nowrap;
            position: sticky;
            top: 0;
        }

        .table-wrapper table tbody td {
            padding: 12px 14px;
            border-bottom: 1px solid #f1f3f5;
            vertical-align: middle;
            color: #212529;
        }

        .table-wrapper table tbody tr:hover {
            background: #f8f9fc;
        }

        .table-wrapper table tbody td .status-badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .status-badge.today-badge {
            background: #cce5ff;
            color: #004085;
        }

        .status-badge.pending-badge {
            background: #fff3cd;
            color: #856404;
        }

        .status-badge.overdue-badge {
            background: #f8d7da;
            color: #721c24;
        }

        .status-badge.completed-badge {
            background: #d4edda;
            color: #155724;
        }

        .table-wrapper table tbody td .btn-action {
            background: var(--primary);
            color: white;
            border: none;
            padding: 4px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            transition: var(--transition);
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin: 2px;
        }

        .table-wrapper table tbody td .btn-action:hover {
            background: #1f2d6b;
            transform: scale(1.02);
        }

        .table-wrapper table tbody td .btn-action.btn-success {
            background: var(--success);
        }

        .table-wrapper table tbody td .btn-action.btn-success:hover {
            background: #1e7e34;
        }

        .no-records {
            text-align: center;
            padding: 40px 20px;
            color: #adb5bd;
        }

        .no-records i {
            font-size: 48px;
            display: block;
            margin-bottom: 12px;
            opacity: 0.4;
        }

        .table-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #f1f3f5;
        }

        .table-footer .pagination-info {
            font-size: 14px;
            color: #6c757d;
        }

        .table-footer .pagination-info strong {
            color: #212529;
        }

        .export-btn {
            background: white;
            border: 2px solid var(--primary);
            color: var(--primary);
            padding: 8px 24px;
            border-radius: 30px;
            font-weight: 700;
            font-size: 14px;
            transition: var(--transition);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .export-btn:hover {
            background: var(--primary);
            color: white;
        }

        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.8);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .loading-overlay.show {
            display: flex;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
            border-left: 5px solid var(--success);
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
            border-left-color: var(--danger);
        }

        .toast-custom .toast-icon {
            font-size: 24px;
            color: var(--success);
        }

        .toast-custom.error .toast-icon {
            color: var(--danger);
        }

        .toast-custom .toast-msg {
            font-size: 14px;
            font-weight: 500;
        }

        .toast-custom .toast-msg small {
            display: block;
            font-weight: 400;
            color: #6c757d;
            font-size: 12px;
        }

        .modal-custom .modal-content {
            border-radius: 16px;
            border: none;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
        }

        .modal-custom .modal-header {
            border-bottom: 1px solid #f1f3f5;
            padding: 20px 24px;
        }

        .modal-custom .modal-header .modal-title {
            font-weight: 700;
            color: var(--primary);
        }

        .modal-custom .modal-body {
            padding: 24px;
        }

        .modal-custom .modal-footer {
            border-top: 1px solid #f1f3f5;
            padding: 16px 24px;
        }

        .modal-custom .form-label {
            font-weight: 600;
            font-size: 14px;
            color: #495057;
        }

        .modal-custom .form-control {
            border-radius: 10px;
            border: 1px solid #e9ecef;
            padding: 10px 14px;
        }

        .modal-custom .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43, 58, 140, 0.1);
        }

        .branch-indicator {
            font-size: 13px;
            color: var(--gray-600);
            background: var(--gray-100);
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-block;
        }

        .active-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
        }

        .active-filters .filter-badge {
            background: #eef2ff;
            color: var(--primary);
            padding: 2px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .active-filters .filter-badge .remove-filter {
            cursor: pointer;
            opacity: 0.6;
        }

        .active-filters .filter-badge .remove-filter:hover {
            opacity: 1;
        }

        @media (max-width: 1200px) {
            .stats-row {
                grid-template-columns: repeat(3, 1fr);
            }
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .dashboard-content {
                padding: 20px 16px 40px;
            }
            .stats-row {
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
            .stat-card {
                padding: 16px;
            }
            .stat-card .stat-number {
                font-size: 28px;
            }
            .stat-card .stat-icon {
                font-size: 24px;
                right: 10px;
                top: 10px;
            }
            .filter-section {
                flex-direction: column;
                align-items: stretch;
                padding: 16px;
            }
            .filter-section .filter-group {
                flex-direction: column;
                align-items: stretch;
            }
            .filter-section .filter-group .form-control,
            .filter-section .filter-group .form-select {
                min-width: 100%;
            }
            .table-wrapper .table-header {
                flex-direction: column;
                align-items: stretch;
            }
            .table-wrapper .table-header .filter-tabs {
                flex-wrap: wrap;
            }
            .table-footer {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            .topbar {
                padding: 0 16px;
                height: 64px;
            }
            .topbar h3 {
                font-size: 20px;
            }
            .calendar-wrapper {
                padding: 16px;
            }
            .legend-wrapper {
                padding: 16px;
            }
            .table-wrapper {
                padding: 16px;
            }
            .export-btn {
                width: 100%;
                justify-content: center;
            }
            .calendar-wrapper table td .day-cell {
                width: 28px;
                height: 28px;
                font-size: 12px;
            }
            .calendar-wrapper .cal-header h5 {
                font-size: 16px;
            }
            .legend-wrapper {
                order: 2;
            }
            .calendar-wrapper {
                order: 1;
            }
        }

        @media (max-width: 480px) {
            .stats-row {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
            .stat-card .stat-number {
                font-size: 22px;
            }
            .stat-card .stat-label {
                font-size: 11px;
            }
            .calendar-wrapper table td .day-cell {
                width: 24px;
                height: 24px;
                font-size: 11px;
            }
            .table-wrapper table thead th,
            .table-wrapper table tbody td {
                padding: 8px 10px;
                font-size: 12px;
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
            <div class="system-name">Smart Bite Care</div>
        </div>

        <nav class="nav-menu">
            <ul>
                <li><a href="AdminStaff_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
                <li><a class="active" href="AdminStaff_Calendar.php"><i class="bi bi-calendar-fill"></i><span>Calendar</span></a></li>
                <li><a href="AdminStaff_PatientRecord.php"><i class="bi bi-people-fill"></i><span>Patient Record Management</span></a></li>
                <li><a href="AdminStaff_PhilhealthStatus.php"><i class="bi bi-check2-all"></i><span>PhilHealth Patient Status</span></a></li>
                <li><a href="AdminStaff_MedicalDocuments.php"><i class="bi bi-file-earmark-ruled"></i><span>Medical Documents</span></a></li>
                <li><a href="AdminStaff_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
            </ul>
        </nav>

        <div class="logout">
            <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main">
        <!-- Top Header -->
        <div class="topbar">
            <h3>Follow-up Records <span style="font-size:16px; color:#6c757d; font-weight:400; margin-left:8px;"> <?php echo htmlspecialchars($branch_name); ?> </span> </h3>
            
            <div class="profile">
                <i class="bi bi-person-circle"></i>
                <?php echo htmlspecialchars($username); ?>
                <span style="font-size:12px; color:#adb5bd; font-weight:400; margin-left:4px;">| Admin Staff</span>
            </div>
        </div>

        <div class="dashboard-content">
            <!-- STATS ROW -->
            <div class="stats-row">
                <div class="stat-card total" onclick="applyStatusFilter('all')">
                    <div class="stat-number" id="totalCount"><?php echo $totalCount; ?></div>
                    <div class="stat-label">Total Records</div>
                    <div class="stat-sub">All follow-ups</div>
                    <i class="bi bi-list-check stat-icon"></i>
                </div>

                <div class="stat-card" onclick="applyStatusFilter('today')">
                    <div class="stat-number" id="todayCount"><?php echo $todayCount; ?></div>
                    <div class="stat-label">Today's Follow-ups</div>
                    <div class="stat-sub">Scheduled for today</div>
                    <i class="bi bi-calendar-day stat-icon"></i>
                </div>

                <div class="stat-card overdue" onclick="applyStatusFilter('overdue')">
                    <div class="stat-number" id="overdueCount"><?php echo $overdueCount; ?></div>
                    <div class="stat-label">Overdue</div>
                    <div class="stat-sub">Missed schedule</div>
                    <i class="bi bi-exclamation-triangle-fill stat-icon"></i>
                </div>

                <div class="stat-card pending" onclick="applyStatusFilter('pending')">
                    <div class="stat-number" id="pendingCount"><?php echo $pendingCount; ?></div>
                    <div class="stat-label">Pending</div>
                    <div class="stat-sub">Yet to be administered</div>
                    <i class="bi bi-clock-history stat-icon"></i>
                </div>

                <div class="stat-card completed" onclick="applyStatusFilter('completed')">
                    <div class="stat-number" id="completedCount"><?php echo $completedCount; ?></div>
                    <div class="stat-label">Completed</div>
                    <div class="stat-sub">Administered</div>
                    <i class="bi bi-check-circle-fill stat-icon"></i>
                </div>
            </div>

            <!-- CALENDAR + LEGEND -->
            <div class="dashboard-grid">
                <!-- Calendar -->
                <div class="calendar-wrapper">
                    <div class="cal-header">
                        <h5 id="calendarTitle"><?php echo date('F Y', strtotime("$currentYear-$currentMonth-01")); ?></h5>
                        <div class="cal-nav">
                            <button id="prevMonthBtn"><i class="bi bi-chevron-left"></i></button>
                            <button id="nextMonthBtn"><i class="bi bi-chevron-right"></i></button>
                            <button id="todayBtn">Today</button>
                        </div>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th>SUN</th>
                                <th>MON</th>
                                <th>TUE</th>
                                <th>WED</th>
                                <th>THU</th>
                                <th>FRI</th>
                                <th>SAT</th>
                            </tr>
                        </thead>
                        <tbody id="calendarBody"></tbody>
                    </table>

                    <div class="cal-footer">
                        <span><i class="bi bi-calendar-event"></i> <span id="eventsCount"><?php echo $totalEvents; ?></span> events this month</span>
                        <span><i class="bi bi-circle-fill" style="color:var(--primary);font-size:10px;"></i> Today</span>
                    </div>
                </div>

                <!-- Legend -->
                <div class="legend-wrapper" id="legendWrapper">
                    <h5>Quick Filters</h5>

                    <div class="legend-item" onclick="applyStatusFilter('all')" style="cursor:pointer;">
                        <span class="dot" style="background:var(--info);"></span>
                        All Records (<span id="legendTotal"><?php echo $totalCount; ?></span>)
                    </div>
                    <div class="legend-item" onclick="applyStatusFilter('today')" style="cursor:pointer;">
                        <span class="dot today-dot"></span>
                        Today (<span id="legendToday"><?php echo $todayCount; ?></span>)
                    </div>
                    <div class="legend-item" onclick="applyStatusFilter('overdue')" style="cursor:pointer;">
                        <span class="dot overdue-dot"></span>
                        Overdue (<span id="legendOverdue"><?php echo $overdueCount; ?></span>)
                    </div>
                    <div class="legend-item" onclick="applyStatusFilter('pending')" style="cursor:pointer;">
                        <span class="dot pending-dot"></span>
                        Pending (<span id="legendPending"><?php echo $pendingCount; ?></span>)
                    </div>
                    <div class="legend-item" onclick="applyStatusFilter('completed')" style="cursor:pointer;">
                        <span class="dot completed-dot"></span>
                        Completed (<span id="legendCompleted"><?php echo $completedCount; ?></span>)
                    </div>

                    <hr class="legend-divider">
                    <div style="font-size:13px;color:#6c757d;">
                        <i class="bi bi-info-circle"></i> Click a stat card or legend item to filter
                    </div>

                    <hr class="legend-divider">
                    <div class="legend-upcoming" id="upcomingList">
                        <div style="font-size:13px;font-weight:600;color:var(--gray-700);margin-bottom:6px;">
                            <i class="bi bi-clock"></i> Upcoming (30 days)
                        </div>
                        <?php if (empty($upcomingData)): ?>
                        <div class="no-upcoming">No upcoming follow-ups</div>
                        <?php else: ?>
                        <?php $displayCount = 0; ?>
                        <?php foreach ($upcomingData as $date => $count): ?>
                        <?php if ($displayCount++ >= 8) break; ?>
                        <div class="upcoming-item">
                            <span class="date-label"><?php echo date('M d', strtotime($date)); ?></span>
                            <span class="count-badge"><?php echo $count; ?> patient<?php echo $count > 1 ? 's' : ''; ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (count($upcomingData) > 8): ?>
                        <div style="text-align:center;font-size:12px;color:#adb5bd;padding-top:4px;">
                            +<?php echo count($upcomingData) - 8; ?> more days
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- FILTER SECTION -->
            <div class="filter-section">
                <div class="filter-group">
                    <label><i class="bi bi-calendar3"></i> Month</label>
                    <select class="form-select" id="filterMonth" style="min-width:130px;">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $m == $currentMonth ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label><i class="bi bi-calendar2"></i> Year</label>
                    <select class="form-select" id="filterYear" style="min-width:100px;">
                        <?php for ($y = date('Y') - 2; $y <= date('Y') + 1; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y == $currentYear ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label><i class="bi bi-calendar-day"></i> Specific Date</label>
                    <input type="date" class="form-control" id="filterDate" value="<?php echo $selectedDate; ?>" style="min-width:160px;">
                </div>

                <div class="filter-group">
                    <label><i class="bi bi-search"></i> Search</label>
                    <input type="text" class="form-control" id="filterSearch" placeholder="Patient, Case, Vaccine..." value="<?php echo htmlspecialchars($searchQuery); ?>" style="min-width:180px;">
                </div>

                <button class="btn-filter" id="applyFiltersBtn"><i class="bi bi-funnel"></i> Apply</button>
                <button class="btn-clear" id="clearFiltersBtn"><i class="bi bi-x-circle"></i> Clear</button>

                <div class="active-filters" id="activeFilters">
                    <?php if (!empty($selectedDate)): ?>
                    <span class="filter-badge">
                        <i class="bi bi-calendar-day"></i> <?php echo date('M d, Y', strtotime($selectedDate)); ?>
                        <span class="remove-filter" onclick="removeFilter('date')">&times;</span>
                    </span>
                    <?php endif; ?>
                    <?php if ($filterStatus != 'all'): ?>
                    <span class="filter-badge">
                        <i class="bi bi-funnel"></i> <?php echo ucfirst($filterStatus); ?>
                        <span class="remove-filter" onclick="removeFilter('status')">&times;</span>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($searchQuery)): ?>
                    <span class="filter-badge">
                        <i class="bi bi-search"></i> "<?php echo htmlspecialchars($searchQuery); ?>"
                        <span class="remove-filter" onclick="removeFilter('search')">&times;</span>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- PATIENT TABLE -->
            <div class="table-wrapper">
                <div class="table-header">
                    <div class="filter-tabs">
                        <button class="tab-btn <?php echo $filterStatus == 'all' ? 'active' : ''; ?>" data-filter="all" onclick="applyStatusFilter('all')">
                            All <span class="badge-count" id="filterAllCount"><?php echo count($followUpRecords); ?></span>
                        </button>
                        <button class="tab-btn <?php echo $filterStatus == 'today' ? 'active' : ''; ?>" data-filter="today" onclick="applyStatusFilter('today')">
                            Today <span class="badge-count today-badge" id="filterTodayCount"><?php echo $todayCount; ?></span>
                        </button>
                        <button class="tab-btn <?php echo $filterStatus == 'pending' ? 'active' : ''; ?>" data-filter="pending" onclick="applyStatusFilter('pending')">
                            Pending <span class="badge-count pending-badge" id="filterPendingCount"><?php echo $pendingCount; ?></span>
                        </button>
                        <button class="tab-btn <?php echo $filterStatus == 'overdue' ? 'active' : ''; ?>" data-filter="overdue" onclick="applyStatusFilter('overdue')">
                            Overdue <span class="badge-count overdue-badge" id="filterOverdueCount"><?php echo $overdueCount; ?></span>
                        </button>
                        <button class="tab-btn <?php echo $filterStatus == 'completed' ? 'active' : ''; ?>" data-filter="completed" onclick="applyStatusFilter('completed')">
                            Completed <span class="badge-count" style="background:var(--success);" id="filterCompletedCount"><?php echo $completedCount; ?></span>
                        </button>
                    </div>
                    <div class="record-info">
                        <strong id="recordCountDisplay"><?php echo count($followUpRecords); ?></strong> records found
                        <span class="branch-indicator"><?php echo htmlspecialchars($branch_name); ?></span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Case ID</th>
                                <th>Patient Name</th>
                                <th>Vaccine</th>
                                <th>Dose</th>
                                <th>Scheduled</th>
                                <th>Administered</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="recordsTableBody">
                            <?php if (empty($followUpRecords)): ?>
                            <tr>
                                <td colspan="9">
                                    <div class="no-records">
                                        <i class="bi bi-inbox"></i>
                                        <p>No follow-up records found.</p>
                                        <small class="text-muted">Try adjusting your filters or check back later.</small>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php $counter = 1; ?>
                            <?php foreach ($followUpRecords as $record): ?>
                            <?php 
                                $statusClass = 'pending-badge';
                                $statusLabel = $record['display_status'] ?? 'Pending';
                                if ($statusLabel == 'Completed') $statusClass = 'completed-badge';
                                elseif ($statusLabel == 'Overdue') $statusClass = 'overdue-badge';
                                elseif ($statusLabel == 'Today') $statusClass = 'today-badge';
                                
                                $patientInfo = $record['patient_name'] . ' (' . ($record['age'] ?? 'N/A') . 'y)';
                            ?>
                            <tr data-status="<?php echo strtolower($statusLabel); ?>" data-case-id="<?php echo $record['case_id']; ?>" data-vaccination-id="<?php echo $record['vaccination_id']; ?>">
                                <td><?php echo $counter++; ?></td>
                                <td><strong><?php echo htmlspecialchars($record['case_no'] ?? $record['case_number'] ?? 'N/A'); ?></strong></td>
                                <td>
                                    <?php echo htmlspecialchars($patientInfo); ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($record['animal_type'] ?? ''); ?></small>
                                </td>
                                <td><span style="font-size:13px;"><?php echo htmlspecialchars($record['vaccine_name'] ?? 'N/A'); ?></span></td>
                                <td><?php echo htmlspecialchars($record['dose_label'] ?? 'Dose ' . $record['dose_number']); ?></td>
                                <td><?php echo $record['next_schedule'] ? date('M d, Y', strtotime($record['next_schedule'])) : 'N/A'; ?></td>
                                <td><?php echo $record['date_administered'] ? date('M d, Y', strtotime($record['date_administered'])) : '—'; ?></td>
                                <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                                <td>
                                    <a href="AdminStaff_PatientRecord.php?action=view&case_id=<?php echo $record['case_id']; ?>" class="btn-action" title="View patient record">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                    <?php if ($statusLabel != 'Completed' && $statusLabel != 'Overdue'): ?>
                                    <button class="btn-action btn-success mark-complete-btn" 
                                            data-vaccination-id="<?php echo $record['vaccination_id']; ?>"
                                            data-case-id="<?php echo $record['case_id']; ?>"
                                            data-patient-name="<?php echo htmlspecialchars($record['patient_name']); ?>"
                                            data-vaccine-name="<?php echo htmlspecialchars($record['vaccine_name'] ?? 'Vaccine'); ?>"
                                            data-dose-label="<?php echo htmlspecialchars($record['dose_label'] ?? 'Dose ' . $record['dose_number']); ?>"
                                            title="Mark this dose as completed">
                                        <i class="bi bi-check-lg"></i> Complete
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Table Footer -->
                <div class="table-footer">
                    <div class="pagination-info">
                        Showing <strong id="recordCountDisplayFooter"><?php echo count($followUpRecords); ?></strong> of <strong id="totalRecordCount"><?php echo $totalCount; ?></strong> records
                    </div>
                    <div>
                        <button class="export-btn" id="exportBtn">
                            <i class="bi bi-download"></i> Export
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container-custom" id="toastContainer"></div>

    <!-- Complete Modal -->
    <div class="modal fade modal-custom" id="completeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-check-circle-fill" style="color:var(--success);"></i> Mark Dose as Completed</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Confirm that you have administered the vaccine dose for:</p>
                    <div class="alert alert-info">
                        <strong id="modalPatientName">Patient Name</strong><br>
                        <span id="modalVaccineInfo">Vaccine: Dose</span>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Date Administered</label>
                        <input type="date" class="form-control" id="modalAdministeredDate" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Remarks (Optional)</label>
                        <input type="text" class="form-control" id="modalRemarks" placeholder="e.g., No adverse reaction observed">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="modalConfirmComplete">
                        <i class="bi bi-check-lg"></i> Confirm Completed
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    // ----------------------------------------------------------------
    // CONFIGURATION
    // ----------------------------------------------------------------
    const BRANCH_ID = '<?php echo $branch_id; ?>';
    const CURRENT_USER_ID = <?php echo $user_id; ?>;
    const TODAY_DATE = '<?php echo date('Y-m-d'); ?>';
    
    let currentMonth = <?php echo $currentMonth; ?>;
    let currentYear = <?php echo $currentYear; ?>;
    let currentStatus = '<?php echo $filterStatus; ?>';
    let currentDate = '<?php echo $selectedDate; ?>';
    let currentSearch = '<?php echo htmlspecialchars($searchQuery); ?>';
    
    const calendarData = <?php echo json_encode($calendarData); ?>;

    // ----------------------------------------------------------------
    // TOAST NOTIFICATIONS
    // ----------------------------------------------------------------
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
        }, 4000);
    }

    // ----------------------------------------------------------------
    // LOADING OVERLAY
    // ----------------------------------------------------------------
    function showLoading() {
        document.getElementById('loadingOverlay').classList.add('show');
    }

    function hideLoading() {
        document.getElementById('loadingOverlay').classList.remove('show');
    }

    // ----------------------------------------------------------------
    // RENDER CALENDAR
    // ----------------------------------------------------------------
    function renderCalendar(month, year) {
        const firstDay = new Date(year, month - 1, 1).getDay();
        const daysInMonth = new Date(year, month, 0).getDate();
        const today = new Date();
        const todayStr = today.getFullYear() + '-' + 
            String(today.getMonth() + 1).padStart(2, '0') + '-' + 
            String(today.getDate()).padStart(2, '0');

        const calData = {};
        Object.keys(calendarData).forEach(date => {
            const d = new Date(date);
            if (d.getMonth() === month - 1 && d.getFullYear() === year) {
                calData[date] = calendarData[date];
            }
        });

        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                            'July', 'August', 'September', 'October', 'November', 'December'];
        document.getElementById('calendarTitle').textContent = monthNames[month - 1] + ' ' + year;

        let html = '';
        let date = 1;
        let totalEvents = 0;

        for (let i = 0; i < 6; i++) {
            html += '<tr>';
            for (let j = 0; j < 7; j++) {
                if (i === 0 && j < firstDay) {
                    const prevMonthDays = new Date(year, month - 1, 0).getDate();
                    const prevDate = prevMonthDays - (firstDay - j) + 1;
                    const prevMonth = month === 1 ? 12 : month - 1;
                    const prevYear = month === 1 ? year - 1 : year;
                    const dateStr = prevYear + '-' + 
                        String(prevMonth).padStart(2, '0') + '-' + 
                        String(prevDate).padStart(2, '0');
                    const hasEvent = calData[dateStr] ? 'has-event' : '';
                    html += `<td><span class="day-cell other-month ${hasEvent}">${prevDate}</span></td>`;
                } else if (date > daysInMonth) {
                    const nextDate = date - daysInMonth;
                    const nextMonth = month === 12 ? 1 : month + 1;
                    const nextYear = month === 12 ? year + 1 : year;
                    const dateStr = nextYear + '-' + 
                        String(nextMonth).padStart(2, '0') + '-' + 
                        String(nextDate).padStart(2, '0');
                    const hasEvent = calData[dateStr] ? 'has-event' : '';
                    html += `<td><span class="day-cell other-month ${hasEvent}">${nextDate}</span></td>`;
                    date++;
                } else {
                    const dateStr = year + '-' + 
                        String(month).padStart(2, '0') + '-' + 
                        String(date).padStart(2, '0');
                    const isToday = dateStr === todayStr;
                    const hasEvent = calData[dateStr] ? 'has-event' : '';
                    const isOverdue = hasEvent && dateStr < todayStr;
                    const todayClass = isToday ? 'today' : '';
                    const eventClass = hasEvent ? 'has-event' : '';
                    const overdueClass = isOverdue ? 'overdue' : '';
                    const clickable = hasEvent ? `style="cursor:pointer;" onclick="goToDate('${dateStr}')"` : '';
                    
                    html += `<td><span class="day-cell ${todayClass} ${eventClass} ${overdueClass}" ${clickable}>${date}</span></td>`;
                    
                    if (hasEvent) {
                        totalEvents += calData[dateStr].count || 1;
                    }
                    date++;
                }
            }
            html += '</tr>';
            if (date > daysInMonth) break;
        }

        document.getElementById('calendarBody').innerHTML = html;
        document.getElementById('eventsCount').textContent = totalEvents;
    }

    // ----------------------------------------------------------------
    // GO TO DATE
    // ----------------------------------------------------------------
    function goToDate(dateStr) {
        document.getElementById('filterDate').value = dateStr;
        applyFilters();
    }

    // ----------------------------------------------------------------
    // APPLY STATUS FILTER
    // ----------------------------------------------------------------
    function applyStatusFilter(status) {
        currentStatus = status;
        const url = new URL(window.location.href);
        url.searchParams.set('status', status);
        if (status == 'all') {
            url.searchParams.delete('status');
        }
        window.location.href = url.toString();
    }

    // ----------------------------------------------------------------
    // APPLY FILTERS
    // ----------------------------------------------------------------
    function applyFilters() {
        showLoading();
        const month = document.getElementById('filterMonth').value;
        const year = document.getElementById('filterYear').value;
        const date = document.getElementById('filterDate').value;
        const search = document.getElementById('filterSearch').value;
        const status = currentStatus;

        const url = new URL(window.location.href);
        url.searchParams.set('month', month);
        url.searchParams.set('year', year);
        if (date) url.searchParams.set('date', date);
        else url.searchParams.delete('date');
        if (search) url.searchParams.set('search', search);
        else url.searchParams.delete('search');
        if (status && status != 'all') url.searchParams.set('status', status);
        else url.searchParams.delete('status');
        
        window.location.href = url.toString();
    }

    // ----------------------------------------------------------------
    // REMOVE FILTER
    // ----------------------------------------------------------------
    function removeFilter(filterType) {
        if (filterType == 'date') {
            document.getElementById('filterDate').value = '';
        } else if (filterType == 'status') {
            currentStatus = 'all';
        } else if (filterType == 'search') {
            document.getElementById('filterSearch').value = '';
        }
        applyFilters();
    }

    // ----------------------------------------------------------------
    // FETCH RECORDS (AJAX)
    // ----------------------------------------------------------------
    function fetchRecords() {
        showLoading();
        
        const month = document.getElementById('filterMonth')?.value || currentMonth;
        const year = document.getElementById('filterYear')?.value || currentYear;
        const date = document.getElementById('filterDate')?.value || '';
        const search = document.getElementById('filterSearch')?.value || '';
        const status = currentStatus;

        let url = window.location.pathname + '?ajax_action=get_records';
        url += '&month=' + month + '&year=' + year;
        if (date) url += '&date=' + date;
        if (search) url += '&search=' + encodeURIComponent(search);
        if (status && status != 'all') url += '&status=' + status;

        fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                updateTable(data.records);
                document.getElementById('recordCountDisplay').textContent = data.count;
                document.getElementById('recordCountDisplayFooter').textContent = data.count;
            } else {
                showToast('Error', 'Failed to load records', true);
            }
        })
        .catch(error => {
            hideLoading();
            showToast('Error', 'Network error occurred', true);
            console.error('Fetch error:', error);
        });
    }

    // ----------------------------------------------------------------
    // UPDATE TABLE
    // ----------------------------------------------------------------
    function updateTable(records) {
        const tbody = document.getElementById('recordsTableBody');
        
        if (!records || records.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="9">
                        <div class="no-records">
                            <i class="bi bi-inbox"></i>
                            <p>No follow-up records found.</p>
                            <small class="text-muted">Try adjusting your filters or check back later.</small>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        let html = '';
        let counter = 1;

        records.forEach(record => {
            const statusClass = record.display_status === 'Completed' ? 'completed-badge' :
                               record.display_status === 'Overdue' ? 'overdue-badge' :
                               record.display_status === 'Today' ? 'today-badge' : 'pending-badge';
            
            const patientInfo = record.patient_name + ' (' + (record.age || 'N/A') + 'y)';

            html += `
                <tr data-status="${record.display_status.toLowerCase()}" data-case-id="${record.case_id}" data-vaccination-id="${record.vaccination_id}">
                    <td>${counter++}</td>
                    <td><strong>${record.case_no || record.case_number || 'N/A'}</strong></td>
                    <td>
                        ${patientInfo}
                        <br><small class="text-muted">${record.animal_type || ''}</small>
                    </td>
                    <td><span style="font-size:13px;">${record.vaccine_name || 'N/A'}</span></td>
                    <td>${record.dose_label || 'Dose ' + record.dose_number}</td>
                    <td>${record.next_schedule ? new Date(record.next_schedule).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A'}</td>
                    <td>${record.date_administered ? new Date(record.date_administered).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—'}</td>
                    <td><span class="status-badge ${statusClass}">${record.display_status}</span></td>
                    <td>
                        <a href="AdminStaff_PatientRecord.php?action=view&case_id=${record.case_id}" class="btn-action" title="View patient record">
                            <i class="bi bi-eye"></i> View
                        </a>
                        ${record.display_status !== 'Completed' && record.display_status !== 'Overdue' ? `
                        <button class="btn-action btn-success mark-complete-btn" 
                                data-vaccination-id="${record.vaccination_id}"
                                data-case-id="${record.case_id}"
                                data-patient-name="${record.patient_name}"
                                data-vaccine-name="${record.vaccine_name || 'Vaccine'}"
                                data-dose-label="${record.dose_label || 'Dose ' + record.dose_number}"
                                title="Mark this dose as completed">
                            <i class="bi bi-check-lg"></i> Complete
                        </button>` : ''}
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
        bindMarkCompleteButtons();
    }

    // ----------------------------------------------------------------
    // BIND MARK COMPLETE BUTTONS
    // ----------------------------------------------------------------
    function bindMarkCompleteButtons() {
        const completeModal = new bootstrap.Modal(document.getElementById('completeModal'));
        let currentVaccinationId = null;
        let currentCaseId = null;

        document.querySelectorAll('.mark-complete-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                currentVaccinationId = this.dataset.vaccinationId;
                currentCaseId = this.dataset.caseId;
                const patientName = this.dataset.patientName;
                const vaccineName = this.dataset.vaccineName || 'Vaccine';
                const doseLabel = this.dataset.doseLabel || 'Dose';

                document.getElementById('modalPatientName').textContent = patientName;
                document.getElementById('modalVaccineInfo').textContent = vaccineName + ' - ' + doseLabel;
                document.getElementById('modalAdministeredDate').value = TODAY_DATE;
                document.getElementById('modalRemarks').value = '';

                completeModal.show();
            });
        });

        document.getElementById('modalConfirmComplete').addEventListener('click', function() {
            if (!currentVaccinationId || !currentCaseId) {
                showToast('Error', 'Missing vaccination information', true);
                return;
            }

            const administeredDate = document.getElementById('modalAdministeredDate').value;
            const remarks = document.getElementById('modalRemarks').value;

            showLoading();

            const formData = new FormData();
            formData.append('vaccination_id', currentVaccinationId);
            formData.append('case_id', currentCaseId);
            formData.append('administered_date', administeredDate);
            formData.append('remarks', remarks);

            fetch(window.location.pathname + '?ajax_action=mark_completed', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                completeModal.hide();
                
                if (data.success) {
                    showToast('Success', 'Vaccination marked as completed');
                    setTimeout(() => location.reload(), 500);
                } else {
                    showToast('Error', data.error || 'Failed to mark as completed', true);
                }
            })
            .catch(error => {
                hideLoading();
                completeModal.hide();
                showToast('Error', 'Network error occurred', true);
                console.error('Complete error:', error);
            });
        });
    }

    // ----------------------------------------------------------------
    // REFRESH STATS
    // ----------------------------------------------------------------
    function refreshStats() {
        fetch(window.location.pathname + '?ajax_action=get_stats', {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('totalCount').textContent = data.total || 0;
                document.getElementById('todayCount').textContent = data.today || 0;
                document.getElementById('overdueCount').textContent = data.overdue || 0;
                document.getElementById('pendingCount').textContent = data.pending || 0;
                document.getElementById('completedCount').textContent = data.completed || 0;
                
                document.getElementById('legendTotal').textContent = data.total || 0;
                document.getElementById('legendToday').textContent = data.today || 0;
                document.getElementById('legendOverdue').textContent = data.overdue || 0;
                document.getElementById('legendPending').textContent = data.pending || 0;
                document.getElementById('legendCompleted').textContent = data.completed || 0;
                
                document.getElementById('filterTodayCount').textContent = data.today || 0;
                document.getElementById('filterPendingCount').textContent = data.pending || 0;
                document.getElementById('filterOverdueCount').textContent = data.overdue || 0;
                document.getElementById('filterCompletedCount').textContent = data.completed || 0;
                document.getElementById('totalRecordCount').textContent = data.total || 0;
            }
        })
        .catch(error => console.error('Stats refresh error:', error));
    }

    // ----------------------------------------------------------------
    // REFRESH CALENDAR
    // ----------------------------------------------------------------
    function refreshCalendar() {
        const month = document.getElementById('filterMonth')?.value || currentMonth;
        const year = document.getElementById('filterYear')?.value || currentYear;
        
        fetch(window.location.pathname + '?ajax_action=get_calendar&month=' + month + '&year=' + year, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Object.keys(data.data).forEach(date => {
                    calendarData[date] = data.data[date];
                });
                renderCalendar(parseInt(month), parseInt(year));
            }
        })
        .catch(error => console.error('Calendar refresh error:', error));
    }

    // ----------------------------------------------------------------
    // EVENT LISTENERS
    // ----------------------------------------------------------------
    document.getElementById('applyFiltersBtn').addEventListener('click', applyFilters);
    document.getElementById('clearFiltersBtn').addEventListener('click', function() {
        document.getElementById('filterDate').value = '';
        document.getElementById('filterSearch').value = '';
        currentStatus = 'all';
        const url = new URL(window.location.href);
        url.searchParams.delete('date');
        url.searchParams.delete('search');
        url.searchParams.delete('status');
        window.location.href = url.toString();
    });

    document.getElementById('prevMonthBtn').addEventListener('click', function() {
        let month = parseInt(document.getElementById('filterMonth').value);
        let year = parseInt(document.getElementById('filterYear').value);
        if (month === 1) { month = 12; year--; }
        else { month--; }
        document.getElementById('filterMonth').value = month;
        document.getElementById('filterYear').value = year;
        applyFilters();
    });

    document.getElementById('nextMonthBtn').addEventListener('click', function() {
        let month = parseInt(document.getElementById('filterMonth').value);
        let year = parseInt(document.getElementById('filterYear').value);
        if (month === 12) { month = 1; year++; }
        else { month++; }
        document.getElementById('filterMonth').value = month;
        document.getElementById('filterYear').value = year;
        applyFilters();
    });

    document.getElementById('todayBtn').addEventListener('click', function() {
        document.getElementById('filterDate').value = TODAY_DATE;
        applyFilters();
    });

    document.getElementById('exportBtn').addEventListener('click', function() {
        const date = document.getElementById('filterDate').value;
        const status = currentStatus;
        const url = window.location.pathname + '?export=true';
        const params = [];
        if (date) params.push('date=' + date);
        if (status && status != 'all') params.push('status=' + status);
        window.location.href = url + (params.length ? '&' + params.join('&') : '');
    });

    // Press Enter to apply filters
    document.getElementById('filterSearch').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') applyFilters();
    });
    document.getElementById('filterDate').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') applyFilters();
    });

    // ----------------------------------------------------------------
    // AUTO-REFRESH
    // ----------------------------------------------------------------
    setInterval(() => {
        if (!document.hidden) {
            refreshStats();
            refreshCalendar();
        }
    }, 60000);

    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            refreshStats();
            refreshCalendar();
        }
    });

    // ----------------------------------------------------------------
    // INITIAL RENDER
    // ----------------------------------------------------------------
    renderCalendar(currentMonth, currentYear);
    bindMarkCompleteButtons();

    console.log('Follow-up Records loaded successfully');
    console.log('Branch:', '<?php echo htmlspecialchars($branch_name); ?>');
    console.log('Total records:', '<?php echo $totalCount; ?>');
    </script>
</body>
</html>