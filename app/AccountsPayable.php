<?php
declare(strict_types=1);
require_once __DIR__.'/Accounting.php';
require_once __DIR__.'/AccountingSetup.php';
require_once __DIR__.'/CashBank.php'; // aging subtracts payments

/**
 * Accounting Phase 2 slice A1 (migration 041): vendors, supplier bills, AP aging.
 * A bill's lines live in its draft journal entry (Dr each line / Cr Accounts Payable), so the bill's status is the entry's status
 * and Submit / Approve / Void / Reverse are the normal journal actions.
 */
final class AccountsPayable
{
    public const AP_CODE='2010'; // shortcut: AP account found by code; move to a setting if a client renumbers its chart.
    public const BUCKETS=['current'=>'Current','d30'=>'1-30 days','d60'=>'31-60 days','d90'=>'61-90 days','over90'=>'Over 90 days'];

    // ---------- Vendors ----------

    public static function vendors(bool $activeOnly=false): array
    {
        return db()->query('SELECT v.*,(SELECT COUNT(*) FROM acct_bills b WHERE b.vendor_id=v.id) AS bills FROM acct_vendors v'.($activeOnly?' WHERE v.is_active=1':'').' ORDER BY v.name')->fetchAll();
    }

    public static function saveVendor(int $id,string $name,string $tin,string $contact,int $terms,bool $active): int
    {
        return self::saveParty('vendor',$id,$name,$tin,$contact,$terms,$active);
    }

    /** Vendors (041) and customers (042) share one shape and one set of rules. */
    public static function saveParty(string $kind,int $id,string $name,string $tin,string $contact,int $terms,bool $active): int
    {
        $table=['vendor'=>'acct_vendors','customer'=>'acct_customers'][$kind] ?? throw new InvalidArgumentException('Unknown party kind.');
        $label=ucfirst($kind);
        $name=trim(preg_replace('/\s+/',' ',$name)); $tin=trim($tin); $contact=trim(preg_replace('/\s+/',' ',$contact));
        if($name==='' || mb_strlen($name)>150) throw new RuntimeException("$label name is required (up to 150 characters).");
        // TIN format and branch code rules are NEEDS VERIFICATION; only the characters are checked.
        if($tin!=='' && !preg_match('/^[0-9-]{1,20}$/',$tin)) throw new RuntimeException('TIN: numbers and dashes only (up to 20).');
        if(mb_strlen($contact)>150) throw new RuntimeException('Contact is too long (150 characters).');
        if($terms<0 || $terms>365) throw new RuntimeException('Payment terms: 0 to 365 days.');
        $pdo=db();
        $dup=$pdo->prepare("SELECT id FROM $table WHERE name=? AND id<>?"); $dup->execute([$name,$id]);
        if($dup->fetchColumn()) throw new RuntimeException("A $kind named $name already exists.");
        $vals=[$name,$tin===''?null:$tin,$contact===''?null:$contact,$terms,$active?1:0];
        if($id>0) {
            $q=$pdo->prepare("UPDATE $table SET name=?,tin=?,contact=?,terms_days=?,is_active=? WHERE id=?"); $q->execute([...$vals,$id]);
            $chk=$pdo->prepare("SELECT 1 FROM $table WHERE id=?"); $chk->execute([$id]);
            if(!$chk->fetchColumn()) throw new RuntimeException("$label not found.");
            audit('accounting',$kind.'_update','acct_'.$kind,$id,['active'=>$active]);
            return $id;
        }
        $pdo->prepare("INSERT INTO $table(name,tin,contact,terms_days,is_active) VALUES(?,?,?,?,?)")->execute($vals);
        $id=(int)$pdo->lastInsertId();
        audit('accounting',$kind.'_create','acct_'.$kind,$id);
        return $id;
    }

    // ---------- Bills ----------

    private const BILL_SQL='SELECT b.*,v.name AS vendor_name,e.entry_no,e.status,e.return_note,
        (SELECT r.entry_no FROM acct_journal_entries r WHERE r.reverses_entry_id=e.id AND r.status=\'POSTED\') AS reversed_by_no
        FROM acct_bills b JOIN acct_vendors v ON v.id=b.vendor_id JOIN acct_journal_entries e ON e.id=b.entry_id';

    public static function bills(string $status=''): array
    {
        if(isset(Accounting::STATUS[$status])) { $q=db()->prepare(self::BILL_SQL.' WHERE e.status=? ORDER BY b.bill_date DESC,b.id DESC LIMIT 500'); $q->execute([$status]); return $q->fetchAll(); }
        return db()->query(self::BILL_SQL.' ORDER BY b.bill_date DESC,b.id DESC LIMIT 500')->fetchAll();
    }

    public static function statusCounts(): array
    {
        return db()->query('SELECT e.status,COUNT(*) FROM acct_bills b JOIN acct_journal_entries e ON e.id=b.entry_id GROUP BY e.status')->fetchAll(PDO::FETCH_KEY_PAIR)+array_fill_keys(array_keys(Accounting::STATUS),0);
    }

    public static function bill(int $id): ?array
    {
        $q=db()->prepare(self::BILL_SQL.' WHERE b.id=?'); $q->execute([$id]); $b=$q->fetch();
        if(!$b) return null;
        // The bill's own lines are the entry's debit lines; the credit line is the AP total.
        $b['lines']=array_values(array_filter(Accounting::entry((int)$b['entry_id'])['lines'],fn($l)=>(float)$l['debit']>0 && !((float)($b['vat_amount']??0)>0 && (int)$l['account_id']===(int)$b['vat_account_id']))); // Input VAT line comes from the tax code
        return $b;
    }

    public static function apAccount(): int
    {
        $q=db()->prepare("SELECT id FROM acct_accounts WHERE code=? AND type='L' AND is_active=1"); $q->execute([self::AP_CODE]);
        $id=(int)$q->fetchColumn();
        if(!$id) throw new RuntimeException('Bills need an active liability account '.self::AP_CODE.' (Accounts Payable) in the chart of accounts.');
        return $id;
    }

    /**
     * Form rows [account_id, description, amount] -> journal lines (one debit each) plus the AP credit. Returns [lines, total cents].
     * @param array<int,array<string,mixed>> $rows
     */
    public static function billLines(array $rows,int $apId,string $vendorName): array
    {
        $j=[];
        foreach(array_values($rows) as $i=>$r) {
            if(!is_array($r)) continue;
            $acc=(int)($r['account_id']??0); $amt=trim((string)($r['amount']??''));
            if($acc>0 && $amt==='') throw new RuntimeException('Line '.($i+1).': enter the amount.');
            if($acc===$apId) throw new RuntimeException('Line '.($i+1).': choose the expense or asset account, not Accounts Payable.');
            $j[]=['account_id'=>$acc,'description'=>$r['description']??'','debit'=>$amt];
        }
        $lines=Accounting::parseLines($j); // active account, amount format, one side only, max lines
        if(!$lines) throw new RuntimeException('Add at least one line with an account and amount.');
        $total=array_sum(array_column($lines,2));
        $lines[]=[$apId,mb_substr($vendorName,0,255),0,$total];
        return [$lines,$total];
    }

    /** Creates ($id=0) or updates a bill while its entry is still a DRAFT. A blank due date uses the vendor's terms. */
    public static function saveBill(int $id,int $vendorId,string $ref,string $billDate,string $dueDate,string $memo,array $rows,int $userId,int $vatId=0,int $ewtId=0): int
    {
        $billDate=Accounting::validDate(trim($billDate)); $ref=trim($ref); $memo=trim(preg_replace('/\s+/',' ',$memo));
        $v=db()->prepare('SELECT * FROM acct_vendors WHERE id=? AND is_active=1'); $v->execute([$vendorId]); $v=$v->fetch();
        if(!$v) throw new RuntimeException('Choose an active vendor.');
        $dueDate=trim($dueDate)===''?date('Y-m-d',strtotime($billDate.' +'.(int)$v['terms_days'].' days')):Accounting::validDate(trim($dueDate));
        if($dueDate<$billDate) throw new RuntimeException('The due date cannot be before the bill date.');
        if(mb_strlen($ref)>50) throw new RuntimeException('Supplier invoice no. is too long (50 characters).');
        if(mb_strlen($memo)>150) throw new RuntimeException('Memo is too long (150 characters).');
        [$lines,$total]=self::billLines($rows,self::apAccount(),$v['name']);
        [$lines,$total,$taxCols]=AccountingSetup::billTax($lines,$total,$vatId,$ewtId);
        Accounting::assertOpen($billDate);
        $pdo=db(); $pdo->beginTransaction();
        try {
            if($ref!=='') {
                // Same supplier invoice entered twice = paid twice. A voided bill frees its number.
                $d=$pdo->prepare("SELECT b.bill_no FROM acct_bills b JOIN acct_journal_entries e ON e.id=b.entry_id WHERE b.vendor_id=? AND b.vendor_ref=? AND b.id<>? AND e.status<>'VOID' FOR UPDATE");
                $d->execute([$vendorId,$ref,$id]);
                if($no=$d->fetchColumn()) throw new RuntimeException("Supplier invoice $ref of this vendor is already recorded as $no.");
            }
            if($id>0) {
                $s=$pdo->prepare('SELECT b.bill_no,b.entry_id,e.status FROM acct_bills b JOIN acct_journal_entries e ON e.id=b.entry_id WHERE b.id=? FOR UPDATE'); $s->execute([$id]); $row=$s->fetch();
                if(!$row) throw new RuntimeException('Bill not found.');
                if($row['status']!=='DRAFT') throw new RuntimeException('Only draft bills can be edited. Return the entry to draft first, or reverse a posted one.');
                $entry=(int)$row['entry_id'];
                $pdo->prepare('UPDATE acct_journal_entries SET entry_date=?,memo=? WHERE id=?')->execute([$billDate,self::memo($row['bill_no'],$v['name'],$ref,$memo),$entry]);
                $pdo->prepare('DELETE FROM acct_journal_lines WHERE entry_id=?')->execute([$entry]);
                $pdo->prepare('UPDATE acct_bills SET vendor_id=?,vendor_ref=?,memo=?,bill_date=?,due_date=?,total=? WHERE id=?')
                    ->execute([$vendorId,$ref===''?null:$ref,$memo===''?null:$memo,$billDate,$dueDate,number_format($total/100,2,'.',''),$id]);
            } else {
                $year=substr($billDate,0,4);
                $q=$pdo->prepare('SELECT bill_no FROM acct_bills WHERE bill_no LIKE ? ORDER BY bill_no DESC LIMIT 1 FOR UPDATE'); $q->execute(["BILL-$year-%"]);
                $no=sprintf('BILL-%s-%04d',$year,(int)substr((string)$q->fetchColumn(),10)+1);
                $entry=Accounting::insertHeader($billDate,self::memo($no,$v['name'],$ref,$memo),$userId,null);
                $pdo->prepare('INSERT INTO acct_bills(bill_no,vendor_id,vendor_ref,memo,bill_date,due_date,total,entry_id,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?)')
                    ->execute([$no,$vendorId,$ref===''?null:$ref,$memo===''?null:$memo,$billDate,$dueDate,number_format($total/100,2,'.',''),$entry,$userId]);
                $id=(int)$pdo->lastInsertId();
            }
            Accounting::insertLines($entry,$lines);
            AccountingSetup::storeColumns('acct_bills',$id,$taxCols);
            $pdo->commit();
        } catch(Throwable $e) { $pdo->rollBack(); throw $e; }
        audit('accounting','bill_save','acct_bill',$id,['lines'=>count($lines)-1]);
        return $id;
    }

    private static function memo(string $no,string $vendor,string $ref,string $memo): string
    {
        return mb_substr("Bill $no: $vendor".($ref!==''?" (inv. $ref)":'').($memo!==''?" - $memo":''),0,255);
    }

    /** Pure: days past due -> bucket key. Due today or later is current. */
    public static function bucket(int $daysPastDue): string
    {
        return $daysPastDue<=0?'current':($daysPastDue<=30?'d30':($daysPastDue<=60?'d60':($daysPastDue<=90?'d90':'over90')));
    }

    /**
     * Open payables as of a date, per vendor and bucket, in centavos. Only posted bills that no posted reversal cancelled.
     * Open = bill total minus disbursements posted by that date (CashBank, slice A3); fully paid bills drop out.
     */
    public static function aging(string $asOf): array
    {
        $q=db()->prepare("SELECT b.vendor_id,v.name,b.total,DATEDIFF(?,b.due_date) AS late,".CashBank::postedPaidSql('bill','b')." AS paid FROM acct_bills b JOIN acct_vendors v ON v.id=b.vendor_id
            JOIN acct_journal_entries e ON e.id=b.entry_id WHERE e.status='POSTED' AND b.bill_date<=?
            AND NOT EXISTS (SELECT 1 FROM acct_journal_entries r WHERE r.reverses_entry_id=e.id AND r.status='POSTED' AND r.entry_date<=?)");
        $q->execute(array_fill(0,5,$asOf));
        $zero=array_fill_keys(array_keys(self::BUCKETS),0)+['total'=>0]; $rows=[]; $sum=$zero;
        foreach($q as $r) {
            $c=(int)round($r['total']*100)-(int)round($r['paid']*100); if($c<=0) continue;
            $k=self::bucket((int)$r['late']); $v=(int)$r['vendor_id'];
            $rows[$v]??=['vendor'=>$r['name']]+$zero;
            $rows[$v][$k]+=$c; $rows[$v]['total']+=$c; $sum[$k]+=$c; $sum['total']+=$c;
        }
        uasort($rows,fn($a,$b)=>strcasecmp($a['vendor'],$b['vendor']));
        return ['rows'=>array_values($rows),'total'=>$sum];
    }
}
