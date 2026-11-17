<?php
declare(strict_types=1);
/**
 * Accounting Phase 3 B3-B5 (migration 049): posting settings, VAT / EWT codes and the tax lines of bills and invoices.
 * Nothing is seeded: VAT registration, ATC codes and rates are NEEDS VERIFICATION, so the client types them in.
 * With no settings and no codes, bills, invoices and payroll posting behave exactly as before 049.
 * Bill and invoice line amounts are BEFORE VAT. Tax = line total x rate, rounded half up to the centavo.
 *   Bill:    Dr expense lines (net) + Dr Input VAT / Cr EWT payable + Cr Accounts Payable (net + VAT - EWT)
 *   Invoice: Dr Accounts Receivable (net + VAT) / Cr income lines (net) + Cr Output VAT
 */
final class AccountingSetup
{
    /** Payroll release keys => label; defaults are PayrollRun::ACCOUNTS (sample chart of migration 039). */
    public const PAYROLL=['salaries'=>'Gross pay (expense)','employer'=>'Employer contributions (expense)','SSS'=>'SSS payable','PHILHEALTH'=>'PhilHealth payable',
        'PAGIBIG'=>'Pag-IBIG payable','WHT'=>'Withholding tax payable','advances'=>'Payroll deductions (advances receivable)','carry'=>'Balance owed by employees','bank'=>'Net pay: payroll bank'];
    public const TAX_ACCOUNTS=['tax.vat_input'=>['Input VAT','A'],'tax.vat_output'=>['Output VAT','L'],'tax.ewt'=>['Expanded withholding tax payable','L']];

    public static function ready(): bool
    {
        static $ready=null;
        return $ready ??= (bool)db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='acct_tax_codes'")->fetchColumn();
    }

    public static function settings(): array
    {
        return self::ready() ? db()->query('SELECT k,v FROM acct_settings')->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    }

    public static function get(string $k): ?string { return self::settings()[$k] ?? null; }

    /** Active account id by chart code, optionally of one type. */
    public static function accountByCode(string $code,string $type=''): int
    {
        $q=db()->prepare('SELECT id FROM acct_accounts WHERE code=? AND is_active=1'.($type!==''?' AND type=?':''));
        $q->execute($type!==''?[$code,$type]:[$code]); return (int)$q->fetchColumn();
    }

    /**
     * Saves posting settings (codes of active accounts). Blank = back to the default.
     * @param array<string,string> $in keys from PAYROLL (payroll.<key>), payroll.net_to, payroll.payable, TAX_ACCOUNTS
     */
    public static function saveSettings(array $in,int $userId): void
    {
        $allowed=array_merge(array_map(fn($k)=>"payroll.$k",array_keys(self::PAYROLL)),['payroll.payable'],array_keys(self::TAX_ACCOUNTS));
        $set=[]; $del=[];
        foreach($allowed as $k) {
            $v=trim((string)($in[$k]??''));
            if($v==='') { $del[]=$k; continue; }
            $type=self::TAX_ACCOUNTS[$k][1]??($k==='payroll.payable'?'L':'');
            if(!self::accountByCode($v,$type)) throw new RuntimeException('Choose an active account'.($type?' of type '.Accounting::TYPES[$type]:'').' for '.(self::TAX_ACCOUNTS[$k][0]??self::PAYROLL[substr($k,8)]??'Salaries payable').'.');
            $set[$k]=$v;
        }
        $net=(string)($in['payroll.net_to']??'bank');
        if(!in_array($net,['bank','payable'],true)) throw new RuntimeException('Choose where net pay goes.');
        if($net==='payable' && !isset($set['payroll.payable'])) throw new RuntimeException('Choose the Salaries Payable account, or post net pay straight to the bank.');
        $set['payroll.net_to']=$net;
        $pdo=db(); $pdo->beginTransaction();
        try {
            $up=$pdo->prepare('INSERT INTO acct_settings(k,v,updated_by_user_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE v=VALUES(v),updated_by_user_id=VALUES(updated_by_user_id)');
            foreach($set as $k=>$v) $up->execute([$k,$v,$userId]);
            $d=$pdo->prepare('DELETE FROM acct_settings WHERE k=?'); foreach($del as $k) $d->execute([$k]);
            $pdo->commit();
        } catch(Throwable $e) { $pdo->rollBack(); throw $e; }
        audit('accounting','settings_save','acct_settings',null,['keys'=>array_keys($set)]);
    }

    /** Payroll release account codes: PayrollRun::ACCOUNTS with saved overrides; net pay to Salaries Payable when chosen (Q-P7). */
    public static function payrollAccounts(array $defaults): array
    {
        $s=self::settings(); $out=$defaults;
        foreach(array_keys(self::PAYROLL) as $k) if(isset($s["payroll.$k"])) $out[$k]=$s["payroll.$k"];
        if(($s['payroll.net_to']??'bank')==='payable' && isset($s['payroll.payable'])) $out['bank']=$s['payroll.payable'];
        return $out;
    }

    // ---------- Tax codes ----------

    public static function codes(string $kind='',bool $activeOnly=false): array
    {
        if(!self::ready()) return [];
        $w=[]; $a=[];
        if($kind!=='') { $w[]='kind=?'; $a[]=$kind; }
        if($activeOnly) $w[]='is_active=1';
        $q=db()->prepare('SELECT * FROM acct_tax_codes'.($w?' WHERE '.implode(' AND ',$w):'').' ORDER BY kind,code'); $q->execute($a); return $q->fetchAll();
    }

    public static function saveCode(int $id,string $kind,string $code,string $desc,string $rate,bool $active): int
    {
        $kind=strtoupper(trim($kind)); $code=strtoupper(trim($code)); $desc=trim(preg_replace('/\s+/',' ',$desc));
        if(!in_array($kind,['VAT','EWT'],true)) throw new RuntimeException('Choose VAT or EWT.');
        if(!preg_match('/^[A-Z0-9-]{1,20}$/',$code)) throw new RuntimeException('Code: letters, numbers and dashes, up to 20 characters (for EWT, the ATC).');
        if($desc==='' || mb_strlen($desc)>150) throw new RuntimeException('Enter a description (up to 150 characters).');
        $r=str_replace([' ','%'],'',trim($rate));
        if(!preg_match('/^\d{1,2}(\.\d{1,4})?$/',$r) || (float)$r<=0) throw new RuntimeException('Rate is a percent above 0 and below 100, up to 4 decimals.');
        $pdo=db();
        $dup=$pdo->prepare('SELECT id FROM acct_tax_codes WHERE kind=? AND code=? AND id<>?'); $dup->execute([$kind,$code,$id]);
        if($dup->fetchColumn()) throw new RuntimeException("$kind code $code already exists.");
        if($id>0) {
            $u=$pdo->prepare('SELECT COUNT(*) FROM acct_bills WHERE vat_code_id=? OR ewt_code_id=?'); $u->execute([$id,$id]); $used=(int)$u->fetchColumn();
            $u=$pdo->prepare('SELECT COUNT(*) FROM acct_invoices WHERE vat_code_id=?'); $u->execute([$id]); $used+=(int)$u->fetchColumn();
            $o=$pdo->prepare('SELECT kind,code FROM acct_tax_codes WHERE id=?'); $o->execute([$id]); $old=$o->fetch();
            if(!$old) throw new RuntimeException('Tax code not found.');
            // Rates are copied onto each document, so a used code may change rate going forward; kind and code stay.
            if($used && ($old['kind']!==$kind || $old['code']!==$code)) throw new RuntimeException('This code is used on bills or invoices: only its description, rate and status can change.');
            $pdo->prepare('UPDATE acct_tax_codes SET kind=?,code=?,description=?,rate=?,is_active=? WHERE id=?')->execute([$kind,$code,$desc,$r,$active?1:0,$id]);
        } else {
            $pdo->prepare('INSERT INTO acct_tax_codes(kind,code,description,rate,is_active) VALUES(?,?,?,?,?)')->execute([$kind,$code,$desc,$r,$active?1:0]);
            $id=(int)$pdo->lastInsertId();
        }
        audit('accounting','tax_code_save','acct_tax_code',$id);
        return $id;
    }

    /** Tax on a base in centavos; rate is a percent string with up to 4 decimals. Half up. Pure, for tests. */
    public static function tax(int $base,string $rate): int
    {
        $r=(int)round(((float)$rate)*10000); // percent x 10,000
        return intdiv($base*$r+500000,1000000);
    }

    /** Active code of a kind, its rate and the settings account it posts to; null for "none". */
    private static function pick(int $codeId,string $kind,string $acctKey): ?array
    {
        if($codeId<=0) return null;
        if(!self::ready()) throw new RuntimeException('Tax codes are not installed yet (migration 049).');
        $q=db()->prepare('SELECT * FROM acct_tax_codes WHERE id=? AND kind=? AND is_active=1'); $q->execute([$codeId,$kind]); $c=$q->fetch();
        if(!$c) throw new RuntimeException("Choose an active $kind code.");
        [$label,$type]=self::TAX_ACCOUNTS[$acctKey];
        $acc=($code=self::get($acctKey))!==null?self::accountByCode($code,$type):0;
        if(!$acc) throw new RuntimeException("Set the $label account in Accounting Settings first.");
        return $c+['account_id'=>$acc];
    }

    /** Account ids a bill or invoice line may not use, so tax lines can always be told apart from the document's own lines. */
    public static function taxAccountIds(): array
    {
        $ids=[]; foreach(self::TAX_ACCOUNTS as $k=>[,$t]) if(($c=self::get($k))!==null && ($id=self::accountByCode($c,$t))) $ids[]=$id;
        return $ids;
    }

    /**
     * Adds VAT / EWT to bill lines from AccountsPayable::billLines (expense debits, then the AP credit last).
     * Returns [lines, payable total, tax columns for acct_bills].
     */
    public static function billTax(array $lines,int $net,int $vatId,int $ewtId): array
    {
        $cols=['vat_code_id'=>null,'vat_rate'=>'0','vat_amount'=>'0.00','vat_account_id'=>null,'ewt_code_id'=>null,'ewt_rate'=>'0','ewt_amount'=>'0.00','ewt_account_id'=>null];
        $vat=self::pick($vatId,'VAT','tax.vat_input'); $ewt=self::pick($ewtId,'EWT','tax.ewt');
        if(!$vat && !$ewt) return [$lines,$net,self::ready()?$cols:[]];
        foreach(array_slice($lines,0,-1) as $i=>$l) if(in_array($l[0],self::taxAccountIds(),true)) throw new RuntimeException('Line '.($i+1).': VAT and EWT accounts are added by the tax codes; choose the expense or asset account.');
        $ap=array_pop($lines); $v=$vat?self::tax($net,$vat['rate']):0; $w=$ewt?self::tax($net,$ewt['rate']):0;
        if($v>0) { $lines[]=[(int)$vat['account_id'],'Input VAT ('.$vat['code'].')',$v,0]; $cols=['vat_code_id'=>(int)$vat['id'],'vat_rate'=>$vat['rate'],'vat_amount'=>number_format($v/100,2,'.',''),'vat_account_id'=>(int)$vat['account_id']]+$cols; }
        if($w>0) { $lines[]=[(int)$ewt['account_id'],'EWT ('.$ewt['code'].')',0,$w]; $cols=['ewt_code_id'=>(int)$ewt['id'],'ewt_rate'=>$ewt['rate'],'ewt_amount'=>number_format($w/100,2,'.',''),'ewt_account_id'=>(int)$ewt['account_id']]+$cols; }
        $total=$net+$v-$w;
        if($total<=0) throw new RuntimeException('The amount payable after withholding must be above zero.');
        $ap[3]=$total; $lines[]=$ap;
        return [$lines,$total,$cols];
    }

    /** Adds Output VAT to invoice lines from AccountsReceivable::invoiceLines (AR debit first). Returns [lines, receivable total, columns]. */
    public static function invoiceTax(array $lines,int $net,int $vatId): array
    {
        $cols=['vat_code_id'=>null,'vat_rate'=>'0','vat_amount'=>'0.00','vat_account_id'=>null];
        $vat=self::pick($vatId,'VAT','tax.vat_output');
        if(!$vat) return [$lines,$net,self::ready()?$cols:[]];
        foreach(array_slice($lines,1) as $i=>$l) if(in_array($l[0],self::taxAccountIds(),true)) throw new RuntimeException('Line '.($i+1).': the VAT account is added by the tax code; choose the income account.');
        $v=self::tax($net,$vat['rate']);
        if($v>0) { $lines[]=[(int)$vat['account_id'],'Output VAT ('.$vat['code'].')',0,$v]; $lines[0][2]=$net+$v;
            $cols=['vat_code_id'=>(int)$vat['id'],'vat_rate'=>$vat['rate'],'vat_amount'=>number_format($v/100,2,'.',''),'vat_account_id'=>(int)$vat['account_id']]; }
        return [$lines,$net+$v,$cols];
    }

    /** Writes the tax columns on a bill or invoice row (no-op before 049). */
    public static function storeColumns(string $table,int $id,array $cols): void
    {
        if(!$cols) return;
        db()->prepare('UPDATE '.($table==='acct_bills'?'acct_bills':'acct_invoices').' SET '.implode(',',array_map(fn($k)=>"$k=?",array_keys($cols))).' WHERE id=?')->execute([...array_values($cols),$id]);
    }

    // ---------- Reports (posted documents that no posted reversal cancelled) ----------

    private const LIVE="e.status='POSTED' AND NOT EXISTS (SELECT 1 FROM acct_journal_entries r WHERE r.reverses_entry_id=e.id AND r.status='POSTED')";

    /** Month => [output, input, net] VAT in centavos for one year. */
    public static function vatSummary(int $year): array
    {
        $out=[]; for($m=1;$m<=12;$m++) $out[sprintf('%04d-%02d-01',$year,$m)]=['output'=>0,'input'=>0,'sales'=>0,'purchases'=>0];
        if(!self::ready()) return $out;
        foreach([['acct_invoices','invoice_date','output','sales'],['acct_bills','bill_date','input','purchases']] as [$t,$col,$k,$base]) {
            $q=db()->prepare("SELECT DATE_FORMAT(d.$col,'%Y-%m-01') m,SUM(d.vat_amount) v,SUM(d.total-d.vat_amount".($t==='acct_bills'?'+d.ewt_amount':'').") b FROM $t d JOIN acct_journal_entries e ON e.id=d.entry_id
                WHERE d.vat_code_id IS NOT NULL AND YEAR(d.$col)=? AND ".self::LIVE.' GROUP BY m');
            $q->execute([$year]);
            foreach($q as $r) if(isset($out[$r['m']])) { $out[$r['m']][$k]=(int)round($r['v']*100); $out[$r['m']][$base]=(int)round($r['b']*100); }
        }
        foreach($out as &$r) $r['net']=$r['output']-$r['input']; unset($r);
        return $out;
    }

    /** EWT withheld from vendors in one month: one row per bill. */
    public static function ewtList(string $month): array
    {
        if(!self::ready()) return [];
        $q=db()->prepare("SELECT b.id,b.bill_no,b.bill_date,b.vendor_ref,v.name AS vendor,v.tin,c.code AS atc,b.ewt_rate,b.ewt_amount,(b.total-b.vat_amount+b.ewt_amount) AS base
            FROM acct_bills b JOIN acct_vendors v ON v.id=b.vendor_id JOIN acct_tax_codes c ON c.id=b.ewt_code_id JOIN acct_journal_entries e ON e.id=b.entry_id
            WHERE b.ewt_code_id IS NOT NULL AND b.bill_date BETWEEN ? AND LAST_DAY(?) AND ".self::LIVE.' ORDER BY v.name,b.bill_date,b.id');
        $q->execute([$month,$month]); return $q->fetchAll();
    }
}
