<?php
session_start();

require_once 'sources/db_connect.php';

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
        b.branch_name
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
    $username = $userData['username'] ?? 'Admin Staff';
}

$stmt->close();

if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
}

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
        if ($action === 'fetch_documents') {

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

            $where = "WHERE md.branch_id = ?";
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
                <li><a href="AdminStaff_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
            </ul>
        </nav>

    <div class="logout">

        <a href="logout.php">

            <i class="bi bi-box-arrow-right"></i>

            <span>Logout</span>

        </a>

    </div>

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

         <div class="profile">
                <i class="bi bi-person-circle"></i>
                <?php echo htmlspecialchars($username); ?>
                <span style="font-size:12px; color:#adb5bd; font-weight:400; margin-left:4px;">| Admin Staff</span>
            </div>
    </div>


    <div class="content">

        <div class="section-card">

            <!-- TOOLBAR -->

            <div class="toolbar">

                <div class="left-tools">

                    <div class="search-box">

                        <i class="bi bi-search"></i>

                        <input
                            type="text"
                            id="searchInput"
                            placeholder="Search documents..."
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

                    Upload New

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
                                                No documents uploaded yet.
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


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


<script>

/*
|--------------------------------------------------------------------------
| GLOBAL VARIABLES
|--------------------------------------------------------------------------
*/

let currentPage = 1;
let totalPages = 1;

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

const uploadModal =
    new bootstrap.Modal(uploadModalElement);

const viewModal =
    new bootstrap.Modal(viewModalElement);

const editModal =
    new bootstrap.Modal(editModalElement);

const deleteModal =
    new bootstrap.Modal(deleteModalElement);


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

                    refreshDocuments();

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

    showLoading();

    const url =
        window.location.href +
        '?action=fetch_documents' +
        '&search=' +
        encodeURIComponent(search) +
        '&document_type=' +
        encodeURIComponent(type) +
        '&page=' +
        encodeURIComponent(currentPage);

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

        hideLoading();

        if (data.success) {

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
                ' documents';

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

        hideLoading();

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
            refreshDocuments();
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
            refreshDocuments();
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
