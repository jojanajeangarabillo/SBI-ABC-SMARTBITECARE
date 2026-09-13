<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/notification_helper.php';

// Check if user is logged in and is a nurse
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 3) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$notification_count = getUnreadNotificationCount($conn, $user_id);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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

// If no branch assigned
if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
}

/*
|--------------------------------------------------------------------------
| DOWNLOAD A GENERATED PATIENT PDF
|--------------------------------------------------------------------------
| This endpoint returns the exact PDF that was generated and saved in
| uploads/documents. Using Content-Disposition: attachment makes the browser
| perform a real PDF download instead of relying on a JavaScript <a download>
| click, which some browsers can block after a redirect.
*/
if (isset($_GET['download_generated'])) {
    $document_id = (int)($_GET['download_generated'] ?? 0);

    if ($document_id < 1 || !$branch_id) {
        http_response_code(400);
        exit('Invalid generated document request.');
    }

    $download_stmt = $conn->prepare(
        "SELECT document_id, file_name, file_path, file_type
         FROM medical_documents
         WHERE document_id = ?
           AND branch_id = ?
           AND uploaded_by = ?
           AND COALESCE(status, 'Active') <> 'Archived'
         LIMIT 1"
    );

    if (!$download_stmt) {
        http_response_code(500);
        exit('Unable to prepare the PDF download.');
    }

    $download_stmt->bind_param('isi', $document_id, $branch_id, $user_id);
    $download_stmt->execute();
    $download_document = $download_stmt->get_result()->fetch_assoc();
    $download_stmt->close();

    if (!$download_document) {
        http_response_code(404);
        exit('Generated PDF not found.');
    }

    $stored_path = trim((string)($download_document['file_path'] ?? ''));
    $documents_base = realpath(
        __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'documents'
    );

    $relative_path = str_replace(
        ['/', '\\'],
        DIRECTORY_SEPARATOR,
        ltrim($stored_path, '/\\')
    );

    $absolute_path = realpath(__DIR__ . DIRECTORY_SEPARATOR . $relative_path);

    if (
        !$documents_base ||
        !$absolute_path ||
        !is_file($absolute_path) ||
        strpos($absolute_path, $documents_base . DIRECTORY_SEPARATOR) !== 0
    ) {
        http_response_code(404);
        exit('The generated PDF file could not be found.');
    }

    $download_name = basename((string)($download_document['file_name'] ?? 'patient_document.pdf'));
    $download_name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $download_name);

    if ($download_name === '' || $download_name === '.' || $download_name === '..') {
        $download_name = 'patient_document.pdf';
    }

    if (strtolower(substr($download_name, -4)) !== '.pdf') {
        $download_name .= '.pdf';
    }

    // Remove any buffered HTML/errors before streaming the PDF bytes.
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $download_name . '"');
    header('Content-Length: ' . filesize($absolute_path));
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');

    session_write_close();
    readfile($absolute_path);
    exit;
}

// Handle AJAX requests for patient data
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_patient') {
    header('Content-Type: application/json');

    $patient_id = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;

    if ($patient_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid patient ID']);
        exit;
    }

    // Only allow the nurse to view patients from the nurse's own branch.
    $sql_patient = "SELECT *
                    FROM patients
                    WHERE patient_id = ?
                      AND branch_id = ?
                      AND is_archived = 0
                    LIMIT 1";

    $stmt_patient = $conn->prepare($sql_patient);
    $stmt_patient->bind_param("is", $patient_id, $branch_id);
    $stmt_patient->execute();
    $patient = $stmt_patient->get_result()->fetch_assoc();
    $stmt_patient->close();

    if (!$patient) {
        echo json_encode([
            'success' => false,
            'message' => 'Patient not found in your branch.'
        ]);
        exit;
    }

    // Complete case history for this patient in the current branch.
    $sql_cases = "SELECT *
                  FROM animal_bite_cases
                  WHERE patient_id = ?
                    AND branch_id = ?
                    AND is_archived = 0
                  ORDER BY created_at DESC";

    $stmt_cases = $conn->prepare($sql_cases);
    $stmt_cases->bind_param("is", $patient_id, $branch_id);
    $stmt_cases->execute();
    $cases = $stmt_cases->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_cases->close();

    // Complete vaccination history for this patient in the current branch.
    // LEFT JOIN keeps historical vaccination records visible even if an
    // inventory item is later changed or removed.
    $sql_vacc = "SELECT
                    v.*,
                    COALESCE(v.vaccine_name, i.item_name, 'Unknown Vaccine') AS item_name,
                    COALESCE(u.unit_name, 'N/A') AS unit_name
                 FROM vaccination_records v
                 LEFT JOIN inventory_items i
                    ON v.item_id = i.item_id
                 LEFT JOIN units u
                    ON v.unit_id = u.unit_id
                 WHERE v.patient_id = ?
                   AND v.branch_id = ?
                   AND v.is_archived = 0
                 ORDER BY
                    COALESCE(v.date_administered, v.scheduled_date, v.created_at) DESC,
                    v.dose_number ASC";

    $stmt_vacc = $conn->prepare($sql_vacc);
    $stmt_vacc->bind_param("is", $patient_id, $branch_id);
    $stmt_vacc->execute();
    $vaccinations = $stmt_vacc->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_vacc->close();

    echo json_encode([
        'success' => true,
        'patient' => $patient,
        'cases' => $cases,
        'vaccinations' => $vaccinations
    ]);
    exit;
}

// Handle AJAX request for latest case
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_latest_case') {
    header('Content-Type: application/json');

    $patient_id = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;

    if ($patient_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid patient ID']);
        exit;
    }

    $sql = "SELECT a.case_id
            FROM animal_bite_cases a
            INNER JOIN patients p
                ON a.patient_id = p.patient_id
            WHERE a.patient_id = ?
              AND a.branch_id = ?
              AND p.branch_id = ?
              AND a.is_archived = 0
              AND p.is_archived = 0
            ORDER BY a.created_at DESC
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $patient_id, $branch_id, $branch_id);
    $stmt->execute();
    $case = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    echo json_encode([
        'success' => true,
        'case_id' => $case ? (int) $case['case_id'] : null
    ]);
    exit;
}

// Generate a designed SBI Medical PDF and register it in the existing
// Administrative Staff Medical Documents repository. No new database columns are required.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_document'])) {
    $absolute_file_path = null;
    $transaction_started = false;
    $is_ajax_generation =
        isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    try {
        $csrf_token = (string)($_POST['csrf_token'] ?? '');
        if ($csrf_token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
            throw new RuntimeException('Invalid request token. Refresh the page and try again.');
        }

        $patient_id = (int)($_POST['patient_id'] ?? 0);
        $case_id = (int)($_POST['case_id'] ?? 0);
        $document_type = trim((string)($_POST['document_type'] ?? ''));
        $allowed_document_types = [
            'Medical Certificate',
            'Referral Letter',
            'Vaccination Certificate'
        ];

        if ($patient_id < 1 || $case_id < 1) {
            throw new RuntimeException('A valid patient and case are required.');
        }
        if (!in_array($document_type, $allowed_document_types, true)) {
            throw new RuntimeException('Choose a valid document type.');
        }

        // Validate both the patient and selected case against the Nurse's branch.
        $sql = "SELECT
                    p.patient_id,p.full_name,p.email,p.contact_number,p.birthday,p.gender,p.address,
                    a.case_id,a.case_number,a.animal_type,a.bite_location,a.bite_category,
                    a.animal_status,a.date_of_bite,a.case_status,a.remarks AS case_remarks
                FROM patients p
                INNER JOIN animal_bite_cases a
                    ON a.patient_id=p.patient_id AND a.branch_id=p.branch_id
                WHERE p.patient_id=? AND p.branch_id=? AND p.is_archived=0
                  AND a.case_id=? AND a.branch_id=? AND a.is_archived=0
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('isis', $patient_id, $branch_id, $case_id, $branch_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$data) {
            throw new RuntimeException('Patient or case was not found in your branch.');
        }

        // Build the same structured document data used by the Admin Staff
        // generator so Nurse-generated PDFs have the exact same form design.
        $branch_info = getBranchInfo($branch_id) ?: [];
        $document_data = buildPatientDocumentContent(
            $conn,
            $data,
            $document_type,
            $branch_id,
            $branch_info['branch_name'] ?? $branch_name,
            $username,
            $branch_info['branch_address'] ?? '',
            $branch_info['contact_number'] ?? '',
            $branch_info['email'] ?? ''
        );

        $safe_patient = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '_', $data['full_name']), '_');
        $safe_type = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '_', $document_type), '_');
        $unique_name = $safe_type . '_' . $safe_patient . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $stored_file_name = $unique_name . '.pdf';
        $original_file_name = $safe_type . '_' . $safe_patient . '.pdf';
        $document_name = $document_type . ' - ' . $data['full_name'] . ' - ' . $data['case_number'];

        // Use the exact folder used by AdminStaff_MedicalDocuments.php.
        $documents_dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'documents';
        if (!is_dir($documents_dir) && !mkdir($documents_dir, 0755, true) && !is_dir($documents_dir)) {
            throw new RuntimeException('Unable to create uploads/documents. Check the folder permissions.');
        }

        $absolute_file_path = $documents_dir . DIRECTORY_SEPARATOR . $stored_file_name;
        $database_file_path = 'uploads/documents/' . $stored_file_name;

        // Generate the designed PDF with the same layout, colors, tables,
        // signatures and logo as the Admin Staff patient-document generator.
        if (!writePatientPdf($absolute_file_path, $document_type, $document_data)) {
            throw new RuntimeException('Unable to create the designed PDF file.');
        }
        if (!is_file($absolute_file_path) || filesize($absolute_file_path) < 1) {
            throw new RuntimeException('The generated PDF file is empty.');
        }

        $file_type = 'application/pdf';
        $file_size = (int)filesize($absolute_file_path);
        $status = 'Active';

        $conn->begin_transaction();
        $transaction_started = true;

        // These are the columns already present in your medical_documents table.
        $insert = $conn->prepare(
            "INSERT INTO medical_documents
             (branch_id,document_type,document_name,file_name,file_path,file_type,file_size,uploaded_by,status)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        $insert->bind_param(
            'ssssssiis',
            $branch_id,
            $document_type,
            $document_name,
            $original_file_name,
            $database_file_path,
            $file_type,
            $file_size,
            $user_id,
            $status
        );
        $insert->execute();
        $medical_document_id = (int)$insert->insert_id;
        $insert->close();

        // document_tracking links the generated file back to its patient case.
        // Its old ENUM has no Vaccination Certificate value, so NULL is used for
        // that type while the complete type is preserved in remarks.
        $tracking_type = in_array($document_type, ['Medical Certificate', 'Referral Letter'], true)
            ? $document_type
            : null;
        $tracking_status = 'Generated';
        $tracking_remarks = $document_type . ' generated by Nurse ' . $username .
            '. Medical Document ID: ' . $medical_document_id . '.';
        $tracking = $conn->prepare(
            "INSERT INTO document_tracking (case_id,document_type,status,remarks,created_by)
             VALUES (?,?,?,?,?)"
        );
        $tracking->bind_param('isssi', $case_id, $tracking_type, $tracking_status, $tracking_remarks, $user_id);
        $tracking->execute();
        $tracking->close();

        logNurseDocumentAudit(
            $conn,
            $user_id,
            $branch_id,
            'Generated ' . $document_type . ' for ' . $data['full_name'] .
            ' (Case ' . $data['case_number'] . ', Document ID ' . $medical_document_id . ')'
        );
        notifyBranchAdministrativeStaff(
            $conn,
            $branch_id,
            $document_type,
            $data['full_name'],
            $data['case_number']
        );

        $conn->commit();
        $transaction_started = false;

        $download_url = 'Nurse_Patients.php?download_generated=' . rawurlencode((string)$medical_document_id);

        if ($is_ajax_generation) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => $document_type . ' generated successfully and saved in Medical Documents.',
                'document_id' => $medical_document_id,
                'document_name' => $document_name,
                'file_name' => $original_file_name,
                'file_path' => $database_file_path,
                'download_url' => $download_url
            ]);
            exit;
        }

        // Non-JavaScript fallback: redirect directly to the secure PDF attachment.
        header('Location: ' . $download_url, true, 303);
        exit;
    } catch (Throwable $e) {
        if ($transaction_started) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }
        }
        if ($absolute_file_path && is_file($absolute_file_path)) {
            @unlink($absolute_file_path);
        }
        error_log('Nurse document generation error: ' . $e->getMessage());
        $error_message = $e instanceof mysqli_sql_exception
            ? 'The document could not be saved because of a database error. Check the Apache error log.'
            : $e->getMessage();

        if ($is_ajax_generation) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => $error_message
            ]);
            exit;
        }
    }
}

// Handle patient search and pagination
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Keep completed patients visible here because this page is the permanent
// patient/history module. Only archived patients are excluded.
$count_sql = "SELECT COUNT(*) AS total
              FROM patients
              WHERE branch_id = ?
                AND is_archived = 0";

if ($search !== '') {
    $count_sql .= " AND (
        full_name LIKE ?
        OR email LIKE ?
        OR contact_number LIKE ?
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
$total_rows = (int) ($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$count_stmt->close();

$total_pages = max(1, (int) ceil($total_rows / $limit));

if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $limit;
}

// Latest non-archived case is shown for each patient. Completed patients
// remain in the list.
$sql_patients = "SELECT
                    p.*,
                    a.case_id,
                    a.case_status,
                    a.date_of_bite
                 FROM patients p
                 LEFT JOIN (
                     SELECT
                         patient_id,
                         branch_id,
                         case_id,
                         case_status,
                         date_of_bite,
                         ROW_NUMBER() OVER (
                             PARTITION BY patient_id
                             ORDER BY created_at DESC
                         ) AS rn
                     FROM animal_bite_cases
                     WHERE is_archived = 0
                 ) a
                    ON p.patient_id = a.patient_id
                   AND p.branch_id = a.branch_id
                   AND a.rn = 1
                 WHERE p.branch_id = ?
                   AND p.is_archived = 0";

if ($search !== '') {
    $sql_patients .= " AND (
        p.full_name LIKE ?
        OR p.email LIKE ?
        OR p.contact_number LIKE ?
    )";
}

$sql_patients .= " ORDER BY p.patient_id DESC LIMIT ? OFFSET ?";

$stmt_patients = $conn->prepare($sql_patients);

if ($search !== '') {
    $search_param = '%' . $search . '%';

    $stmt_patients->bind_param(
        "ssssii",
        $branch_id,
        $search_param,
        $search_param,
        $search_param,
        $limit,
        $offset
    );
} else {
    $stmt_patients->bind_param(
        "sii",
        $branch_id,
        $limit,
        $offset
    );
}

$stmt_patients->execute();
$patients = $stmt_patients->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_patients->close();

/**
 * Convert the stored sequential dose number into the clinic schedule label.
 * Unknown values remain readable instead of causing a fatal error.
 */
function getDoseLabel(int $doseNumber): string
{
    $labels = [
        1 => 'D0',
        2 => 'D3',
        3 => 'D7',
        4 => 'D14',
        5 => 'D21',
        6 => 'D28'
    ];

    return $labels[$doseNumber] ?? ('Dose ' . $doseNumber);
}

/**
 * Record successful document generation in the existing audit_logs table.
 */
function logNurseDocumentAudit(
    mysqli $conn,
    int $userId,
    string $branchId,
    string $action
): void {
    $module = 'Medical Documents';
    $stmt = $conn->prepare(
        'INSERT INTO audit_logs (user_id,branch_id,action,module) VALUES (?,?,?,?)'
    );
    $stmt->bind_param('isss', $userId, $branchId, $action, $module);
    $stmt->execute();
    $stmt->close();
}

/**
 * Tell active Administrative Staff in the same branch that a new generated
 * document is ready in their Medical Documents page.
 */
function notifyBranchAdministrativeStaff(
    mysqli $conn,
    string $branchId,
    string $documentType,
    string $patientName,
    string $caseNumber
): void {
    $users = $conn->prepare(
        "SELECT user_id
         FROM users
         WHERE branch_id=? AND role_id=4 AND status='Active'"
    );
    $users->bind_param('s', $branchId);
    $users->execute();
    $result = $users->get_result();

    $title = 'New Generated Medical Document';
    $message = $documentType . ' for ' . $patientName .
        ' (Case ' . $caseNumber . ') is ready in Medical Documents.';
    $notificationType = 'medical_document';
    $insert = $conn->prepare(
        'INSERT INTO notifications
         (user_id,title,message,notification_type,is_read,created_at)
         VALUES (?,?,?,?,0,NOW())'
    );

    while ($recipient = $result->fetch_assoc()) {
        $recipientId = (int)$recipient['user_id'];
        $insert->bind_param(
            'isss',
            $recipientId,
            $title,
            $message,
            $notificationType
        );
        $insert->execute();
    }

    $insert->close();
    $users->close();
}

/*
|--------------------------------------------------------------------------
| STYLED PATIENT PDF GENERATOR
|--------------------------------------------------------------------------
| Uses the exact same SBI Medical PDF design used by
| AdminStaff_MedicalDocuments.php, including the real project logo.png.
|--------------------------------------------------------------------------
*/
function pdfEscapeText($value)
{
    $value = (string)$value;
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

/**
 * Basic text-width estimate for Helvetica. It is intentionally conservative
 * so values remain inside their cells when printed.
 */
function pdfTextWidth($text, $fontSize = 9)
{
    return strlen((string)$text) * ((float)$fontSize * 0.50);
}

function pdfWriteText(&$stream, $x, $y, $text, $size = 9, $bold = false, $rgb = [0, 0, 0])
{
    $font = $bold ? 'F2' : 'F1';
    $safe = pdfEscapeText($text);
    $stream .= sprintf("%.3F %.3F %.3F rg\n", $rgb[0], $rgb[1], $rgb[2]);
    $stream .= sprintf("BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $font, $size, $x, $y, $safe);
}

function pdfCenteredText(&$stream, $x, $y, $width, $text, $size = 9, $bold = false, $rgb = [0, 0, 0])
{
    $textWidth = pdfTextWidth($text, $size);
    $tx = $x + max(2, ($width - $textWidth) / 2);
    pdfWriteText($stream, $tx, $y, $text, $size, $bold, $rgb);
}

function pdfLine(&$stream, $x1, $y1, $x2, $y2, $width = 0.6, $rgb = [0.55, 0.60, 0.70])
{
    $stream .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n", $rgb[0], $rgb[1], $rgb[2], $width, $x1, $y1, $x2, $y2);
}

function pdfRect(&$stream, $x, $y, $w, $h, $fill = null, $stroke = [0.58, 0.63, 0.72], $lineWidth = 0.6)
{
    if (is_array($fill)) {
        $stream .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $fill[0], $fill[1], $fill[2], $x, $y, $w, $h);
    }
    if (is_array($stroke)) {
        $stream .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F %.2F %.2F re S\n", $stroke[0], $stroke[1], $stroke[2], $lineWidth, $x, $y, $w, $h);
    }
}

function pdfWrapText($text, $fontSize, $maxWidth)
{
    $text = trim((string)$text);
    if ($text === '') {
        return [''];
    }

    $words = preg_split('/\s+/', $text);
    $lines = [];
    $line = '';
    foreach ($words as $word) {
        $candidate = $line === '' ? $word : $line . ' ' . $word;
        if (pdfTextWidth($candidate, $fontSize) <= $maxWidth) {
            $line = $candidate;
        } else {
            if ($line !== '') {
                $lines[] = $line;
            }
            $line = $word;
        }
    }
    if ($line !== '') {
        $lines[] = $line;
    }
    return $lines ?: [''];
}

function pdfCellText(&$stream, $x, $y, $w, $h, $text, $size = 8.2, $bold = false, $align = 'left', $rgb = [0, 0, 0])
{
    $text = (string)$text;
    $baseline = $y + ($h / 2) - ($size * 0.32);
    if ($align === 'center') {
        pdfCenteredText($stream, $x, $baseline, $w, $text, $size, $bold, $rgb);
        return;
    }
    if ($align === 'right') {
        $tx = $x + $w - pdfTextWidth($text, $size) - 5;
        pdfWriteText($stream, max($x + 3, $tx), $baseline, $text, $size, $bold, $rgb);
        return;
    }
    pdfWriteText($stream, $x + 5, $baseline, $text, $size, $bold, $rgb);
}

/**
 * Build the structured information used by all generated PDFs.
 */
function buildPatientDocumentContent(
    $conn,
    $patientCase,
    $documentType,
    $branchId,
    $branchName,
    $preparedBy,
    $branchAddress = '',
    $branchContact = '',
    $branchEmail = ''
) {
    $patientId = (int)$patientCase['patient_id'];
    $caseId = (int)$patientCase['case_id'];
    $birthdayRaw = $patientCase['birthday'] ?? null;
    $age = '';
    if (!empty($birthdayRaw)) {
        try {
            $birthDate = new DateTime($birthdayRaw);
            $age = (string)$birthDate->diff(new DateTime('today'))->y;
        } catch (Throwable $ignored) {
            $age = '';
        }
    }

    $data = [
        'document_type' => $documentType,
        'date_issued' => date('F d, Y'),
        'certificate_no' => 'DOC-' . date('Y') . '-' . str_pad((string)$caseId, 5, '0', STR_PAD_LEFT),
        'branch_name' => $branchName,
        'branch_address' => $branchAddress,
        'branch_contact' => $branchContact,
        'branch_email' => $branchEmail,
        'prepared_by' => $preparedBy,
        'patient_name' => $patientCase['full_name'] ?? 'N/A',
        'birthday' => !empty($birthdayRaw) ? date('F d, Y', strtotime($birthdayRaw)) : 'N/A',
        'age' => $age !== '' ? $age : 'N/A',
        'sex' => $patientCase['gender'] ?? 'N/A',
        'address' => $patientCase['address'] ?? 'N/A',
        'contact_number' => $patientCase['contact_number'] ?? 'N/A',
        'email' => $patientCase['email'] ?? 'N/A',
        'case_number' => $patientCase['case_number'] ?? ('C' . str_pad((string)$caseId, 4, '0', STR_PAD_LEFT)),
        'animal_type' => $patientCase['animal_type'] ?? 'N/A',
        'bite_location' => $patientCase['bite_location'] ?? 'N/A',
        'bite_category' => $patientCase['bite_category'] ?? 'N/A',
        'animal_status' => $patientCase['animal_status'] ?? 'N/A',
        'bite_date' => !empty($patientCase['date_of_bite']) ? date('F d, Y', strtotime($patientCase['date_of_bite'])) : 'N/A',
        'case_status' => $patientCase['case_status'] ?? 'N/A',
        'case_remarks' => trim((string)($patientCase['case_remarks'] ?? '')),
        'vaccinations' => [],
        'next_schedule' => null,
        'vaccine_name' => 'N/A'
    ];

    $vaccinationQuery = "
        SELECT
            vr.dose_number,
            vr.vaccination_status,
            vr.scheduled_date,
            vr.date_administered,
            COALESCE(vr.vaccine_name, i.item_name, 'Unknown Vaccine') AS vaccine_name
        FROM vaccination_records vr
        LEFT JOIN inventory_items i ON i.item_id = vr.item_id
        WHERE vr.patient_id = ?
          AND vr.case_id = ?
          AND vr.branch_id = ?
          AND vr.is_archived = 0
        ORDER BY vr.dose_number ASC, vr.vaccination_id ASC
    ";
    $vaccinationStmt = $conn->prepare($vaccinationQuery);
    if ($vaccinationStmt) {
        $vaccinationStmt->bind_param('iis', $patientId, $caseId, $branchId);
        $vaccinationStmt->execute();
        $vaccinationResult = $vaccinationStmt->get_result();
        while ($vaccination = $vaccinationResult->fetch_assoc()) {
            $data['vaccinations'][] = $vaccination;
            if ($data['vaccine_name'] === 'N/A' && !empty($vaccination['vaccine_name'])) {
                $data['vaccine_name'] = $vaccination['vaccine_name'];
            }
            if (
                $data['next_schedule'] === null &&
                ($vaccination['vaccination_status'] ?? '') === 'Scheduled' &&
                !empty($vaccination['scheduled_date']) &&
                strtotime($vaccination['scheduled_date']) >= strtotime(date('Y-m-d'))
            ) {
                $data['next_schedule'] = $vaccination['scheduled_date'];
            }
        }
        $vaccinationStmt->close();
    }

    if ($documentType === 'Vaccination Certificate') {
        $hasCompleted = false;
        foreach ($data['vaccinations'] as $vaccination) {
            if (
                ($vaccination['vaccination_status'] ?? '') === 'Completed' &&
                !empty($vaccination['date_administered'])
            ) {
                $hasCompleted = true;
                break;
            }
        }
        if (!$hasCompleted) {
            throw new Exception('A vaccination certificate requires at least one completed vaccination for this case.');
        }
    }

    return $data;
}

function pdfDrawImage(&$stream, $resourceName, $x, $y, $w, $h)
{
    $stream .= sprintf(
        "q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n",
        $w,
        $h,
        $x,
        $y,
        $resourceName
    );
}

/**
 * Convert the real project logo (logo.png) to JPEG bytes so it can be
 * embedded directly into the lightweight PDF writer.
 *
 * Expected location:
 * C:\\xampp\\htdocs\\SBI-ABC-SMARTBITECARE\\logo.png
 *
 * Because this PHP file is also in the project root, __DIR__/logo.png is
 * the portable path we should use instead of hard-coding C:\\xampp....
 */
function getSbiLogoForPdf()
{
    $logoPath = __DIR__ . DIRECTORY_SEPARATOR . 'logo.png';

    if (!is_file($logoPath) || !is_readable($logoPath)) {
        return null;
    }

    $imageInfo = @getimagesize($logoPath);
    if (!$imageInfo || empty($imageInfo[0]) || empty($imageInfo[1])) {
        return null;
    }

    // Preferred path for PNG: flatten transparency to white and re-encode
    // as JPEG. PDF can then use the standard DCTDecode image filter.
    if (
        function_exists('imagecreatefrompng') &&
        function_exists('imagecreatetruecolor') &&
        function_exists('imagejpeg')
    ) {
        $source = @imagecreatefrompng($logoPath);

        if ($source !== false) {
            $width = imagesx($source);
            $height = imagesy($source);
            $canvas = imagecreatetruecolor($width, $height);

            if ($canvas !== false) {
                $white = imagecolorallocate($canvas, 255, 255, 255);
                imagefill($canvas, 0, 0, $white);
                imagealphablending($canvas, true);
                imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

                ob_start();
                imagejpeg($canvas, null, 92);
                $jpegData = ob_get_clean();

                imagedestroy($canvas);
                imagedestroy($source);

                if ($jpegData !== false && $jpegData !== '') {
                    return [
                        'data' => $jpegData,
                        'width' => $width,
                        'height' => $height
                    ];
                }
            } else {
                imagedestroy($source);
            }
        }
    }

    // If GD is unavailable, do not break document generation. The header
    // renderer will show a small fallback SBI mark instead.
    return null;
}

function drawSbiPdfHeader(&$stream, $data, $title, $hasLogo = false)
{
    $blue = [0.04, 0.18, 0.50];
    $red = [0.90, 0.12, 0.16];
    $muted = [0.18, 0.22, 0.30];

    if ($hasLogo) {
        // Real /logo.png embedded by writePatientPdf().
        pdfDrawImage($stream, 'Logo', 38, 754, 67, 67);
    } else {
        // Fallback only if logo.png cannot be loaded.
        $cx = 71;
        $cy = 786;
        $r = 27;
        $k = 0.5522847498;
        $stream .= sprintf("%.3F %.3F %.3F RG 1.25 w\n", $blue[0], $blue[1], $blue[2]);
        $stream .= sprintf(
            "%.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c S\n",
            $cx+$r,$cy,
            $cx+$r,$cy+$k*$r,$cx+$k*$r,$cy+$r,$cx,$cy+$r,
            $cx-$k*$r,$cy+$r,$cx-$r,$cy+$k*$r,$cx-$r,$cy,
            $cx-$r,$cy-$k*$r,$cx-$k*$r,$cy-$r,$cx,$cy-$r,
            $cx+$k*$r,$cy-$r,$cx+$r,$cy-$k*$r,$cx+$r,$cy
        );
        pdfCenteredText($stream, 45, 776, 52, 'SBI Medical', 7.3, true, $blue);
    }

    pdfCenteredText($stream, 128, 806, 390, 'SBI MEDICAL', 14.5, true, $blue);
    pdfCenteredText($stream, 128, 794, 390, 'Animal Bite Center & Vaccination Clinic', 7.4, false, $muted);

    $branch = trim((string)($data['branch_name'] ?? ''));
    $address = trim((string)($data['branch_address'] ?? ''));
    $contact = trim((string)($data['branch_contact'] ?? ''));
    $email = trim((string)($data['branch_email'] ?? ''));

    pdfWriteText($stream, 166, 778, 'Branch:', 6.6, false, $muted);
    pdfWriteText($stream, 199, 778, $branch !== '' ? $branch : '________________________', 6.8);
    pdfWriteText($stream, 166, 768, 'Address:', 6.6, false, $muted);
    pdfWriteText($stream, 204, 768, $address !== '' ? $address : '______________________________', 6.6);
    pdfWriteText($stream, 166, 758, 'Contact No.:', 6.6, false, $muted);
    pdfWriteText($stream, 217, 758, $contact !== '' ? $contact : '_____________', 6.6);
    pdfWriteText($stream, 342, 758, '| Email:', 6.6, false, $muted);
    pdfWriteText($stream, 378, 758, $email !== '' ? $email : '________________', 6.6);

    pdfLine($stream, 36, 742, 559, 742, 1.35, $blue);
    pdfLine($stream, 36, 737, 559, 737, 1.0, $red);
    pdfCenteredText($stream, 36, 718, 523, strtoupper($title), 13.5, true, $blue);
}

function drawPdfLabelValueRow(&$stream, $x, $y, $width, $height, $leftLabel, $leftValue, $rightLabel, $rightValue)
{
    $labelFill = [0.965, 0.975, 0.995];
    $stroke = [0.62, 0.67, 0.76];
    $labelW = 96;
    $rightStart = $x + ($width * 0.59);
    $rightLabelW = 82;

    pdfRect($stream, $x, $y, $width, $height, null, $stroke, 0.55);
    pdfRect($stream, $x, $y, $labelW, $height, $labelFill, $stroke, 0.55);
    pdfRect($stream, $rightStart, $y, $rightLabelW, $height, $labelFill, $stroke, 0.55);
    pdfLine($stream, $rightStart, $y, $rightStart, $y + $height, 0.55, $stroke);
    pdfLine($stream, $rightStart + $rightLabelW, $y, $rightStart + $rightLabelW, $y + $height, 0.55, $stroke);
    pdfCellText($stream, $x, $y, $labelW, $height, $leftLabel, 7.0, true);
    pdfCellText($stream, $x + $labelW, $y, $rightStart - ($x + $labelW), $height, $leftValue, 7.2);
    pdfCellText($stream, $rightStart, $y, $rightLabelW, $height, $rightLabel, 7.0, true);
    pdfCellText($stream, $rightStart + $rightLabelW, $y, ($x + $width) - ($rightStart + $rightLabelW), $height, $rightValue, 7.2);
}

function drawSectionHeader(&$stream, $x, $y, $w, $text)
{
    $blue = [0.04, 0.18, 0.50];
    pdfRect($stream, $x, $y, $w, 20, [0.94, 0.96, 0.99], [0.60, 0.65, 0.74], 0.55);
    pdfCellText($stream, $x, $y, $w, 20, $text, 7.3, true, 'left', $blue);
}

function drawSignatureArea(&$stream, $leftTitle, $rightTitle, $baseY = 120)
{
    $blue = [0.04, 0.18, 0.50];
    $dark = [0.28, 0.31, 0.38];

    pdfWriteText($stream, 55, $baseY + 64, $leftTitle, 6.3, true, $blue);
    pdfWriteText($stream, 326, $baseY + 64, $rightTitle, 6.3, true, $blue);

    pdfLine($stream, 91, $baseY + 28, 245, $baseY + 28, 0.6, [0.35,0.35,0.35]);
    pdfLine($stream, 350, $baseY + 28, 505, $baseY + 28, 0.6, [0.35,0.35,0.35]);
    pdfCenteredText($stream, 88, $baseY + 17, 160, 'Printed Name & Signature', 5.8);
    pdfCenteredText($stream, 347, $baseY + 17, 160, 'Printed Name & Signature', 5.8);
    pdfWriteText($stream, 91, $baseY + 4, 'License/PRC No.: __________________', 5.7, false, $dark);
    pdfWriteText($stream, 350, $baseY + 4, 'Date: __________________', 5.7, false, $dark);
}

function getVaccinationDoseMap($data)
{
    $map = [];
    foreach (($data['vaccinations'] ?? []) as $vaccination) {
        $map[(int)($vaccination['dose_number'] ?? 0)] = $vaccination;
    }
    return $map;
}

function getTreatmentSummary($data)
{
    $names = [];
    foreach (($data['vaccinations'] ?? []) as $vaccination) {
        $name = trim((string)($vaccination['vaccine_name'] ?? ''));
        if ($name !== '' && strcasecmp($name, 'Unknown Vaccine') !== 0) {
            $names[$name] = true;
        }
    }

    if ($names) {
        return implode(', ', array_keys($names));
    }

    return 'See clinic treatment record';
}

function drawVaccinationCertificate(&$stream, $data, $hasLogo = false)
{
    drawSbiPdfHeader($stream, $data, 'Vaccination Certificate', $hasLogo);

    $x = 38;
    $w = 519;
    $h = 20;
    $y = 682;

    drawPdfLabelValueRow($stream, $x, $y, $w, $h, 'Certificate No.', $data['certificate_no'], 'Date Issued', $data['date_issued']);
    $y -= $h;
    drawPdfLabelValueRow($stream, $x, $y, $w, $h, 'Patient Name', $data['patient_name'], 'Case No.', $data['case_number']);
    $y -= $h;
    drawPdfLabelValueRow($stream, $x, $y, $w, $h, 'Date of Birth', $data['birthday'], 'Age', $data['age']);
    $y -= $h;
    drawPdfLabelValueRow($stream, $x, $y, $w, $h, 'Sex', $data['sex'], 'PhilHealth No.', '');
    $y -= $h;
    drawPdfLabelValueRow($stream, $x, $y, $w, $h, 'Address', $data['address'], '', '');

    $y -= 18;
    $intro = 'This is to certify that the above-named patient received vaccination at SBI Medical Animal Bite Center & Vaccination Clinic.';
    foreach (pdfWrapText($intro, 6.9, 510) as $line) {
        pdfWriteText($stream, $x, $y, $line, 6.9);
        $y -= 9;
    }
    pdfWriteText($stream, $x, $y, 'The vaccination details below reflect the immunization administered or scheduled at the clinic.', 6.9);

    $y -= 27;
    drawSectionHeader($stream, $x, $y, $w, 'VACCINE INFORMATION');
    $y -= 20;

    $vaccineRows = [
        ['Vaccine Name', $data['vaccine_name']],
        ['Vaccine Brand / Manufacturer', 'As recorded by the clinic'],
        ['Vaccination Category / Purpose', 'Animal-bite post-exposure vaccination'],
        ['Route / Site', 'As documented by authorized clinic personnel']
    ];

    foreach ($vaccineRows as $row) {
        pdfRect($stream, $x, $y, $w, 20, null, [0.60,0.65,0.74], 0.55);
        pdfRect($stream, $x, $y, 158, 20, [0.965,0.975,0.995], [0.60,0.65,0.74], 0.55);
        pdfCellText($stream, $x, $y, 158, 20, $row[0], 6.9, true);
        pdfCellText($stream, $x + 158, $y, $w - 158, 20, $row[1], 7.0);
        $y -= 20;
    }

    $y -= 6;
    $cols = [118, 132, 145, 124];
    $headers = ['DOSE / VACCINATION', 'SCHEDULED DATE', 'ADMINISTERED DATE', 'STATUS'];
    $cx = $x;
    foreach ($cols as $i => $cw) {
        pdfRect($stream, $cx, $y, $cw, 20, [0.94,0.96,0.99], [0.60,0.65,0.74], 0.55);
        pdfCellText($stream, $cx, $y, $cw, 20, $headers[$i], 6.4, true, 'center', [0.04,0.18,0.50]);
        $cx += $cw;
    }
    $y -= 20;

    $doseMap = getVaccinationDoseMap($data);
    for ($dose = 1; $dose <= 5; $dose++) {
        $v = $doseMap[$dose] ?? [];
        $scheduled = !empty($v['scheduled_date']) ? date('M d, Y', strtotime($v['scheduled_date'])) : '';
        $administered = !empty($v['date_administered']) ? date('M d, Y', strtotime($v['date_administered'])) : '';
        $status = trim((string)($v['vaccination_status'] ?? ''));
        if ($status === '') {
            $status = 'Pending';
        }

        $suffix = 'th';
        if ($dose === 1) $suffix = 'st';
        elseif ($dose === 2) $suffix = 'nd';
        elseif ($dose === 3) $suffix = 'rd';

        $values = [$dose . $suffix . ' Dose', $scheduled, $administered, $status];
        $cx = $x;
        foreach ($cols as $i => $cw) {
            pdfRect($stream, $cx, $y, $cw, 20, null, [0.60,0.65,0.74], 0.55);
            pdfCellText($stream, $cx, $y, $cw, 20, $values[$i], 6.8, false, 'center');
            $cx += $cw;
        }
        $y -= 20;
    }

    $y -= 14;
    pdfWriteText($stream, $x, $y, 'Remarks / Additional Information', 6.8);
    $remarksBoxY = $y - 47;
    pdfRect($stream, $x, $remarksBoxY, $w, 39, null, [0.60,0.65,0.74], 0.55);
    $remarks = $data['case_remarks'] !== '' ? $data['case_remarks'] : 'No additional remarks recorded.';
    $ry = $remarksBoxY + 27;
    foreach (array_slice(pdfWrapText($remarks, 6.8, $w - 12), 0, 3) as $line) {
        pdfWriteText($stream, $x + 6, $ry, $line, 6.8);
        $ry -= 9;
    }

    drawSignatureArea($stream, 'ATTENDING HEALTHCARE PROFESSIONAL', 'AUTHORIZED SIGNATORY', 70);
    pdfLine($stream, 35, 58, 560, 58, 0.5, [0.65,0.68,0.74]);
    pdfWriteText($stream, 35, 47, 'CONFIDENTIAL MEDICAL DOCUMENT - Vaccination entries must be completed and verified by authorized clinic personnel.', 5.5, false, [0.28,0.31,0.38]);
}

function drawMedicalCertificate(&$stream, $data, $hasLogo = false)
{
    drawSbiPdfHeader($stream, $data, 'Medical Certificate', $hasLogo);

    $blue = [0.04, 0.18, 0.50];
    $x = 42;
    $w = 511;
    $y = 682;
    $h = 20;

    drawPdfLabelValueRow($stream, $x, $y, $w, $h, 'Certificate No.', $data['certificate_no'], 'Date Issued', $data['date_issued']);
    $y -= $h;
    drawPdfLabelValueRow($stream, $x, $y, $w, $h, 'Patient Name', $data['patient_name'], 'Case No.', $data['case_number']);
    $y -= $h;
    drawPdfLabelValueRow($stream, $x, $y, $w, $h, 'Date of Birth', $data['birthday'], 'Age', $data['age']);
    $y -= $h;
    drawPdfLabelValueRow($stream, $x, $y, $w, $h, 'Sex', $data['sex'], 'Contact No.', $data['contact_number']);
    $y -= $h;
    drawPdfLabelValueRow($stream, $x, $y, $w, $h, 'Address', $data['address'], '', '');

    $y -= 20;
    $intro = 'This is to certify that the above-named patient was examined/treated at this clinic in connection with an animal bite or rabies exposure and was provided the appropriate medical management and follow-up instructions.';
    foreach (pdfWrapText($intro, 6.9, $w) as $line) {
        pdfWriteText($stream, $x, $y, $line, 6.9);
        $y -= 9;
    }

    $y -= 10;
    drawSectionHeader($stream, $x, $y, $w, 'ANIMAL BITE / EXPOSURE DETAILS');
    $y -= 20;

    $detailRows = [
        ['Date of Bite/Exposure', $data['bite_date']],
        ['Animal', $data['animal_type']],
        ['Site of Bite/Exposure', $data['bite_location']],
        ['Type / Category of Exposure', $data['bite_category']],
        ['Treatment Given', getTreatmentSummary($data)]
    ];

    foreach ($detailRows as $row) {
        pdfRect($stream, $x, $y, $w, 20, null, [0.60,0.65,0.74], 0.55);
        pdfRect($stream, $x, $y, 155, 20, [0.965,0.975,0.995], [0.60,0.65,0.74], 0.55);
        pdfCellText($stream, $x, $y, 155, 20, $row[0], 6.8, true);
        pdfCellText($stream, $x + 155, $y, $w - 155, 20, $row[1], 6.9);
        $y -= 20;
    }

    $y -= 15;
    pdfWriteText($stream, $x, $y, 'Medical Findings / Remarks', 6.9, true);
    $findingsY = $y - 50;
    pdfRect($stream, $x, $findingsY, $w, 42, null, [0.60,0.65,0.74], 0.55);
    $remarks = $data['case_remarks'] !== '' ? $data['case_remarks'] : 'No additional medical findings or remarks recorded.';
    $fy = $findingsY + 29;
    foreach (array_slice(pdfWrapText($remarks, 6.8, $w - 12), 0, 3) as $line) {
        pdfWriteText($stream, $x + 6, $fy, $line, 6.8);
        $fy -= 9;
    }

    $y = $findingsY - 14;
    $cols = [165, 165, 181];
    $headers = ['VACCINATION FOLLOW-UP', 'SCHEDULED DATE', 'STATUS / REMARKS'];
    $cx = $x;
    foreach ($cols as $i => $cw) {
        pdfRect($stream, $cx, $y, $cw, 20, [0.94,0.96,0.99], [0.60,0.65,0.74], 0.55);
        pdfCellText($stream, $cx, $y, $cw, 20, $headers[$i], 6.3, true, 'center', $blue);
        $cx += $cw;
    }
    $y -= 20;

    $doseMap = getVaccinationDoseMap($data);
    $followups = [1 => 'D0', 2 => 'D3', 3 => 'D7', 4 => 'D14', 6 => 'D28'];
    foreach ($followups as $doseNumber => $label) {
        $v = $doseMap[$doseNumber] ?? [];
        $scheduled = !empty($v['scheduled_date']) ? date('M d, Y', strtotime($v['scheduled_date'])) : '';
        $status = $v['vaccination_status'] ?? '';
        if (!empty($v['date_administered'])) {
            $status .= ($status !== '' ? ' - ' : '') . 'Given ' . date('M d, Y', strtotime($v['date_administered']));
        }
        $values = [$label, $scheduled, $status];
        $cx = $x;
        foreach ($cols as $i => $cw) {
            pdfRect($stream, $cx, $y, $cw, 19, null, [0.60,0.65,0.74], 0.55);
            pdfCellText($stream, $cx, $y, $cw, 19, $values[$i], 6.6, false, 'center');
            $cx += $cw;
        }
        $y -= 19;
    }

    $y -= 12;
    $note = 'The patient is advised to follow the prescribed vaccination schedule and return for the next dose(s) as instructed by the attending healthcare professional.';
    foreach (pdfWrapText($note, 5.9, $w) as $line) {
        pdfWriteText($stream, $x, $y, $line, 5.9);
        $y -= 8;
    }

    drawSignatureArea($stream, 'ATTENDING HEALTHCARE PROFESSIONAL', 'CLINIC / AUTHORIZED SIGNATORY', 60);
    pdfLine($stream, 35, 49, 560, 49, 0.5, [0.65,0.68,0.74]);
    pdfWriteText($stream, 35, 38, 'This certificate is issued upon request for documentation purposes. Contents should be verified by authorized clinic personnel.', 5.3, false, [0.28,0.31,0.38]);
}

function drawReferralLetter(&$stream, $data, $hasLogo = false)
{
    drawSbiPdfHeader($stream, $data, 'Referral Letter', $hasLogo);

    $blue = [0.04, 0.18, 0.50];
    $x = 42;
    $w = 511;
    $y = 685;

    $referralLines = [
        ['Date', $data['date_issued']],
        ['To', 'THE MEDICAL OFFICER / EMERGENCY DEPARTMENT'],
        ['Facility', 'Receiving Hospital / Referral Facility'],
        ['Address', 'To be completed by referring clinic'],
        ['Subject', 'Referral for Further Evaluation and Management of Animal Bite/Exposure']
    ];

    foreach ($referralLines as $row) {
        pdfWriteText($stream, $x, $y, $row[0], 7.2, true);
        pdfWriteText($stream, $x + 76, $y, $row[1], 7.3, $row[0] === 'To' || $row[0] === 'Facility', $row[0] === 'Facility' ? $blue : [0,0,0]);
        $y -= 18;
    }

    $y -= 5;
    pdfWriteText($stream, $x, $y, 'Dear Sir/Madam:', 7.3);
    $y -= 18;

    $intro = 'We respectfully refer the patient identified below for further evaluation and management following an animal bite/exposure. The patient was initially assessed and managed at our Animal Bite Center. Kindly evaluate and provide further treatment as clinically indicated.';
    foreach (pdfWrapText($intro, 6.9, $w) as $line) {
        pdfWriteText($stream, $x, $y, $line, 6.9);
        $y -= 9;
    }

    $y -= 9;
    drawSectionHeader($stream, $x, $y, $w, 'PATIENT INFORMATION');
    $y -= 20;
    drawPdfLabelValueRow($stream, $x, $y, $w, 20, 'Patient Name', $data['patient_name'], 'Case No.', $data['case_number']);
    $y -= 20;
    drawPdfLabelValueRow($stream, $x, $y, $w, 20, 'Date of Birth', $data['birthday'], 'Age', $data['age']);
    $y -= 20;
    drawPdfLabelValueRow($stream, $x, $y, $w, 20, 'Sex', $data['sex'], 'Contact No.', $data['contact_number']);
    $y -= 20;
    drawPdfLabelValueRow($stream, $x, $y, $w, 20, 'Address', $data['address'], '', '');

    $y -= 26;
    drawSectionHeader($stream, $x, $y, $w, 'ANIMAL BITE / EXPOSURE DETAILS');
    $y -= 20;

    $detailRows = [
        ['Date of Bite/Exposure', $data['bite_date']],
        ['Animal', $data['animal_type']],
        ['Site of Exposure', $data['bite_location']],
        ['Type / Category of Exposure', $data['bite_category']],
        ['Initial Assessment / Case Status', $data['case_status']],
        ['Treatment Given', getTreatmentSummary($data)]
    ];

    foreach ($detailRows as $row) {
        pdfRect($stream, $x, $y, $w, 18, null, [0.60,0.65,0.74], 0.55);
        pdfRect($stream, $x, $y, 170, 18, [0.965,0.975,0.995], [0.60,0.65,0.74], 0.55);
        pdfCellText($stream, $x, $y, 170, 18, $row[0], 6.4, true);
        pdfCellText($stream, $x + 170, $y, $w - 170, 18, $row[1], 6.5);
        $y -= 18;
    }

    $y -= 12;
    pdfWriteText($stream, $x, $y, 'REASON FOR REFERRAL', 6.8, true, $blue);
    $reasonY = $y - 56;
    pdfRect($stream, $x, $reasonY, $w, 47, null, [0.60,0.65,0.74], 0.55);
    $reason = $data['case_remarks'] !== ''
        ? $data['case_remarks']
        : 'Further evaluation and appropriate management of the recorded animal-bite exposure.';
    $ry = $reasonY + 33;
    foreach (array_slice(pdfWrapText($reason, 6.8, $w - 12), 0, 4) as $line) {
        pdfWriteText($stream, $x + 6, $ry, $line, 6.8);
        $ry -= 9;
    }

    $closingY = $reasonY - 17;
    pdfWriteText($stream, $x, $closingY, 'We respectfully request further evaluation and appropriate management as clinically indicated.', 6.5);
    pdfWriteText($stream, $x + 8, $closingY - 26, 'Respectfully referred by:', 6.5, true, $blue);

    pdfLine($stream, 88, 92, 242, 92, 0.6, [0.35,0.35,0.35]);
    pdfLine($stream, 350, 92, 505, 92, 0.6, [0.35,0.35,0.35]);
    pdfCenteredText($stream, 84, 80, 165, 'Attending Healthcare Professional', 5.8);
    pdfCenteredText($stream, 346, 80, 165, 'Authorized Signatory', 5.8);
    pdfWriteText($stream, 88, 67, 'License/PRC No.: __________________', 5.5);
    pdfWriteText($stream, 350, 67, 'Date: __________________', 5.5);
    pdfLine($stream, 35, 52, 560, 52, 0.5, [0.65,0.68,0.74]);
    pdfWriteText($stream, 35, 41, 'CONFIDENTIAL MEDICAL DOCUMENT - All information must be completed and verified by authorized clinic personnel.', 5.3, false, [0.28,0.31,0.38]);
}

/**
 * Create a styled printable PDF that visually matches the SBI templates.
 * The real logo.png in the project root is embedded whenever PHP GD is
 * available. If GD is missing, document generation still works with a
 * fallback vector mark instead of failing.
 */
function writePatientPdf($filePath, $documentType, $data)
{
    $logo = getSbiLogoForPdf();
    $hasLogo = is_array($logo) && !empty($logo['data']);

    $stream = '';
    if ($documentType === 'Vaccination Certificate') {
        drawVaccinationCertificate($stream, $data, $hasLogo);
    } elseif ($documentType === 'Medical Certificate') {
        drawMedicalCertificate($stream, $data, $hasLogo);
    } elseif ($documentType === 'Referral Letter') {
        drawReferralLetter($stream, $data, $hasLogo);
    } else {
        throw new Exception('Unsupported patient document template.');
    }

    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids [5 0 R] /Count 1 >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

    $resources = '/Font << /F1 3 0 R /F2 4 0 R >>';

    if ($hasLogo) {
        $resources .= ' /XObject << /Logo 7 0 R >>';
    }

    $objects[5] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << ' . $resources . ' >> /Contents 6 0 R >>';
    $objects[6] = '<< /Length ' . strlen($stream) . ">>\nstream\n{$stream}\nendstream";

    if ($hasLogo) {
        $objects[7] =
            '<< /Type /XObject /Subtype /Image' .
            ' /Width ' . (int)$logo['width'] .
            ' /Height ' . (int)$logo['height'] .
            ' /ColorSpace /DeviceRGB /BitsPerComponent 8' .
            ' /Filter /DCTDecode' .
            ' /Length ' . strlen($logo['data']) .
            ">>\nstream\n" . $logo['data'] . "\nendstream";
    }

    ksort($objects);

    $pdf = "%PDF-1.4\n%âãÏÓ\n";
    $offsets = [0 => 0];

    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
    }

    $xref = strlen($pdf);
    $maxId = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";

    for ($id = 1; $id <= $maxId; $id++) {
        $pdf .= sprintf('%010d 00000 n ', $offsets[$id] ?? 0) . "\n";
    }

    $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n{$xref}\n%%EOF";

    return file_put_contents($filePath, $pdf) !== false;
}

function getBranchInfo($branch_id)
{
    global $conn;

    $sql = "SELECT *
            FROM branches
            WHERE branch_id = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $branch_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row;
}

// Get status badge class
function getStatusBadge($status)
{
    $status = strtolower((string) $status);
    $class = 'status-badge ';

    if (
        $status === 'ongoing' ||
        $status === 'active' ||
        $status === 'scheduled'
    ) {
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
    <title>Nurse - Patient Module</title>
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <!-- Reusable Sidebar CSS (simulated) -->
    <link rel="stylesheet" href="sidebar.css" />
    <style>
        /* =========================================
           INTERNAL CSS – matches image style
           ========================================= */
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

        * {
            box-sizing: border-box;
        }

        body {
            background: #f0f2f5;
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

        /* ---- page header ---- */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 28px;
        }
        .page-header h2 {
            font-size: 26px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
        }
        .page-header .badge-role {
            background: var(--primary);
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            padding: 6px 16px;
            border-radius: 30px;
            letter-spacing: 0.3px;
            margin-left: 12px;
        }

        /* ---- search ---- */
        .search-wrap {
            position: relative;
            max-width: 420px;
            margin-bottom: 28px;
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

        /* ---- table ---- */
        .table-wrap {
            background: white;
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            padding: 0;
            margin-bottom: 20px;
        }
        .table {
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }
        .table thead th {
            background: var(--primary);
            color: #fff;
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
        .action-icon {
            font-size: 22px;
            color: var(--primary);
            cursor: pointer;
            opacity: 0.7;
            transition: 0.1s;
            text-decoration: none;
            padding: 0 4px;
        }
        .action-icon:hover {
            opacity: 1;
        }

        /* Pagination */
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
            padding: 8px 16px;
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

        /* Modal styles */
        .modal-content {
            border-radius: 18px;
        }
        .modal-header {
            border-bottom: 2px solid #f0f3fc;
            padding: 20px 24px;
        }
        .modal-header .modal-title {
            color: var(--primary);
            font-weight: 700;
        }
        .modal-body {
            padding: 24px;
        }
        .modal-footer {
            border-top: 2px solid #f0f3fc;
            padding: 16px 24px;
        }
        .document-option {
            padding: 15px 20px;
            border: 2px solid #e2e7f2;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.15s;
            margin-bottom: 10px;
        }
        .document-option:hover {
            border-color: var(--primary);
            background: #f8f9ff;
        }
        .document-option.selected {
            border-color: var(--primary);
            background: #e8ebf8;
        }
        .document-option i {
            font-size: 28px;
            color: var(--primary);
            margin-right: 12px;
        }
        .document-option .doc-title {
            font-weight: 600;
            color: #1f2a4a;
        }
        .document-option .doc-desc {
            font-size: 13px;
            color: #7a85a8;
        }

        /* Patient info display in modal */
        .patient-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        .patient-info-item {
            background: #f8f9ff;
            padding: 12px 16px;
            border-radius: 12px;
        }
        .patient-info-item label {
            font-size: 12px;
            color: #7a85a8;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: block;
            margin-bottom: 4px;
        }
        .patient-info-item .value {
            font-weight: 600;
            color: #1f2a4a;
            font-size: 15px;
        }

        /* Toast/Alert */
        .alert-toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
        }

        /* responsive */
        @media (max-width: 991px) {
            .main {
                margin-left: 90px;
            }
            .sidebar {
                width: 90px;
                padding: 16px 10px;
            }
            .system-name,
            .nav-menu span,
            .logout span {
                display: none;
            }
            .logo-area {
                justify-content: center;
            }
            .nav-menu a {
                justify-content: center;
                padding: 12px 8px;
            }
            .nav-menu a i {
                font-size: 26px;
                margin: 0;
            }
            .logout a {
                justify-content: center;
            }
            .topbar h3 {
                font-size: 22px;
            }
            .branch-indicator {
                font-size: 12px;
                padding: 4px 14px 4px 12px;
            }
        }

        @media (max-width: 576px) {
            .topbar {
                padding: 0 16px;
                height: auto;
                min-height: 70px;
                flex-wrap: wrap;
                gap: 8px;
                padding: 12px 16px;
            }
            .topbar h3 {
                font-size: 18px;
            }
            .topbar-left {
                flex-wrap: wrap;
                gap: 10px;
            }
            .branch-indicator {
                font-size: 11px;
                padding: 4px 12px 4px 10px;
            }
            .content {
                padding: 20px 16px;
            }
            .page-header h2 {
                font-size: 22px;
            }
            .table-wrap {
                overflow-x: auto;
            }
            .table thead th,
            .table tbody td {
                padding: 12px 14px;
                font-size: 14px;
            }
            .search-wrap {
                max-width: 100%;
            }
            .alert-toast {
                min-width: 90%;
                right: 5%;
                top: 10px;
            }
            .pagination-wrap .page-link {
                padding: 6px 12px;
                font-size: 13px;
            }
            .profile {
                font-size: 13px;
            }
        }
    </style>
</head>
<body>

<!-- ========== ALERT TOAST ========== -->
<?php if (isset($success_message)): ?>
<div class="alert-toast alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i> <?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
    <?php if (!empty($generated_document_path)): ?>
        <a
            class="alert-link ms-2"
            href="<?php echo htmlspecialchars($generated_document_path, ENT_QUOTES, 'UTF-8'); ?>"
            target="_blank"
            rel="noopener"
        >Open PDF</a>
    <?php endif; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (isset($error_message)): ?>
<div class="alert-toast alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-circle-fill me-2"></i> <?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ========== SIDEBAR (Nurse) ========== -->
<div class="sidebar">
    <div class="logo-area">
        <div class="logo-frame">
            <img src="logo.png" alt="Smart Bite Care Logo" class="logo" />
        </div>
        <div class="system-name">Smart Bite Care</div>
    </div>

       <nav class="nav-menu">
        <ul>
            <li><a href="Nurse_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a class="active" href="Nurse_Patients.php"><i class="bi bi-heart-pulse-fill"></i><span>Patients</span></a></li>
            <li><a href="Nurse_Assessment.php"><i class="bi bi-clipboard2-pulse-fill"></i><span>Assessment Queue</span></a></li>
            <li><a href="Nurse_Vaccination.php"><i class="bi-shield-plus"></i><span>Vaccination</span></a></li>
            <li><a href="Nurse_DailyInventory.php"><i class="bi bi-clipboard-data-fill"></i><span>Daily Inventory</span></a></li>
            <li><a href="Nurse_MedicalSuppliesManagement.php"><i class="bi bi-calendar-check"></i><span>Medical Supplies Management</span></a></li>
            <li><a href="Nurse_Supplyforecasting.php"><i class="bi bi-box-seam"></i><span>Supply Forecasting</span></a></li>
            <li>
                <a href="Nurse_Notification.php">
                    <i class="bi bi-bell-fill"></i>

                    <span class="notification-label">
                        Notifications

                        <?php if ($notification_count > 0): ?>
                            <span class="notification-badge">
                                <?php echo $notification_count; ?>
                            </span>
                        <?php endif; ?>
                    </span>
                </a>
            </li>
        </ul>
    </nav>

    
</div>

<!-- ========== MAIN CONTENT ========== -->
<div class="main">

    <!-- TOP BAR -->
    <div class="topbar">
        <h3>Patients <small><?php echo htmlspecialchars($branch_name); ?></small></h3>

       <div class="dropdown">
            <button class="profile dropdown-toggle border-0 bg-transparent px-3 py-2 rounded-3"
                    type="button" id="nurseProfileMenu"
                    data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle"></i>
                <span><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></span>
                <span style="font-size:12px; color:#adb5bd; font-weight:400; margin-left:4px;">| Nurse</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end border-0 shadow p-2 mt-2"
                aria-labelledby="nurseProfileMenu">
                <li><h6 class="dropdown-header">Account options</h6></li>
                <li>
                    <a class="dropdown-item rounded-2 py-2" href="Account_ChangePassword.php">
                        <i class="bi bi-key-fill me-2"></i>Change Password
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item rounded-2 py-2 text-danger" href="logout.php"
                       onclick="return window.confirm('Are you sure you want to log out?');">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="content">

        <!-- Search -->
        <form method="GET" action="">
            <div class="search-wrap">
                <i class="bi bi-search"></i>
                <input type="text" name="search" placeholder="Search Patients" value="<?php echo htmlspecialchars($search); ?>" />
            </div>
        </form>

        <!-- Recent Patients Table -->
        <div class="table-wrap">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Patient Name</th>
                        <th>Last Visit</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($patients) > 0): ?>
                        <?php foreach ($patients as $patient): ?>
                        <tr>
                            <td><strong>P<?php echo str_pad($patient['patient_id'], 4, '0', STR_PAD_LEFT); ?></strong></td>
                            <td><?php echo htmlspecialchars($patient['full_name']); ?></td>
                            <td><?php echo $patient['date_of_bite'] ? date('M d, Y', strtotime($patient['date_of_bite'])) : 'N/A'; ?></td>
                            <td><span class="<?php echo getStatusBadge($patient['case_status']); ?>"><?php echo $patient['case_status'] ?: 'N/A'; ?></span></td>
                            <td>
                                <a href="#" class="action-icon me-2" onclick="viewPatient(<?php echo $patient['patient_id']; ?>)" title="View Patient">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="#" class="action-icon" onclick="openDocumentModal(<?php echo $patient['patient_id']; ?>)" title="Generate Document">
                                    <i class="bi bi-file-earmark-text"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-4">No patients found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination-wrap">
            <nav aria-label="Page navigation">
                <ul class="pagination">
                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <div class="pagination-info">
            Showing <?php echo ($offset + 1); ?> - <?php echo min($offset + $limit, $total_rows); ?> of <?php echo $total_rows; ?> patients
        </div>
        <?php endif; ?>

    </div> <!-- /content -->
</div> <!-- /main -->

<!-- ========== VIEW PATIENT MODAL ========== -->
<div class="modal fade" id="viewPatientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-badge me-2"></i>Patient Information</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="patientInfoBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ========== DOCUMENT GENERATION MODAL ========== -->
<div class="modal fade" id="documentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Generate Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="Nurse_Patients.php" id="documentGenerationForm">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>"
                >

                <div class="modal-body">
                    <p class="text-muted">Select the type of document to generate.</p>
                    
                    <div class="document-option" onclick="selectDocument(this, 'Medical Certificate')">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-file-earmark-check"></i>
                            <div>
                                <div class="doc-title">Medical Certificate</div>
                                <div class="doc-desc">Certificate for medical treatment and fitness</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="document-option" onclick="selectDocument(this, 'Referral Letter')">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-file-earmark-arrow-up"></i>
                            <div>
                                <div class="doc-title">Referral Letter</div>
                                <div class="doc-desc">Letter for patient referral to specialist</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="document-option" onclick="selectDocument(this, 'Vaccination Certificate')">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-file-earmark-check"></i>
                            <div>
                                <div class="doc-title">Vaccination Certificate</div>
                                <div class="doc-desc">Certificate for vaccination records</div>
                            </div>
                        </div>
                    </div>
                    
                    <input type="hidden" name="patient_id" id="doc_patient_id" value="">
                    <input type="hidden" name="case_id" id="doc_case_id" value="">
                    <input type="hidden" name="document_type" id="doc_type" value="">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="generate_document" class="btn btn-primary" id="generateBtn" disabled>Generate Document</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// View patient function - loads branch-secured data via AJAX
function viewPatient(patientId) {
    var modal = new bootstrap.Modal(
        document.getElementById('viewPatientModal')
    );

    var body = document.getElementById('patientInfoBody');

    body.innerHTML =
        '<div class="text-center py-4">' +
        '<div class="spinner-border text-primary" role="status">' +
        '<span class="visually-hidden">Loading...</span>' +
        '</div>' +
        '</div>';

    modal.show();

    fetch(
        'Nurse_Patients.php?ajax=get_patient&patient_id=' +
        encodeURIComponent(patientId)
    )
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            body.innerHTML =
                '<div class="alert alert-danger">' +
                'Error loading patient data: ' +
                escapeHtml(data.message || 'Unknown error') +
                '</div>';
            return;
        }

        var html = '';
        var p = data.patient || {};
        var cases = data.cases || [];
        var vaccines = data.vaccinations || [];

        html += '<div class="patient-info-grid">';

        html +=
            '<div class="patient-info-item">' +
            '<label>Full Name</label>' +
            '<div class="value">' +
            escapeHtml(p.full_name || 'N/A') +
            '</div>' +
            '</div>';

        html +=
            '<div class="patient-info-item">' +
            '<label>Email</label>' +
            '<div class="value">' +
            escapeHtml(p.email || 'N/A') +
            '</div>' +
            '</div>';

        html +=
            '<div class="patient-info-item">' +
            '<label>Contact</label>' +
            '<div class="value">' +
            escapeHtml(p.contact_number || 'N/A') +
            '</div>' +
            '</div>';

        html +=
            '<div class="patient-info-item">' +
            '<label>Gender</label>' +
            '<div class="value">' +
            escapeHtml(p.gender || 'N/A') +
            '</div>' +
            '</div>';

        var birthday = 'N/A';

        if (p.birthday) {
            birthday = new Date(
                p.birthday
            ).toLocaleDateString(
                'en-US',
                {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                }
            );
        }

        html +=
            '<div class="patient-info-item">' +
            '<label>Birthday</label>' +
            '<div class="value">' +
            escapeHtml(birthday) +
            '</div>' +
            '</div>';

        html +=
            '<div class="patient-info-item">' +
            '<label>Address</label>' +
            '<div class="value">' +
            escapeHtml(p.address || 'N/A') +
            '</div>' +
            '</div>';

        html += '</div>';

        // Case history
        if (cases.length > 0) {
            html +=
                '<h6 class="fw-bold text-primary mt-3">' +
                'Case History' +
                '</h6>';

            cases.forEach(function(c) {
                var status =
                    c.case_status ||
                    'N/A';

                var lowerStatus =
                    status.toLowerCase();

                var statusClass =
                    (
                        lowerStatus === 'ongoing' ||
                        lowerStatus === 'active' ||
                        lowerStatus === 'scheduled'
                    )
                    ? 'ongoing'
                    : 'completed';

                html +=
                    '<div class="border p-3 rounded mb-2 bg-light">' +
                    '<div class="row">' +

                    '<div class="col-md-3">' +
                    '<strong>Case ID:</strong> C' +
                    escapeHtml(
                        String(c.case_id || '')
                        .padStart(4, '0')
                    ) +
                    '</div>' +

                    '<div class="col-md-3">' +
                    '<strong>Animal:</strong> ' +
                    escapeHtml(c.animal_type || 'N/A') +
                    '</div>' +

                    '<div class="col-md-3">' +
                    '<strong>Bite Location:</strong> ' +
                    escapeHtml(c.bite_location || 'N/A') +
                    '</div>' +

                    '<div class="col-md-3">' +
                    '<strong>Status:</strong> ' +
                    '<span class="status-badge ' +
                    statusClass +
                    '">' +
                    escapeHtml(status) +
                    '</span>' +
                    '</div>' +

                    '</div>' +
                    '</div>';
            });
        }

        // Vaccination history
        if (vaccines.length > 0) {
            html +=
                '<h6 class="fw-bold text-primary mt-3">' +
                'Vaccination History' +
                '</h6>';

            html += '<div class="table-responsive">';
            html += '<table class="table table-sm table-bordered">';
            html +=
                '<thead>' +
                '<tr>' +
                '<th>Vaccine</th>' +
                '<th>Dose</th>' +
                '<th>Date</th>' +
                '<th>Status</th>' +
                '</tr>' +
                '</thead>';
            html += '<tbody>';

            vaccines.forEach(function(v) {
                var status =
                    v.vaccination_status ||
                    'N/A';

                var lowerStatus =
                    status.toLowerCase();

                var statusClass =
                    (
                        lowerStatus === 'scheduled' ||
                        lowerStatus === 'ongoing'
                    )
                    ? 'ongoing'
                    : 'completed';

                var vaccinationDate =
                    v.date_administered ||
                    v.scheduled_date ||
                    null;

                var dateText = 'N/A';

                if (vaccinationDate) {
                    dateText =
                        new Date(
                            vaccinationDate
                        ).toLocaleDateString(
                            'en-US',
                            {
                                month: 'short',
                                day: 'numeric',
                                year: 'numeric'
                            }
                        );
                }

                html += '<tr>';

                html +=
                    '<td>' +
                    escapeHtml(
                        v.item_name ||
                        v.vaccine_name ||
                        'N/A'
                    ) +
                    '</td>';

                html +=
                    '<td>' +
                    escapeHtml(
                        'Dose ' +
                        (v.dose_number || '')
                    ) +
                    '</td>';

                html +=
                    '<td>' +
                    escapeHtml(dateText) +
                    '</td>';

                html +=
                    '<td>' +
                    '<span class="status-badge ' +
                    statusClass +
                    '">' +
                    escapeHtml(status) +
                    '</span>' +
                    '</td>';

                html += '</tr>';
            });

            html += '</tbody></table></div>';
        }

        if (
            cases.length === 0 &&
            vaccines.length === 0
        ) {
            html +=
                '<p class="text-muted text-center mt-3">' +
                'No case history or vaccination records found.' +
                '</p>';
        }

        body.innerHTML = html;
    })
    .catch(error => {
        body.innerHTML =
            '<div class="alert alert-danger">' +
            'Error loading patient data. Please try again.' +
            '</div>';

        console.error('Error:', error);
    });
}

// Document modal
function openDocumentModal(patientId) {
    fetch(
        'Nurse_Patients.php?ajax=get_latest_case&patient_id=' +
        encodeURIComponent(patientId)
    )
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            alert(data.message || 'Unable to load patient case.');
            return;
        }

        if (!data.case_id) {
            alert('This patient does not have a case available for document generation.');
            return;
        }

        document.getElementById('doc_patient_id').value =
            patientId;

        document.getElementById('doc_case_id').value =
            data.case_id;

        document.getElementById('doc_type').value =
            '';

        document.getElementById('generateBtn').disabled =
            true;

        document
            .querySelectorAll('.document-option')
            .forEach(
                el => el.classList.remove('selected')
            );

        var modal =
            new bootstrap.Modal(
                document.getElementById('documentModal')
            );

        modal.show();
    })
    .catch(error => {
        alert('Error loading patient data. Please try again.');
        console.error(error);
    });
}

function selectDocument(element, type) {
    // Remove selection from all
    document.querySelectorAll('.document-option').forEach(el => el.classList.remove('selected'));
    
    // Select this
    element.classList.add('selected');
    
    // Set type
    document.getElementById('doc_type').value = type;
    document.getElementById('generateBtn').disabled = false;
}

// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    var documentForm = document.getElementById('documentGenerationForm');

    if (documentForm) {
        documentForm.addEventListener('submit', function(event) {
            event.preventDefault();

            var patientId = document.getElementById('doc_patient_id').value;
            var caseId = document.getElementById('doc_case_id').value;
            var documentType = document.getElementById('doc_type').value;
            var button = document.getElementById('generateBtn');
            var originalButtonHtml = button.innerHTML;

            if (!patientId || !caseId || !documentType) {
                alert('Select a patient case and document type first.');
                return;
            }

            var formData = new FormData(documentForm);
            // FormData(form) does not include the submit button automatically,
            // so add the PHP action flag explicitly.
            formData.append('generate_document', '1');

            button.disabled = true;
            button.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' +
                'Generating PDF...';

            fetch(documentForm.action || 'Nurse_Patients.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(async function(response) {
                var data;
                try {
                    data = await response.json();
                } catch (error) {
                    throw new Error('The server returned an invalid response while generating the PDF.');
                }

                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Unable to generate the patient PDF.');
                }

                return data;
            })
            .then(function(data) {
                var modalElement = document.getElementById('documentModal');
                var modalInstance = bootstrap.Modal.getInstance(modalElement);
                if (modalInstance) {
                    modalInstance.hide();
                }

                // Show immediate confirmation on the Nurse page.
                var notice = document.createElement('div');
                notice.className = 'alert-toast alert alert-success alert-dismissible fade show';
                notice.setAttribute('role', 'alert');
                notice.innerHTML =
                    '<i class="bi bi-check-circle-fill me-2"></i>' +
                    escapeHtml(data.message || 'PDF generated successfully.') +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                document.body.appendChild(notice);

                window.setTimeout(function() {
                    if (notice && notice.parentNode) {
                        bootstrap.Alert.getOrCreateInstance(notice).close();
                    }
                }, 8000);

                if (!data.download_url) {
                    throw new Error('The PDF was generated, but no download URL was returned.');
                }

                /*
                 * IMPORTANT:
                 * This URL points to a PHP endpoint that sends
                 * Content-Disposition: attachment and Content-Type: application/pdf.
                 * That forces a real PDF download and does not depend on the browser
                 * honoring a scripted <a download> click.
                 */
                window.location.href = data.download_url;
            })
            .catch(function(error) {
                alert(error.message || 'Unable to generate the patient PDF.');
            })
            .finally(function() {
                button.disabled = false;
                button.innerHTML = originalButtonHtml;
            });
        });
    }

    setTimeout(function() {
        var alerts = document.querySelectorAll('.alert-toast');
        alerts.forEach(function(alert) {
            bootstrap.Alert.getOrCreateInstance(alert).close();
        });
    }, 8000);
});
</script>
</body>
</html>
