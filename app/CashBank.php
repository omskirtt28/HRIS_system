<?php
declare(strict_types=1);
require_once __DIR__.'/AccountsReceivable.php';

/**
 * Accounting Phase 2 slice A3 (migration 043): bank accounts, payments, bank reconciliation.
 * A payment is a disbursement voucher (pays posted bills: Dr 2010 AP / Cr bank) or a collection receipt (settles posted invoices:
 * Dr bank / Cr 1100 AR). Like bills, its lines live in a draft journal entry, so Submit / Approve / Void / Reverse are the journal actions.
 * A payment that is not voided and not cancelled by a posted reversal holds its amount against the bill or invoice, even as a draft,
 * so the same open balance cannot be paid twice.
 */
final class CashBank
{
    /** kind => [number prefix, label, party, document, document table, party column, document number column, document date column] */
    public const KINDS=[
        'DISBURSEMENT'=>['DV','Disbursement','vendor','bill','acct_bills','vendor_id','bill_no','bill_date'],
        'COLLECTION'=>['CR','Collection','customer','invoice','acct_invoices','customer_id','invoice_no','invoice_date'],
    ];
    private const MAX_DOCS=49; // + the bank line = the journal's 50-line limit
    /** Payment entry `pe` still holds its amount: not voided, no posted reversal. */
    private const LIVE="pe.status<>'VOID' AND NOT EXISTS (SELECT 1 FROM acct_journal_entries rv WHERE rv.reverses_entry_id=pe.id AND rv.status='POSTED')";

    public static function kind(string $kind): array
    {
        return self::KINDS[$kind] ?? throw new RuntimeException('Choose disbursement or collection.');
    }

    /** SQL for posted payments on a bill/invoice as of a date (2 placeholders: asOf, asOf), for the aging reports. */
    public static function postedPaidSql(string $doc,string $alias): string
    {
        return "(SELECT COALESCE(SUM(al.amount),0) FROM acct_payment_allocations al JOIN acct_payments p ON p.id=al.payment_id JOIN acct_journal_entries pe ON pe.id=p.entry_id
            WHERE al.{$doc}_id=$alias.id AND pe.status='POSTED' AND pe.entry_date<=?
            AND NOT EXISTS (SELECT 1 FROM acct_journal_entries rv WHERE rv.reverses_entry_id=pe.id AND rv.status='POSTED' AND rv.entry_date<=?))";
    }

    /** Cash account kinds (migration 052). Petty cash has no bank statement, so it is never reconciled. */
    public const TYPES=['BANK'=>'Bank','EWALLET'=>'E-wallet','PETTY'=>'Petty cash'];
    /** Cheque register statuses (migration 052). CLEARED is set only by a finished reconciliation. */
    public const CHEQUE_STATUS=['ISSUED'=>'Issued','CLEARED'=>'Cleared','BOUNCED'=>'Bounced','VOID'=>'Void','STALE'=>'Stale'];
    // Hint only: the 180-day stale cheque period NEEDS VERIFICATION (HRIS-Vault), so nothing changes status on its own.
    public const STALE_HINT_DAYS=180;

    /** Migration 052 (account kind + cheque register) is applied. */
    public static function ready(): bool
    {
        static $ready=null;
        return $ready ??= (bool)db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='acct_cheques'")->fetchColumn();
    }

    // ---------- Bank accounts ----------

    public static function banks(bool $activeOnly=false): array
    {
        return db()->query('SELECT b.*,a.code,a.name AS gl_name,(SELECT COUNT(*) FROM acct_payments p WHERE p.bank_account_id=b.id) AS payments,
            (SELECT MAX(r.period_month) FROM acct_bank_recons r WHERE r.bank_account_id=b.id) AS last_recon,
            (SELECT COALESCE(SUM(l.debit-l.credit),0) FROM acct_journal_lines l JOIN acct_journal_entries e ON e.id=l.entry_id WHERE l.account_id=b.account_id AND e.status=\'POSTED\') AS book_balance
            FROM acct_bank_accounts b JOIN acct_accounts a ON a.id=b.account_id'.($activeOnly?' WHERE b.is_active=1':'').' ORDER BY b.name')->fetchAll();
    }

    public static function bank(int $id): ?array
    {
        $q=db()->prepare('SELECT b.*,a.code,a.name AS gl_name FROM acct_bank_accounts b JOIN acct_accounts a ON a.id=b.account_id WHERE b.id=?'); $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    /** Asset accounts a bank account may be mapped to (not AR, not already used by another bank account). */
    public static function cashAccounts(int $bankId=0): array
    {
        $q=db()->prepare("SELECT a.* FROM acct_accounts a WHERE a.type='A' AND a.is_active=1 AND a.code<>? AND NOT EXISTS (SELECT 1 FROM acct_bank_accounts b WHERE b.account_id=a.id AND b.id<>?) ORDER BY a.code");
        $q->execute([AccountsReceivable::AR_CODE,$bankId]); return $q->fetchAll();
    }

    public static function saveBank(int $id,string $name,string $bankName,string $accountNo,int $glId,bool $active,string $kind='BANK'): int
    {
        if(!isset(self::TYPES[$kind]) || ($kind!=='BANK' && !self::ready())) throw new RuntimeException('Choose bank, e-wallet or petty cash.');
        $name=trim(preg_replace('/\s+/',' ',$name)); $bankName=trim(preg_replace('/\s+/',' ',$bankName)); $accountNo=trim($accountNo);
        if($name==='' || mb_strlen($name)>100) throw new RuntimeException('Account name is required (up to 100 characters).');
        if(mb_strlen($bankName)>100) throw new RuntimeException('Bank name is too long (100 characters).');
        if($accountNo!=='' && !preg_match('/^[0-9 -]{1,30}$/',$accountNo)) throw new RuntimeException('Bank account no.: numbers, spaces and dashes only (up to 30).');
        if(!in_array($glId,array_map('intval',array_column(self::cashAccounts($id),'id')),true)) throw new RuntimeException('Choose an active asset account that no other bank account uses.');
        $pdo=db();
        $dup=$pdo->prepare('SELECT id FROM acct_bank_accounts WHERE name=? AND id<>?'); $dup->execute([$name,$id]);
        if($dup->fetchColumn()) throw new RuntimeException("A bank account named $name already exists.");
        $vals=[$name,$bankName===''?null:$bankName,$accountNo===''?null:$accountNo,$glId,$active?1:0];
        if($id>0) {
            $old=self::bank($id); if(!$old) throw new RuntimeException('Bank account not found.');
            if((int)$old['account_id']!==$glId) {
                // Payments and reconciliations already point at the old ledger account.
                $u=$pdo->prepare('SELECT (SELECT COUNT(*) FROM acct_payments WHERE bank_account_id=?)+(SELECT COUNT(*) FROM acct_bank_recons WHERE bank_account_id=?)'); $u->execute([$id,$id]);
                if((int)$u->fetchColumn()) throw new RuntimeException('This bank account has payments or reconciliations, so its ledger account cannot change.');
            }
            if($kind!=='BANK' && self::ready()) {
                $c=$pdo->prepare('SELECT COUNT(*) FROM acct_cheques WHERE bank_account_id=?'); $c->execute([$id]);
                if((int)$c->fetchColumn()) throw new RuntimeException('This account has cheques, so it stays a bank account.');
            }
            $pdo->prepare('UPDATE acct_bank_accounts SET name=?,bank_name=?,account_no=?,account_id=?,is_active=? WHERE id=?')->execute([...$vals,$id]);
            if(self::ready()) $pdo->prepare('UPDATE acct_bank_accounts SET kind=? WHERE id=?')->execute([$kind,$id]);
            audit('accounting','bank_update','acct_bank_account',$id,['active'=>$active,'kind'=>$kind]);
            return $id;
        }
        $pdo->prepare('INSERT INTO acct_bank_accounts(name,bank_name,account_no,account_id,is_active) VALUES(?,?,?,?,?)')->execute($vals);
        $id=(int)$pdo->lastInsertId();
        if(self::ready()) $pdo->prepare('UPDATE acct_bank_accounts SET kind=? WHERE id=?')->execute([$kind,$id]);
        audit('accounting','bank_create','acct_bank_account',$id,['kind'=>$kind]);
        return $id;
    }

    // ---------- Payments ----------

    private const PAY_SQL='SELECT p.*,b.name AS bank_name,COALESCE(v.name,c.name) AS party_name,e.entry_no,e.status,e.return_note,
        (SELECT r.entry_no FROM acct_journal_entries r WHERE r.reverses_entry_id=e.id AND r.status=\'POSTED\') AS reversed_by_no
        FROM acct_payments p JOIN acct_bank_accounts b ON b.id=p.bank_account_id LEFT JOIN acct_vendors v ON v.id=p.vendor_id
        LEFT JOIN acct_customers c ON c.id=p.customer_id JOIN acct_journal_entries e ON e.id=p.entry_id';

    public static function payments(string $kind,string $status=''): array
    {
        self::kind($kind);
        $sql=self::PAY_SQL.' WHERE p.kind=?'.(isset(Accounting::STATUS[$status])?' AND e.status=?':'').' ORDER BY p.pay_date DESC,p.id DESC LIMIT 500';
        $q=db()->prepare($sql); $q->execute(isset(Accounting::STATUS[$status])?[$kind,$status]:[$kind]); return $q->fetchAll();
    }

    public static function statusCounts(string $kind): array
    {
        $q=db()->prepare('SELECT e.status,COUNT(*) FROM acct_payments p JOIN acct_journal_entries e ON e.id=p.entry_id WHERE p.kind=? GROUP BY e.status'); $q->execute([$kind]);
        return $q->fetchAll(PDO::FETCH_KEY_PAIR)+array_fill_keys(array_keys(Accounting::STATUS),0);
    }

    public static function payment(int $id): ?array
    {
        $q=db()->prepare(self::PAY_SQL.' WHERE p.id=?'); $q->execute([$id]); $p=$q->fetch();
        if(!$p) return null;
        [, , , $doc, $table, , $no]=self::KINDS[$p['kind']];
        $a=db()->prepare("SELECT al.amount,x.id AS doc_id,x.$no AS doc_no,x.total FROM acct_payment_allocations al JOIN $table x ON x.id=al.{$doc}_id WHERE al.payment_id=? ORDER BY x.$no");
        $a->execute([$id]); $p['allocations']=$a->fetchAll();
        $p['party_id']=(int)($p['vendor_id'] ?? $p['customer_id']);
        return $p;
    }

    /** Payments recorded against one bill or invoice (all statuses, newest first), for its detail page. */
    public static function docPayments(string $doc,int $docId): array
    {
        if(!in_array($doc,['bill','invoice'],true)) throw new InvalidArgumentException('Unknown document.');
        $q=db()->prepare("SELECT p.id,p.payment_no,p.pay_date,al.amount,pe.status,(".self::LIVE.") AS live FROM acct_payment_allocations al JOIN acct_payments p ON p.id=al.payment_id
            JOIN acct_journal_entries pe ON pe.id=p.entry_id WHERE al.{$doc}_id=? ORDER BY p.pay_date DESC,p.id DESC");
        $q->execute([$docId]); return $q->fetchAll();
    }

    /**
     * Posted, not reversed bills (or invoices) of one party with an open balance, oldest due first. Amounts in centavos.
     * open = total minus every live payment except $paymentId (the one being edited); `this` = what $paymentId already applies.
     */
    public static function openDocs(string $kind,int $partyId,int $paymentId=0): array
    {
        [, , , $doc, $table, $pcol, $no, $date]=self::kind($kind);
        $q=db()->prepare("SELECT x.id,x.$no AS doc_no,x.$date AS doc_date,x.due_date,x.total,
            (SELECT COALESCE(SUM(al.amount),0) FROM acct_payment_allocations al JOIN acct_payments p ON p.id=al.payment_id JOIN acct_journal_entries pe ON pe.id=p.entry_id
              WHERE al.{$doc}_id=x.id AND p.id<>? AND ".self::LIVE.") AS paid,
            (SELECT al.amount FROM acct_payment_allocations al WHERE al.{$doc}_id=x.id AND al.payment_id=?) AS this_amount
            FROM $table x JOIN acct_journal_entries e ON e.id=x.entry_id WHERE x.$pcol=? AND e.status='POSTED'
            AND NOT EXISTS (SELECT 1 FROM acct_journal_entries r WHERE r.reverses_entry_id=e.id AND r.status='POSTED') ORDER BY x.due_date,x.id");
        $q->execute([$paymentId,$paymentId,$partyId]);
        $out=[];
        foreach($q as $r) {
            $open=(int)round($r['total']*100)-(int)round($r['paid']*100); $mine=$r['this_amount']===null?0:(int)round($r['this_amount']*100);
            if($open>0 || $mine>0) $out[(int)$r['id']]=['id'=>(int)$r['id'],'doc_no'=>$r['doc_no'],'doc_date'=>$r['doc_date'],'due_date'=>$r['due_date'],'total'=>(int)round($r['total']*100),'open'=>$open,'this'=>$mine];
        }
        return $out;
    }

    private static function party(string $kind,int $id): array
    {
        $t=$kind==='DISBURSEMENT'?'acct_vendors':'acct_customers';
        $q=db()->prepare("SELECT * FROM $t WHERE id=? AND is_active=1"); $q->execute([$id]);
        return $q->fetch() ?: throw new RuntimeException('Choose an active '.self::KINDS[$kind][2].'.');
    }

    /**
     * Creates ($id=0) or updates a DRAFT payment. $amounts = [bill or invoice id => amount text]; blank or zero rows are skipped.
     * @param array<int|string,mixed> $amounts
     */
    public static function savePayment(string $kind,int $id,int $bankId,int $partyId,string $date,string $ref,string $memo,array $amounts,int $userId): int
    {
        [$prefix,$label,$partyWord,$doc]=self::kind($kind);
        $date=Accounting::validDate(trim($date)); $ref=trim($ref); $memo=trim(preg_replace('/\s+/',' ',$memo));
        if(mb_strlen($ref)>50) throw new RuntimeException('Reference no. is too long (50 characters).');
        if(mb_strlen($memo)>150) throw new RuntimeException('Memo is too long (150 characters).');
        $bank=self::bank($bankId);
        if(!$bank || !$bank['is_active']) throw new RuntimeException('Choose an active bank account.');
        $party=self::party($kind,$partyId);
        $want=[];
        foreach($amounts as $docId=>$raw) { $c=Accounting::cents((string)$raw); if($c>0) $want[(int)$docId]=$c; }
        if(!$want) throw new RuntimeException("Enter an amount for at least one $doc.");
        if(count($want)>self::MAX_DOCS) throw new RuntimeException('A payment can cover up to '.self::MAX_DOCS." {$doc}s.");
        Accounting::assertOpen($date);
        $ctlId=$kind==='DISBURSEMENT'?AccountsPayable::apAccount():AccountsReceivable::arAccount();
        $pdo=db(); $pdo->beginTransaction();
        try {
            // Locking reads first: the open balances below are then read after any competing payment on these documents committed.
            $table=self::KINDS[$kind][4];
            $pdo->prepare("SELECT id FROM $table WHERE id IN (".implode(',',array_fill(0,count($want),'?')).') ORDER BY id FOR UPDATE')->execute(array_keys($want));
            if($id>0) {
                $s=$pdo->prepare('SELECT p.payment_no,p.kind,p.vendor_id,p.customer_id,p.entry_id,e.status FROM acct_payments p JOIN acct_journal_entries e ON e.id=p.entry_id WHERE p.id=? FOR UPDATE'); $s->execute([$id]); $row=$s->fetch();
                if(!$row) throw new RuntimeException('Payment not found.');
                if($row['kind']!==$kind || (int)($row['vendor_id'] ?? $row['customer_id'])!==$partyId) throw new RuntimeException("The $partyWord of a saved payment cannot change. Void it and create a new one.");
                if($row['status']!=='DRAFT') throw new RuntimeException('Only draft payments can be edited. Return the entry to draft first, or reverse a posted one.');
            }
            $open=self::openDocs($kind,$partyId,$id);
            $lines=[]; $total=0;
            foreach($want as $docId=>$c) {
                $d=$open[$docId] ?? throw new RuntimeException("That $doc is not an open posted $doc of this $partyWord. Refresh the page.");
                if($c>$d['open']) throw new RuntimeException("{$d['doc_no']}: the amount is more than the open balance (".Accounting::money($d['open']).').');
                if($date<$d['doc_date']) throw new RuntimeException("The payment date cannot be before the date of {$d['doc_no']}.");
                $lines[]=$kind==='DISBURSEMENT'?[$ctlId,'Pays '.$d['doc_no'],$c,0]:[$ctlId,'Collects '.$d['doc_no'],0,$c];
                $total+=$c;
            }
            $bankLine=[(int)$bank['account_id'],mb_substr($party['name'],0,255),$kind==='COLLECTION'?$total:0,$kind==='DISBURSEMENT'?$total:0];
            $kind==='DISBURSEMENT' ? $lines[]=$bankLine : array_unshift($lines,$bankLine);
            $vals=[$bankId,$date,$ref===''?null:$ref,$memo===''?null:$memo,number_format($total/100,2,'.','')];
            if($id>0) {
                $entry=(int)$row['entry_id'];
                $pdo->prepare('UPDATE acct_journal_entries SET entry_date=?,memo=? WHERE id=?')->execute([$date,self::memo($row['payment_no'],$label,$party['name'],$ref,$memo),$entry]);
                $pdo->prepare('DELETE FROM acct_journal_lines WHERE entry_id=?')->execute([$entry]);
                $pdo->prepare('UPDATE acct_payments SET bank_account_id=?,pay_date=?,reference=?,memo=?,total=? WHERE id=?')->execute([...$vals,$id]);
                $pdo->prepare('DELETE FROM acct_payment_allocations WHERE payment_id=?')->execute([$id]);
            } else {
                $year=substr($date,0,4);
                $q=$pdo->prepare('SELECT payment_no FROM acct_payments WHERE payment_no LIKE ? ORDER BY payment_no DESC LIMIT 1 FOR UPDATE'); $q->execute(["$prefix-$year-%"]);
                $no=sprintf('%s-%s-%04d',$prefix,$year,(int)substr((string)$q->fetchColumn(),8)+1);
                $entry=Accounting::insertHeader($date,self::memo($no,$label,$party['name'],$ref,$memo),$userId,null);
                $pdo->prepare('INSERT INTO acct_payments(bank_account_id,pay_date,reference,memo,total,payment_no,kind,vendor_id,customer_id,entry_id,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([...$vals,$no,$kind,$kind==='DISBURSEMENT'?$partyId:null,$kind==='COLLECTION'?$partyId:null,$entry,$userId]);
                $id=(int)$pdo->lastInsertId();
            }
            $ins=$pdo->prepare("INSERT INTO acct_payment_allocations(payment_id,{$doc}_id,amount) VALUES(?,?,?)");
            foreach($want as $docId=>$c) $ins->execute([$id,$docId,number_format($c/100,2,'.','')]);
            Accounting::insertLines($entry,$lines);
            $pdo->commit();
        } catch(Throwable $e) { $pdo->rollBack(); throw $e; }
        audit('accounting','payment_save','acct_payment',$id,['kind'=>$kind,'documents'=>count($want)]);
        return $id;
    }

    private static function memo(string $no,string $label,string $party,string $ref,string $memo): string
    {
        return mb_substr("$label $no: $party".($ref!==''?" (ref. $ref)":'').($memo!==''?" - $memo":''),0,255);
    }

    // ---------- Bank reconciliation ----------

    /** "1,234.50", "-1,234.50" or "(1,234.50)" -> signed centavos. A bank account can be overdrawn. */
    public static function signedCents(string $raw): int
    {
        $v=trim($raw); $neg=false;
        if(preg_match('/^\((.*)\)$/',$v,$m)) { $v=$m[1]; $neg=true; } elseif(str_starts_with($v,'-')) { $v=substr($v,1); $neg=true; }
        if(trim($v)==='') throw new RuntimeException('Enter the ending balance on the bank statement.');
        $c=Accounting::cents($v); return $neg?-$c:$c;
    }

    public static function validMonth(string $month): string
    {
        $d=DateTime::createFromFormat('!Y-m',$month);
        if(!$d || $d->format('Y-m')!==$month) throw new RuntimeException('Choose a statement month.');
        if($month>date('Y-m')) throw new RuntimeException('A future month cannot be reconciled.');
        return $month.'-01';
    }

    /** Debit minus credit of posted lines on a ledger account up to a date, in centavos (a bank's book balance). */
    private static function bookBalance(int $glId,string $to): int
    {
        $q=db()->prepare("SELECT COALESCE(SUM(l.debit-l.credit),0) FROM acct_journal_lines l JOIN acct_journal_entries e ON e.id=l.entry_id WHERE l.account_id=? AND e.status='POSTED' AND e.entry_date<=?");
        $q->execute([$glId,$to]); return (int)round($q->fetchColumn()*100);
    }

    private static function clearedBalance(int $bankId): int
    {
        $q=db()->prepare('SELECT COALESCE(SUM(l.debit-l.credit),0) FROM acct_recon_lines c JOIN acct_bank_recons r ON r.id=c.recon_id JOIN acct_journal_lines l ON l.id=c.line_id WHERE r.bank_account_id=?');
        $q->execute([$bankId]); return (int)round($q->fetchColumn()*100);
    }

    /** Uncleared posted lines on the bank's ledger account dated up to $to, oldest first. */
    private static function unclearedLines(int $glId,string $to): array
    {
        $q=db()->prepare("SELECT l.id,l.debit,l.credit,l.description,e.id AS entry_id,e.entry_no,e.entry_date,e.memo FROM acct_journal_lines l JOIN acct_journal_entries e ON e.id=l.entry_id
            LEFT JOIN acct_recon_lines c ON c.line_id=l.id WHERE l.account_id=? AND e.status='POSTED' AND e.entry_date<=? AND c.line_id IS NULL ORDER BY e.entry_date,e.id,l.line_no");
        $q->execute([$glId,$to]); return $q->fetchAll();
    }

    /** Everything the reconciliation screen needs for one bank account and month ('Y-m-01'). */
    public static function reconData(array $bank,string $month): array
    {
        $end=date('Y-m-t',strtotime($month));
        $h=db()->prepare('SELECT r.*,u.full_name AS reconciled_by,(SELECT COUNT(*) FROM acct_recon_lines c WHERE c.recon_id=r.id) AS line_count FROM acct_bank_recons r
            LEFT JOIN users u ON u.id=r.reconciled_by_user_id WHERE r.bank_account_id=? ORDER BY r.period_month DESC LIMIT 24');
        $h->execute([(int)$bank['id']]); $history=$h->fetchAll();
        $done=null; foreach($history as $r) if($r['period_month']===$month) $done=$r;
        return ['month'=>$month,'end'=>$end,'history'=>$history,'done'=>$done,'last'=>$history[0]['period_month']??null,
            'book'=>self::bookBalance((int)$bank['account_id'],$end),'cleared'=>self::clearedBalance((int)$bank['id']),
            'lines'=>$done?[]:self::unclearedLines((int)$bank['account_id'],$end),
            'cheques'=>$done || !self::ready()?[]:self::openCheques((int)$bank['id'],$end)];
    }

    /**
     * Saves a finished reconciliation: the ticked lines become cleared, and all cleared lines must add up to the statement balance.
     * Months go forward only; undo the latest month to redo it.
     * Cheques clear with it: a linked cheque when its payment's bank line is ticked, a standalone cheque when ticked in $chequeIds.
     * @param array<int|string,mixed> $lineIds
     * @param array<int|string,mixed> $chequeIds
     */
    public static function finishRecon(int $bankId,string $month,string $statement,array $lineIds,int $userId,array $chequeIds=[]): int
    {
        $chq=array_values(array_unique(array_filter(array_map('intval',$chequeIds),fn($x)=>$x>0)));
        $month=self::validMonth(trim($month)); $end=date('Y-m-t',strtotime($month));
        $stmt=self::signedCents($statement);
        $ids=array_values(array_unique(array_filter(array_map('intval',$lineIds),fn($x)=>$x>0)));
        $pdo=db(); $pdo->beginTransaction();
        try {
            $b=$pdo->prepare('SELECT * FROM acct_bank_accounts WHERE id=? FOR UPDATE'); $b->execute([$bankId]); // one reconciliation per bank at a time
            $acct=$b->fetch(); $gl=(int)($acct['account_id']??0); if(!$gl) throw new RuntimeException('Bank account not found.');
            if(($acct['kind']??'BANK')==='PETTY') throw new RuntimeException('Petty cash has no bank statement, so it is not reconciled.');
            $l=$pdo->prepare('SELECT MAX(period_month) FROM acct_bank_recons WHERE bank_account_id=?'); $l->execute([$bankId]);
            if(($last=$l->fetchColumn()) && $last>=$month) throw new RuntimeException(date('F Y',strtotime($last)).' is already reconciled. Months are reconciled in order; undo the latest month to redo it.');
            $open=array_column(self::unclearedLines($gl,$end),null,'id'); $ticked=0;
            foreach($ids as $x) {
                $r=$open[$x] ?? throw new RuntimeException('A ticked line is no longer open for this account and month. Refresh the page.');
                $ticked+=(int)round($r['debit']*100)-(int)round($r['credit']*100);
            }
            $cleared=self::clearedBalance($bankId)+$ticked;
            if($cleared!==$stmt) throw new RuntimeException('Not balanced: cleared balance '.Accounting::money($cleared).' vs statement '.Accounting::money($stmt).' (difference '.Accounting::money($stmt-$cleared).'). Tick the lines that appear on the statement.');
            $pdo->prepare('INSERT INTO acct_bank_recons(bank_account_id,period_month,statement_balance,book_balance,reconciled_by_user_id) VALUES(?,?,?,?,?)')
                ->execute([$bankId,$month,number_format($stmt/100,2,'.',''),number_format(self::bookBalance($gl,$end)/100,2,'.',''),$userId]);
            $rid=(int)$pdo->lastInsertId();
            $ins=$pdo->prepare('INSERT INTO acct_recon_lines(line_id,recon_id) VALUES(?,?)');
            foreach($ids as $x) $ins->execute([$x,$rid]);
            if(self::ready()) {
                if($chq) {
                    $openChq=array_map('intval',array_column(self::openCheques($bankId,$end),'id'));
                    if(array_diff($chq,$openChq)) throw new RuntimeException('A ticked cheque is no longer open for this account and month. Refresh the page.');
                    $pdo->prepare("UPDATE acct_cheques SET status='CLEARED',recon_id=? WHERE id IN (".implode(',',array_fill(0,count($chq),'?')).')')->execute([$rid,...$chq]);
                }
                $pdo->prepare("UPDATE acct_cheques ch JOIN acct_payments p ON p.id=ch.payment_id JOIN acct_journal_lines l ON l.entry_id=p.entry_id JOIN acct_recon_lines c ON c.line_id=l.id
                    SET ch.status='CLEARED',ch.recon_id=? WHERE c.recon_id=? AND ch.bank_account_id=? AND ch.status='ISSUED'")->execute([$rid,$rid,$bankId]);
            }
            $pdo->commit();
        } catch(Throwable $e) { $pdo->rollBack(); throw $e; }
        audit('accounting','bank_recon_finish','acct_bank_recon',$rid,['lines'=>count($ids)]);
        return $rid;
    }

    /** Removes the latest reconciliation of a bank account; its lines become uncleared again. Returns the bank id. */
    public static function undoRecon(int $reconId): int
    {
        $pdo=db(); $pdo->beginTransaction();
        try {
            // Locking read first (bank row too), so the "latest month" check below sees any reconciliation committed meanwhile.
            $r=$pdo->prepare('SELECT r.bank_account_id,r.period_month FROM acct_bank_recons r JOIN acct_bank_accounts b ON b.id=r.bank_account_id WHERE r.id=? FOR UPDATE'); $r->execute([$reconId]); $row=$r->fetch();
            if(!$row) throw new RuntimeException('Reconciliation not found.');
            $l=$pdo->prepare('SELECT MAX(period_month) FROM acct_bank_recons WHERE bank_account_id=?'); $l->execute([$row['bank_account_id']]);
            if($l->fetchColumn()!==$row['period_month']) throw new RuntimeException('Only the latest reconciled month can be undone.');
            if(self::ready()) $pdo->prepare("UPDATE acct_cheques SET status='ISSUED',recon_id=NULL WHERE recon_id=?")->execute([$reconId]);
            $pdo->prepare('DELETE FROM acct_bank_recons WHERE id=?')->execute([$reconId]);
            $pdo->commit();
        } catch(Throwable $e) { $pdo->rollBack(); throw $e; }
        audit('accounting','bank_recon_undo','acct_bank_recon',$reconId,['month'=>$row['period_month']]);
        return (int)$row['bank_account_id'];
    }

    // ---------- Cheque register (migration 052) ----------

    /** Issued standalone cheques (no linked payment) of a bank dated up to $to: the ones a reconciliation can tick. */
    private static function openCheques(int $bankId,string $to): array
    {
        $q=db()->prepare("SELECT id,cheque_no,payee,cheque_date,amount FROM acct_cheques WHERE bank_account_id=? AND status='ISSUED' AND payment_id IS NULL AND cheque_date<=? ORDER BY cheque_date,id");
        $q->execute([$bankId,$to]); return $q->fetchAll();
    }

    public static function cheques(string $status=''): array
    {
        $ok=isset(self::CHEQUE_STATUS[$status]);
        $q=db()->prepare('SELECT ch.*,b.name AS bank_name,p.payment_no,r.period_month AS recon_month FROM acct_cheques ch JOIN acct_bank_accounts b ON b.id=ch.bank_account_id
            LEFT JOIN acct_payments p ON p.id=ch.payment_id LEFT JOIN acct_bank_recons r ON r.id=ch.recon_id'.($ok?' WHERE ch.status=?':'').' ORDER BY ch.cheque_date DESC,ch.id DESC LIMIT 500');
        $q->execute($ok?[$status]:[]); return $q->fetchAll();
    }

    public static function chequeCounts(): array
    {
        return db()->query('SELECT status,COUNT(*) FROM acct_cheques GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR)+array_fill_keys(array_keys(self::CHEQUE_STATUS),0);
    }

    /** Disbursements a new cheque may point at: not voided, on an active bank-type account, no cheque yet. */
    public static function chequePayments(): array
    {
        return db()->query("SELECT p.id,p.payment_no,p.bank_account_id,p.total,v.name AS vendor,b.name AS bank_name FROM acct_payments p JOIN acct_journal_entries e ON e.id=p.entry_id
            JOIN acct_vendors v ON v.id=p.vendor_id JOIN acct_bank_accounts b ON b.id=p.bank_account_id
            WHERE p.kind='DISBURSEMENT' AND e.status<>'VOID' AND b.kind='BANK' AND b.is_active=1 AND NOT EXISTS (SELECT 1 FROM acct_cheques ch WHERE ch.payment_id=p.id)
            ORDER BY p.pay_date DESC,p.id DESC LIMIT 200")->fetchAll();
    }

    public static function saveCheque(int $bankId,string $no,string $payee,string $date,string $amount,int $paymentId,string $memo,int $userId): int
    {
        if(!self::ready()) throw new RuntimeException('The cheque register is not installed yet (migration 052).');
        $no=trim($no); $payee=trim(preg_replace('/\s+/',' ',$payee)); $memo=trim(preg_replace('/\s+/',' ',$memo));
        if(!preg_match('/^[0-9A-Za-z-]{1,30}$/',$no)) throw new RuntimeException('Cheque no.: letters, numbers and dashes only (up to 30).');
        $date=Accounting::validDate(trim($date));
        $cents=Accounting::cents($amount); if($cents<=0) throw new RuntimeException('Enter the cheque amount.');
        if(mb_strlen($payee)>150) throw new RuntimeException('Payee is too long (150 characters).');
        if(mb_strlen($memo)>150) throw new RuntimeException('Memo is too long (150 characters).');
        $bank=self::bank($bankId);
        if(!$bank || !$bank['is_active'] || $bank['kind']!=='BANK') throw new RuntimeException('Choose an active bank account (not an e-wallet or petty cash).');
        $pdo=db();
        if($paymentId>0) {
            $q=$pdo->prepare('SELECT p.*,e.status,v.name AS vendor FROM acct_payments p JOIN acct_journal_entries e ON e.id=p.entry_id LEFT JOIN acct_vendors v ON v.id=p.vendor_id WHERE p.id=?'); $q->execute([$paymentId]); $p=$q->fetch();
            if(!$p || $p['kind']!=='DISBURSEMENT' || $p['status']==='VOID') throw new RuntimeException('Link only a disbursement that is not voided.');
            if((int)$p['bank_account_id']!==$bankId) throw new RuntimeException('The linked disbursement is paid from another bank account.');
            if((int)round($p['total']*100)!==$cents) throw new RuntimeException('The cheque amount must equal the disbursement total ('.Accounting::money((int)round($p['total']*100)).').');
            $u=$pdo->prepare('SELECT cheque_no FROM acct_cheques WHERE payment_id=?'); $u->execute([$paymentId]);
            if($x=$u->fetchColumn()) throw new RuntimeException("{$p['payment_no']} already has cheque $x.");
            if($payee==='') $payee=(string)$p['vendor'];
        }
        if($payee==='') throw new RuntimeException('Enter the payee.');
        $d=$pdo->prepare('SELECT id FROM acct_cheques WHERE bank_account_id=? AND cheque_no=?'); $d->execute([$bankId,$no]);
        if($d->fetchColumn()) throw new RuntimeException("Cheque $no is already recorded for this bank account.");
        $pdo->prepare('INSERT INTO acct_cheques(bank_account_id,cheque_no,payee,cheque_date,amount,payment_id,memo,created_by_user_id) VALUES(?,?,?,?,?,?,?,?)')
            ->execute([$bankId,$no,mb_substr($payee,0,150),$date,number_format($cents/100,2,'.',''),$paymentId>0?$paymentId:null,$memo===''?null:$memo,$userId]);
        $id=(int)$pdo->lastInsertId();
        audit('accounting','cheque_create','acct_cheque',$id,['linked'=>$paymentId>0]);
        return $id;
    }

    /** Manual status change of an issued cheque: VOID, BOUNCED or STALE (the caller checks who may do which). */
    public static function setChequeStatus(int $id,string $status): void
    {
        if(!in_array($status,['VOID','BOUNCED','STALE'],true)) throw new RuntimeException('Choose void, bounced or stale. Cleared is set only by a finished bank reconciliation.');
        $u=db()->prepare("UPDATE acct_cheques SET status=? WHERE id=? AND status='ISSUED'"); $u->execute([$status,$id]);
        if(!$u->rowCount()) throw new RuntimeException('Only an issued cheque can change status. Refresh the page.');
        audit('accounting','cheque_status','acct_cheque',$id,['status'=>$status]);
    }
}
