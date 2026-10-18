<?php
declare(strict_types=1);

require_once __DIR__ . '/forecast_accuracy_units.php';

// Report-only aggregation. No training, forecast generation, or database writes.
function forecastMetricsValid(array $row, bool $rolling): bool
{
    $status = $row[$rolling ? 'status' : 'evaluation_status'] ?? null;
    $count = (int)($row[$rolling ? 'observed_records' : 'testing_records'] ?? 0);
    $horizon = (int)($row['horizon_days'] ?? 0);
    if ($status !== 'Available' || $count < 1 || (int)($row['training_records'] ?? 0) < 1) {
        return false;
    }
    if ($rolling || ($row['method'] ?? '') === 'recursive') {
        if (!in_array($horizon, [7, 14, 30], true) || $count !== $horizon) return false;
    } elseif (($row['method'] ?? '') !== 'one_step' || $horizon !== 1) {
        return false;
    }
    // Paired rolling comparisons include a window only when all methods have
    // valid scores. Zero actual demand is valid and is deliberately retained.
    foreach ($rolling ? ['xgb_', 'last_', 'mean_'] : [''] as $prefix) {
        foreach (['mae', 'rmse', 'smape_percent'] as $metric) {
            $value = $row[$prefix . $metric] ?? null;
            if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0) return false;
            if ($metric === 'smape_percent' && (float)$value > 200) return false;
        }
    }
    return true;
}

function forecastMetricsAccumulate(array &$groups, array $row, string $method, bool $valid, bool $byUnit, bool $rolling): void
{
    $horizon = (int)($row['horizon_days'] ?? 0);
    $unit = trim((string)($row['base_unit'] ?? ''));
    if ($unit === '') $unit = 'Unverified unit';
    $key = json_encode([$method, $horizon, $byUnit ? $unit : null], JSON_THROW_ON_ERROR);
    if (!isset($groups[$key])) {
        $groups[$key] = ['method' => $method, 'horizon_days' => $horizon,
            'base_unit' => $byUnit ? $unit : null, 'testing_records' => 0,
            'available_results' => 0, 'excluded_results' => 0,
            '_mae' => 0.0, '_mse' => 0.0, '_smape' => 0.0, '_items' => [], '_units' => []];
    }
    $group = &$groups[$key];
    if (!$valid) {
        $group['excluded_results']++;
        return;
    }
    $count = (int)$row[$rolling ? 'observed_records' : 'testing_records'];
    $prefix = $rolling ? $method . '_' : '';
    $group['testing_records'] += $count;
    $group['available_results']++;
    $group['_mae'] += $count * (float)$row[$prefix . 'mae'];
    $group['_mse'] += $count * (float)$row[$prefix . 'rmse'] ** 2;
    $group['_smape'] += $count * (float)$row[$prefix . 'smape_percent'];
    $group['_items'][(string)$row['item_id']] = true;
    $group['_units'][$unit] = true;
}

function forecastMetricsFinalize(array $groups): array
{
    $results = [];
    foreach ($groups as $group) {
        $count = $group['testing_records'];
        $group['mae'] = $count ? $group['_mae'] / $count : null;
        $group['rmse'] = $count ? sqrt($group['_mse'] / $count) : null;
        $group['smape_percent'] = $count ? $group['_smape'] / $count : null;
        $group['supplies'] = count($group['_items']);
        $group['base_units'] = array_keys($group['_units']);
        sort($group['base_units'], SORT_STRING);
        unset($group['_mae'], $group['_mse'], $group['_smape'], $group['_items'], $group['_units']);
        $results[] = $group;
    }
    $order = ['recursive' => 0, 'xgb' => 0, 'last' => 1, 'mean' => 2, 'one_step' => 3];
    usort($results, static function (array $a, array $b) use ($order): int {
        // Keep the operational horizons first; the known-patient diagnostic last.
        return [($a['method'] === 'one_step' ? 1 : 0), $a['horizon_days'], $order[$a['method']], $a['base_unit'] ?? '']
            <=> [($b['method'] === 'one_step' ? 1 : 0), $b['horizon_days'], $order[$b['method']], $b['base_unit'] ?? ''];
    });
    return $results;
}

/**
 * Pooled errors over the matching rows. With $unitMap (forecastAccuracyUnitMap)
 * and $mode 'display', each item's MAE/RMSE is first converted to its counting
 * unit (vials, ampules) and the pooled values are computed from those
 * converted per-item values; SMAPE is unit-free and unchanged.
 */
function forecastMetricsSummarize(iterable $rows, bool $rolling = false, ?array $unitMap = null, string $mode = 'base'): array
{
    $overall = [];
    $units = [];
    foreach ($rows as $row) {
        if ($unitMap !== null) {
            $row = forecastAccuracyConvertRow($row, $unitMap, $mode, $rolling);
        }
        if ($rolling) {
            if (!in_array((int)($row['horizon_days'] ?? 0), [7, 14, 30], true)) continue;
            $methods = ['xgb', 'last', 'mean'];
        } else {
            $method = (string)($row['method'] ?? '');
            if (!in_array($method, ['one_step', 'recursive'], true)) continue;
            if ($method === 'one_step' && (int)($row['horizon_days'] ?? 0) !== 1) continue;
            if ($method === 'recursive' && !in_array((int)($row['horizon_days'] ?? 0), [7, 14, 30], true)) continue;
            $methods = [$method];
        }
        $valid = forecastMetricsValid($row, $rolling);
        foreach ($methods as $method) {
            forecastMetricsAccumulate($overall, $row, $method, $valid, false, $rolling);
            forecastMetricsAccumulate($units, $row, $method, $valid, true, $rolling);
        }
    }
    return ['overall' => forecastMetricsFinalize($overall), 'by_unit' => forecastMetricsFinalize($units)];
}

function forecastMetricsLabel(array $row): string
{
    return match ($row['method']) {
        'recursive' => 'Horizon forecast test',
        'one_step' => 'Next-day check',
        'xgb' => 'XGBoost',
        'last' => 'Last usage',
        'mean' => 'Recent mean',
    };
}

function forecastMetricsRender(array $summary, bool $rolling = false, bool $filtered = false): void
{
    $overall = $summary['overall'];
    ?>
    <section class="panel" aria-label="Overall forecast error metrics">
        <div class="table-header"><div><h2><?= $filtered ? 'Overall errors for matching supplies' : 'Overall forecast errors' ?></h2>
            <p><?= $rolling ? 'Paired historical windows: XGBoost and both baselines use the same observations.' : 'Use the 7-, 14- or 30-day forecast test that matches your selected forecast.' ?> Includes all matching supplies across every results page.</p></div></div>
        <p class="mobile-table-hint">Swipe the table sideways to see the overall errors and coverage.</p>
        <div class="table-scroll"><table><thead><tr><th scope="col">Horizon / test</th><th scope="col">SMAPE</th><th scope="col">MAE</th><th scope="col">RMSE</th><th scope="col">Error units</th><th scope="col">Test coverage</th></tr></thead><tbody>
        <?php if (!$overall): ?><tr><td colspan="6" class="empty-cell"><strong>No saved results match these filters</strong></td></tr><?php endif; ?>
        <?php foreach ($overall as $row): ?>
            <tr data-overall-method="<?= accuracyH($row['method']) ?>" data-overall-horizon="<?= (int)$row['horizon_days'] ?>">
                <td><span class="item-name"><?= (int)$row['horizon_days'] ?> day<?= (int)$row['horizon_days'] === 1 ? '' : 's' ?></span><div class="test-count"><?= accuracyH(forecastMetricsLabel($row)) ?></div></td>
                <td class="numeric"><strong><?= accuracyH(accuracyScore($row['smape_percent'])) ?><?= $row['smape_percent'] !== null ? '%' : '' ?></strong></td>
                <td class="numeric"><?= accuracyH(accuracyScore($row['mae'])) ?></td>
                <td class="numeric"><?= accuracyH(accuracyScore($row['rmse'])) ?></td>
                <td><?= accuracyH(count($row['base_units']) > 1 ? 'Mixed units: ' . implode(', ', $row['base_units']) : ($row['base_units'][0] ?? 'Unavailable')) ?></td>
                <td><?= number_format($row['testing_records']) ?> item-day observations<div class="test-count"><?= (int)$row['supplies'] ?> tested supplies · <?= (int)$row['available_results'] ?> complete <?= $rolling ? 'item windows' : 'item tests' ?><br><?= (int)$row['excluded_results'] ?> excluded <?= $rolling ? 'item windows' : 'item tests' ?></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <div style="padding:0 22px 18px">
            <p class="policy-note">Lower errors are better. Valid zero-use observations remain included. Unavailable or incomplete tests are excluded and counted. Each horizon is scored separately. SMAPE is an error percentage, not an accuracy percentage. MAE and RMSE are shown in each supply's counting unit (vials, ampules) unless "Base units (as stored)" is selected; pooled values are computed from those converted per-supply values. Mixed-unit MAE/RMSE are numerical summaries; use the errors by unit below for a single unit.</p>
            <?php if (!$rolling): ?><p class="policy-note">The one-day diagnostic uses the known patient count and previous actual usage on each test day. The 7-, 14- and 30-day tests estimate patient counts from past records and feed their own predicted usage into later days, as the operational forecast does.</p><?php endif; ?>
            <details><summary style="cursor:pointer;font-weight:600">View overall errors by unit</summary>
                <div class="table-scroll"><table><thead><tr><th scope="col">Horizon / test</th><th scope="col">Unit</th><th scope="col">SMAPE</th><th scope="col">MAE</th><th scope="col">RMSE</th><th scope="col">Observations / exclusions</th></tr></thead><tbody>
                <?php foreach ($summary['by_unit'] as $row): ?><tr>
                    <td><?= (int)$row['horizon_days'] ?> day<?= (int)$row['horizon_days'] === 1 ? '' : 's' ?><div class="test-count"><?= accuracyH(forecastMetricsLabel($row)) ?></div></td>
                    <td><?= accuracyH($row['base_unit']) ?></td>
                    <td class="numeric"><?= accuracyH(accuracyScore($row['smape_percent'])) ?><?= $row['smape_percent'] !== null ? '%' : '' ?></td>
                    <td class="numeric"><?= accuracyH(accuracyScore($row['mae'])) ?></td>
                    <td class="numeric"><?= accuracyH(accuracyScore($row['rmse'])) ?></td>
                    <td><?= number_format($row['testing_records']) ?> / <?= (int)$row['excluded_results'] ?> excluded <?= $rolling ? 'item windows' : 'item tests' ?></td>
                </tr><?php endforeach; ?>
                </tbody></table></div>
            </details>
            <p class="policy-note">Totals combine the saved per-supply scores, weighted by observation count; small rounding differences from raw daily errors can occur. Imported or generated history does not by itself establish reliability on future clinic records.</p>
        </div>
    </section>
    <?php
}
