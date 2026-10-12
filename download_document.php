<?php
declare(strict_types=1);

// Files are identified by database ID; never accept a filesystem path from GET.
session_start();
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'; base-uri 'none'");

function documentFailure(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($message);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    documentFailure(405, 'Method not allowed.');
}

$sessionUserId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
$sessionRoleId = filter_var($_SESSION['role_id'] ?? null, FILTER_VALIDATE_INT);
if (!$sessionUserId || $sessionUserId < 1 || !$sessionRoleId) {
    documentFailure(401, 'Please sign in to access this document.');
}
if (!in_array($sessionRoleId, [3, 4], true)) {
    documentFailure(403, 'Your role cannot access medical documents.');
}

$documentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$documentId || $documentId < 1) {
    documentFailure(400, 'Invalid document ID.');
}

try {
    require_once __DIR__ . '/sources/db_connect.php';

    // Use the current database account, not a cached session branch or role.
    $stmt = $conn->prepare('SELECT role_id, branch_id, status FROM users WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $sessionUserId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || $user['status'] !== 'Active' || (int)$user['role_id'] !== $sessionRoleId || empty($user['branch_id'])) {
        documentFailure(403, 'Your account cannot access this document.');
    }

    $branchId = (string)$user['branch_id'];
    $query = "SELECT file_name, file_path FROM medical_documents
              WHERE document_id = ? AND branch_id = ? AND COALESCE(status, 'Active') = 'Active'";
    // Keep the nurse's existing permission: their own generated documents.
    if ($sessionRoleId === 3) {
        $query .= ' AND uploaded_by = ?';
    }
    $stmt = $conn->prepare($query . ' LIMIT 1');
    if ($sessionRoleId === 3) {
        $stmt->bind_param('isi', $documentId, $branchId, $sessionUserId);
    } else {
        $stmt->bind_param('is', $documentId, $branchId);
    }
    $stmt->execute();
    $document = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$document) {
        documentFailure(404, 'Document not found.');
    }

    $storedPath = str_replace('\\', '/', (string)$document['file_path']);
    if (strpos($storedPath, "\0") !== false || !str_starts_with($storedPath, 'uploads/documents/') || in_array('..', explode('/', $storedPath), true)) {
        documentFailure(404, 'Document not found.');
    }
    $base = realpath(__DIR__ . '/uploads/documents');
    $path = realpath(__DIR__ . '/' . $storedPath);
    if (!$base || !$path || !is_file($path) || !is_readable($path)) {
        documentFailure(404, 'Document file not found.');
    }
    $prefix = $base . DIRECTORY_SEPARATOR;
    $inside = DIRECTORY_SEPARATOR === '\\'
        ? strncasecmp($path, $prefix, strlen($prefix)) === 0
        : strncmp($path, $prefix, strlen($prefix)) === 0;
    if (!$inside) {
        documentFailure(404, 'Document not found.');
    }

    // Never serve uploaded scripts or HTML as executable/active content.
    $types = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'txt' => 'text/plain; charset=UTF-8',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!isset($types[$extension])) {
        documentFailure(404, 'Document not found.');
    }
    $filename = basename(str_replace('\\', '/', (string)$document['file_name']));
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
    if (!$filename || $filename === '.' || $filename === '..') {
        $filename = 'document.' . $extension;
    }
    $attachment = ($_GET['download'] ?? '') === '1'
        || !in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'txt'], true);

    $file = fopen($path, 'rb');
    if ($file === false) {
        documentFailure(404, 'Document file not found.');
    }
    $size = fstat($file)['size'];
} catch (Throwable $error) {
    error_log('Medical document delivery failed: ' . $error->getMessage());
    documentFailure(500, 'Unable to access the document. Please try again later.');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . $types[$extension]);
header('Content-Disposition: ' . ($attachment ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
header('Content-Length: ' . $size);
session_write_close();
if ($method !== 'HEAD') {
    fpassthru($file);
}
fclose($file);
exit;
