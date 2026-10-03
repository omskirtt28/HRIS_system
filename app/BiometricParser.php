<?php
declare(strict_types=1);

final class BiometricParser
{
    public static function parse(string $path, string $extension): array
    {
        if ($extension === 'pdf') {
            if (!class_exists(\Smalot\PdfParser\Parser::class)) {
                throw new RuntimeException('PDF support requires the bundled vendor folder. Run composer install --no-dev if it is missing.');
            }
            $pdf = (new \Smalot\PdfParser\Parser())->parseFile($path);
            if (count($pdf->getPages()) > 500) throw new RuntimeException('Split PDF exports into files with at most 500 pages.');
            $text = $pdf->getText();
            $rows = [];
            // OGAL report: No. | Date/Time | Status, with C/In and C/Out.
            preg_match_all('/\b([A-Za-z0-9_-]+)\s+(\d{1,2}\/\d{1,2}\/\d{4}|\d{4}-\d{2}-\d{2})\s+(\d{1,2}:\d{2}(?::\d{2})?(?:\s*[AP]M)?)\s+(C\s*\/\s*(?:In|Out)|Check\s*(?:In|Out)|Lunch\s*(?:In|Out)|Break\s*(?:In|Out)|Time\s*(?:In|Out)|IN|OUT)\b/i', $text, $matches, PREG_SET_ORDER);
            preg_match_all('/\b[A-Za-z0-9_-]+\s+(?:\d{1,2}\/\d{1,2}\/\d{4}|\d{4}-\d{2}-\d{2})\s+\d{1,2}:\d{2}/', $text, $candidates);
            if(count($candidates[0])!==count($matches)) throw new RuntimeException('Some PDF punch rows could not be read. Export CSV/XLSX so no biometric rows are silently skipped.');
            foreach ($matches as $m) $rows[] = self::row($m[1], $m[2].' '.$m[3], $m[4]);
            if (!$rows) throw new RuntimeException('No readable biometric rows found. Upload a text PDF with No., Date/Time and Status, or export CSV/XLSX. Scanned PDFs need a text export.');
            return self::bounded($rows);
        }
        $matrix = $extension === 'xlsx' ? self::xlsx($path) : self::csv($path);
        if (!$matrix) throw new RuntimeException('The upload has no rows.');
        $headers = array_map(static fn($v) => preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)$v, "\xEF\xBB\xBF \t\r\n"))), array_shift($matrix));
        $pick = static function(array $names) use ($headers): ?int {
            foreach ($names as $name) { $i = array_search($name, $headers, true); if ($i !== false) return (int)$i; }
            return null;
        };
        $id = $pick(['no','biometricid','employeeid','employeeno','userid','id']);
        $dt = $pick(['datetime','timestamp','punchedat']);
        $date = $pick(['date']); $time = $pick(['time']);
        $status = $pick(['status','punchstatus','event','type']);
        if ($id === null || $status === null || ($dt === null && ($date === null || $time === null))) {
            throw new RuntimeException('Headers must contain Biometric ID (or No.), Date/Time, and Status. Separate Date and Time columns are also accepted.');
        }
        $rows = [];
        foreach ($matrix as $n => $cells) {
            if (!array_filter($cells, static fn($v) => trim((string)$v) !== '')) continue;
            try {
                $when = $dt !== null ? ($cells[$dt] ?? '') : (string)($cells[$date] ?? '').' '.(string)($cells[$time] ?? '');
                if ($dt !== null && is_numeric($when)) $when = self::excelDate((float)$when);
                elseif ($dt === null && is_numeric($cells[$date] ?? null) && is_numeric($cells[$time] ?? null)) $when = self::excelDate((float)$cells[$date] + (float)$cells[$time]);
                $rows[] = self::row((string)($cells[$id] ?? ''), (string)$when, (string)($cells[$status] ?? ''));
            } catch (Throwable $e) { throw new RuntimeException('Row '.($n + 2).': '.$e->getMessage()); }
        }
        return self::bounded($rows);
    }

    private static function bounded(array $rows): array
    {
        if (!$rows || count($rows) > 100000) throw new RuntimeException('Upload between 1 and 100,000 punch rows per file.');
        return $rows;
    }

    private static function row(string $id, string $when, string $status): array
    {
        $id = trim($id);
        if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $id)) throw new RuntimeException('Invalid biometric ID.');
        $when = preg_replace('/\s+/', ' ', trim($when));
        $dt = null;
        foreach (['!Y-m-d H:i:s','!Y-m-d H:i','!m/d/Y H:i:s','!m/d/Y H:i','!n/j/Y G:i','!m/d/Y h:i A','!n/j/Y g:i A','!Y-m-d h:i A'] as $format) {
            $candidate = DateTimeImmutable::createFromFormat($format, $when);
            $errors = DateTimeImmutable::getLastErrors();
            if ($candidate && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) { $dt = $candidate; break; }
        }
        if (!$dt) throw new RuntimeException('Invalid date/time. Use YYYY-MM-DD HH:MM or MM/DD/YYYY HH:MM.');
        $key = preg_replace('/[^A-Z]/', '', strtoupper($status));
        $event = match ($key) {
            'CIN','CHECKIN','IN' => 'IN', 'COUT','CHECKOUT','OUT' => 'OUT',
            'TIMEIN' => 'TIME_IN', 'TIMEOUT' => 'TIME_OUT',
            'LUNCHOUT','BREAKOUT' => 'LUNCH_OUT', 'LUNCHIN','BREAKIN' => 'LUNCH_IN',
            default => throw new RuntimeException('Unknown punch status. Use C/In, C/Out, Time In/Out or Lunch In/Out.')
        };
        return ['biometric_id' => $id, 'punched_at' => $dt->format('Y-m-d H:i:s'), 'punch_status' => $event];
    }

    private static function csv(string $path): array
    {
        $f = fopen($path, 'rb');
        if (!$f) throw new RuntimeException('Unable to read CSV.');
        try {
            $first = (string)fgets($f); rewind($f);
            $delimiter = substr_count($first, "\t") > substr_count($first, ',') ? "\t" : (substr_count($first, ';') > substr_count($first, ',') ? ';' : ',');
            $rows = [];
            while (($r = fgetcsv($f, 0, $delimiter, '"', '')) !== false) {
                $rows[] = $r;
                if (count($rows) > 100001) throw new RuntimeException('Split exports into at most 100,000 rows.');
            }
            return $rows;
        } finally { fclose($f); }
    }

    private static function excelDate(float $serial): string
    {
        return (new DateTimeImmutable('1899-12-30'))->modify('+'.(int)round($serial * 86400).' seconds')->format('Y-m-d H:i:s');
    }

    private static function xlsx(string $path): array
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('Enable PHP zip extension for XLSX uploads, or use CSV/PDF.');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('Invalid XLSX file.');
        try {
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) { $total += (int)($zip->statIndex($i)['size'] ?? 0); if ($total > 100 * 1024 * 1024) throw new RuntimeException('XLSX expands beyond the 100 MB limit.'); }
            $readXml = static function(string $name) use ($zip): ?SimpleXMLElement {
                $s = $zip->getFromName($name);
                if ($s === false) return null;
                if (stripos($s, '<!DOCTYPE') !== false || stripos($s, '<!ENTITY') !== false) throw new RuntimeException('Invalid workbook XML.');
                $old = libxml_use_internal_errors(true);
                try { $x = simplexml_load_string($s, SimpleXMLElement::class, LIBXML_NONET); if (!$x) throw new RuntimeException('Unreadable workbook XML.'); return $x; }
                finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
            };
            $shared = [];
            $strings = $readXml('xl/sharedStrings.xml');
            if ($strings) foreach ($strings->si as $si) { $parts = $si->xpath('.//*[local-name()="t"]') ?: []; $shared[] = implode('', array_map('strval', $parts)); }
            $sheet = $readXml('xl/worksheets/sheet1.xml');
            if (!$sheet) throw new RuntimeException('Keep biometric data in the first worksheet.');
            $rows = [];
            foreach ($sheet->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    preg_match('/^([A-Z]+)/', (string)$c['r'], $m); $index = 0;
                    foreach (str_split($m[1] ?? 'A') as $letter) $index = $index * 26 + ord($letter) - 64;
                    $value = (string)$c->v;
                    if ((string)$c['t'] === 's') $value = $shared[(int)$value] ?? '';
                    elseif ((string)$c['t'] === 'inlineStr') $value = implode('', array_map('strval', $c->xpath('.//*[local-name()="t"]') ?: []));
                    $cells[$index - 1] = $value;
                }
                if ($cells) { $filled = array_fill(0, max(array_keys($cells)) + 1, ''); foreach ($cells as $i => $v) $filled[$i] = $v; $rows[] = $filled; }
                if (count($rows) > 100001) throw new RuntimeException('Split exports into at most 100,000 rows.');
            }
            return $rows;
        } finally { $zip->close(); }
    }
}
