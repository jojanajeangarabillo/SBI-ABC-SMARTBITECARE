<?php
declare(strict_types=1);

require_once __DIR__ . '/workflow_helpers.php';

/*
 * One display format for a patient and their case on every page and document.
 *
 *  - Name: patients.full_name as entered (extra spaces removed). There is no
 *    separate middle-name column, so no page reorders or abbreviates it.
 *  - Age: whole years from the date of birth as of TODAY (same reference date
 *    everywhere, same as SQL TIMESTAMPDIFF(YEAR, birthday, CURDATE())).
 *  - Case No.: animal_bite_cases.case_number (YY-NNNN). The registry number is
 *    a different value and is labelled "Registry No." where shown.
 *  - Clinical fields (biting animal, animal status, site/date of bite,
 *    category, type of bite, type of wound, regimen, route): the most recent
 *    nurse assessment of the case; the Admin Staff intake is the fallback.
 *  - Dose labels: "Dose 4 - D28" (patient order + day label) from the regimen.
 *  - Dose status: Completed, Scheduled, Missed or Archived/Restarted, with the
 *    same colours everywhere. A past-due dose that was not given is Missed even
 *    if the stored row still says Scheduled (see workflowMissedDoseCondition).
 */

const PATIENT_DOSE_STATUS_STYLES = [
    'Completed' => ['class' => 'success', 'background' => '#d1e7dd', 'color' => '#0f5132'],
    'Scheduled' => ['class' => 'primary', 'background' => '#cfe2ff', 'color' => '#084298'],
    'Missed' => ['class' => 'danger', 'background' => '#f8d7da', 'color' => '#842029'],
    'Archived/Restarted' => ['class' => 'secondary', 'background' => '#e2e3e5', 'color' => '#41464b'],
];

function patientDisplayName(?string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', (string)$name));
    return $name !== '' ? $name : 'N/A';
}

/** Whole years on $asOf (default today); null when the birthday is missing/invalid. */
function patientDisplayAge(?string $birthday, ?string $asOf = null): ?int
{
    if ($birthday === null || trim($birthday) === '' || str_starts_with($birthday, '0000')) {
        return null;
    }
    try {
        $born = new DateTimeImmutable(substr($birthday, 0, 10));
        $on = new DateTimeImmutable($asOf ?? 'today');
    } catch (Throwable $e) {
        return null;
    }
    return $born > $on ? null : $born->diff($on)->y;
}

/** SQL for the same age, as of today. */
function patientDisplayAgeSql(string $p = 'p'): string
{
    return "TIMESTAMPDIFF(YEAR, $p.birthday, CURDATE())";
}

/** Case number shown as "Case No."; the registry number only when no case number exists (old records). */
function patientDisplayCaseNumber(?string $caseNumber, ?string $registryNumber = null): string
{
    $caseNumber = trim((string)$caseNumber);
    if ($caseNumber !== '') return $caseNumber;
    $registryNumber = trim((string)$registryNumber);
    return $registryNumber !== '' ? $registryNumber : 'N/A';
}

function patientDisplayCaseNumberSql(string $c = 'c', string $r = 'r'): string
{
    return "COALESCE(NULLIF(TRIM($c.case_number), ''), $r.registry_number)";
}

/** LEFT JOIN of the most recent nurse assessment of case alias $c as $la. */
function patientLatestAssessmentJoinSql(string $c = 'c', string $la = 'la'): string
{
    return "LEFT JOIN clinical_assessments $la ON $la.assessment_id = (
            SELECT la_pick.assessment_id FROM clinical_assessments la_pick
            WHERE la_pick.case_id = $c.case_id
            ORDER BY la_pick.updated_at DESC, la_pick.assessment_id DESC LIMIT 1)";
}

/**
 * SELECT list of clinical fields: latest nurse assessment first, intake as
 * fallback. Column names match animal_bite_cases so existing pages keep working.
 */
function patientClinicalSelectSql(mysqli $conn, string $c = 'c', string $la = 'la'): string
{
    $optional = static fn(string $column): string => workflowColumnExists($conn, 'clinical_assessments', $column)
        ? "$la.$column" : 'NULL';
    return "COALESCE(NULLIF($la.animal_type, ''), $c.animal_type) AS animal_type,
        COALESCE(NULLIF($la.animal_status, ''), $c.animal_status) AS animal_status,
        COALESCE(NULLIF($la.exposure_site, ''), $c.bite_location) AS bite_location,
        COALESCE($la.date_of_exposure, $c.date_of_bite) AS date_of_bite,
        COALESCE(NULLIF($la.bite_category, ''), $c.bite_category) AS bite_category,
        " . $optional('bite_type') . " AS bite_type,
        " . $optional('wound_type') . " AS wound_type,
        $la.treatment_profile AS treatment_profile,
        $la.route AS regimen_route,
        $la.active_regimen AS assessment_active_regimen";
}

/** Regimen + route text from the latest assessment ("PEP - Intradermal (ID)"), or "Not yet assessed". */
function patientRegimenLabel(?string $profile, ?string $route = null): string
{
    return trim((string)$profile) !== '' ? workflowProfileLabel($profile, $route) : 'Not yet assessed by Nurse';
}

/**
 * Canonical status of one vaccination row. $row needs vaccination_status,
 * scheduled_date, date_administered and optionally is_archived.
 */
function patientDoseStatus(array $row): string
{
    if ((int)($row['is_archived'] ?? 0) === 1) return 'Archived/Restarted';
    $status = trim((string)($row['vaccination_status'] ?? ''));
    $administered = trim((string)($row['date_administered'] ?? ''));
    if ($status === 'Completed' || ($status === '' && $administered !== '' && $administered <= date('Y-m-d'))) {
        return 'Completed';
    }
    $scheduled = trim((string)($row['scheduled_date'] ?? ''));
    if ($status === 'Missed' || ($scheduled !== '' && substr($scheduled, 0, 10) < date('Y-m-d'))) {
        return 'Missed';
    }
    return 'Scheduled';
}

/** The same canonical status as SQL (alias $v = vaccination_records). */
function patientDoseStatusSql(string $v = 'v'): string
{
    return "CASE
        WHEN COALESCE($v.is_archived, 0) = 1 THEN 'Archived/Restarted'
        WHEN $v.vaccination_status = 'Completed'
             OR (COALESCE($v.vaccination_status, '') = '' AND $v.date_administered IS NOT NULL AND $v.date_administered <= CURDATE())
            THEN 'Completed'
        WHEN $v.vaccination_status = 'Missed'
             OR ($v.scheduled_date IS NOT NULL AND $v.scheduled_date < CURDATE())
            THEN 'Missed'
        ELSE 'Scheduled'
    END";
}

function patientDoseStatusStyle(string $status): array
{
    return PATIENT_DOSE_STATUS_STYLES[$status] ?? PATIENT_DOSE_STATUS_STYLES['Scheduled'];
}

/** Badge with the shared colours (inline style so every page looks the same). */
function patientDoseStatusBadge(string $status): string
{
    $style = patientDoseStatusStyle($status);
    return '<span class="badge patient-dose-status status-' . strtolower(str_replace('/', '-', $status)) . '"'
        . ' style="background:' . $style['background'] . ';color:' . $style['color'] . ';font-weight:600">'
        . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span>';
}

/** Patient-facing dose label for one row of a case, e.g. "Dose 4 - D28" (cached per case). */
function patientDoseDisplay(mysqli $conn, int $caseId, int $doseNumber): string
{
    static $maps = [];
    if ($doseNumber <= 0) return 'One-time';
    if (!isset($maps[$caseId])) {
        $profile = workflowCaseProfile($conn, $caseId);
        $stmt = $conn->prepare('SELECT DISTINCT dose_number FROM vaccination_records
            WHERE case_id = ? AND is_archived = 0 AND dose_number >= 1');
        $stmt->bind_param('i', $caseId);
        $stmt->execute();
        $numbers = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'dose_number'));
        $stmt->close();
        $maps[$caseId] = workflowDoseSequenceMap($profile['profile'], $numbers);
    }
    return workflowDoseDisplay($doseNumber, $maps[$caseId]);
}

/**
 * Active-cycle doses of one case in display order, each with dose_display
 * ("Dose 2 - D3"), status (canonical) and the stored fields. One row per dose
 * stage: a completed product row wins over its schedule placeholder.
 */
function patientCaseDoses(mysqli $conn, int $caseId, string $branchId): array
{
    $stmt = $conn->prepare(
        "SELECT vr.vaccination_id, vr.dose_number, vr.scheduled_date, vr.date_administered,
                vr.vaccination_status, vr.is_archived,
                COALESCE(NULLIF(vr.vaccine_name, ''), i.item_name) AS vaccine_name
         FROM vaccination_records vr
         LEFT JOIN inventory_items i ON i.item_id = vr.item_id
         WHERE vr.case_id = ? AND vr.branch_id = ? AND vr.is_archived = 0 AND vr.dose_number >= 1
         ORDER BY vr.dose_number, (vr.vaccination_status = 'Completed') DESC, vr.vaccination_id"
    );
    $stmt->bind_param('is', $caseId, $branchId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $stages = [];
    foreach ($rows as $row) {
        $dose = (int)$row['dose_number'];
        if (!isset($stages[$dose])) $stages[$dose] = $row;
    }
    $profile = workflowCaseProfile($conn, $caseId);
    $stages = workflowAnnotateCaseDoses(array_values($stages), $profile['profile']);
    foreach ($stages as &$stage) {
        $stage['status'] = patientDoseStatus($stage);
    }
    unset($stage);
    return $stages;
}

/**
 * <script> that defines window.patientStatusLabel(row-like) and
 * window.patientStatusBadge(label) with the same labels and colours, for pages
 * that build tables in JavaScript.
 */
function patientDoseStatusScript(): string
{
    return '<script>'
        . 'window.PATIENT_DOSE_STATUS_STYLES=' . json_encode(PATIENT_DOSE_STATUS_STYLES, JSON_HEX_TAG) . ';'
        . 'window.patientStatusLabel=function(status,scheduled,administered,archived){'
        . 'var t=new Date();var today=t.getFullYear()+"-"+String(t.getMonth()+1).padStart(2,"0")+"-"+String(t.getDate()).padStart(2,"0");'
        . 'status=String(status||"").trim();scheduled=String(scheduled||"").slice(0,10);administered=String(administered||"").slice(0,10);'
        . 'if(archived==1||status==="Archived/Restarted")return "Archived/Restarted";'
        . 'if(status==="Completed"||status==="Administered"||(status===""&&administered&&administered<=today))return "Completed";'
        . 'if(status==="Missed"||(scheduled&&scheduled<today))return "Missed";return "Scheduled";};'
        . 'window.patientStatusBadge=function(label){var s=window.PATIENT_DOSE_STATUS_STYLES[label]||window.PATIENT_DOSE_STATUS_STYLES.Scheduled;'
        . 'var e=String(label).replace(/[&<>"]/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c];});'
        . 'return \'<span class="badge patient-dose-status" style="background:\'+s.background+\';color:\'+s.color+\';font-weight:600">\'+e+\'</span>\';};'
        . '</script>';
}

/** Clinical fields of one case (latest nurse assessment, intake fallback), cached per request. */
function patientCaseClinical(mysqli $conn, int $caseId): array
{
    static $cache = [];
    if (!isset($cache[$caseId])) {
        $stmt = $conn->prepare('SELECT ' . patientClinicalSelectSql($conn, 'c', 'la')
            . ' FROM animal_bite_cases c ' . patientLatestAssessmentJoinSql('c', 'la') . ' WHERE c.case_id = ?');
        $stmt->bind_param('i', $caseId);
        $stmt->execute();
        $cache[$caseId] = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
    }
    return $cache[$caseId];
}

/**
 * Normalize one schedule/vaccination row for display: clinical fields from
 * patientCaseClinical(), dose_label "Dose N - Dx", display_status canonical,
 * case_no = case number. Keys that are absent in $row are not added except
 * dose_label/display_status.
 */
function patientNormalizeScheduleRow(mysqli $conn, array $row): array
{
    $caseId = (int)($row['case_id'] ?? 0);
    if ($caseId > 0) {
        foreach (patientCaseClinical($conn, $caseId) as $key => $value) {
            if (array_key_exists($key, $row) && $value !== null && $value !== '') {
                $row[$key] = $value;
            }
        }
        if (array_key_exists('dose_number', $row)) {
            $row['dose_label'] = patientDoseDisplay($conn, $caseId, (int)$row['dose_number']);
        }
    }
    if (array_key_exists('case_number', $row) || array_key_exists('case_no', $row)) {
        $row['case_no'] = patientDisplayCaseNumber($row['case_number'] ?? null, $row['registry_no'] ?? ($row['case_no'] ?? null));
    }
    if (array_key_exists('vaccination_status', $row)) {
        $row['display_status'] = patientDoseStatus($row);
    }
    return $row;
}

/**
 * Check-in state of every active case of a branch, from the same rules as the
 * dashboards and the missed-dose count:
 *  - a dose stage is done when the active cycle has a Completed row for that
 *    case and dose (doses given at another clinic are stored as Completed);
 *  - a case is "completed" when case_status is Completed, or its active cycle
 *    has doses and every dose stage is done; otherwise it is "active" (doses
 *    remain, or it has not been assessed/scheduled yet).
 * Each row has patient_id, full_name, case_id, case_number, case_status,
 * total_doses, remaining_doses, next_dose (dose_number), next_date, state,
 * visit_today (today's non-cancelled visit status or null).
 */
function patientCheckInCases(mysqli $conn, string $branchId): array
{
    $stmt = $conn->prepare(
        "SELECT p.patient_id, p.full_name, c.case_id, c.case_number, c.case_status, c.created_at,
                COALESCE(d.total_doses, 0) AS total_doses,
                COALESCE(d.remaining_doses, 0) AS remaining_doses,
                d.next_dose, d.next_date,
                (SELECT v.workflow_status FROM patient_visits v
                  WHERE v.case_id = c.case_id AND v.visit_date = CURDATE() AND v.workflow_status <> 'Cancelled'
                  ORDER BY v.visit_id DESC LIMIT 1) AS visit_today
         FROM patients p
         INNER JOIN animal_bite_cases c ON c.patient_id = p.patient_id AND c.branch_id = p.branch_id
         LEFT JOIN (
             SELECT stage.case_id,
                    COUNT(*) AS total_doses,
                    SUM(stage.done = 0) AS remaining_doses,
                    MIN(CASE WHEN stage.done = 0 THEN stage.dose_number END) AS next_dose,
                    MIN(CASE WHEN stage.done = 0 THEN stage.scheduled_date END) AS next_date
             FROM (
                 SELECT vr.case_id, vr.dose_number,
                        MAX(vr.vaccination_status = 'Completed') AS done,
                        MIN(CASE WHEN vr.vaccination_status <> 'Completed' THEN vr.scheduled_date END) AS scheduled_date
                 FROM vaccination_records vr
                 WHERE vr.branch_id = ? AND vr.is_archived = 0 AND vr.dose_number >= 1
                 GROUP BY vr.case_id, vr.dose_number
             ) stage
             GROUP BY stage.case_id
         ) d ON d.case_id = c.case_id
         WHERE p.branch_id = ? AND p.is_archived = 0 AND c.is_archived = 0
         ORDER BY p.full_name, c.created_at DESC"
    );
    $stmt->bind_param('ss', $branchId, $branchId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        $total = (int)$row['total_doses'];
        $remaining = (int)$row['remaining_doses'];
        $row['state'] = ($row['case_status'] === 'Completed' || ($total > 0 && $remaining === 0)) ? 'completed' : 'active';
    }
    unset($row);
    return $rows;
}

/** Visit statuses that end a visit: it leaves the active check-in queue. */
function patientVisitDoneStatuses(): array
{
    return ['Treatment Completed', 'Registered', 'Visit Completed', 'Cancelled'];
}
