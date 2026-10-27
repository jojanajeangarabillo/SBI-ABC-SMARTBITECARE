<?php
declare(strict_types=1);

require_once __DIR__ . '/report_catalog.php';
require_once __DIR__ . '/workflow_helpers.php';

/*
 * REPORT CENTER: the one report form used on every Reports page.
 * A page gives its reports as entries:
 *   key => [title, description, icon, build(mysqli, params): report array,
 *           dates (true | false: "as of now" report), horizon (bool),
 *           all_branches (bool: Super Admin may pick "All branches")]
 * The form has the report, branch (Super Admin only), date range and, for the
 * forecast, the horizon, with Preview, PDF and CSV buttons. Preview shows all
 * columns on screen (links included); PDF and CSV are generated from the same
 * report array, so they always match. Every export writes an audit entry.
 * Read-only: no report changes data.
 */

const REPORT_CENTER_PREVIEW_ROWS = 300;

/** Entries for the catalog reports a role can open. */
function reportCenterCatalogEntries(int $roleId, ?array $only = null, array $overrides = []): array
{
    $icons = [
        'approval_control' => 'bi-patch-check', 'adjustment_disposal' => 'bi-sliders', 'restock' => 'bi-truck',
        'daily_closing' => 'bi-journal-check', 'wastage' => 'bi-trash3', 'forecast' => 'bi-graph-up-arrow',
        'philhealth_status' => 'bi-shield-plus', 'data_quality' => 'bi-clipboard-check',
    ];
    $entries = [];
    foreach (reportCatalogForRole($roleId) as $key) {
        if ($only !== null && !in_array($key, $only, true)) continue;
        $def = REPORT_CATALOG[$key];
        $entries[$key] = array_replace([
            'title' => $def['title'],
            'description' => $def['description'],
            'icon' => $icons[$key] ?? 'bi-file-earmark-text',
            'dates' => $key !== 'forecast',
            'horizon' => !empty($def['horizon']),
            'all_branches' => $roleId === 1 && $key === 'approval_control',
            'build' => static fn(mysqli $conn, array $p): array => reportBuild($conn, $key, $p),
        ], $overrides[$key] ?? []);
    }
    return $entries;
}

/** A Y-m-d date from the request, or null. */
function reportCenterDate($value): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : null;
}

/**
 * Read the request, and for format=pdf|csv send the file (and stop).
 * $ctx: user_id, role_id, branch_id (the user's branch), branches [id => name]
 *       (Super Admin selector), link_base, module (audit), page (form action)
 * Returns the page state: entries, key, from, to, horizon, branch_id, report, meta, error.
 */
function reportCenterRun(mysqli $conn, array $entries, array $ctx): array
{
    $state = [
        'entries' => $entries, 'ctx' => $ctx, 'key' => '', 'format' => '', 'report' => null, 'meta' => null, 'error' => '',
        'from' => date('Y-m-d', strtotime('-29 days')), 'to' => date('Y-m-d'), 'horizon' => 0,
        'branch_id' => (int)$ctx['role_id'] === 1 ? '' : (string)($ctx['branch_id'] ?? ''),
    ];
    if (!isset($_GET['report'])) return $state;
    $key = (string)$_GET['report'];
    if (!isset($entries[$key])) { $state['error'] = 'Choose a report.'; return $state; }
    $entry = $entries[$key];
    $state['key'] = $key;
    $format = strtolower((string)($_GET['format'] ?? 'preview'));
    $state['format'] = in_array($format, ['pdf', 'csv'], true) ? $format : 'preview';

    // Dates: both empty means all dates; otherwise both must be valid.
    $rawFrom = trim((string)($_GET['from'] ?? ''));
    $rawTo = trim((string)($_GET['to'] ?? ''));
    $from = reportCenterDate($rawFrom);
    $to = reportCenterDate($rawTo);
    $state['from'] = $rawFrom;
    $state['to'] = $rawTo;
    if ($entry['dates']) {
        if (($rawFrom !== '' && !$from) || ($rawTo !== '' && !$to)) { $state['error'] = 'Enter valid dates.'; return $state; }
        if ($from && $to && $from > $to) { $state['error'] = 'The start date must be on or before the end date.'; return $state; }
    }

    // Branch: Super Admin chooses; everyone else gets their own branch.
    if ((int)$ctx['role_id'] === 1) {
        $branchId = trim((string)($_GET['branch'] ?? ''));
        if ($branchId !== '' && !isset($ctx['branches'][$branchId])) { $state['error'] = 'Choose a valid branch.'; return $state; }
        if ($branchId === '' && empty($entry['all_branches'])) { $state['error'] = 'Choose a branch for this report.'; return $state; }
    } else {
        $branchId = (string)($ctx['branch_id'] ?? '');
        if ($branchId === '') { $state['error'] = 'No branch is assigned to your account.'; return $state; }
    }
    $state['branch_id'] = $branchId;

    $horizon = (int)($_GET['horizon'] ?? 0);
    if (!$entry['horizon'] || !in_array($horizon, [7, 14, 30], true)) $horizon = 0;
    $state['horizon'] = $horizon;

    $params = [
        'branch_id' => $branchId === '' ? null : $branchId,
        'from' => $entry['dates'] ? ($from ?? '2000-01-01') : date('Y-m-d'),
        'to' => $entry['dates'] ? ($to ?? date('Y-m-d', strtotime('+1 year'))) : date('Y-m-d'),
        'all_dates' => $entry['dates'] && !$from && !$to,
        'raw_from' => $from, 'raw_to' => $to,
        'horizon' => $horizon ?: null,
        'link_base' => (string)($ctx['link_base'] ?? ''),
        'role_id' => (int)$ctx['role_id'],
        'user_id' => (int)$ctx['user_id'],
    ];
    try {
        $report = ($entry['build'])($conn, $params);
    } catch (Throwable $e) {
        error_log('Report ' . $key . ' failed: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
        $state['error'] = 'The report could not be generated. Please try again or contact the system administrator.';
        return $state;
    }
    $report['title'] = $report['title'] ?? $entry['title'];
    if (!isset($report['orientation'])) $report['orientation'] = 'P';

    $period = $report['period_text'] ?? ($entry['dates']
        ? (($from || $to) ? reportPeriodText($from ?? $to, $to ?? $from) : 'All dates')
        : 'As of ' . date('M d, Y h:i A'));
    $filters = [];
    if ($horizon) $filters[] = 'Horizon: ' . $horizon . ' days';
    if (!empty($report['filters_text'])) $filters[] = (string)$report['filters_text'];
    $meta = reportMeta($conn, (int)$ctx['user_id'], reportBranchName($conn, $branchId === '' ? null : $branchId), $period, implode(' · ', $filters));
    $state['report'] = $report;
    $state['meta'] = $meta;

    if ($state['format'] !== 'preview') {
        $auditBranch = $branchId !== '' ? $branchId : (string)($ctx['branch_id'] ?? '');
        workflowAudit($conn, (int)$ctx['user_id'], $auditBranch,
            'Exported ' . $report['title'] . ' (' . strtoupper($state['format']) . ') - Branch: ' . $meta['branch'] . ' - Period: ' . $period
            . ($meta['filters'] !== '' ? ' - ' . $meta['filters'] : ''), (string)($ctx['module'] ?? 'Reports'));
        $suffix = ($branchId !== '' ? $branchId : 'All_branches') . '_' . date('Ymd_His');
        reportSend($report, $meta, $state['format'], $report['title'] . '_' . $suffix);
    }
    return $state;
}

function reportCenterH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Cell HTML for the preview (links open the record). */
function reportCenterCellHtml($cell): string
{
    if (is_array($cell)) {
        $text = reportCenterH($cell['text'] ?? '');
        return !empty($cell['url']) ? '<a href="' . reportCenterH($cell['url']) . '" class="btn btn-sm btn-outline-primary py-0">' . $text . '</a>' : $text;
    }
    return nl2br(reportCenterH($cell));
}

/** The report form, the list of reports and the preview. */
function reportCenterRender(array $state, string $heading = 'Generate a report'): string
{
    $entries = $state['entries'];
    $ctx = $state['ctx'];
    $isSuper = (int)$ctx['role_id'] === 1;
    $action = reportCenterH($ctx['page'] ?? basename((string)($_SERVER['PHP_SELF'] ?? '')));
    $selected = $state['key'];
    ob_start();
    ?>
<style>
.rc-card{background:#fff;border:1px solid #e3e7f3;border-radius:14px;padding:22px 24px;margin-bottom:22px;box-shadow:0 2px 10px rgba(43,58,140,.05)}
.rc-card h5{color:#2B3A8C;font-weight:700;margin-bottom:4px}
.rc-card .rc-sub{color:#6b7280;font-size:13px;margin-bottom:16px}
.rc-form label{font-size:12px;font-weight:600;color:#374151;margin-bottom:4px}
.rc-actions .btn{min-width:110px}
.rc-list td{vertical-align:middle;font-size:14px}
.rc-list .rc-desc{color:#6b7280;font-size:13px}
.rc-preview table{font-size:12.5px}
.rc-preview thead th{background:#2B3A8C;color:#fff;font-weight:600;position:sticky;top:0;z-index:1}
.rc-preview .rc-total td{font-weight:700;background:#eef1fb}
.rc-meta{font-size:13px;color:#374151}
.rc-sum{display:flex;flex-wrap:wrap;gap:10px;margin:10px 0 16px}
.rc-sum div{background:#f2f4fb;border-radius:10px;padding:8px 14px;min-width:140px}
.rc-sum small{display:block;color:#6b7280;font-size:11.5px}
.rc-sum strong{color:#2B3A8C}
.rc-scroll{max-height:560px;overflow:auto;border:1px solid #e5e7eb;border-radius:8px}
</style>
<div class="rc-card" id="reportCenter">
  <h5><i class="bi bi-file-earmark-bar-graph me-2"></i><?php echo reportCenterH($heading); ?></h5>
  <div class="rc-sub">Live data. Preview on screen, or download a PDF or CSV (Excel) with the same columns and all rows. Quantities are in vials, ampules or pieces.</div>
  <?php if ($state['error'] !== ''): ?>
    <div class="alert alert-danger py-2" role="alert"><?php echo reportCenterH($state['error']); ?></div>
  <?php endif; ?>
  <form method="GET" action="<?php echo $action; ?>" class="rc-form" id="rcForm">
    <div class="row g-3 align-items-end">
      <div class="col-lg-4 col-md-6">
        <label for="rcReport">Report</label>
        <select name="report" id="rcReport" class="form-select" required>
          <option value="">-- Select report --</option>
          <?php foreach ($entries as $key => $e): ?>
            <option value="<?php echo reportCenterH($key); ?>" data-dates="<?php echo $e['dates'] ? '1' : '0'; ?>" data-horizon="<?php echo $e['horizon'] ? '1' : '0'; ?>" data-all="<?php echo !empty($e['all_branches']) ? '1' : '0'; ?>"<?php echo $key === $selected ? ' selected' : ''; ?>><?php echo reportCenterH($e['title']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($isSuper): ?>
      <div class="col-lg-2 col-md-6">
        <label for="rcBranch">Branch</label>
        <select name="branch" id="rcBranch" class="form-select">
          <option value="">All branches</option>
          <?php foreach ($ctx['branches'] ?? [] as $bid => $bname): ?>
            <option value="<?php echo reportCenterH($bid); ?>"<?php echo (string)$bid === (string)$state['branch_id'] ? ' selected' : ''; ?>><?php echo reportCenterH($bname); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-lg-2 col-md-3 col-6 rc-date">
        <label for="rcFrom">From</label>
        <input type="date" name="from" id="rcFrom" class="form-control" value="<?php echo reportCenterH($state['from']); ?>">
      </div>
      <div class="col-lg-2 col-md-3 col-6 rc-date">
        <label for="rcTo">To</label>
        <input type="date" name="to" id="rcTo" class="form-control" value="<?php echo reportCenterH($state['to']); ?>">
      </div>
      <div class="col-lg-2 col-md-3 col-6 rc-horizon">
        <label for="rcHorizon">Horizon</label>
        <select name="horizon" id="rcHorizon" class="form-select">
          <option value="">7, 14 and 30 days</option>
          <?php foreach ([7, 14, 30] as $h): ?>
            <option value="<?php echo $h; ?>"<?php echo (int)$state['horizon'] === $h ? ' selected' : ''; ?>><?php echo $h; ?> days</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="rc-actions d-flex flex-wrap gap-2 mt-3">
      <button type="submit" name="format" value="preview" class="btn btn-outline-primary"><i class="bi bi-eye me-1"></i>Preview</button>
      <button type="submit" name="format" value="pdf" class="btn btn-primary" style="background:#2B3A8C;border-color:#2B3A8C"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
      <button type="submit" name="format" value="csv" class="btn btn-success"><i class="bi bi-filetype-csv me-1"></i>CSV (Excel)</button>
      <span class="text-muted small align-self-center" id="rcHint">Leave both dates empty for all dates.</span>
    </div>
  </form>
</div>

<div class="rc-card">
  <h5><i class="bi bi-list-ul me-2"></i>Available reports</h5>
  <div class="table-responsive">
    <table class="table rc-list mb-0">
      <thead><tr><th>Report</th><th>Description</th><th class="text-end" style="min-width:250px">Download</th></tr></thead>
      <tbody>
      <?php foreach ($entries as $key => $e): ?>
        <tr>
          <td class="fw-semibold"><i class="bi <?php echo reportCenterH($e['icon']); ?> me-2"></i><?php echo reportCenterH($e['title']); ?></td>
          <td class="rc-desc"><?php echo reportCenterH($e['description']); ?></td>
          <td class="text-end text-nowrap">
            <button type="button" class="btn btn-sm btn-outline-primary" data-rc-run="<?php echo reportCenterH($key); ?>" data-rc-format="preview">Preview</button>
            <button type="button" class="btn btn-sm btn-primary" style="background:#2B3A8C;border-color:#2B3A8C" data-rc-run="<?php echo reportCenterH($key); ?>" data-rc-format="pdf">PDF</button>
            <button type="button" class="btn btn-sm btn-success" data-rc-run="<?php echo reportCenterH($key); ?>" data-rc-format="csv">CSV</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($state['report'] !== null): $report = $state['report']; $meta = $state['meta']; ?>
<div class="rc-card rc-preview" id="rcPreview">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
    <div>
      <h5 class="mb-1"><?php echo reportCenterH($report['title']); ?></h5>
      <div class="rc-meta">Branch: <strong><?php echo reportCenterH($meta['branch']); ?></strong> · Period: <strong><?php echo reportCenterH($meta['period']); ?></strong><?php if ($meta['filters'] !== ''): ?> · <?php echo reportCenterH($meta['filters']); ?><?php endif; ?></div>
      <div class="rc-meta text-muted">Generated by <?php echo reportCenterH($meta['generated_by']); ?> on <?php echo reportCenterH($meta['generated_at']); ?> (Manila time)</div>
    </div>
    <div class="d-flex gap-2">
      <button type="button" class="btn btn-sm btn-primary" style="background:#2B3A8C;border-color:#2B3A8C" data-rc-run="<?php echo reportCenterH($state['key']); ?>" data-rc-format="pdf"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
      <button type="button" class="btn btn-sm btn-success" data-rc-run="<?php echo reportCenterH($state['key']); ?>" data-rc-format="csv"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
    </div>
  </div>
  <?php if (!empty($report['summary'])): ?>
    <div class="rc-sum">
      <?php foreach ($report['summary'] as $label => $value): ?>
        <div><small><?php echo reportCenterH($label); ?></small><strong><?php echo reportCenterH($value); ?></strong></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php foreach ($report['sections'] ?? [] as $section): $count = count($section['rows']); ?>
    <?php if (!empty($section['title'])): ?><h6 class="mt-3 fw-bold" style="color:#2B3A8C"><?php echo reportCenterH($section['title']); ?> <span class="text-muted fw-normal small">(<?php echo $count; ?> row<?php echo $count === 1 ? '' : 's'; ?>)</span></h6><?php endif; ?>
    <div class="rc-scroll mb-2">
      <table class="table table-sm table-striped mb-0">
        <thead><tr><?php foreach ($section['columns'] as $c): ?><th class="<?php echo ($c['align'] ?? '') === 'R' ? 'text-end' : (($c['align'] ?? '') === 'C' ? 'text-center' : ''); ?>"><?php echo reportCenterH($c['label']); ?></th><?php endforeach; ?></tr></thead>
        <tbody>
          <?php if (!$section['rows']): ?>
            <tr><td colspan="<?php echo count($section['columns']); ?>" class="text-muted"><?php echo reportCenterH($section['empty'] ?? 'No records match the selected filters.'); ?></td></tr>
          <?php endif; ?>
          <?php foreach (array_slice($section['rows'], 0, REPORT_CENTER_PREVIEW_ROWS) as $row): ?>
            <tr><?php foreach ($section['columns'] as $i => $c): ?><td class="<?php echo ($c['align'] ?? '') === 'R' ? 'text-end' : (($c['align'] ?? '') === 'C' ? 'text-center' : ''); ?>"><?php echo reportCenterCellHtml($row[$i] ?? ''); ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
          <?php if (isset($section['totals'])): ?>
            <tr class="rc-total"><?php foreach ($section['columns'] as $i => $c): ?><td class="<?php echo ($c['align'] ?? '') === 'R' ? 'text-end' : ''; ?>"><?php echo reportCenterH($section['totals'][$i] ?? ''); ?></td><?php endforeach; ?></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($count > REPORT_CENTER_PREVIEW_ROWS): ?><div class="text-muted small mb-2">Showing the first <?php echo REPORT_CENTER_PREVIEW_ROWS; ?> of <?php echo $count; ?> rows. The PDF and CSV include all rows.</div><?php endif; ?>
  <?php endforeach; ?>
  <?php if (!empty($report['notes'])): ?>
    <div class="small text-muted mt-2"><strong><?php echo reportCenterH($report['notes_title'] ?? 'Notes'); ?>:</strong>
      <ul class="mb-0"><?php foreach ($report['notes'] as $n): ?><li><?php echo reportCenterH($n); ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<script>
(function () {
  const form = document.getElementById('rcForm');
  if (!form) return;
  const select = document.getElementById('rcReport');
  const toggle = () => {
    const opt = select.options[select.selectedIndex];
    const dates = !opt || !opt.value || opt.dataset.dates === '1';
    const horizon = !!opt && opt.dataset.horizon === '1';
    form.querySelectorAll('.rc-date').forEach(el => { el.style.display = dates ? '' : 'none'; el.querySelector('input').disabled = !dates; });
    const h = form.querySelector('.rc-horizon');
    h.style.display = horizon ? '' : 'none';
    h.querySelector('select').disabled = !horizon;
    const hint = document.getElementById('rcHint');
    if (hint) hint.textContent = dates ? 'Leave both dates empty for all dates.' : 'Shows the latest saved results; no new forecast is run.';
  };
  select.addEventListener('change', toggle);
  toggle();
  document.querySelectorAll('[data-rc-run]').forEach(btn => btn.addEventListener('click', () => {
    select.value = btn.dataset.rcRun;
    toggle();
    let input = form.querySelector('input[name="format"][type="hidden"]');
    if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = 'format'; form.appendChild(input); }
    input.value = btn.dataset.rcFormat;
    form.submit();
    input.remove();
  }));
<?php if ($state['report'] !== null): ?>
  const preview = document.getElementById('rcPreview');
  if (preview) preview.scrollIntoView({ behavior: 'smooth', block: 'start' });
<?php endif; ?>
})();
</script>
    <?php
    return (string)ob_get_clean();
}
