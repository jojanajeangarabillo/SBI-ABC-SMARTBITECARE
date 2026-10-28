<?php
declare(strict_types=1);

/*
 * SERVER-SIDE PAGINATION, one style for every long table (the Supply
 * Forecasting look: "Showing 1-10 of 35", page buttons, rows per page
 * 10/25/50). The current query string (filters, tabs, search) is kept on
 * every link; only this table's page / per_page parameters change.
 *
 *   $pg = paginationFromRequest('logs_', 10);      // reads logs_page, logs_per_page
 *   $pg = paginationApply($pg, $totalMatchingRows);  // clamps the page, sets offset
 *   ... "LIMIT {$pg['per_page']} OFFSET {$pg['offset']}" ...
 *   echo paginationRender($pg, '#logs');
 *
 * Counts, badges and exports must use the full filtered query, never the page.
 */

const PAGINATION_SIZES = [10, 25, 50];
// One-time parameters a pagination link never repeats (exports, AJAX calls).
const PAGINATION_DROP_PARAMS = ['export', 'format', 'generate_pdf', 'export_csv', 'inline', 'ajax'];

/** Page and rows per page from the request ($prefix lets one page have several tables). */
function paginationFromRequest(string $prefix = '', int $defaultPerPage = 10, array $sizes = PAGINATION_SIZES): array
{
    $perPage = (int)($_GET[$prefix . 'per_page'] ?? $defaultPerPage);
    if (!in_array($perPage, $sizes, true)) $perPage = in_array($defaultPerPage, $sizes, true) ? $defaultPerPage : $sizes[0];
    $page = (int)($_GET[$prefix . 'page'] ?? 1);
    return ['prefix' => $prefix, 'page' => max(1, $page), 'per_page' => $perPage, 'sizes' => $sizes,
        'total' => 0, 'pages' => 1, 'offset' => 0, 'from' => 0, 'to' => 0];
}

/** Fill in the totals for $total matching rows; a page past the end shows the last page. */
function paginationApply(array $pg, int $total): array
{
    $pg['total'] = max(0, $total);
    $pg['pages'] = max(1, (int)ceil($pg['total'] / $pg['per_page']));
    $pg['page'] = min(max(1, (int)$pg['page']), $pg['pages']);
    $pg['offset'] = ($pg['page'] - 1) * $pg['per_page'];
    $pg['from'] = $pg['total'] ? $pg['offset'] + 1 : 0;
    $pg['to'] = min($pg['offset'] + $pg['per_page'], $pg['total']);
    return $pg;
}

/** The rows of one page of an array that is already complete (and correctly counted). */
function paginationSlice(array $rows, array &$pg): array
{
    $pg = paginationApply($pg, count($rows));
    return array_slice($rows, $pg['offset'], $pg['per_page']);
}

/** Current URL with this table's page parameters replaced (other parameters kept). */
function paginationUrl(array $pg, array $changes, string $anchor = '', array $extra = []): string
{
    $query = $_GET;
    // $extra: other parameters to set on the link (null removes one), e.g. the tab to reopen.
    foreach ($extra as $key => $value) {
        if ($value === null) unset($query[$key]); else $query[$key] = $value;
    }
    foreach ($changes as $key => $value) {
        $name = $pg['prefix'] . $key;
        if ($value === null) unset($query[$name]); else $query[$name] = $value;
    }
    // A pagination link never repeats an export or a one-time action.
    foreach (PAGINATION_DROP_PARAMS as $drop) unset($query[$drop]);
    $path = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') ?: basename((string)($_SERVER['PHP_SELF'] ?? ''));
    $path = basename($path);
    return $path . ($query ? '?' . http_build_query($query) : '') . $anchor;
}

/** "Showing 1-10 of 35", page buttons and the rows-per-page choice. */
function paginationRender(array $pg, string $anchor = '', string $noun = 'rows', array $extra = []): string
{
    $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $page = (int)$pg['page'];
    $pages = (int)$pg['pages'];
    $out = '<div class="sbc-pagination">';
    $out .= '<div class="sbc-pagination-summary">' . ($pg['total']
        ? 'Showing ' . number_format($pg['from']) . '–' . number_format($pg['to']) . ' of ' . number_format($pg['total']) . ' ' . $h($noun)
        : 'No ' . $h($noun)) . '</div>';
    $out .= '<div class="sbc-pagination-controls">';
    if ($pages > 1) {
        $out .= '<nav class="sbc-pagination-pages" aria-label="Pages">';
        $link = static function (int $target, string $label, string $aria, bool $disabled = false, bool $active = false) use ($pg, $anchor, $h, $extra): string {
            if ($disabled) return '<span class="sbc-page disabled" aria-disabled="true" aria-label="' . $h($aria) . '">' . $label . '</span>';
            return '<a class="sbc-page' . ($active ? ' active' : '') . '"' . ($active ? ' aria-current="page"' : '')
                . ' href="' . $h(paginationUrl($pg, ['page' => $target], $anchor, $extra)) . '" aria-label="' . $h($aria) . '">' . $label . '</a>';
        };
        $out .= $link($page - 1, '<i class="bi bi-chevron-left"></i>', 'Previous page', $page <= 1);
        $numbers = array_unique(array_filter([1, $page - 1, $page, $page + 1, $pages], static fn($n) => $n >= 1 && $n <= $pages));
        sort($numbers);
        $previous = 0;
        foreach ($numbers as $n) {
            if ($previous && $n > $previous + 1) $out .= '<span class="sbc-page gap">…</span>';
            $out .= $link($n, (string)$n, 'Page ' . $n, false, $n === $page);
            $previous = $n;
        }
        $out .= $link($page + 1, '<i class="bi bi-chevron-right"></i>', 'Next page', $page >= $pages);
        $out .= '</nav>';
    }
    // Rows per page: a tiny GET form that keeps every other parameter.
    $out .= '<form method="get" class="sbc-per-page" action="' . $h(strtok(basename((string)($_SERVER['PHP_SELF'] ?? '')), '?')) . $h($anchor) . '">';
    $formQuery = $_GET;
    foreach ($extra as $key => $value) {
        if ($value === null) unset($formQuery[$key]); else $formQuery[$key] = $value;
    }
    foreach ($formQuery as $key => $value) {
        if (in_array($key, array_merge([$pg['prefix'] . 'page', $pg['prefix'] . 'per_page'], PAGINATION_DROP_PARAMS), true)) continue;
        foreach ((array)$value as $sub => $item) {
            if (is_array($item)) continue;
            $name = is_array($value) ? $key . '[' . (is_int($sub) ? '' : $sub) . ']' : $key;
            $out .= '<input type="hidden" name="' . $h($name) . '" value="' . $h($item) . '">';
        }
    }
    $out .= '<label>Rows <select name="' . $h($pg['prefix'] . 'per_page') . '" onchange="this.form.submit()">';
    foreach ($pg['sizes'] as $size) {
        $out .= '<option value="' . (int)$size . '"' . ((int)$size === (int)$pg['per_page'] ? ' selected' : '') . '>' . (int)$size . '</option>';
    }
    $out .= '</select></label><noscript><button type="submit">Go</button></noscript></form>';
    $out .= '</div></div>';
    return paginationStyles() . $out;
}

/** The pagination styles (printed once per page). */
function paginationStyles(): string
{
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<style>
.sbc-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 18px;border-top:1px solid #edf0f5;background:#fff}
.sbc-pagination-summary{color:#71809d;font-size:13px}
.sbc-pagination-controls{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.sbc-pagination-pages{display:flex;align-items:center;gap:5px;flex-wrap:wrap}
.sbc-page{display:inline-flex;align-items:center;justify-content:center;min-width:36px;height:36px;padding:0 10px;border:1px solid #dfe4ee;border-radius:9px;background:#fff;color:#2B3A8C;font-size:13px;font-weight:700;text-decoration:none}
a.sbc-page:hover,.sbc-page.active{color:#fff;background:#2B3A8C;border-color:#2B3A8C}
.sbc-page.disabled{opacity:.45;cursor:not-allowed}
.sbc-page.gap{border:0;background:transparent;min-width:20px;padding:0}
.sbc-per-page{margin:0}.sbc-per-page label{display:flex;align-items:center;gap:6px;margin:0;color:#71809d;font-size:13px}
.sbc-per-page select{height:36px;padding:0 8px;border:1px solid #dfe4ee;border-radius:9px;color:#2B3A8C;font-weight:600;background:#fff}
</style>';
}
