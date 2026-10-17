<?php

require_once __DIR__ . '/forecast_notification_helper.php';

function getUnreadNotificationCount($conn, $user_id)
{
    // Keep Inventory Officer forecast alerts current on every page that shows
    // a bell count. Other roles are skipped using their current database role.
    $forecastAlertsReady = forecastNotificationRefresh($conn, (int)$user_id);
    $forecastVisibilitySql = $forecastAlertsReady ? ''
        : " AND COALESCE(notification_type, '') NOT IN " . forecastNotificationTypesSql();
    $query = "
        SELECT COUNT(*) AS unread_count
        FROM notifications
        WHERE user_id = ?
          AND is_read = 0
          $forecastVisibilitySql
    ";

    $stmt = $conn->prepare($query);

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $stmt->close();

    return (int)($row['unread_count'] ?? 0);
}
function getAdminStaffNotificationCount(mysqli $conn, $branch_id, ?int $user_id = null):int
{
    if ($branch_id === null || $branch_id === '') {
        return 0;
    }

    $count = 0;

    // 0. Unread stored notifications sent to this Admin Staff member
    //    (e.g. "Chart Ready for Registry" from the Nurse).
    $user_id = $user_id ?? (int)($_SESSION['user_id'] ?? 0);
    if ($user_id > 0) {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total FROM notifications
             WHERE user_id = ? AND is_read = 0 AND notification_type = 'registry'
               AND source_key LIKE 'registry_visit_%'"
        );
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            if ($stmt->execute()) {
                $count += (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            }
            $stmt->close();
        }
    }

    // 1. Upcoming vaccination schedules
    $query = "
        SELECT COUNT(*) AS total
        FROM animal_bite_cases c
        INNER JOIN vaccination_records v
            ON c.case_id = v.case_id
           AND c.branch_id = v.branch_id
        WHERE c.branch_id = ?
          AND c.is_archived = 0
          AND v.is_archived = 0
          AND v.scheduled_date IS NOT NULL
          AND v.vaccination_status = 'Scheduled'
          AND v.scheduled_date BETWEEN CURDATE()
              AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ";

    $stmt = $conn->prepare($query);

    if ($stmt) {
        $stmt->bind_param("s", $branch_id);

        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            $count += (int)($row['total'] ?? 0);
        }

        $stmt->close();
    }

    // 2. Overdue vaccinations
    $query = "
        SELECT COUNT(*) AS total
        FROM animal_bite_cases c
        INNER JOIN vaccination_records v
            ON c.case_id = v.case_id
           AND c.branch_id = v.branch_id
        WHERE c.branch_id = ?
          AND c.is_archived = 0
          AND c.case_status <> 'Completed'
          AND v.is_archived = 0
          AND v.item_id IS NULL
          AND v.scheduled_date IS NOT NULL
          AND v.vaccination_status IN ('Scheduled', 'Missed')
          AND v.scheduled_date < CURDATE()
    ";

    $stmt = $conn->prepare($query);

    if ($stmt) {
        $stmt->bind_param("s", $branch_id);

        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            $count += (int)($row['total'] ?? 0);
        }

        $stmt->close();
    }

    // 3. Incomplete patient records
    $query = "
        SELECT COUNT(*) AS total
        FROM animal_bite_cases c
        INNER JOIN patients p
            ON c.patient_id = p.patient_id
        LEFT JOIN registry_records r
            ON c.case_id = r.case_id
        WHERE c.branch_id = ?
          AND (
              p.birthday IS NULL
              OR p.gender IS NULL
              OR p.gender = ''
              OR p.contact_number IS NULL
              OR p.contact_number = ''
              OR c.animal_type IS NULL
              OR c.animal_type = ''
              OR c.bite_location IS NULL
              OR c.bite_location = ''
              OR r.registry_number IS NULL
              OR r.registry_number = ''
          )
    ";

    $stmt = $conn->prepare($query);

    if ($stmt) {
        $stmt->bind_param("s", $branch_id);

        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            $count += (int)($row['total'] ?? 0);
        }

        $stmt->close();
    }

    // 4. New patients in the last 7 days
    $query = "
        SELECT COUNT(*) AS total
        FROM animal_bite_cases c
        WHERE c.branch_id = ?
          AND c.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ";

    $stmt = $conn->prepare($query);

    if ($stmt) {
        $stmt->bind_param("s", $branch_id);

        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            $count += (int)($row['total'] ?? 0);
        }

        $stmt->close();
    }

    // 5. Patients without vaccination schedule
    $query = "
        SELECT COUNT(*) AS total
        FROM (
            SELECT c.case_id
            FROM animal_bite_cases c
            INNER JOIN patients p
                ON c.patient_id = p.patient_id
            WHERE c.branch_id = ?
              AND c.case_status != 'Completed'
              AND NOT EXISTS (
                  SELECT 1
                  FROM vaccination_records v
                  WHERE v.case_id = c.case_id
                    AND v.branch_id = c.branch_id
              )
            ORDER BY c.created_at DESC
            LIMIT 20
        ) AS limited_notifications
    ";

    $stmt = $conn->prepare($query);

    if ($stmt) {
        $stmt->bind_param("s", $branch_id);

        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            $count += (int)($row['total'] ?? 0);
        }

        $stmt->close();
    }

    // 6. PhilHealth records pending action
    $query = "
        SELECT COUNT(*) AS total
        FROM (
            SELECT c.case_id
            FROM animal_bite_cases c
            INNER JOIN philhealth_records ph
                ON c.case_id = ph.case_id
            WHERE c.branch_id = ?
              AND ph.status IN ('For Writing', 'For Screening', 'For Signing')
            ORDER BY ph.updated_at ASC
            LIMIT 10
        ) AS limited_notifications
    ";

    $stmt = $conn->prepare($query);

    if ($stmt) {
        $stmt->bind_param("s", $branch_id);

        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            $count += (int)($row['total'] ?? 0);
        }

        $stmt->close();
    }

    return $count;
}

