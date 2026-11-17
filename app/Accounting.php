<?php
declare(strict_types=1);

/**
 * Accounting Phase 1 core (migration 039, HRIS-Vault/02-Modules/Accounting.md).
 * Money is handled as integer centavos in PHP and DECIMAL(15,2) in MySQL; only POSTED entries reach the reports.
 */
final class Accounting
{
    public const TYPES=['A'=>'Asset','L'=>'Liability','E'=>'Equity','I'=>'Income','X'=>'Expense'];
    public const STATUS=['DRAFT'=>'Draft','FOR_APPROVAL'=>'For approval','POSTED'=>'Posted','VOID'=>'Voided'];
    private const MAX_LINES=50;

    public static function ready(): bool
    {
        static $ready=null;
        // acct_remittances arrives with migration 044, which needs 039 to 043: one check covers all six.
        return $ready ??= (bool)db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='acct_remittances'")->fetchColumn();
    }

    /** "1,234.5" -> 123450. Throws on anything that is not a non-negative amount with at most 2 decimals. */
    public static function cents(string $raw): int
    {
        $v=str_replace([',',' '],'',trim($raw));
        if($v==='') return 0;
        if(!preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/',$v,$m)) throw new RuntimeException('Amounts must be numbers with up to 2 decimals.');
        return (int)$m[1]*100+(int)str_pad($m[2]??'0',2,'0');
    }

    public static function money(int $cents): string
    {
        $s=number_format(abs($cents)/100,2);
        return $cents<0?'('.$s.')':$s;
    }

    private static function toCents(mixed $decimal): int { return (int)round(((float)$decimal)*100); }

    public static function debitNormal(string $type): bool { return $type==='A' || $type==='X'; }

    // ---------- Chart of accounts ----------

    public static function accounts(bool $activeOnly=false): array
    {
        return db()->query('SELECT a.*,(SELECT COUNT(*) FROM acct_journal_lines l WHERE l.account_id=a.id) AS used FROM acct_accounts a'.($activeOnly?' WHERE a.is_active=1':'').' ORDER BY a.code')->fetchAll();
    }

    public static function saveAccount(int $id,string $code,string $name,string $type,bool $active): int
    {
        $code=trim($code); $name=trim(preg_replace('/\s+/',' ',$name));
        if(!preg_match('/^[0-9A-Za-z-]{1,20}$/',$code)) throw new RuntimeException('Account code: 1 to 20 letters, numbers or dashes.');
        if($name==='' || mb_strlen($name)>150) throw new RuntimeException('Account name is required (up to 150 characters).');
        if(!isset(self::TYPES[$type])) throw new RuntimeException('Choose an account type.');
        $pdo=db();
        $dup=$pdo->prepare('SELECT id FROM acct_accounts WHERE code=? AND id<>?'); $dup->execute([$code,$id]);
        if($dup->fetchColumn()) throw new RuntimeException('Account code '.$code.' is already used.');
        if($id>0) {
            $old=$pdo->prepare('SELECT a.*,(SELECT COUNT(*) FROM acct_journal_lines l WHERE l.account_id=a.id) AS used FROM acct_accounts a WHERE a.id=?'); $old->execute([$id]); $old=$old->fetch();
            if(!$old) throw new RuntimeException('Account not found.');
            // Changing code or type of a used account would silently move history to another report section.
            if((int)$old['used']>0 && ($old['type']!==$type || $old['code']!==$code)) throw new RuntimeException('This account already has journal lines; its code and type can no longer change. Deactivate it and add a new account instead.');
            $pdo->prepare('UPDATE acct_accounts SET code=?,name=?,type=?,is_active=? WHERE id=?')->execute([$code,$name,$type,$active?1:0,$id]);
            audit('accounting','account_update','acct_account',$id,['code'=>$code,'active'=>$active]);
            return $id;
        }
        $pdo->prepare('INSERT INTO acct_accounts(code,name,type,is_active) VALUES(?,?,?,?)')->execute([$code,$name,$type,$active?1:0]);
        $id=(int)$pdo->lastInsertId();
        audit('accounting','account_create','acct_account',$id,['code'=>$code]);
        return $id;
    }

    // ---------- Periods ----------

    public static function monthOf(string $date): string { return substr($date,0,7).'-01'; }

    public static function isClosed(string $date): bool
    {
        $q=db()->prepare('SELECT 1 FROM acct_closed_periods WHERE period_month=?'); $q->execute([self::monthOf($date)]);
        return (bool)$q->fetchColumn();
    }

    public static function assertOpen(string $date): void
    {
        if(self::isClosed($date)) throw new RuntimeException(date('F Y',strtotime($date)).' is closed. Ask the Finance Head to reopen it, or use a date in an open month.');
    }

    /** Last 12 months up to the current one, newest first, with pending/posted counts. */
    public static function periods(): array
    {
        $out=[]; $closed=db()->query('SELECT p.period_month,p.closed_at,u.full_name FROM acct_closed_periods p LEFT JOIN users u ON u.id=p.closed_by_user_id')->fetchAll(PDO::FETCH_UNIQUE);
        $counts=db()->query("SELECT DATE_FORMAT(entry_date,'%Y-%m-01') m,SUM(status='POSTED') posted,SUM(status IN ('DRAFT','FOR_APPROVAL')) pending FROM acct_journal_entries GROUP BY m")->fetchAll(PDO::FETCH_UNIQUE);
        // Month-close checklist (advisory; only pending entries block the close): active banks reconciled, agencies with a posted, not reversed remittance.
        // shortcut: counts every active bank for every month, even before the bank was opened; add an opened-on date if that misleads.
        require_once __DIR__.'/Remittances.php';
        $banks=(int)db()->query('SELECT COUNT(*) FROM acct_bank_accounts WHERE is_active=1')->fetchColumn();
        $recon=db()->query('SELECT r.period_month,COUNT(*) FROM acct_bank_recons r JOIN acct_bank_accounts b ON b.id=r.bank_account_id AND b.is_active=1 GROUP BY r.period_month')->fetchAll(PDO::FETCH_KEY_PAIR);
        $remit=db()->query("SELECT r.period_month,COUNT(DISTINCT r.agency) FROM acct_remittances r JOIN acct_journal_entries e ON e.id=r.entry_id
            WHERE e.status='POSTED' AND NOT EXISTS (SELECT 1 FROM acct_journal_entries x WHERE x.reverses_entry_id=e.id AND x.status='POSTED') GROUP BY r.period_month")->fetchAll(PDO::FETCH_KEY_PAIR);
        $months=array_merge(array_keys($closed),array_keys($counts),array_keys($recon),array_keys($remit));
        for($i=0;$i<12;$i++) $months[]=date('Y-m-01',strtotime(date('Y-m-01')." -$i month"));
        $months=array_unique($months); rsort($months);
        foreach($months as $m) $out[]=['month'=>$m,'closed'=>$closed[$m]??null,'posted'=>(int)($counts[$m]['posted']??0),'pending'=>(int)($counts[$m]['pending']??0),
            'banks_total'=>$banks,'banks_done'=>(int)($recon[$m]??0),'remit_total'=>count(Remittances::AGENCIES),'remit_done'=>(int)($remit[$m]??0)];
        return $out;
    }

    public static function closeMonth(string $month,int $userId): void
    {
        if(!preg_match('/^\d{4}-\d{2}-01$/',$month) || !strtotime($month)) throw new RuntimeException('Choose a month.');
        if($month>date('Y-m-01')) throw new RuntimeException('A future month cannot be closed.');
        $q=db()->prepare("SELECT COUNT(*) FROM acct_journal_entries WHERE status IN ('DRAFT','FOR_APPROVAL') AND entry_date BETWEEN ? AND LAST_DAY(?)"); $q->execute([$month,$month]);
        if((int)$q->fetchColumn()>0) throw new RuntimeException('Post or return every draft and submitted entry dated '.date('F Y',strtotime($month)).' before closing it.');
        db()->prepare('INSERT IGNORE INTO acct_closed_periods(period_month,closed_by_user_id) VALUES(?,?)')->execute([$month,$userId]);
        audit('accounting','period_close','acct_period',null,['month'=>$month]);
    }

    public static function reopenMonth(string $month): void
    {
        $q=db()->prepare('DELETE FROM acct_closed_periods WHERE period_month=?'); $q->execute([$month]);
        if($q->rowCount()<1) throw new RuntimeException('That month is not closed.');
        audit('accounting','period_reopen','acct_period',null,['month'=>$month]);
    }

    // ---------- Journal ----------

    public static function entries(string $status=''): array
    {
        $sql='SELECT e.*,u.full_name AS created_by_name,a.full_name AS approved_by_name,(SELECT COALESCE(SUM(debit),0) FROM acct_journal_lines l WHERE l.entry_id=e.id) AS total_debit,
              r.entry_no AS reversed_by_no FROM acct_journal_entries e LEFT JOIN users u ON u.id=e.created_by_user_id LEFT JOIN users a ON a.id=e.approved_by_user_id
              LEFT JOIN acct_journal_entries r ON r.reverses_entry_id=e.id';
        if(isset(self::STATUS[$status])) { $q=db()->prepare($sql.' WHERE e.status=? ORDER BY e.entry_date DESC,e.id DESC LIMIT 500'); $q->execute([$status]); return $q->fetchAll(); }
        return db()->query($sql.' ORDER BY e.entry_date DESC,e.id DESC LIMIT 500')->fetchAll();
    }

    public static function statusCounts(): array
    {
        return db()->query('SELECT status,COUNT(*) FROM acct_journal_entries GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR)+array_fill_keys(array_keys(self::STATUS),0);
    }

    public static function entry(int $id): ?array
    {
        $q=db()->prepare('SELECT e.*,u.full_name AS created_by_name,a.full_name AS approved_by_name,o.entry_no AS reverses_no,r.id AS reversed_by_id,r.entry_no AS reversed_by_no,b.id AS bill_id,b.bill_no,i.id AS invoice_id,i.invoice_no,p.id AS payment_id,p.payment_no,rm.id AS remittance_id,rm.remittance_no
            FROM acct_journal_entries e LEFT JOIN users u ON u.id=e.created_by_user_id LEFT JOIN users a ON a.id=e.approved_by_user_id
            LEFT JOIN acct_journal_entries o ON o.id=e.reverses_entry_id LEFT JOIN acct_journal_entries r ON r.reverses_entry_id=e.id LEFT JOIN acct_bills b ON b.entry_id=e.id LEFT JOIN acct_invoices i ON i.entry_id=e.id LEFT JOIN acct_payments p ON p.entry_id=e.id LEFT JOIN acct_remittances rm ON rm.entry_id=e.id WHERE e.id=?');
        $q->execute([$id]); $e=$q->fetch(); if(!$e) return null;
        $l=db()->prepare('SELECT l.*,a.code,a.name,a.type FROM acct_journal_lines l JOIN acct_accounts a ON a.id=l.account_id WHERE l.entry_id=? ORDER BY l.line_no'); $l->execute([$id]);
        $e['lines']=$l->fetchAll();
        $e['debit']=array_sum(array_map(fn($x)=>self::toCents($x['debit']),$e['lines']));
        $e['credit']=array_sum(array_map(fn($x)=>self::toCents($x['credit']),$e['lines']));
        return $e;
    }

    /**
     * Validates posted form lines. Blank rows are skipped. Returns [[account_id,description,debit_cents,credit_cents],...].
     * @param array<int,array<string,mixed>> $rows
     */
    public static function parseLines(array $rows): array
    {
        $active=db()->query('SELECT id FROM acct_accounts WHERE is_active=1')->fetchAll(PDO::FETCH_COLUMN); $active=array_flip(array_map('intval',$active));
        $out=[];
        foreach(array_values($rows) as $i=>$r) {
            if(!is_array($r)) continue;
            $acc=(int)($r['account_id']??0); $desc=trim((string)($r['description']??''));
            $dr=self::cents((string)($r['debit']??'')); $cr=self::cents((string)($r['credit']??''));
            if($acc===0 && $dr===0 && $cr===0 && $desc==='') continue;
            $n=$i+1;
            if(!isset($active[$acc])) throw new RuntimeException("Line $n: choose an active account.");
            if(($dr>0)===($cr>0)) throw new RuntimeException("Line $n: enter either a debit or a credit amount, not both or neither.");
            if(mb_strlen($desc)>255) throw new RuntimeException("Line $n: description is too long (255 characters).");
            $out[]=[$acc,$desc===''?null:$desc,$dr,$cr];
        }
        if(count($out)>self::MAX_LINES) throw new RuntimeException('An entry can have up to '.self::MAX_LINES.' lines.');
        return $out;
    }

    public static function validDate(string $date): string
    {
        $d=DateTime::createFromFormat('!Y-m-d',$date);
        if(!$d || $d->format('Y-m-d')!==$date) throw new RuntimeException('Enter a valid entry date.');
        return $date;
    }

    /** Creates ($id=0) or updates a DRAFT. Drafts may be unbalanced; Submit enforces balance. */
    public static function saveDraft(int $id,string $date,string $memo,array $rows,int $userId): int
    {
        $date=self::validDate(trim($date)); $memo=trim(preg_replace('/\s+/',' ',$memo));
        if($memo==='' || mb_strlen($memo)>255) throw new RuntimeException('Memo is required (up to 255 characters).');
        $lines=self::parseLines($rows);
        if(!$lines) throw new RuntimeException('Add at least one line.');
        self::assertOpen($date);
        $pdo=db(); $pdo->beginTransaction();
        try {
            if($id>0) {
                $s=$pdo->prepare('SELECT status,entry_kind FROM acct_journal_entries WHERE id=? FOR UPDATE'); $s->execute([$id]); $row=$s->fetch();
                if(($row['status']??'')!=='DRAFT') throw new RuntimeException('Only draft entries can be edited.');
                if($row['entry_kind']==='CLOSING') throw new RuntimeException('A closing entry is built from the books. Void it and create it again instead of editing it.');
                $b=$pdo->prepare('SELECT bill_no FROM acct_bills WHERE entry_id=?'); $b->execute([$id]);
                if($no=$b->fetchColumn()) throw new RuntimeException("This entry belongs to bill $no. Edit the bill instead so its total stays in step.");
                $b=$pdo->prepare('SELECT invoice_no FROM acct_invoices WHERE entry_id=?'); $b->execute([$id]);
                if($no=$b->fetchColumn()) throw new RuntimeException("This entry belongs to invoice $no. Edit the invoice instead so its total stays in step.");
                $b=$pdo->prepare('SELECT payment_no FROM acct_payments WHERE entry_id=?'); $b->execute([$id]);
                if($no=$b->fetchColumn()) throw new RuntimeException("This entry belongs to payment $no. Edit the payment instead so its amounts stay in step.");
                $b=$pdo->prepare('SELECT remittance_no FROM acct_remittances WHERE entry_id=?'); $b->execute([$id]);
                if($no=$b->fetchColumn()) throw new RuntimeException("This entry belongs to remittance $no. Edit the remittance instead so its total stays in step.");
                $pdo->prepare('UPDATE acct_journal_entries SET entry_date=?,memo=? WHERE id=?')->execute([$date,$memo,$id]);
                $pdo->prepare('DELETE FROM acct_journal_lines WHERE entry_id=?')->execute([$id]);
            } else {
                $id=self::insertHeader($date,$memo,$userId,null);
            }
            self::insertLines($id,$lines);
            $pdo->commit();
        } catch(Throwable $e) { $pdo->rollBack(); throw $e; }
        audit('accounting','journal_save','acct_journal_entry',$id,['lines'=>count($lines)]);
        return $id;
    }

    /** Callers own the transaction (journal, bills). */
    public static function insertHeader(string $date,string $memo,int $userId,?int $reverses,string $kind='NORMAL'): int
    {
        $pdo=db(); $year=substr($date,0,4);
        // Serialises numbering per year inside the caller's transaction; the unique key is the final guard.
        $q=$pdo->prepare("SELECT entry_no FROM acct_journal_entries WHERE entry_no LIKE ? ORDER BY entry_no DESC LIMIT 1 FOR UPDATE"); $q->execute(["JE-$year-%"]);
        $next=(int)substr((string)$q->fetchColumn(),8)+1;
        $pdo->prepare('INSERT INTO acct_journal_entries(entry_no,entry_date,memo,status,entry_kind,reverses_entry_id,created_by_user_id) VALUES(?,?,?,?,?,?,?)')
            ->execute([sprintf('JE-%s-%04d',$year,$next),$date,$memo,'DRAFT',$kind,$reverses,$userId]);
        return (int)$pdo->lastInsertId();
    }

    public static function insertLines(int $id,array $lines): void
    {
        $ins=db()->prepare('INSERT INTO acct_journal_lines(entry_id,line_no,account_id,description,debit,credit) VALUES(?,?,?,?,?,?)');
        foreach($lines as $i=>[$acc,$desc,$dr,$cr]) $ins->execute([$id,$i+1,$acc,$desc,number_format($dr/100,2,'.',''),number_format($cr/100,2,'.','')]);
    }

    /** Locks the row and checks its status; returns the entry with lines. */
    private static function lockEntry(int $id,string $expected): array
    {
        $s=db()->prepare('SELECT status FROM acct_journal_entries WHERE id=? FOR UPDATE'); $s->execute([$id]);
        $status=$s->fetchColumn();
        if($status===false) throw new RuntimeException('Journal entry not found.');
        if($status!==$expected) throw new RuntimeException('This entry is already '.strtolower(self::STATUS[$status]??$status).'. Refresh the page.');
        return self::entry($id);
    }

    public static function submit(int $id): void
    {
        $pdo=db(); $pdo->beginTransaction();
        try {
            $e=self::lockEntry($id,'DRAFT');
            self::assertOpen($e['entry_date']);
            if(count($e['lines'])<2) throw new RuntimeException('An entry needs at least two lines.');
            if($e['debit']!==$e['credit']) throw new RuntimeException('Debits ('.self::money($e['debit']).') and credits ('.self::money($e['credit']).') must be equal before submitting.');
            $pdo->prepare("UPDATE acct_journal_entries SET status='FOR_APPROVAL',submitted_at=NOW(),return_note=NULL WHERE id=?")->execute([$id]);
            $pdo->commit();
        } catch(Throwable $x) { $pdo->rollBack(); throw $x; }
        audit('accounting','journal_submit','acct_journal_entry',$id);
    }

    public static function returnToDraft(int $id,string $note): void
    {
        $note=trim($note);
        if($note==='' || mb_strlen($note)>255) throw new RuntimeException('Give the reason for returning the entry (up to 255 characters).');
        $pdo=db(); $pdo->beginTransaction();
        try {
            self::lockEntry($id,'FOR_APPROVAL');
            $pdo->prepare("UPDATE acct_journal_entries SET status='DRAFT',submitted_at=NULL,return_note=? WHERE id=?")->execute([$note,$id]);
            $pdo->commit();
        } catch(Throwable $x) { $pdo->rollBack(); throw $x; }
        audit('accounting','journal_return','acct_journal_entry',$id);
    }

    public static function approve(int $id,int $userId): void
    {
        $pdo=db(); $pdo->beginTransaction();
        try {
            $e=self::lockEntry($id,'FOR_APPROVAL');
            if((int)$e['created_by_user_id']===$userId) throw new RuntimeException('You prepared this entry, so another approver must post it.');
            self::assertOpen($e['entry_date']);
            if($e['reverses_entry_id']) self::assertUnpaid((int)$e['reverses_entry_id']); // a payment may have come in after the reversal draft
            if($e['debit']!==$e['credit'] || $e['debit']===0) throw new RuntimeException('The entry is not balanced and cannot be posted.');
            $pdo->prepare("UPDATE acct_journal_entries SET status='POSTED',approved_by_user_id=?,posted_at=NOW() WHERE id=?")->execute([$userId,$id]);
            $pdo->commit();
        } catch(Throwable $x) { $pdo->rollBack(); throw $x; }
        audit('accounting','journal_post','acct_journal_entry',$id);
    }

    /** Creates a DRAFT that swaps every debit and credit of a posted entry. It goes through approval like any entry. */
    public static function reverse(int $id,string $date,int $userId): int
    {
        $date=self::validDate(trim($date)); self::assertOpen($date);
        $pdo=db(); $pdo->beginTransaction();
        try {
            $e=self::lockEntry($id,'POSTED');
            if($e['reversed_by_id']) throw new RuntimeException('This entry already has a reversing entry ('.$e['reversed_by_no'].').');
            if($date<$e['entry_date']) throw new RuntimeException('The reversal date cannot be before the original entry date.');
            self::assertUnpaid($id);
            $new=self::insertHeader($date,mb_substr('Reversal of '.$e['entry_no'].': '.$e['memo'],0,255),$userId,$id,$e['entry_kind']); // a reversed closing stays out of the income statement too
            self::insertLines($new,array_map(fn($l)=>[(int)$l['account_id'],$l['description'],self::toCents($l['credit']),self::toCents($l['debit'])],$e['lines']));
            $pdo->commit();
        } catch(Throwable $x) { $pdo->rollBack(); throw $x; }
        audit('accounting','journal_reverse','acct_journal_entry',$new,['reverses'=>$id]);
        return $new;
    }

    /**
     * A bill or invoice entry that a live payment (not voided, not reversed) still pays cannot be reversed: reverse the payment first.
     * Locking read, so it waits for a payment being saved on the same document (CashBank::savePayment locks the document row).
     */
    private static function assertUnpaid(int $entryId): void
    {
        $q=db()->prepare("SELECT p.payment_no FROM acct_payment_allocations al JOIN acct_payments p ON p.id=al.payment_id JOIN acct_journal_entries pe ON pe.id=p.entry_id
            LEFT JOIN acct_bills b ON b.id=al.bill_id LEFT JOIN acct_invoices i ON i.id=al.invoice_id
            WHERE (b.entry_id=? OR i.entry_id=?) AND pe.status<>'VOID'
            AND NOT EXISTS (SELECT 1 FROM acct_journal_entries rv WHERE rv.reverses_entry_id=pe.id AND rv.status='POSTED') LIMIT 1 LOCK IN SHARE MODE");
        $q->execute([$entryId,$entryId]);
        if($no=$q->fetchColumn()) throw new RuntimeException("Payment $no is recorded against this document. Void or reverse the payment first.");
    }

    /** Discards a draft. The row and its JE number stay (no gaps); a voided reversal frees the original to be reversed again. */
    public static function void(int $id,string $reason,int $userId): void
    {
        $reason=trim($reason);
        if($reason==='' || mb_strlen($reason)>255) throw new RuntimeException('Give the reason for voiding the entry (up to 255 characters).');
        $pdo=db(); $pdo->beginTransaction();
        try {
            self::lockEntry($id,'DRAFT');
            $pdo->prepare("UPDATE acct_journal_entries SET status='VOID',void_reason=?,voided_by_user_id=?,voided_at=NOW(),reverses_entry_id=NULL WHERE id=?")->execute([$reason,$userId,$id]);
            $pdo->commit();
        } catch(Throwable $x) { $pdo->rollBack(); throw $x; }
        audit('accounting','journal_void','acct_journal_entry',$id);
    }

    /**
     * Year-end closing: a DRAFT dated Dec 31 that brings every income and expense account of that year to zero
     * against the chosen equity account (usually Retained Earnings). It goes through approval like any entry.
     */
    public static function closeYear(int $year,int $equityId,int $userId): int
    {
        if($year<2000 || $year>(int)date('Y')) throw new RuntimeException('Choose a year up to the current one.');
        $eq=db()->prepare("SELECT id FROM acct_accounts WHERE id=? AND type='E' AND is_active=1"); $eq->execute([$equityId]);
        if(!$eq->fetchColumn()) throw new RuntimeException('Choose an active equity account (for example, Retained Earnings).');
        $from="$year-01-01"; $to="$year-12-31"; self::assertOpen($to);
        $pdo=db(); $pdo->beginTransaction();
        try {
            // A closing entry that a posted reversal cancelled no longer counts.
            $q=$pdo->prepare("SELECT entry_no FROM acct_journal_entries e WHERE e.entry_kind='CLOSING' AND e.reverses_entry_id IS NULL AND e.status<>'VOID' AND e.entry_date=?
                AND NOT EXISTS (SELECT 1 FROM acct_journal_entries r WHERE r.reverses_entry_id=e.id AND r.status='POSTED') FOR UPDATE"); $q->execute([$to]);
            if($no=$q->fetchColumn()) throw new RuntimeException("$year already has a closing entry ($no).");
            $q=$pdo->prepare("SELECT COUNT(*) FROM acct_journal_entries WHERE entry_date BETWEEN ? AND ? AND status IN ('DRAFT','FOR_APPROVAL')"); $q->execute([$from,$to]);
            if((int)$q->fetchColumn()>0) throw new RuntimeException("Post, void or finish every draft and submitted entry dated $year first.");
            $lines=[]; $net=0;
            foreach(self::balances($from,$to,false) as $b) {
                if($b['type']!=='I' && $b['type']!=='X') continue;
                $d=$b['debit']-$b['credit']; if($d===0) continue;
                $lines[]=[$b['id'],'Close '.$year,$d<0?-$d:0,$d>0?$d:0]; $net+=$d;
            }
            if(!$lines) throw new RuntimeException("No posted income or expense in $year to close.");
            // $net = expenses minus income: a profit (net<0) is credited to equity, a loss debited.
            $lines[]=[$equityId,$net<0?'Net income '.$year:'Net loss '.$year,$net>0?$net:0,$net<0?-$net:0];
            $id=self::insertHeader($to,"Year-end closing $year",$userId,null,'CLOSING');
            self::insertLines($id,$lines);
            $pdo->commit();
        } catch(Throwable $x) { $pdo->rollBack(); throw $x; }
        audit('accounting','year_close','acct_journal_entry',$id,['year'=>$year]);
        return $id;
    }

    // ---------- Reports (posted entries only) ----------

    /**
     * Per account: natural-side balance in centavos for posted lines dated in [from,to] (from=null: from the beginning).
     * @return array<int,array{id:int,code:string,name:string,type:string,debit:int,credit:int,balance:int}>
     */
    public static function balances(?string $from,string $to,bool $withClosing=true): array
    {
        $q=db()->prepare("SELECT a.id,a.code,a.name,a.type,COALESCE(SUM(l.debit),0) d,COALESCE(SUM(l.credit),0) c FROM acct_accounts a
            LEFT JOIN acct_journal_lines l ON l.account_id=a.id AND l.entry_id IN (SELECT id FROM acct_journal_entries WHERE status='POSTED' AND entry_date<=? AND entry_date>=?"
            .($withClosing?'':" AND entry_kind<>'CLOSING'").') GROUP BY a.id ORDER BY a.code');
        $q->execute([$to,$from??'1000-01-01']);
        $out=[];
        foreach($q as $r) { $d=self::toCents($r['d']); $c=self::toCents($r['c']);
            $out[]=['id'=>(int)$r['id'],'code'=>$r['code'],'name'=>$r['name'],'type'=>$r['type'],'debit'=>$d,'credit'=>$c,'balance'=>self::debitNormal($r['type'])?$d-$c:$c-$d]; }
        return $out;
    }

    public static function trialBalance(string $asOf): array
    {
        $rows=[]; $td=0; $tc=0;
        foreach(self::balances(null,$asOf) as $b) {
            $net=$b['debit']-$b['credit']; if($b['debit']===0 && $b['credit']===0) continue;
            $dr=max($net,0); $cr=max(-$net,0); $td+=$dr; $tc+=$cr;
            $rows[]=$b+['dr'=>$dr,'cr'=>$cr];
        }
        return ['rows'=>$rows,'debit'=>$td,'credit'=>$tc];
    }

    public static function incomeStatement(string $from,string $to): array
    {
        $b=array_filter(self::balances($from,$to,false),fn($x)=>in_array($x['type'],['I','X'],true) && ($x['debit']||$x['credit']));
        $inc=array_values(array_filter($b,fn($x)=>$x['type']==='I')); $exp=array_values(array_filter($b,fn($x)=>$x['type']==='X'));
        $ti=array_sum(array_column($inc,'balance')); $tx=array_sum(array_column($exp,'balance'));
        return ['income'=>$inc,'expense'=>$exp,'total_income'=>$ti,'total_expense'=>$tx,'net'=>$ti-$tx];
    }

    /** Net income to date is shown as "Current earnings" under equity so the sheet balances without a closing entry. */
    public static function balanceSheet(string $asOf): array
    {
        $all=self::balances(null,$asOf); $pick=fn($t)=>array_values(array_filter($all,fn($x)=>$x['type']===$t && ($x['debit']||$x['credit'])));
        $earn=array_sum(array_map(fn($x)=>$x['type']==='I'?$x['balance']:($x['type']==='X'?-$x['balance']:0),$all));
        $a=$pick('A'); $l=$pick('L'); $e=$pick('E');
        $ta=array_sum(array_column($a,'balance')); $tl=array_sum(array_column($l,'balance')); $te=array_sum(array_column($e,'balance'))+$earn;
        return ['assets'=>$a,'liabilities'=>$l,'equity'=>$e,'earnings'=>$earn,'total_assets'=>$ta,'total_liabilities'=>$tl,'total_equity'=>$te];
    }
}
