<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * READ-ONLY case number report for one branch and year. Changes nothing.
 *   php sources/check_case_numbers.php --branch=SBI-002 [--year=26]
 * Lists:
 *   1. the next number the generator will give,
 *   2. the highest numbers of the year (archived included) with any big jump
 *      from the number before them, so a stray number is easy to spot,
 *   3. numbers that start with the year but are not valid YY-NNNN (ignored by
 *      the generator), and numbers whose year does not match the admission year.
 * Nothing is renumbered; decide by hand what to do with the listed cases.
 */
$options = getopt('', ['branch:', 'year::', 'help']);
if (isset($options['help'])) { echo "Usage: php sources/check_case_numbers.php --branch=BRANCH_ID [--year=YY]\n"; exit; }
$branchId = trim((string)($options['branch'] ?? ''));
$year = isset($options['year']) ? (string)$options['year'] : date('y');
if (!preg_match('/^[A-Za-z0-9_-]{1,10}$/', $branchId) || !preg_match('/^[0-9]{2}$/', $year)) {
    fwrite(STDERR, "Usage: php sources/check_case_numbers.php --branch=BRANCH_ID [--year=YY]\n");
    exit(1);
}
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/case_number_helper.php';

$prefix = $year . '-';
$pattern = $prefix . '%';
$stmt = $conn->prepare("SELECT case_id, case_number, is_archived, case_status, created_at
    FROM animal_bite_cases WHERE branch_id = ? AND TRIM(case_number) LIKE ?");
$stmt->bind_param('ss', $branchId, $pattern);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$valid = [];
$invalid = [];
foreach ($rows as $row) {
    if (caseNumberIsValid((string)$row['case_number'], $prefix)) {
        $row['n'] = (int)substr(trim((string)$row['case_number']), 3);
        $valid[] = $row;
    } else {
        $invalid[] = $row;
    }
}
usort($valid, static fn(array $a, array $b): int => $a['n'] <=> $b['n'] ?: $a['case_id'] <=> $b['case_id']);

$line = static fn(array $r, string $extra = ''): string => sprintf("  %-12s case_id %-6d %-9s %-9s created %s%s\n",
    '"' . $r['case_number'] . '"', (int)$r['case_id'], (int)$r['is_archived'] ? 'ARCHIVED' : 'active',
    (string)$r['case_status'], (string)$r['created_at'], $extra);

echo "Branch $branchId, year $prefix (read-only; nothing is changed)\n";
echo "Next number the generator gives: " . caseNumberNext($conn, $branchId, '20' . $year . '-01-01') . "\n";
echo count($valid) . " valid $prefix" . "NNNN number(s), " . count($invalid) . " other number(s) starting with $prefix\n\n";

echo "Highest valid numbers (a big jump from the number before is marked):\n";
$top = array_slice($valid, -15);
$offset = count($valid) - count($top);
foreach ($top as $i => $r) {
    $prev = $valid[$offset + $i - 1]['n'] ?? null;
    $gap = $prev === null ? 0 : $r['n'] - $prev;
    echo $line($r, $gap > 50 ? "   <-- jumps $gap from " . $prefix . str_pad((string)$prev, 4, '0', STR_PAD_LEFT) : '');
}
if (!$valid) echo "  (none)\n";

echo "\nIgnored by the generator (not exactly $prefix" . "NNNN):\n";
foreach ($invalid as $r) echo $line($r);
if (!$invalid) echo "  (none)\n";

$stmt = $conn->prepare("SELECT case_id, case_number, is_archived, case_status, created_at
    FROM animal_bite_cases WHERE branch_id = ? AND TRIM(case_number) REGEXP '^[0-9]{2}-[0-9]{4}$'
      AND LEFT(TRIM(case_number), 2) <> DATE_FORMAT(created_at, '%y')
    ORDER BY created_at DESC LIMIT 20");
$stmt->bind_param('s', $branchId);
$stmt->execute();
$other = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
echo "\nYear in the number differs from the year the case was created (back-dated admissions are normal):\n";
foreach ($other as $r) echo $line($r);
if (!$other) echo "  (none)\n";
