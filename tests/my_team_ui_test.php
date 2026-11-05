<?php
declare(strict_types=1);
// Static source checks for the My Team modern UI (SPEC-my-team-ui.md): no bootstrap, request or database.
$fail = 0; $pass = 0;
function check(string $label, bool $ok): void { global $fail, $pass; echo ($ok ? 'PASS ' : 'FAIL ').$label."\n"; $ok ? $pass++ : $fail++; }
$index = (string)file_get_contents(__DIR__.'/../index.php');
$css = (string)file_get_contents(__DIR__.'/../public/assets/hris-modern.css');
$shell = (string)file_get_contents(__DIR__.'/../app/View.php');
$s = strpos($index, "if (\$page === 'employee-team')");
$e = $s === false ? false : strpos($index, 'render_portal_footer(); exit;', $s);
$tpl = ($s === false || $e === false) ? '' : substr($index, $s, $e - $s);
$cs = strpos($css, '/* My Team (.myteam)');
$block = $cs === false ? '' : substr($css, $cs);

// 1. Populated roster: five fields, emphasis, escaped output, consistent dash.
check('tpl: scoped under .myteam inside the shared panel', str_contains($tpl, 'class="panel myteam"'));
check('tpl: description line', str_contains($tpl, 'View the people assigned to your team.'));
foreach (['Employee code', 'Name', 'Position', 'Area / Branch', 'Reporting to'] as $h)
    check("tpl: column $h kept", str_contains($tpl, '<th scope="col">'.$h.'</th>'));
check('tpl: name is the strongest cell', str_contains($tpl, 'class="myteam-name"'));
check('tpl: code cell is scannable', str_contains($tpl, 'class="myteam-code"'));
check('tpl: every dynamic value is escaped', substr_count($tpl, 'e(') >= 6 && !preg_match('/<\?=\s*\$(m|team|teamSearch)\s*(\[|\?>)/', $tpl));
check('tpl: missing optional fields show a dash', str_contains($tpl, "'—'"));
check('tpl: count badge says matching results while searching', str_contains($tpl, 'matching result') && str_contains($tpl, 'myteam-count'));

// 2. Search, clear, paging: scope untouched.
check('tpl: visible label tied to the input', str_contains($tpl, '<label for="teamSearch">') && str_contains($tpl, 'id="teamSearch"'));
check('tpl: search form submits with Enter and keeps the page param', str_contains($tpl, 'role="search"') && str_contains($tpl, 'name="page" value="employee-team"'));
check('tpl: primary Search button', str_contains($tpl, 'class="btn primary" type="submit">Search'));
check('tpl: Clear link only while searching, drops q and p', str_contains($tpl, "\$teamSearch!==''") && str_contains($tpl, 'myteam-clear') && str_contains($tpl, "url('employee-team')"));
check('tpl: paging keeps q, route and scope call unchanged', str_contains($tpl, "pager_html(\$team['total'],\$pageNo,\$perPage,'employee-team',\$teamSearch!==''?['q'=>\$teamSearch]:[])") && substr_count($tpl, 'EmployeeTeamService::roster(') === 2);
check('tpl: permission guard kept, no edit or export links', str_contains($tpl, "Auth::requirePermission('employees.view_team')") && !preg_match('/href="[^"]*(employee-201|export|download|assign)/i', $tpl));

// 3. Empty states, mobile semantics, keyboard.
check('tpl: no-assignment message points to HR', str_contains($tpl, 'Contact HR to assign people to your team.'));
check('tpl: no-result message points to Clear', str_contains($tpl, 'No team members match your search.') && str_contains($tpl, 'Clear the search'));
check('tpl: table keeps ARIA table semantics for the stacked layout', str_contains($tpl, 'role="table"') && str_contains($tpl, 'role="row"') && str_contains($tpl, 'role="cell"'));
check('tpl: mobile labels are decorative so nothing is read twice', str_contains($tpl, 'class="myteam-lbl" aria-hidden="true"'));
check('css: block exists and every rule is scoped to .myteam', $block !== '' && !array_filter(array_map('trim', explode("\n", $block)), fn($l) => $l !== '' && !str_starts_with($l, '/*') && !str_starts_with($l, '.myteam') && !str_starts_with($l, '@media') && $l !== '}'));
check('css: tokens only, no raw hex colours', $block !== '' && !preg_match('/#[0-9a-fA-F]{3,8}\b/', $block));
check('css: 44px touch targets on input, button and Clear', substr_count($block, 'min-height:44px') >= 3);
check('css: visible focus', str_contains($block, ':focus-visible'));
check('css: stacked rows at phone width with no forced min-width', str_contains($block, '@media(max-width:720px)') && str_contains($block, 'attr(') === false && !str_contains($block, 'min-width:7') && !str_contains($block, 'min-width:8'));
check('css: long values wrap', str_contains($block, 'overflow-wrap:anywhere'));
check('css: shared table and field styles untouched', str_contains($css, '.phase3a-table{width:100%;border-collapse:collapse;min-width:820px}') && !str_contains($block, '.field{') && !str_contains($block, 'phase3a'));
check('shell: stylesheet version bumped', str_contains($shell, 'hris-modern.css?v=20261105-my-team-ui4'));

// 4. v3 modern pass (addendum v3): decorative avatar, code chip, ghost Clear, calm header, motion guard.
check('v3 tpl: initials avatar is decorative and escaped', str_contains($tpl, 'class="myteam-avatar" aria-hidden="true"><?=e($ini('));
check('v3 tpl: stored name still printed as-is beside the avatar', str_contains($tpl, "e(\$dash(trim(\$m['first_name'].' '.\$m['last_name'])))"));
check('v3 tpl: employee code shown as a chip', str_contains($tpl, 'class="myteam-chip"'));
check('v3 tpl: Clear is a ghost button', str_contains($tpl, 'class="btn myteam-clear"'));
check('v3 tpl: empty state has a decorative icon', str_contains($tpl, 'class="myteam-empty-ico" viewBox="0 0 24 24" aria-hidden="true"'));
check('v3 css: sentence-case header (no uppercase tracking)', $block !== '' && !str_contains($block, 'text-transform:uppercase'));
check('v3 css: hover transitions respect reduced motion', str_contains($block, 'transition:') && str_contains($block, 'prefers-reduced-motion'));
check('v3 css: panel uses shared radius and shadow tokens', str_contains($block, 'var(--hris-radius)') && str_contains($block, 'var(--hris-shadow)'));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
