<?php
session_start();
require_once 'sources/db_connect.php';

// Check if user is logged in and is a nurse
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || (int)$_SESSION['role_id'] !== 3) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
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
$stmt->close();

if (!$branch_id) {
    $branch_name = 'No Branch Assigned';
}

function dashboardDoseLabel($dose_number) {
    $doseMap = [
        1 => 'D0',
        2 => 'D3',
        3 => 'D7',
        4 => 'D14',
        5 => 'D21',
        6 => 'D28/30'
    ];
    return $doseMap[(int)$dose_number] ?? ('D' . (int)$dose_number);
}

// =============================================
// FETCH ALL STATISTICS FOR NURSE DASHBOARD
// =============================================
$stats = [];

// 1. PATIENT WAITING
// Distinct non-archived patients who currently have an ongoing, non-archived case.
$waitingQuery = "SELECT COUNT(DISTINCT p.patient_id) AS waiting
                 FROM patients p
                 INNER JOIN animal_bite_cases abc ON p.patient_id = abc.patient_id
                 WHERE abc.branch_id = ?
                   AND p.branch_id = ?
                   AND p.is_archived = 0
                   AND abc.is_archived = 0
                   AND abc.case_status = 'Ongoing'";
$stmt = $conn->prepare($waitingQuery);
$stmt->bind_param("ss", $branch_id, $branch_id);
$stmt->execute();
$stats['patient_waiting'] = (int)($stmt->get_result()->fetch_assoc()['waiting'] ?? 0);
$stmt->close();

// 2. ONGOING CASES
$ongoingQuery = "SELECT COUNT(*) AS ongoing
                 FROM animal_bite_cases
                 WHERE branch_id = ?
                   AND is_archived = 0
                   AND case_status = 'Ongoing'";
$stmt = $conn->prepare($ongoingQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$stats['ongoing_cases'] = (int)($stmt->get_result()->fetch_assoc()['ongoing'] ?? 0);
$stmt->close();

// 3. COMPLETED CASES
$completedQuery = "SELECT COUNT(*) AS completed
                   FROM animal_bite_cases
                   WHERE branch_id = ?
                     AND is_archived = 0
                     AND case_status = 'Completed'";
$stmt = $conn->prepare($completedQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$stats['completed_cases'] = (int)($stmt->get_result()->fetch_assoc()['completed'] ?? 0);
$stmt->close();

// 4. TOTAL CASES
$totalCasesQuery = "SELECT COUNT(*) AS total
                    FROM animal_bite_cases
                    WHERE branch_id = ?
                      AND is_archived = 0";
$stmt = $conn->prepare($totalCasesQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$stats['total_cases'] = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

// 5. TOTAL PATIENTS
$totalPatientsQuery = "SELECT COUNT(*) AS total
                       FROM patients
                       WHERE branch_id = ?
                         AND is_archived = 0";
$stmt = $conn->prepare($totalPatientsQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$stats['total_patients'] = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

// Vaccination dashboard counts are DOSE-STAGE based, not product-row based.
// Example: Rabies Vaccine + ERIG + ATS under D0 count as ONE vaccination stage.

// 6. VACCINATIONS TODAY
$todayQuery = "SELECT COUNT(*) AS today_vaccinations
               FROM (
                   SELECT vr.patient_id, vr.case_id, vr.dose_number
                   FROM vaccination_records vr
                   LEFT JOIN inventory_items ii ON vr.item_id = ii.item_id
                   WHERE vr.branch_id = ?
                     AND vr.is_archived = 0
                     AND vr.vaccination_status = 'Completed'
                     AND vr.date_administered IS NOT NULL
                     AND DATE(vr.date_administered) = CURDATE()
                     AND COALESCE(NULLIF(vr.vaccine_name, ''), ii.item_name, '') NOT LIKE '%Default%'
                   GROUP BY vr.patient_id, vr.case_id, vr.dose_number
               ) AS completed_today";
$stmt = $conn->prepare($todayQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$stats['today_vaccinations'] = (int)($stmt->get_result()->fetch_assoc()['today_vaccinations'] ?? 0);
$stmt->close();

// 7. TOTAL VACCINATION STAGES (All Time)
$totalVaccQuery = "SELECT COUNT(*) AS total
                   FROM (
                       SELECT vr.patient_id, vr.case_id, vr.dose_number
                       FROM vaccination_records vr
                       LEFT JOIN inventory_items ii ON vr.item_id = ii.item_id
                       WHERE vr.branch_id = ?
                         AND vr.is_archived = 0
                         AND vr.vaccination_status = 'Completed'
                         AND vr.date_administered IS NOT NULL
                         AND DATE(vr.date_administered) <= CURDATE()
                         AND COALESCE(NULLIF(vr.vaccine_name, ''), ii.item_name, '') NOT LIKE '%Default%'
                       GROUP BY vr.patient_id, vr.case_id, vr.dose_number
                   ) AS completed_stages";
$stmt = $conn->prepare($totalVaccQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$stats['total_vaccinations'] = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

// 8. UPCOMING SCHEDULED VACCINATIONS (Next 7 days)
$upcomingQuery = "SELECT COUNT(*) AS upcoming
                  FROM (
                      SELECT vr.patient_id, vr.case_id, vr.dose_number
                      FROM vaccination_records vr
                      LEFT JOIN inventory_items ii ON vr.item_id = ii.item_id
                      WHERE vr.branch_id = ?
                        AND vr.is_archived = 0
                        AND vr.vaccination_status = 'Scheduled'
                        AND vr.scheduled_date IS NOT NULL
                        AND DATE(vr.scheduled_date) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                        AND COALESCE(NULLIF(vr.vaccine_name, ''), ii.item_name, '') NOT LIKE '%Default%'
                      GROUP BY vr.patient_id, vr.case_id, vr.dose_number
                  ) AS upcoming_stages";
$stmt = $conn->prepare($upcomingQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$stats['upcoming_vaccinations'] = (int)($stmt->get_result()->fetch_assoc()['upcoming'] ?? 0);
$stmt->close();

// 9. MISSED VACCINATIONS
$missedQuery = "SELECT COUNT(*) AS missed
                FROM (
                    SELECT vr.patient_id, vr.case_id, vr.dose_number
                    FROM vaccination_records vr
                    LEFT JOIN inventory_items ii ON vr.item_id = ii.item_id
                    WHERE vr.branch_id = ?
                      AND vr.is_archived = 0
                      AND vr.vaccination_status = 'Missed'
                      AND COALESCE(NULLIF(vr.vaccine_name, ''), ii.item_name, '') NOT LIKE '%Default%'
                    GROUP BY vr.patient_id, vr.case_id, vr.dose_number
                ) AS missed_stages";
$stmt = $conn->prepare($missedQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$stats['missed_vaccinations'] = (int)($stmt->get_result()->fetch_assoc()['missed'] ?? 0);
$stmt->close();

// 10. ANIMAL BITE CATEGORY STATISTICS
$categoryQuery = "SELECT bite_category, COUNT(*) AS count
                  FROM animal_bite_cases
                  WHERE branch_id = ?
                    AND is_archived = 0
                  GROUP BY bite_category";
$stmt = $conn->prepare($categoryQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$categoryResult = $stmt->get_result();
$biteCategories = [];
while ($row = $categoryResult->fetch_assoc()) {
    $biteCategories[] = $row;
}
$stmt->close();

// 11. ANIMAL TYPE STATISTICS
$animalTypeQuery = "SELECT animal_type, COUNT(*) AS count
                    FROM animal_bite_cases
                    WHERE branch_id = ?
                      AND is_archived = 0
                      AND animal_type IS NOT NULL
                    GROUP BY animal_type
                    ORDER BY count DESC
                    LIMIT 5";
$stmt = $conn->prepare($animalTypeQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$animalTypeResult = $stmt->get_result();
$animalTypes = [];
while ($row = $animalTypeResult->fetch_assoc()) {
    $animalTypes[] = $row;
}
$stmt->close();

// 12. PHILHEALTH COVERAGE
$philhealthQuery = "SELECT pr.has_philhealth, COUNT(*) AS count
                    FROM philhealth_records pr
                    INNER JOIN animal_bite_cases abc ON pr.case_id = abc.case_id
                    WHERE abc.branch_id = ?
                      AND abc.is_archived = 0
                    GROUP BY pr.has_philhealth";
$stmt = $conn->prepare($philhealthQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$philhealthResult = $stmt->get_result();
$philhealthStats = [];
while ($row = $philhealthResult->fetch_assoc()) {
    $philhealthStats[$row['has_philhealth']] = $row['count'];
}
$stmt->close();

// 13. LOW STOCK MEDICAL SUPPLIES
// Compare minimum stock against the TOTAL quantity across all batches in this branch.
$lowStockQuery = "SELECT
                      ii.item_id,
                      ii.item_name,
                      COALESCE(SUM(is_.quantity_available), 0) AS quantity_available,
                      ii.minimum_stock,
                      u.unit_name
                  FROM inventory_items ii
                  INNER JOIN inventory_categories c ON ii.category_id = c.category_id
                  INNER JOIN units u ON ii.unit_id = u.unit_id
                  LEFT JOIN inventory_stocks is_
                    ON is_.item_id = ii.item_id
                   AND is_.branch_id = ?
                  WHERE c.category_name = 'Medical Supplies'
                  GROUP BY ii.item_id, ii.item_name, ii.minimum_stock, u.unit_name
                  HAVING COALESCE(SUM(is_.quantity_available), 0) <= ii.minimum_stock
                  ORDER BY
                      CASE
                          WHEN ii.minimum_stock > 0
                          THEN COALESCE(SUM(is_.quantity_available), 0) / ii.minimum_stock
                          ELSE 999999
                      END ASC,
                      ii.item_name ASC
                  LIMIT 5";
$stmt = $conn->prepare($lowStockQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$lowStockResult = $stmt->get_result();
$lowStockItems = [];
while ($row = $lowStockResult->fetch_assoc()) {
    $lowStockItems[] = $row;
}
$stmt->close();

// 14. TODAY'S SCHEDULE
// Group all products that belong to the same patient/case/dose stage.
$scheduleQuery = "SELECT
                      vr.patient_id,
                      vr.case_id,
                      vr.dose_number,
                      MIN(vr.scheduled_date) AS scheduled_date,
                      MAX(vr.is_final_dose) AS is_final_dose,
                      p.full_name,
                      p.contact_number,
                      GROUP_CONCAT(
                          DISTINCT COALESCE(NULLIF(vr.vaccine_name, ''), ii.item_name, 'Unknown Vaccine')
                          ORDER BY COALESCE(NULLIF(vr.vaccine_name, ''), ii.item_name, 'Unknown Vaccine')
                          SEPARATOR ', '
                      ) AS vaccine_names
                  FROM vaccination_records vr
                  INNER JOIN patients p ON vr.patient_id = p.patient_id
                  LEFT JOIN inventory_items ii ON vr.item_id = ii.item_id
                  WHERE vr.branch_id = ?
                    AND p.branch_id = ?
                    AND vr.is_archived = 0
                    AND p.is_archived = 0
                    AND vr.vaccination_status = 'Scheduled'
                    AND vr.scheduled_date IS NOT NULL
                    AND DATE(vr.scheduled_date) = CURDATE()
                    AND COALESCE(NULLIF(vr.vaccine_name, ''), ii.item_name, '') NOT LIKE '%Default%'
                  GROUP BY
                      vr.patient_id,
                      vr.case_id,
                      vr.dose_number,
                      p.full_name,
                      p.contact_number
                  ORDER BY scheduled_date ASC, p.full_name ASC, vr.dose_number ASC
                  LIMIT 10";
$stmt = $conn->prepare($scheduleQuery);
$stmt->bind_param("ss", $branch_id, $branch_id);
$stmt->execute();
$scheduleResult = $stmt->get_result();
$schedules = [];
while ($row = $scheduleResult->fetch_assoc()) {
    $schedules[] = $row;
}
$stmt->close();

// 15. FOLLOW-UP DUE
$followupQuery = "SELECT abc.case_id, p.full_name, abc.date_of_bite,
                         DATEDIFF(CURDATE(), abc.date_of_bite) AS days_since_bite,
                         abc.remarks
                  FROM animal_bite_cases abc
                  INNER JOIN patients p ON abc.patient_id = p.patient_id
                  WHERE abc.branch_id = ?
                    AND p.branch_id = ?
                    AND abc.is_archived = 0
                    AND p.is_archived = 0
                    AND abc.case_status = 'Ongoing'
                    AND DATEDIFF(CURDATE(), abc.date_of_bite) >= 7
                  ORDER BY abc.date_of_bite ASC
                  LIMIT 5";
$stmt = $conn->prepare($followupQuery);
$stmt->bind_param("ss", $branch_id, $branch_id);
$stmt->execute();
$followupResult = $stmt->get_result();
$followups = [];
while ($row = $followupResult->fetch_assoc()) {
    $followups[] = $row;
}
$stmt->close();

// 16. WEEKLY VACCINATION TREND (Today + previous 6 days)
// One point = one completed dose stage, regardless of how many products were given.
$weeklyTrendQuery = "SELECT x.date, COUNT(*) AS count
                     FROM (
                         SELECT
                             DATE(vr.date_administered) AS date,
                             vr.patient_id,
                             vr.case_id,
                             vr.dose_number
                         FROM vaccination_records vr
                         LEFT JOIN inventory_items ii ON vr.item_id = ii.item_id
                         WHERE vr.branch_id = ?
                           AND vr.is_archived = 0
                           AND vr.vaccination_status = 'Completed'
                           AND vr.date_administered IS NOT NULL
                           AND DATE(vr.date_administered) BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE()
                           AND COALESCE(NULLIF(vr.vaccine_name, ''), ii.item_name, '') NOT LIKE '%Default%'
                         GROUP BY DATE(vr.date_administered), vr.patient_id, vr.case_id, vr.dose_number
                     ) AS x
                     GROUP BY x.date
                     ORDER BY x.date ASC";
$stmt = $conn->prepare($weeklyTrendQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$weeklyTrendResult = $stmt->get_result();
$weeklyTrend = [];
while ($row = $weeklyTrendResult->fetch_assoc()) {
    $weeklyTrend[] = $row;
}
$stmt->close();

// 17. REGISTRY RECORDS STATUS
$registryStatusQuery = "SELECT
                           COALESCE(SUM(rr.erig), 0) AS erig_count,
                           COALESCE(SUM(rr.ats), 0) AS ats_count,
                           COALESCE(SUM(rr.tt), 0) AS tt_count
                        FROM registry_records rr
                        INNER JOIN animal_bite_cases abc ON rr.case_id = abc.case_id
                        WHERE abc.branch_id = ?
                          AND abc.is_archived = 0
                          AND rr.is_archived = 0";
$stmt = $conn->prepare($registryStatusQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$registryStatus = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

// 18. DOSE COMPLETION RATE
// registry_records is the source of truth for dose-stage completion.
$doseCompletionQuery = "SELECT
                           AVG(CASE WHEN rr.dose_d0 = 1 THEN 100 ELSE 0 END) AS dose0_rate,
                           AVG(CASE WHEN rr.dose_d3 = 1 THEN 100 ELSE 0 END) AS dose3_rate,
                           AVG(CASE WHEN rr.dose_d7 = 1 THEN 100 ELSE 0 END) AS dose7_rate,
                           AVG(CASE WHEN rr.dose_d14 = 1 THEN 100 ELSE 0 END) AS dose14_rate,
                           AVG(CASE WHEN rr.dose_d21 = 1 THEN 100 ELSE 0 END) AS dose21_rate,
                           AVG(CASE WHEN rr.dose_d28_30 = 1 THEN 100 ELSE 0 END) AS dose28_rate
                        FROM registry_records rr
                        INNER JOIN animal_bite_cases abc ON rr.case_id = abc.case_id
                        WHERE abc.branch_id = ?
                          AND abc.is_archived = 0
                          AND rr.is_archived = 0";
$stmt = $conn->prepare($doseCompletionQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$doseCompletion = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

// 19. CASE STATUS DISTRIBUTION
$caseStatusQuery = "SELECT case_status, COUNT(*) AS count
                    FROM animal_bite_cases
                    WHERE branch_id = ?
                      AND is_archived = 0
                    GROUP BY case_status";
$stmt = $conn->prepare($caseStatusQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$caseStatusResult = $stmt->get_result();
$caseStatusStats = [];
while ($row = $caseStatusResult->fetch_assoc()) {
    $caseStatusStats[$row['case_status']] = $row['count'];
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Nurse Dashboard - <?php echo htmlspecialchars($branch_name); ?></title>
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Reusable Sidebar CSS -->
    <link rel="stylesheet" href="sidebar.css" />
    <style>
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
            background:#f0f2f5;
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

        /* CLICKABLE STAT CARDS */
        .stat-card-link {
            display: block;
            height: 100%;
            text-decoration: none;
            color: inherit;
            border-radius: 16px;
        }

        .stat-card-link:hover,
        .stat-card-link:focus {
            color: inherit;
            text-decoration: none;
        }

        .stat-card-link:focus-visible {
            outline: 3px solid rgba(43, 58, 140, 0.28);
            outline-offset: 3px;
        }

        .stat-card-link .stat-card {
            cursor: pointer;
        }

        /* ALL STAT CARDS - UNIFORM SIZE */
   .stat-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 18px 22px;
    height: 120px;

    display: grid;
    grid-template-columns: 42px 1fr;
    grid-template-rows: auto auto;
    column-gap: 12px;
    align-items: center;

    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);

    position: relative;
    overflow: hidden;

    transition: transform 0.2s, box-shadow 0.2s;
}

/* Colored left border */
.stat-card {
    border-left: 5px solid var(--primary);
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.10);
}

/* ICON */
.stat-card .stat-icon {
    position: static;
    grid-column: 1;
    grid-row: 1 / 3;

    transform: none;

    font-size: 30px;
    opacity: 1;
    color: var(--primary);

    display: flex;
    align-items: center;
    justify-content: center;
}

/* TITLE */
.stat-card .stat-title {
    grid-column: 2;
    grid-row: 1;

    font-weight: 500;
    color: #2f3b4d;
    font-size: 14px;
    letter-spacing: 0;
    margin: 0;
}

/* NUMBER */
.stat-card .stat-number {
    grid-column: 2;
    grid-row: 2;

    font-size: 28px;
    font-weight: 700;
    color: #111827;
    line-height: 1.1;
}

.stat-danger {
    border-left-color: #F21D2F;
}

.stat-warning {
    border-left-color: #ffc107;
}

.stat-success {
    border-left-color: #28a745;
}

.stat-info {
    border-left-color: #17a2b8;
}

.stat-primary {
    border-left-color: #2B3A8C;
}

/* Match icon color with card border */
.stat-danger .stat-icon {
    color: #F21D2F;
}

.stat-warning .stat-icon {
    color: #ffc107;
}

.stat-success .stat-icon {
    color: #28a745;
}

.stat-info .stat-icon {
    color: #17a2b8;
}

.stat-primary .stat-icon {
    color: #2B3A8C;
}


        /* Large Cards */
        .large-card {
            background: white;
            border-radius: 18px;
            padding: 22px 24px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.06);
            height: 100%;
            min-height: 340px;
            display: flex;
            flex-direction: column;
            transition: transform 0.2s;
        }
        .large-card:hover {
            transform: translateY(-2px);
        }
        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Schedule Table */
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
            flex: 1;
        }
        .schedule-table td {
            padding: 10px 4px;
            border-bottom: 1px solid #d7def0;
            vertical-align: top;
        }
        .schedule-table tr:last-child td {
            border-bottom: none;
        }
        .schedule-table .time-col {
            font-weight: 600;
            color: var(--primary);
            white-space: nowrap;
            width: 90px;
        }
        .schedule-table .activity-col {
            font-weight: 500;
            color: #1f2a4a;
        }
        .schedule-table .activity-col .sub-activity {
            font-weight: 400;
            color: #5a6a8a;
            font-size: 14px;
            display: block;
            margin-top: 1px;
        }

        /* Follow-up Items */
        .followup-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px solid #d7def0;
        }
        .followup-item:last-child {
            border-bottom: none;
        }
        .followup-item .followup-date {
            font-weight: 600;
            color: var(--primary);
            white-space: nowrap;
            min-width: 100px;
            font-size: 15px;
        }
        .followup-item .followup-name {
            font-weight: 500;
            color: #1f2a4a;
            font-size: 15px;
            flex: 1;
        }
        .followup-item .followup-days {
            font-size: 13px;
            color: #6c757d;
            margin-left: auto;
        }

        /* Stock Items */
        .stock-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #d7def0;
        }
        .stock-item:last-child {
            border-bottom: none;
        }
        .stock-item .stock-name {
            font-weight: 500;
            color: #1f2a4a;
        }
        .stock-item .stock-qty {
            font-weight: 600;
            color: var(--danger);
            white-space: nowrap;
        }

        /* Buttons */
        .btn-view {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 8px 28px;
            font-weight: 600;
            transition: 0.15s;
            font-size: 14px;
        }
        .btn-view:hover {
            background: #1d2863;
            color: #fff;
        }
        .text-end.mt-auto {
            margin-top: auto;
            padding-top: 14px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 20px 10px;
            color: #999;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .empty-state i {
            font-size: 32px;
            margin-bottom: 10px;
            display: block;
        }

        /* Chart Container */
        .chart-container {
            height: 200px;
            position: relative;
            flex: 1;
        }

        /* Responsive */
        @media (max-width: 991px) {
            .main {
                margin-left: 90px;
            }
}
        @media (max-width: 576px) {
            .topbar {
                padding: 0 16px;
                height: 70px;
            }
            .content {
                padding: 20px 16px;
            }
            .stat-card .stat-number {
                font-size: 32px;
            }
            .stat-card {
                height: 100px;
                padding: 16px;
            }
            .stat-card .stat-icon {
                font-size: 36px;
                right: 14px;
            }
            .schedule-table .time-col {
                width: 60px;
                font-size: 13px;
            }
            .followup-item {
                flex-wrap: wrap;
                gap: 4px;
            }
            .followup-item .followup-date {
                min-width: auto;
                font-size: 14px;
            }
            .large-card {
                padding: 16px;
                min-height: 280px;
            }
        }
    </style>
</head>
<body>

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
            <li><a class="active" href="Nurse_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="Nurse_Patients.php"><i class="bi bi-heart-pulse-fill"></i><span>Patients</span></a></li>
            <li><a href="Nurse_Assessment.php"><i class="bi bi-clipboard2-pulse-fill"></i><span>Assessment Queue</span></a></li>
            <li><a href="Nurse_Vaccination.php"><i class="bi-shield-plus"></i><span>Vaccination</span></a></li>
            <li><a href="Nurse_DailyInventory.php"><i class="bi bi-clipboard-data-fill"></i><span>Daily Inventory</span></a></li>
            <li><a href="Nurse_MedicalSuppliesManagement.php"><i class="bi bi-calendar-check"></i><span>Medical Supplies Management</span></a></li>
            <li><a href="Nurse_Supplyforecasting.php"><i class="bi bi-box-seam"></i><span>Supply Forecasting</span></a></li>
            <li><a href="Nurse_Notification.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
        </ul>
    </nav>

    <div class="logout">
        <a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </div>
</div>

<!-- ========== MAIN CONTENT ========== -->
<div class="main">

    <!-- TOP BAR -->
    <div class="topbar">
        <h3>Dashboard <small><?php echo htmlspecialchars($branch_name); ?></small></h3>
        <div class="profile"><i class="bi bi-person-circle"></i><span><?php echo htmlspecialchars($username); ?></span><span>| Nurse</span></div>
    </div>

    <!-- PAGE CONTENT -->
    <div class="content">

        <!-- ============================================ -->
        <!-- STATS ROW - ALL 8 CARDS UNIFORM -->
        <!-- ============================================ -->
        <div class="row g-4">
            <!-- Patient Waiting -->
            <div class="col-xl-3 col-lg-6 col-md-6">
                <a class="stat-card-link" href="Nurse_Vaccination.php?tab=patients" aria-label="View patients waiting for vaccination" title="View patients waiting for vaccination">
                    <div class="stat-card stat-danger">
                        <span class="stat-icon"><i class="bi bi-person"></i></span>
                        <div class="stat-title">Patient Waiting</div>
                        <div class="stat-number"><?php echo number_format($stats['patient_waiting']); ?></div>
                    </div>
                </a>
            </div>
            <!-- Ongoing Cases -->
            <div class="col-xl-3 col-lg-6 col-md-6">
                <a class="stat-card-link" href="Nurse_Patients.php" aria-label="View ongoing patient cases" title="View ongoing patient cases">
                    <div class="stat-card stat-warning">
                        <span class="stat-icon"><i class="bi bi-activity"></i></span>
                        <div class="stat-title">Ongoing Cases</div>
                        <div class="stat-number"><?php echo number_format($stats['ongoing_cases']); ?></div>
                    </div>
                </a>
            </div>
            <!-- Completed Cases -->
            <div class="col-xl-3 col-lg-6 col-md-6">
                <a class="stat-card-link" href="Nurse_Patients.php" aria-label="View completed patient cases" title="View completed patient cases">
                    <div class="stat-card stat-success">
                        <span class="stat-icon"><i class="bi bi-check-circle"></i></span>
                        <div class="stat-title">Completed Cases</div>
                        <div class="stat-number"><?php echo number_format($stats['completed_cases']); ?></div>
                    </div>
                </a>
            </div>
            <!-- Vaccinations Today -->
            <div class="col-xl-3 col-lg-6 col-md-6">
                <a class="stat-card-link" href="Nurse_Vaccination.php" aria-label="Open vaccination management" title="Open vaccination management">
                    <div class="stat-card stat-info">
                        <span class="stat-icon"><i class="bi bi-shield-plus"></i></span>
                        <div class="stat-title">Vaccination Stages Today</div>
                        <div class="stat-number"><?php echo number_format($stats['today_vaccinations']); ?></div>
                    </div>
                </a>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- SECOND STATS ROW - ALL 4 CARDS UNIFORM -->
        <!-- ============================================ -->
        <div class="row g-4 mt-3">
            <!-- Total Patients -->
            <div class="col-xl-3 col-lg-6 col-md-6">
                <a class="stat-card-link" href="Nurse_Patients.php" aria-label="View all patients" title="View all patients">
                    <div class="stat-card">
                        <span class="stat-icon"><i class="bi bi-people"></i></span>
                        <div class="stat-title">Total Patients</div>
                        <div class="stat-number"><?php echo number_format($stats['total_patients']); ?></div>
                    </div>
                </a>
            </div>
            <!-- Total Cases -->
            <div class="col-xl-3 col-lg-6 col-md-6">
                <a class="stat-card-link" href="Nurse_Patients.php" aria-label="View all patient cases" title="View all patient cases">
                    <div class="stat-card">
                        <span class="stat-icon"><i class="bi bi-file-medical"></i></span>
                        <div class="stat-title">Total Cases</div>
                        <div class="stat-number"><?php echo number_format($stats['total_cases']); ?></div>
                    </div>
                </a>
            </div>
            <!-- Upcoming (7 days) -->
            <div class="col-xl-3 col-lg-6 col-md-6">
                <a class="stat-card-link" href="Nurse_Vaccination.php?tab=patients" aria-label="View upcoming vaccinations" title="View upcoming vaccinations">
                    <div class="stat-card">
                        <span class="stat-icon"><i class="bi bi-calendar-check"></i></span>
                        <div class="stat-title">Upcoming (7 days)</div>
                        <div class="stat-number"><?php echo number_format($stats['upcoming_vaccinations']); ?></div>
                    </div>
                </a>
            </div>
            <!-- Missed Vaccinations -->
            <div class="col-xl-3 col-lg-6 col-md-6">
                <a class="stat-card-link" href="Nurse_Vaccination.php?tab=patients" aria-label="View missed vaccinations" title="View missed vaccinations">
                    <div class="stat-card">
                        <span class="stat-icon"><i class="bi bi-exclamation-triangle"></i></span>
                        <div class="stat-title">Missed Vaccinations</div>
                        <div class="stat-number"><?php echo number_format($stats['missed_vaccinations']); ?></div>
                    </div>
                </a>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- CHART & STATISTICS ROW -->
        <!-- ============================================ -->
        <div class="row g-4 mt-2">
            
            <!-- Weekly Vaccination Trend Chart -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="section-title">
                        <i class="bi bi-graph-up"></i> Weekly Vaccination Trend
                    </div>
                    <div class="chart-container">
                        <canvas id="weeklyChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Bite Category Distribution -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="section-title">
                        <i class="bi bi-pie-chart"></i> Bite Category Distribution
                    </div>
                    <?php if (empty($biteCategories)): ?>
                        <div class="empty-state">
                            <i class="bi bi-inbox"></i>
                            <p>No data available</p>
                        </div>
                    <?php else: ?>
                        <div class="chart-container">
                            <canvas id="categoryChart"></canvas>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- 2 COLUMN LAYOUT: Schedule & Follow-ups -->
        <!-- ============================================ -->
        <div class="row g-4 mt-2">

            <!-- Today's Schedule -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="section-title">
                        <i class="bi bi-calendar-day"></i> Today's Schedule
                        <span class="badge bg-primary rounded-pill ms-auto"><?php echo count($schedules); ?></span>
                    </div>

                    <?php if (empty($schedules)): ?>
                        <div class="empty-state">
                            <i class="bi bi-calendar-check"></i>
                            <p>No scheduled vaccinations for today.</p>
                        </div>
                    <?php else: ?>
                        <table class="schedule-table">
                            <?php foreach ($schedules as $schedule): ?>
                                <tr>
                                    <td class="time-col">
                                        <?php echo htmlspecialchars(dashboardDoseLabel($schedule['dose_number'])); ?>
                                    </td>
                                    <td class="activity-col">
                                        <?php echo htmlspecialchars($schedule['full_name']); ?>
                                        <span class="sub-activity">
                                            Dose <?php echo (int)$schedule['dose_number']; ?>
                                            (<?php echo htmlspecialchars(dashboardDoseLabel($schedule['dose_number'])); ?>)
                                            <?php if ((int)$schedule['dose_number'] === 6): ?>
                                                <span class="badge bg-success">Final Stage</span>
                                            <?php endif; ?>
                                            <br>
                                            <small class="text-muted">
                                                Products: <?php echo htmlspecialchars($schedule['vaccine_names'] ?? 'N/A'); ?>
                                            </small>
                                            <br>
                                            <small class="text-muted">Contact: <?php echo htmlspecialchars($schedule['contact_number'] ?? 'N/A'); ?></small>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php endif; ?>

                    <div class="text-end mt-auto">
                        <button class="btn-view" onclick="window.location.href='Nurse_Vaccination.php?tab=patients'">View All Schedule</button>
                    </div>
                </div>
            </div>

            <!-- Follow-up Due -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="section-title">
                        <i class="bi bi-clock-history"></i> Follow-up Due
                        <span class="badge bg-warning rounded-pill ms-auto"><?php echo count($followups); ?></span>
                    </div>

                    <?php if (empty($followups)): ?>
                        <div class="empty-state">
                            <i class="bi bi-check-circle"></i>
                            <p>No follow-ups due at this time.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($followups as $followup): ?>
                            <div class="followup-item">
                                <span class="followup-date">
                                    <?php echo date('M d, Y', strtotime($followup['date_of_bite'])); ?>
                                </span>
                                <span class="followup-name">
                                    <?php echo htmlspecialchars($followup['full_name']); ?>
                                    <?php if (!empty($followup['remarks'])): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars(substr($followup['remarks'], 0, 50)); ?></small>
                                    <?php endif; ?>
                                </span>
                                <span class="followup-days">
                                    <span class="badge bg-danger"><?php echo $followup['days_since_bite']; ?> days</span>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="text-end mt-auto">
                        <button class="btn-view" onclick="window.location.href='Nurse_Patients.php'">View All</button>
                    </div>
                </div>
            </div>

        </div>

        <!-- ============================================ -->
        <!-- BOTTOM ROW: Low Stock & PhilHealth -->
        <!-- ============================================ -->
        <div class="row g-4 mt-2">

            <!-- Low Stock Items -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="section-title">
                        <i class="bi bi-exclamation-triangle-fill" style="color:var(--danger);"></i> Low Stock Items
                        <span class="badge bg-danger rounded-pill ms-auto"><?php echo count($lowStockItems); ?></span>
                    </div>

                    <?php if (empty($lowStockItems)): ?>
                        <div class="empty-state">
                            <i class="bi bi-check-circle-fill" style="color:var(--success);"></i>
                            <p>All items are adequately stocked.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($lowStockItems as $item): ?>
                            <div class="stock-item">
                                <div>
                                    <span class="stock-name"><?php echo htmlspecialchars($item['item_name']); ?></span>
                                    <br>
                                    <small class="text-muted">Min: <?php echo $item['minimum_stock']; ?> <?php echo $item['unit_name']; ?></small>
                                </div>
                                <span class="stock-qty">
                                    <?php echo $item['quantity_available']; ?> <?php echo $item['unit_name']; ?>
                                    <?php if ($item['quantity_available'] == 0): ?>
                                        <span class="badge bg-danger">Out of Stock</span>
                                    <?php elseif ($item['quantity_available'] <= $item['minimum_stock'] / 2): ?>
                                        <span class="badge bg-danger">Critical</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">Low</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="text-end mt-auto">
                        <button class="btn-view" onclick="window.location.href='Nurse_MedicalSuppliesManagement.php'">Manage Inventory</button>
                    </div>
                </div>
            </div>

            <!-- PhilHealth Coverage & Dose Completion -->
            <div class="col-lg-6">
                <div class="large-card">
                    <div class="section-title">
                        <i class="bi bi-hospital"></i> PhilHealth Coverage & Dose Completion
                    </div>

                    <div class="row g-3">
                        <div class="col-6">
                            <div class="p-3 bg-white rounded-3 text-center">
                                <div class="text-muted small">With PhilHealth</div>
                                <div class="h3 fw-bold text-success">
                                    <?php echo number_format($philhealthStats['Yes'] ?? 0); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 bg-white rounded-3 text-center">
                                <div class="text-muted small">Without PhilHealth</div>
                                <div class="h3 fw-bold text-danger">
                                    <?php echo number_format($philhealthStats['No'] ?? 0); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <div class="small text-muted">Dose Completion Rates</div>
                    <div class="row g-2 mt-1">
                        <div class="col-4">
                            <div class="p-2 bg-white rounded-3 text-center">
                                <div class="text-muted small">Dose 0</div>
                                <div class="fw-bold"><?php echo round($doseCompletion['dose0_rate'] ?? 0); ?>%</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 bg-white rounded-3 text-center">
                                <div class="text-muted small">Dose 3</div>
                                <div class="fw-bold"><?php echo round($doseCompletion['dose3_rate'] ?? 0); ?>%</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 bg-white rounded-3 text-center">
                                <div class="text-muted small">Dose 7</div>
                                <div class="fw-bold"><?php echo round($doseCompletion['dose7_rate'] ?? 0); ?>%</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 bg-white rounded-3 text-center">
                                <div class="text-muted small">Dose 14</div>
                                <div class="fw-bold"><?php echo round($doseCompletion['dose14_rate'] ?? 0); ?>%</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 bg-white rounded-3 text-center">
                                <div class="text-muted small">Dose 21</div>
                                <div class="fw-bold"><?php echo round($doseCompletion['dose21_rate'] ?? 0); ?>%</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 bg-white rounded-3 text-center">
                                <div class="text-muted small">Dose 28</div>
                                <div class="fw-bold"><?php echo round($doseCompletion['dose28_rate'] ?? 0); ?>%</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div> <!-- /content -->
</div> <!-- /main -->

<!-- ============================================ -->
<!-- CHARTS INITIALIZATION -->
<!-- ============================================ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Weekly Vaccination Trend Chart
    const weeklyData = <?php echo json_encode($weeklyTrend); ?>;
    if (weeklyData.length > 0) {
        const ctx = document.getElementById('weeklyChart').getContext('2d');
        const labels = weeklyData.map(item => {
            const date = new Date(item.date);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });
        const values = weeklyData.map(item => item.count);
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Completed Dose Stages',
                    data: values,
                    backgroundColor: 'rgba(43, 58, 140, 0.2)',
                    borderColor: '#2B3A8C',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true,
                    pointBackgroundColor: '#2B3A8C',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    }

    // Bite Category Distribution Chart
    const categoryData = <?php echo json_encode($biteCategories); ?>;
    if (categoryData.length > 0) {
        const ctx2 = document.getElementById('categoryChart').getContext('2d');
        const labels = categoryData.map(item => item.bite_category || 'Unknown');
        const values = categoryData.map(item => item.count);
        const colors = ['#2B3A8C', '#F21D2F', '#28a745', '#ffc107', '#17a2b8', '#6f42c1'];
        
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors.slice(0, values.length),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: {
                                size: 11
                            }
                        }
                    }
                },
                cutout: '60%'
            }
        });
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
