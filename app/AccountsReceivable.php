<?php
declare(strict_types=1);
require_once __DIR__.'/AccountsPayable.php';

/**
 * Accounting Phase 2 slice A2 (migration 042): customers, sales invoices, AR aging. Mirror of AccountsPayable:
 * an invoice's lines live in its draft journal entry (Dr Accounts Receivable / Cr each income line), so the invoice's status
 * is the entry's status and Submit / Approve / Void / Reverse are the normal journal actions.
 */
final class AccountsReceivable
{
    public const AR_CODE='1100'; // shortcut: AR account found by code; move to a setting if a client renumbers its chart.

    // ---------- Customers ----------

    public static function customers(bool $activeOnly=false): array
    {
        return db()->query('SELECT c.*,(SELECT COUNT(*) FROM acct_invoices i WHERE i.customer_id=c.id) AS invoices FROM acct_customers c'.($activeOnly?' WHERE c.is_active=1':'').' ORDER BY c.name')->fetchAll();
    }

    public static function saveCustomer(int $id,string $name,string $tin,string $contact,int $terms,bool $active): int
    {
        return AccountsPayable::saveParty('customer',$id,$name,$tin,$contact,$terms,$active);
    }

    // ---------- Invoices ----------

    private const INV_SQL='SELECT i.*,c.name AS customer_name,e.entry_no,e.status,e.return_note,
        (SELECT r.entry_no FROM acct_journal_entries r WHERE r.reverses_entry_id=e.id AND r.status=\'POSTED\') AS reversed_by_no
        FROM acct_invoices i JOIN acct_customers c ON c.id=i.customer_id JOIN acct_journal_entries e ON e.id=i.entry_id';

    public static function invoices(string $status=''): array
    {
        if(isset(Accounting::STATUS[$status])) { $q=db()->prepare(self::INV_SQL.' WHERE e.status=? ORDER BY i.invoice_date DESC,i.id DESC LIMIT 500'); $q->execute([$status]); return $q->fetchAll(); }
        return db()->query(self::INV_SQL.' ORDER BY i.invoice_date DESC,i.id DESC LIMIT 500')->fetchAll();
    }

    public static function statusCounts(): array
    {
        return db()->query('SELECT e.status,COUNT(*) FROM acct_invoices i JOIN acct_journal_entries e ON e.id=i.entry_id GROUP BY e.status')->fetchAll(PDO::FETCH_KEY_PAIR)+array_fill_keys(array_keys(Accounting::STATUS),0);
    }

    public static function invoice(int $id): ?array
    {
        $q=db()->prepare(self::INV_SQL.' WHERE i.id=?'); $q->execute([$id]); $i=$q->fetch();
        if(!$i) return null;
        // The invoice's own lines are the entry's credit lines; the debit line is the AR total.
        $i['lines']=array_values(array_filter(Accounting::entry((int)$i['entry_id'])['lines'],fn($l)=>(float)$l['credit']>0));
        return $i;
    }

    public static function arAccount(): int
    {
        $q=db()->prepare("SELECT id FROM acct_accounts WHERE code=? AND type='A' AND is_active=1"); $q->execute([self::AR_CODE]);
        $id=(int)$q->fetchColumn();
        if(!$id) throw new RuntimeException('Invoices need an active asset account '.self::AR_CODE.' (Accounts Receivable) in the chart of accounts.');
        return $id;
    }

    /**
     * Form rows [account_id, description, amount] -> the AR debit plus one credit line each. Returns [lines, total cents].
     * @param array<int,array<string,mixed>> $rows
     */
    public static function invoiceLines(array $rows,int $arId,string $customerName): array
    {
        $j=[];
        foreach(array_values($rows) as $n=>$r) {
            if(!is_array($r)) continue;
            $acc=(int)($r['account_id']??0); $amt=trim((string)($r['amount']??''));
            if($acc>0 && $amt==='') throw new RuntimeException('Line '.($n+1).': enter the amount.');
            if($acc===$arId) throw new RuntimeException('Line '.($n+1).': choose the income account, not Accounts Receivable.');
            $j[]=['account_id'=>$acc,'description'=>$r['description']??'','credit'=>$amt];
        }
        $lines=Accounting::parseLines($j); // active account, amount format, one side only, max lines
        if(!$lines) throw new RuntimeException('Add at least one line with an account and amount.');
        $total=array_sum(array_column($lines,3));
        array_unshift($lines,[$arId,mb_substr($customerName,0,255),$total,0]);
        return [$lines,$total];
    }

    /** Creates ($id=0) or updates an invoice while its entry is still a DRAFT. A blank due date uses the customer's terms. */
    public static function saveInvoice(int $id,int $customerId,string $ref,string $invDate,string $dueDate,string $memo,array $rows,int $userId): int
    {
        $invDate=Accounting::validDate(trim($invDate)); $ref=trim($ref); $memo=trim(preg_replace('/\s+/',' ',$memo));
        $c=db()->prepare('SELECT * FROM acct_customers WHERE id=? AND is_active=1'); $c->execute([$customerId]); $c=$c->fetch();
        if(!$c) throw new RuntimeException('Choose an active customer.');
        $dueDate=trim($dueDate)===''?date('Y-m-d',strtotime($invDate.' +'.(int)$c['terms_days'].' days')):Accounting::validDate(trim($dueDate));
        if($dueDate<$invDate) throw new RuntimeException('The due date cannot be before the invoice date.');
        if(mb_strlen($ref)>50) throw new RuntimeException('Customer PO no. is too long (50 characters).');
        if(mb_strlen($memo)>150) throw new RuntimeException('Memo is too long (150 characters).');
        [$lines,$total]=self::invoiceLines($rows,self::arAccount(),$c['name']);
        Accounting::assertOpen($invDate);
        $pdo=db(); $pdo->beginTransaction();
        try {
            if($id>0) {
                $s=$pdo->prepare('SELECT i.invoice_no,i.entry_id,e.status FROM acct_invoices i JOIN acct_journal_entries e ON e.id=i.entry_id WHERE i.id=? FOR UPDATE'); $s->execute([$id]); $row=$s->fetch();
                if(!$row) throw new RuntimeException('Invoice not found.');
                if($row['status']!=='DRAFT') throw new RuntimeException('Only draft invoices can be edited. Return the entry to draft first, or reverse a posted one.');
                $entry=(int)$row['entry_id'];
                $pdo->prepare('UPDATE acct_journal_entries SET entry_date=?,memo=? WHERE id=?')->execute([$invDate,self::memo($row['invoice_no'],$c['name'],$ref,$memo),$entry]);
                $pdo->prepare('DELETE FROM acct_journal_lines WHERE entry_id=?')->execute([$entry]);
                $pdo->prepare('UPDATE acct_invoices SET customer_id=?,customer_ref=?,memo=?,invoice_date=?,due_date=?,total=? WHERE id=?')
                    ->execute([$customerId,$ref===''?null:$ref,$memo===''?null:$memo,$invDate,$dueDate,number_format($total/100,2,'.',''),$id]);
            } else {
                $year=substr($invDate,0,4);
                $q=$pdo->prepare('SELECT invoice_no FROM acct_invoices WHERE invoice_no LIKE ? ORDER BY invoice_no DESC LIMIT 1 FOR UPDATE'); $q->execute(["INV-$year-%"]);
                $no=sprintf('INV-%s-%04d',$year,(int)substr((string)$q->fetchColumn(),9)+1);
                $entry=Accounting::insertHeader($invDate,self::memo($no,$c['name'],$ref,$memo),$userId,null);
                $pdo->prepare('INSERT INTO acct_invoices(invoice_no,customer_id,customer_ref,memo,invoice_date,due_date,total,entry_id,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?)')
                    ->execute([$no,$customerId,$ref===''?null:$ref,$memo===''?null:$memo,$invDate,$dueDate,number_format($total/100,2,'.',''),$entry,$userId]);
                $id=(int)$pdo->lastInsertId();
            }
            Accounting::insertLines($entry,$lines);
            $pdo->commit();
        } catch(Throwable $e) { $pdo->rollBack(); throw $e; }
        audit('accounting','invoice_save','acct_invoice',$id,['lines'=>count($lines)-1]);
        return $id;
    }

    private static function memo(string $no,string $customer,string $ref,string $memo): string
    {
        return mb_substr("Invoice $no: $customer".($ref!==''?" (PO $ref)":'').($memo!==''?" - $memo":''),0,255);
    }

    /**
     * Open receivables as of a date, per customer and bucket (AccountsPayable::BUCKETS), in centavos.
     * Only posted invoices that no posted reversal cancelled.
     * Open = invoice total minus collections posted by that date (CashBank, slice A3); fully collected invoices drop out.
     */
    public static function aging(string $asOf): array
    {
        $q=db()->prepare("SELECT i.customer_id,c.name,i.total,DATEDIFF(?,i.due_date) AS late,".CashBank::postedPaidSql('invoice','i')." AS paid FROM acct_invoices i JOIN acct_customers c ON c.id=i.customer_id
            JOIN acct_journal_entries e ON e.id=i.entry_id WHERE e.status='POSTED' AND i.invoice_date<=?
            AND NOT EXISTS (SELECT 1 FROM acct_journal_entries r WHERE r.reverses_entry_id=e.id AND r.status='POSTED' AND r.entry_date<=?)");
        $q->execute(array_fill(0,5,$asOf));
        $zero=array_fill_keys(array_keys(AccountsPayable::BUCKETS),0)+['total'=>0]; $rows=[]; $sum=$zero;
        foreach($q as $r) {
            $cents=(int)round($r['total']*100)-(int)round($r['paid']*100); if($cents<=0) continue;
            $k=AccountsPayable::bucket((int)$r['late']); $c=(int)$r['customer_id'];
            $rows[$c]??=['customer'=>$r['name']]+$zero;
            $rows[$c][$k]+=$cents; $rows[$c]['total']+=$cents; $sum[$k]+=$cents; $sum['total']+=$cents;
        }
        uasort($rows,fn($a,$b)=>strcasecmp($a['customer'],$b['customer']));
        return ['rows'=>array_values($rows),'total'=>$sum];
    }
}
