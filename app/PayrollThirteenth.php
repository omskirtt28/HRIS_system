<?php
declare(strict_types=1);

require_once __DIR__.'/PayrollRun.php';

/**
 * 13th month pay (migration 050; HRIS-Vault/02-Modules/Payroll.md Flow 6, John 2026-11-17).
 * NEEDS VERIFICATION: divisor 12, what counts as basic and the tax-exempt ceiling are statutory. Defaults chosen by John:
 * basic = BASE_CODES lines of the year's RELEASED payroll runs; active employees only (archived: inactive, resigned, terminated = back pay);
 * the ceiling is typed on each run (never seeded) and the excess is recorded as taxable, not withheld.
 */
final class PayrollThirteenth
{
    /** Earning codes counted as basic pay (PayrollRun::earnings): no OT, night, premium, adjustments or carried balances. */
    public const BASE_CODES = ['BASIC','REGULAR','LEAVE','ABSENT','LATE'];
    public const ACCOUNT = '5030'; // 13th Month Pay in the sample COA (migration 039); mappable in Accounting Settings

    public static function ready(): bool
    {
        static $ready=null;
        return $ready ??= PayrollRun::ready() && (bool)db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='payroll_13th_employees'")->fetchColumn();
    }

    /** Pure: [amount, taxable] in pesos (2dp) from the year's basic pay and the exempt ceiling. */
    public static function amount(float $basic, float $ceiling): array
    {
        $amount=$basic>0 ? round($basic/12,2) : 0.0;
        return [$amount, max(0.0,round($amount-$ceiling,2))];
    }

    public static function runs(): array
    {
        return db()->query('SELECT r.*,uc.full_name computed_name,ur.full_name released_name,e.entry_no FROM payroll_13th_runs r JOIN users uc ON uc.id=r.computed_by
            LEFT JOIN users ur ON ur.id=r.released_by LEFT JOIN acct_journal_entries e ON e.id=r.entry_id ORDER BY r.pay_year DESC,r.id DESC LIMIT 50')->fetchAll();
    }

    public static function run(int $id): array
    {
        $q=db()->prepare('SELECT r.*,uc.full_name computed_name,ur.full_name released_name,e.entry_no FROM payroll_13th_runs r JOIN users uc ON uc.id=r.computed_by
            LEFT JOIN users ur ON ur.id=r.released_by LEFT JOIN acct_journal_entries e ON e.id=r.entry_id WHERE r.id=?');
        $q->execute([$id]); $r=$q->fetch() ?: throw new RuntimeException('13th month run not found.');
        $q=db()->prepare('SELECT p.*,e.employee_no,CONCAT_WS(" ",e.first_name,e.last_name) employee_name FROM payroll_13th_employees p JOIN employees e ON e.id=p.employee_id WHERE p.run_id=? ORDER BY e.last_name,e.first_name');
        $q->execute([$id]); $r['employees']=$q->fetchAll();
        return $r;
    }

    /** Last ceiling typed, to prefill the form (HR still confirms it every year). */
    public static function lastCeiling(): ?string
    {
        $v=db()->query('SELECT exempt_ceiling FROM payroll_13th_runs WHERE status<>"VOIDED" ORDER BY pay_year DESC,id DESC LIMIT 1')->fetchColumn();
        return $v===false ? null : (string)$v;
    }

    /** Computes (or recomputes while COMPUTED) the 13th month run of a year. */
    public static function compute(string $year, string $payDate, string $ceiling, int $actor): int
    {
        if(!preg_match('/^\d{4}$/',$year) || (int)$year<2000 || (int)$year>2100) throw new RuntimeException('Enter a valid year.');
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$payDate);
        if(!$d || $d->format('Y-m-d')!==$payDate) throw new RuntimeException('Enter a valid pay date.');
        if($payDate<"$year-01-01") throw new RuntimeException('The pay date cannot be before the year starts.');
        $ceiling=trim($ceiling);
        if(!preg_match('/^\d{1,13}(\.\d{1,2})?$/',$ceiling)) throw new RuntimeException('Enter the tax-exempt ceiling (pesos, for example 90000.00).');
        $pdo=db(); $pdo->beginTransaction();
        try {
            $q=$pdo->prepare('SELECT id,status FROM payroll_13th_runs WHERE active_year=? FOR UPDATE'); $q->execute([$year]); $run=$q->fetch();
            if($run && $run['status']==='RELEASED') throw new RuntimeException("The $year 13th month pay is already released and locked.");
            // Unreleased runs would be left out of the base, so they must be final first.
            $q=$pdo->prepare('SELECT COUNT(*) FROM payroll_runs r JOIN payroll_cutoffs c ON c.id=r.cutoff_id WHERE r.status="COMPUTED" AND YEAR(c.period_end)=?'); $q->execute([$year]);
            if((int)$q->fetchColumn()) throw new RuntimeException("Release or void the computed payroll runs of $year first; only released pay counts.");
            if($run) {
                $id=(int)$run['id'];
                $pdo->prepare('DELETE FROM payroll_13th_employees WHERE run_id=?')->execute([$id]);
                $pdo->prepare('UPDATE payroll_13th_runs SET pay_date=?,exempt_ceiling=?,computed_by=?,computed_at=NOW() WHERE id=?')->execute([$payDate,$ceiling,$actor,$id]);
            } else {
                $pdo->prepare('INSERT INTO payroll_13th_runs(pay_year,pay_date,exempt_ceiling,computed_by) VALUES(?,?,?,?)')->execute([$year,$payDate,$ceiling,$actor]);
                $id=(int)$pdo->lastInsertId();
            }
            $codes=implode(',',array_map(fn($c)=>$pdo->quote($c),self::BASE_CODES));
            // Archived (inactive, resigned, terminated) employees get theirs in back pay (PayrollFinalPay).
            $archived=implode(',',array_map(fn($c)=>$pdo->quote($c),EmployeeRepository::ARCHIVED_STATUSES));
            $q=$pdo->prepare("SELECT pre.employee_id,SUM(l.amount) basic FROM payroll_run_lines l JOIN payroll_run_employees pre ON pre.id=l.run_employee_id
                JOIN payroll_runs r ON r.id=pre.run_id JOIN payroll_cutoffs c ON c.id=r.cutoff_id JOIN employees e ON e.id=pre.employee_id
                WHERE r.status='RELEASED' AND YEAR(c.period_end)=? AND l.kind='EARNING' AND l.code IN ($codes) AND e.status NOT IN ($archived)
                GROUP BY pre.employee_id");
            $q->execute([$year]);
            $ins=$pdo->prepare('INSERT INTO payroll_13th_employees(run_id,employee_id,basic_total,amount,taxable) VALUES(?,?,?,?,?)');
            $n=0; $total=0.0; $taxable=0.0;
            foreach($q->fetchAll() as $row) {
                [$amount,$tax]=self::amount((float)$row['basic'],(float)$ceiling);
                if($amount<=0) continue;
                $ins->execute([$id,(int)$row['employee_id'],$row['basic'],$amount,$tax]);
                $n++; $total+=$amount; $taxable+=$tax;
            }
            $pdo->prepare('UPDATE payroll_13th_runs SET employee_count=?,total_amount=?,total_taxable=? WHERE id=?')->execute([$n,round($total,2),round($taxable,2),$id]);
            $pdo->commit();
        } catch(Throwable $e) { $pdo->rollBack(); throw $e; }
        audit('payroll','13th_computed','payroll_13th_runs',$id,['year'=>(int)$year,'employees'=>$n]);
        return $id;
    }

    public static function void(int $id, int $actor): void
    {
        $q=db()->prepare('UPDATE payroll_13th_runs SET status="VOIDED",voided_by=?,voided_at=NOW() WHERE id=? AND status="COMPUTED"'); $q->execute([$actor,$id]);
        if(!$q->rowCount()) throw new RuntimeException('Only a computed (unreleased) 13th month run can be voided.');
        audit('payroll','13th_voided','payroll_13th_runs',$id);
    }

    /** Second person releases: the run locks, employees see it, one DRAFT journal entry Dr 13th month pay / Cr net pay account. */
    public static function release(int $id, int $actor): void
    {
        $pdo=db(); $pdo->beginTransaction();
        try {
            $q=$pdo->prepare('SELECT * FROM payroll_13th_runs WHERE id=? FOR UPDATE'); $q->execute([$id]);
            $r=$q->fetch() ?: throw new RuntimeException('13th month run not found.');
            if($r['status']!=='COMPUTED') throw new RuntimeException('Only a computed run can be released.');
            if((int)$r['computed_by']===$actor) throw new RuntimeException('A different person must release the 13th month pay that you computed.');
            if((int)$r['employee_count']===0) throw new RuntimeException('This run has no employees.');
            $entry=null;
            if(class_exists('Accounting') && Accounting::ready()) {
                Accounting::assertOpen($r['pay_date']);
                require_once __DIR__.'/AccountingSetup.php';
                $acc=AccountingSetup::payrollAccounts(PayrollRun::ACCOUNTS);
                $cents=(int)round((float)$r['total_amount']*100); $memo='13th month pay '.$r['pay_year'];
                $lines=[[$acc['thirteenth'],$memo,$cents,0],[$acc['bank'],"$memo net pay",0,$cents]];
                $ids=$pdo->prepare('SELECT id FROM acct_accounts WHERE code=? AND is_active=1');
                foreach($lines as &$line) { $ids->execute([$line[0]]); $line[0]=(int)$ids->fetchColumn() ?: throw new RuntimeException('Chart of Accounts needs an active account '.$line[0].' for 13th month posting.'); } unset($line);
                $entry=Accounting::insertHeader($r['pay_date'],"$memo, pay date ".$r['pay_date'],$actor,null);
                Accounting::insertLines($entry,$lines);
            }
            $pdo->prepare('UPDATE payroll_13th_runs SET status="RELEASED",released_by=?,released_at=NOW(),entry_id=? WHERE id=?')->execute([$actor,$entry,$id]);
            $pdo->commit();
        } catch(Throwable $e) { $pdo->rollBack(); throw $e; }
        audit('payroll','13th_released','payroll_13th_runs',$id,['entry_id'=>$entry]);
    }

    /** Released 13th month pay of one employee, newest first (My Profile > Additional). */
    public static function forEmployee(int $employeeId): array
    {
        $q=db()->prepare('SELECT r.pay_year,r.pay_date,p.basic_total,p.amount,p.taxable FROM payroll_13th_employees p JOIN payroll_13th_runs r ON r.id=p.run_id
            WHERE p.employee_id=? AND r.status="RELEASED" ORDER BY r.pay_year DESC');
        $q->execute([$employeeId]); return $q->fetchAll();
    }
}
