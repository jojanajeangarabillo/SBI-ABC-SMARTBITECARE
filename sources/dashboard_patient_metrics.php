<?php
declare(strict_types=1);

require_once __DIR__ . '/workflow_helpers.php';

// Read-only dashboard definitions. Dates use db_connect.php's Manila session.
// A visit belongs to a branch only when its patient AND case agree with it.
function dashboardPatientVisitSql(): string
{
    return "SELECT v.* FROM patient_visits v
        INNER JOIN patients p ON p.patient_id = v.patient_id
            AND p.branch_id = v.branch_id AND p.is_archived = 0
        INNER JOIN animal_bite_cases c ON c.case_id = v.case_id
            AND c.patient_id = v.patient_id AND c.branch_id = v.branch_id
            AND c.is_archived = 0
        WHERE v.branch_id = ? AND v.workflow_status <> 'Cancelled'
            AND v.visit_date <= CURDATE()";
}

function dashboardPendingFollowupSql(): string
{
    return "SELECT vr.patient_id, vr.case_id, vr.dose_number,
            vr.scheduled_date, vr.vaccination_status
        FROM vaccination_records vr
        INNER JOIN patients p ON p.patient_id = vr.patient_id
            AND p.branch_id = vr.branch_id AND p.is_archived = 0
        INNER JOIN animal_bite_cases c ON c.case_id = vr.case_id
            AND c.patient_id = vr.patient_id AND c.branch_id = vr.branch_id
            AND c.is_archived = 0 AND c.case_status = 'Ongoing'
        LEFT JOIN inventory_items i ON i.item_id = vr.item_id
        WHERE vr.branch_id = ? AND vr.is_archived = 0
            AND vr.vaccination_status IN ('Scheduled', 'Missed')
            AND vr.scheduled_date IS NOT NULL
            AND COALESCE(NULLIF(TRIM(vr.vaccine_name), ''), i.item_name, '') NOT LIKE '%Default%'";
}

function dashboardPatientMetrics(mysqli $conn, string $branchId): array
{
    if ($branchId === '') {
        throw new InvalidArgumentException('A branch is required for dashboard counts.');
    }
    $visits = dashboardPatientVisitSql();
    $pending = dashboardPendingFollowupSql();
    // One SELECT gives the cards a consistent database snapshot.
    // Group today's visits by patient: several cases/product rows never inflate
    // Patients Waiting or the mutually exclusive New/Returning patient counts.
    $query = "SELECT today.*, cases.*, patients.*, followups.*, registry.*
        FROM (
            SELECT COALESCE(SUM(visit_count), 0) AS today_visits,
                COUNT(*) AS today_patients,
                COALESCE(SUM(has_new_visit), 0) AS new_patients_today,
                COALESCE(SUM(1 - has_new_visit), 0) AS returning_patients_today,
                COALESCE(SUM(is_waiting), 0) AS patient_waiting,
                COALESCE(SUM(completed_count), 0) AS completed_consultations_today,
                COALESCE(SUM(assessment_count), 0) AS under_assessment_today,
                COALESCE(SUM(registry_count), 0) AS for_registry_today
            FROM (
                SELECT v.patient_id, COUNT(*) AS visit_count,
                    MAX(v.visit_type = 'New Patient') AS has_new_visit,
                    MAX(v.workflow_status IN ('Checked In', 'Waiting for Nurse')) AS is_waiting,
                    SUM(v.workflow_status IN ('Treatment Completed', 'For Registry',
                        'Registered', 'Visit Completed')) AS completed_count,
                    SUM(v.workflow_status = 'Under Assessment') AS assessment_count,
                    SUM(v.workflow_status = 'For Registry') AS registry_count
                FROM ($visits) v WHERE v.visit_date = CURDATE()
                GROUP BY v.patient_id
            ) per_patient
        ) today
        CROSS JOIN (
            SELECT COUNT(*) AS total_cases,
                COALESCE(SUM(c.case_status = 'Ongoing'), 0) AS ongoing_cases,
                COALESCE(SUM(c.case_status = 'Completed'), 0) AS completed_cases
            FROM animal_bite_cases c
            INNER JOIN patients p ON p.patient_id = c.patient_id
                AND p.branch_id = c.branch_id AND p.is_archived = 0
            WHERE c.branch_id = ? AND c.is_archived = 0
        ) cases
        CROSS JOIN (
            SELECT COUNT(*) AS total_patients,
                COALESCE(SUM(p.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                    AND p.created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)), 0)
                    AS new_patients_month
            FROM patients p WHERE p.branch_id = ? AND p.is_archived = 0
        ) patients
        CROSS JOIN (
            SELECT COUNT(DISTINCT f.patient_id) AS pending_followup_patients,
                COUNT(DISTINCT CASE WHEN f.scheduled_date = CURDATE()
                    THEN f.patient_id END) AS followup_patients_due_today,
                COUNT(DISTINCT CASE WHEN f.scheduled_date < CURDATE()
                    THEN f.patient_id END) AS overdue_followup_patients
            FROM ($pending) f
        ) followups
        CROSS JOIN (
            SELECT COUNT(*) AS for_registry FROM ($visits) v
            WHERE v.workflow_status = 'For Registry'
        ) registry";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare patient dashboard counts.');
    }
    try {
        $stmt->bind_param('sssss', $branchId, $branchId, $branchId, $branchId, $branchId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to load patient dashboard counts.');
        }
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            throw new RuntimeException('Patient dashboard counts were not returned.');
        }
        $metrics = array_map(static fn($value): int => (int)$value, $row);
        // Missed/overdue always uses the one shared definition (see
        // workflowMissedDoseCondition) so every page shows the same number.
        $missed = workflowMissedDoseCounts($conn, $branchId);
        $metrics['missed_doses'] = $missed['doses'];
        $metrics['overdue_followup_patients'] = $missed['patients'];
        return $metrics;
    } finally {
        $stmt->close();
    }
}

function dashboardFollowupCalendar(mysqli $conn, string $branchId): array
{
    $pending = dashboardPendingFollowupSql();
    $stmt = $conn->prepare("SELECT f.scheduled_date AS schedule_date,
        COUNT(DISTINCT f.patient_id) AS patient_count
        FROM ($pending) f
        WHERE f.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        GROUP BY f.scheduled_date ORDER BY f.scheduled_date");
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the follow-up calendar.');
    }
    try {
        $stmt->bind_param('s', $branchId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to load the follow-up calendar.');
        }
        $dates = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $dates[$row['schedule_date']] = (int)$row['patient_count'];
        }
        return $dates;
    } finally {
        $stmt->close();
    }
}

function dashboardDueFollowupPatients(mysqli $conn, string $branchId): array
{
    $pending = dashboardPendingFollowupSql();
    $stmt = $conn->prepare("SELECT f.patient_id, p.full_name,
        MIN(f.scheduled_date) AS oldest_due,
        DATEDIFF(CURDATE(), MIN(f.scheduled_date)) AS days_overdue
        FROM ($pending) f INNER JOIN patients p ON p.patient_id = f.patient_id
        WHERE f.scheduled_date <= CURDATE()
        GROUP BY f.patient_id, p.full_name
        ORDER BY oldest_due, p.full_name, f.patient_id LIMIT 5");
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare due follow-ups.');
    }
    try {
        $stmt->bind_param('s', $branchId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to load due follow-ups.');
        }
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally {
        $stmt->close();
    }
}
