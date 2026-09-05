<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/workflow_helpers.php';

$user = workflowRequireUser($conn, 3);
$userId = (int)$user['user_id'];
$branchId = (string)$user['branch_id'];
$csrf = workflowCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        workflowVerifyCsrf();
        $action = (string)($_POST['action'] ?? '');
        $visitId = (int)($_POST['visit_id'] ?? 0);

        $visitCheck = $conn->prepare(
            "SELECT v.patient_id, v.case_id, v.workflow_status, p.full_name, c.case_number
             FROM patient_visits v
             INNER JOIN patients p ON p.patient_id = v.patient_id
             INNER JOIN animal_bite_cases c ON c.case_id = v.case_id
             WHERE v.visit_id = ? AND v.branch_id = ? LIMIT 1"
        );
        $visitCheck->bind_param('is', $visitId, $branchId);
        $visitCheck->execute();
        $visit = $visitCheck->get_result()->fetch_assoc();
        $visitCheck->close();
        if (!$visit) {
            throw new RuntimeException('Visit was not found in your branch.');
        }

        if ($action === 'save_assessment') {
            if (!in_array($visit['workflow_status'], ['Waiting for Nurse','Under Assessment','Treatment Completed'], true)) {
                throw new RuntimeException('This visit is no longer available for assessment.');
            }
            $history = trim((string)($_POST['exposure_history'] ?? ''));
            $exposureDate = (string)($_POST['date_of_exposure'] ?? '');
            $site = trim((string)($_POST['exposure_site'] ?? ''));
            $animal = trim((string)($_POST['animal_type'] ?? ''));
            $animalStatus = trim((string)($_POST['animal_status'] ?? ''));
            $category = trim((string)($_POST['bite_category'] ?? ''));
            $profile = (string)($_POST['treatment_profile'] ?? '');
            $route = trim((string)($_POST['route'] ?? ''));
            $regimen = trim((string)($_POST['active_regimen'] ?? ''));
            $concerns = trim((string)($_POST['important_concerns'] ?? ''));
            $instructions = trim((string)($_POST['instructions_given'] ?? ''));
            $notes = trim((string)($_POST['chart_notes'] ?? ''));
            $d0Date = (string)($_POST['d0_date'] ?? '');
            $profiles = ['PEP_ID','PEP_IM','PREP','BOOSTER'];
            if ($history === '' || $d0Date === '' || !in_array($profile, $profiles, true)) {
                throw new RuntimeException('History, treatment profile, and D0 date are required.');
            }
            foreach ([$d0Date, $exposureDate] as $date) {
                if ($date !== '' && DateTime::createFromFormat('Y-m-d', $date)?->format('Y-m-d') !== $date) {
                    throw new RuntimeException('Enter valid dates.');
                }
            }

            $patientId = (int)$visit['patient_id'];
            $caseId = (int)$visit['case_id'];
            $conn->begin_transaction();

            $assessment = $conn->prepare(
                "INSERT INTO clinical_assessments
                 (visit_id, patient_id, case_id, branch_id, nurse_id, exposure_history,
                  date_of_exposure, exposure_site, animal_type, animal_status, bite_category,
                  treatment_profile, route, active_regimen, important_concerns,
                  instructions_given, chart_notes, d0_date)
                 VALUES (?, ?, ?, ?, ?, ?, NULLIF(?,''), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE nurse_id=VALUES(nurse_id), exposure_history=VALUES(exposure_history),
                  date_of_exposure=VALUES(date_of_exposure), exposure_site=VALUES(exposure_site),
                  animal_type=VALUES(animal_type), animal_status=VALUES(animal_status),
                  bite_category=VALUES(bite_category), treatment_profile=VALUES(treatment_profile),
                  route=VALUES(route), active_regimen=VALUES(active_regimen),
                  important_concerns=VALUES(important_concerns), instructions_given=VALUES(instructions_given),
                  chart_notes=VALUES(chart_notes), d0_date=VALUES(d0_date), updated_at=NOW()"
            );
            $assessment->bind_param(
                'iiisisssssssssssss',
                $visitId, $patientId, $caseId, $branchId, $userId, $history, $exposureDate,
                $site, $animal, $animalStatus, $category, $profile, $route, $regimen,
                $concerns, $instructions, $notes, $d0Date
            );
            $assessment->execute();
            $assessment->close();

            $caseUpdate = $conn->prepare(
                "UPDATE animal_bite_cases
                 SET animal_type = NULLIF(?,''), bite_location = NULLIF(?,''),
                     bite_category = NULLIF(?,''), animal_status = NULLIF(?,''),
                     date_of_bite = NULLIF(?,''), remarks = NULLIF(?, '')
                 WHERE case_id = ? AND branch_id = ?"
            );
            $caseUpdate->bind_param('ssssssis', $animal, $site, $category, $animalStatus, $exposureDate, $notes, $caseId, $branchId);
            $caseUpdate->execute();
            $caseUpdate->close();

            $visitUpdate = $conn->prepare(
                "UPDATE patient_visits SET workflow_status='Under Assessment', assigned_nurse=?,
                 assessment_started_at=COALESCE(assessment_started_at,NOW()), updated_at=NOW()
                 WHERE visit_id=? AND branch_id=?"
            );
            $visitUpdate->bind_param('iis', $userId, $visitId, $branchId);
            $visitUpdate->execute();
            $visitUpdate->close();

            workflowCreateSchedule($conn, $visitId, $patientId, $caseId, $branchId, $profile, $d0Date, $userId);
            workflowAudit($conn, $userId, $branchId, 'Saved nurse assessment and schedule for visit ' . $visitId, 'Clinical Assessment');
            $conn->commit();
            workflowFlash('success', 'Assessment saved. The schedule was generated from the confirmed D0 date.');
        } elseif ($action === 'send_registry') {
            if (!in_array($visit['workflow_status'], ['Under Assessment','Treatment Completed'], true)) {
                throw new RuntimeException('Save the Nurse assessment before sending the chart for registry.');
            }
            $hasAssessment = $conn->prepare('SELECT assessment_id FROM clinical_assessments WHERE visit_id=? LIMIT 1');
            $hasAssessment->bind_param('i', $visitId);
            $hasAssessment->execute();
            $assessmentRow = $hasAssessment->get_result()->fetch_assoc();
            $hasAssessment->close();
            if (!$assessmentRow) {
                throw new RuntimeException('Assessment is incomplete.');
            }

            $conn->begin_transaction();
            $sign = $conn->prepare('UPDATE clinical_assessments SET chart_signed_at=NOW(), nurse_id=? WHERE visit_id=?');
            $sign->bind_param('ii', $userId, $visitId);
            $sign->execute();
            $sign->close();
            $update = $conn->prepare(
                "UPDATE patient_visits SET workflow_status='For Registry', assigned_nurse=?,
                 treatment_completed_at=COALESCE(treatment_completed_at,NOW()), sent_for_registry_at=NOW()
                 WHERE visit_id=? AND branch_id=?"
            );
            $update->bind_param('iis', $userId, $visitId, $branchId);
            $update->execute();
            $update->close();
            $message = $visit['full_name'] . ' (Case ' . $visit['case_number'] . ') is ready for registry verification.';
            workflowNotifyRole($conn, $branchId, 4, 'Chart Ready for Registry', $message, 'registry');
            workflowAudit($conn, $userId, $branchId, 'Signed chart and sent visit ' . $visitId . ' for registry', 'Clinical Assessment');
            $conn->commit();
            workflowFlash('success', 'Chart signed and sent to Administrative Staff for registry.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        workflowFlash('danger', $e->getMessage());
    }
    header('Location: Nurse_Assessment.php?visit_id=' . max(0, $visitId));
    exit;
}

$queueStmt = $conn->prepare(
    "SELECT v.*, p.full_name, p.contact_number, c.case_number
     FROM patient_visits v INNER JOIN patients p ON p.patient_id=v.patient_id
     INNER JOIN animal_bite_cases c ON c.case_id=v.case_id
     WHERE v.branch_id=? AND v.workflow_status IN ('Waiting for Nurse','Under Assessment','Treatment Completed')
     ORDER BY FIELD(v.workflow_status,'Waiting for Nurse','Under Assessment','Treatment Completed'), v.check_in_at"
);
$queueStmt->bind_param('s', $branchId);
$queueStmt->execute();
$queue = $queueStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$queueStmt->close();

$overdueStmt = $conn->prepare(
    "SELECT p.full_name,c.case_number,MIN(vr.scheduled_date) AS oldest_due,
            DATEDIFF(CURDATE(),MIN(vr.scheduled_date)) AS days_overdue
     FROM vaccination_records vr
     INNER JOIN patients p ON p.patient_id=vr.patient_id
     INNER JOIN animal_bite_cases c ON c.case_id=vr.case_id
     WHERE vr.branch_id=? AND vr.is_archived=0
       AND vr.vaccination_status IN ('Scheduled','Missed')
       AND vr.scheduled_date<CURDATE()
     GROUP BY vr.patient_id,vr.case_id,p.full_name,c.case_number
     HAVING days_overdue>7
     ORDER BY days_overdue DESC"
);
$overdueStmt->bind_param('s', $branchId);
$overdueStmt->execute();
$overdueCases = $overdueStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$overdueStmt->close();

$selectedId = (int)($_GET['visit_id'] ?? 0);
$selected = null;
if ($selectedId) {
    $stmt = $conn->prepare(
        "SELECT v.*, p.full_name, c.case_number, c.animal_type, c.bite_location, c.bite_category,
                c.animal_status, c.date_of_bite, a.*
         FROM patient_visits v INNER JOIN patients p ON p.patient_id=v.patient_id
         INNER JOIN animal_bite_cases c ON c.case_id=v.case_id
         LEFT JOIN clinical_assessments a ON a.visit_id=v.visit_id
         WHERE v.visit_id=? AND v.branch_id=? LIMIT 1"
    );
    $stmt->bind_param('is', $selectedId, $branchId);
    $stmt->execute();
    $selected = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
$flash = workflowTakeFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nurse Assessment - Smart Bite Care</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sidebar.css">
    <style>
        :root {
            --primary: #2B3A8C;
            --primary-dark: #1f2d6e;
            --success: #28a745;
            --danger: #dc3545;
            --text: #1f2a44;
            --muted: #6f7b91;
            --border: #e6eaf2;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f9faff; color: var(--text); font-family: 'Segoe UI', Roboto, system-ui, sans-serif; }
        .main { min-height: 100vh; margin-left: 260px; }
        .topbar { height: 80px; padding: 0 35px; display: flex; align-items: center; justify-content: space-between; background: #fff; border-bottom: 1px solid #e9edf5; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
        .topbar h3 { margin: 0; color: var(--primary); font-size: 28px; font-weight: 700; letter-spacing: -.3px; }
        .topbar h3 small { margin-left: 10px; color: #666; font-size: 16px; font-weight: 400; }
        .profile { display: flex; align-items: center; gap: 6px; color: var(--primary); font-weight: 600; }
        .profile-role { margin-left: 3px; color: #adb5bd; font-size: 12px; font-weight: 400; }
        .content { padding: 35px 35px 40px; }
        .alert { border: 0; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.05); }

        .content-card { overflow: hidden; height: 100%; background: #fff; border: 0; border-radius: 18px; box-shadow: 0 3px 8px rgba(0,0,0,.08); }
        .content-card-header { display: flex; align-items: center; justify-content: space-between; gap: 15px; padding: 19px 22px; border-bottom: 1px solid #edf0f5; }
        .content-card-header h2 { display: flex; align-items: center; gap: 9px; margin: 0; color: var(--primary); font-size: 19px; font-weight: 700; }
        .content-card-header p { margin: 5px 0 0; color: var(--muted); font-size: 13px; }
        .section-icon { width: 35px; height: 35px; display: inline-flex; align-items: center; justify-content: center; color: #fff; background: var(--primary); border-radius: 9px; }
        .content-card-body { padding: 22px; }

        .queue-card { position: sticky; top: 20px; height: auto; max-height: calc(100vh - 120px); }
        .queue-list { max-height: calc(100vh - 245px); overflow-y: auto; }
        .queue-item { padding: 14px 16px; color: #2e3b59; border: 0; border-bottom: 1px solid #edf0f5; }
        .queue-item:last-child { border-bottom: 0; }
        .queue-item:hover { background: #f6f7fd; color: var(--primary); }
        .queue-item.active { color: #fff; background: var(--primary); border-color: var(--primary); }
        .queue-name { display: block; margin-bottom: 3px; font-size: 14px; font-weight: 700; }
        .queue-detail { display: block; margin-bottom: 7px; font-size: 12px; opacity: .8; }
        .status-pill { display: inline-flex; align-items: center; padding: 4px 8px; color: #3b4a6b; background: #edf0f7; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .queue-item.active .status-pill { color: var(--primary); background: #fff; }
        .empty-state { display: flex; min-height: 230px; flex-direction: column; align-items: center; justify-content: center; padding: 35px 20px; color: #8a94a6; text-align: center; }
        .empty-state i { margin-bottom: 10px; color: #b1b8ca; font-size: 42px; }
        .empty-state strong { color: var(--primary); font-size: 16px; }

        .patient-heading { display: flex; align-items: center; justify-content: space-between; gap: 15px; }
        .patient-heading .case-badge { padding: 5px 10px; color: var(--primary); background: #edf1ff; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .form-section { margin-top: 6px; padding-top: 20px; border-top: 1px solid #edf0f5; }
        .form-section:first-of-type { margin-top: 0; padding-top: 0; border-top: 0; }
        .form-section-title { margin: 0 0 15px; color: var(--primary); font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; }
        .form-label { margin-bottom: 6px; color: #48546f; font-size: 13px; font-weight: 650; }
        .required::after { content: ' *'; color: var(--danger); }
        .form-control, .form-select { min-height: 44px; border-color: #d9dfeb; border-radius: 9px; }
        textarea.form-control { min-height: auto; }
        .form-control:focus, .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 .2rem rgba(43,58,140,.12); }
        .btn-primary { background: var(--primary); border-color: var(--primary); font-weight: 650; }
        .btn-primary:hover, .btn-primary:focus { background: var(--primary-dark); border-color: var(--primary-dark); }
        .btn-outline-primary { color: var(--primary); border-color: var(--primary); font-weight: 650; }
        .btn-outline-primary:hover { background: var(--primary); border-color: var(--primary); }
        .form-actions { display: flex; flex-wrap: wrap; gap: 9px; }
        .registry-panel { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin-top: 22px; padding: 17px 18px; background: #f7f9fc; border: 1px solid #e7ebf2; border-radius: 12px; }
        .registry-panel strong { display: block; color: #26345f; font-size: 14px; }
        .registry-panel small { color: var(--muted); }
        .overdue-list { max-height: 145px; overflow-y: auto; }

        @media (max-width: 991px) {
            .main { margin-left: 90px; }
            .topbar { padding: 0 22px; }
            .content { padding: 28px 22px 35px; }
            .topbar h3 small, .profile-role { display: none; }
            .queue-card { position: static; max-height: none; }
            .queue-list { max-height: 370px; }
        }
        @media (max-width: 767px) {
            .topbar { height: 70px; padding: 0 16px; }
            .topbar h3 { font-size: 20px; }
            .content { padding: 20px 14px 30px; }
            .content-card-header, .content-card-body { padding: 17px; }
            .registry-panel { align-items: stretch; flex-direction: column; }
            .registry-panel form, .registry-panel button { width: 100%; }
        }
        @media (max-width: 520px) { .profile span { display: none; } .form-actions .btn { width: 100%; } }
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="logo-area">
        <div class="logo-frame"><img src="logo.png" alt="Smart Bite Care Logo" class="logo"></div>
        <div class="system-name">Smart Bite Care</div>
    </div>
    <nav class="nav-menu" aria-label="Nurse navigation">
        <ul>
            <li><a href="Nurse_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="Nurse_Patients.php"><i class="bi bi-heart-pulse-fill"></i><span>Patients</span></a></li>
            <li><a class="active" href="Nurse_Assessment.php" aria-current="page"><i class="bi bi-clipboard2-pulse-fill"></i><span>Assessment Queue</span></a></li>
            <li><a href="Nurse_Vaccination.php"><i class="bi bi-shield-plus"></i><span>Vaccination</span></a></li>
            <li><a href="Nurse_DailyInventory.php"><i class="bi bi-clipboard-data-fill"></i><span>Daily Inventory</span></a></li>
            <li><a href="Nurse_MedicalSuppliesManagement.php"><i class="bi bi-calendar-check"></i><span>Medical Supplies Management</span></a></li>
            <li><a href="Nurse_Supplyforecasting.php"><i class="bi bi-box-seam"></i><span>Supply Forecasting</span></a></li>
            <li><a href="Nurse_Notification.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
        </ul>
    </nav>
    <div class="logout"><a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></div>
</aside>

<main class="main">
    <div class="topbar">
        <h3>Nurse Assessment <small><?= workflowH((string)($user['branch_name'] ?? $branchId)) ?></small></h3>
        <div class="profile"><i class="bi bi-person-circle"></i><span><?= workflowH((string)$user['username']) ?></span><span class="profile-role">| Nurse</span></div>
    </div>

    <div class="content">
        <?php if ($flash): ?>
            <div class="alert alert-<?= workflowH((string)$flash['type']) ?> alert-dismissible fade show" role="alert">
                <?= workflowH((string)$flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($overdueCases): ?>
            <div class="alert alert-danger">
                <div class="d-flex align-items-start gap-2"><i class="bi bi-exclamation-triangle-fill fs-5"></i><div class="flex-grow-1"><strong>Nurse reassessment required</strong><div class="small mt-1">These cases are more than seven days overdue. The system does not automatically restart treatment. Follow the clinic-approved decision and ask Administrative Staff to check in the patient.</div>
                    <ul class="overdue-list mb-0 mt-2"><?php foreach ($overdueCases as $overdue): ?><li><?= workflowH($overdue['full_name'].' — '.$overdue['case_number'].' ('.$overdue['days_overdue'].' days overdue)') ?></li><?php endforeach; ?></ul>
                </div></div>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <section class="col-xl-4 col-lg-5">
                <div class="content-card queue-card">
                    <div class="content-card-header"><div><h2><span class="section-icon"><i class="bi bi-people-fill"></i></span>Assessment Queue</h2><p><?= count($queue) ?> patient<?= count($queue) === 1 ? '' : 's' ?> awaiting nurse action</p></div></div>
                    <?php if (!$queue): ?>
                        <div class="empty-state"><i class="bi bi-person-check"></i><strong>No patients waiting</strong><span>The queue is currently clear.</span></div>
                    <?php else: ?>
                        <div class="list-group list-group-flush queue-list">
                            <?php foreach ($queue as $row): ?>
                                <a class="list-group-item list-group-item-action queue-item <?= $selectedId === (int)$row['visit_id'] ? 'active' : '' ?>" href="?visit_id=<?= (int)$row['visit_id'] ?>">
                                    <span class="queue-name"><?= workflowH((string)$row['full_name']) ?></span>
                                    <span class="queue-detail"><?= workflowH($row['case_number'].' | '.$row['visit_type']) ?></span>
                                    <span class="status-pill"><?= workflowH((string)$row['workflow_status']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="col-xl-8 col-lg-7">
                <?php if (!$selected): ?>
                    <div class="content-card"><div class="empty-state"><i class="bi bi-clipboard2-pulse"></i><strong>Select a patient</strong><span>Choose a waiting patient from the assessment queue to begin charting.</span></div></div>
                <?php else: ?>
                    <div class="content-card">
                        <div class="content-card-header patient-heading">
                            <div><h2><span class="section-icon"><i class="bi bi-person-vcard-fill"></i></span><?= workflowH((string)$selected['full_name']) ?></h2><p>Clinical assessment and treatment schedule</p></div>
                            <span class="case-badge"><?= workflowH((string)$selected['case_number']) ?></span>
                        </div>
                        <div class="content-card-body">
                            <div class="alert alert-warning small"><i class="bi bi-info-circle-fill me-1"></i>Schedule profiles must be approved by the clinic supervisor before production use. D0 is the actual first-dose date, not automatically the exposure date.</div>

                            <form method="post" class="row g-3">
                                <input type="hidden" name="csrf_token" value="<?= workflowH($csrf) ?>">
                                <input type="hidden" name="action" value="save_assessment">
                                <input type="hidden" name="visit_id" value="<?= $selectedId ?>">

                                <div class="col-12 form-section"><h3 class="form-section-title"><i class="bi bi-journal-medical me-1"></i>Exposure Information</h3></div>
                                <div class="col-12"><label class="form-label required" for="exposure_history">History of Incident/Exposure</label><textarea class="form-control" id="exposure_history" name="exposure_history" rows="3" required><?= workflowH((string)($selected['exposure_history'] ?? '')) ?></textarea></div>
                                <div class="col-md-4"><label class="form-label" for="date_of_exposure">Exposure Date</label><input type="date" class="form-control" id="date_of_exposure" name="date_of_exposure" value="<?= workflowH((string)($selected['date_of_exposure'] ?? $selected['date_of_bite'] ?? '')) ?>"></div>
                                <div class="col-md-4"><label class="form-label" for="exposure_site">Exposure Site</label><input class="form-control" id="exposure_site" name="exposure_site" value="<?= workflowH((string)($selected['exposure_site'] ?? $selected['bite_location'] ?? '')) ?>"></div>
                                <div class="col-md-4"><label class="form-label" for="bite_category">Bite Category</label><select class="form-select" id="bite_category" name="bite_category"><option value="">Select category</option><?php foreach (['I','II','III','Not Applicable'] as $category): ?><option value="<?= workflowH($category) ?>" <?= ($selected['bite_category'] ?? '') === $category ? 'selected' : '' ?>><?= workflowH($category) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-6"><label class="form-label" for="animal_type">Animal/Exposure Source</label><input class="form-control" id="animal_type" name="animal_type" value="<?= workflowH((string)($selected['animal_type'] ?? '')) ?>"></div>
                                <div class="col-md-6"><label class="form-label" for="animal_status">Animal Status</label><input class="form-control" id="animal_status" name="animal_status" value="<?= workflowH((string)($selected['animal_status'] ?? '')) ?>"></div>

                                <div class="col-12 form-section"><h3 class="form-section-title"><i class="bi bi-shield-plus me-1"></i>Treatment Plan</h3></div>
                                <div class="col-md-4"><label class="form-label required" for="treatment_profile">Treatment Profile</label><select class="form-select" id="treatment_profile" name="treatment_profile" required><?php foreach (['PEP_ID'=>'PEP - Intradermal','PEP_IM'=>'PEP - Intramuscular','PREP'=>'PrEP','BOOSTER'=>'Booster'] as $value=>$label): ?><option value="<?= workflowH($value) ?>" <?= ($selected['treatment_profile'] ?? '') === $value ? 'selected' : '' ?>><?= workflowH($label) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-4"><label class="form-label" for="route">Route</label><input class="form-control" id="route" name="route" value="<?= workflowH((string)($selected['route'] ?? '')) ?>" placeholder="ID, IM or N/A"></div>
                                <div class="col-md-4"><label class="form-label required" for="d0_date">Actual D0 Date</label><input type="date" class="form-control" id="d0_date" name="d0_date" value="<?= workflowH((string)($selected['d0_date'] ?? date('Y-m-d'))) ?>" required></div>
                                <div class="col-12"><label class="form-label" for="active_regimen">Active Regimen/Product Plan</label><input class="form-control" id="active_regimen" name="active_regimen" value="<?= workflowH((string)($selected['active_regimen'] ?? '')) ?>"></div>

                                <div class="col-12 form-section"><h3 class="form-section-title"><i class="bi bi-clipboard2-check-fill me-1"></i>Notes and Instructions</h3></div>
                                <div class="col-md-6"><label class="form-label" for="important_concerns">Important Concerns/Escalation</label><textarea class="form-control" id="important_concerns" name="important_concerns" rows="2"><?= workflowH((string)($selected['important_concerns'] ?? '')) ?></textarea></div>
                                <div class="col-md-6"><label class="form-label" for="instructions_given">Instructions Given</label><textarea class="form-control" id="instructions_given" name="instructions_given" rows="2"><?= workflowH((string)($selected['instructions_given'] ?? '')) ?></textarea></div>
                                <div class="col-12"><label class="form-label" for="chart_notes">Chart Notes</label><textarea class="form-control" id="chart_notes" name="chart_notes" rows="2"><?= workflowH((string)($selected['chart_notes'] ?? '')) ?></textarea></div>
                                <div class="col-12 form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-save-fill me-1"></i>Save Assessment and Generate Schedule</button><a class="btn btn-outline-primary" href="Nurse_Vaccination.php"><i class="bi bi-shield-check me-1"></i>Record Administered Products</a></div>
                            </form>

                            <div class="registry-panel">
                                <div><strong>Complete the patient chart</strong><small>Sign the assessment and send it to Administrative Staff for registry verification.</small></div>
                                <form method="post" onsubmit="return confirm('Confirm that charting is complete and signed?')">
                                    <input type="hidden" name="csrf_token" value="<?= workflowH($csrf) ?>">
                                    <input type="hidden" name="action" value="send_registry">
                                    <input type="hidden" name="visit_id" value="<?= $selectedId ?>">
                                    <button class="btn btn-success" type="submit"><i class="bi bi-send-check-fill me-1"></i>Complete and Send for Registry</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
