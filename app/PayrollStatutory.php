<?php
declare(strict_types=1);

/**
 * Payroll engine P1 (migration 045): effectivity-dated statutory tables entered by one person and verified by another
 * (HRIS-Vault/02-Modules/Payroll.md Flow 8, BR-503, BR-514). No rate lives in code: every figure is NEEDS VERIFICATION
 * and comes from the circular named in source_reference. Row column meaning per table_code is in COLUMNS.
 */
final class PayrollStatutory
{
    public const CODES = ['SSS'=>'SSS','PHILHEALTH'=>'PhilHealth','PAGIBIG'=>'Pag-IBIG','BIR_WITHHOLDING'=>'BIR withholding tax'];
    public const FREQUENCIES = ['MONTHLY'=>'Monthly','SEMI_MONTHLY'=>'Semi-monthly','WEEKLY'=>'Weekly','DAILY'=>'Daily'];
    /** Entry columns per table, in paste order. bracket_to and base_ceiling may be blank. */
    public const COLUMNS = [
        'SSS'=>['bracket_from'=>'Compensation from','bracket_to'=>'Compensation to','employee_amount'=>'Employee share','employer_amount'=>'Employer share (incl. EC)'],
        'PHILHEALTH'=>['bracket_from'=>'Salary floor','bracket_to'=>'Salary ceiling','employee_rate'=>'Employee %','employer_rate'=>'Employer %'],
        'PAGIBIG'=>['bracket_from'=>'Compensation from','bracket_to'=>'Compensation to','employee_rate'=>'Employee %','employer_rate'=>'Employer %','base_ceiling'=>'Max compensation base'],
        'BIR_WITHHOLDING'=>['bracket_from'=>'Taxable from','bracket_to'=>'Taxable to','fixed_amount'=>'Fixed tax','rate_percent'=>'% of excess over "from"'],
    ];
    private const OPTIONAL = ['bracket_to','base_ceiling'];
    private const PERCENTS = ['employee_rate','employer_rate','rate_percent'];

    public static function ready(): bool
    {
        static $ready=null;
        return $ready ??= (bool)db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='payroll_stat_table_rows'")->fetchColumn();
    }

    /** Parses pasted rows: tab-separated (spreadsheet paste, empty cell = blank) or space-separated with "-" for blank. */
    public static function parseRows(string $code, string $text): array
    {
        $cols=self::COLUMNS[$code] ?? throw new RuntimeException('Choose a table type.');
        $rows=[];
        foreach(preg_split('/\R/',trim($text)) as $n=>$line) {
            if(trim($line)==='') continue;
            $cells=str_contains($line,"\t") ? array_map('trim',explode("\t",rtrim($line))) : preg_split('/\s+/',trim($line));
            if(count($cells)>count($cols)) throw new RuntimeException('Line '.($n+1).' has more than '.count($cols).' values.');
            $row=[];
            foreach(array_keys($cols) as $i=>$key) {
                $raw=$cells[$i] ?? '';
                if($raw==='' || $raw==='-') {
                    if(!in_array($key,self::OPTIONAL,true)) throw new RuntimeException('Line '.($n+1).': '.$cols[$key].' is required.');
                    $row[$key]=null; continue;
                }
                $clean=str_replace(',','',$raw);
                if(!preg_match('/^\d{1,11}(\.\d{1,4})?$/',$clean)) throw new RuntimeException('Line '.($n+1).': '.$cols[$key].' must be a number with up to 4 decimals.');
                if(in_array($key,self::PERCENTS,true) && (float)$clean>100) throw new RuntimeException('Line '.($n+1).': '.$cols[$key].' cannot be above 100.');
                $row[$key]=$clean;
            }
            $rows[]=$row;
        }
        self::checkBrackets($code,$rows);
        return $rows;
    }

    /** Brackets ascend without overlap; only the last row may be open-ended; PhilHealth is one floor/ceiling row. */
    public static function checkBrackets(string $code, array $rows): void
    {
        if(!$rows) throw new RuntimeException('Enter at least one row.');
        if($code==='PHILHEALTH' && count($rows)!==1) throw new RuntimeException('PhilHealth takes one row: floor, ceiling, employee %, employer %.');
        $last=count($rows)-1;
        foreach($rows as $i=>$r) {
            if($r['bracket_to']!==null && (float)$r['bracket_to']<(float)$r['bracket_from']) throw new RuntimeException('Row '.($i+1).': "to" is below "from".');
            if($r['bracket_to']===null && $i<$last) throw new RuntimeException('Row '.($i+1).': only the last row may leave "to" blank.');
            // "from" may equal the previous "to" (BIR "over 20,833" style); the lower row wins at that exact amount.
            if($i>0 && (float)$r['bracket_from']<(float)$rows[$i-1]['bracket_to']) throw new RuntimeException('Row '.($i+1).' overlaps or is not above row '.$i.'.');
        }
    }

    /**
     * Employee and employer amounts for one base (BR-512: four decimals, rounding to 2 happens on presentation/payment).
     * SSS: bracket amounts. PhilHealth: base clamped to floor/ceiling x rates. Pag-IBIG: bracket rates x base capped at max.
     * BIR: fixed + rate x (base - from); employer is 0.
     */
    public static function contribution(string $code, array $rows, float $base): array
    {
        if($base<0) throw new RuntimeException('Base cannot be negative.');
        if($code==='PHILHEALTH') {
            $r=$rows[0] ?? throw new RuntimeException('PhilHealth table has no row.');
            $b=max((float)$r['bracket_from'],$r['bracket_to']===null?$base:min($base,(float)$r['bracket_to']));
            return self::pair($b*(float)$r['employee_rate']/100,$b*(float)$r['employer_rate']/100);
        }
        $r=null;
        foreach($rows as $row) if($base>=(float)$row['bracket_from'] && ($row['bracket_to']===null || $base<=(float)$row['bracket_to'])) { $r=$row; break; }
        if($r===null) throw new RuntimeException(self::CODES[$code].' table has no bracket for '.number_format($base,2).'.');
        return match($code) {
            'SSS'=>self::pair((float)$r['employee_amount'],(float)$r['employer_amount']),
            'PAGIBIG'=>(function() use($r,$base) { $b=$r['base_ceiling']===null?$base:min($base,(float)$r['base_ceiling']); return self::pair($b*(float)$r['employee_rate']/100,$b*(float)$r['employer_rate']/100); })(),
            'BIR_WITHHOLDING'=>self::pair((float)$r['fixed_amount']+($base-(float)$r['bracket_from'])*(float)$r['rate_percent']/100,0.0),
            default=>throw new RuntimeException('Unknown table type.'),
        };
    }

    private static function pair(float $ee, float $er): array { return ['employee'=>round($ee,4),'employer'=>round($er,4)]; }

    public static function versions(): array
    {
        return db()->query('SELECT t.*,ue.full_name entered_name,uv.full_name verified_name,(SELECT COUNT(*) FROM payroll_stat_table_rows r WHERE r.table_id=t.id) row_count
            FROM payroll_stat_tables t JOIN users ue ON ue.id=t.entered_by LEFT JOIN users uv ON uv.id=t.verified_by
            ORDER BY t.table_code,t.pay_frequency,t.effective_from DESC')->fetchAll();
    }

    public static function version(int $id): array
    {
        $q=db()->prepare('SELECT * FROM payroll_stat_tables WHERE id=?'); $q->execute([$id]);
        $t=$q->fetch() ?: throw new RuntimeException('Statutory table not found.');
        $q=db()->prepare('SELECT * FROM payroll_stat_table_rows WHERE table_id=? ORDER BY bracket_from'); $q->execute([$id]);
        return $t+['rows'=>$q->fetchAll()];
    }

    /** The verified version in force on $date (BR-514: unverified versions are never returned). */
    public static function forDate(string $code, string $frequency, string $date): ?array
    {
        $q=db()->prepare('SELECT id FROM payroll_stat_tables WHERE table_code=? AND pay_frequency=? AND verified_at IS NOT NULL AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) ORDER BY effective_from DESC LIMIT 1');
        $q->execute([$code,$frequency,$date,$date]);
        $id=(int)$q->fetchColumn();
        return $id ? self::version($id) : null;
    }

    /** Creates or replaces an unverified draft. Verified versions are locked; a correction is a new version. */
    public static function saveDraft(int $id, array $data, int $actor): int
    {
        $code=(string)($data['table_code']??'');
        $freq=$code==='BIR_WITHHOLDING' ? (string)($data['pay_frequency']??'') : 'MONTHLY';
        if(!isset(self::CODES[$code])) throw new RuntimeException('Choose a table type.');
        if(!isset(self::FREQUENCIES[$freq])) throw new RuntimeException('Choose the payroll frequency this BIR table is for.');
        $label=trim((string)($data['version_label']??'')); $source=trim((string)($data['source_reference']??''));
        if($label==='' || mb_strlen($label)>60) throw new RuntimeException('Enter a version label (up to 60 characters).');
        if($source==='' || mb_strlen($source)>150) throw new RuntimeException('Enter the circular or source reference (up to 150 characters).');
        $from=(string)($data['effective_from']??'');
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$from);
        if(!$d || $d->format('Y-m-d')!==$from) throw new RuntimeException('Enter a valid effective date.');
        $rows=self::parseRows($code,(string)($data['rows']??''));
        db()->beginTransaction();
        try {
            if($id>0) {
                $q=db()->prepare('SELECT verified_at FROM payroll_stat_tables WHERE id=? FOR UPDATE'); $q->execute([$id]);
                $existing=$q->fetch() ?: throw new RuntimeException('Statutory table not found.');
                if($existing['verified_at']!==null) throw new RuntimeException('A verified table is locked. Enter a new version instead.');
                db()->prepare('UPDATE payroll_stat_tables SET table_code=?,pay_frequency=?,version_label=?,effective_from=?,source_reference=?,entered_by=?,entered_at=NOW() WHERE id=?')->execute([$code,$freq,$label,$from,$source,$actor,$id]);
                db()->prepare('DELETE FROM payroll_stat_table_rows WHERE table_id=?')->execute([$id]);
            } else {
                db()->prepare('INSERT INTO payroll_stat_tables(table_code,pay_frequency,version_label,effective_from,source_reference,entered_by) VALUES(?,?,?,?,?,?)')->execute([$code,$freq,$label,$from,$source,$actor]);
                $id=(int)db()->lastInsertId();
            }
            $ins=db()->prepare('INSERT INTO payroll_stat_table_rows(table_id,bracket_from,bracket_to,fixed_amount,rate_percent,employee_amount,employer_amount,employee_rate,employer_rate,base_ceiling) VALUES(?,?,?,?,?,?,?,?,?,?)');
            foreach($rows as $r) $ins->execute([$id,$r['bracket_from'],$r['bracket_to'],$r['fixed_amount']??null,$r['rate_percent']??null,$r['employee_amount']??null,$r['employer_amount']??null,$r['employee_rate']??null,$r['employer_rate']??null,$r['base_ceiling']??null]);
            db()->commit();
        } catch(PDOException $e) {
            db()->rollBack();
            if($e->getCode()==='23000') throw new RuntimeException('A version of this table already starts on that date.');
            throw $e;
        } catch(Throwable $e) { db()->rollBack(); throw $e; }
        audit('payroll','stat_table_saved','payroll_stat_tables',$id,['table_code'=>$code,'pay_frequency'=>$freq,'effective_from'=>$from,'rows'=>count($rows)]);
        return $id;
    }

    /** Second person verifies; the previous open version is closed the day before, so versions never overlap or gap. */
    public static function verify(int $id, int $actor): void
    {
        db()->beginTransaction();
        try {
            $q=db()->prepare('SELECT * FROM payroll_stat_tables WHERE id=? FOR UPDATE'); $q->execute([$id]);
            $t=$q->fetch() ?: throw new RuntimeException('Statutory table not found.');
            if($t['verified_at']!==null) throw new RuntimeException('This table is already verified.');
            if((int)$t['entered_by']===$actor) throw new RuntimeException('A different person must verify the table that you entered.');
            $q=db()->prepare('SELECT MAX(effective_from) FROM payroll_stat_tables WHERE table_code=? AND pay_frequency=? AND verified_at IS NOT NULL FOR UPDATE');
            $q->execute([$t['table_code'],$t['pay_frequency']]); $latest=$q->fetchColumn();
            if($latest!==null && $latest>=$t['effective_from']) throw new RuntimeException('A verified version already starts on or after '.$t['effective_from'].'. New versions must start later.');
            db()->prepare('UPDATE payroll_stat_tables SET effective_to=DATE_SUB(?,INTERVAL 1 DAY) WHERE table_code=? AND pay_frequency=? AND verified_at IS NOT NULL AND effective_to IS NULL')->execute([$t['effective_from'],$t['table_code'],$t['pay_frequency']]);
            db()->prepare('UPDATE payroll_stat_tables SET verified_by=?,verified_at=NOW() WHERE id=?')->execute([$actor,$id]);
            db()->commit();
        } catch(Throwable $e) { db()->rollBack(); throw $e; }
        audit('payroll','stat_table_verified','payroll_stat_tables',$id,['table_code'=>$t['table_code'],'effective_from'=>$t['effective_from']]);
    }

    /** Only an unverified draft can be discarded; verified versions are history that payroll runs cite. */
    public static function discard(int $id): void
    {
        $q=db()->prepare('DELETE FROM payroll_stat_tables WHERE id=? AND verified_at IS NULL'); $q->execute([$id]);
        if($q->rowCount()!==1) throw new RuntimeException('Only an unverified draft can be discarded.');
        audit('payroll','stat_table_discarded','payroll_stat_tables',$id);
    }

    /** Rows back to paste text, for editing a draft. */
    public static function rowsText(string $code, array $rows): string
    {
        $fmt=fn($v)=>$v===null?'-':(str_contains((string)$v,'.')?rtrim(rtrim((string)$v,'0'),'.'):(string)$v);
        return implode("\n",array_map(fn($r)=>implode(' ',array_map(fn($k)=>$fmt($r[$k]),array_keys(self::COLUMNS[$code]))),$rows));
    }
}
