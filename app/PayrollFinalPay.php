<?php
declare(strict_types=1);

require_once __DIR__.'/PayrollThirteenth.php';

/**
 * Back pay / final pay (migration 051; HRIS-Vault/02-Modules/Payroll.md Flow 7, John 2026-11-17).
 * Back pay = pro-rated 13th month of the separation year + leave conversion + manual earnings, less manual deductions
 * (accountabilities) and tax adjustments. The last salary stays in the regular payroll run (no double pay).
 * Release: a second person who confirms "Clearance is complete" (no Clearance module yet); one DRAFT journal entry.
 * NEEDS VERIFICATION: which leave converts and at what rate (company policy / SIL), tax on back pay (manual for now).
 */
final class PayrollFinalPay
{
    /** shortcut: SL only (John 2026-11-17, until the client meeting); add codes here when other leave converts. */
    public const CONVERT_LEAVE = ['SL'];
    public const KINDS = ['EARNING'=>'Earning','DEDUCTION'=>'Deduction (accountability, advance)','TAX'=>'Withholding tax adjustment'];

    public static function ready(): bool
    {
        static $ready=null;
        return $ready ??= PayrollThirteenth::ready() && (bool)db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='payroll_final_pay_items'")->fetchColumn();
    }

    /**
     * Pure settlement. $in: basic (year to date), ceiling, paid13 (13th month already released for the year),
     * leave_days, daily_rate (null = unknown), items [[kind, amount]]. Returns the figures and blocking issues.
     */
    public static function settle(array $in): array
    {
        $issues=[];
        [$thirteenth,$taxable]=!empty($in['paid13']) ? [0.0,0.0] : PayrollThirteenth::amount((float)$in['basic'],(float)$in['ceiling']);
        $days=max(0.0,round((float)$in['leave_days'],2)); $daily=$in['daily_rate']; $leave=0.0;
        if($days>0) {
            if($daily===null) $issues[]='Leave conversion needs the employee\'s salary rate and the site work schedule (shift and break).';
            else $leave=round($days*(float)$daily,2);
        }
        $earn=0.0; $ded=0.0; $tax=0.0;
        foreach($in['items']??[] as [$kind,$amount]) { if($kind==='EARNING') $earn+=(float)$amount; elseif($kind==='TAX') $tax+=(float)$amount; else $ded+=(float)$amount; }
        $earnings=round($thirteenth+$leave+$earn,2); $deductions=round($ded+$tax,2); $net=round($earnings-$deductions,2);
        if($net<0) $issues[]='Deductions are more than the back pay. Lower the deductions; the balance is collected outside payroll.';
        return ['thirteenth'=>$thirteenth,'taxable'=>$taxable,'leave_days'=>$days,'daily_rate'=>$daily===null?0.0:round((float)$daily,4),'leave'=>$leave,
            'manual_earnings'=>round($earn,2),'manual_deductions'=>round($ded,2),'tax'=>round($tax,2),'earnings'=>$earnings,'deductions'=>$deductions,'net'=>$net,'issues'=>$issues];
    }

    /** Archived employees (inactive, resigned, terminated) with their live back pay, if any. */
    public static function candidates(): array
    {
        $archived=implode(',',array_map(fn($c)=>db()->quote($c),EmployeeRepository::ARCHIVED_STATUSES));
        return db()->query("SELECT e.id,e.employee_no,e.first_name,e.last_name,e.status,f.id final_pay_id,f.status final_status,f.net,f.pay_date
            FROM employees e LEFT JOIN payroll_final_pays f ON f.active_employee_id=e.id WHERE e.status IN ($archived) ORDER BY f.id IS NOT NULL, e.last_name, e.first_name LIMIT 500")->fetchAll();
    }

    public static function get(int $id): array
    {
        $q=db()->prepare('SELECT f.*,e.employee_no,e.status employee_status,CONCAT_WS(" ",e.first_name,e.last_name) employee_name,uc.full_name computed_name,ur.full_name released_name,j.entry_no
            FROM payroll_final_pays f JOIN employees e ON e.id=f.employee_id JOIN users uc ON uc.id=f.computed_by LEFT JOIN users ur ON ur.id=f.released_by
            LEFT JOIN acct_journal_entries j ON j.id=f.entry_id WHERE f.id=?');
        $q->execute([$id]); $f=$q->fetch() ?: throw new RuntimeException('Back pay record not found.');
        $q=db()->prepare('SELECT * FROM payroll_final_pay_items WHERE final_pay_id=? ORDER BY id'); $q->execute([$id]); $f['items']=$q->fetchAll();
        return $f;
    }

    /** Separation date = effective date of the history entry that moved the employee to the current archived status. */
    public static function separationDate(int $employeeId): ?string
    {
        $q=db()->prepare('SELECT h.effective_date FROM employee_employment_history h JOIN employees e ON e.id=h.employee_id AND h.to_status=e.status WHERE h.employee_id=? ORDER BY h.effective_date DESC,h.id DESC LIMIT 1');
        $q->execute([$employeeId]); $d=$q->fetchColumn(); return $d===false ? null : (string)$d;
    }

    /** Computes (or recomputes while COMPUTED) the back pay of one archived employee. Manual items are kept. */
    public static function compute(int $employeeId, string $payDate, string $ceiling, int $actor): int
    {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$payDate);
        if(!$d || $d->format('Y-m-d')!==$payDate) throw new RuntimeException('Enter a valid pay date.');
        $ceiling=trim($ceiling);
        if(!preg_match('/^\d{1,13}(\.\d{1,2})?$/',$ceiling)) throw new RuntimeException('Enter the tax-exempt ceiling (pesos, for example 90000.00).');
        $pdo=db(); $pdo->beginTransaction();
        try {
            $q=$pdo->prepare('SELECT id,status,branch_id FROM employees WHERE id=? FOR UPDATE'); $q->execute([$employeeId]);
            $e=$q->fetch() ?: throw new RuntimeException('Employee record not found.');
            if(!in_array($e['status'],EmployeeRepository::ARCHIVED_STATUSES,true)) throw new RuntimeException('Back pay is only for employees in the Employee Archive (inactive, resigned or terminated).');
            $sep=self::separationDate($employeeId) ?? throw new RuntimeException('No separation date found in the employee\'s employment history.');
            if($payDate<$sep) throw new RuntimeException('The pay date cannot be before the separation date ('.$sep.').');
            $year=substr($sep,0,4);
            $q=$pdo->prepare('SELECT COUNT(*) FROM payroll_runs r JOIN payroll_run_employees pre ON pre.run_id=r.id JOIN payroll_cutoffs c ON c.id=r.cutoff_id WHERE r.status="COMPUTED" AND pre.employee_id=? AND YEAR(c.period_end)=?');
            $q->execute([$employeeId,$year]);
            if((int)$q->fetchColumn()) throw new RuntimeException('Release or void the employee\'s computed payroll first; only released pay counts toward the 13th month.');
            $q=$pdo->prepare('SELECT id,status FROM payroll_final_pays WHERE active_employee_id=? FOR UPDATE'); $q->execute([$employeeId]); $fp=$q->fetch();
            if($fp && $fp['status']==='RELEASED') throw new RuntimeException('This employee\'s back pay is already released and locked.');

            $codes=implode(',',array_map(fn($c)=>$pdo->quote($c),PayrollThirteenth::BASE_CODES));
            $q=$pdo->prepare("SELECT COALESCE(SUM(l.amount),0) FROM payroll_run_lines l JOIN payroll_run_employees pre ON pre.id=l.run_employee_id JOIN payroll_runs r ON r.id=pre.run_id
                JOIN payroll_cutoffs c ON c.id=r.cutoff_id WHERE r.status='RELEASED' AND pre.employee_id=? AND YEAR(c.period_end)=? AND l.kind='EARNING' AND l.code IN ($codes)");
            $q->execute([$employeeId,$year]); $basic=(float)$q->fetchColumn();
            $q=$pdo->prepare('SELECT COUNT(*) FROM payroll_13th_employees p JOIN payroll_13th_runs r ON r.id=p.run_id WHERE p.employee_id=? AND r.pay_year=? AND r.status="RELEASED"');
            $q->execute([$employeeId,$year]); $paid13=(bool)$q->fetchColumn();
            $lc=implode(',',array_map(fn($c)=>$pdo->quote($c),self::CONVERT_LEAVE));
            $q=$pdo->prepare("SELECT COALESCE(SUM(b.balance),0) FROM employee_leave_balances b JOIN leave_types t ON t.id=b.leave_type_id WHERE b.employee_id=? AND t.code IN ($lc)");
            $q->execute([$employeeId]); $days=(float)$q->fetchColumn();
            $q=$pdo->prepare('SELECT * FROM payroll_employee_rates WHERE employee_id=? AND effective_from<=? ORDER BY effective_from DESC LIMIT 1'); $q->execute([$employeeId,$sep]); $rate=$q->fetch();
            $sched=PayrollRun::scheduleMinutes(PayrollAttendanceService::attendancePolicy((int)$e['branch_id']));
            $daily=$rate && $sched ? (float)$rate['salary_amount']/(float)$rate['hourly_divisor']*$sched/60 : null;

            if($fp) {
                $id=(int)$fp['id'];
                $pdo->prepare('UPDATE payroll_final_pays SET separation_date=?,pay_date=?,exempt_ceiling=?,computed_by=?,computed_at=NOW() WHERE id=?')->execute([$sep,$payDate,$ceiling,$actor,$id]);
            } else {
                $pdo->prepare('INSERT INTO payroll_final_pays(employee_id,separation_date,pay_date,exempt_ceiling,issues_json,computed_by) VALUES(?,?,?,?,"[]",?)')->execute([$employeeId,$sep,$payDate,$ceiling,$actor]);
                $id=(int)$pdo->lastInsertId();
            }
            $q=$pdo->prepare('SELECT kind,amount FROM payroll_final_pay_items WHERE final_pay_id=?'); $q->execute([$id]);
            $s=self::settle(['basic'=>$basic,'ceiling'=>$ceiling,'paid13'=>$paid13,'leave_days'=>$days,'daily_rate'=>$daily,'items'=>array_map(fn($i)=>[$i['kind'],$i['amount']],$q->fetchAll())]);
            $pdo->prepare('UPDATE payroll_final_pays SET basic_ytd=?,thirteenth=?,thirteenth_taxable=?,leave_days=?,daily_rate=?,leave_amount=?,earnings_total=?,deductions_total=?,net=?,issues_json=? WHERE id=?')
                ->execute([$basic,$s['thirteenth'],$s['taxable'],$s['leave_days'],$s['daily_rate'],$s['leave'],$s['earnings'],$s['deductions'],$s['net'],json_encode($s['issues'],JSON_THROW_ON_ERROR),$id]);
            $pdo->commit();
        } catch(Throwable $ex) { $pdo->rollBack(); throw $ex; }
        audit('payroll','final_pay_computed','payroll_final_pays',$id,['employee_id'=>$employeeId,'net'=>$s['net']]);
        return $id;
    }

    private static function open(int $id): array
    {
        $q=db()->prepare('SELECT * FROM payroll_final_pays WHERE id=?'); $q->execute([$id]);
        $f=$q->fetch() ?: throw new RuntimeException('Back pay record not found.');
        if($f['status']!=='COMPUTED') throw new RuntimeException('Only a computed (unreleased) back pay can be changed.');
        return $f;
    }

    private static function recompute(array $f, int $actor): void
    {
        self::compute((int)$f['employee_id'],(string)$f['pay_date'],(string)$f['exempt_ceiling'],$actor);
    }

    public static function addItem(int $id, string $kind, string $label, string $amount, int $actor): void
    {
        $f=self::open($id);
        if(!isset(self::KINDS[$kind])) throw new RuntimeException('Choose earning, deduction or tax adjustment.');
        $label=trim($label); if($label==='' || mb_strlen($label)>120) throw new RuntimeException('Enter a description (up to 120 characters).');
        $amount=trim(str_replace(',','',$amount));
        if(!preg_match('/^\d{1,13}(\.\d{1,2})?$/',$amount) || (float)$amount<=0) throw new RuntimeException('Enter an amount greater than zero, up to 2 decimals.');
        db()->prepare('INSERT INTO payroll_final_pay_items(final_pay_id,kind,label,amount,created_by) VALUES(?,?,?,?,?)')->execute([$id,$kind,$label,$amount,$actor]);
        audit('payroll','final_pay_item_added','payroll_final_pays',$id,['kind'=>$kind,'amount'=>$amount]);
        self::recompute($f,$actor);
    }

    public static function removeItem(int $id, int $itemId, int $actor): void
    {
        $f=self::open($id);
        $q=db()->prepare('DELETE FROM payroll_final_pay_items WHERE id=? AND final_pay_id=?'); $q->execute([$itemId,$id]);
        if(!$q->rowCount()) throw new RuntimeException('Item not found.');
        audit('payroll','final_pay_item_removed','payroll_final_pays',$id,['item_id'=>$itemId]);
        self::recompute($f,$actor);
    }

    public static function void(int $id, int $actor): void
    {
        self::open($id);
        db()->prepare('UPDATE payroll_final_pays SET status="VOIDED",voided_by=?,voided_at=NOW() WHERE id=? AND status="COMPUTED"')->execute([$actor,$id]);
        audit('payroll','final_pay_voided','payroll_final_pays',$id);
    }

    /** Journal lines in cents [account code, description, debit, credit]; net pay absorbs rounding. */
    public static function journalLines(array $f, array $sums, array $accounts, string $memo): array
    {
        $c=fn($v)=>(int)round((float)$v*100);
        $dr=['thirteenth'=>$c($f['thirteenth']),'salaries'=>$c($f['leave_amount'])+$c($sums['EARNING'])];
        $cr=['advances'=>$c($sums['DEDUCTION']),'WHT'=>$c($sums['TAX'])];
        $cr['bank']=array_sum($dr)-array_sum($cr);
        $names=['thirteenth'=>'pro-rated 13th month','salaries'=>'leave conversion and other pay','advances'=>'accountabilities','WHT'=>'withholding tax','bank'=>'net pay'];
        $lines=[];
        foreach($dr as $k=>$v) if($v>0) $lines[]=[$accounts[$k],"$memo ".$names[$k],$v,0];
        foreach($cr as $k=>$v) if($v>0) $lines[]=[$accounts[$k],"$memo ".$names[$k],0,$v];
        return $lines;
    }

    /** Second person releases after confirming clearance: locks the record and sends one DRAFT journal entry to Accounting. */
    public static function release(int $id, int $actor, bool $clearance): void
    {
        if(!$clearance) throw new RuntimeException('Confirm that the employee\'s clearance is complete before releasing back pay.');
        $pdo=db(); $pdo->beginTransaction();
        try {
            $q=$pdo->prepare('SELECT f.*,e.employee_no FROM payroll_final_pays f JOIN employees e ON e.id=f.employee_id WHERE f.id=? FOR UPDATE'); $q->execute([$id]);
            $f=$q->fetch() ?: throw new RuntimeException('Back pay record not found.');
            if($f['status']!=='COMPUTED') throw new RuntimeException('Only a computed back pay can be released.');
            if((int)$f['computed_by']===$actor) throw new RuntimeException('A different person must release the back pay that you computed.');
            if(json_decode($f['issues_json'],true)) throw new RuntimeException('Resolve the issues, then recompute.');
            if((float)$f['earnings_total']<=0) throw new RuntimeException('There is no back pay to release.');
            $entry=null;
            if(class_exists('Accounting') && Accounting::ready()) {
                Accounting::assertOpen($f['pay_date']);
                require_once __DIR__.'/AccountingSetup.php';
                $q=$pdo->prepare('SELECT kind,SUM(amount) FROM payroll_final_pay_items WHERE final_pay_id=? GROUP BY kind'); $q->execute([$id]);
                $sums=$q->fetchAll(PDO::FETCH_KEY_PAIR)+['EARNING'=>0,'DEDUCTION'=>0,'TAX'=>0];
                $memo='Back pay '.$f['employee_no'];
                $lines=self::journalLines($f,$sums,AccountingSetup::payrollAccounts(PayrollRun::ACCOUNTS),$memo);
                $ids=$pdo->prepare('SELECT id FROM acct_accounts WHERE code=? AND is_active=1');
                foreach($lines as &$line) { $ids->execute([$line[0]]); $line[0]=(int)$ids->fetchColumn() ?: throw new RuntimeException('Chart of Accounts needs an active account '.$line[0].' for back pay posting.'); } unset($line);
                $entry=Accounting::insertHeader($f['pay_date'],"$memo, pay date ".$f['pay_date'],$actor,null);
                Accounting::insertLines($entry,$lines);
            }
            $pdo->prepare('UPDATE payroll_final_pays SET status="RELEASED",released_by=?,released_at=NOW(),clearance_confirmed_at=NOW(),entry_id=? WHERE id=?')->execute([$actor,$entry,$id]);
            $pdo->commit();
        } catch(Throwable $ex) { $pdo->rollBack(); throw $ex; }
        audit('payroll','final_pay_released','payroll_final_pays',$id,['entry_id'=>$entry,'clearance_confirmed'=>true]);
    }
}
