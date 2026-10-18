<?php
/*
 * The separate whole-day sheet was merged into the Daily Inventory closing
 * page (Nurse_DailyInventory.php), which now lists every item used that day
 * with Consumed filled in. Old links and bookmarks are redirected there.
 */
$date = is_string($_GET['date'] ?? null) ? trim($_GET['date']) : '';
$parsed = DateTime::createFromFormat('Y-m-d', $date);
$target = 'Nurse_DailyInventory.php';
if ($parsed !== false && $parsed->format('Y-m-d') === $date) {
    $target .= '?' . http_build_query(['date' => $date]);
}
header('Location: ' . $target, true, 302);
exit;
