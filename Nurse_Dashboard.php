<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/notification_helper.php';

// Get logged-in Nurse
$user_id = (int)$_SESSION['user_id'];
// Get unread notification count
$notification_count = getUnreadNotificationCount($conn, $user_id);

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
// Only non-expired stock is usable. Expired batches are not counted.
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
                   AND (is_.expiration_date IS NULL OR is_.expiration_date >= CURDATE())
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

// 20. TOTAL LOW / OUT-OF-STOCK MEDICAL SUPPLIES
$lowStockCountQuery = "SELECT COUNT(*) AS total
                       FROM (
                           SELECT ii.item_id
                           FROM inventory_items ii
                           INNER JOIN inventory_categories c ON ii.category_id = c.category_id
                           LEFT JOIN inventory_stocks s
                             ON s.item_id = ii.item_id
                            AND s.branch_id = ?
                            AND (s.expiration_date IS NULL OR s.expiration_date >= CURDATE())
                           WHERE c.category_name = 'Medical Supplies'
                           GROUP BY ii.item_id, ii.minimum_stock
                           HAVING COALESCE(SUM(s.quantity_available), 0) <= ii.minimum_stock
                       ) AS low_items";
$stmt = $conn->prepare($lowStockCountQuery);
$stmt->bind_param("s", $branch_id);
$stmt->execute();
$stats['low_stock_items'] = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

// 21. LATEST 7-DAY NON-STALE SUPPLY FORECAST SUMMARY
$forecastPreview = [];
$forecastHighRiskCount = 0;
$forecastLatestDate = null;
$forecastStartDate = null;
$forecastEndDate = null;

$forecastDateStmt = $conn->prepare(
    "SELECT MAX(forecast_date) AS latest_date
     FROM forecast_results
     WHERE branch_id = ?
       AND forecast_days = 7
       AND is_stale = 0"
);

if ($forecastDateStmt) {
    $forecastDateStmt->bind_param("s", $branch_id);
    $forecastDateStmt->execute();
    $forecastLatestDate = $forecastDateStmt->get_result()->fetch_assoc()['latest_date'] ?? null;
    $forecastDateStmt->close();
}

if ($forecastLatestDate) {
    $forecastSummaryStmt = $conn->prepare(
        "SELECT
            COUNT(*) AS total_items,
            SUM(CASE WHEN shortage_probability >= 0.80 THEN 1 ELSE 0 END) AS high_risk_count,
            MIN(forecast_start_date) AS forecast_start_date,
            MAX(forecast_end_date) AS forecast_end_date
         FROM forecast_results
         WHERE branch_id = ?
           AND forecast_days = 7
           AND forecast_date = ?
           AND is_stale = 0"
    );

    if ($forecastSummaryStmt) {
        $forecastSummaryStmt->bind_param("ss", $branch_id, $forecastLatestDate);
        $forecastSummaryStmt->execute();
        $forecastSummary = $forecastSummaryStmt->get_result()->fetch_assoc() ?: [];
        $forecastSummaryStmt->close();

        $forecastHighRiskCount = (int)($forecastSummary['high_risk_count'] ?? 0);
        $forecastStartDate = $forecastSummary['forecast_start_date'] ?? null;
        $forecastEndDate = $forecastSummary['forecast_end_date'] ?? null;
    }

    $forecastPreviewStmt = $conn->prepare(
        "SELECT
            fr.item_id,
            i.item_name,
            u.unit_name,
            fr.current_stock_snapshot,
            fr.minimum_stock_snapshot,
            fr.stock_status,
            fr.shortage_probability,
            fr.recommended_reorder
         FROM forecast_results fr
         INNER JOIN inventory_items i ON i.item_id = fr.item_id
         LEFT JOIN units u ON u.unit_id = i.unit_id
         WHERE fr.branch_id = ?
           AND fr.forecast_days = 7
           AND fr.forecast_date = ?
           AND fr.is_stale = 0
         ORDER BY fr.shortage_probability DESC, fr.recommended_reorder DESC, i.item_name ASC
         LIMIT 5"
    );

    if ($forecastPreviewStmt) {
        $forecastPreviewStmt->bind_param("ss", $branch_id, $forecastLatestDate);
        $forecastPreviewStmt->execute();
        $forecastPreview = $forecastPreviewStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $forecastPreviewStmt->close();
    }
}

$todayDisplay = date('l, F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nurse Dashboard - <?php echo htmlspecialchars($branch_name); ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="sidebar.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>

    <style>
        :root {
            --primary: #2B3A8C;
            --primary-dark: #1f2d6e;
            --primary-soft: #eef1ff;
            --accent: #F21D2F;
            --success: #28a745;
            --success-soft: #e8f7ef;
            --warning: #e4a300;
            --warning-soft: #fff4d6;
            --danger: #dc3545;
            --danger-soft: #feeceb;
            --info: #17a2b8;
            --text: #1f2a44;
            --muted: #6f7b91;
            --border: #e6eaf2;
            --surface: #ffffff;
            --page: #f7f9fd;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--page);
            color: var(--text);
            font-family: 'Segoe UI', Roboto, system-ui, sans-serif;
        }

        .main {
            min-height: 100vh;
            margin-left: 260px;
        }

        .topbar {
            height: 80px;
            padding: 0 35px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #fff;
            border-bottom: 1px solid #e9edf5;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
        }

        .topbar h3 {
            margin: 0;
            color: var(--primary);
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -.3px;
        }

        .topbar h3 small {
            margin-left: 10px;
            color: #6c757d;
            font-size: 15px;
            font-weight: 400;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 7px;
            color: var(--primary);
            font-weight: 600;
        }

        .profile-role {
            margin-left: 3px;
            color: #adb5bd;
            font-size: 12px;
            font-weight: 400;
        }

        .content {
            padding: 30px 35px 42px;
        }

        /* Welcome / priority header */
        .welcome-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
            margin-bottom: 22px;
            padding: 20px 24px;
            background: linear-gradient(135deg, #ffffff 0%, #f2f4ff 100%);
            border: 1px solid #e3e7f5;
            border-radius: 18px;
            box-shadow: 0 4px 14px rgba(43,58,140,.06);
        }

        .welcome-card h1 {
            margin: 0 0 4px;
            color: #18233f;
            font-size: 22px;
            font-weight: 700;
        }

        .welcome-card p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
        }

        .today-date {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 12px;
            color: var(--primary);
            background: #fff;
            border: 1px solid #dfe4f4;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .quick-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
        }

        .quick-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            color: var(--primary);
            background: #fff;
            border: 1px solid #dfe4f4;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            transition: .18s ease;
        }

        .quick-action:hover {
            color: #fff;
            background: var(--primary);
            border-color: var(--primary);
            transform: translateY(-1px);
        }

        /* Section heading */
        .dashboard-section {
            margin-bottom: 24px;
        }

        .section-heading {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 13px;
        }

        .section-heading h2 {
            margin: 0;
            color: #25345d;
            font-size: 16px;
            font-weight: 800;
        }

        .section-heading p {
            margin: 2px 0 0;
            color: var(--muted);
            font-size: 11.5px;
        }

        .section-heading a {
            color: var(--primary);
            font-size: 11.5px;
            font-weight: 700;
            text-decoration: none;
        }

        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4,minmax(0,1fr));
            gap: 16px;
        }

        .stat-card-link {
            display: block;
            color: inherit;
            text-decoration: none;
        }

        .stat-card {
            min-height: 112px;
            padding: 18px 19px;
            display: grid;
            grid-template-columns: 42px 1fr;
            grid-template-rows: auto auto auto;
            column-gap: 12px;
            align-items: center;
            background: #fff;
            border-left: 5px solid var(--primary);
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,.07);
            transition: transform .18s ease, box-shadow .18s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,.09);
        }

        .stat-icon {
            grid-row: 1 / 4;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 28px;
        }

        .stat-title {
            color: #526078;
            font-size: 12.5px;
            font-weight: 650;
        }

        .stat-number {
            color: #111827;
            font-size: 27px;
            font-weight: 750;
            line-height: 1;
        }

        .stat-note {
            color: #8892a5;
            font-size: 10.5px;
        }

        .stat-danger { border-left-color: var(--danger); }
        .stat-danger .stat-icon { color: var(--danger); }
        .stat-warning { border-left-color: var(--warning); }
        .stat-warning .stat-icon { color: var(--warning); }
        .stat-success { border-left-color: var(--success); }
        .stat-success .stat-icon { color: var(--success); }
        .stat-info { border-left-color: var(--info); }
        .stat-info .stat-icon { color: var(--info); }

        /* Cards */
        .panel-card {
            height: 100%;
            overflow: hidden;
            background: #fff;
            border: 0;
            border-radius: 18px;
            box-shadow: 0 3px 10px rgba(0,0,0,.07);
        }

        .panel-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding: 18px 20px;
            border-bottom: 1px solid #edf0f5;
        }

        .panel-title {
            display: flex;
            align-items: center;
            gap: 9px;
            margin: 0;
            color: var(--primary);
            font-size: 16px;
            font-weight: 750;
        }

        .panel-title .icon-box {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            background: var(--primary);
            border-radius: 9px;
            font-size: 16px;
        }

        .panel-subtitle {
            margin: 4px 0 0 43px;
            color: var(--muted);
            font-size: 11px;
        }

        .panel-badge {
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 10.5px;
            font-weight: 750;
            white-space: nowrap;
        }

        .panel-badge.danger {
            color: #b42318;
            background: var(--danger-soft);
        }

        .panel-badge.warning {
            color: #8a6200;
            background: var(--warning-soft);
        }

        .panel-badge.success {
            color: #18794e;
            background: var(--success-soft);
        }

        .panel-body {
            padding: 18px 20px;
        }

        .panel-footer {
            padding: 13px 20px;
            border-top: 1px solid #edf0f5;
            text-align: right;
        }

        .panel-link {
            color: var(--primary);
            font-size: 11.5px;
            font-weight: 750;
            text-decoration: none;
        }

        .panel-link:hover { text-decoration: underline; }

        /* Priority lists */
        .schedule-item,
        .followup-item,
        .stock-item,
        .forecast-item {
            display: flex;
            align-items: flex-start;
            gap: 11px;
            padding: 11px 0;
            border-bottom: 1px solid #edf0f5;
        }

        .schedule-item:last-child,
        .followup-item:last-child,
        .stock-item:last-child,
        .forecast-item:last-child {
            border-bottom: 0;
        }

        .list-marker {
            width: 36px;
            min-width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            background: var(--primary-soft);
            border-radius: 9px;
            font-size: 11px;
            font-weight: 800;
        }

        .list-marker.warning {
            color: #8a6200;
            background: var(--warning-soft);
        }

        .list-marker.danger {
            color: #b42318;
            background: var(--danger-soft);
        }

        .list-main {
            min-width: 0;
            flex: 1;
        }

        .list-title {
            display: block;
            color: #26334f;
            font-size: 12.5px;
            font-weight: 700;
        }

        .list-meta {
            margin-top: 2px;
            color: var(--muted);
            font-size: 10.5px;
            line-height: 1.4;
        }

        .list-side {
            margin-left: auto;
            text-align: right;
            white-space: nowrap;
        }

        .mini-badge {
            display: inline-block;
            padding: 4px 7px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 750;
        }

        .mini-badge.danger {
            color: #b42318;
            background: var(--danger-soft);
        }

        .mini-badge.warning {
            color: #8a6200;
            background: var(--warning-soft);
        }

        .mini-badge.success {
            color: #18794e;
            background: var(--success-soft);
        }

        /* Charts */
        .chart-wrap {
            position: relative;
            height: 250px;
        }

        /* Coverage */
        .coverage-grid {
            display: grid;
            grid-template-columns: 190px 1fr;
            gap: 22px;
            align-items: center;
        }

        .coverage-summary {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .coverage-box {
            padding: 14px 10px;
            text-align: center;
            background: #fafbff;
            border: 1px solid var(--border);
            border-radius: 12px;
        }

        .coverage-box span {
            display: block;
            color: var(--muted);
            font-size: 10.5px;
        }

        .coverage-box strong {
            display: block;
            margin-top: 3px;
            font-size: 22px;
        }

        .dose-row {
            display: grid;
            grid-template-columns: 58px 1fr 44px;
            gap: 9px;
            align-items: center;
            margin-bottom: 10px;
        }

        .dose-row:last-child { margin-bottom: 0; }

        .dose-label {
            color: #536078;
            font-size: 10.5px;
            font-weight: 700;
        }

        .dose-value {
            color: #273451;
            font-size: 10.5px;
            font-weight: 750;
            text-align: right;
        }

        .dose-track {
            height: 7px;
            overflow: hidden;
            background: #edf0f5;
            border-radius: 999px;
        }

        .dose-fill {
            height: 100%;
            background: var(--primary);
            border-radius: inherit;
        }

        .empty-state {
            min-height: 190px;
            padding: 25px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #8c96a8;
            text-align: center;
        }

        .empty-state i {
            margin-bottom: 8px;
            color: #b2bac8;
            font-size: 34px;
        }

        .empty-state p {
            margin: 0;
            font-size: 12px;
        }

        @media (max-width: 1199px) {
            .stats-grid { grid-template-columns: repeat(2,minmax(0,1fr)); }
            .coverage-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 991px) {
            .main { margin-left: 90px; }
            .topbar { padding: 0 22px; }
            .content { padding: 25px 22px 35px; }
            .topbar h3 small, .profile-role { display: none; }
        }

        @media (max-width: 767px) {
            .topbar { height: 70px; padding: 0 16px; }
            .topbar h3 { font-size: 20px; }
            .content { padding: 18px 14px 28px; }
            .stats-grid { grid-template-columns: 1fr; }
            .welcome-card { padding: 18px; }
            .coverage-summary { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 520px) {
            .profile span { display: none; }
            .coverage-summary { grid-template-columns: 1fr; }
        }
    </style>
</head>

<body>

<div class="sidebar">
    <div class="logo-area">
        <div class="logo-frame">
            <img src="logo.png" alt="Smart Bite Care Logo" class="logo">
        </div>
        <div class="system-name">Smart Bite Care</div>
    </div>

    <nav class="nav-menu">
        <ul>
            <li><a class="active" href="Nurse_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
            <li><a href="Nurse_Patients.php"><i class="bi bi-heart-pulse-fill"></i><span>Patients</span></a></li>
            <li><a href="Nurse_Assessment.php"><i class="bi bi-clipboard2-pulse-fill"></i><span>Assessment Queue</span></a></li>
            <li><a href="Nurse_Vaccination.php"><i class="bi bi-shield-plus"></i><span>Vaccination</span></a></li>
            <li><a href="Nurse_DailyInventory.php"><i class="bi bi-clipboard-data-fill"></i><span>Daily Inventory</span></a></li>
            <li><a href="Nurse_MedicalSuppliesManagement.php"><i class="bi bi-calendar-check"></i><span>Medical Supplies Management</span></a></li>
            <li><a href="Nurse_Supplyforecasting.php"><i class="bi bi-graph-up-arrow"></i><span>Supply Forecasting</span></a></li>
            <li>
                <a href="Nurse_Notification.php">
                    <i class="bi bi-bell-fill"></i>
                    <span class="notification-label">
                        Notifications
                        <?php if ($notification_count > 0): ?>
                            <span class="notification-badge"><?php echo $notification_count; ?></span>
                        <?php endif; ?>
                    </span>
                </a>
            </li>
        </ul>
    </nav>
</div>

<div class="main">

    <div class="topbar">
        <h3>
            Dashboard
            <small><?php echo htmlspecialchars($branch_name); ?></small>
        </h3>

        <div class="dropdown">
            <button
                class="profile dropdown-toggle border-0 bg-transparent px-3 py-2 rounded-3"
                type="button"
                id="nurseProfileMenu"
                data-bs-toggle="dropdown"
                aria-expanded="false"
            >
                <i class="bi bi-person-circle"></i>
                <span><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="profile-role">| Nurse</span>
            </button>

            <ul class="dropdown-menu dropdown-menu-end border-0 shadow p-2 mt-2" aria-labelledby="nurseProfileMenu">
                <li><h6 class="dropdown-header">Account options</h6></li>
                <li>
                    <a class="dropdown-item rounded-2 py-2" href="Account_ChangePassword.php">
                        <i class="bi bi-key-fill me-2"></i>Change Password
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a
                        class="dropdown-item rounded-2 py-2 text-danger"
                        href="logout.php"
                        onclick="return window.confirm('Are you sure you want to log out?');"
                    >
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <div class="content">

        <?php if (isset($_GET['password_changed']) && $_GET['password_changed'] === '1'): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 mb-4" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <strong>Password changed successfully.</strong>
                Use your new password the next time you log in.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

    

        <!-- TODAY / ATTENTION -->
        <section class="dashboard-section">
            <div class="section-heading">
                <div>
                    <h2>Today's Workload</h2>
                    <p>Items that may need the nurse's attention first.</p>
                </div>
            </div>

            <div class="stats-grid">
                <a class="stat-card-link" href="Nurse_Vaccination.php?tab=patients">
                    <div class="stat-card stat-danger">
                        <span class="stat-icon"><i class="bi bi-person-exclamation"></i></span>
                        <span class="stat-title">Patients Waiting</span>
                        <span class="stat-number"><?php echo number_format($stats['patient_waiting']); ?></span>
                        <span class="stat-note">Ongoing patient cases</span>
                    </div>
                </a>

                <a class="stat-card-link" href="Nurse_Vaccination.php">
                    <div class="stat-card stat-info">
                        <span class="stat-icon"><i class="bi bi-shield-check"></i></span>
                        <span class="stat-title">Vaccination Stages Today</span>
                        <span class="stat-number"><?php echo number_format($stats['today_vaccinations']); ?></span>
                        <span class="stat-note">Completed today</span>
                    </div>
                </a>

                <a class="stat-card-link" href="Nurse_Vaccination.php?tab=patients">
                    <div class="stat-card stat-warning">
                        <span class="stat-icon"><i class="bi bi-calendar-event"></i></span>
                        <span class="stat-title">Upcoming Vaccinations</span>
                        <span class="stat-number"><?php echo number_format($stats['upcoming_vaccinations']); ?></span>
                        <span class="stat-note">Next 7 days</span>
                    </div>
                </a>

                <a class="stat-card-link" href="Nurse_Vaccination.php?tab=patients">
                    <div class="stat-card stat-danger">
                        <span class="stat-icon"><i class="bi bi-calendar-x"></i></span>
                        <span class="stat-title">Missed Vaccinations</span>
                        <span class="stat-number"><?php echo number_format($stats['missed_vaccinations']); ?></span>
                        <span class="stat-note">Needs follow-up</span>
                    </div>
                </a>
            </div>
        </section>

        <!-- BRANCH OVERVIEW -->
        <section class="dashboard-section">
            <div class="section-heading">
                <div>
                    <h2>Branch Overview</h2>
                    <p>Current patient/case volume and inventory attention items.</p>
                </div>
            </div>

            <div class="stats-grid">
                <a class="stat-card-link" href="Nurse_Patients.php">
                    <div class="stat-card stat-warning">
                        <span class="stat-icon"><i class="bi bi-activity"></i></span>
                        <span class="stat-title">Ongoing Cases</span>
                        <span class="stat-number"><?php echo number_format($stats['ongoing_cases']); ?></span>
                        <span class="stat-note">Active animal-bite cases</span>
                    </div>
                </a>

                <a class="stat-card-link" href="Nurse_Patients.php">
                    <div class="stat-card stat-success">
                        <span class="stat-icon"><i class="bi bi-check-circle"></i></span>
                        <span class="stat-title">Completed Cases</span>
                        <span class="stat-number"><?php echo number_format($stats['completed_cases']); ?></span>
                        <span class="stat-note">Completed case records</span>
                    </div>
                </a>

                <a class="stat-card-link" href="Nurse_Patients.php">
                    <div class="stat-card">
                        <span class="stat-icon"><i class="bi bi-people"></i></span>
                        <span class="stat-title">Total Patients</span>
                        <span class="stat-number"><?php echo number_format($stats['total_patients']); ?></span>
                        <span class="stat-note"><?php echo number_format($stats['total_cases']); ?> total cases</span>
                    </div>
                </a>

                <a class="stat-card-link" href="Nurse_MedicalSuppliesManagement.php">
                    <div class="stat-card stat-danger">
                        <span class="stat-icon"><i class="bi bi-box-seam"></i></span>
                        <span class="stat-title">Low / Out of Stock</span>
                        <span class="stat-number"><?php echo number_format($stats['low_stock_items']); ?></span>
                        <span class="stat-note">Usable medical supplies</span>
                    </div>
                </a>
            </div>
        </section>

        <!-- PRIORITY WORK -->
        <section class="dashboard-section">
            <div class="section-heading">
                <div>
                    <h2>Priority Work</h2>
                    <p>Today's vaccination schedule and older ongoing cases that may need follow-up.</p>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-xl-7">
                    <div class="panel-card">
                        <div class="panel-header">
                            <div>
                                <h3 class="panel-title">
                                    <span class="icon-box"><i class="bi bi-calendar-day"></i></span>
                                    Today's Vaccination Schedule
                                </h3>
                                <p class="panel-subtitle">Scheduled dose stages for today.</p>
                            </div>
                            <span class="panel-badge success"><?php echo count($schedules); ?> scheduled</span>
                        </div>

                        <div class="panel-body">
                            <?php if (empty($schedules)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-calendar-check"></i>
                                    <p>No scheduled vaccinations for today.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($schedules as $schedule): ?>
                                    <div class="schedule-item">
                                        <span class="list-marker">
                                            <?php echo htmlspecialchars(dashboardDoseLabel($schedule['dose_number'])); ?>
                                        </span>

                                        <div class="list-main">
                                            <span class="list-title"><?php echo htmlspecialchars($schedule['full_name']); ?></span>
                                            <div class="list-meta">
                                                <?php echo htmlspecialchars($schedule['vaccine_names'] ?? 'N/A'); ?>
                                                · Contact: <?php echo htmlspecialchars($schedule['contact_number'] ?? 'N/A'); ?>
                                            </div>
                                        </div>

                                        <div class="list-side">
                                            <?php if ((int)$schedule['dose_number'] === 6): ?>
                                                <span class="mini-badge success">Final Stage</span>
                                            <?php else: ?>
                                                <span class="mini-badge success">Scheduled</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="panel-footer">
                            <a class="panel-link" href="Nurse_Vaccination.php?tab=patients">
                                View vaccination schedule <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-xl-5">
                    <div class="panel-card">
                        <div class="panel-header">
                            <div>
                                <h3 class="panel-title">
                                    <span class="icon-box"><i class="bi bi-clock-history"></i></span>
                                    Follow-up Due
                                </h3>
                                <p class="panel-subtitle">Ongoing cases seven or more days from the bite date.</p>
                            </div>
                            <span class="panel-badge warning"><?php echo count($followups); ?> shown</span>
                        </div>

                        <div class="panel-body">
                            <?php if (empty($followups)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-check-circle"></i>
                                    <p>No follow-ups due at this time.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($followups as $followup): ?>
                                    <div class="followup-item">
                                        <span class="list-marker warning">
                                            <?php echo (int)$followup['days_since_bite']; ?>d
                                        </span>

                                        <div class="list-main">
                                            <span class="list-title"><?php echo htmlspecialchars($followup['full_name']); ?></span>
                                            <div class="list-meta">
                                                Bite date: <?php echo date('M d, Y', strtotime($followup['date_of_bite'])); ?>
                                                <?php if (!empty($followup['remarks'])): ?>
                                                    · <?php echo htmlspecialchars(mb_strimwidth($followup['remarks'], 0, 55, '…')); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="list-side">
                                            <span class="mini-badge danger">Follow-up</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="panel-footer">
                            <a class="panel-link" href="Nurse_Patients.php">
                                View patient records <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ACTIVITY -->
        <section class="dashboard-section">
            <div class="section-heading">
                <div>
                    <h2>Clinical Activity</h2>
                    <p>Recent vaccination volume and animal-bite category distribution.</p>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="panel-card">
                        <div class="panel-header">
                            <div>
                                <h3 class="panel-title">
                                    <span class="icon-box"><i class="bi bi-graph-up"></i></span>
                                    Weekly Vaccination Trend
                                </h3>
                                <p class="panel-subtitle">Completed dose stages over the previous seven days.</p>
                            </div>
                        </div>
                        <div class="panel-body">
                            <?php if (empty($weeklyTrend)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-graph-up"></i>
                                    <p>No completed vaccination stages in the last seven days.</p>
                                </div>
                            <?php else: ?>
                                <div class="chart-wrap">
                                    <canvas id="weeklyChart"></canvas>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="panel-card">
                        <div class="panel-header">
                            <div>
                                <h3 class="panel-title">
                                    <span class="icon-box"><i class="bi bi-pie-chart"></i></span>
                                    Bite Category Distribution
                                </h3>
                                <p class="panel-subtitle">Current non-archived animal-bite cases.</p>
                            </div>
                        </div>
                        <div class="panel-body">
                            <?php if (empty($biteCategories)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-pie-chart"></i>
                                    <p>No bite-category data available.</p>
                                </div>
                            <?php else: ?>
                                <div class="chart-wrap">
                                    <canvas id="categoryChart"></canvas>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- SUPPLY INTELLIGENCE -->
        <section class="dashboard-section">
            <div class="section-heading">
                <div>
                    <h2>Supply Intelligence</h2>
                    <p>Immediate stock conditions and the latest seven-day forecasting risks.</p>
                </div>
                <a href="Nurse_Supplyforecasting.php?days=7">Open full forecast <i class="bi bi-arrow-right"></i></a>
            </div>

            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="panel-card">
                        <div class="panel-header">
                            <div>
                                <h3 class="panel-title">
                                    <span class="icon-box"><i class="bi bi-exclamation-triangle"></i></span>
                                    Low Stock Medical Supplies
                                </h3>
                                <p class="panel-subtitle">Only non-expired usable stock is counted.</p>
                            </div>
                            <span class="panel-badge danger"><?php echo number_format($stats['low_stock_items']); ?> total</span>
                        </div>

                        <div class="panel-body">
                            <?php if (empty($lowStockItems)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-check-circle"></i>
                                    <p>All medical supplies are above their minimum stock level.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($lowStockItems as $item): ?>
                                    <?php
                                        $qty = (float)$item['quantity_available'];
                                        $min = (float)$item['minimum_stock'];
                                        $unit = (string)$item['unit_name'];
                                        $isOut = $qty <= 0;
                                        $isCritical = !$isOut && $min > 0 && $qty <= ($min * 0.5);
                                    ?>
                                    <div class="stock-item">
                                        <span class="list-marker <?php echo ($isOut || $isCritical) ? 'danger' : 'warning'; ?>">
                                            <i class="bi bi-box-seam"></i>
                                        </span>

                                        <div class="list-main">
                                            <span class="list-title"><?php echo htmlspecialchars($item['item_name']); ?></span>
                                            <div class="list-meta">
                                                <?php echo number_format($qty, 2); ?> <?php echo htmlspecialchars($unit); ?>
                                                available · Minimum <?php echo number_format($min, 2); ?>
                                            </div>
                                        </div>

                                        <div class="list-side">
                                            <?php if ($isOut): ?>
                                                <span class="mini-badge danger">Out of Stock</span>
                                            <?php elseif ($isCritical): ?>
                                                <span class="mini-badge danger">Critical</span>
                                            <?php else: ?>
                                                <span class="mini-badge warning">Low</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="panel-footer">
                            <a class="panel-link" href="Nurse_MedicalSuppliesManagement.php">
                                View medical supplies <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="panel-card">
                        <div class="panel-header">
                            <div>
                                <h3 class="panel-title">
                                    <span class="icon-box"><i class="bi bi-graph-up-arrow"></i></span>
                                    7-Day Forecast Risk
                                </h3>
                                <p class="panel-subtitle">
                                    <?php if ($forecastLatestDate && $forecastStartDate && $forecastEndDate): ?>
                                        <?php echo date('M d', strtotime($forecastStartDate)); ?>
                                        – <?php echo date('M d, Y', strtotime($forecastEndDate)); ?>
                                    <?php else: ?>
                                        Latest non-stale Branch Admin forecast
                                    <?php endif; ?>
                                </p>
                            </div>
                            <span class="panel-badge <?php echo $forecastHighRiskCount > 0 ? 'danger' : 'success'; ?>">
                                <?php echo number_format($forecastHighRiskCount); ?> high risk
                            </span>
                        </div>

                        <div class="panel-body">
                            <?php if (empty($forecastPreview)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-graph-up"></i>
                                    <p>No current seven-day forecast is available yet.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($forecastPreview as $forecast): ?>
                                    <?php
                                        $risk = max(0.0, min(1.0, (float)$forecast['shortage_probability']));
                                        $riskPercent = $risk * 100;
                                        $riskClass = $risk >= .80 ? 'danger' : ($risk >= .60 ? 'warning' : 'success');
                                        $riskLabel = $risk >= .80 ? 'High Risk' : ($risk >= .60 ? 'Moderate' : 'Low Risk');
                                        $reorder = max(0, (int)$forecast['recommended_reorder']);
                                        $unit = trim((string)($forecast['unit_name'] ?? '')) ?: 'unit(s)';
                                    ?>
                                    <div class="forecast-item">
                                        <span class="list-marker <?php echo $riskClass === 'danger' ? 'danger' : ($riskClass === 'warning' ? 'warning' : ''); ?>">
                                            <?php echo number_format($riskPercent, 0); ?>%
                                        </span>

                                        <div class="list-main">
                                            <span class="list-title"><?php echo htmlspecialchars($forecast['item_name']); ?></span>
                                            <div class="list-meta">
                                                Stock <?php echo number_format((float)$forecast['current_stock_snapshot'], 2); ?>
                                                · Min <?php echo number_format((float)$forecast['minimum_stock_snapshot'], 2); ?>
                                                <?php if ($reorder > 0): ?>
                                                    · Reorder <?php echo number_format($reorder); ?> <?php echo htmlspecialchars($unit); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="list-side">
                                            <span class="mini-badge <?php echo $riskClass; ?>"><?php echo $riskLabel; ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="panel-footer">
                            <a class="panel-link" href="Nurse_Supplyforecasting.php?days=7">
                                Review supply forecast <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- COVERAGE + DOSE COMPLETION -->
        <section class="dashboard-section mb-0">
            <div class="section-heading">
                <div>
                    <h2>Coverage & Treatment Progress</h2>
                    <p>PhilHealth coverage and dose-stage completion among registry records.</p>
                </div>
            </div>

            <div class="panel-card">
                <div class="panel-body">
                    <div class="coverage-grid">
                        <div class="coverage-summary">
                            <div class="coverage-box">
                                <span>With PhilHealth</span>
                                <strong class="text-success"><?php echo number_format($philhealthStats['Yes'] ?? 0); ?></strong>
                            </div>
                            <div class="coverage-box">
                                <span>Without PhilHealth</span>
                                <strong class="text-danger"><?php echo number_format($philhealthStats['No'] ?? 0); ?></strong>
                            </div>
                        </div>

                        <div>
                            <?php
                                $doseRows = [
                                    'D0' => (float)($doseCompletion['dose0_rate'] ?? 0),
                                    'D3' => (float)($doseCompletion['dose3_rate'] ?? 0),
                                    'D7' => (float)($doseCompletion['dose7_rate'] ?? 0),
                                    'D14' => (float)($doseCompletion['dose14_rate'] ?? 0),
                                    'D21' => (float)($doseCompletion['dose21_rate'] ?? 0),
                                    'D28/30' => (float)($doseCompletion['dose28_rate'] ?? 0),
                                ];
                            ?>

                            <?php foreach ($doseRows as $label => $value): ?>
                                <?php $safeValue = max(0, min(100, $value)); ?>
                                <div class="dose-row">
                                    <span class="dose-label"><?php echo htmlspecialchars($label); ?></span>
                                    <div class="dose-track">
                                        <div class="dose-fill" style="width: <?php echo $safeValue; ?>%;"></div>
                                    </div>
                                    <span class="dose-value"><?php echo round($safeValue); ?>%</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const weeklyData = <?php echo json_encode($weeklyTrend); ?>;

    if (weeklyData.length > 0) {
        const canvas = document.getElementById('weeklyChart');

        if (canvas) {
            const labels = weeklyData.map(function (item) {
                const date = new Date(item.date + 'T00:00:00');
                return date.toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric'
                });
            });

            const values = weeklyData.map(function (item) {
                return Number(item.count);
            });

            new Chart(canvas, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Completed Dose Stages',
                        data: values,
                        borderColor: '#2B3A8C',
                        backgroundColor: 'rgba(43,58,140,.10)',
                        fill: true,
                        tension: .35,
                        pointRadius: 4,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#2B3A8C',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            displayColors: false,
                            callbacks: {
                                label: function (context) {
                                    return context.raw + ' completed stage(s)';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                precision: 0
                            },
                            grid: {
                                color: '#eef1f6'
                            }
                        },
                        x: {
                            grid: { display: false }
                        }
                    }
                }
            });
        }
    }

    const categoryData = <?php echo json_encode($biteCategories); ?>;

    if (categoryData.length > 0) {
        const canvas = document.getElementById('categoryChart');

        if (canvas) {
            new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels: categoryData.map(function (item) {
                        return item.bite_category || 'Unknown';
                    }),
                    datasets: [{
                        data: categoryData.map(function (item) {
                            return Number(item.count);
                        }),
                        backgroundColor: [
                            '#2B3A8C',
                            '#F21D2F',
                            '#28a745',
                            '#e4a300',
                            '#17a2b8',
                            '#6f42c1'
                        ],
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '66%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                boxWidth: 8,
                                padding: 14,
                                font: { size: 10 }
                            }
                        }
                    }
                }
            });
        }
    }
});
</script>

</body>
</html>
