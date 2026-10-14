<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_stock_helpers.php';

/** The same rule file and environment overrides are used by forecasting.py. */
function forecastStatusPolicy(): array
{
    static $policy = null;
    if ($policy !== null) return $policy;
    $contents = file_get_contents(__DIR__ . '/forecast_status_rules.json');
    if ($contents === false) throw new RuntimeException('The forecasting status rule file is missing.');
    $policy = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (($policy['version'] ?? '') !== 'sbc-status-v2') {
        throw new RuntimeException('Unsupported forecasting rules. Install all matching Step 8 files.');
    }
    foreach (['restocking_lead_days' => 'SMARTBITECARE_RESTOCK_LEAD_DAYS',
              'safety_buffer_days' => 'SMARTBITECARE_SAFETY_BUFFER_DAYS'] as $field => $name) {
        $configured = getenv($name);
        $value = $configured !== false && $configured !== '' ? $configured : ($policy[$field] ?? null);
        if ((!is_string($value) && !is_int($value))
            || !preg_match('/\A(?:0|[1-9][0-9]*)\z/D', trim((string)$value)) || (int)$value > 3650) {
            throw new RuntimeException('Restocking lead time and safety buffer must be whole days from 0 to 3650.');
        }
        $policy[$field] = (int)$value;
    }
    return $policy;
}

function forecastStatusTicks(float $quantity): int
{
    if (!is_finite($quantity) || $quantity < 0 || $quantity > 99999999.9999) {
        throw new RuntimeException('Forecast quantities must be valid nonnegative DECIMAL(12,4) values.');
    }
    return (int)round($quantity * 10000, 0, PHP_ROUND_HALF_UP);
}

function forecastStatusClassify(float $stock, float $minimum, float $demand, int $days,
    ?int $lead = null, ?int $buffer = null): array
{
    $policy = forecastStatusPolicy();
    $lead ??= $policy['restocking_lead_days'];
    $buffer ??= $policy['safety_buffer_days'];
    if (!in_array($days, [7, 14, 30], true) || $lead < 0 || $buffer < 0 || $lead > 3650 || $buffer > 3650) {
        throw new RuntimeException('Invalid forecast horizon or replenishment window.');
    }
    $s = forecastStatusTicks($stock);
    $m = forecastStatusTicks($minimum);
    $d = forecastStatusTicks($demand);
    $shortage = $s === 0 || ($d > 0 && ($d >= $s || $s * $days < $d * ($lead + $buffer)));
    $difference = max(0, $d + $m - $s);
    return [
        'stock_status' => $s === 0 ? 'OUT OF STOCK' : ($s <= $m ? 'LOW STOCK' : 'SUFFICIENT STOCK'),
        'forecast_status' => $shortage ? 'Shortage Risk' : ($difference > 0 ? 'Reorder Needed' : 'Sufficient'),
        'recommended_reorder' => intdiv($difference + 9999, 10000),
        'estimated_ending_stock' => max(0, $s - $d) / 10000,
        'days_of_supply_remaining' => $d > 0 ? $s * $days / $d : null,
        'restocking_lead_days' => $lead,
        'safety_buffer_days' => $buffer,
        'status_rule_version' => $policy['version'],
    ];
}

function forecastStatusColor(string $status): string
{
    return match ($status) {
        'Shortage Risk', 'OUT OF STOCK' => 'danger',
        'Reorder Needed', 'LOW STOCK' => 'warning',
        'Sufficient', 'SUFFICIENT STOCK' => 'success',
        default => 'secondary',
    };
}

function forecastStatusPriority(string $status): int
{
    return match ($status) { 'Shortage Risk' => 3, 'Reorder Needed' => 2, 'Sufficient' => 1, default => 0 };
}

/** Only forecasts matching the policy, dates, usable stock and minimum are current. */
function forecastStatusCurrentSql(string $alias = 'fr'): string
{
    if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $alias)) throw new InvalidArgumentException('Invalid SQL alias.');
    $policy = forecastStatusPolicy();
    $stockSql = inventoryStockTotalsSql();
    return "$alias.is_stale = 0 AND $alias.status_rule_version = 'sbc-status-v2'"
        . " AND $alias.restocking_lead_days = " . $policy['restocking_lead_days']
        . " AND $alias.safety_buffer_days = " . $policy['safety_buffer_days']
        . " AND $alias.forecast_days IN (7,14,30)"
        . " AND $alias.forecast_date <= CURDATE() AND $alias.forecast_end_date >= CURDATE()"
        . " AND $alias.forecast_start_date = DATE_ADD($alias.forecast_date, INTERVAL 1 DAY)"
        . " AND $alias.forecast_end_date = DATE_ADD($alias.forecast_date, INTERVAL $alias.forecast_days DAY)"
        . " AND EXISTS (SELECT 1 FROM ($stockSql) live_stock"
        . " JOIN inventory_items live_item ON live_item.item_id = live_stock.item_id"
        . " WHERE live_stock.item_id = $alias.item_id AND live_stock.branch_id = $alias.branch_id"
        . " AND live_item.is_forecastable = 1"
        . " AND live_stock.usable_stock = $alias.current_stock_snapshot"
        . " AND live_item.minimum_stock = $alias.minimum_stock_snapshot)";
}

function forecastStatusNormalizeRow(array $row): array
{
    return array_replace($row, forecastStatusClassify((float)$row['current_stock_snapshot'],
        (float)$row['minimum_stock_snapshot'], (float)$row['forecasted_consumption'],
        (int)$row['forecast_days'], (int)$row['restocking_lead_days'], (int)$row['safety_buffer_days']));
}

/** forecast_results.forecast_method is added by sources/migrate_forecast_method.php. */
function forecastMethodColumnExists(mysqli $conn): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forecast_results' AND COLUMN_NAME = 'forecast_method'");
    $stmt->execute();
    $exists = (int)$stmt->get_result()->fetch_assoc()['total'] > 0;
    $stmt->close();
    return $exists;
}

/** Which method produced a forecast: XGBoost or the recent 28-day average fallback. */
function forecastMethodLabel(?string $method): string
{
    return match ($method) {
        'xgboost' => 'XGBoost',
        'recent_mean' => 'Recent 28-day average',
        default => 'Not recorded',
    };
}

function forecastMethodBadgeHtml(?string $method): string
{
    $title = match ($method) {
        'xgboost' => 'XGBoost beat the recent 28-day average on this item\'s holdout test.',
        'recent_mean' => 'XGBoost did not beat the recent 28-day average on this item\'s holdout test, so the average is used.',
        default => 'This forecast was saved before the forecasting method was recorded.',
    };
    return '<div class="text-muted" style="font-size:11px;" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">'
        . 'Method: ' . htmlspecialchars(forecastMethodLabel($method), ENT_QUOTES, 'UTF-8') . '</div>';
}

function forecastStatusLegendHtml(): string
{
    $policy = forecastStatusPolicy();
    return '<div class="alert alert-info mb-4" role="note">'
        . '<strong>Low Stock:</strong> positive usable stock is at or below minimum; zero is Out of Stock. '
        . '<strong>Reorder Needed:</strong> demand would leave less than minimum, with no shortage warning. '
        . '<strong>Shortage Risk:</strong> stock is zero, runs out within this horizon, or covers fewer than '
        . ($policy['restocking_lead_days'] + $policy['safety_buffer_days']) . ' days (lead time plus safety buffer). '
        . 'The stockout percentage is an approximate estimate; it does not determine these labels.'
        . '</div>';
}

/** Latest current results for one horizon, or independently for all horizons. */
function forecastStatusLatestRows(mysqli $conn, string $branchId, ?int $horizon = null): array
{
    if ($horizon !== null && !in_array($horizon, [7, 14, 30], true)) throw new InvalidArgumentException('Invalid horizon.');
    $valid = forecastStatusCurrentSql('fr');
    $valid2 = forecastStatusCurrentSql('fr2');
    $horizonSql = $horizon === null ? '' : ' AND fr.forecast_days = ?';
    $stmt = $conn->prepare("SELECT fr.*, i.item_name, i.base_unit_label,
        i.display_unit_label, i.conversion_to_base, u.unit_name
        FROM forecast_results fr JOIN inventory_items i ON i.item_id = fr.item_id
        LEFT JOIN units u ON u.unit_id = i.unit_id
        WHERE fr.branch_id = ? AND $valid $horizonSql
          AND fr.forecast_date = (SELECT MAX(fr2.forecast_date) FROM forecast_results fr2
              WHERE fr2.branch_id = fr.branch_id AND fr2.forecast_days = fr.forecast_days AND $valid2)");
    if ($horizon === null) $stmt->bind_param('s', $branchId);
    else $stmt->bind_param('si', $branchId, $horizon);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) $row = forecastStatusNormalizeRow($row);
    unset($row);
    usort($rows, static function (array $a, array $b): int {
        return forecastStatusPriority($b['forecast_status']) <=> forecastStatusPriority($a['forecast_status'])
            ?: (float)$b['shortage_probability'] <=> (float)$a['shortage_probability']
            ?: (float)$b['recommended_reorder'] <=> (float)$a['recommended_reorder']
            ?: strcmp($a['item_name'], $b['item_name'])
            ?: (int)$a['forecast_days'] <=> (int)$b['forecast_days'];
    });
    return $rows;
}
