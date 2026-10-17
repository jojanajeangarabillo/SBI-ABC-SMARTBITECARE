<?php
declare(strict_types=1);

/**
 * Case numbers use the clinic format YY-NNNN per branch (26-0001, 26-0002...).
 * Numbering continues from the branch's highest number of that year and
 * restarts at 0001 every new year (27-0001). Archived cases still count, so a
 * number is never reused.
 */

/** "26-" for any date in 2026. $date is Y-m-d. */
function caseNumberPrefix(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('A valid date is required for the case number.');
    }
    return $parsed->format('y') . '-';
}

/** Next free case number of the branch for the year of $date (Y-m-d). */
function caseNumberNext(mysqli $conn, string $branchId, string $date): string
{
    $prefix = caseNumberPrefix($date);
    $pattern = $prefix . '%';
    $stmt = $conn->prepare('SELECT case_number FROM animal_bite_cases WHERE branch_id = ? AND case_number LIKE ?');
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the case number lookup.');
    }
    $stmt->bind_param('ss', $branchId, $pattern);
    $stmt->execute();
    $highest = 0;
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        if (preg_match('/\A' . preg_quote($prefix, '/') . '([0-9]{1,6})\z/', trim((string)$row['case_number']), $match)) {
            $highest = max($highest, (int)$match[1]);
        }
    }
    $stmt->close();
    return $prefix . str_pad((string)($highest + 1), 4, '0', STR_PAD_LEFT);
}

/**
 * Serialize case-number allocation per branch so two staff creating a case at
 * the same time cannot receive the same number. Uses a MySQL named lock, so
 * no schema change is required. Always pair with caseNumberUnlock().
 */
function caseNumberLock(mysqli $conn, string $branchId): void
{
    $name = 'sbc_case_number_' . $branchId;
    $stmt = $conn->prepare('SELECT GET_LOCK(?, 10) AS locked');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $locked = (int)($stmt->get_result()->fetch_assoc()['locked'] ?? 0);
    $stmt->close();
    if ($locked !== 1) {
        throw new RuntimeException('Another case is being saved right now. Please try again.');
    }
}

function caseNumberUnlock(mysqli $conn, string $branchId): void
{
    $name = 'sbc_case_number_' . $branchId;
    $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->get_result();
    $stmt->close();
}
