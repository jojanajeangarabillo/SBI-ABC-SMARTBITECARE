<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/workflow_helpers.php';
require_once 'sources/registry_lock_helpers.php';

/*
 * REQUEST CORRECTION of a verified (locked) registry record.
 * Nurse and Admin Staff of the record's branch. The user gives a reason
 * and the new values; the change is saved with the old and new values
 * (registry_corrections and the audit log) and the record goes back
 * For Re-verification by Admin Staff (AdminStaff_Registry.php).
 */
$roleId = (int)($_SESSION['role_id'] ?? 0);
if (empty($_SESSION['user_id']) || !in_array($roleId, [3, 4], true)) {
    header('Location: login.php');
    exit;
}
$stmt = $conn->prepare("SELECT u.user_id, u.username, u.branch_id, u.role_id, b.branch_name FROM users u
    LEFT JOIN branches b ON b.branch_id = u.branch_id WHERE u.user_id = ? AND u.status = 'Active' LIMIT 1");
$sessionUserId = (int)$_SESSION['user_id'];
$stmt->bind_param('i', $sessionUserId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user || (int)$user['role_id'] !== $roleId || empty($user['branch_id'])) {
    http_response_code(403);
    exit('Active Nurse or Admin Staff access is required.');
}
$userId = (int)$user['user_id'];
$branchId = (string)$user['branch_id'];
$backUrl = $roleId === 4 ? 'AdminStaff_PatientRecord.php' : 'Nurse_Patients.php';
$csrf = workflowCsrfToken();
$caseId = (int)($_GET['case_id'] ?? $_POST['case_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        workflowVerifyCsrf();
        $posted = is_array($_POST['field'] ?? null) ? $_POST['field'] : [];
        $values = [];
        foreach (array_keys(REGISTRY_LOCK_FIELDS) as $key) {
            $formKey = str_replace('.', '__', $key);
            if ($key === 'registry.tt') {
                $values[$key] = isset($posted[$formKey]) ? '1' : '0';
            } elseif (array_key_exists($formKey, $posted)) {
                $values[$key] = is_string($posted[$formKey]) ? $posted[$formKey] : '';
            }
        }
        workflowFlash('success', registryCorrectionApply($conn, $userId, $branchId, $caseId, $values, (string)($_POST['reason'] ?? '')));
    } catch (Throwable $e) {
        workflowFlash('danger', $e->getMessage());
    }
    header('Location: Registry_Correction.php?case_id=' . $caseId);
    exit;
}

$flash = workflowTakeFlash();
$lock = registryLockReady($conn) ? registryLockForCase($conn, $caseId) : null;
$snapshot = null;
if ($lock && (string)$lock['branch_id'] === $branchId) {
    $snapshot = registryLockSnapshot($conn, $caseId);
} else {
    $lock = null;
}
$history = $lock ? registryCorrectionsForCase($conn, $caseId) : [];
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$groups = ['patient' => 'Patient', 'case' => 'Bite case', 'registry' => 'Registry and treatment'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request Registry Correction - SmartBiteCare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .topbar { background: #2B3A8C; color: #fff; padding: 16px 24px; display: flex; align-items: center; gap: 16px; }
        .topbar a { color: #fff; text-decoration: none; }
        .card-box { background: #fff; border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,.08); padding: 24px; margin-bottom: 20px; }
        .section-title { color: #2B3A8C; font-weight: 700; font-size: 17px; margin-bottom: 12px; }
        .current-value { font-size: 12px; color: #6c757d; }
        .changed { background: #fff8e1; }
    </style>
</head>
<body>
<div class="topbar">
    <a href="<?= $h($backUrl) ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
    <h1 class="h5 mb-0">Request Registry Correction</h1>
    <span class="ms-auto small"><?= $h($user['username']) ?> · <?= $h($user['branch_name'] ?? $branchId) ?></span>
</div>
<div class="container py-4" style="max-width: 980px;">
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>"><?= $h($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (!$lock || !$snapshot): ?>
        <div class="card-box">
            <p class="mb-0">This case has no verified registry record in your branch, so it is not locked. Edit it on the
                <a href="<?= $h($backUrl) ?>">patient page</a> as usual.</p>
        </div>
    <?php else: ?>
        <?php $row = $snapshot['row']; ?>
        <div class="card-box">
            <div class="d-flex flex-wrap gap-3 align-items-center">
                <div>
                    <div class="fw-bold fs-5"><?= $h($row['full_name']) ?></div>
                    <div class="text-muted">Case <?= $h($row['case_number']) ?> · Registry <?= $h($row['registry_number'] ?? '') ?></div>
                </div>
                <span class="badge <?= $lock['registry_status'] === 'Registered' ? 'text-bg-success' : 'text-bg-warning' ?> ms-auto fs-6">
                    <?= $h($lock['registry_status'] === 'Registered' ? 'Verified — locked' : 'For Re-verification') ?>
                </span>
            </div>
            <p class="mt-3 mb-0 small text-muted">
                This record was <?= $h(registryLockLabel($lock)) ?>. Change only the fields that are wrong and give the reason.
                The old and new values are saved in the audit log, and the record goes back to Admin Staff for re-verification.
                Dose dates are recorded by the Nurse on the Vaccination page and are not changed here.
            </p>
        </div>

        <form method="POST" class="card-box" id="correctionForm">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <input type="hidden" name="case_id" value="<?= (int)$caseId ?>">
            <?php foreach ($groups as $group => $groupLabel): ?>
                <div class="section-title mt-2"><?= $h($groupLabel) ?></div>
                <div class="row g-3 mb-3">
                    <?php foreach (REGISTRY_LOCK_FIELDS as $key => [$label, , ]): ?>
                        <?php if (!str_starts_with($key, $group . '.')) continue; ?>
                        <?php $formKey = str_replace('.', '__', $key); $current = $snapshot['values'][$key] ?? ''; ?>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="f_<?= $h($formKey) ?>"><?= $h($label) ?></label>
                            <?php if (in_array($key, ['patient.birthday', 'case.admission_date', 'case.date_of_bite'], true)): ?>
                                <input type="date" class="form-control correction-input" id="f_<?= $h($formKey) ?>" name="field[<?= $h($formKey) ?>]" value="<?= $h($current) ?>" data-original="<?= $h($current) ?>" max="<?= $h(date('Y-m-d')) ?>">
                            <?php elseif ($key === 'patient.gender'): ?>
                                <select class="form-select correction-input" id="f_<?= $h($formKey) ?>" name="field[<?= $h($formKey) ?>]" data-original="<?= $h($current) ?>">
                                    <?php foreach (['' => '—', 'Male' => 'Male', 'Female' => 'Female'] as $v => $t): ?>
                                        <option value="<?= $h($v) ?>" <?= $current === $v ? 'selected' : '' ?>><?= $h($t) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($key === 'case.bite_category'): ?>
                                <select class="form-select correction-input" id="f_<?= $h($formKey) ?>" name="field[<?= $h($formKey) ?>]" data-original="<?= $h($current) ?>">
                                    <?php $cats = ['' => '—', 'I' => 'Category I', 'II' => 'Category II', 'III' => 'Category III'];
                                    if ($current !== '' && !isset($cats[$current])) $cats = [$current => $current] + $cats; ?>
                                    <?php foreach ($cats as $v => $t): ?>
                                        <option value="<?= $h($v) ?>" <?= $current === (string)$v ? 'selected' : '' ?>><?= $h($t) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($key === 'registry.tt'): ?>
                                <div class="form-check mt-2">
                                    <input class="form-check-input correction-input" type="checkbox" id="f_<?= $h($formKey) ?>" name="field[<?= $h($formKey) ?>]" value="1" <?= $current === '1' ? 'checked' : '' ?> data-original="<?= $current === '1' ? 'on' : '' ?>">
                                    <label class="form-check-label" for="f_<?= $h($formKey) ?>">Tetanus toxoid given</label>
                                </div>
                            <?php elseif (in_array($key, ['registry.erig', 'registry.ats'], true)): ?>
                                <input type="number" min="0" step="0.01" class="form-control correction-input" id="f_<?= $h($formKey) ?>" name="field[<?= $h($formKey) ?>]" value="<?= $h($current) ?>" data-original="<?= $h($current) ?>">
                            <?php elseif ($key === 'registry.remarks' || $key === 'patient.address'): ?>
                                <textarea class="form-control correction-input" rows="2" maxlength="1000" id="f_<?= $h($formKey) ?>" name="field[<?= $h($formKey) ?>]" data-original="<?= $h($current) ?>"><?= $h($current) ?></textarea>
                            <?php else: ?>
                                <input type="text" class="form-control correction-input" maxlength="255" id="f_<?= $h($formKey) ?>" name="field[<?= $h($formKey) ?>]" value="<?= $h($current) ?>" data-original="<?= $h($current) ?>">
                            <?php endif; ?>
                            <div class="current-value">Verified value: <?= $h($current === '' ? '—' : ($key === 'registry.tt' ? ($current === '1' ? 'Yes' : 'No') : $current)) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <label class="form-label fw-semibold" for="reason">Reason for the correction <span class="text-danger">*</span></label>
            <textarea class="form-control mb-3" id="reason" name="reason" rows="3" minlength="5" maxlength="1000" required placeholder="Example: The date of bite on the intake form was 10/12, not 10/21."></textarea>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Save correction and send for re-verification</button>
                <a class="btn btn-light border" href="<?= $h($backUrl) ?>">Cancel</a>
            </div>
        </form>

        <div class="card-box">
            <div class="section-title">Correction history</div>
            <?php if (!$history): ?>
                <p class="text-muted mb-0">No corrections yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>#</th><th>Requested</th><th>Reason</th><th>Changes (old → new)</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($history as $c): ?>
                            <tr>
                                <td><?= (int)$c['correction_id'] ?></td>
                                <td><?= $h($c['requester_name'] ?? '') ?><div class="small text-muted"><?= $h(date('M d, Y h:i A', strtotime($c['requested_at']))) ?></div></td>
                                <td><?= $h($c['reason']) ?></td>
                                <td class="small"><?php foreach ($c['changes_list'] as $ch): ?><div><strong><?= $h($ch['label']) ?>:</strong> <?= $h($ch['old'] === '' ? '—' : $ch['old']) ?> → <?= $h($ch['new'] === '' ? '—' : $ch['new']) ?></div><?php endforeach; ?></td>
                                <td><?= $h($c['status']) ?><?php if ($c['verified_at']): ?><div class="small text-muted"><?= $h(($c['verifier_name'] ?? '') . ', ' . date('M d, Y', strtotime($c['verified_at']))) ?></div><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<script>
    document.querySelectorAll('.correction-input').forEach(function (input) {
        function mark() {
            var value = input.type === 'checkbox' ? (input.checked ? 'on' : '') : input.value;
            input.classList.toggle('changed', value !== (input.dataset.original || ''));
        }
        input.addEventListener('input', mark);
        input.addEventListener('change', mark);
    });
    var form = document.getElementById('correctionForm');
    if (form) form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (form.dataset.sent === '1') return;
        if (!document.querySelector('.correction-input.changed')) {
            SbcDialog.alert('Change at least one field before requesting a correction.');
            return;
        }
        SbcDialog.confirm({ title: 'Request correction', confirmText: 'Save correction',
            message: 'Save this correction? The record goes back to Admin Staff for re-verification.' }).then(function (ok) {
            if (!ok || form.dataset.sent === '1') return;
            form.dataset.sent = '1';
            form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; });
            form.submit();
        });
    });
</script>
<?php require_once __DIR__ . '/sources/confirm_modal.php'; echo sbcConfirmModalScript(); ?>
</body>
</html>
