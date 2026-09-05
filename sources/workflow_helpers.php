<?php
declare(strict_types=1);

function workflowCsrfToken(): string
{
    if (empty($_SESSION['workflow_csrf'])) {
        $_SESSION['workflow_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['workflow_csrf'];
}

function workflowVerifyCsrf(): void
{
    $posted = (string)($_POST['csrf_token'] ?? '');
    if ($posted === '' || !hash_equals(workflowCsrfToken(), $posted)) {
        throw new RuntimeException('Invalid request token. Refresh the page and try again.');
    }
}

function workflowRequireUser(mysqli $conn, int $requiredRole): array
{
    if (empty($_SESSION['user_id']) || (int)($_SESSION['role_id'] ?? 0) !== $requiredRole) {
        header('Location: login.php');
        exit;
    }
    $stmt = $conn->prepare(
        'SELECT u.user_id, u.username, u.branch_id, b.branch_name, r.role_name
         FROM users u
         LEFT JOIN branches b ON b.branch_id = u.branch_id
         LEFT JOIN roles r ON r.role_id = u.role_id
         WHERE u.user_id = ? AND u.status = "Active" LIMIT 1'
    );
    $id = (int)$_SESSION['user_id'];
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || empty($user['branch_id'])) {
        throw new RuntimeException('Your account has no active branch assignment.');
    }
    return $user;
}

function workflowAudit(mysqli $conn, int $userId, string $branchId, string $action, string $module): void
{
    $stmt = $conn->prepare('INSERT INTO audit_logs (user_id, branch_id, action, module) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isss', $userId, $branchId, $action, $module);
    $stmt->execute();
    $stmt->close();
}

function workflowNotifyRole(mysqli $conn, string $branchId, int $roleId, string $title, string $message, string $type): void
{
    $find = $conn->prepare('SELECT user_id FROM users WHERE branch_id = ? AND role_id = ? AND status = "Active"');
    $find->bind_param('si', $branchId, $roleId);
    $find->execute();
    $users = $find->get_result();
    $insert = $conn->prepare(
        'INSERT INTO notifications (user_id, title, message, notification_type, is_read, created_at)
         VALUES (?, ?, ?, ?, 0, NOW())'
    );
    while ($row = $users->fetch_assoc()) {
        $uid = (int)$row['user_id'];
        $insert->bind_param('isss', $uid, $title, $message, $type);
        $insert->execute();
    }
    $insert->close();
    $find->close();
}

function workflowScheduleStages(string $profile): array
{
    // These profiles mirror the client interview. The clinic must approve them before production use.
    $profiles = [
        'PEP_ID' => [1 => 0, 2 => 3, 3 => 7, 6 => 28],
        'PEP_IM' => [1 => 0, 2 => 3, 3 => 7, 4 => 14, 6 => 28],
        'PREP' => [1 => 0, 3 => 7, 5 => 21],
        'BOOSTER' => [1 => 0, 2 => 3],
        'OTHER' => [1 => 0],
    ];
    return $profiles[$profile] ?? [];
}

function workflowDoseLabel(int $doseNumber): string
{
    return [1 => 'D0', 2 => 'D3', 3 => 'D7', 4 => 'D14', 5 => 'D21', 6 => 'D28'][$doseNumber] ?? ('Dose ' . $doseNumber);
}

function workflowCreateSchedule(
    mysqli $conn,
    int $visitId,
    int $patientId,
    int $caseId,
    string $branchId,
    string $profile,
    string $d0Date,
    int $nurseId
): void {
    $stages = workflowScheduleStages($profile);
    if (!$stages) {
        throw new RuntimeException('Unsupported treatment profile.');
    }

    // Preserve completed administrations. Replace only unfinished schedules for this case.
    $archive = $conn->prepare(
        "UPDATE vaccination_records
         SET is_archived = 1, archived_at = NOW(), archived_by = ?
         WHERE case_id = ? AND branch_id = ? AND vaccination_status = 'Scheduled' AND is_archived = 0"
    );
    $archive->bind_param('iis', $nurseId, $caseId, $branchId);
    $archive->execute();
    $archive->close();

    $insert = $conn->prepare(
        "INSERT INTO vaccination_records
         (patient_id, case_id, visit_id, item_id, vaccine_name, unit_id, quantity_used,
          branch_id, dose_number, treatment_profile, scheduled_date, vaccination_status,
          scheduled_by, is_final_dose, remarks, nurse_id)
         VALUES (?, ?, ?, NULL, NULL, NULL, 0, ?, ?, ?, ?, 'Scheduled', ?, ?, ?, NULL)"
    );

    $lastDose = (int)array_key_last($stages);
    foreach ($stages as $doseNumber => $offset) {
        $scheduledDate = date('Y-m-d', strtotime($d0Date . ' +' . $offset . ' days'));
        $isFinal = ((int)$doseNumber === $lastDose) ? 1 : 0;
        $remarks = 'Schedule created from nurse-confirmed ' . $profile . ' profile.';
        $dose = (int)$doseNumber;
        $insert->bind_param(
            'iiisissiis',
            $patientId,
            $caseId,
            $visitId,
            $branchId,
            $dose,
            $profile,
            $scheduledDate,
            $nurseId,
            $isFinal,
            $remarks
        );
        $insert->execute();
    }
    $insert->close();
}

function workflowFlash(string $type, string $message): void
{
    $_SESSION['workflow_flash'] = ['type' => $type, 'message' => $message];
}

function workflowTakeFlash(): ?array
{
    $flash = $_SESSION['workflow_flash'] ?? null;
    unset($_SESSION['workflow_flash']);
    return is_array($flash) ? $flash : null;
}

function workflowH(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
