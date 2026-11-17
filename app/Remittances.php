<?php
declare(strict_types=1);
require_once __DIR__.'/Accounting.php';
require_once __DIR__.'/AccountsPayable.php';
require_once __DIR__.'/CashBank.php';

/**
 * Accounting Phase 2 slice A4 (migration 044): SSS / PhilHealth / Pag-IBIG / BIR remittances.
 * A remittance's lines live in its draft journal entry (Dr each payable line / Cr the bank's cash account), so its status is the
 * entry's status and Submit / Approve / Void / Reverse are the normal journal actions. Bank reconciliation sees it as a bank line.
 * Deadlines and BIR form numbers are NEEDS VERIFICATION: the screens show "To confirm" and nothing is computed from them.
 */
final class Remittances
{
    // Agency => [label, default payable account code from the 039 sample chart]. shortcut: codes fixed; move to a setting if a client renumbers.
    public const AGENCIES=['SSS'=>['SSS','2100'],'PHILHEALTH'=>['PhilHealth','2110'],'PAGIBIG'=>['Pag-IBIG','2120'],'BIR'=>['BIR','2130']];

    private const SQL='SELECT r.*,b.name AS bank_name,e.entry_no,e.status,e.return_note,
        (SELECT x.entry_no FROM acct_journal_entries x WHERE x.reverses_entry_id=e.id AND x.status=\'POSTED\') AS reversed_by_no
        FROM acct_remittances r JOIN acct_bank_accounts b ON b.id=r.bank_account_id JOIN acct_journal_entries e ON e.id=r.entry_id';

    public static function label(string $agency): string { return self::AGENCIES[$agency][0] ?? $agency; }

    public static function remittances(string $status=''): array
    {
        if(isset(Accounting::STATUS[$status])) { $q=db()->prepare(self::SQL.' WHERE e.status=? ORDER BY r.pay_date DESC,r.id DESC LIMIT 500'); $q->execute([$status]); return $q->fetchAll(); }
        return db()->query(self::SQL.' ORDER BY r.pay_date DESC,r.id DESC LIMIT 500')->fetchAll();
    }

    public static function statusCounts(): array
    {
        return db()->query('SELECT e.status,COUNT(*) FROM acct_remittances r JOIN acct_journal_entries e ON e.id=r.entry_id GROUP BY e.status')->fetchAll(PDO::FETCH_KEY_PAIR)+array_fill_keys(array_keys(Accounting::STATUS),0);
    }

    public static function remittance(int $id): ?array
    {
        $q=db()->prepare(self::SQL.' WHERE r.id=?'); $q->execute([$id]); $r=$q->fetch();
        if(!$r) return null;
        $r['lines']=array_values(array_filter(Accounting::entry((int)$r['entry_id'])['lines'],fn($l)=>(float)$l['debit']>0));
        return $r;
    }

    /** Active liability accounts a remittance can clear. Accounts Payable is paid through disbursements so AP aging stays right. */
    public static function payableAccounts(): array
    {
        return array_values(array_filter(Accounting::accounts(true),fn($a)=>$a['type']==='L' && $a['code']!==AccountsPayable::AP_CODE));
    }

    /** Default payable account id for an agency, or 0 when its sample code is not in the chart. */
    public static function defaultAccount(string $agency): int
    {
        $code=self::AGENCIES[$agency][1] ?? '';
        foreach(self::payableAccounts() as $a) if($a['code']===$code) return (int)$a['id'];
        return 0;
    }

    /** Creates ($id=0) or updates a remittance while its entry is still a DRAFT. $rows = [[account_id, description, amount], ...]. */
    public static function save(int $id,string $agency,string $period,int $bankId,string $payDate,string $ref,string $memo,array $rows,int $userId): int
    {
        if(!isset(self::AGENCIES[$agency])) throw new RuntimeException('Choose the agency.');
        $payDate=Accounting::validDate(trim($payDate)); $ref=trim($ref); $memo=trim(preg_replace('/\s+/',' ',$memo));
        $d=DateTime::createFromFormat('!Y-m',trim($period));
        if(!$d || $d->format('Y-m')!==trim($period)) throw new RuntimeException('Choose the period (month) being remitted.');
        $period=$d->format('Y-m-01');
        if($period>substr($payDate,0,7).'-01') throw new RuntimeException('The period cannot be after the month of the payment date.');
        if($ref==='' || mb_strlen($ref)>50) throw new RuntimeException('Enter the reference no. from the payment (up to 50 characters).');
        if(mb_strlen($memo)>150) throw new RuntimeException('Memo is too long (150 characters).');
        $bank=CashBank::bank($bankId);
        if(!$bank || !$bank['is_active']) throw new RuntimeException('Choose an active bank account.');
        $allowed=array_column(self::payableAccounts(),'id');
        $j=[];
        foreach(array_values($rows) as $i=>$r) {
            if(!is_array($r)) continue;
            $acc=(int)($r['account_id']??0); $amt=trim((string)($r['amount']??''));
            if($acc>0 && $amt==='') throw new RuntimeException('Line '.($i+1).': enter the amount.');
            if($acc>0 && !in_array($acc,array_map('intval',$allowed),true)) throw new RuntimeException('Line '.($i+1).': choose an active payable (liability) account other than Accounts Payable.');
            $j[]=['account_id'=>$acc,'description'=>$r['description']??'','debit'=>$amt];
        }
        $lines=Accounting::parseLines($j);
        if(!$lines) throw new RuntimeException('Add at least one line with a payable account and amount.');
        $total=array_sum(array_column($lines,2));
        $lines[]=[(int)$bank['account_id'],mb_substr(self::label($agency).' '.$d->format('M Y'),0,255),0,$total];
        Accounting::assertOpen($payDate);
        $pdo=db(); $pdo->beginTransaction();
        try {
            // Same payment reference entered twice = remitted twice in the books. A voided remittance frees its reference.
            $dup=$pdo->prepare("SELECT r.remittance_no FROM acct_remittances r JOIN acct_journal_entries e ON e.id=r.entry_id WHERE r.agency=? AND r.reference=? AND r.id<>? AND e.status<>'VOID' FOR UPDATE");
            $dup->execute([$agency,$ref,$id]);
            if($no=$dup->fetchColumn()) throw new RuntimeException("Reference $ref for ".self::label($agency)." is already recorded as $no.");
            $vals=[$agency,$period,$bankId,$payDate,$ref,$memo===''?null:$memo,number_format($total/100,2,'.','')];
            if($id>0) {
                $s=$pdo->prepare('SELECT r.remittance_no,r.entry_id,e.status FROM acct_remittances r JOIN acct_journal_entries e ON e.id=r.entry_id WHERE r.id=? FOR UPDATE'); $s->execute([$id]); $row=$s->fetch();
                if(!$row) throw new RuntimeException('Remittance not found.');
                if($row['status']!=='DRAFT') throw new RuntimeException('Only draft remittances can be edited. Return the entry to draft first, or reverse a posted one.');
                $entry=(int)$row['entry_id'];
                $pdo->prepare('UPDATE acct_journal_entries SET entry_date=?,memo=? WHERE id=?')->execute([$payDate,self::memo($row['remittance_no'],$agency,$d,$ref,$memo),$entry]);
                $pdo->prepare('DELETE FROM acct_journal_lines WHERE entry_id=?')->execute([$entry]);
                $pdo->prepare('UPDATE acct_remittances SET agency=?,period_month=?,bank_account_id=?,pay_date=?,reference=?,memo=?,total=? WHERE id=?')->execute([...$vals,$id]);
            } else {
                $year=substr($payDate,0,4);
                $q=$pdo->prepare('SELECT remittance_no FROM acct_remittances WHERE remittance_no LIKE ? ORDER BY remittance_no DESC LIMIT 1 FOR UPDATE'); $q->execute(["RMT-$year-%"]);
                $no=sprintf('RMT-%s-%04d',$year,(int)substr((string)$q->fetchColumn(),9)+1);
                $entry=Accounting::insertHeader($payDate,self::memo($no,$agency,$d,$ref,$memo),$userId,null);
                $pdo->prepare('INSERT INTO acct_remittances(agency,period_month,bank_account_id,pay_date,reference,memo,total,remittance_no,entry_id,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?)')
                    ->execute([...$vals,$no,$entry,$userId]);
                $id=(int)$pdo->lastInsertId();
            }
            Accounting::insertLines($entry,$lines);
            $pdo->commit();
        } catch(Throwable $e) { $pdo->rollBack(); throw $e; }
        audit('accounting','remittance_save','acct_remittance',$id,['agency'=>$agency,'lines'=>count($lines)-1]);
        return $id;
    }

    private static function memo(string $no,string $agency,DateTime $period,string $ref,string $memo): string
    {
        return mb_substr("Remittance $no: ".self::label($agency).' '.$period->format('F Y')." (ref. $ref)".($memo!==''?" - $memo":''),0,255);
    }

    /**
     * Posted, not reversed remittances of a year in centavos: [month 1..12][agency] => cents. Feeds the "recorded by month" grid,
     * which is how a missing month shows up before the period is closed.
     */
    public static function yearGrid(int $year): array
    {
        $q=db()->prepare("SELECT MONTH(r.period_month) AS m,r.agency,SUM(r.total) AS t FROM acct_remittances r JOIN acct_journal_entries e ON e.id=r.entry_id
            WHERE e.status='POSTED' AND YEAR(r.period_month)=? AND NOT EXISTS (SELECT 1 FROM acct_journal_entries x WHERE x.reverses_entry_id=e.id AND x.status='POSTED')
            GROUP BY m,r.agency");
        $q->execute([$year]);
        $grid=array_fill(1,12,array_fill_keys(array_keys(self::AGENCIES),0));
        foreach($q as $r) $grid[(int)$r['m']][$r['agency']]=(int)round($r['t']*100);
        return $grid;
    }
}
