<?php
session_start();

require_once 'sources/db_connect.php';
require_once 'sources/notification_helper.php';

/*
|--------------------------------------------------------------------------
| ACCESS CONTROL
|--------------------------------------------------------------------------
*/
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_id']) ||
    (int)$_SESSION['role_id'] !== 4
) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$branch_id = null;
$branch_name = 'No Branch Assigned';
$branch_address = '';
$branch_contact = '';
$branch_email = '';
$username = 'Admin Staff';

/*
|--------------------------------------------------------------------------
| GET CURRENT USER / BRANCH
|--------------------------------------------------------------------------
*/
$userQuery = "
    SELECT 
        u.branch_id,
        u.username,
        b.branch_name,
        b.branch_address,
        b.contact_number AS branch_contact,
        b.email AS branch_email
    FROM users u
    LEFT JOIN branches b 
        ON u.branch_id = b.branch_id
    WHERE u.user_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($userQuery);

if (!$stmt) {
    die("Database error: " . $conn->error);
}

$stmt->bind_param("i", $user_id);
$stmt->execute();

$userResult = $stmt->get_result();

if ($userResult && $userResult->num_rows > 0) {
    $userData = $userResult->fetch_assoc();

    $branch_id = $userData['branch_id'];
    $branch_name = $userData['branch_name'] ?? 'Unknown Branch';
    $branch_address = $userData['branch_address'] ?? '';
    $branch_contact = $userData['branch_contact'] ?? '';
    $branch_email = $userData['branch_email'] ?? '';
    $username = $userData['username'] ?? 'Admin Staff';
}

$stmt->close();

if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
}
$notification_count = getAdminStaffNotificationCount($conn, $branch_id);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| CONFIGURATION
|--------------------------------------------------------------------------
*/
define('UPLOAD_DIR', 'uploads/documents/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10 MB

define('ALLOWED_EXTENSIONS', [
    'pdf',
    'doc',
    'docx',
    'xls',
    'xlsx',
    'jpg',
    'jpeg',
    'png',
    'txt'
]);

define('ALLOWED_MIME_TYPES', [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'image/jpeg',
    'image/png',
    'text/plain'
]);

if (!is_dir(UPLOAD_DIR)) {
    if (!mkdir(UPLOAD_DIR, 0755, true)) {
        die("Unable to create document upload directory.");
    }
}

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function jsonResponse($success, $message = '', $data = [])
{
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message
            ],
            $data
        )
    );

    exit();
}

/**
 * Validate uploaded file.
 */
function validateUploadedFile($file)
{
    if (!isset($file) || !is_array($file)) {
        throw new Exception('Invalid file upload.');
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {

        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new Exception('The uploaded file is too large.');

            case UPLOAD_ERR_NO_FILE:
                throw new Exception('No file was uploaded.');

            default:
                throw new Exception('An error occurred while uploading the file.');
        }
    }

    if ($file['size'] <= 0) {
        throw new Exception('The uploaded file is empty.');
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        throw new Exception('File size exceeds the 10MB limit.');
    }

    $extension = strtolower(
        pathinfo($file['name'], PATHINFO_EXTENSION)
    );

    if (!in_array($extension, ALLOWED_EXTENSIONS, true)) {
        throw new Exception(
            'File type not allowed. Allowed files: PDF, DOC, DOCX, XLS, XLSX, JPG, JPEG, PNG, TXT.'
        );
    }

    /*
     * Use finfo when available for additional MIME validation.
     */
    if (function_exists('finfo_open')) {

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo) {

            $mimeType = finfo_file(
                $finfo,
                $file['tmp_name']
            );

            finfo_close($finfo);

            if (
                $mimeType !== false &&
                !in_array($mimeType, ALLOWED_MIME_TYPES, true)
            ) {
                throw new Exception('The uploaded file type is not allowed.');
            }
        }
    }

    return [
        'extension' => $extension,
        'mime_type' => $file['type'] ?? '',
        'size' => (int)$file['size']
    ];
}

/**
 * Generate a safe unique filename.
 */
function generateStoredFileName($originalName)
{
    $extension = strtolower(
        pathinfo($originalName, PATHINFO_EXTENSION)
    );

    $baseName = pathinfo(
        $originalName,
        PATHINFO_FILENAME
    );

    $baseName = preg_replace(
        '/[^a-zA-Z0-9_-]/',
        '_',
        $baseName
    );

    $baseName = trim($baseName, '_');

    if ($baseName === '') {
        $baseName = 'document';
    }

    return $baseName . '_' . uniqid('', true) . '.' . $extension;
}

/**
 * Make sure the requested document belongs to the current branch.
 */
function getDocumentById($conn, $documentId, $branchId)
{
    $query = "
        SELECT
            document_id,
            branch_id,
            document_type,
            document_name,
            file_name,
            file_path,
            file_type,
            file_size,
            uploaded_by,
            uploaded_at,
            updated_at,
            status
        FROM medical_documents
        WHERE document_id = ?
          AND branch_id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);

    if (!$stmt) {
        throw new Exception('Database error: ' . $conn->error);
    }

    $stmt->bind_param(
        "is",
        $documentId,
        $branchId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $document = $result->fetch_assoc();

    $stmt->close();

    if (!$document) {
        throw new Exception('Document not found.');
    }

    return $document;
}

/**
 * Clinic schedule label used on vaccination certificates.
 */
function patientDoseLabel($doseNumber)
{
    $labels = [1 => 'D0', 2 => 'D3', 3 => 'D7', 4 => 'D14', 5 => 'D21', 6 => 'D28'];
    $doseNumber = (int)$doseNumber;

    return $labels[$doseNumber] ?? ('Dose ' . $doseNumber);
}

/**
 * Load one patient and one case, both restricted to the current branch.
 */
function getPatientCaseForDocument($conn, $patientId, $caseId, $branchId)
{
    $query = "
        SELECT
            p.patient_id,
            p.full_name,
            p.email,
            p.contact_number,
            p.birthday,
            p.gender,
            p.address,
            c.case_id,
            c.case_number,
            c.animal_type,
            c.bite_location,
            c.bite_category,
            c.animal_status,
            c.date_of_bite,
            c.case_status,
            c.remarks AS case_remarks
        FROM patients p
        INNER JOIN animal_bite_cases c
            ON c.patient_id = p.patient_id
           AND c.branch_id = p.branch_id
        WHERE p.patient_id = ?
          AND c.case_id = ?
          AND p.branch_id = ?
          AND c.branch_id = ?
          AND p.is_archived = 0
          AND c.is_archived = 0
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param('iiss', $patientId, $caseId, $branchId, $branchId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new Exception('The selected patient or case was not found in your branch.');
    }

    return $row;
}

/**
 * Escape text used inside a PDF text command.
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

/*
|--------------------------------------------------------------------------
| AJAX REQUEST HANDLER
|--------------------------------------------------------------------------
*/

$isAjax =
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {

    $action = $_GET['action']
        ?? $_POST['action']
        ?? '';

    try {

        /*
        |--------------------------------------------------------------------------
        | FETCH DOCUMENTS
        |--------------------------------------------------------------------------
        */
        if ($action === 'fetch_patients') {

            if (!$branch_id) {
                jsonResponse(false, 'No branch is assigned to this account.');
            }

            $search = trim($_GET['search'] ?? '');
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = 10;
            $offset = ($page - 1) * $limit;
            $searchValue = '%' . $search . '%';

            $countQuery = "
                SELECT COUNT(*) AS total
                FROM patients p
                WHERE p.branch_id = ?
                  AND p.is_archived = 0
            ";

            if ($search !== '') {
                $countQuery .= "
                    AND (
                        p.full_name LIKE ?
                        OR p.email LIKE ?
                        OR p.contact_number LIKE ?
                        OR EXISTS (
                            SELECT 1
                            FROM animal_bite_cases sc
                            WHERE sc.patient_id = p.patient_id
                              AND sc.branch_id = p.branch_id
                              AND sc.is_archived = 0
                              AND sc.case_number LIKE ?
                        )
                    )
                ";
            }

            $countStmt = $conn->prepare($countQuery);
            if ($search !== '') {
                $countStmt->bind_param(
                    'sssss',
                    $branch_id,
                    $searchValue,
                    $searchValue,
                    $searchValue,
                    $searchValue
                );
            } else {
                $countStmt->bind_param('s', $branch_id);
            }
            $countStmt->execute();
            $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
            $countStmt->close();

            $pages = max(1, (int)ceil($total / $limit));
            if ($page > $pages) {
                $page = $pages;
                $offset = ($page - 1) * $limit;
            }

            $patientQuery = "
                SELECT
                    p.patient_id,
                    p.full_name,
                    p.email,
                    p.contact_number,
                    p.birthday,
                    p.gender,
                    p.address,
                    TIMESTAMPDIFF(YEAR, p.birthday, CURDATE()) AS age,
                    c.case_id,
                    c.case_number,
                    c.date_of_bite,
                    c.case_status
                FROM patients p
                LEFT JOIN animal_bite_cases c
                    ON c.case_id = (
                        SELECT c2.case_id
                        FROM animal_bite_cases c2
                        WHERE c2.patient_id = p.patient_id
                          AND c2.branch_id = p.branch_id
                          AND c2.is_archived = 0
                        ORDER BY c2.created_at DESC, c2.case_id DESC
                        LIMIT 1
                    )
                WHERE p.branch_id = ?
                  AND p.is_archived = 0
            ";

            if ($search !== '') {
                $patientQuery .= "
                    AND (
                        p.full_name LIKE ?
                        OR p.email LIKE ?
                        OR p.contact_number LIKE ?
                        OR EXISTS (
                            SELECT 1
                            FROM animal_bite_cases sc
                            WHERE sc.patient_id = p.patient_id
                              AND sc.branch_id = p.branch_id
                              AND sc.is_archived = 0
                              AND sc.case_number LIKE ?
                        )
                    )
                ";
            }

            $patientQuery .= ' ORDER BY p.full_name ASC LIMIT ? OFFSET ?';
            $patientStmt = $conn->prepare($patientQuery);

            if ($search !== '') {
                $patientStmt->bind_param(
                    'sssssii',
                    $branch_id,
                    $searchValue,
                    $searchValue,
                    $searchValue,
                    $searchValue,
                    $limit,
                    $offset
                );
            } else {
                $patientStmt->bind_param('sii', $branch_id, $limit, $offset);
            }

            $patientStmt->execute();
            $patients = $patientStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $patientStmt->close();

            jsonResponse(true, '', [
                'patients' => $patients,
                'total' => $total,
                'pages' => $pages,
                'current_page' => $page
            ]);
        }

        elseif ($action === 'fetch_patient_cases') {

            if (!$branch_id) {
                jsonResponse(false, 'No branch is assigned to this account.');
            }

            $patientId = (int)($_GET['patient_id'] ?? 0);
            if ($patientId < 1) {
                jsonResponse(false, 'Invalid patient selection.');
            }

            $query = "
                SELECT
                    c.case_id,
                    c.case_number,
                    c.date_of_bite,
                    c.case_status,
                    c.animal_type,
                    c.bite_category
                FROM animal_bite_cases c
                INNER JOIN patients p
                    ON p.patient_id = c.patient_id
                   AND p.branch_id = c.branch_id
                WHERE c.patient_id = ?
                  AND c.branch_id = ?
                  AND p.branch_id = ?
                  AND c.is_archived = 0
                  AND p.is_archived = 0
                ORDER BY c.created_at DESC, c.case_id DESC
            ";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('iss', $patientId, $branch_id, $branch_id);
            $stmt->execute();
            $cases = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            jsonResponse(true, '', ['cases' => $cases]);
        }

        elseif ($action === 'generate_patient_document') {

            if (!$branch_id) {
                jsonResponse(false, 'No branch is assigned to this account.');
            }

            $requestToken = (string)($_POST['csrf_token'] ?? '');
            if ($requestToken === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $requestToken)) {
                jsonResponse(false, 'Invalid request token. Refresh the page and try again.');
            }

            $patientId = (int)($_POST['patient_id'] ?? 0);
            $caseId = (int)($_POST['case_id'] ?? 0);
            $documentType = trim((string)($_POST['document_type'] ?? ''));
            $allowedTemplates = [
                'Medical Certificate',
                'Vaccination Certificate',
                'Referral Letter'
            ];

            if ($patientId < 1 || $caseId < 1) {
                jsonResponse(false, 'Select a patient with an existing animal-bite case.');
            }
            if (!in_array($documentType, $allowedTemplates, true)) {
                jsonResponse(false, 'Select a valid patient document template.');
            }

            $patientCase = getPatientCaseForDocument(
                $conn,
                $patientId,
                $caseId,
                $branch_id
            );
            $documentData = buildPatientDocumentContent(
                $conn,
                $patientCase,
                $documentType,
                $branch_id,
                $branch_name,
                $username,
                $branch_address,
                $branch_contact,
                $branch_email
            );

            $safePatient = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '_', $patientCase['full_name']), '_');
            $safeType = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '_', $documentType), '_');
            $storedFileName = $safeType . '_' . $safePatient . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
            $originalFileName = $safeType . '_' . $safePatient . '.pdf';
            $filePath = UPLOAD_DIR . $storedFileName;
            $documentName = $documentType . ' - ' . $patientCase['full_name'] . ' - ' . $patientCase['case_number'];
            $transactionStarted = false;

            try {
                if (!writePatientPdf($filePath, $documentType, $documentData) || !is_file($filePath)) {
                    throw new Exception('Unable to create the PDF document.');
                }

                $fileSize = (int)filesize($filePath);
                $fileType = 'application/pdf';
                $status = 'Active';
                $conn->begin_transaction();
                $transactionStarted = true;

                $insert = $conn->prepare(
                    "INSERT INTO medical_documents
                     (branch_id,document_type,document_name,file_name,file_path,file_type,file_size,uploaded_by,status)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                );
                $insert->bind_param(
                    'ssssssiis',
                    $branch_id,
                    $documentType,
                    $documentName,
                    $originalFileName,
                    $filePath,
                    $fileType,
                    $fileSize,
                    $user_id,
                    $status
                );
                $insert->execute();
                $medicalDocumentId = (int)$insert->insert_id;
                $insert->close();

                $trackingType = in_array($documentType, ['Medical Certificate', 'Referral Letter'], true)
                    ? $documentType
                    : null;
                $trackingStatus = 'Generated';
                $trackingRemarks = $documentType . ' generated for ' . $patientCase['full_name'] .
                    '. Medical Document ID: ' . $medicalDocumentId . '.';
                $tracking = $conn->prepare(
                    'INSERT INTO document_tracking
                     (case_id,document_type,status,remarks,created_by)
                     VALUES (?,?,?,?,?)'
                );
                $tracking->bind_param(
                    'isssi',
                    $caseId,
                    $trackingType,
                    $trackingStatus,
                    $trackingRemarks,
                    $user_id
                );
                $tracking->execute();
                $tracking->close();

                $auditAction = 'Generated ' . $documentType . ' for ' .
                    $patientCase['full_name'] . ' (Case ' . $patientCase['case_number'] .
                    ', Document ID ' . $medicalDocumentId . ')';
                $auditModule = 'Medical Documents';
                $audit = $conn->prepare(
                    'INSERT INTO audit_logs (user_id,branch_id,action,module) VALUES (?,?,?,?)'
                );
                $audit->bind_param('isss', $user_id, $branch_id, $auditAction, $auditModule);
                $audit->execute();
                $audit->close();

                $conn->commit();
                $transactionStarted = false;

                jsonResponse(true, 'Patient PDF generated successfully.', [
                    'document_id' => $medicalDocumentId,
                    'document_name' => $documentName,
                    'file_path' => $filePath
                ]);
            } catch (Throwable $generationError) {
                if ($transactionStarted) {
                    try {
                        $conn->rollback();
                    } catch (Throwable $ignored) {
                    }
                }
                if (is_file($filePath)) {
                    @unlink($filePath);
                }
                throw $generationError;
            }
        }

        elseif ($action === 'fetch_documents') {

            if (!$branch_id) {
                jsonResponse(false, 'No branch is assigned to this account.');
            }

            $search = trim($_GET['search'] ?? '');
            $documentType = trim($_GET['document_type'] ?? '');

            $page = isset($_GET['page'])
                ? max(1, (int)$_GET['page'])
                : 1;

            $limit = 10;
            $offset = ($page - 1) * $limit;

            $where = "WHERE md.branch_id = ?
                AND COALESCE(md.status, 'Active') <> 'Archived'
                AND NOT EXISTS (
                    SELECT 1
                    FROM document_tracking dt
                    WHERE dt.status = 'Generated'
                      AND dt.remarks LIKE CONCAT('%Medical Document ID: ', md.document_id, '.%')
                )";
            $params = [$branch_id];
            $types = "s";

            if ($search !== '') {

                $where .= "
                    AND (
                        md.document_name LIKE ?
                        OR md.document_type LIKE ?
                        OR md.file_name LIKE ?
                    )
                ";

                $searchParam = "%{$search}%";

                $params[] = $searchParam;
                $params[] = $searchParam;
                $params[] = $searchParam;

                $types .= "sss";
            }

            if ($documentType !== '') {

                $validTypes = [
                    'Medical Certificate',
                    'Vaccination Certificate',
                    'Referral Letter',
                    'Other'
                ];

                if (!in_array($documentType, $validTypes, true)) {
                    jsonResponse(false, 'Invalid document type.');
                }

                $where .= " AND md.document_type = ?";

                $params[] = $documentType;
                $types .= "s";
            }

            /*
            |--------------------------------------------------------------------------
            | COUNT
            |--------------------------------------------------------------------------
            */
            $countQuery = "
                SELECT COUNT(*) AS total
                FROM medical_documents md
                $where
            ";

            $stmt = $conn->prepare($countQuery);

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare count query: ' . $conn->error
                );
            }

            $stmt->bind_param(
                $types,
                ...$params
            );

            $stmt->execute();

            $countResult = $stmt->get_result();

            $totalRecords = (int)(
                $countResult->fetch_assoc()['total'] ?? 0
            );

            $stmt->close();

            $totalPages = max(
                1,
                (int)ceil($totalRecords / $limit)
            );

            /*
            |--------------------------------------------------------------------------
            | DOCUMENT LIST
            |--------------------------------------------------------------------------
            */
            $query = "
                SELECT
                    md.document_id,
                    md.document_type,
                    md.document_name,
                    md.file_name,
                    md.file_path,
                    md.file_size,
                    md.status,
                    md.uploaded_at,
                    md.updated_at,
                    u.username AS uploaded_by_name
                FROM medical_documents md
                LEFT JOIN users u
                    ON md.uploaded_by = u.user_id
                $where
                ORDER BY md.uploaded_at DESC
                LIMIT ? OFFSET ?
            ";

            $stmt = $conn->prepare($query);

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare document query: ' . $conn->error
                );
            }

            $queryParams = $params;
            $queryTypes = $types . "ii";

            $queryParams[] = $limit;
            $queryParams[] = $offset;

            $stmt->bind_param(
                $queryTypes,
                ...$queryParams
            );

            $stmt->execute();

            $result = $stmt->get_result();

            $documents = [];

            while ($row = $result->fetch_assoc()) {

                $documents[] = [
                    'document_id' => (int)$row['document_id'],
                    'document_type' => $row['document_type'],
                    'document_name' => $row['document_name'],
                    'file_name' => $row['file_name'],
                    'file_path' => $row['file_path'],
                    'file_size' => (int)$row['file_size'],
                    'status' => $row['status'],
                    'uploaded_by_name' => $row['uploaded_by_name'] ?? 'Unknown',
                    'formatted_date' => date(
                        'M d, Y h:i A',
                        strtotime($row['uploaded_at'])
                    )
                ];
            }

            $stmt->close();

            jsonResponse(
                true,
                '',
                [
                    'documents' => $documents,
                    'total' => $totalRecords,
                    'pages' => $totalPages,
                    'current_page' => $page
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | FETCH GENERATED PATIENT PDFS FOR DOWNLOAD TAB
        |--------------------------------------------------------------------------
        */
        elseif ($action === 'fetch_downloads') {
            if (!$branch_id) {
                jsonResponse(false, 'No branch is assigned to this account.');
            }

            $search = trim($_GET['search'] ?? '');
            $documentType = trim($_GET['document_type'] ?? '');
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = 10;
            $offset = ($page - 1) * $limit;

            $where = "WHERE md.branch_id = ?
                AND COALESCE(md.status, 'Active') <> 'Archived'
                AND EXISTS (
                    SELECT 1
                    FROM document_tracking dt
                    WHERE dt.status = 'Generated'
                      AND dt.remarks LIKE CONCAT('%Medical Document ID: ', md.document_id, '.%')
                )";
            $params = [$branch_id];
            $types = 's';

            if ($search !== '') {
                $where .= " AND (md.document_name LIKE ? OR md.document_type LIKE ? OR md.file_name LIKE ?)";
                $searchParam = '%' . $search . '%';
                $params[] = $searchParam;
                $params[] = $searchParam;
                $params[] = $searchParam;
                $types .= 'sss';
            }

            if ($documentType !== '') {
                $validTypes = ['Medical Certificate', 'Vaccination Certificate', 'Referral Letter'];
                if (!in_array($documentType, $validTypes, true)) {
                    jsonResponse(false, 'Invalid document type.');
                }
                $where .= ' AND md.document_type = ?';
                $params[] = $documentType;
                $types .= 's';
            }

            $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM medical_documents md $where");
            if (!$countStmt) {
                throw new Exception('Unable to prepare generated-document count query: ' . $conn->error);
            }
            $countStmt->bind_param($types, ...$params);
            $countStmt->execute();
            $totalRecords = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
            $countStmt->close();
            $totalPages = max(1, (int)ceil($totalRecords / $limit));
            if ($page > $totalPages) {
                $page = $totalPages;
                $offset = ($page - 1) * $limit;
            }

            $query = "
                SELECT
                    md.document_id,
                    md.document_type,
                    md.document_name,
                    md.file_name,
                    md.file_path,
                    md.file_size,
                    md.status,
                    md.uploaded_at,
                    u.username AS uploaded_by_name
                FROM medical_documents md
                LEFT JOIN users u ON md.uploaded_by = u.user_id
                $where
                ORDER BY md.uploaded_at DESC
                LIMIT ? OFFSET ?
            ";
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception('Unable to prepare generated-document query: ' . $conn->error);
            }
            $queryParams = $params;
            $queryParams[] = $limit;
            $queryParams[] = $offset;
            $queryTypes = $types . 'ii';
            $stmt->bind_param($queryTypes, ...$queryParams);
            $stmt->execute();
            $result = $stmt->get_result();
            $documents = [];
            while ($row = $result->fetch_assoc()) {
                $documents[] = [
                    'document_id' => (int)$row['document_id'],
                    'document_type' => $row['document_type'],
                    'document_name' => $row['document_name'],
                    'file_name' => $row['file_name'],
                    'file_path' => $row['file_path'],
                    'file_size' => (int)$row['file_size'],
                    'status' => $row['status'],
                    'uploaded_by_name' => $row['uploaded_by_name'] ?? 'Unknown',
                    'formatted_date' => date('M d, Y h:i A', strtotime($row['uploaded_at']))
                ];
            }
            $stmt->close();

            jsonResponse(true, '', [
                'documents' => $documents,
                'total' => $totalRecords,
                'pages' => $totalPages,
                'current_page' => $page
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | ADD / UPLOAD DOCUMENT
        |--------------------------------------------------------------------------
        */
        elseif ($action === 'upload_document') {

            if (!$branch_id) {
                jsonResponse(false, 'No branch is assigned to this account.');
            }

            if (
                !isset($_FILES['document_file']) ||
                $_FILES['document_file']['error'] === UPLOAD_ERR_NO_FILE
            ) {
                throw new Exception('Please select a document file.');
            }

            $file = $_FILES['document_file'];

            validateUploadedFile($file);

            $documentType = trim(
                $_POST['document_type'] ?? ''
            );

            $documentName = trim(
                $_POST['document_name'] ?? ''
            );

            $validTypes = [
                'Medical Certificate',
                'Vaccination Certificate',
                'Referral Letter',
                'Other'
            ];

            if (!in_array($documentType, $validTypes, true)) {
                throw new Exception('Please select a valid document type.');
            }

            if ($documentName === '') {

                $documentName = pathinfo(
                    $file['name'],
                    PATHINFO_FILENAME
                );

                $documentName = preg_replace(
                    '/[_-]+/',
                    ' ',
                    $documentName
                );

                $documentName = trim($documentName);
            }

            if (mb_strlen($documentName) > 255) {
                throw new Exception(
                    'Document name cannot exceed 255 characters.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | CREATE STORED FILE
            |--------------------------------------------------------------------------
            */
            $storedFileName = generateStoredFileName(
                $file['name']
            );

            $filePath = UPLOAD_DIR . $storedFileName;

            if (
                !move_uploaded_file(
                    $file['tmp_name'],
                    $filePath
                )
            ) {
                throw new Exception(
                    'Failed to save the uploaded file.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | INSERT DATABASE RECORD
            |--------------------------------------------------------------------------
            */
            $insertQuery = "
                INSERT INTO medical_documents (
                    branch_id,
                    document_type,
                    document_name,
                    file_name,
                    file_path,
                    file_type,
                    file_size,
                    uploaded_by
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $stmt = $conn->prepare($insertQuery);

            if (!$stmt) {

                if (file_exists($filePath)) {
                    unlink($filePath);
                }

                throw new Exception(
                    'Unable to prepare upload query: ' . $conn->error
                );
            }

            $originalFileName = $file['name'];
            $fileMimeType = $file['type'] ?? '';
            $fileSize = (int)$file['size'];

            $stmt->bind_param(
                "ssssssii",
                $branch_id,
                $documentType,
                $documentName,
                $originalFileName,
                $filePath,
                $fileMimeType,
                $fileSize,
                $user_id
            );

            if (!$stmt->execute()) {

                if (file_exists($filePath)) {
                    unlink($filePath);
                }

                $error = $stmt->error;
                $stmt->close();

                throw new Exception(
                    'Failed to save document record: ' . $error
                );
            }

            $stmt->close();

            jsonResponse(
                true,
                'Document uploaded successfully.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | GET SINGLE DOCUMENT
        |--------------------------------------------------------------------------
        */
        elseif ($action === 'get_document') {

            if (!$branch_id) {
                jsonResponse(false, 'No branch is assigned.');
            }

            $documentId = isset($_GET['document_id'])
                ? (int)$_GET['document_id']
                : 0;

            if ($documentId <= 0) {
                throw new Exception('Invalid document ID.');
            }

            $document = getDocumentById(
                $conn,
                $documentId,
                $branch_id
            );

            /*
            |--------------------------------------------------------------------------
            | GET UPLOADER NAME
            |--------------------------------------------------------------------------
            */
            $uploadedByName = 'Unknown';

            if (!empty($document['uploaded_by'])) {

                $userStmt = $conn->prepare(
                    "SELECT username FROM users WHERE user_id = ? LIMIT 1"
                );

                if ($userStmt) {

                    $userStmt->bind_param(
                        "i",
                        $document['uploaded_by']
                    );

                    $userStmt->execute();

                    $userResult = $userStmt->get_result();

                    if ($userResult && $userResult->num_rows > 0) {

                        $userRow = $userResult->fetch_assoc();

                        $uploadedByName =
                            $userRow['username'] ?? 'Unknown';
                    }

                    $userStmt->close();
                }
            }

            $document['uploaded_by_name'] = $uploadedByName;

            $document['formatted_date'] =
                !empty($document['uploaded_at'])
                ? date(
                    'M d, Y h:i A',
                    strtotime($document['uploaded_at'])
                )
                : 'N/A';

            $document['formatted_updated'] =
                !empty($document['updated_at'])
                ? date(
                    'M d, Y h:i A',
                    strtotime($document['updated_at'])
                )
                : 'Not updated';

            $document['file_size_mb'] =
                !empty($document['file_size'])
                ? number_format(
                    ((int)$document['file_size']) / 1024 / 1024,
                    2
                )
                : '0.00';

            jsonResponse(
                true,
                '',
                [
                    'document' => $document
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE DOCUMENT
        |--------------------------------------------------------------------------
        |
        | The replacement file is OPTIONAL.
        |
        | If no new file is uploaded:
        |     keep existing file.
        |
        | If a new file is uploaded:
        |     save new file,
        |     update DB,
        |     delete old file.
        |--------------------------------------------------------------------------
        */
        elseif ($action === 'update_document') {

            if (!$branch_id) {
                jsonResponse(false, 'No branch is assigned.');
            }

            $documentId = isset($_POST['document_id'])
                ? (int)$_POST['document_id']
                : 0;

            if ($documentId <= 0) {
                throw new Exception('Invalid document ID.');
            }

            $documentType = trim(
                $_POST['document_type'] ?? ''
            );

            $documentName = trim(
                $_POST['document_name'] ?? ''
            );

            $validTypes = [
                'Medical Certificate',
                'Vaccination Certificate',
                'Referral Letter',
                'Other'
            ];

            if (!in_array($documentType, $validTypes, true)) {
                throw new Exception('Invalid document type.');
            }

            if ($documentName === '') {
                throw new Exception('Document name is required.');
            }

            if (mb_strlen($documentName) > 255) {
                throw new Exception(
                    'Document name cannot exceed 255 characters.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | GET EXISTING DOCUMENT
            |--------------------------------------------------------------------------
            */
            $existingDocument = getDocumentById(
                $conn,
                $documentId,
                $branch_id
            );

            $hasNewFile =
                isset($_FILES['edit_document_file']) &&
                $_FILES['edit_document_file']['error'] !== UPLOAD_ERR_NO_FILE;

            $newFilePath = null;
            $newFileName = null;
            $newFileType = null;
            $newFileSize = null;

            /*
            |--------------------------------------------------------------------------
            | IF NEW FILE WAS PROVIDED
            |--------------------------------------------------------------------------
            */
            if ($hasNewFile) {

                $newFile = $_FILES['edit_document_file'];

                validateUploadedFile($newFile);

                $newStoredFileName =
                    generateStoredFileName(
                        $newFile['name']
                    );

                $newFilePath =
                    UPLOAD_DIR . $newStoredFileName;

                if (
                    !move_uploaded_file(
                        $newFile['tmp_name'],
                        $newFilePath
                    )
                ) {
                    throw new Exception(
                        'Failed to save the replacement file.'
                    );
                }

                $newFileName = $newFile['name'];
                $newFileType = $newFile['type'] ?? '';
                $newFileSize = (int)$newFile['size'];
            }

            /*
            |--------------------------------------------------------------------------
            | UPDATE DATABASE
            |--------------------------------------------------------------------------
            */
            if ($hasNewFile) {

                $updateQuery = "
                    UPDATE medical_documents
                    SET
                        document_type = ?,
                        document_name = ?,
                        file_name = ?,
                        file_path = ?,
                        file_type = ?,
                        file_size = ?,
                        updated_at = NOW()
                    WHERE document_id = ?
                      AND branch_id = ?
                ";

                $stmt = $conn->prepare($updateQuery);

                if (!$stmt) {

                    if (
                        $newFilePath &&
                        file_exists($newFilePath)
                    ) {
                        unlink($newFilePath);
                    }

                    throw new Exception(
                        'Unable to prepare update query: ' . $conn->error
                    );
                }

                $stmt->bind_param(
                    "sssssiss",
                    $documentType,
                    $documentName,
                    $newFileName,
                    $newFilePath,
                    $newFileType,
                    $newFileSize,
                    $documentId,
                    $branch_id
                );

            } else {

                $updateQuery = "
                    UPDATE medical_documents
                    SET
                        document_type = ?,
                        document_name = ?,
                        updated_at = NOW()
                    WHERE document_id = ?
                      AND branch_id = ?
                ";

                $stmt = $conn->prepare($updateQuery);

                if (!$stmt) {
                    throw new Exception(
                        'Unable to prepare update query: ' . $conn->error
                    );
                }

                $stmt->bind_param(
                    "ssis",
                    $documentType,
                    $documentName,
                    $documentId,
                    $branch_id
                );
            }

            if (!$stmt->execute()) {

                $error = $stmt->error;

                $stmt->close();

                if (
                    $newFilePath &&
                    file_exists($newFilePath)
                ) {
                    unlink($newFilePath);
                }

                throw new Exception(
                    'Failed to update document: ' . $error
                );
            }

            $affectedRows = $stmt->affected_rows;

            $stmt->close();

            /*
            |--------------------------------------------------------------------------
            | DELETE OLD FILE ONLY AFTER SUCCESSFUL DB UPDATE
            |--------------------------------------------------------------------------
            */
            if (
                $hasNewFile &&
                !empty($existingDocument['file_path']) &&
                $existingDocument['file_path'] !== $newFilePath &&
                file_exists($existingDocument['file_path'])
            ) {
                @unlink(
                    $existingDocument['file_path']
                );
            }

            jsonResponse(
                true,
                $hasNewFile
                    ? 'Document and file updated successfully.'
                    : 'Document information updated successfully.',
                [
                    'file_replaced' => $hasNewFile,
                    'affected_rows' => $affectedRows
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | DELETE DOCUMENT
        |--------------------------------------------------------------------------
        */
        elseif ($action === 'delete_document') {

            if (!$branch_id) {
                jsonResponse(false, 'No branch is assigned.');
            }

            $documentId = isset($_GET['document_id'])
                ? (int)$_GET['document_id']
                : 0;

            if ($documentId <= 0) {
                throw new Exception('Invalid document ID.');
            }

            $document = getDocumentById(
                $conn,
                $documentId,
                $branch_id
            );

            /*
            |--------------------------------------------------------------------------
            | ARCHIVE DATABASE RECORD
            |--------------------------------------------------------------------------
            */
            $deleteQuery = "
                UPDATE medical_documents
                SET status = 'Archived', updated_at = NOW()
                WHERE document_id = ?
                  AND branch_id = ?
            ";

            $stmt = $conn->prepare($deleteQuery);

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare archive query: ' . $conn->error
                );
            }

            $stmt->bind_param(
                "is",
                $documentId,
                $branch_id
            );

            if (!$stmt->execute()) {

                $error = $stmt->error;
                $stmt->close();

                throw new Exception(
                    'Failed to archive document: ' . $error
                );
            }

            $deletedRows = $stmt->affected_rows;

            $stmt->close();

            if ($deletedRows <= 0) {
                throw new Exception(
                    'Document could not be archived.'
                );
            }

            jsonResponse(
                true,
                'Document archived successfully. The file was retained for audit purposes.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | INVALID ACTION
        |--------------------------------------------------------------------------
        */
        else {

            jsonResponse(
                false,
                'Invalid request action.'
            );
        }

    } catch (Throwable $e) {

        jsonResponse(
            false,
            $e->getMessage()
        );
    }
}



/*
|--------------------------------------------------------------------------
| INITIAL RECENT DOCUMENTS
|--------------------------------------------------------------------------
*/

$recentDocuments = [];

if ($branch_id) {

    $recentQuery = "
        SELECT
            md.document_id,
            md.document_type,
            md.document_name,
            md.file_name,
            md.file_path,
            md.file_size,
            md.status,
            md.uploaded_at,
            u.username AS uploaded_by_name
        FROM medical_documents md
        LEFT JOIN users u
            ON md.uploaded_by = u.user_id
        WHERE md.branch_id = ?
          AND COALESCE(md.status, 'Active') <> 'Archived'
          AND NOT EXISTS (
              SELECT 1
              FROM document_tracking dt
              WHERE dt.status = 'Generated'
                AND dt.remarks LIKE CONCAT('%Medical Document ID: ', md.document_id, '.%')
          )
        ORDER BY md.uploaded_at DESC
        LIMIT 10
    ";

    $stmt = $conn->prepare($recentQuery);

    if ($stmt) {

        $stmt->bind_param(
            "s",
            $branch_id
        );

        $stmt->execute();

        $recentResult = $stmt->get_result();

        while ($row = $recentResult->fetch_assoc()) {
            $recentDocuments[] = $row;
        }

        $stmt->close();
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Medical Documents - SmartBiteCare</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <link
        rel="stylesheet"
        href="sidebar.css"
    >

    <style>

        :root {
            --primary: #2B3A8C;
            --primary-dark: #1f2d6b;
            --danger: #dc3545;
            --success: #198754;
            --gray-100: #f8f9fc;
            --gray-500: #adb5bd;
            --gray-600: #6c757d;
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

/* =========================================================
   GLOBAL LOGOUT CONFIRMATION MODAL 
   ========================================================= */

.confirm-modal .modal-dialog {
    max-width: 500px !important;
    width: calc(100% - 30px);
    margin: 1.75rem auto;
}

.confirm-modal .modal-content {
    overflow: hidden !important;
    border: 0 !important;
    border-radius: 20px !important;
    background: #fff !important;
    box-shadow: 0 20px 55px rgba(31, 45, 110, 0.20) !important;
}

.confirm-modal .modal-header {
    display: block !important;
    padding: 26px 24px 6px !important;
    border: 0 !important;
    background: #fff !important;
    text-align: center !important;
}

.confirm-modal .modal-icon {
    width: 64px !important;
    height: 64px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 auto 14px !important;

    color: #fff !important;
    background: linear-gradient(135deg, #ef3340, #f05b68) !important;
    border-radius: 50% !important;

    box-shadow: 0 9px 22px rgba(239, 51, 64, 0.22) !important;
    font-size: 27px !important;
}

.confirm-modal .modal-icon.logout-icon {
    background: linear-gradient(135deg, #ef3340, #f05b68) !important;
    box-shadow: 0 9px 22px rgba(239, 51, 64, 0.22) !important;
}

.confirm-modal .modal-title {
    margin: 0 !important;
    color: #283a7a !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    line-height: 1.3 !important;
}

.confirm-modal .modal-body {
    padding: 6px 30px 22px !important;
    background: #fff !important;
    color: #7a879e !important;
    text-align: center !important;
}

.confirm-modal .modal-body p {
    margin: 0 !important;
    color: #7a879e !important;
    font-size: 17px !important;
    line-height: 1.45 !important;
}

.confirm-modal .modal-footer {
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 10px !important;
    padding: 0 24px 26px !important;
    border: 0 !important;
    background: #fff !important;
}

.confirm-modal .modal-footer .btn {
    min-height: 50px !important;
    margin: 0 !important;
    border-radius: 10px !important;
    font-size: 16px !important;
    font-weight: 700 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}

.confirm-modal .btn-light,
.confirm-modal .btn-cancel {
    color: #111 !important;
    background: #f8f9fa !important;
    border: 1px solid #d9dfe8 !important;
}

.confirm-modal .btn-light:hover,
.confirm-modal .btn-cancel:hover {
    background: #eef0f3 !important;
}

.confirm-modal .btn-danger,
.confirm-modal .btn-logout {
    color: #fff !important;
    background: #e83445 !important;
    border: 1px solid #e83445 !important;
    text-decoration: none !important;
}

.confirm-modal .btn-danger:hover,
.confirm-modal .btn-logout:hover {
    color: #fff !important;
    background: #d92839 !important;
    border-color: #d92839 !important;
}

        .content {
            padding: 30px;
        }

        .section-card {
            background: #fff;
            border: 1px solid #e8e8e8;
            border-radius: 18px;
            padding: 24px;
            box-shadow: var(--shadow);
            margin-bottom: 24px;
        }

        /* Toolbar */

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .left-tools {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-box {
            position: relative;
            width: 400px;
            max-width: 100%;
        }

        .search-box > i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #7180a8;
            font-size: 18px;
            z-index: 2;
            pointer-events: none;
        }

        .search-box input {
            width: 100%;
            height: 48px;
            padding: 0 18px 0 45px;
            background: #ffffff;
            border: 1px solid #d0d7e8;
            border-radius: 10px;
            font-size: 14px;
            color: #1f2a4a;
            outline: none;
            box-sizing: border-box;
            transition: 0.2s ease;
        }

        .search-box input:focus {
            border-color: var(--primary);
            box-shadow:
                0 0 0 3px rgba(43, 58, 140, 0.10);
        }

        .form-select-sm-custom {
            height: 42px;
            border-radius: 8px;
            border: 1px solid #ced4da;
            padding: 0 12px;
            font-size: 13px;
        }

        .toolbar-btn {
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 600;
            transition: var(--transition);
        }

        .toolbar-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            color: white;
        }

        .toolbar-btn i {
            margin-right: 6px;
        }

        /* Table */

        .table-wrapper {
            background: #fff;
            border-radius: 18px;
            overflow: hidden;
            border: 1px solid #e8e8e8;
            box-shadow: var(--shadow);
        }

        .table {
            width: 100%;
            margin-bottom: 0;
            border-collapse: collapse;
        }

        .table thead th {
            border: none !important;
            padding: 12px 16px;
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            background: var(--primary);
            color: #fff;
        }

        .table tbody td {
            text-align: center;
            vertical-align: middle;
            padding: 10px 16px;
            border-top: 1px solid #e9ecef;
            font-size: 14px;
        }

        .table tbody tr:hover {
            background: var(--gray-100);
        }

        /* Document Badge */

        .document-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .document-badge.medical-certificate {
            background: #EAF2FF;
            color: #2563EB;
        }

        .document-badge.vaccination-certificate {
            background: #E8FAF2;
            color: #1DBA6C;
        }

        .document-badge.referral-letter {
            background: #F2EAFE;
            color: #7C4DFF;
        }

        .document-badge.other {
            background: #f1f3f5;
            color: #6c757d;
        }

        /* Actions */

        .actions {
            display: flex;
            justify-content: center;
            gap: 4px;
        }

        .action-btn {
            width: 36px;
            height: 36px;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            border-radius: 50%;
            border: none;
            background: transparent;
            cursor: pointer;
            transition: var(--transition);
            font-size: 16px;
        }

        .action-btn:hover {
            transform: translateY(-1px);
        }

        .action-btn.view {
            color: var(--primary);
        }

        .action-btn.view:hover {
            background: #e7ebff;
        }

        .action-btn.edit {
            color: #198754;
        }

        .action-btn.edit:hover {
            background: #e8f8ef;
        }

        .action-btn.print {
            color: #6f42c1;
        }

        .action-btn.print:hover {
            background: #f0e9ff;
        }

        .action-btn.download {
            color: #0d6efd;
        }

        .action-btn.download:hover {
            background: #e7f1ff;
        }

        .action-btn.delete {
            color: var(--danger);
        }

        .action-btn.delete:hover {
            background: #ffe7ea;
        }

        /* Pagination */

        .pagination-area {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .page-item {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            border: 1px solid transparent;
            font-size: 14px;
            color: var(--gray-600);
            text-decoration: none;
            background: transparent;
        }

        .page-item:hover {
            background: var(--gray-100);
            border-color: #ddd;
        }

        .page-item.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .page-item.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* Modal */

        .modal-content {
            border-radius: var(--radius);
            border: none;
        }

        .modal-header {
            background: var(--primary);
            color: #fff;
            border-radius:
                var(--radius)
                var(--radius)
                0
                0;
            padding: 18px 25px;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        .modal-body {
            padding: 25px;
        }

        .modal-footer {
            border-top: none;
            padding: 18px 25px 25px;
        }

        .form-label {
            font-weight: 600;
            font-size: 13px;
            color: #333;
        }

        .form-control,
        .form-select {
            border-radius: 8px;
            padding: 9px 14px;
            border: 1px solid #ced4da;
            font-size: 14px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow:
                0 0 0 3px rgba(43,58,140,0.12);
        }

        /* File Upload */

        .file-upload-area {
            border: 2px dashed #ced4da;
            border-radius: 10px;
            padding: 25px;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .file-upload-area:hover,
        .file-upload-area.dragover {
            border-color: var(--primary);
            background: var(--gray-100);
        }

        .file-upload-area i {
            font-size: 40px;
            color: var(--gray-500);
            display: block;
            margin-bottom: 8px;
        }

        .file-upload-area p {
            margin: 0;
            color: var(--gray-600);
            font-size: 14px;
        }

        .file-upload-area .file-name {
            font-weight: 600;
            color: var(--primary);
            margin-top: 8px;
            word-break: break-word;
        }

        .current-file {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 14px;
        }

        .current-file i {
            color: var(--primary);
            margin-right: 6px;
        }

        /* Details */

        .detail-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            width: 130px;
            font-weight: 600;
            color: #555;
        }

        .detail-value {
            flex: 1;
            color: #222;
            word-break: break-word;
        }

        /* Toast */

        .toast-container-custom {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 9999;
            max-width: 380px;
        }

        .toast-custom {
            background: #fff;
            border-radius: 12px;
            padding: 14px 20px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            border-left: 5px solid #28a745;
            display: flex;
            align-items: center;
            gap: 12px;
            transform: translateX(120%);
            transition:
                transform
                0.4s
                cubic-bezier(0.34,1.56,0.64,1);
            margin-bottom: 10px;
        }

        .toast-custom.show {
            transform: translateX(0);
        }

        .toast-custom.error {
            border-left-color: var(--danger);
        }

        .toast-custom .toast-icon {
            font-size: 22px;
            color: #28a745;
        }

        .toast-custom.error .toast-icon {
            color: var(--danger);
        }

        .toast-msg {
            font-size: 14px;
        }

        .toast-msg small {
            display: block;
            color: #666;
            margin-top: 2px;
        }

        /* Loading */

        .loading-overlay {
            display: flex;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.8);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity .18s ease, visibility 0s linear .18s;
        }

        .loading-overlay.show {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
            transition-delay: 0s;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {

            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .no-records {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray-500);
        }

        .no-records i {
            font-size: 48px;
            display: block;
            margin-bottom: 12px;
            opacity: 0.4;
        }

        @media (max-width: 991px) {

            .main {
                margin-left: 90px;
            }

            .topbar {
                padding: 0 16px;
                height: 64px;
            }

            .topbar h3 {
                font-size: 20px;
            }
        }

        @media (max-width: 768px) {

            .content {
                padding: 16px;
            }

            .search-box {
                width: 100%;
            }

            .left-tools {
                width: 100%;
            }

            .toolbar-btn {
                width: 100%;
            }

            .detail-row {
                display: block;
            }

            .detail-label {
                width: auto;
                margin-bottom: 3px;
            }
        }

        .medical-module-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 22px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e1e6f0;
        }

        .medical-module-tab {
            border: 0;
            background: transparent;
            color: #6c7590;
            padding: 10px 18px;
            border-radius: 9px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background-color .2s ease, color .2s ease,
                        box-shadow .2s ease, transform .2s ease;
        }

        .medical-module-tab:hover {
            background: #f0f3ff;
            color: var(--primary);
            transform: translateY(-1px);
        }

        .medical-module-tab.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 5px 14px rgba(43, 58, 140, .2);
        }

        .medical-tab-panel {
            display: none;
            min-height: 320px;
        }

        .medical-tab-panel.active {
            display: block;
            animation: medicalTabEnter .22s ease-out both;
        }

        @keyframes medicalTabEnter {
            from {
                opacity: 0;
                transform: translateY(6px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .medical-tab-panel.active {
                animation: none;
            }

            .medical-module-tab {
                transition: none;
            }

            .loading-overlay {
                transition: none;
            }
        }

        .patient-cell strong {
            color: #12205d;
            display: block;
            margin-bottom: 3px;
        }

        .patient-cell small,
        .case-cell small {
            color: #727b91;
            display: block;
            line-height: 1.5;
        }

        .generate-pdf-btn {
            border: 1px solid var(--primary);
            color: var(--primary);
            background: #fff;
            border-radius: 8px;
            padding: 7px 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .generate-pdf-btn:hover {
            background: var(--primary);
            color: #fff;
        }

        .generate-pdf-btn:disabled {
            border-color: #c9cfdd;
            color: #9aa2b5;
            background: #f5f6f9;
            cursor: not-allowed;
        }

        .selected-patient-card {
            background: #f4f6ff;
            border: 1px solid #dce2f7;
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 18px;
        }

        .selected-patient-card strong {
            color: var(--primary);
            font-size: 16px;
        }

        .template-choice {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 13px;
            border: 1px solid #dce2ee;
            border-radius: 10px;
            cursor: pointer;
            margin-bottom: 10px;
            transition: .2s ease;
        }

        .template-choice:hover,
        .template-choice.selected {
            border-color: var(--primary);
            background: #f3f5ff;
        }

        .template-choice input {
            margin-top: 4px;
        }

        .template-choice i {
            color: var(--primary);
            font-size: 22px;
        }

        .template-choice span {
            color: #747d91;
            display: block;
            font-size: 12px;
            margin-top: 2px;
        }

        .download-tab-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
            padding: 16px 18px;
            border-radius: 12px;
            border: 1px solid #dce2f7;
            background: linear-gradient(135deg, #f6f8ff 0%, #ffffff 100%);
        }

        .download-tab-heading h5 {
            margin: 0 0 4px;
            color: var(--primary);
            font-weight: 800;
            font-size: 17px;
        }

        .download-tab-heading p {
            margin: 0;
            color: #737c91;
            font-size: 13px;
        }

        @media (max-width: 767px) {
            .medical-module-tabs {
                overflow-x: auto;
            }

            .medical-module-tab {
                white-space: nowrap;
            }
        }
    </style>

</head>

<body>

<!--
|--------------------------------------------------------------------------
| SIDEBAR
|--------------------------------------------------------------------------
-->

<div class="sidebar">

    <div class="logo-area">

        <div class="logo-frame">
            <img
                src="logo.png"
                alt="Smart Bite Care Logo"
                class="logo"
            >
        </div>

        <div class="system-name">
            Smart Bite Care
        </div>

    </div>

   <nav class="nav-menu">
            <ul>
                <li><a href="AdminStaff_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
                <li><a href="AdminStaff_Calendar.php"><i class="bi bi-calendar-fill"></i><span>Calendar</span></a></li>
                <li><a href="AdminStaff_PatientRecord.php"><i class="bi bi-people-fill"></i><span>Patient Record Management</span></a></li>
                <li><a href="AdminStaff_VisitQueue.php"><i class="bi bi-person-check-fill"></i><span>Visit Check-in</span></a></li>
                <li><a href="AdminStaff_Registry.php"><i class="bi bi-journal-check"></i><span>Registry Queue</span></a></li>
                <li><a href="AdminStaff_PhilhealthWorkflow.php"><i class="bi bi-check2-all"></i><span>PhilHealth Workflow</span></a></li>
                <li><a class="active" href="AdminStaff_MedicalDocuments.php"><i class="bi bi-file-earmark-ruled"></i><span>Medical Documents</span></a></li>
                <li>
                    <a href="AdminStaff_Notifications.php" class="notification-link">
                        <i class="bi bi-bell-fill"></i>
                        <span>Notifications</span>

                        <?php if ($notification_count > 0): ?>
                            <span class="notification-badge">
                                <?php echo $notification_count; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
            </ul>
        </nav>

</div>


<!--
|--------------------------------------------------------------------------
| MAIN CONTENT
|--------------------------------------------------------------------------
-->

<div class="main">

    <div class="topbar">

        <h3>

            Medical Documents

            <span
                style="
                    font-size:16px;
                    color:#6c757d;
                    font-weight:400;
                    margin-left:8px;
                "
            >
                <?php
                echo htmlspecialchars(
                    $branch_name,
                    ENT_QUOTES,
                    'UTF-8'
                );
                ?>
            </span>

        </h3>

         <div class="dropdown">
                <button class="profile dropdown-toggle border-0 bg-transparent px-3 py-2 rounded-3"
                        type="button" id="adminStaffProfileMenu"
                        data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle"></i>
                    <span><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span style="font-size:12px; color:#adb5bd; font-weight:400; margin-left:4px;">| Admin Staff</span>
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


    <div class="content">

        <div class="section-card">

            <div class="medical-module-tabs" role="tablist" aria-label="Medical document sections">
                <button
                    type="button"
                    class="medical-module-tab active"
                    id="documentsTabButton"
                    role="tab"
                    aria-selected="true"
                    aria-controls="documentsPanel"
                    onclick="switchMedicalTab('documents')"
                >
                    <i class="bi bi-folder2-open"></i>
                    Documents
                </button>
                <button
                    type="button"
                    class="medical-module-tab"
                    id="patientsTabButton"
                    role="tab"
                    aria-selected="false"
                    aria-controls="patientsPanel"
                    onclick="switchMedicalTab('patients')"
                >
                    <i class="bi bi-people"></i>
                    Patients
                </button>
                <button
                    type="button"
                    class="medical-module-tab"
                    id="downloadTabButton"
                    role="tab"
                    aria-selected="false"
                    aria-controls="downloadPanel"
                    onclick="switchMedicalTab('download')"
                >
                    <i class="bi bi-download"></i>
                    Download
                </button>
            </div>

            <div
                class="medical-tab-panel active"
                id="documentsPanel"
                role="tabpanel"
                aria-labelledby="documentsTabButton"
            >

            <!-- TOOLBAR -->

            <div class="toolbar">

                <div class="left-tools">

                    <div class="search-box">

                        <i class="bi bi-search"></i>

                        <input
                            type="text"
                            id="searchInput"
                            placeholder="Search templates..."
                        >

                    </div>

                    <select
                        class="form-select-sm-custom"
                        id="typeFilter"
                    >

                        <option value="">
                            All Types
                        </option>

                        <option value="Medical Certificate">
                            Medical Certificate
                        </option>

                        <option value="Vaccination Certificate">
                            Vaccination Certificate
                        </option>

                        <option value="Referral Letter">
                            Referral Letter
                        </option>

                        <option value="Other">
                            Other
                        </option>

                    </select>

                </div>


                <button
                    type="button"
                    class="toolbar-btn"
                    onclick="openUploadModal()"
                >

                    <i class="bi bi-plus-circle"></i>

                    Upload New Template

                </button>

            </div>


            <!-- DOCUMENT TABLE -->

            <div class="table-wrapper">

                <div class="table-responsive">

                    <table class="table">

                        <thead>

                            <tr>

                                <th>
                                    Document Type
                                </th>

                                <th>
                                    Document Name
                                </th>

                                <th>
                                    Uploaded By
                                </th>

                                <th>
                                    Date
                                </th>

                                <th>
                                    Actions
                                </th>

                            </tr>

                        </thead>

                        <tbody id="documentsTableBody">

                            <?php if (empty($recentDocuments)): ?>

                                <tr>

                                    <td colspan="5">

                                        <div class="no-records">

                                            <i
                                                class="bi bi-file-earmark-text"
                                            ></i>

                                            <p>
                                                No document templates uploaded yet.
                                            </p>

                                        </div>

                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php foreach ($recentDocuments as $doc): ?>

                                    <?php

                                    $badgeClass =
                                        strtolower(
                                            str_replace(
                                                ' ',
                                                '-',
                                                $doc['document_type']
                                            )
                                        );

                                    ?>

                                    <tr>

                                        <td>

                                            <span
                                                class="document-badge <?php echo htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8'); ?>"
                                            >

                                                <?php
                                                echo htmlspecialchars(
                                                    $doc['document_type'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                );
                                                ?>

                                            </span>

                                        </td>

                                        <td>

                                            <?php
                                            echo htmlspecialchars(
                                                $doc['document_name'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            );
                                            ?>

                                        </td>

                                        <td>

                                            <?php
                                            echo htmlspecialchars(
                                                $doc['uploaded_by_name'] ?? 'Unknown',
                                                ENT_QUOTES,
                                                'UTF-8'
                                            );
                                            ?>

                                        </td>

                                        <td>

                                            <?php
                                            echo htmlspecialchars(
                                                date(
                                                    'M d, Y h:i A',
                                                    strtotime(
                                                        $doc['uploaded_at']
                                                    )
                                                ),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            );
                                            ?>

                                        </td>

                                        <td>

                                            <div class="actions">

                                                <!-- VIEW -->

                                                <button
                                                    type="button"
                                                    class="action-btn view"
                                                    onclick="viewDocument(<?php echo (int)$doc['document_id']; ?>)"
                                                    title="View"
                                                >

                                                    <i class="bi bi-eye"></i>

                                                </button>


                                                <!-- EDIT -->

                                                <button
                                                    type="button"
                                                    class="action-btn edit"
                                                    onclick="editDocument(<?php echo (int)$doc['document_id']; ?>)"
                                                    title="Edit"
                                                >

                                                    <i class="bi bi-pencil"></i>

                                                </button>


                                                <!-- PRINT -->

                                                <button
                                                    type="button"
                                                    class="action-btn print"
                                                    onclick="printDocument(<?php echo (int)$doc['document_id']; ?>)"
                                                    title="Print"
                                                >

                                                    <i class="bi bi-printer"></i>

                                                </button>


                                                <!-- DOWNLOAD -->

                                                <button
                                                    type="button"
                                                    class="action-btn download"
                                                    onclick="downloadDocument(<?php echo (int)$doc['document_id']; ?>)"
                                                    title="Download"
                                                >

                                                    <i class="bi bi-download"></i>

                                                </button>


                                                <!-- DELETE -->

                                                <button
                                                    type="button"
                                                    class="action-btn delete"
                                                    onclick="deleteDocument(<?php echo (int)$doc['document_id']; ?>)"
                                                    title="Delete"
                                                >

                                                    <i class="bi bi-trash"></i>

                                                </button>

                                            </div>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>


            <div
                class="pagination-area"
                id="paginationArea"
            ></div>

            <div class="text-center mt-2">

                <small
                    class="text-muted"
                    id="recordCount"
                >
                    Loading...
                </small>

            </div>

            </div>

            <div
                class="medical-tab-panel"
                id="patientsPanel"
                role="tabpanel"
                aria-labelledby="patientsTabButton"
            >

                <div class="toolbar">
                    <div class="left-tools">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input
                                type="text"
                                id="patientSearchInput"
                                placeholder="Search patient, contact, email, or case..."
                            >
                        </div>
                    </div>

                    <div class="text-muted small">
                        <i class="bi bi-database-check me-1"></i>
                        Templates use current patient records
                    </div>
                </div>

                <div class="table-wrapper">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Contact</th>
                                    <th>Latest Case</th>
                                    <th>Case Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="patientsTableBody">
                                <tr>
                                    <td colspan="5">
                                        <div class="no-records">
                                            <i class="bi bi-people"></i>
                                            <p>Open the Patients tab to load branch patients.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="pagination-area" id="patientPaginationArea"></div>
                <div class="text-center mt-2">
                    <small class="text-muted" id="patientRecordCount">Loading...</small>
                </div>

            </div>

            <div
                class="medical-tab-panel"
                id="downloadPanel"
                role="tabpanel"
                aria-labelledby="downloadTabButton"
            >
                <div class="download-tab-heading">
                    <div>
                        <h5><i class="bi bi-file-earmark-arrow-down me-2"></i>Generated Patient Documents</h5>
                        <p>Only patient-specific PDFs generated from the Patients tab appear here.</p>
                    </div>
                </div>

                <div class="toolbar">
                    <div class="left-tools">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" id="downloadSearchInput" placeholder="Search patient document...">
                        </div>
                        <select class="form-select-sm-custom" id="downloadTypeFilter">
                            <option value="">All Types</option>
                            <option value="Medical Certificate">Medical Certificate</option>
                            <option value="Vaccination Certificate">Vaccination Certificate</option>
                            <option value="Referral Letter">Referral Letter</option>
                        </select>
                    </div>
                    <div class="text-muted small">
                        <i class="bi bi-shield-check me-1"></i>
                        Generated PDFs use the uniform SBI form design
                    </div>
                </div>

                <div class="table-wrapper">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Document Type</th>
                                    <th>Patient Document</th>
                                    <th>Generated By</th>
                                    <th>Date Generated</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="downloadsTableBody">
                                <tr>
                                    <td colspan="5">
                                        <div class="no-records">
                                            <i class="bi bi-file-earmark-arrow-down"></i>
                                            <p>Open the Download tab to load generated patient PDFs.</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="pagination-area" id="downloadPaginationArea"></div>
                <div class="text-center mt-2">
                    <small class="text-muted" id="downloadRecordCount">Loading...</small>
                </div>
            </div>

        </div>

    </div>

</div>


<!--
|--------------------------------------------------------------------------
| UPLOAD MODAL
|--------------------------------------------------------------------------
-->

<div
    class="modal fade"
    id="uploadModal"
    tabindex="-1"
>

    <div class="modal-dialog">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">

                    <i class="bi bi-upload"></i>

                    Upload Document

                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>


            <div class="modal-body">

                <form
                    id="uploadForm"
                    enctype="multipart/form-data"
                >

                    <div class="mb-3">

                        <label class="form-label">

                            Document Type

                            <span class="text-danger">
                                *
                            </span>

                        </label>

                        <select
                            class="form-select"
                            id="docTypeSelect"
                            name="document_type"
                            required
                        >

                            <option value="Medical Certificate">
                                Medical Certificate
                            </option>

                            <option value="Vaccination Certificate">
                                Vaccination Certificate
                            </option>

                            <option value="Referral Letter">
                                Referral Letter
                            </option>

                            <option value="Other">
                                Other
                            </option>

                        </select>

                    </div>


                    <div class="mb-3">

                        <label class="form-label">

                            Document Name

                            <span class="text-danger">
                                *
                            </span>

                        </label>

                        <input
                            type="text"
                            class="form-control"
                            id="docName"
                            name="document_name"
                            maxlength="255"
                            required
                        >

                    </div>


                    <div class="mb-3">

                        <label class="form-label">

                            File

                            <span class="text-danger">
                                *
                            </span>

                        </label>

                        <div
                            class="file-upload-area"
                            id="fileUploadArea"
                        >

                            <i class="bi bi-cloud-arrow-up"></i>

                            <p>
                                Click or drag to upload
                            </p>

                            <p
                                class="text-muted"
                                style="font-size:11px;"
                            >
                                PDF, DOC, DOCX, XLS, XLSX,
                                JPG, PNG, TXT
                                (Max 10MB)
                            </p>

                            <div
                                id="selectedFileName"
                                class="file-name"
                                style="display:none;"
                            ></div>

                            <input
                                type="file"
                                id="fileInput"
                                name="document_file"
                                style="display:none;"
                                required
                            >

                        </div>

                    </div>

                </form>

            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>

                <button
                    type="button"
                    class="btn btn-primary"
                    id="uploadBtn"
                >

                    <i class="bi bi-cloud-upload"></i>

                    Upload

                </button>

            </div>

        </div>

    </div>

</div>


<!--
|--------------------------------------------------------------------------
| VIEW MODAL
|--------------------------------------------------------------------------
-->

<div
    class="modal fade"
    id="viewModal"
    tabindex="-1"
>

    <div class="modal-dialog">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">

                    <i class="bi bi-file-earmark-text"></i>

                    Document Details

                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>


            <div
                class="modal-body"
                id="viewModalBody"
            ></div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    Close
                </button>

                <button
                    type="button"
                    class="btn btn-primary"
                    id="viewEditBtn"
                >

                    <i class="bi bi-pencil"></i>

                    Edit

                </button>

            </div>

        </div>

    </div>

</div>


<!--
|--------------------------------------------------------------------------
| EDIT MODAL
|--------------------------------------------------------------------------
-->

<div
    class="modal fade"
    id="editModal"
    tabindex="-1"
>

    <div class="modal-dialog">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">

                    <i class="bi bi-pencil-square"></i>

                    Edit Document

                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>


            <div class="modal-body">

                <form
                    id="editForm"
                    enctype="multipart/form-data"
                >

                    <input
                        type="hidden"
                        id="editDocumentId"
                        name="document_id"
                    >


                    <div class="mb-3">

                        <label class="form-label">

                            Document Type

                            <span class="text-danger">
                                *
                            </span>

                        </label>

                        <select
                            class="form-select"
                            id="editDocType"
                            name="document_type"
                            required
                        >

                            <option value="Medical Certificate">
                                Medical Certificate
                            </option>

                            <option value="Vaccination Certificate">
                                Vaccination Certificate
                            </option>

                            <option value="Referral Letter">
                                Referral Letter
                            </option>

                            <option value="Other">
                                Other
                            </option>

                        </select>

                    </div>


                    <div class="mb-3">

                        <label class="form-label">

                            Document Name

                            <span class="text-danger">
                                *
                            </span>

                        </label>

                        <input
                            type="text"
                            class="form-control"
                            id="editDocName"
                            name="document_name"
                            maxlength="255"
                            required
                        >

                    </div>


                    <div class="mb-3">

                        <label class="form-label">
                            Current File
                        </label>

                        <div
                            class="current-file"
                            id="currentFileDisplay"
                        >
                            No file information available.
                        </div>

                    </div>


                    <div class="mb-3">

                        <label class="form-label">

                            Replace File

                            <small
                                class="text-muted fw-normal"
                            >
                                (Optional)
                            </small>

                        </label>

                        <div
                            class="file-upload-area"
                            id="editFileUploadArea"
                        >

                            <i class="bi bi-cloud-arrow-up"></i>

                            <p>
                                Click or drag to upload a replacement
                            </p>

                            <p
                                class="text-muted"
                                style="font-size:11px;"
                            >
                                Leave empty to keep the current file.
                                Max 10MB.
                            </p>

                            <div
                                id="editSelectedFileName"
                                class="file-name"
                                style="display:none;"
                            ></div>

                            <input
                                type="file"
                                id="editFileInput"
                                name="edit_document_file"
                                style="display:none;"
                            >

                        </div>

                    </div>

                </form>

            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>

                <button
                    type="button"
                    class="btn btn-primary"
                    id="updateDocBtn"
                >

                    <i class="bi bi-save"></i>

                    Save Changes

                </button>

            </div>

        </div>

    </div>

</div>


<!--
|--------------------------------------------------------------------------
| DELETE MODAL
|--------------------------------------------------------------------------
-->

<div
    class="modal fade"
    id="deleteModal"
    tabindex="-1"
>

    <div class="modal-dialog modal-sm">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">

                    <i class="bi bi-trash"></i>

                    Confirm Delete

                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>


            <div class="modal-body">

                <p>
                    Are you sure you want to delete this document?
                </p>

                <p
                    class="text-danger"
                >
                    <small>
                        This action cannot be undone.
                    </small>
                </p>

                <p
                    id="deleteDocName"
                    class="fw-bold"
                ></p>

            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>

                <button
                    type="button"
                    class="btn btn-danger"
                    id="confirmDeleteBtn"
                >
                    Delete
                </button>

            </div>

        </div>

    </div>

</div>


<!-- PATIENT DOCUMENT GENERATION MODAL -->
<div class="modal fade" id="patientDocumentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-file-earmark-pdf me-2"></i>
                    Generate Patient PDF
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div class="selected-patient-card">
                    <strong id="generatePatientName">Patient</strong>
                    <div class="text-muted small mt-1" id="generatePatientCase">Case</div>
                </div>

                <p class="fw-bold mb-2">Choose a document template</p>

                <form id="patientDocumentForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="patient_id" id="generatePatientId">

                    <div class="mb-3">
                        <label class="form-label fw-bold" for="generateCaseId">Animal-bite case</label>
                        <select class="form-select" name="case_id" id="generateCaseId" required>
                            <option value="">Loading cases...</option>
                        </select>
                        <div class="form-text">Choose which patient case should supply the document information.</div>
                    </div>

                    <label class="template-choice">
                        <input type="radio" name="document_type" value="Medical Certificate" required>
                        <i class="bi bi-file-earmark-medical"></i>
                        <div>
                            <strong>Medical Certificate</strong>
                            <span>Patient identity and the selected animal-bite case are filled automatically.</span>
                        </div>
                    </label>

                    <label class="template-choice">
                        <input type="radio" name="document_type" value="Vaccination Certificate" required>
                        <i class="bi bi-shield-check"></i>
                        <div>
                            <strong>Vaccination Certificate</strong>
                            <span>Includes completed vaccinations and the next recorded schedule.</span>
                        </div>
                    </label>

                    <label class="template-choice">
                        <input type="radio" name="document_type" value="Referral Letter" required>
                        <i class="bi bi-file-earmark-arrow-up"></i>
                        <div>
                            <strong>Referral Letter</strong>
                            <span>Uses the patient profile and current animal-bite case summary.</span>
                        </div>
                    </label>
                </form>

                <div class="alert alert-info py-2 mt-3 mb-0 small">
                    <i class="bi bi-info-circle me-1"></i>
                    Information is retrieved again from the database when Generate PDF is clicked.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="generatePatientDocumentBtn">
                    <i class="bi bi-file-earmark-pdf me-1"></i>
                    Generate PDF
                </button>
            </div>
        </div>
    </div>
</div>

<!-- LOADING -->

<div
    class="loading-overlay"
    id="loadingOverlay"
>

    <div class="spinner"></div>

</div>


<!-- TOAST -->

<div
    class="toast-container-custom"
    id="toastContainer"
></div>

<div class="modal fade confirm-modal" id="logoutConfirmModal" tabindex="-1"
     aria-labelledby="logoutConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <div class="modal-icon logout-icon">
                    <i class="bi bi-box-arrow-right"></i>
                </div>

                <h2 class="modal-title" id="logoutConfirmModalLabel">
                    Log out of Smart Bite Care?
                </h2>
            </div>

            <div class="modal-body">
                <p class="mb-0">
                    Make sure you have saved any unfinished work before leaving your account.
                </p>
            </div>

            <div class="modal-footer">
                <button
                    type="button"
                    class="btn btn-light border"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>

                <a
                    href="logout.php"
                    class="btn btn-danger d-flex align-items-center justify-content-center"
                >
                    <i class="bi bi-box-arrow-right me-1"></i>
                    Yes, Log Out
                </a>
            </div>

        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


<script>

/*
|--------------------------------------------------------------------------
| GLOBAL VARIABLES
|--------------------------------------------------------------------------
*/

let currentPage = 1;
let totalPages = 1;
let currentPatientPage = 1;
let totalPatientPages = 1;
let currentDownloadPage = 1;
let totalDownloadPages = 1;
let activeMedicalTab = 'documents';
let documentsLoaded = false;
let patientsLoaded = false;
let downloadsLoaded = false;
let documentRequestSerial = 0;
let patientRequestSerial = 0;
let downloadRequestSerial = 0;

let currentViewId = null;
let deleteId = null;


/*
|--------------------------------------------------------------------------
| ELEMENTS
|--------------------------------------------------------------------------
*/

const uploadModalElement =
    document.getElementById('uploadModal');

const viewModalElement =
    document.getElementById('viewModal');

const editModalElement =
    document.getElementById('editModal');

const deleteModalElement =
    document.getElementById('deleteModal');

const patientDocumentModalElement =
    document.getElementById('patientDocumentModal');

const uploadModal =
    new bootstrap.Modal(uploadModalElement);

const viewModal =
    new bootstrap.Modal(viewModalElement);

const editModal =
    new bootstrap.Modal(editModalElement);

const deleteModal =
    new bootstrap.Modal(deleteModalElement);

const patientDocumentModal =
    new bootstrap.Modal(patientDocumentModalElement);


/*
|--------------------------------------------------------------------------
| TOAST
|--------------------------------------------------------------------------
*/

function showToast(
    message,
    subMessage = '',
    isError = false
) {

    const container =
        document.getElementById(
            'toastContainer'
        );

    const toast =
        document.createElement('div');

    toast.className =
        'toast-custom' +
        (isError ? ' error' : '');

    const icon =
        isError
            ? 'bi-exclamation-circle-fill'
            : 'bi-check-circle-fill';

    const iconSpan =
        document.createElement('span');

    iconSpan.className =
        'toast-icon';

    iconSpan.innerHTML =
        `<i class="bi ${icon}"></i>`;

    const messageDiv =
        document.createElement('div');

    messageDiv.className =
        'toast-msg';

    messageDiv.textContent =
        message;

    if (subMessage) {

        const small =
            document.createElement('small');

        small.textContent =
            subMessage;

        messageDiv.appendChild(small);
    }

    toast.appendChild(iconSpan);
    toast.appendChild(messageDiv);

    container.appendChild(toast);

    requestAnimationFrame(() => {
        toast.classList.add('show');
    });

    setTimeout(() => {

        toast.classList.remove('show');

        setTimeout(() => {
            toast.remove();
        }, 400);

    }, 3500);
}


/*
|--------------------------------------------------------------------------
| LOADING
|--------------------------------------------------------------------------
*/

function showLoading() {

    document
        .getElementById('loadingOverlay')
        .classList
        .add('show');
}

function hideLoading() {

    document
        .getElementById('loadingOverlay')
        .classList
        .remove('show');
}


/*
|--------------------------------------------------------------------------
| ESCAPE HTML
|--------------------------------------------------------------------------
*/

function escapeHtml(value) {

    if (value === null || value === undefined) {
        return '';
    }

    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}


/*
|--------------------------------------------------------------------------
| FILE VALIDATION - FRONTEND
|--------------------------------------------------------------------------
*/

const allowedExtensions = [
    'pdf',
    'doc',
    'docx',
    'xls',
    'xlsx',
    'jpg',
    'jpeg',
    'png',
    'txt'
];

function validateClientFile(file) {

    if (!file) {
        return 'Please select a file.';
    }

    if (file.size <= 0) {
        return 'The selected file is empty.';
    }

    if (file.size > 10 * 1024 * 1024) {
        return 'File exceeds the 10MB limit.';
    }

    const parts =
        file.name.split('.');

    const extension =
        parts.length > 1
            ? parts.pop().toLowerCase()
            : '';

    if (!allowedExtensions.includes(extension)) {

        return (
            'File type not allowed. ' +
            'Allowed: PDF, DOC, DOCX, XLS, XLSX, JPG, JPEG, PNG, TXT.'
        );
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| UPLOAD FILE AREA
|--------------------------------------------------------------------------
*/

const uploadArea =
    document.getElementById(
        'fileUploadArea'
    );

const fileInput =
    document.getElementById(
        'fileInput'
    );


uploadArea.addEventListener(
    'click',
    () => fileInput.click()
);


uploadArea.addEventListener(
    'dragover',
    function(event) {

        event.preventDefault();

        uploadArea.classList.add(
            'dragover'
        );
    }
);


uploadArea.addEventListener(
    'dragleave',
    function() {

        uploadArea.classList.remove(
            'dragover'
        );
    }
);


uploadArea.addEventListener(
    'drop',
    function(event) {

        event.preventDefault();

        uploadArea.classList.remove(
            'dragover'
        );

        if (
            event.dataTransfer.files.length
        ) {

            const file =
                event.dataTransfer.files[0];

            const error =
                validateClientFile(file);

            if (error) {

                showToast(
                    'Invalid file',
                    error,
                    true
                );

                return;
            }

            fileInput.files =
                event.dataTransfer.files;

            showSelectedUploadFile(file);
        }
    }
);


fileInput.addEventListener(
    'change',
    function() {

        if (!this.files.length) {
            return;
        }

        const file =
            this.files[0];

        const error =
            validateClientFile(file);

        if (error) {

            this.value = '';

            showToast(
                'Invalid file',
                error,
                true
            );

            return;
        }

        showSelectedUploadFile(file);
    }
);


function showSelectedUploadFile(file) {

    const display =
        document.getElementById(
            'selectedFileName'
        );

    display.textContent =
        file.name +
        ' (' +
        (file.size / 1024 / 1024).toFixed(2) +
        ' MB)';

    display.style.display =
        'block';
}


/*
|--------------------------------------------------------------------------
| EDIT FILE AREA
|--------------------------------------------------------------------------
*/

const editFileUploadArea =
    document.getElementById(
        'editFileUploadArea'
    );

const editFileInput =
    document.getElementById(
        'editFileInput'
    );


editFileUploadArea.addEventListener(
    'click',
    () => editFileInput.click()
);


editFileUploadArea.addEventListener(
    'dragover',
    function(event) {

        event.preventDefault();

        editFileUploadArea.classList.add(
            'dragover'
        );
    }
);


editFileUploadArea.addEventListener(
    'dragleave',
    function() {

        editFileUploadArea.classList.remove(
            'dragover'
        );
    }
);


editFileUploadArea.addEventListener(
    'drop',
    function(event) {

        event.preventDefault();

        editFileUploadArea.classList.remove(
            'dragover'
        );

        if (
            event.dataTransfer.files.length
        ) {

            const file =
                event.dataTransfer.files[0];

            const error =
                validateClientFile(file);

            if (error) {

                showToast(
                    'Invalid file',
                    error,
                    true
                );

                return;
            }

            editFileInput.files =
                event.dataTransfer.files;

            showSelectedEditFile(file);
        }
    }
);


editFileInput.addEventListener(
    'change',
    function() {

        if (!this.files.length) {
            return;
        }

        const file =
            this.files[0];

        const error =
            validateClientFile(file);

        if (error) {

            this.value = '';

            showToast(
                'Invalid file',
                error,
                true
            );

            return;
        }

        showSelectedEditFile(file);
    }
);


function showSelectedEditFile(file) {

    const display =
        document.getElementById(
            'editSelectedFileName'
        );

    display.textContent =
        'New file: ' +
        file.name +
        ' (' +
        (file.size / 1024 / 1024).toFixed(2) +
        ' MB)';

    display.style.display =
        'block';
}


/*
|--------------------------------------------------------------------------
| OPEN UPLOAD MODAL
|--------------------------------------------------------------------------
*/

function openUploadModal(
    documentType = ''
) {

    document
        .getElementById(
            'uploadForm'
        )
        .reset();

    document
        .getElementById(
            'selectedFileName'
        )
        .style.display = 'none';

    document
        .getElementById(
            'fileInput'
        )
        .value = '';

    if (documentType) {

        document
            .getElementById(
                'docTypeSelect'
            )
            .value = documentType;
    }

    uploadModal.show();
}


/*
|--------------------------------------------------------------------------
| UPLOAD DOCUMENT
|--------------------------------------------------------------------------
*/

document
    .getElementById('uploadBtn')
    .addEventListener(
        'click',
        function() {

            const form =
                document.getElementById(
                    'uploadForm'
                );

            if (!form.checkValidity()) {

                form.reportValidity();

                return;
            }

            const file =
                fileInput.files[0];

            const fileError =
                validateClientFile(file);

            if (fileError) {

                showToast(
                    'Upload failed',
                    fileError,
                    true
                );

                return;
            }

            const formData =
                new FormData(form);

            formData.append(
                'action',
                'upload_document'
            );

            showLoading();

            fetch(
                window.location.href,
                {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With':
                            'XMLHttpRequest'
                    }
                }
            )
            .then(response => {

                if (!response.ok) {
                    throw new Error(
                        'Server returned an error.'
                    );
                }

                return response.json();
            })
            .then(data => {

                hideLoading();

                if (data.success) {

                    uploadModal.hide();

                    showToast(
                        'Document uploaded successfully.'
                    );

                    currentPage = 1;

                    documentsLoaded = false;
                    refreshDocuments();

                } else {

                    showToast(
                        'Upload failed',
                        data.message ||
                        data.error ||
                        'Unable to upload document.',
                        true
                    );
                }
            })
            .catch(error => {

                hideLoading();

                showToast(
                    'Upload error',
                    error.message,
                    true
                );
            });
        }
    );


/*
|--------------------------------------------------------------------------
| GET DOCUMENT
|--------------------------------------------------------------------------
*/

async function getDocument(id) {

    const response =
        await fetch(
            window.location.href +
            '?action=get_document&document_id=' +
            encodeURIComponent(id),
            {
                headers: {
                    'X-Requested-With':
                        'XMLHttpRequest'
                }
            }
        );

    if (!response.ok) {

        throw new Error(
            'Unable to communicate with the server.'
        );
    }

    const data =
        await response.json();

    if (!data.success) {

        throw new Error(
            data.message ||
            data.error ||
            'Document could not be retrieved.'
        );
    }

    return data.document;
}


/*
|--------------------------------------------------------------------------
| VIEW DOCUMENT
|--------------------------------------------------------------------------
*/

function viewDocument(id) {

    currentViewId = id;

    showLoading();

    getDocument(id)

        .then(d => {

            hideLoading();

            const body =
                document.getElementById(
                    'viewModalBody'
                );

            document.getElementById('viewEditBtn').style.display =
                activeMedicalTab === 'download' ? 'none' : '';

            const filePath =
                escapeHtml(d.file_path);

            const fileName =
                escapeHtml(d.file_name);

            body.innerHTML = `

                <div class="detail-row">
                    <div class="detail-label">
                        Name
                    </div>
                    <div class="detail-value">
                        ${escapeHtml(d.document_name)}
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-label">
                        Type
                    </div>
                    <div class="detail-value">
                        ${escapeHtml(d.document_type)}
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-label">
                        File
                    </div>
                    <div class="detail-value">
                        ${fileName}
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-label">
                        File Size
                    </div>
                    <div class="detail-value">
                        ${escapeHtml(d.file_size_mb)} MB
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-label">
                        Uploaded By
                    </div>
                    <div class="detail-value">
                        ${escapeHtml(d.uploaded_by_name)}
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-label">
                        Uploaded
                    </div>
                    <div class="detail-value">
                        ${escapeHtml(d.formatted_date)}
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-label">
                        Last Updated
                    </div>
                    <div class="detail-value">
                        ${escapeHtml(d.formatted_updated)}
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2 flex-wrap">

                    <a
                        href="${filePath}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="btn btn-primary"
                    >
                        <i class="bi bi-eye"></i>
                        Open File
                    </a>

                    <button
                        type="button"
                        class="btn btn-success"
                        onclick="downloadDocument(${d.document_id})"
                    >
                        <i class="bi bi-download"></i>
                        Download
                    </button>

                    <button
                        type="button"
                        class="btn btn-secondary"
                        onclick="printDocument(${d.document_id})"
                    >
                        <i class="bi bi-printer"></i>
                        Print
                    </button>

                </div>
            `;

            viewModal.show();

        })
        .catch(error => {

            hideLoading();

            showToast(
                'Unable to load document',
                error.message,
                true
            );
        });
}


/*
|--------------------------------------------------------------------------
| EDIT DOCUMENT
|--------------------------------------------------------------------------
*/

function editDocument(id) {

    showLoading();

    getDocument(id)

        .then(d => {

            hideLoading();

            document
                .getElementById(
                    'editDocumentId'
                )
                .value = d.document_id;

            document
                .getElementById(
                    'editDocType'
                )
                .value = d.document_type;

            document
                .getElementById(
                    'editDocName'
                )
                .value = d.document_name;

            document
                .getElementById(
                    'currentFileDisplay'
                )
                .innerHTML = `

                    <i class="bi bi-file-earmark"></i>

                    <strong>
                        ${escapeHtml(d.file_name)}
                    </strong>

                    <br>

                    <small class="text-muted">
                        ${escapeHtml(d.file_size_mb)}
                        MB
                    </small>

                `;

            document
                .getElementById(
                    'editFileInput'
                )
                .value = '';

            document
                .getElementById(
                    'editSelectedFileName'
                )
                .style.display = 'none';

            editModal.show();

        })
        .catch(error => {

            hideLoading();

            showToast(
                'Unable to load document',
                error.message,
                true
            );
        });
}


/*
|--------------------------------------------------------------------------
| EDIT BUTTON FROM VIEW MODAL
|--------------------------------------------------------------------------
*/

document
    .getElementById('viewEditBtn')
    .addEventListener(
        'click',
        function() {

            if (!currentViewId) {

                showToast(
                    'Error',
                    'No document selected.',
                    true
                );

                return;
            }

            viewModal.hide();

            editDocument(
                currentViewId
            );
        }
    );


/*
|--------------------------------------------------------------------------
| SAVE EDIT
|--------------------------------------------------------------------------
*/

document
    .getElementById('updateDocBtn')
    .addEventListener(
        'click',
        function() {

            const form =
                document.getElementById(
                    'editForm'
                );

            if (!form.checkValidity()) {

                form.reportValidity();

                return;
            }

            const documentId =
                document.getElementById(
                    'editDocumentId'
                ).value;

            if (!documentId) {

                showToast(
                    'Error',
                    'Invalid document ID.',
                    true
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | OPTIONAL NEW FILE
            |--------------------------------------------------------------------------
            */

            const replacementFile =
                editFileInput.files[0];

            if (replacementFile) {

                const fileError =
                    validateClientFile(
                        replacementFile
                    );

                if (fileError) {

                    showToast(
                        'Invalid replacement file',
                        fileError,
                        true
                    );

                    return;
                }
            }

            const formData =
                new FormData(form);

            formData.append(
                'action',
                'update_document'
            );

            showLoading();

            fetch(
                window.location.href,
                {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With':
                            'XMLHttpRequest'
                    }
                }
            )
            .then(response => {

                if (!response.ok) {

                    throw new Error(
                        'Server returned an error.'
                    );
                }

                return response.json();
            })
            .then(data => {

                hideLoading();

                if (data.success) {

                    editModal.hide();

                    showToast(
                        data.message ||
                        'Document updated successfully.'
                    );

                    refreshDocuments();

                } else {

                    showToast(
                        'Update failed',
                        data.message ||
                        data.error ||
                        'Unable to update document.',
                        true
                    );
                }
            })
            .catch(error => {

                hideLoading();

                showToast(
                    'Update error',
                    error.message,
                    true
                );
            });
        }
    );


/*
|--------------------------------------------------------------------------
| DOWNLOAD
|--------------------------------------------------------------------------
*/

async function downloadDocument(id) {

    try {

        showLoading();

        const d =
            await getDocument(id);

        hideLoading();

        const link =
            document.createElement('a');

        link.href =
            d.file_path;

        link.download =
            d.file_name;

        link.target =
            '_blank';

        document.body.appendChild(link);

        link.click();

        document.body.removeChild(link);

        showToast(
            'Download started.'
        );

    } catch (error) {

        hideLoading();

        showToast(
            'Download failed',
            error.message,
            true
        );
    }
}


/*
|--------------------------------------------------------------------------
| PRINT
|--------------------------------------------------------------------------
*/

async function printDocument(id) {

    try {

        showLoading();

        const d =
            await getDocument(id);

        hideLoading();

        /*
        |--------------------------------------------------------------------------
        | Open printable document in a new browser tab.
        |--------------------------------------------------------------------------
        */

        const printWindow =
            window.open(
                d.file_path,
                '_blank'
            );

        if (!printWindow) {

            showToast(
                'Print blocked',
                'Please allow pop-ups for this site.',
                true
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | PDF / browser-supported files
        |
        | The browser's native viewer handles printing.
        |--------------------------------------------------------------------------
        */

        showToast(
            'Document opened',
            'Use the browser print option to print the form.'
        );

    } catch (error) {

        hideLoading();

        showToast(
            'Print failed',
            error.message,
            true
        );
    }
}


/*
|--------------------------------------------------------------------------
| DELETE DOCUMENT
|--------------------------------------------------------------------------
*/

function deleteDocument(id) {

    deleteId = id;

    showLoading();

    getDocument(id)

        .then(d => {

            hideLoading();

            document
                .getElementById(
                    'deleteDocName'
                )
                .textContent =
                d.document_name;

            deleteModal.show();

        })
        .catch(error => {

            hideLoading();

            showToast(
                'Unable to delete document',
                error.message,
                true
            );
        });
}


/*
|--------------------------------------------------------------------------
| CONFIRM DELETE
|--------------------------------------------------------------------------
*/

document
    .getElementById(
        'confirmDeleteBtn'
    )
    .addEventListener(
        'click',
        function() {

            if (!deleteId) {

                showToast(
                    'Error',
                    'No document selected.',
                    true
                );

                return;
            }

            showLoading();

            fetch(
                window.location.href +
                '?action=delete_document&document_id=' +
                encodeURIComponent(deleteId),
                {
                    method: 'GET',
                    headers: {
                        'X-Requested-With':
                            'XMLHttpRequest'
                    }
                }
            )
            .then(response => {

                if (!response.ok) {

                    throw new Error(
                        'Server returned an error.'
                    );
                }

                return response.json();
            })
            .then(data => {

                hideLoading();

                if (data.success) {

                    deleteModal.hide();

                    showToast(
                        'Document deleted successfully.'
                    );

                    deleteId = null;

                    if (activeMedicalTab === 'download') {
                        downloadsLoaded = false;
                        refreshDownloads();
                    } else {
                        documentsLoaded = false;
                        refreshDocuments();
                    }

                } else {

                    showToast(
                        'Delete failed',
                        data.message ||
                        data.error ||
                        'Unable to delete document.',
                        true
                    );
                }
            })
            .catch(error => {

                hideLoading();

                showToast(
                    'Delete error',
                    error.message,
                    true
                );
            });
        }
    );


/*
|--------------------------------------------------------------------------
| REFRESH DOCUMENTS
|--------------------------------------------------------------------------
*/

function refreshDocuments() {

    const search =
        document
            .getElementById(
                'searchInput'
            )
            .value
            .trim();

    const type =
        document
            .getElementById(
                'typeFilter'
            )
            .value;

    const url =
        window.location.pathname +
        '?action=fetch_documents' +
        '&search=' +
        encodeURIComponent(search) +
        '&document_type=' +
        encodeURIComponent(type) +
        '&page=' +
        encodeURIComponent(currentPage);

    const requestSerial = ++documentRequestSerial;

    fetch(
        url,
        {
            headers: {
                'X-Requested-With':
                    'XMLHttpRequest'
            }
        }
    )
    .then(response => {

        if (!response.ok) {

            throw new Error(
                'Unable to load documents.'
            );
        }

        return response.json();
    })
    .then(data => {

        if (requestSerial !== documentRequestSerial) {
            return;
        }

        if (data.success) {

            documentsLoaded = true;

            renderDocuments(
                data.documents || []
            );

            renderPagination(
                data
            );

            document
                .getElementById(
                    'recordCount'
                )
                .textContent =
                (
                    data.documents
                        ? data.documents.length
                        : 0
                ) +
                ' of ' +
                data.total +
                ' templates';

        } else {

            showToast(
                'Unable to load documents',
                data.message ||
                data.error ||
                'Unknown error.',
                true
            );
        }
    })
    .catch(error => {

        if (requestSerial !== documentRequestSerial) {
            return;
        }

        showToast(
            'Error',
            error.message,
            true
        );
    });
}


/*
|--------------------------------------------------------------------------
| RENDER DOCUMENT TABLE
|--------------------------------------------------------------------------
*/

function renderDocuments(docs) {

    const tbody =
        document.getElementById(
            'documentsTableBody'
        );

    if (!docs || docs.length === 0) {

        tbody.innerHTML = `

            <tr>

                <td colspan="5">

                    <div class="no-records">

                        <i
                            class="bi bi-file-earmark-text"
                        ></i>

                        <p>
                            No documents found.
                        </p>

                    </div>

                </td>

            </tr>

        `;

        return;
    }

    let html = '';

    docs.forEach(d => {

        let badgeClass =
            String(
                d.document_type || 'Other'
            )
            .toLowerCase()
            .replace(/ /g, '-');

        html += `

            <tr>

                <td>

                    <span
                        class="document-badge ${escapeHtml(badgeClass)}"
                    >
                        ${escapeHtml(
                            d.document_type
                        )}
                    </span>

                </td>

                <td>
                    ${escapeHtml(
                        d.document_name
                    )}
                </td>

                <td>
                    ${escapeHtml(
                        d.uploaded_by_name ||
                        'Unknown'
                    )}
                </td>

                <td>
                    ${escapeHtml(
                        d.formatted_date
                    )}
                </td>

                <td>

                    <div class="actions">

                        <button
                            type="button"
                            class="action-btn view"
                            onclick="viewDocument(${Number(d.document_id)})"
                            title="View"
                        >
                            <i class="bi bi-eye"></i>
                        </button>

                        <button
                            type="button"
                            class="action-btn edit"
                            onclick="editDocument(${Number(d.document_id)})"
                            title="Edit"
                        >
                            <i class="bi bi-pencil"></i>
                        </button>

                        <button
                            type="button"
                            class="action-btn print"
                            onclick="printDocument(${Number(d.document_id)})"
                            title="Print"
                        >
                            <i class="bi bi-printer"></i>
                        </button>

                        <button
                            type="button"
                            class="action-btn download"
                            onclick="downloadDocument(${Number(d.document_id)})"
                            title="Download"
                        >
                            <i class="bi bi-download"></i>
                        </button>

                        <button
                            type="button"
                            class="action-btn delete"
                            onclick="deleteDocument(${Number(d.document_id)})"
                            title="Delete"
                        >
                            <i class="bi bi-trash"></i>
                        </button>

                    </div>

                </td>

            </tr>

        `;
    });

    tbody.innerHTML = html;
}


/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/

function renderPagination(data) {

    const area =
        document.getElementById(
            'paginationArea'
        );

    totalPages =
        Number(data.pages) || 1;

    currentPage =
        Number(data.current_page) || 1;

    let html = '';

    /*
    |--------------------------------------------------------------------------
    | PREVIOUS
    |--------------------------------------------------------------------------
    */

    html += `

        <a
            href="#"
            class="page-item ${
                currentPage <= 1
                    ? 'disabled'
                    : ''
            }"
            onclick="
                event.preventDefault();
                goToPage(${currentPage - 1});
            "
        >
            <i class="bi bi-chevron-left"></i>
        </a>

    `;


    /*
    |--------------------------------------------------------------------------
    | PAGE NUMBERS
    |--------------------------------------------------------------------------
    */

    for (
        let i = 1;
        i <= totalPages;
        i++
    ) {

        html += `

            <a
                href="#"
                class="page-item ${
                    i === currentPage
                        ? 'active'
                        : ''
                }"
                onclick="
                    event.preventDefault();
                    goToPage(${i});
                "
            >
                ${i}
            </a>

        `;
    }


    /*
    |--------------------------------------------------------------------------
    | NEXT
    |--------------------------------------------------------------------------
    */

    html += `

        <a
            href="#"
            class="page-item ${
                currentPage >= totalPages
                    ? 'disabled'
                    : ''
            }"
            onclick="
                event.preventDefault();
                goToPage(${currentPage + 1});
            "
        >
            <i class="bi bi-chevron-right"></i>
        </a>

    `;

    area.innerHTML = html;
}


function goToPage(page) {

    if (
        page < 1 ||
        page > totalPages
    ) {
        return;
    }

    currentPage = page;

    refreshDocuments();
}


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

let searchTimeout = null;

document
    .getElementById(
        'searchInput'
    )
    .addEventListener(
        'input',
        function() {

            clearTimeout(
                searchTimeout
            );

            searchTimeout =
                setTimeout(
                    function() {

                        currentPage = 1;

                        refreshDocuments();

                    },
                    400
                );
        }
    );


/*
|--------------------------------------------------------------------------
| FILTER
|--------------------------------------------------------------------------
*/

document
    .getElementById(
        'typeFilter'
    )
    .addEventListener(
        'change',
        function() {

            currentPage = 1;

            refreshDocuments();
        }
    );


/*
|--------------------------------------------------------------------------
| DOCUMENTS / PATIENTS / DOWNLOAD TABS
|--------------------------------------------------------------------------
*/

function switchMedicalTab(tabName) {
    const validTabs = ['documents', 'patients', 'download'];
    const requestedTab = validTabs.includes(tabName) ? tabName : 'documents';

    if (requestedTab === activeMedicalTab) {
        if (requestedTab === 'documents' && !documentsLoaded) refreshDocuments();
        if (requestedTab === 'patients' && !patientsLoaded) refreshPatients();
        if (requestedTab === 'download' && !downloadsLoaded) refreshDownloads();
        return;
    }

    activeMedicalTab = requestedTab;

    const tabConfig = [
        ['documents', 'documentsPanel', 'documentsTabButton'],
        ['patients', 'patientsPanel', 'patientsTabButton'],
        ['download', 'downloadPanel', 'downloadTabButton']
    ];

    tabConfig.forEach(([name, panelId, buttonId]) => {
        const isActive = activeMedicalTab === name;
        document.getElementById(panelId).classList.toggle('active', isActive);
        document.getElementById(buttonId).classList.toggle('active', isActive);
        document.getElementById(buttonId).setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    if (activeMedicalTab === 'patients' && !patientsLoaded) {
        refreshPatients();
    } else if (activeMedicalTab === 'documents' && !documentsLoaded) {
        refreshDocuments();
    } else if (activeMedicalTab === 'download' && !downloadsLoaded) {
        refreshDownloads();
    }
}

function refreshPatients() {
    const search = document.getElementById('patientSearchInput').value.trim();
    const url = window.location.pathname +
        '?action=fetch_patients&search=' + encodeURIComponent(search) +
        '&page=' + encodeURIComponent(currentPatientPage);

    const requestSerial = ++patientRequestSerial;

    fetch(url, {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Unable to load patients.');
        }
        return response.json();
    })
    .then(data => {
        if (requestSerial !== patientRequestSerial) {
            return;
        }

        if (!data.success) {
            throw new Error(data.message || 'Unable to load patients.');
        }

        patientsLoaded = true;
        renderPatients(data.patients || []);
        renderPatientPagination(data);
        document.getElementById('patientRecordCount').textContent =
            (data.patients ? data.patients.length : 0) +
            ' of ' + data.total + ' patients';
    })
    .catch(error => {
        if (requestSerial !== patientRequestSerial) {
            return;
        }
        showToast('Patient list error', error.message, true);
    });
}

function renderPatients(patients) {
    const body = document.getElementById('patientsTableBody');

    if (!patients.length) {
        body.innerHTML = `
            <tr>
                <td colspan="5">
                    <div class="no-records">
                        <i class="bi bi-person-x"></i>
                        <p>No patients found in this branch.</p>
                    </div>
                </td>
            </tr>`;
        return;
    }

    body.innerHTML = patients.map(patient => {
        const hasCase = Number(patient.case_id) > 0;
        const birthday = patient.birthday
            ? new Date(patient.birthday + 'T00:00:00').toLocaleDateString()
            : 'Birthday not recorded';
        const biteDate = patient.date_of_bite
            ? new Date(patient.date_of_bite + 'T00:00:00').toLocaleDateString()
            : 'Date not recorded';

        return `
            <tr>
                <td class="patient-cell">
                    <strong>${escapeHtml(patient.full_name)}</strong>
                    <small>${escapeHtml(patient.gender || 'N/A')} · ${escapeHtml(patient.age ?? 'N/A')} years old</small>
                    <small>${escapeHtml(birthday)}</small>
                </td>
                <td class="patient-cell">
                    <small><i class="bi bi-telephone me-1"></i>${escapeHtml(patient.contact_number || 'N/A')}</small>
                    <small><i class="bi bi-envelope me-1"></i>${escapeHtml(patient.email || 'N/A')}</small>
                </td>
                <td class="case-cell">
                    ${hasCase
                        ? `<strong>${escapeHtml(patient.case_number || ('C' + patient.case_id))}</strong>
                           <small>Bite date: ${escapeHtml(biteDate)}</small>`
                        : '<span class="text-muted">No animal-bite case</span>'}
                </td>
                <td>
                    ${hasCase
                        ? `<span class="document-badge medical-certificate">${escapeHtml(patient.case_status || 'Not set')}</span>`
                        : '<span class="text-muted">—</span>'}
                </td>
                <td>
                    <button
                        type="button"
                        class="generate-pdf-btn"
                        data-patient-id="${Number(patient.patient_id)}"
                        data-case-id="${Number(patient.case_id || 0)}"
                        data-patient-name="${escapeHtml(patient.full_name)}"
                        data-case-number="${escapeHtml(patient.case_number || '')}"
                        ${hasCase ? '' : 'disabled'}
                    >
                        <i class="bi bi-file-earmark-pdf me-1"></i>
                        Generate
                    </button>
                </td>
            </tr>`;
    }).join('');
}

function renderPatientPagination(data) {
    const area = document.getElementById('patientPaginationArea');
    totalPatientPages = Number(data.pages) || 1;
    currentPatientPage = Number(data.current_page) || 1;
    let html = `
        <a href="#" class="page-item ${currentPatientPage <= 1 ? 'disabled' : ''}"
           onclick="event.preventDefault(); goToPatientPage(${currentPatientPage - 1});">
            <i class="bi bi-chevron-left"></i>
        </a>`;

    const start = Math.max(1, currentPatientPage - 2);
    const end = Math.min(totalPatientPages, currentPatientPage + 2);

    for (let page = start; page <= end; page++) {
        html += `
            <a href="#" class="page-item ${page === currentPatientPage ? 'active' : ''}"
               onclick="event.preventDefault(); goToPatientPage(${page});">
                ${page}
            </a>`;
    }

    html += `
        <a href="#" class="page-item ${currentPatientPage >= totalPatientPages ? 'disabled' : ''}"
           onclick="event.preventDefault(); goToPatientPage(${currentPatientPage + 1});">
            <i class="bi bi-chevron-right"></i>
        </a>`;
    area.innerHTML = html;
}

function goToPatientPage(page) {
    if (page < 1 || page > totalPatientPages) {
        return;
    }
    currentPatientPage = page;
    refreshPatients();
}

function refreshDownloads() {
    const search = document.getElementById('downloadSearchInput').value.trim();
    const type = document.getElementById('downloadTypeFilter').value;
    const url = window.location.pathname +
        '?action=fetch_downloads&search=' + encodeURIComponent(search) +
        '&document_type=' + encodeURIComponent(type) +
        '&page=' + encodeURIComponent(currentDownloadPage);

    const requestSerial = ++downloadRequestSerial;
    fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(response => {
            if (!response.ok) throw new Error('Unable to load generated patient documents.');
            return response.json();
        })
        .then(data => {
            if (requestSerial !== downloadRequestSerial) return;
            if (!data.success) throw new Error(data.message || 'Unable to load generated patient documents.');
            downloadsLoaded = true;
            renderDownloads(data.documents || []);
            renderDownloadPagination(data);
            document.getElementById('downloadRecordCount').textContent =
                (data.documents ? data.documents.length : 0) + ' of ' + data.total + ' generated documents';
        })
        .catch(error => {
            if (requestSerial !== downloadRequestSerial) return;
            showToast('Download list error', error.message, true);
        });
}

function renderDownloads(docs) {
    const tbody = document.getElementById('downloadsTableBody');
    if (!docs || docs.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5">
                    <div class="no-records">
                        <i class="bi bi-file-earmark-arrow-down"></i>
                        <p>No generated patient PDFs found.</p>
                    </div>
                </td>
            </tr>`;
        return;
    }

    tbody.innerHTML = docs.map(d => {
        const badgeClass = String(d.document_type || 'Other').toLowerCase().replace(/\s+/g, '-');
        return `
            <tr>
                <td><span class="document-badge ${escapeHtml(badgeClass)}">${escapeHtml(d.document_type)}</span></td>
                <td>${escapeHtml(d.document_name)}</td>
                <td>${escapeHtml(d.uploaded_by_name || 'Unknown')}</td>
                <td>${escapeHtml(d.formatted_date || '')}</td>
                <td>
                    <div class="actions">
                        <button type="button" class="action-btn view" onclick="viewDocument(${Number(d.document_id)})" title="View"><i class="bi bi-eye"></i></button>
                        <button type="button" class="action-btn print" onclick="printDocument(${Number(d.document_id)})" title="Print"><i class="bi bi-printer"></i></button>
                        <button type="button" class="action-btn download" onclick="downloadDocument(${Number(d.document_id)})" title="Download"><i class="bi bi-download"></i></button>
                        <button type="button" class="action-btn delete" onclick="deleteGeneratedDocument(${Number(d.document_id)})" title="Archive"><i class="bi bi-trash"></i></button>
                    </div>
                </td>
            </tr>`;
    }).join('');
}

function renderDownloadPagination(data) {
    const area = document.getElementById('downloadPaginationArea');
    totalDownloadPages = Number(data.pages) || 1;
    currentDownloadPage = Number(data.current_page) || 1;
    let html = '';

    html += `<a href="#" class="page-item ${currentDownloadPage <= 1 ? 'disabled' : ''}" onclick="event.preventDefault(); goToDownloadPage(${currentDownloadPage - 1});"><i class="bi bi-chevron-left"></i></a>`;
    for (let i = 1; i <= totalDownloadPages; i++) {
        html += `<a href="#" class="page-item ${i === currentDownloadPage ? 'active' : ''}" onclick="event.preventDefault(); goToDownloadPage(${i});">${i}</a>`;
    }
    html += `<a href="#" class="page-item ${currentDownloadPage >= totalDownloadPages ? 'disabled' : ''}" onclick="event.preventDefault(); goToDownloadPage(${currentDownloadPage + 1});"><i class="bi bi-chevron-right"></i></a>`;
    area.innerHTML = html;
}

function goToDownloadPage(page) {
    if (page < 1 || page > totalDownloadPages) return;
    currentDownloadPage = page;
    refreshDownloads();
}

let downloadSearchTimeout = null;
document.getElementById('downloadSearchInput').addEventListener('input', function() {
    clearTimeout(downloadSearchTimeout);
    downloadSearchTimeout = setTimeout(() => {
        currentDownloadPage = 1;
        refreshDownloads();
    }, 400);
});

document.getElementById('downloadTypeFilter').addEventListener('change', function() {
    currentDownloadPage = 1;
    refreshDownloads();
});

function deleteGeneratedDocument(id) {
    deleteId = id;
    document.getElementById('deleteDocName').textContent = 'Generated patient PDF';
    deleteModal.show();
}

let patientSearchTimeout = null;
document.getElementById('patientSearchInput').addEventListener('input', function() {
    clearTimeout(patientSearchTimeout);
    patientSearchTimeout = setTimeout(function() {
        currentPatientPage = 1;
        refreshPatients();
    }, 400);
});

document.getElementById('patientsTableBody').addEventListener('click', function(event) {
    const button = event.target.closest('.generate-pdf-btn');
    if (!button || button.disabled) {
        return;
    }

    document.getElementById('patientDocumentForm').reset();
    document.getElementById('generatePatientId').value = button.dataset.patientId;
    document.getElementById('generatePatientName').textContent = button.dataset.patientName;
    document.getElementById('generatePatientCase').textContent = 'Loading patient cases...';
    const caseSelect = document.getElementById('generateCaseId');
    caseSelect.innerHTML = '<option value="">Loading cases...</option>';
    caseSelect.disabled = true;
    document.querySelectorAll('.template-choice').forEach(choice => {
        choice.classList.remove('selected');
    });

    showLoading();
    fetch(
        window.location.pathname +
        '?action=fetch_patient_cases&patient_id=' +
        encodeURIComponent(button.dataset.patientId),
        {headers: {'X-Requested-With': 'XMLHttpRequest'}}
    )
    .then(response => {
        if (!response.ok) {
            throw new Error('Unable to load the patient cases.');
        }
        return response.json();
    })
    .then(data => {
        hideLoading();
        if (!data.success || !data.cases || !data.cases.length) {
            throw new Error(data.message || 'This patient has no available animal-bite case.');
        }

        caseSelect.innerHTML = data.cases.map(item => {
            const details = [
                item.case_number || ('C' + item.case_id),
                item.date_of_bite || 'No bite date',
                item.case_status || 'No status'
            ].join(' · ');
            return `<option value="${Number(item.case_id)}">${escapeHtml(details)}</option>`;
        }).join('');
        caseSelect.disabled = false;
        caseSelect.value = String(button.dataset.caseId);
        document.getElementById('generatePatientCase').textContent =
            data.cases.length + (data.cases.length === 1 ? ' case available' : ' cases available');
        patientDocumentModal.show();
    })
    .catch(error => {
        hideLoading();
        showToast('Unable to open templates', error.message, true);
    });
});

document.querySelectorAll('.template-choice input').forEach(input => {
    input.addEventListener('change', function() {
        document.querySelectorAll('.template-choice').forEach(choice => {
            choice.classList.remove('selected');
        });
        this.closest('.template-choice').classList.add('selected');
    });
});

document.getElementById('generatePatientDocumentBtn').addEventListener('click', function() {
    const form = document.getElementById('patientDocumentForm');

    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const button = this;
    const originalContent = button.innerHTML;
    const formData = new FormData(form);
    formData.append('action', 'generate_patient_document');
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Generating...';
    showLoading();

    fetch(window.location.pathname, {
        method: 'POST',
        body: formData,
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('The server could not generate the document.');
        }
        return response.json();
    })
    .then(data => {
        hideLoading();

        if (!data.success) {
            throw new Error(data.message || 'Unable to generate the patient document.');
        }

        patientDocumentModal.hide();
        showToast('PDF generated', data.document_name || 'The document was saved.');
        currentDownloadPage = 1;
        downloadsLoaded = false;
        switchMedicalTab('download');
        refreshDownloads();
    })
    .catch(error => {
        hideLoading();
        showToast('Generation failed', error.message, true);
    })
    .finally(() => {
        button.disabled = false;
        button.innerHTML = originalContent;
    });
});


/*
|--------------------------------------------------------------------------
| RESET EDIT MODAL AFTER CLOSE
|--------------------------------------------------------------------------
*/

editModalElement.addEventListener(
    'hidden.bs.modal',
    function() {

        document
            .getElementById(
                'editForm'
            )
            .reset();

        document
            .getElementById(
                'editDocumentId'
            )
            .value = '';

        document
            .getElementById(
                'currentFileDisplay'
            )
            .textContent =
            'No file information available.';

        document
            .getElementById(
                'editSelectedFileName'
            )
            .style.display = 'none';

        editFileInput.value = '';
    }
);


/*
|--------------------------------------------------------------------------
| RESET UPLOAD MODAL AFTER CLOSE
|--------------------------------------------------------------------------
*/

uploadModalElement.addEventListener(
    'hidden.bs.modal',
    function() {

        document
            .getElementById(
                'uploadForm'
            )
            .reset();

        document
            .getElementById(
                'selectedFileName'
            )
            .style.display = 'none';

        fileInput.value = '';
    }
);


/*
|--------------------------------------------------------------------------
| AUTO REFRESH
|--------------------------------------------------------------------------
*/

setInterval(
    function() {

        if (!document.hidden) {
            if (activeMedicalTab === 'patients') {
                refreshPatients();
            } else if (activeMedicalTab === 'download') {
                refreshDownloads();
            } else {
                refreshDocuments();
            }
        }

    },
    30000
);


/*
|--------------------------------------------------------------------------
| REFRESH WHEN TAB BECOMES VISIBLE
|--------------------------------------------------------------------------
*/

document.addEventListener(
    'visibilitychange',
    function() {

        if (!document.hidden) {
            if (activeMedicalTab === 'patients') {
                refreshPatients();
            } else if (activeMedicalTab === 'download') {
                refreshDownloads();
            } else {
                refreshDocuments();
            }
        }
    }
);


/*
|--------------------------------------------------------------------------
| INITIAL LOAD
|--------------------------------------------------------------------------
*/

refreshDocuments();

</script>

</body>

</html>
