<?php
declare(strict_types=1);

/**
 * My Profile (read-only self-service). Pure checks, no database needed:
 *   C:\xampp\php\php.exe tests\employee_profile_test.php
 * Later slices (own-data-only routes, masked ID reveal) add DB scenarios guarded by HRIS_TEST_DB.
 */

require dirname(__DIR__).'/app/functions.php';
require dirname(__DIR__).'/app/EmployeeProfileService.php';
require dirname(__DIR__).'/app/EmployeeProfileViews.php';

$fail = 0;
function check(string $label, bool $ok): void { global $fail; echo ($ok ? 'PASS ' : 'FAIL ').$label."\n"; if (!$ok) $fail++; }

$now = new DateTimeImmutable('2026-10-31');
check('tenure: no date', EmployeeProfileService::tenure(null, $now) === '');
check('tenure: future date', EmployeeProfileService::tenure('2027-01-01', $now) === '');
check('tenure: under a month', EmployeeProfileService::tenure('2026-10-20', $now) === 'Less than a month');
check('tenure: 1 month', EmployeeProfileService::tenure('2026-09-30', $now) === '1 month');
check('tenure: years and months', EmployeeProfileService::tenure('2024-09-24', $now) === '2 years 1 month');
check('tenure: whole years', EmployeeProfileService::tenure('2024-10-31', $now) === '2 years');

$emp = ['id' => 7, 'employee_no' => 'PMBSI007663', 'first_name' => 'John <b>Joshua</b>', 'middle_name' => 'B', 'last_name' => 'Perena',
    'position_name' => 'Graphic Artist', 'department_name' => 'Online Sales', 'branch_name' => 'Head Office', 'work_company' => 'PMBSI',
    'status' => 'ACTIVE', 'hire_date' => '2024-09-24', 'profile_photo_stored_name' => null];
$hero = EmployeeProfileService::hero($emp, $now);
check('hero: status label', $hero['status'] === 'Active' && $hero['tone'] === 'green');
check('hero: inactive tone', EmployeeProfileService::hero(['status' => 'RESIGNED'] + $emp, $now)['tone'] === 'gray');

$html = EmployeeProfileViews::hero($hero);
check('hero: name escaped', !str_contains($html, '<b>') && str_contains($html, '&lt;b&gt;'));
check('hero: shows employee no and position', str_contains($html, 'PMBSI007663') && str_contains($html, 'Graphic Artist'));
check('hero: initials avatar without photo', str_contains($html, 'myp-avatar') && !str_contains($html, '<img'));
check('hero: own photo upload form posts upload_own_photo with csrf and no employee id', ($h = EmployeeProfileViews::hero(EmployeeProfileService::hero($emp, $now))) && str_contains($h, 'value="upload_own_photo"') && str_contains($h, 'name="csrf"') && str_contains($h, 'name="profile_photo"') && !str_contains($h, 'employee_id'));
check('hero: own-photo route when photo exists', str_contains(EmployeeProfileViews::hero(EmployeeProfileService::hero(['profile_photo_stored_name' => 'x.jpg'] + $emp, $now)), 'employee-profile-photo'));
check('hero: no id parameter in any link', !str_contains($html, '&id=') && !str_contains($html, '?id='));
check('empty state wording', str_contains(EmployeeProfileViews::empty(), 'not linked to an employee record'));

// ---- T2: tabs content ----
$full = $emp + ['birth_date' => '2000-06-18', 'place_of_birth' => 'Taguig', 'gender' => 'MALE', 'civil_status' => 'SINGLE', 'nationality' => '',
    'preferred_name' => '', 'lee_code' => 'OFJI-09242024', 'mobile_no' => '0949', 'personal_email' => 'a@b.co', 'company_email' => '',
    'address_text' => 'Blk 4 <i>Lot</i>', 'permanent_address_text' => '', 'manager_first_name' => 'Ann', 'manager_last_name' => 'Lee',
    'regularization_date' => null, 'probation_end_date' => null];
$personal = EmployeeProfileService::personal($full);
$byLabel = static fn(array $rows): array => array_column($rows, 1, 0);
check('personal: birth date formatted', $byLabel($personal)['Birth date'] === 'Jun 18, 2000');
check('personal: empty value is blank string', $byLabel($personal)['Nationality'] === '');
check('personal: gender title-cased', $byLabel($personal)['Gender'] === 'Male');

$contact = EmployeeProfileService::contact($full, [['name' => 'Gen', 'relationship' => 'Mother', 'mobile_no' => '0962', 'email' => '', 'address_text' => '', 'is_primary' => 1]]);
check('contact: mobile + email rows', $byLabel($contact['contact'])['Mobile no.'] === '0949' && $byLabel($contact['contact'])['Personal email'] === 'a@b.co');
check('contact: emergency listed', count($contact['emergency']) === 1 && $byLabel($contact['emergency'][0])['Relationship'] === 'Mother');

$rate = ['salary_basis' => 'MONTHLY', 'salary_amount' => '25000', 'hourly_divisor' => '208'];
$job = EmployeeProfileService::employment($full, $rate, [['name' => 'Vacation Leave', 'balance' => '1.5', 'pending' => '0']]);
check('employment: manager name', $byLabel($job['job'])['Immediate manager'] === 'Ann Lee');
check('employment: hourly = amount / divisor', $byLabel($job['pay'])['Hourly rate'] === '₱120.19');
check('employment: monthly basis shows amount, no guessed daily', $byLabel($job['pay'])['Monthly rate'] === '₱25,000.00' && !isset($byLabel($job['pay'])['Daily rate']));
check('employment: daily basis', $byLabel(EmployeeProfileService::employment($full, ['salary_basis' => 'DAILY', 'salary_amount' => '800', 'hourly_divisor' => '8'], [])['pay'])['Daily rate'] === '₱800.00');
check('employment: no rate row means no pay rows', EmployeeProfileService::employment($full, null, [])['pay'] === []);
check('employment: leave rows', $job['leave'][0] === ['Vacation Leave', '1.50']);

$hist = EmployeeProfileService::historyRows([['event_type' => 'POSITION_CHANGE', 'effective_date' => '2025-03-01', 'from_position_name' => 'Artist', 'to_position_name' => 'Senior Artist',
    'from_department_name' => 'Sales', 'to_department_name' => 'Sales', 'remarks' => 'Promoted']]);
check('history: title/date/detail', $hist[0]['title'] === 'Position Change' && $hist[0]['date'] === 'Mar 1, 2025' && str_contains($hist[0]['detail'], 'Position: Artist → Senior Artist') && !str_contains($hist[0]['detail'], 'Department'));

$rowsHtml = EmployeeProfileViews::rows(array_merge($personal, [['Address', 'Blk <i>x</i>']]));
check('rows: blank shows Not provided', str_contains($rowsHtml, 'Not provided'));
check('rows: values escaped', !str_contains($rowsHtml, '<i>') && str_contains($rowsHtml, '&lt;i&gt;'));
$tabsHtml = EmployeeProfileViews::tabs(['personal' => ['Personal', '<p>A</p>'], 'employment' => ['Employment', '<p>B</p>']]);
check('tabs: tablist roles and anchors', str_contains($tabsHtml, 'role="tablist"') && str_contains($tabsHtml, 'href="#myp-personal"') && str_contains($tabsHtml, 'role="tabpanel"'));
check('tabs: first tab selected', str_contains($tabsHtml, 'aria-selected="true"'));
check('history view: empty message', str_contains(EmployeeProfileViews::history([]), 'No employment history'));

// ---- T3: masked IDs + scanned documents ----
check('mask: keeps last 4 alphanumerics', EmployeeProfileService::maskId("'01-250992021-2") === '••••0212');
check('mask: plain number', EmployeeProfileService::maskId('3522076357') === '••••6357');
check('mask: short value fully hidden', EmployeeProfileService::maskId('123') === '••••' && EmployeeProfileService::maskId('') === '');

$ids = EmployeeProfileService::idRows([
    ['id' => 5, 'id_type' => 'PAGIBIG', 'id_number' => '121305474255', 'issued_date' => '2020-01-05', 'expiry_date' => '2026-11-20', 'notes' => 'HR secret'],
    ['id' => 6, 'id_type' => 'DRIVERS_LICENSE', 'id_number' => 'N01-23-456789', 'issued_date' => null, 'expiry_date' => '2026-01-01', 'notes' => ''],
    ['id' => 7, 'id_type' => 'SSS', 'id_number' => '3522076357', 'issued_date' => null, 'expiry_date' => null, 'notes' => ''],
], $now);
check('ids: label + masked, no raw number', $ids[0]['label'] === 'Pag-IBIG' && $ids[0]['masked'] === '••••4255' && !str_contains(json_encode($ids), '121305474255') && !str_contains(json_encode($ids), 'HR secret'));
check('ids: expiring soon / expired / none', $ids[0]['state'] === 'soon' && $ids[1]['state'] === 'expired' && $ids[2]['state'] === '');
check('ids: driver license label', $ids[1]['label'] === "Driver's license");

$docs = EmployeeProfileService::documentRows([
    ['id' => 1, 'document_type' => 'GOVERNMENT_ID', 'title' => 'SSS <scan>', 'original_name' => 'sss.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 2411520, 'uploaded_at' => '2026-10-01 08:00:00'],
    ['id' => 2, 'document_type' => 'CONTRACT', 'title' => 'Contract', 'original_name' => 'c.pdf', 'mime_type' => 'application/pdf', 'file_size' => 900, 'uploaded_at' => '2026-10-02 08:00:00'],
    ['id' => 3, 'document_type' => 'OTHER', 'title' => 'Sheet', 'original_name' => 's.xlsx', 'mime_type' => 'application/vnd.ms-excel', 'file_size' => 100000, 'uploaded_at' => null],
]);
check('docs: kinds', [$docs[0]['kind'], $docs[1]['kind'], $docs[2]['kind']] === ['image', 'pdf', 'file']);
check('docs: size + type label + date', $docs[0]['size'] === '2.3 MB' && $docs[1]['size'] === '900 B' && $docs[0]['type'] === 'Government ID' && $docs[0]['date'] === 'Oct 1, 2026');
check('docs: ALL types kept (no filter)', count($docs) === 3);

$idsHtml = EmployeeProfileViews::ids($ids, 'tok<en>');
check('ids view: masked shown, button per id, token escaped', str_contains($idsHtml, '••••4255') && substr_count($idsHtml, 'myp-reveal') >= 3 && !str_contains($idsHtml, 'tok<en>'));
check('ids view: no raw number in HTML', !str_contains($idsHtml, '121305474255') && !str_contains($idsHtml, '3522076357'));
check('ids view: expiry chip', str_contains($idsHtml, 'Expired') && str_contains($idsHtml, 'Expires soon'));
check('ids view: empty', str_contains(EmployeeProfileViews::ids([], 't'), 'No government IDs'));
$docHtml = EmployeeProfileViews::documents($docs);
check('docs view: image thumb lazy via own route', str_contains($docHtml, 'loading="lazy"') && str_contains($docHtml, 'employee-profile-document') && !str_contains($docHtml, 'stored_name'));
check('docs view: title escaped', !str_contains($docHtml, '<scan>') && str_contains($docHtml, '&lt;scan&gt;'));
check('docs view: filter chips for present types', str_contains($docHtml, 'data-filter="CONTRACT"') && str_contains($docHtml, 'data-filter="all"'));
check('docs view: lightbox dialog + download link', str_contains($docHtml, '<dialog') && str_contains($docHtml, 'download=1'));
check('docs view: empty', str_contains(EmployeeProfileViews::documents([]), 'No documents'));

// ---- web performance fixes ----
$dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'myp_thumb_'.bin2hex(random_bytes(4)); mkdir($dir);
$big = $dir.DIRECTORY_SEPARATOR.'big.png'; $small = $dir.DIRECTORY_SEPARATOR.'small.png';
if (function_exists('imagecreatetruecolor')) { foreach ([[$big, 1200, 900], [$small, 200, 100]] as [$p, $w, $h]) { $im = imagecreatetruecolor($w, $h); imagepng($im, $p); } }
else { file_put_contents($big, 'not an image'); file_put_contents($small, 'not an image'); }
$thumb = EmployeeProfileService::thumbnail($big, 'big.png');
if (function_exists('imagecreatefromstring')) {
    $dim = $thumb ? getimagesize($thumb) : false;
    check('thumbnail: large image becomes a 480 px JPEG cached under .thumbs', $dim && $dim[0] === 480 && $dim[1] === 360 && $dim['mime'] === 'image/jpeg' && str_contains(str_replace('\\', '/', $thumb), '/.thumbs/') && EmployeeProfileService::thumbnail($big, 'big.png') === $thumb);
} else check('thumbnail: without GD the original is served (null)', $thumb === null);
check('thumbnail: small or unreadable image is served as is (null)', EmployeeProfileService::thumbnail($small, 'small.png') === null);
foreach (glob($dir.'/.thumbs/*') ?: [] as $f) @unlink($f); @rmdir($dir.'/.thumbs'); @unlink($big); @unlink($small); @rmdir($dir);
$docView = EmployeeProfileViews::documents($docs);
check('perf: document thumbnails use the thumb URL with width/height', str_contains($docView, 'thumb=1') && str_contains($docView, 'width="480" height="360"'));
check('perf: photo has explicit size', str_contains($html, 'width') === false && str_contains(EmployeeProfileViews::hero(EmployeeProfileService::hero(['profile_photo_stored_name' => 'x.jpg'] + $emp, $now)), 'width="88" height="88"'));
$tabsPerf = EmployeeProfileViews::tabs(['a' => ['A', ''], 'b' => ['B', '']]);
check('perf: only the first panel is visible at first paint', !preg_match('/id="myp-a"[^>]* hidden/', $tabsPerf) && preg_match('/id="myp-b"[^>]* hidden/', $tabsPerf) === 1);
$_SESSION = ['csrf' => 'abc'];
check('perf: no-JS fallback shows every panel', str_contains(EmployeeProfileViews::page($hero, $personal, $contact, $job ?? ['job' => [], 'dates' => [], 'pay' => [], 'leave' => []], [], [], 'employee', []), '<noscript><style>.myp-panel[hidden]'));

// ---- ship review fixes ----
$zeroExp = EmployeeProfileService::idRows([['id' => 1, 'id_type' => 'TIN', 'id_number' => '123456789', 'issued_date' => '0000-00-00', 'expiry_date' => '0000-00-00']], $now)[0];
check('ship: zero expiry/issued dates are blank and never "Expired"', $zeroExp['state'] === '' && $zeroExp['expiry'] === '' && $zeroExp['issued'] === '');

$dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'myp_exif_'.bin2hex(random_bytes(4)); mkdir($dir);
$exifJpg = $dir.DIRECTORY_SEPARATOR.'phone.jpg'; $exifThumb = null;
if (function_exists('imagecreatetruecolor')) {
    $im = imagecreatetruecolor(1200, 900); imagejpeg($im, $exifJpg);
    $tiff = "II*\x00\x08\x00\x00\x00"."\x01\x00"."\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00"."\x00\x00\x00\x00"; // one IFD entry: Orientation = 6 (rotate 90 CW)
    $app1 = "Exif\x00\x00".$tiff; $raw = (string)file_get_contents($exifJpg);
    file_put_contents($exifJpg, substr($raw, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($raw, 2));
    $exifThumb = EmployeeProfileService::thumbnail($exifJpg, 'phone.jpg');
    $dim = $exifThumb ? getimagesize($exifThumb) : false;
    $expectH = function_exists('exif_read_data') ? 640 : 360; // portrait after rotation when the exif extension exists
    check('ship: EXIF-rotated phone scan keeps its orientation in the thumbnail', $dim && $dim[0] === 480 && $dim[1] === $expectH, $dim ? $dim[0].'x'.$dim[1] : 'no thumbnail');
} else check('ship: EXIF thumbnail needs GD (skipped, served as original)', EmployeeProfileService::thumbnail($exifJpg, 'phone.jpg') === null);

$hugePng = $dir.DIRECTORY_SEPARATOR.'huge.png';
if (function_exists('imagecreatetruecolor')) { $im = imagecreatetruecolor(4000, 3000); imagepng($im, $hugePng, 9); unset($im); } else file_put_contents($hugePng, 'x');
$oldLimit = ini_get('memory_limit'); ini_set('memory_limit', '40M');
$tight = EmployeeProfileService::thumbnail($hugePng, 'huge.png');
ini_set('memory_limit', $oldLimit);
check('ship: an image that would not fit in memory is served as the original, not a fatal error', $tight === null);
foreach (glob($dir.'/.thumbs/*') ?: [] as $f) @unlink($f); @rmdir($dir.'/.thumbs'); @unlink($exifJpg); @unlink($hugePng); @rmdir($dir);

// ---- review fixes ----
$badDate = EmployeeProfileService::hero(['hire_date' => '0000-00-00'] + $emp, $now);
check('review: invalid start date is left out, never 1970', $badDate['start'] === '' && !str_contains(EmployeeProfileViews::hero($badDate), '1970') && !str_contains(EmployeeProfileViews::hero($badDate), 'Start date'));
check('review: zero date gives no tenure', EmployeeProfileService::tenure('0000-00-00', $now) === '' && $badDate['tenure'] === '');
check('review: valid start date label', $hero['start'] === 'Sep 24, 2024' && str_contains($html, 'Sep 24, 2024'));
check('review: tab links carry no server tabindex (reachable without JavaScript)', !str_contains(EmployeeProfileViews::tabs(['a' => ['A', ''], 'b' => ['B', '']]), 'tabindex'));

// ---- T5: shortcuts, print, HR/admin access ----
check('kind: employee roles', EmployeeProfileService::kind(['role_code' => 'MANAGER', 'role_portal' => 'employee']) === 'employee');
check('kind: hr roles', EmployeeProfileService::kind(['role_code' => 'HR_USER', 'role_portal' => 'hr']) === 'hr');
check('kind: admin roles', EmployeeProfileService::kind(['role_code' => 'HRIS_ADMIN', 'role_portal' => 'admin']) === 'admin' && EmployeeProfileService::kind(['role_code' => 'SUPER_ADMIN']) === 'admin');
check('access: employee, hr, admin allowed', EmployeeProfileService::canView(['role_code' => 'EMPLOYEE', 'role_portal' => 'employee']) && EmployeeProfileService::canView(['role_code' => 'PAYROLL', 'role_portal' => 'hr']) && EmployeeProfileService::canView(['role_code' => 'SUPER_ADMIN', 'role_portal' => 'admin']));
check('access: client and unknown denied', !EmployeeProfileService::canView(['role_code' => 'CLIENT_USER', 'role_portal' => 'client']) && !EmployeeProfileService::canView([]) && !EmployeeProfileService::canView(['role_code' => 'X', 'role_portal' => '']));

$all = EmployeeProfileService::shortcuts(static fn(string $p): bool => true);
$labels = array_column($all, 'label');
check('shortcuts: order starts with Print Summary', $labels[0] === 'Print Summary' && $all[0]['print'] === true);
check('shortcuts: old links all present', array_diff(['Print Summary', 'Payslip', 'Deductions', 'Additional', 'Send Request', 'Send DTR Adjustment', 'Downloads', 'FAQs'], $labels) === []);
$byLabel2 = array_column($all, null, 'label');
check('shortcuts: real targets', $byLabel2['Send DTR Adjustment']['page'] === 'employee-request-new' && $byLabel2['Send Request']['page'] === 'hr-requests-my' && $byLabel2['Leave']['page'] === 'employee-leave');
check('shortcuts: no Soon items, the five new links have pages', array_filter($all, static fn(array $i): bool => $i['state'] === 'soon') === [] && $byLabel2['Payslip']['page'] === 'profile-payslip' && $byLabel2['Deductions']['page'] === 'profile-deductions' && $byLabel2['Additional']['page'] === 'profile-additional' && $byLabel2['Downloads']['page'] === 'profile-downloads' && $byLabel2['FAQs']['page'] === 'profile-faqs');
$none = array_column(EmployeeProfileService::shortcuts(static fn(string $p): bool => false), 'label');
check('shortcuts: no permission means hidden, Print + the five profile links stay', !array_intersect(['Send Request', 'Send DTR Adjustment', 'Leave', 'Attendance'], $none) && in_array('Print Summary', $none, true) && in_array('Payslip', $none, true));

$sc = EmployeeProfileViews::shortcuts($all);
check('shortcuts view: print button, real links, no Soon pills', str_contains($sc, 'data-print') && str_contains($sc, 'employee-request-new') && str_contains($sc, 'profile-faqs') && !str_contains($sc, 'myp-soon'));
check('shortcuts view: a page-less item still renders as Soon, never a dead link', substr_count(EmployeeProfileViews::shortcuts([['label'=>'X','page'=>null,'print'=>false,'state'=>'soon']]), 'myp-soon') === 1);
check('shortcuts view: no dead # links', !str_contains($sc, 'href="#"') && !str_contains($sc, "href='#'"));
check('shortcuts view: disabled items are not focusable links', !preg_match('/<a[^>]*aria-disabled/', $sc));

$_SESSION = ['csrf' => 'abc'];
$pageHtml = EmployeeProfileViews::page($hero, $personal, $contact, $job, $hist, ['ids' => $ids, 'documents' => $docs], 'hr', $all);
check('page: HR note points to Employee 201', str_contains($pageHtml, 'maintained in Employee 201') && !str_contains($pageHtml, 'contact HR'));
check('page: employee note says contact HR', str_contains(EmployeeProfileViews::page($hero, $personal, $contact, $job, $hist, [], 'employee', []), 'contact HR'));
check('page: shortcuts rendered before the tabs', strpos($pageHtml, 'myp-shortcuts') !== false && strpos($pageHtml, 'myp-shortcuts') < strpos($pageHtml, 'myp-tabs'));
check('page: raw ID number never rendered', !str_contains($pageHtml, '121305474255'));


// Profile parity patch 1: source-level checks (no DB, no browser).
$root = dirname(__DIR__);
$viewSrc = (string)file_get_contents($root.'/app/View.php');
$repoSrc = (string)file_get_contents($root.'/app/EmployeeRepository.php');
$indexSrc = (string)file_get_contents($root.'/index.php');
$secSrc = (string)file_get_contents($root.'/app/DirectorySectionViews.php');
check('nav: dropdown Profile hidden only when the employee sidebar already has My Profile', str_contains($viewSrc, "!(\$kind==='employee' && Auth::can('employees.view_self'))"));
check('lee code: HR form input, max 30', str_contains($indexSrc, 'name="lee_code"') && str_contains($indexSrc, 'maxlength="30"'));
check('lee code: server rejects over 30 and saves only when posted', str_contains($repoSrc, 'Lee code can be at most 30 characters.') && str_contains($repoSrc, "array_key_exists('lee_code',\$data)") && str_contains($repoSrc, "'lee_code_after'"));
check('directory labels: new wording, old wording gone', str_contains($indexSrc, 'Directory role') && str_contains($indexSrc, 'Automatic (from position)') && str_contains($secSrc, 'Head Office team') && !str_contains($indexSrc, '>Directory section<') && !str_contains($secSrc, 'No section'));
exit($fail ? 1 : 0);
