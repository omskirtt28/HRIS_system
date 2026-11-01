<?php
declare(strict_types=1);

/** Read-only data for My Profile. Only ever resolves the signed-in user's own employee row. */
final class EmployeeProfileService
{
    public static function forCurrentUser(): ?array
    {
        return PayrollRepository::ready() ? PayrollRepository::currentEmployee() : null;
    }

    /** Id of the signed-in user's own employee row: the cheap lookup for the reveal, photo and document routes. */
    public static function ownEmployeeId(): ?int
    {
        $userId = (int)(Auth::user()['id'] ?? 0);
        if ($userId <= 0 || !PayrollRepository::ready()) return null;
        $st = db()->prepare('SELECT id FROM employees WHERE user_id=? LIMIT 1');
        $st->execute([$userId]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public static function hero(array $e, ?DateTimeImmutable $now = null): array
    {
        $name = trim(implode(' ', array_filter([(string)($e['first_name'] ?? ''), (string)($e['middle_name'] ?? ''), (string)($e['last_name'] ?? ''), (string)($e['suffix'] ?? '')])));
        if ($name === '') $name = trim((string)($e['roster_full_name'] ?? ''));
        $status = (string)($e['status'] ?? '');
        $place = array_filter([(string)($e['department_name'] ?? ''), (string)($e['branch_name'] ?? '')]);
        return [
            'name' => $name,
            'employee_no' => (string)($e['employee_no'] ?? ''),
            'position' => (string)($e['position_name'] ?? ''),
            'place' => implode(' · ', $place),
            'company' => (string)($e['work_company'] ?? ''),
            'status' => $status === '' ? '' : stage_label($status),
            'tone' => in_array($status, ['ACTIVE', 'PROBATIONARY'], true) ? 'green' : 'gray',
            'hire_date' => (string)($e['hire_date'] ?? ''),
            'start' => self::date($e['hire_date'] ?? null),
            'tenure' => self::tenure($e['hire_date'] ?? null, $now),
            'has_photo' => !empty($e['profile_photo_stored_name']),
        ];
    }

    /** Which portal chrome (sidebar) the viewer sees: employee, hr or admin. */
    public static function kind(array $user): string
    {
        if (in_array((string)($user['role_code'] ?? ''), ['SUPER_ADMIN', 'HRIS_ADMIN'], true) || ($user['role_portal'] ?? '') === 'admin') return 'admin';
        return ($user['role_portal'] ?? '') === 'hr' ? 'hr' : 'employee';
    }

    /**
     * Own-profile access for every internal account (employee, HR, admin); client accounts have no employee record.
     * Deliberately not the employees.view_self permission: HR roles do not hold it (John asked for HR to have the page), so the sidebar
     * item for employees is permission-gated while this check also admits HR/admin. Keep both in mind when changing either.
     */
    public static function canView(array $user): bool
    {
        return in_array((string)($user['role_portal'] ?? ''), ['employee', 'hr', 'admin'], true) || in_array((string)($user['role_code'] ?? ''), ['SUPER_ADMIN', 'HRIS_ADMIN'], true);
    }

    public static function requireAccess(): void
    {
        if (!Auth::check()) redirect('login');
        if (!self::canView(Auth::user() ?? [])) { http_response_code(403); exit('Access denied'); }
    }

    /** Old-HRIS top links. A link needs the target page's permission to appear; an item with no page yet would show as Soon. */
    public static function shortcuts(callable $can): array
    {
        $defs = [
            ['Print Summary', null, null, true],
            ['Payslip', 'profile-payslip', null, false],
            ['Deductions', 'profile-deductions', null, false],
            ['Additional', 'profile-additional', null, false],
            ['Send Request', 'hr-requests-my', 'tickets.request_self', false],
            ['Send DTR Adjustment', 'employee-request-new', 'payroll.request_self', false],
            ['Leave', 'employee-leave', 'leave.view_self', false],
            ['Attendance', 'employee-attendance', 'attendance.view_self', false],
            ['Downloads', 'profile-downloads', null, false],
            ['FAQs', 'profile-faqs', null, false],
        ];
        $out = [];
        foreach ($defs as [$label, $page, $perm, $print]) {
            if ($perm !== null && !$can($perm)) continue;
            $out[] = ['label' => $label, 'page' => $page, 'print' => $print, 'state' => ($page === null && !$print) ? 'soon' : 'on'];
        }
        usort($out, static fn(array $a, array $b): int => ($a['state'] === 'soon') <=> ($b['state'] === 'soon')); // usable links first (usort is stable)
        return $out;
    }

    /** Extra read-only rows for the signed-in employee's own id. Each lookup tolerates a missing table. */
    public static function extras(int $employeeId): array
    {
        $rate = null;
        if (FoundationRepository::tableExists('payroll_employee_rates')) {
            $st = db()->prepare('SELECT salary_basis,salary_amount,hourly_divisor FROM payroll_employee_rates WHERE employee_id=? AND effective_from<=CURDATE() AND COALESCE(effective_to,"9999-12-31")>=CURDATE() ORDER BY effective_from DESC LIMIT 1');
            $st->execute([$employeeId]);
            $rate = $st->fetch() ?: null;
        }
        return [
            'emergency' => EmployeeRepository::emergencyContacts($employeeId),
            'history' => EmployeeRepository::history($employeeId),
            'rate' => $rate,
            'balances' => PayrollRepository::leaveBalances($employeeId),
            'ids' => self::idRows(EmployeeRepository::governmentIds($employeeId)),
            'documents' => self::documentRows(EmployeeRepository::documents($employeeId)),
        ];
    }

    public static function personal(array $e): array
    {
        return [
            ['Employee no.', (string)($e['employee_no'] ?? '')],
            ['Lee code', (string)($e['lee_code'] ?? '')],
            ['Last name', (string)($e['last_name'] ?? '')],
            ['First name', (string)($e['first_name'] ?? '')],
            ['Middle name', (string)($e['middle_name'] ?? '')],
            ['Suffix', (string)($e['suffix'] ?? '')],
            ['Preferred name', (string)($e['preferred_name'] ?? '')],
            ['Birth date', self::date($e['birth_date'] ?? null)],
            ['Birth place', (string)($e['place_of_birth'] ?? '')],
            ['Gender', ucfirst(strtolower((string)($e['gender'] ?? '')))],
            ['Civil status', ucfirst(strtolower((string)($e['civil_status'] ?? '')))],
            ['Nationality', (string)($e['nationality'] ?? '')],
        ];
    }

    public static function contact(array $e, array $emergency): array
    {
        $people = [];
        foreach ($emergency as $c) {
            $people[] = [
                ['Contact name', (string)($c['name'] ?? '')],
                ['Relationship', (string)($c['relationship'] ?? '')],
                ['Mobile no.', (string)($c['mobile_no'] ?? '')],
                ['Email address', (string)($c['email'] ?? '')],
                ['Address', (string)($c['address_text'] ?? '')],
            ];
        }
        return [
            'contact' => [
                ['Mobile no.', (string)($e['mobile_no'] ?? '')],
                ['Personal email', (string)($e['personal_email'] ?? '')],
                ['Company email', (string)($e['company_email'] ?? '')],
            ],
            'address' => [
                ['Current address', (string)($e['address_text'] ?? '')],
                ['Permanent address', (string)($e['permanent_address_text'] ?? '')],
            ],
            'emergency' => $people,
        ];
    }

    public static function employment(array $e, ?array $rate, array $balances): array
    {
        $manager = trim(implode(' ', array_filter([(string)($e['manager_first_name'] ?? ''), (string)($e['manager_middle_name'] ?? ''), (string)($e['manager_last_name'] ?? '')])));
        $basis = (string)($rate['salary_basis'] ?? '');
        $amount = $rate ? (float)$rate['salary_amount'] : null;
        $divisor = $rate ? (float)$rate['hourly_divisor'] : 0.0;
        $money = static fn(?float $v): string => $v === null ? '' : '₱'.number_format($v, 2);
        $leave = [];
        foreach ($balances as $b) $leave[] = [(string)$b['name'], number_format((float)$b['balance'], 2)];
        return [
            'job' => [
                ['Company', (string)($e['work_company'] ?? '')],
                ['Branch', (string)($e['branch_name'] ?? '')],
                ['Department', (string)($e['department_name'] ?? '')],
                ['Position', (string)($e['position_name'] ?? '')],
                ['Immediate manager', $manager],
                ['Status', ($e['status'] ?? '') === '' ? '' : stage_label((string)$e['status'])],
            ],
            'dates' => [
                ['Start date', self::date($e['hire_date'] ?? null)],
                ['Probation end', self::date($e['probation_end_date'] ?? null)],
                ['Regularization date', self::date($e['regularization_date'] ?? null)],
            ],
            // Only rates that really exist; nothing is guessed (a monthly basis does not invent a daily rate).
            'pay' => array_values(array_filter([
                ['Daily rate', $basis === 'DAILY' ? $money($amount) : ''],
                ['Monthly rate', $basis === 'MONTHLY' ? $money($amount) : ''],
                ['Hourly rate', $basis === 'HOURLY' ? $money($amount) : ($amount !== null && $divisor > 0 ? $money($amount / $divisor) : '')],
            ], static fn(array $row): bool => $row[1] !== '')),
            'leave' => $leave,
        ];
    }

    public static function historyRows(array $history): array
    {
        $fields = ['department' => 'Department', 'position' => 'Position', 'branch' => 'Branch', 'employment_type' => 'Employment type'];
        $rows = [];
        foreach ($history as $h) {
            $bits = [];
            foreach ($fields as $key => $label) {
                $from = (string)($h['from_'.$key.'_name'] ?? ''); $to = (string)($h['to_'.$key.'_name'] ?? '');
                if ($from !== $to && ($from !== '' || $to !== '')) $bits[] = $label.': '.($from !== '' ? $from : 'None').' → '.($to !== '' ? $to : 'None');
            }
            $from = (string)($h['from_status'] ?? ''); $to = (string)($h['to_status'] ?? '');
            if ($from !== $to && $to !== '') $bits[] = 'Status: '.($from !== '' ? stage_label($from) : 'None').' → '.stage_label($to);
            if (trim((string)($h['remarks'] ?? '')) !== '') $bits[] = trim((string)$h['remarks']);
            $rows[] = ['title' => stage_label((string)($h['event_type'] ?? '')), 'date' => self::date($h['effective_date'] ?? null), 'detail' => implode(' · ', $bits)];
        }
        return $rows;
    }

    private const ID_LABELS = ['SSS' => 'SSS', 'PHILHEALTH' => 'PhilHealth', 'PAGIBIG' => 'Pag-IBIG', 'TIN' => 'TIN', 'UMID' => 'UMID', 'PASSPORT' => 'Passport', 'DRIVERS_LICENSE' => "Driver's license", 'PRC' => 'PRC', 'OTHER' => 'Other ID'];
    private const DOC_LABELS = ['CONTRACT' => 'Contract', 'RESUME' => 'Resume', 'NBI_CLEARANCE' => 'NBI clearance', 'POLICE_CLEARANCE' => 'Police clearance', 'MEDICAL' => 'Medical', 'GOVERNMENT_ID' => 'Government ID', 'CERTIFICATE' => 'Certificate', 'MEMO' => 'Memo', 'OTHER' => 'Other'];

    /** Show only the last 4 letters/digits; shorter values are fully hidden. */
    public static function maskId(string $number): string
    {
        $plain = preg_replace('/[^A-Za-z0-9]/', '', $number) ?? '';
        if ($plain === '') return '';
        return '••••'.(strlen($plain) > 4 ? substr($plain, -4) : '');
    }

    /** Display rows for government IDs. The full number is deliberately NOT included (see revealNumber). */
    public static function idRows(array $rows, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('today');
        $out = [];
        foreach ($rows as $r) {
            $expiry = (!empty($r['expiry_date']) && !str_starts_with((string)$r['expiry_date'], '0000')) ? strtotime((string)$r['expiry_date']) : false;
            $state = '';
            if ($expiry) {
                $days = (int)floor(($expiry - $now->getTimestamp()) / 86400);
                $state = $days < 0 ? 'expired' : ($days <= 60 ? 'soon' : '');
            }
            $out[] = [
                'id' => (int)$r['id'],
                'label' => self::ID_LABELS[(string)$r['id_type']] ?? ucwords(strtolower(str_replace('_', ' ', (string)$r['id_type']))),
                'masked' => self::maskId((string)$r['id_number']),
                'issued' => self::date($r['issued_date'] ?? null),
                'expiry' => self::date($r['expiry_date'] ?? null),
                'state' => $state,
            ];
        }
        return $out;
    }

    public static function documentRows(array $docs): array
    {
        $out = [];
        foreach ($docs as $d) {
            $mime = (string)($d['mime_type'] ?? ''); $bytes = (int)($d['file_size'] ?? 0);
            $out[] = [
                'id' => (int)$d['id'],
                'title' => (string)(($d['title'] ?? '') !== '' ? $d['title'] : ($d['original_name'] ?? 'Document')),
                'type_code' => (string)$d['document_type'],
                'type' => self::DOC_LABELS[(string)$d['document_type']] ?? 'Other',
                'kind' => str_starts_with($mime, 'image/') ? 'image' : ($mime === 'application/pdf' ? 'pdf' : 'file'),
                'ext' => strtoupper(pathinfo((string)($d['original_name'] ?? ''), PATHINFO_EXTENSION)),
                'size' => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : ($bytes >= 1024 ? number_format($bytes / 1024, 0).' KB' : $bytes.' B'),
                'date' => self::date($d['uploaded_at'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * 480 px JPEG preview of a scanned image, cached under <folder>/.thumbs (outside public/, served only by the owner-checked route).
     * Null when GD is missing, the image is already small or too large to decode safely, or anything fails; callers then serve the original.
     * shortcut: no cache invalidation, because stored_name is unique per upload; delete .thumbs to rebuild.
     */
    public static function thumbnail(string $path, string $storedName): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) return null;
        $cache = dirname($path).'/.thumbs/'.basename($storedName).'.jpg';
        if (is_file($cache) && filemtime($cache) >= filemtime($path)) return $cache;
        $info = @getimagesize($path);
        if (!$info || $info[0] <= 480 || $info[0] * $info[1] > 16000000 || filesize($path) > 12 * 1048576) return null;
        // Phone scans carry their upright direction in EXIF; GD ignores it, so turn the pixels here.
        $orientation = ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data') && function_exists('imagerotate')) ? (int)((@exif_read_data($path) ?: [])['Orientation'] ?? 1) : 1;
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0; // imagerotate turns counter-clockwise
        // A decode that exceeds memory_limit is an uncatchable fatal 500, so serve the original instead (rotation needs a second full copy).
        if (!self::fitsInMemory($info[0] * $info[1] * ($angle ? 9 : 5) + filesize($path) * 2)) return null;
        $src = @imagecreatefromstring((string)file_get_contents($path));
        if (!$src) return null;
        if ($angle && ($turned = imagerotate($src, $angle, 0))) $src = $turned;
        [$sw, $sh] = [imagesx($src), imagesy($src)];
        $w = 480; $h = max(1, (int)round($sh * $w / $sw));
        $dst = imagecreatetruecolor($w, $h);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // transparent PNG scans get a white page
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $sw, $sh);
        $dir = dirname($cache);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return null;
        $tmp = $cache.'.'.bin2hex(random_bytes(4)).'.tmp';
        $ok = imagejpeg($dst, $tmp, 78) && @rename($tmp, $cache);
        if (!$ok) @unlink($tmp);
        return $ok || is_file($cache) ? $cache : null;
    }

    private static function fitsInMemory(int $bytes): bool
    {
        $raw = trim((string)ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') return true;
        $limit = (int)$raw * match (strtolower(substr($raw, -1))) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
        return $limit <= 0 || memory_get_usage() + $bytes < $limit * 0.85;
    }

    /**
     * Send one owner-checked file with validators (ETag/Last-Modified, 304). Personal scans are never served from the browser cache
     * without asking: `no-cache` makes every request come back here (session + ownership check) and the ETag keeps it cheap.
     */
    public static function stream(string $file, string $type, string $disposition): never
    {
        $mtime = (int)filemtime($file);
        $etag = '"'.$mtime.'-'.filesize($file).'"';
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        header('Content-Type: '.$type); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-cache');
        header('ETag: '.$etag); header('Last-Modified: '.gmdate('D, d M Y H:i:s', $mtime).' GMT');
        if ($disposition !== '') header('Content-Disposition: '.$disposition);
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
        header('Content-Length: '.filesize($file));
        readfile($file);
        exit;
    }

    /** Full number for ONE of the signed-in employee's own IDs; null when it is not theirs. Audited, number never logged. */
    public static function revealNumber(int $employeeId, int $idRowId): ?string
    {
        if (!FoundationRepository::tableExists('employee_government_ids')) return null;
        $st = db()->prepare('SELECT id_type,id_number FROM employee_government_ids WHERE id=? AND employee_id=?');
        $st->execute([$idRowId, $employeeId]);
        $row = $st->fetch();
        if (!$row) return null;
        audit('Employees', 'REVEAL_OWN_GOV_ID', 'employee', $employeeId, ['id_type' => $row['id_type'], 'id_row' => $idRowId]);
        return (string)$row['id_number'];
    }

    private static function date(?string $d): string
    {
        $t = ($d && !str_starts_with($d, '0000')) ? strtotime($d) : false; // MySQL zero dates parse as year -1
        return $t ? date('M j, Y', $t) : '';
    }

    public static function tenure(?string $hireDate, ?DateTimeImmutable $now = null): string
    {
        if (!$hireDate || str_starts_with($hireDate, '0000')) return '';
        try { $from = new DateTimeImmutable($hireDate); } catch (Throwable) { return ''; }
        $diff = $from->diff($now ?? new DateTimeImmutable('today'));
        if ($diff->invert) return '';
        $parts = [];
        if ($diff->y) $parts[] = $diff->y.' year'.($diff->y > 1 ? 's' : '');
        if ($diff->m) $parts[] = $diff->m.' month'.($diff->m > 1 ? 's' : '');
        return $parts ? implode(' ', $parts) : 'Less than a month';
    }
}
