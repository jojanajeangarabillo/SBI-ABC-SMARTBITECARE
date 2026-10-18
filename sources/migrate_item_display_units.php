<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = array_slice($argv, 1);
if (in_array('--help', $args, true)) { echo "Usage: php sources/migrate_item_display_units.php [--apply]\nDefault: read-only preview.\n"; exit; }
foreach ($args as $arg) if ($arg !== '--apply') { fwrite(STDERR, "Unknown argument. Use --help.\n"); exit(1); }
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/inventory_stock_helpers.php';
/*
 * The clinic's confirmed vial sizes. Stock, usage, closings and training data
 * stay in the BASE unit (sites, mL); only the unit settings are corrected so
 * every page shows vials (or ampules):
 *   Speeda, Abhayrab, Vaxirab, Chirorab   base site, display vial,   1 vial = 6 sites
 *   ERIG, HRIG, HTIG                      base mL,   display vial,   1 vial = 5 mL
 *   Hepa B, Hepa BIG, Flu, PPD            base mL,   display vial,   1 vial = 5 mL
 *   ATS (all)                             base mL,   display ampule, 1 ampule = 1 mL
 * Only inventory_items.base_unit_label, display_unit_label and
 * conversion_to_base change. No stored quantity is rescaled. The training
 * history of these items is already in sites/mL (the dataset was generated in
 * base units), so a training_dataset.base_unit_label_snapshot that still says
 * the old label (e.g. "Vial") is relabelled to the base unit; otherwise the
 * repeated-tests evaluation would stop on a unit mismatch. Unexpected labels
 * are reported and left unchanged. The preview lists every item with its
 * current settings and shows the current stock in the new format so a wrong
 * stock unit can be spotted before --apply.
 */
$rules = [
    ['pattern' => '/^(SPEEDA|ABHAYRAB|VAXIRAB|CHIRORAB)$/', 'base' => 'site', 'display' => 'Vial', 'conversion' => '6.0000'],
    ['pattern' => '/^(ERIG|HRIG|HTIG)\b/', 'base' => 'mL', 'display' => 'Vial', 'conversion' => '5.0000'],
    ['pattern' => '/^(HEPA B|HEPA BIG|FLU|PPD)$/', 'base' => 'mL', 'display' => 'Vial', 'conversion' => '5.0000'],
    ['pattern' => '/^ATS\b/', 'base' => 'mL', 'display' => 'Ampule', 'conversion' => '1.0000'],
];
$completed = 0;
try {
    foreach (['base_unit_label', 'display_unit_label', 'conversion_to_base'] as $column) {
        $check = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_items' AND COLUMN_NAME = ?");
        $check->bind_param('s', $column);
        $check->execute();
        if (!$check->get_result()->fetch_row()) throw new RuntimeException("inventory_items.$column is missing. Complete the existing database setup first.");
        $check->close();
    }
    $stockSql = inventoryStockTotalsSql();
    $items = $conn->query("SELECT i.item_id, i.item_name, i.base_unit_label, i.display_unit_label, i.conversion_to_base,
            i.minimum_stock, u.unit_name, COALESCE(SUM(s.usable_stock), 0) AS stock
        FROM inventory_items i
        JOIN units u ON u.unit_id = i.unit_id
        LEFT JOIN ($stockSql) s ON s.item_id = i.item_id
        GROUP BY i.item_id ORDER BY i.item_id")->fetch_all(MYSQLI_ASSOC);

    $plan = [];
    $report = [];
    $matched = array_fill(0, count($rules), 0);
    foreach ($items as $item) {
        $key = strtoupper(preg_replace('/\s+/', ' ', trim((string)$item['item_name'])));
        foreach ($rules as $index => $rule) {
            if (!preg_match($rule['pattern'], $key)) continue;
            $matched[$index]++;
            $currentBase = trim((string)$item['base_unit_label']);
            $currentDisplay = trim((string)$item['display_unit_label']);
            $currentConversion = number_format((float)$item['conversion_to_base'], 4, '.', '');
            $problems = [];
            if (strcasecmp(rtrim($currentBase, 's'), rtrim($rule['base'], 's')) !== 0) $problems[] = "base '" . ($currentBase ?: '(blank)') . "' -> '{$rule['base']}'";
            if (strcasecmp($currentDisplay, $rule['display']) !== 0) $problems[] = "display '" . ($currentDisplay ?: '(blank)') . "' -> '{$rule['display']}'";
            if ($currentConversion !== $rule['conversion']) $problems[] = "conversion $currentConversion -> {$rule['conversion']}";
            $after = ['base_unit_label' => $rule['base'], 'display_unit_label' => $rule['display'], 'conversion_to_base' => $rule['conversion']];
            $stockText = inventoryQuantityText((float)$item['stock'], $after);
            $minText = inventoryQuantityText((float)$item['minimum_stock'], $after);
            $report[] = sprintf('%-38s %s | stock %s %s = %s | minimum %s = %s',
                trim((string)$item['item_name']), $problems ? 'FIX ' . implode(', ', $problems) : 'OK',
                inventoryFormatNumber((float)$item['stock']), $rule['base'], $stockText,
                inventoryFormatNumber((float)$item['minimum_stock']), $minText);
            $id = (int)$item['item_id'];
            if ($problems) {
                $plan[] = ['item', 'Set ' . trim((string)$item['item_name']) . ": {$rule['base']} / {$rule['display']} / {$rule['conversion']}", $id, $rule, []];
            }
            // Training snapshots that still carry the old label (same numbers, already base units).
            $relabel = array_values(array_unique(array_filter(
                [$currentBase, $currentDisplay, trim((string)$item['unit_name']), 'Vial', 'Vials', 'Ampule', 'Ampules'],
                static fn(string $label): bool => $label !== '' && strcasecmp($label, $rule['base']) !== 0
            )));
            $snap = $conn->prepare('SELECT base_unit_label_snapshot AS label, COUNT(*) AS n FROM training_dataset
                WHERE item_id = ? AND COALESCE(base_unit_label_snapshot, \'\') <> \'\' GROUP BY base_unit_label_snapshot');
            $snap->bind_param('i', $id);
            $snap->execute();
            $toRelabel = [];
            foreach ($snap->get_result()->fetch_all(MYSQLI_ASSOC) as $label) {
                if (strcasecmp((string)$label['label'], $rule['base']) === 0) continue;
                $matchesOld = false;
                foreach ($relabel as $old) {
                    if (strcasecmp((string)$label['label'], $old) === 0) $matchesOld = true;
                }
                if ($matchesOld) {
                    $toRelabel[] = (string)$label['label'];
                    $plan[] = ['snapshot', 'Relabel ' . (int)$label['n'] . ' training row(s) of ' . trim((string)$item['item_name'])
                        . " from '{$label['label']}' to '{$rule['base']}' (numbers unchanged)", $id, $rule, [(string)$label['label']]];
                } else {
                    $report[] = '  Note: ' . trim((string)$item['item_name']) . ' has ' . (int)$label['n']
                        . " training row(s) labelled '{$label['label']}'. Left unchanged; review them before running the repeated tests.";
                }
            }
            $snap->close();
            break;
        }
    }

    $apply = in_array('--apply', $args, true);
    echo ($apply ? 'APPLY' : 'PREVIEW — read-only') . "\n";
    echo "Items, with their current stock (all branches) and minimum read in the new format (stored quantities are not changed):\n";
    foreach ($report as $line) echo '  ' . $line . "\n";
    foreach ($rules as $index => $rule) {
        if ($matched[$index] === 0) echo "  Note: no item matches $rule[pattern] in this database.\n";
    }
    if (!$plan) echo "No migration changes are needed.\n";
    $update = $conn->prepare('UPDATE inventory_items SET base_unit_label = ?, display_unit_label = ?, conversion_to_base = ? WHERE item_id = ?');
    $relabelSnapshot = $conn->prepare('UPDATE training_dataset SET base_unit_label_snapshot = ? WHERE item_id = ? AND base_unit_label_snapshot = ?');
    if ($apply) $conn->begin_transaction();
    foreach ($plan as [$kind, $label, $id, $rule, $old]) {
        echo '- ' . $label . "\n";
        if (!$apply) continue;
        if ($kind === 'item') {
            $update->bind_param('sssi', $rule['base'], $rule['display'], $rule['conversion'], $id);
            if (!$update->execute()) throw new RuntimeException('Unable to apply ' . $label);
        } else {
            $relabelSnapshot->bind_param('sis', $rule['base'], $id, $old[0]);
            if (!$relabelSnapshot->execute()) throw new RuntimeException('Unable to apply ' . $label);
        }
        $completed++;
    }
    if ($apply) $conn->commit();
    $update->close();
    $relabelSnapshot->close();
    if ($apply) echo "Item unit settings are ready. Stock, usage, closings and training quantities were not changed.\n";
    else echo "No data or structure was changed. Check that each stock above reads correctly, then add --apply.\n";
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    fwrite(STDERR, 'Item display unit migration stopped: ' . $e->getMessage() . "\n"
        . "No migration changes were applied (all changes run in one transaction).\n");
    exit(1);
}
