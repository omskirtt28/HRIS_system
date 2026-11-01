<?php
declare(strict_types=1);
// Static source checks for the HR dashboard v2 look: no bootstrap, request or database.
$fail = 0;
function check(string $label, bool $ok): void { global $fail; echo ($ok ? 'PASS ' : 'FAIL ').$label."\n"; if (!$ok) $fail++; }
$view = (string)file_get_contents(__DIR__.'/../app/HrDashboardViews.php');
$css = (string)file_get_contents(__DIR__.'/../public/assets/hr-ops.css');
$modern = (string)file_get_contents(__DIR__.'/../public/assets/hris-modern.css');
$data = (string)file_get_contents(__DIR__.'/../app/HrDashboard.php');
$start = strpos($css, '/* HR dashboard v2 (.hd2)');
$block = $start === false ? '' : substr($css, $start);

check('view: page is scoped under .hd2', str_contains($view, 'class="hd2"') && str_contains($view, 'render_portal_footer'));
check('view: slim header with one h1, primary and secondary action', substr_count($view, '<h1>') === 1 && str_contains($view, 'hd-head') && str_contains($view, 'Open HR Request Queue') && str_contains($view, 'Employee directory'));
check('view: period is a segmented control with aria-current and a label', str_contains($view, 'hd-segctl') && str_contains($view, 'aria-current') && str_contains($view, 'aria-label="Period"'));
check('view: company filter keeps the period when it submits', str_contains($view, 'name="period"') && str_contains($view, 'name="company"') && str_contains($view, 'hd-filterbar'));
check('view: KPI cards split in Needs action and Overview tiers', str_contains($view, 'hd-tier-act') && str_contains($view, 'hd-tier-ov') && str_contains($view, 'Needs action') && str_contains($view, 'Overview'));
check('view: KPI card class kept exactly (older tests count it)', str_contains($view, 'class="metric-card hd-kpi"') && substr_count($view, 'hd_kpi(') >= 7);
check('view: delta keeps text and an arrow, not colour alone', str_contains($view, "'▲'") && str_contains($view, "'▼'") && str_contains($view, "\$delta['text']"));
check('view: right column is three cards (oldest, incident, setup)', str_contains($view, 'Oldest pending requests') && str_contains($view, 'Setup warnings') && str_contains($view, 'hd-stack') && str_contains($view, 'All clear'));
check('view: pulse draws no bar for a zero stage and has an empty state', str_contains($view, 'No applicants are in the pipeline yet.') && str_contains($view, 'hd-pulse-zero'));
check('view: old strings other tests rely on are kept', str_contains($view, 'Requests by type') && str_contains($view, 'How long requests have waited') && str_contains($view, 'Latest Incident Feed') && str_contains($view, 'pulse-bars compact') && str_contains($view, 'No requests were filed in this period.') && str_contains($view, 'Nothing is waiting for HR.') && str_contains($view, 'No interviews are scheduled today.'));
check('view: panel links are text links', substr_count($view, 'class="panel-link hd-link"') >= 4 && !str_contains($view, 'class="panel-link" '));
check('view: stylesheet version bumped', str_contains($view, 'hr-ops.css?v=20261031-hd2'));
$lines = array_filter(array_map('trim', explode("
", $block)), fn($l) => $l !== '' && !str_starts_with($l, '/*'));
check('css: v2 block exists and every rule is scoped to .hd2', $block !== '' && !array_filter($lines, fn($l) => !str_starts_with($l, '.hd2') && !str_starts_with($l, '@media')));
check('css: v2 block uses tokens only (no raw hex colours)', $block !== '' && !preg_match('/#[0-9a-fA-F]{3,8}\b/', $block));
check('css: focus-visible on cards, links and the segmented control', str_contains($block, '.hd2 :where(') && str_contains($block, ':focus-visible'));
check('css: reduced motion respected', str_contains($block, 'prefers-reduced-motion:reduce'));
check('css: responsive at 900 and 620', str_contains($block, '@media(max-width:900px)') && str_contains($block, '@media(max-width:620px)'));
check('css: segmented control and tiers defined', str_contains($block, '.hd2 .hd-segctl') && str_contains($block, '.hd2 .hd-tier-act') && str_contains($block, '.hd2 .hd-tier-ov'));
check('css: panel link pill removed only inside .hd2', str_contains($block, '.hd2 .panel-link') && !str_contains($modern, '.hd2'));
check('data layer untouched: queries and delta text still in HrDashboard', str_contains($data, 'vs previous period') && str_contains($data, 'function data('));
exit($fail ? 1 : 0);
