<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
require_once 'sources/db_connect.php';
require_once 'sources/notification_helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || (int)$_SESSION['role_id'] !== 4) {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['admin_archived_patients_csrf'])) {
    $_SESSION['admin_archived_patients_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['admin_archived_patients_csrf'];

$logged_user_id = (int)$_SESSION['user_id'];
$logged_branch_id = null;
$branch_name = '';
$logged_username = 'Admin Staff';
$notification_count = 0;

$stmt = $conn->prepare("
    SELECT u.branch_id, u.username, b.branch_name
    FROM users u
    LEFT JOIN branches b ON u.branch_id = b.branch_id
    WHERE u.user_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $logged_user_id);
$stmt->execute();
$userData = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($userData) {
    $logged_branch_id = $userData['branch_id'];
    $branch_name = $userData['branch_name'] ?? 'Unknown Branch';
    $logged_username = $userData['username'] ?? 'Admin Staff';
}

if (!$logged_branch_id) {
    $branch_name = 'No Branch Assigned';
}

try {
    $notification_count = getAdminStaffNotificationCount($conn, (string)$logged_branch_id);
} catch (Throwable $e) {
    $notification_count = 0;
}

function jsonResponse(array $data, int $code = 200): void {
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function auditLog(mysqli $conn, int $userId, string $branchId, string $action, string $module = 'Patient Record'): void {
    try {
        $stmt = $conn->prepare("
            INSERT INTO audit_logs (user_id, branch_id, action, module)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("isss", $userId, $branchId, $action, $module);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Archived Patients audit log failed: ' . $e->getMessage());
    }
}

function dbToFrontDate(?string $date): string {
    if (empty($date)) return '';
    $dt = DateTime::createFromFormat('Y-m-d', substr($date, 0, 10));
    return $dt ? $dt->format('m/d/Y') : '';
}

function restoreCase(mysqli $conn, int $caseId, int $userId, string $branchId): array {
    $conn->begin_transaction();

    try {
        /*
         * The current SmartBiteCare archive design is a SOFT ARCHIVE:
         * the original rows remain in the main tables with is_archived = 1,
         * while a snapshot is also stored in *_archive tables.
         *
         * Therefore restore is performed by:
         * 1. validating the archived case;
         * 2. unarchiving the original main-table rows;
         * 3. deleting the temporary archive snapshots so the same case can
         *    safely be archived again later;
         * 4. keeping the audit log as the permanent history of the action.
         */

        $stmt = $conn->prepare("
            SELECT
                c.case_id,
                c.case_number,
                c.patient_id,
                c.branch_id,
                p.full_name
            FROM animal_bite_cases c
            INNER JOIN patients p
                ON p.patient_id = c.patient_id
               AND p.branch_id = c.branch_id
            WHERE c.case_id = ?
              AND c.branch_id = ?
              AND c.is_archived = 1
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param("is", $caseId, $branchId);
        $stmt->execute();
        $case = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$case) {
            throw new Exception('Archived patient record not found, or it has already been restored.');
        }

        $patientId = (int)$case['patient_id'];
        $caseNumber = (string)$case['case_number'];
        $patientName = (string)$case['full_name'];

        // Collect registry IDs before any restore/delete operations.
        $registryIds = [];
        $stmt = $conn->prepare("
            SELECT registry_id
            FROM registry_records
            WHERE case_id = ?
            FOR UPDATE
        ");
        $stmt->bind_param("i", $caseId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $registryIds[] = (int)$row['registry_id'];
        }
        $stmt->close();

        // Restore child records first, then the parent case/patient.
        $stmt = $conn->prepare("
            UPDATE registry_vaccination_doses
            SET is_archived = 0,
                archived_at = NULL,
                archived_by = NULL
            WHERE registry_id IN (
                SELECT registry_id
                FROM registry_records
                WHERE case_id = ?
            )
        ");
        $stmt->bind_param("i", $caseId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to restore registry vaccination doses.');
        }
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE registry_patients
            SET is_archived = 0,
                archived_at = NULL,
                archived_by = NULL
            WHERE case_id = ?
        ");
        $stmt->bind_param("i", $caseId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to restore registry patient information.');
        }
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE vaccination_records
            SET is_archived = 0,
                archived_at = NULL,
                archived_by = NULL
            WHERE case_id = ?
              AND branch_id = ?
        ");
        $stmt->bind_param("is", $caseId, $branchId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to restore vaccination records.');
        }
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE philhealth_records
            SET is_archived = 0,
                archived_at = NULL,
                archived_by = NULL
            WHERE case_id = ?
        ");
        $stmt->bind_param("i", $caseId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to restore PhilHealth information.');
        }
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE registry_records
            SET is_archived = 0,
                archived_at = NULL,
                archived_by = NULL
            WHERE case_id = ?
        ");
        $stmt->bind_param("i", $caseId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to restore registry records.');
        }
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE animal_bite_cases
            SET is_archived = 0,
                archived_at = NULL,
                archived_by = NULL
            WHERE case_id = ?
              AND branch_id = ?
        ");
        $stmt->bind_param("is", $caseId, $branchId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to restore the bite case.');
        }
        $stmt->close();

        // A patient becomes active when at least one case is active again.
        $stmt = $conn->prepare("
            UPDATE patients
            SET is_archived = 0,
                archived_at = NULL,
                archived_by = NULL
            WHERE patient_id = ?
              AND branch_id = ?
        ");
        $stmt->bind_param("is", $patientId, $branchId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to restore the patient record.');
        }
        $stmt->close();

        /*
         * Remove the archive snapshots after successful restoration.
         * The main tables are the canonical active records, while audit_logs
         * preserves the fact that the record was previously archived/restored.
         * Child archive rows are deleted first.
         */
        $stmt = $conn->prepare("
            DELETE FROM registry_vaccination_doses_archive
            WHERE registry_id IN (
                SELECT registry_id
                FROM registry_records
                WHERE case_id = ?
            )
        ");
        $stmt->bind_param("i", $caseId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to clean restored registry dose archive.');
        }
        $stmt->close();

        $stmt = $conn->prepare("
            DELETE FROM registry_patients_archive
            WHERE case_id = ?
        ");
        $stmt->bind_param("i", $caseId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to clean restored registry patient archive.');
        }
        $stmt->close();

        $stmt = $conn->prepare("
            DELETE FROM vaccination_records_archive
            WHERE case_id = ?
              AND branch_id = ?
        ");
        $stmt->bind_param("is", $caseId, $branchId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to clean restored vaccination archive.');
        }
        $stmt->close();

        $stmt = $conn->prepare("
            DELETE FROM philhealth_records_archive
            WHERE case_id = ?
        ");
        $stmt->bind_param("i", $caseId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to clean restored PhilHealth archive.');
        }
        $stmt->close();

        $stmt = $conn->prepare("
            DELETE FROM registry_records_archive
            WHERE case_id = ?
        ");
        $stmt->bind_param("i", $caseId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to clean restored registry archive.');
        }
        $stmt->close();

        $stmt = $conn->prepare("
            DELETE FROM animal_bite_cases_archive
            WHERE case_id = ?
              AND branch_id = ?
        ");
        $stmt->bind_param("is", $caseId, $branchId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to clean restored case archive.');
        }
        $stmt->close();

        // The patient archive snapshot may exist only when this was the
        // patient's last active case at the time of archiving.
        $stmt = $conn->prepare("
            DELETE FROM patients_archive
            WHERE patient_id = ?
              AND branch_id = ?
        ");
        $stmt->bind_param("is", $patientId, $branchId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to clean restored patient archive.');
        }
        $stmt->close();

        $conn->commit();

        return [
            'patient_name' => $patientName,
            'case_number' => $caseNumber,
            'patient_id' => $patientId
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

$action = $_GET['action'] ?? ($_POST['action'] ?? null);

if ($action) {
    switch ($action) {
        case 'fetch':
            try {
                $search = trim((string)($_GET['search'] ?? ''));
                $page = max(1, (int)($_GET['page'] ?? 1));
                $perPage = 10;
                $offset = ($page - 1) * $perPage;

                $where = "WHERE c.branch_id = ? AND c.is_archived = 1";
                $params = [$logged_branch_id];
                $types = "s";

                if ($search !== '') {
                    $where .= " AND (
                        p.full_name LIKE ?
                        OR c.case_number LIKE ?
                        OR CAST(p.patient_id AS CHAR) LIKE ?
                    )";
                    $term = "%{$search}%";
                    $params[] = $term;
                    $params[] = $term;
                    $params[] = $term;
                    $types .= "sss";
                }

                $countSql = "
                    SELECT COUNT(*) AS total
                    FROM animal_bite_cases c
                    INNER JOIN patients p
                        ON p.patient_id = c.patient_id
                       AND p.branch_id = c.branch_id
                    $where
                ";
                $stmt = $conn->prepare($countSql);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
                $stmt->close();

                $sql = "
                    SELECT
                        c.case_id,
                        c.case_number,
                        c.patient_id,
                        p.full_name AS patient_name,
                        p.contact_number,
                        p.birthday,
                        p.gender,
                        p.address,
                        c.date_of_bite,
                        c.animal_type,
                        c.bite_location,
                        c.animal_status,
                        c.case_status,
                        c.created_at,
                        abc.archived_at,
                        abc.archive_reason,
                        u.username AS archived_by,
                        r.registry_number,
                        ph.has_philhealth,
                        ph.philhealth_membership,
                        ph.status AS philhealth_status
                    FROM animal_bite_cases c
                    INNER JOIN patients p
                        ON p.patient_id = c.patient_id
                    AND p.branch_id = c.branch_id
                    INNER JOIN animal_bite_cases_archive abc
                        ON abc.original_case_id = c.case_id
                    AND abc.branch_id = c.branch_id
                    LEFT JOIN users u
                        ON u.user_id = abc.archived_by
                    LEFT JOIN (
                        SELECT rr.case_id, MAX(rr.registry_id) AS registry_id
                        FROM registry_records rr
                        WHERE rr.is_archived = 1
                        GROUP BY rr.case_id
                    ) r_latest
                        ON r_latest.case_id = c.case_id
                    LEFT JOIN registry_records r
                        ON r.registry_id = r_latest.registry_id
                    LEFT JOIN (
                        SELECT pr.case_id, MAX(pr.philhealth_record_id) AS philhealth_record_id
                        FROM philhealth_records pr
                        WHERE pr.is_archived = 1
                        GROUP BY pr.case_id
                    ) ph_latest
                        ON ph_latest.case_id = c.case_id
                    LEFT JOIN philhealth_records ph
                        ON ph.philhealth_record_id = ph_latest.philhealth_record_id
                    $where
                    ORDER BY abc.archived_at DESC, c.case_id DESC
                    LIMIT ? OFFSET ?
                ";

                $listParams = $params;
                $listTypes = $types . "ii";
                $listParams[] = $perPage;
                $listParams[] = $offset;

                $stmt = $conn->prepare($sql);
                $stmt->bind_param($listTypes, ...$listParams);
                $stmt->execute();
                $result = $stmt->get_result();

                $rows = [];
                while ($row = $result->fetch_assoc()) {
                    $rows[] = [
                        'case_id' => (int)$row['case_id'],
                        'patient_id' => (int)$row['patient_id'],
                        'case_no' => $row['case_number'] ?: ($row['registry_number'] ?? ''),
                        'patient_name' => $row['patient_name'] ?? '',
                        'contact_number' => $row['contact_number'] ?? '',
                        'dob' => dbToFrontDate($row['birthday'] ?? null),
                        'gender' => $row['gender'] ?? '',
                        'address' => $row['address'] ?? '',
                        'date_of_bite' => dbToFrontDate($row['date_of_bite'] ?? null),
                        'animal_type' => $row['animal_type'] ?? '',
                        'bite_location' => $row['bite_location'] ?? '',
                        'animal_status' => $row['animal_status'] ?? '',
                        'case_status' => $row['case_status'] ?? '',
                        'archived_at' => $row['archived_at'] ?? '',
                        'archive_reason' => $row['archive_reason'] ?? 'Archived by user',
                        'archived_by' => $row['archived_by'] ?? 'Unknown',
                        'philhealth' => $row['has_philhealth'] ?? 'No',
                        'philhealth_type' => $row['philhealth_membership'] ?? '',
                        'philhealth_status' => $row['philhealth_status'] ?? ''
                    ];
                }
                $stmt->close();

                jsonResponse([
                    'success' => true,
                    'rows' => $rows,
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => max(1, (int)ceil($total / $perPage))
                ]);
            } catch (Throwable $e) {
                jsonResponse(['error' => 'Failed to load archived patients: ' . $e->getMessage()], 500);
            }
            break;

        case 'view':
            try {
                $caseId = (int)($_GET['case_id'] ?? 0);
                if ($caseId <= 0) {
                    jsonResponse(['error' => 'Invalid case ID.'], 400);
                }

                $stmt = $conn->prepare("
                    SELECT
                        c.case_id,
                        c.case_number,
                        c.patient_id,
                        c.branch_id,
                        c.animal_type,
                        c.bite_location,
                        c.bite_category,
                        c.animal_status,
                        c.date_of_bite,
                        c.case_status,
                        c.remarks AS case_remarks,
                        c.created_at,
                        abc.archived_at,
                        abc.archive_reason,
                        p.full_name,
                        p.email,
                        p.contact_number,
                        p.birthday,
                        p.gender,
                        p.address,
                        u.username AS archived_by,
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
                        ON p.patient_id = c.patient_id
                    AND p.branch_id = c.branch_id
                    INNER JOIN animal_bite_cases_archive abc
                        ON abc.original_case_id = c.case_id
                    AND abc.branch_id = c.branch_id
                    LEFT JOIN users u
                        ON u.user_id = abc.archived_by
                    LEFT JOIN registry_records r
                        ON r.registry_id = (
                            SELECT MAX(rr.registry_id)
                            FROM registry_records rr
                            WHERE rr.case_id = c.case_id
                            AND rr.is_archived = 1
                        )
                    LEFT JOIN philhealth_records ph
                        ON ph.philhealth_record_id = (
                            SELECT MAX(pr.philhealth_record_id)
                            FROM philhealth_records pr
                            WHERE pr.case_id = c.case_id
                            AND pr.is_archived = 1
                        )
                    WHERE c.case_id = ?
                    AND c.branch_id = ?
                    AND c.is_archived = 1
                    LIMIT 1
                ");
                $stmt->bind_param("is", $caseId, $logged_branch_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$row) {
                    jsonResponse(['error' => 'Archived record not found.'], 404);
                }

                $details = [
                    'case_id' => (int)$row['case_id'],
                    'patient_id' => (int)$row['patient_id'],
                    'case_no' => $row['case_number'] ?? '',
                    'patient_name' => $row['full_name'] ?? '',
                    'email' => $row['email'] ?? '',
                    'contact_number' => $row['contact_number'] ?? '',
                    'dob' => dbToFrontDate($row['birthday'] ?? null),
                    'gender' => $row['gender'] ?? '',
                    'address' => $row['address'] ?? '',
                    'date_of_bite' => dbToFrontDate($row['date_of_bite'] ?? null),
                    'biting_animal' => $row['animal_type'] ?? '',
                    'bite_location' => $row['bite_location'] ?? '',
                    'bite_category' => $row['bite_category'] ?? '',
                    'animal_status' => $row['animal_status'] ?? '',
                    'case_status' => $row['case_status'] ?? '',
                    'registry_number' => $row['registry_number'] ?? '',
                    'active_regimen' => $row['active_regimen'] ?? '',
                    'erig' => (bool)$row['erig'],
                    'ats' => (bool)$row['ats'],
                    'tt' => (bool)$row['tt'],
                    'philhealth' => $row['has_philhealth'] ?? 'No',
                    'philhealth_type' => $row['philhealth_membership'] ?? '',
                    'philhealth_status' => $row['philhealth_status'] ?? '',
                    'remarks' => $row['case_remarks'] ?: ($row['registry_remarks'] ?: ($row['philhealth_remarks'] ?? '')),
                    'archived_at' => $row['archived_at'] ?? '',
                    'archived_by' => $row['archived_by'] ?? 'Unknown',
                    'archive_reason' => $row['archive_reason'] ?? 'Archived by user'
                ];

                jsonResponse(['success' => true, 'data' => $details]);
            } catch (Throwable $e) {
                jsonResponse(['error' => 'Failed to view archived patient: ' . $e->getMessage()], 500);
            }
            break;

        case 'restore':
            try {
                $rawInput = file_get_contents('php://input');
                $input = json_decode($rawInput, true);

                if (!is_array($input)) {
                    jsonResponse(['error' => 'Invalid restore request.'], 400);
                }

                $postedCsrf = (string)($input['csrf_token'] ?? '');
                if ($postedCsrf === '' || !hash_equals($csrfToken, $postedCsrf)) {
                    jsonResponse(['error' => 'Invalid request token. Please refresh the page and try again.'], 403);
                }

                $caseId = (int)($input['case_id'] ?? 0);
                if ($caseId <= 0) {
                    jsonResponse(['error' => 'Invalid case ID.'], 400);
                }

                $restored = restoreCase(
                    $conn,
                    $caseId,
                    $logged_user_id,
                    (string)$logged_branch_id
                );

                auditLog(
                    $conn,
                    $logged_user_id,
                    (string)$logged_branch_id,
                    "Restored patient case: {$restored['patient_name']} (Case: {$restored['case_number']})",
                    'Patient Record'
                );

                jsonResponse([
                    'success' => true,
                    'message' => 'Patient record restored successfully.',
                    'patient_name' => $restored['patient_name'],
                    'case_number' => $restored['case_number']
                ]);
            } catch (Throwable $e) {
                jsonResponse(['error' => $e->getMessage()], 500);
            }
            break;

        default:
            jsonResponse(['error' => 'Unknown action: ' . $action], 400);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Archived Patients - SmartBiteCare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="sidebar.css">
    <link rel="stylesheet" href="notif-num.css">

    <style>
        :root {
            --primary: #2B3A8C;
            --accent: #F21D2F;
            --bg: #f0f2f5;
            --gray-600: #6c757d;
            --gray-700: #495057;
            --border: #e2e6ee;
            --shadow: 0 8px 25px rgba(31, 45, 110, .08);
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            min-height: 100%;
            background: var(--bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
        }

        .main {
            margin-left: 260px;
            width: calc(100% - 260px);
            min-height: 100vh;
            padding: 104px 30px 30px;
        }

        .topbar {
            position: fixed;
            top: 0;
            left: 260px;
            right: 0;
            z-index: 1000;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 35px;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }

        .topbar h3 {
            margin: 0;
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

        .page-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .page-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 20px 22px;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
        }

        .search-wrapper {
            position: relative;
            min-width: 320px;
            max-width: 520px;
            flex: 1;
        }

        .search-wrapper i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #9aa4b2;
        }

        .search-wrapper input {
            width: 100%;
            height: 44px;
            padding: 0 16px 0 42px;
            border: 1px solid #d9dfe8;
            border-radius: 9px;
            outline: none;
        }

        .search-wrapper input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(43,58,140,.10);
        }

        .back-btn,
        .restore-btn,
        .view-btn {
            border-radius: 9px;
            font-weight: 600;
        }

        .back-btn {
            color: var(--primary);
            border: 1px solid var(--primary);
            background: #fff;
        }

        .back-btn:hover {
            background: #eef2ff;
            color: var(--primary);
        }

        .table-responsive {
            overflow-x: auto;
        }

        .archive-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1050px;
        }

        .archive-table th {
            background: var(--primary);
            color: #fff;
            padding: 14px 12px;
            font-size: 13px;
            text-align: left;
            white-space: nowrap;
        }

        .archive-table td {
            padding: 14px 12px;
            border-bottom: 1px solid #edf0f4;
            color: #495057;
            vertical-align: middle;
            font-size: 14px;
        }

        .archive-table tbody tr:hover {
            background: #fafbff;
        }

        /* When sidebar is collapsed, move topbar to the left */
body.sidebar-collapsed .topbar {
    left: 90px !important;
    width: calc(100% - 90px) !important;
}

/* For tablets */
@media (max-width: 991px) {
    .topbar {
        left: 90px !important;
        width: calc(100% - 90px) !important;
    }
}

/* For mobile */
@media (max-width: 576px) {
    .topbar {
        left: 0 !important;
        width: 100% !important;
    }
}


/* When sidebar is collapsed, expand the main content area */
body.sidebar-collapsed .main {
    margin-left: 90px !important;
    width: calc(100% - 90px) !important;
    transition: margin-left 0.3s ease, width 0.3s ease;
}

/* Ensure the table panel itself expands to fill the new space */
.table-panel {
    width: 100%;
    min-width: 0;
}

/* Ensure the record container allows the table to grow */
.record-container {
    display: grid;
    grid-template-columns: 300px minmax(0, 1fr);
    gap: 20px;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    transition: grid-template-columns 0.3s ease;
}

/* On smaller screens, stack the calendar and table */
@media (max-width: 900px) {
    .record-container {
        grid-template-columns: 1fr;
    }
}
        .status-archived {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 9px;
            border-radius: 999px;
            background: #fff3cd;
            color: #856404;
            font-size: 12px;
            font-weight: 700;
        }

        .action-group {
            display: flex;
            gap: 7px;
            white-space: nowrap;
        }

        .action-icon-btn {
            width: 36px;
            height: 36px;
            border: 1px solid #dfe4ec;
            border-radius: 8px;
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .action-icon-btn.view {
            color: var(--primary);
        }

        .action-icon-btn.restore {
            color: #198754;
        }

        .action-icon-btn:hover {
            background: #f5f7fb;
        }

        .empty-state {
            padding: 55px 20px;
            text-align: center;
            color: #8a94a6;
        }

        .empty-state i {
            font-size: 42px;
            display: block;
            margin-bottom: 10px;
        }

        .pagination-wrap {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-top: 1px solid var(--border);
            gap: 12px;
            flex-wrap: wrap;
        }

        .page-info {
            color: var(--gray-600);
            font-size: 14px;
        }

        .pagination-buttons {
            display: flex;
            gap: 5px;
        }

        .pagination-buttons button {
            min-width: 36px;
            height: 36px;
            border: 1px solid #d9dfe8;
            background: #fff;
            border-radius: 7px;
        }

        .pagination-buttons button.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .pagination-buttons button:disabled {
            opacity: .45;
            cursor: not-allowed;
        }

        .archive-modal .modal-content,
        .view-modal .modal-content {
            border: 0;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 20px 55px rgba(31,45,110,.20);
        }

        .archive-modal .modal-header,
        .view-modal .modal-header {
            background: var(--primary);
            color: #fff;
            padding: 20px 24px;
        }

        .archive-modal .btn-close,
        .view-modal .btn-close {
            filter: brightness(0) invert(1);
        }

        .modal-label {
            color: #7a879e;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 3px;
        }

        .modal-value {
            color: #28344f;
            font-size: 15px;
            font-weight: 600;
            word-break: break-word;
        }

        .detail-section {
            border: 1px solid #e8ebf0;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 14px;
        }

        .detail-section-title {
            color: var(--primary);
            font-weight: 700;
            margin-bottom: 12px;
        }

        .archive-warning {
            background: #fff8e1;
            border: 1px solid #ffe08a;
            color: #7a5a00;
            border-radius: 10px;
            padding: 12px 14px;
        }

        .restore-confirm-text {
            color: #6c757d;
            line-height: 1.6;
        }

        @media (max-width: 991px) {
            .main {
                margin-left: 90px;
                width: calc(100% - 90px);
                padding-top: 88px;
            }

            .topbar {
                left: 90px;
                height: 64px;
            }
        }

        @media (max-width: 576px) {
            .main {
                margin-left: 0;
                width: 100%;
                padding: 84px 12px 20px;
            }

            .topbar {
                left: 0;
                padding: 0 14px;
            }

            .topbar h3 {
                font-size: 18px;
            }

            .search-wrapper {
                min-width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="sidebar" id="sidebar">
    <button type="button"
            class="sidebar-toggle"
            id="sidebarToggle"
            aria-label="Toggle sidebar"
            aria-expanded="true">
        <i class="bi bi-chevron-left"></i>
    </button>

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
            <li><a href="AdminStaff_VisitQueue.php"><i class="bi bi-person-check-fill"></i><span>Visit Check-in</span></a></li>
            <li><a href="AdminStaff_Registry.php"><i class="bi bi-journal-check"></i><span>Registry Queue</span></a></li>
            <li><a href="AdminStaff_PhilhealthWorkflow.php"><i class="bi bi-check2-all"></i><span>PhilHealth Workflow</span></a></li>
            <li><a href="AdminStaff_MedicalDocuments.php"><i class="bi bi-file-earmark-ruled"></i><span>Medical Documents</span></a></li>
            <li>
                <a href="AdminStaff_Notifications.php" style="position:relative;">
                    <i class="bi bi-bell-fill"></i>
                    <span>Notifications</span>
                    <?php if ($notification_count > 0): ?>
                        <span class="notification-badge"><?php echo $notification_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>
    </nav>
</div>

<div class="topbar">
    <h3>
        Archived Patients
        <span style="font-size:16px;color:#6c757d;font-weight:400;margin-left:8px;">
            <?php echo htmlspecialchars($branch_name, ENT_QUOTES, 'UTF-8'); ?>
        </span>
    </h3>

    <div class="dropdown">
        <button class="profile dropdown-toggle border-0 bg-transparent px-3 py-2 rounded-3"
                type="button"
                id="adminStaffProfileMenu"
                data-bs-toggle="dropdown"
                aria-expanded="false">
            <i class="bi bi-person-circle"></i>
            <span><?php echo htmlspecialchars($logged_username, ENT_QUOTES, 'UTF-8'); ?></span>
            <span style="font-size:12px;color:#adb5bd;font-weight:400;margin-left:4px;">| Admin Staff</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end border-0 shadow p-2 mt-2"
            aria-labelledby="adminStaffProfileMenu">
            <li><h6 class="dropdown-header">Account options</h6></li>
            <li>
                <a class="dropdown-item rounded-2 py-2" href="Account_ChangePassword.php">
                    <i class="bi bi-key-fill me-2"></i>Change Password
                </a>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
                <a class="dropdown-item rounded-2 py-2 text-danger"
                   href="#"
                   data-bs-toggle="modal"
                   data-bs-target="#logoutConfirmModal">
                    <i class="bi bi-box-arrow-right me-2"></i>Logout
                </a>
            </li>
        </ul>
    </div>
</div>

<main class="main">
    <div class="page-card">
        <div class="page-toolbar">
            <div class="search-wrapper">
                <i class="bi bi-search"></i>
                <input type="text" id="searchInput"
                       placeholder="Search by Case No., Patient Name, or Patient ID...">
            </div>

            <a href="AdminStaff_PatientRecord.php" class="btn back-btn">
                <i class="bi bi-arrow-left me-1"></i> Active Patients
            </a>
        </div>

        <div class="table-responsive">
            <table class="archive-table">
                <thead>
                    <tr>
                        <th>Case No.</th>
                        <th>Patient Name</th>
                        <th>Patient ID</th>
                        <th>Archived Date</th>
                        <th>Archived By</th>
                        <th>Archive Reason</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="archiveTableBody">
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <i class="bi bi-arrow-repeat"></i>
                                Loading archived records...
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="pagination-wrap">
            <div class="page-info" id="pageInfo">Loading...</div>
            <div class="pagination-buttons" id="paginationButtons"></div>
        </div>
    </div>
</main>

<!-- View Archived Patient Modal -->
<div class="modal fade view-modal" id="viewArchivedModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-1">
                        <i class="bi bi-person-vcard-fill me-2"></i>Archived Patient Details
                    </h5>
                    <small id="viewArchivedSubtitle">Patient information</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewArchivedBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary"></div>
                    <div class="mt-2 text-muted">Loading archived patient...</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" id="viewRestoreBtn">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Restore Patient
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Restore Confirmation Modal -->
<div class="modal fade archive-modal" id="restoreModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-arrow-counterclockwise me-2"></i>Restore Patient
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2"><strong>Are you sure you want to restore this patient record?</strong></p>
                <p class="mb-2" id="restorePatientName"></p>
                <div class="archive-warning">
                    This record will become active again and will appear in the active patient records.
                </div>
                <p class="restore-confirm-text small mt-3 mb-0">
                    The archived case and its related patient, registry, vaccination, and PhilHealth records will be restored together.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="confirmRestoreBtn">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Restore
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Logout Confirmation Modal -->
<div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border:0;border-radius:16px;overflow:hidden;">
            <div class="modal-header" style="background:#2B3A8C;color:#fff;">
                <h5 class="modal-title">Log out of Smart Bite Care?</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Make sure you have saved any unfinished work before leaving your account.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <a href="logout.php" class="btn btn-danger">
                    <i class="bi bi-box-arrow-right me-1"></i> Yes, Log Out
                </a>
            </div>
        </div>
    </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="sources/sidebar.js"></script>
<script>
const apiBase = window.location.href.split('?')[0];
const csrfToken = <?php echo json_encode($csrfToken); ?>;

let currentPage = 1;
let currentSearch = '';
let totalPages = 1;
let selectedCaseId = null;
let selectedPatientName = '';

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function showToast(message, isError = false) {
    const id = 'toast_' + Date.now();
    const wrapper = document.createElement('div');
    wrapper.innerHTML = `
        <div id="${id}" class="toast align-items-center ${isError ? 'text-bg-danger' : 'text-bg-success'} border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body">${escapeHtml(message)}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `;
    document.getElementById('toastContainer').appendChild(wrapper.firstElementChild);
    const toastEl = document.getElementById(id);
    const toast = new bootstrap.Toast(toastEl, { delay: 3000 });
    toast.show();
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}

function formatDateTime(value) {
    if (!value) return '—';
    const d = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit'
    });
}

async function loadArchivedPatients(page = 1) {
    currentPage = page;

    const params = new URLSearchParams({
        action: 'fetch',
        page: String(page),
        search: currentSearch
    });

    const tbody = document.getElementById('archiveTableBody');
    tbody.innerHTML = `
        <tr>
            <td colspan="8">
                <div class="empty-state">
                    <div class="spinner-border text-primary mb-2"></div>
                    <p>Loading archived records...</p>
                </div>
            </td>
        </tr>
    `;

    try {
        const response = await fetch(`${apiBase}?${params.toString()}`);
        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Unable to load archived patients.');
        }

        totalPages = data.total_pages || 1;
        renderArchivedRows(data.rows || []);
        renderPagination(data.total || 0, data.page || 1, totalPages);
    } catch (error) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8">
                    <div class="empty-state text-danger">
                        <i class="bi bi-exclamation-circle"></i>
                        <p>${escapeHtml(error.message)}</p>
                    </div>
                </td>
            </tr>
        `;
        document.getElementById('pageInfo').textContent = 'Unable to load records.';
        document.getElementById('paginationButtons').innerHTML = '';
    }
}

function renderArchivedRows(rows) {
    const tbody = document.getElementById('archiveTableBody');

    if (!rows.length) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8">
                    <div class="empty-state">
                        <i class="bi bi-archive"></i>
                        <p>${currentSearch ? 'No archived patients match your search.' : 'No archived patients found.'}</p>
                    </div>
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = rows.map(row => `
        <tr>
            <td><strong>${escapeHtml(row.case_no)}</strong></td>
            <td>${escapeHtml(row.patient_name)}</td>
            <td>${escapeHtml(row.patient_id)}</td>
            <td>${escapeHtml(formatDateTime(row.archived_at))}</td>
            <td>${escapeHtml(row.archived_by)}</td>
            <td>${escapeHtml(row.archive_reason)}</td>
            <td>
                <span class="status-archived">
                    <i class="bi bi-archive-fill"></i> Archived
                </span>
            </td>
            <td>
                <div class="action-group">
                    <button class="action-icon-btn view"
                            title="View archived patient"
                            data-action="view"
                            data-case-id="${row.case_id}">
                        <i class="bi bi-eye"></i>
                    </button>
                    <button class="action-icon-btn restore"
                            title="Restore patient"
                            data-action="restore"
                            data-case-id="${row.case_id}"
                            data-name="${escapeHtml(row.patient_name)}"
                            data-case-no="${escapeHtml(row.case_no)}">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');

    tbody.querySelectorAll('[data-action="view"]').forEach(button => {
        button.addEventListener('click', () => viewArchivedPatient(Number(button.dataset.caseId)));
    });

    tbody.querySelectorAll('[data-action="restore"]').forEach(button => {
        button.addEventListener('click', () => {
            selectedCaseId = Number(button.dataset.caseId);
            selectedPatientName = button.dataset.name || '';
            document.getElementById('restorePatientName').textContent =
                `Patient: ${selectedPatientName} (Case: ${button.dataset.caseNo || ''})`;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('restoreModal')).show();
        });
    });
}

function renderPagination(total, page, pages) {
    const info = document.getElementById('pageInfo');
    const buttons = document.getElementById('paginationButtons');

    if (total === 0) {
        info.textContent = '0 archived records';
        buttons.innerHTML = '';
        return;
    }

    const perPage = 10;
    const start = ((page - 1) * perPage) + 1;
    const end = Math.min(page * perPage, total);
    info.textContent = `Showing ${start}–${end} of ${total} archived record${total === 1 ? '' : 's'}`;

    let html = `
        <button ${page <= 1 ? 'disabled' : ''} data-page="${page - 1}" aria-label="Previous">
            <i class="bi bi-chevron-left"></i>
        </button>
    `;

    const startPage = Math.max(1, page - 2);
    const endPage = Math.min(pages, page + 2);

    for (let p = startPage; p <= endPage; p++) {
        html += `<button class="${p === page ? 'active' : ''}" data-page="${p}">${p}</button>`;
    }

    html += `
        <button ${page >= pages ? 'disabled' : ''} data-page="${page + 1}" aria-label="Next">
            <i class="bi bi-chevron-right"></i>
        </button>
    `;

    buttons.innerHTML = html;
    buttons.querySelectorAll('button[data-page]').forEach(button => {
        button.addEventListener('click', () => {
            const target = Number(button.dataset.page);
            if (target >= 1 && target <= pages && target !== page) {
                loadArchivedPatients(target);
            }
        });
    });
}

async function viewArchivedPatient(caseId) {
    selectedCaseId = caseId;

    const body = document.getElementById('viewArchivedBody');
    body.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary"></div>
            <div class="mt-2 text-muted">Loading archived patient...</div>
        </div>
    `;

    bootstrap.Modal.getOrCreateInstance(document.getElementById('viewArchivedModal')).show();

    try {
        const response = await fetch(`${apiBase}?action=view&case_id=${encodeURIComponent(caseId)}`);
        const result = await response.json();

        if (!result.success) {
            throw new Error(result.error || 'Unable to load archived patient.');
        }

        const d = result.data;
        selectedPatientName = d.patient_name || '';

        document.getElementById('viewArchivedSubtitle').textContent =
            `${d.patient_name} • Case ${d.case_no}`;

        body.innerHTML = `
            <div class="archive-warning mb-3">
                <strong><i class="bi bi-archive-fill me-1"></i> This record is archived.</strong>
                It can be restored to the active patient records when needed.
            </div>

            <div class="detail-section">
                <div class="detail-section-title">
                    <i class="bi bi-person-fill me-1"></i> Patient Information
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="modal-label">Patient Name</div>
                        <div class="modal-value">${escapeHtml(d.patient_name)}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="modal-label">Patient ID</div>
                        <div class="modal-value">${escapeHtml(d.patient_id)}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="modal-label">Case No.</div>
                        <div class="modal-value">${escapeHtml(d.case_no)}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="modal-label">Date of Birth</div>
                        <div class="modal-value">${escapeHtml(d.dob || '—')}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="modal-label">Gender</div>
                        <div class="modal-value">${escapeHtml(d.gender || '—')}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="modal-label">Contact Number</div>
                        <div class="modal-value">${escapeHtml(d.contact_number || '—')}</div>
                    </div>
                    <div class="col-12">
                        <div class="modal-label">Address</div>
                        <div class="modal-value">${escapeHtml(d.address || '—')}</div>
                    </div>
                </div>
            </div>

            <div class="detail-section">
                <div class="detail-section-title">
                    <i class="bi bi-bandaid-fill me-1"></i> Bite Case
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="modal-label">Date of Bite</div>
                        <div class="modal-value">${escapeHtml(d.date_of_bite || '—')}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="modal-label">Biting Animal</div>
                        <div class="modal-value">${escapeHtml(d.biting_animal || '—')}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="modal-label">Animal Status</div>
                        <div class="modal-value">${escapeHtml(d.animal_status || '—')}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="modal-label">Site of Bite</div>
                        <div class="modal-value">${escapeHtml(d.bite_location || '—')}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="modal-label">Case Status</div>
                        <div class="modal-value">${escapeHtml(d.case_status || '—')}</div>
                    </div>
                    <div class="col-12">
                        <div class="modal-label">Registry Number</div>
                        <div class="modal-value">${escapeHtml(d.registry_number || '—')}</div>
                    </div>
                </div>
            </div>

            <div class="detail-section">
                <div class="detail-section-title">
                    <i class="bi bi-heart-pulse-fill me-1"></i> PhilHealth
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="modal-label">PhilHealth</div>
                        <div class="modal-value">${escapeHtml(d.philhealth || 'No')}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="modal-label">Membership Type</div>
                        <div class="modal-value">${escapeHtml(d.philhealth_type || '—')}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="modal-label">Status</div>
                        <div class="modal-value">${escapeHtml(d.philhealth_status || '—')}</div>
                    </div>
                </div>
            </div>

            <div class="detail-section">
                <div class="detail-section-title">
                    <i class="bi bi-archive-fill me-1"></i> Archive Information
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="modal-label">Status</div>
                        <div class="modal-value">ARCHIVED</div>
                    </div>
                    <div class="col-md-4">
                        <div class="modal-label">Archived Date</div>
                        <div class="modal-value">${escapeHtml(formatDateTime(d.archived_at))}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="modal-label">Archived By</div>
                        <div class="modal-value">${escapeHtml(d.archived_by || 'Unknown')}</div>
                    </div>
                    <div class="col-12">
                        <div class="modal-label">Archive Reason</div>
                        <div class="modal-value">${escapeHtml(d.archive_reason || 'Archived by user')}</div>
                    </div>
                </div>
            </div>
        `;

        document.getElementById('viewRestoreBtn').onclick = () => {
            bootstrap.Modal.getInstance(document.getElementById('viewArchivedModal')).hide();
            document.getElementById('restorePatientName').textContent =
                `Patient: ${d.patient_name} (Case: ${d.case_no})`;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('restoreModal')).show();
        };
    } catch (error) {
        body.innerHTML = `
            <div class="alert alert-danger mb-0">
                ${escapeHtml(error.message)}
            </div>
        `;
    }
}

document.getElementById('confirmRestoreBtn').addEventListener('click', async function() {
    if (!selectedCaseId) return;

    const button = this;
    const originalHtml = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Restoring...';

    try {
        const response = await fetch(`${apiBase}?action=restore`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                csrf_token: csrfToken,
                case_id: selectedCaseId
            })
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Restore failed.');
        }

        bootstrap.Modal.getInstance(document.getElementById('restoreModal')).hide();
        showToast(`${data.patient_name} was restored successfully.`);
        selectedCaseId = null;
        selectedPatientName = '';
        loadArchivedPatients(currentPage);
    } catch (error) {
        showToast(error.message, true);
    } finally {
        button.disabled = false;
        button.innerHTML = originalHtml;
    }
});

let searchTimer = null;
document.getElementById('searchInput').addEventListener('input', function() {
    currentSearch = this.value.trim();
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadArchivedPatients(1), 250);
});

loadArchivedPatients(1);
</script>
</body>
</html>
