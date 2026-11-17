<?php
declare(strict_types=1);
/**
 * Accounting Phase 3 B6 (migration 048): fixed asset register and straight-line depreciation.
 * Depreciation starts the month after the asset is acquired and stops after the month it is disposed of.
 * Monthly amount = (cost - salvage) / life months, in centavos; the last month takes the remainder so the total is exact.
 * A run for a month is one draft journal entry (Dr expense / Cr accumulated depreciation, grouped per account pair)
 * dated the month's last day. Amounts in acct_depreciation count while their entry is not voided and not reversed,
 * so a voided or reversed run frees the month. Disposal gain/loss is not booked here (enter it as a journal entry).
 */
final class FixedAssets
{
    /** A depreciation row counts while its entry is not VOID and has no posted reversal. */
    private const LIVE="e.status<>'VOID' AND NOT EXISTS (SELECT 1 FROM acct_journal_entries r WHERE r.reverses_entry_id=e.id AND r.status='POSTED')";

    public static function ready(): bool
    {
        static $ready=null;
        return $ready ??= (bool)db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='acct_depreciation'")->fetchColumn();
    }

    public static function monthly(int $base,int $life): int { return intdiv($base,$life); }

    /**
     * Amount for one month given what is already depreciated and how many months were run. Pure, for tests.
     * Returns 0 once fully depreciated.
     */
    public static function amountFor(int $base,int $life,int $done,int $monthsRun): int
    {
        $left=$base-$done; if($left<=0) return 0;
        return $monthsRun>=$life-1 ? $left : min(self::monthly($base,$life),$left);
    }

    public static function assets(): array
    {
        return db()->query("SELECT a.*,ga.code AS asset_code,ga.name AS asset_account,
            COALESCE((SELECT SUM(d.amount) FROM acct_depreciation d JOIN acct_journal_entries e ON e.id=d.entry_id WHERE d.asset_id=a.id AND ".self::LIVE."),0) AS depreciated,
            COALESCE((SELECT SUM(d.amount) FROM acct_depreciation d JOIN acct_journal_entries e ON e.id=d.entry_id WHERE d.asset_id=a.id AND e.status='POSTED' AND ".str_replace('e.status<>\'VOID\' AND ','',self::LIVE)."),0) AS posted,
            (SELECT COUNT(*) FROM acct_depreciation d JOIN acct_journal_entries e ON e.id=d.entry_id WHERE d.asset_id=a.id AND ".self::LIVE.") AS months_run
            FROM acct_assets a JOIN acct_accounts ga ON ga.id=a.asset_account_id ORDER BY a.asset_no")->fetchAll();
    }

    public static function asset(int $id): ?array
    {
        foreach(self::assets() as $a) if((int)$a['id']===$id) return $a;
        return null;
    }

    /** Active accounts of one type for the form pickers. */
    public static function accountsOf(string $type): array
    {
        $q=db()->prepare('SELECT id,code,name FROM acct_accounts WHERE type=? AND is_active=1 ORDER BY code'); $q->execute([$type]); return $q->fetchAll();
    }

    /** Creates ($id=0) or updates an asset. Cost, life, salvage, dates and accounts are locked once a depreciation entry exists. */
    public static function save(int $id,array $in,int $userId): int
    {
        $name=trim(preg_replace('/\s+/',' ',(string)($in['name']??'')));
        if($name==='' || mb_strlen($name)>150) throw new RuntimeException('Enter the asset name (up to 150 characters).');
        $acq=Accounting::validDate(trim((string)($in['acquired_on']??'')));
        $cost=Accounting::cents((string)($in['cost']??'')); $salvage=Accounting::cents((string)($in['salvage']??''));
        if($cost<=0) throw new RuntimeException('Enter the cost.');
        if($salvage>=$cost) throw new RuntimeException('The salvage value must be less than the cost.');
        $life=filter_var($in['life_months']??'',FILTER_VALIDATE_INT);
        if($life===false || $life<1 || $life>600) throw new RuntimeException('Useful life must be 1 to 600 months.');
        $disp=trim((string)($in['disposed_on']??'')); $disp=$disp===''?null:Accounting::validDate($disp);
        if($disp!==null && $disp<$acq) throw new RuntimeException('The disposal date cannot be before the acquisition date.');
        $acc=[];
        foreach(['asset_account_id'=>'A','accum_account_id'=>'A','expense_account_id'=>'X'] as $k=>$t) {
            $acc[$k]=(int)($in[$k]??0);
            if(!in_array($acc[$k],array_map('intval',array_column(self::accountsOf($t),'id')),true)) throw new RuntimeException('Choose active accounts: asset and accumulated depreciation (Asset type), depreciation expense (Expense type).');
        }
        if($acc['asset_account_id']===$acc['accum_account_id']) throw new RuntimeException('The asset account and the accumulated depreciation account must be different.');
        $pdo=db(); $pdo->beginTransaction();
        try {
            if($id>0) {
                $s=$pdo->prepare('SELECT * FROM acct_assets WHERE id=? FOR UPDATE'); $s->execute([$id]); $old=$s->fetch();
                if(!$old) throw new RuntimeException('Asset not found.');
                $c=$pdo->prepare('SELECT COUNT(*) FROM acct_depreciation d JOIN acct_journal_entries e ON e.id=d.entry_id WHERE d.asset_id=? AND '.self::LIVE); $c->execute([$id]);
                $used=(int)$c->fetchColumn()>0;
                $same=$old['acquired_on']===$acq && (int)round($old['cost']*100)===$cost && (int)round($old['salvage']*100)===$salvage && (int)$old['life_months']===$life
                    && (int)$old['asset_account_id']===$acc['asset_account_id'] && (int)$old['accum_account_id']===$acc['accum_account_id'] && (int)$old['expense_account_id']===$acc['expense_account_id'];
                if($used && !$same) throw new RuntimeException('This asset already has depreciation entries: only the name and disposal date can change. Void or reverse those entries first.');
                if($used && $disp!==null) {
                    $l=$pdo->prepare('SELECT MAX(d.period_month) FROM acct_depreciation d JOIN acct_journal_entries e ON e.id=d.entry_id WHERE d.asset_id=? AND '.self::LIVE); $l->execute([$id]);
                    if(($last=$l->fetchColumn()) && $last>Accounting::monthOf($disp)) throw new RuntimeException('Depreciation is already recorded after the disposal month. Void or reverse those entries first.');
                }
                $pdo->prepare('UPDATE acct_assets SET name=?,acquired_on=?,cost=?,salvage=?,life_months=?,asset_account_id=?,accum_account_id=?,expense_account_id=?,disposed_on=? WHERE id=?')
                    ->execute([$name,$acq,number_format($cost/100,2,'.',''),number_format($salvage/100,2,'.',''),$life,$acc['asset_account_id'],$acc['accum_account_id'],$acc['expense_account_id'],$disp,$id]);
            } else {
                $year=substr($acq,0,4);
                $q=$pdo->prepare('SELECT asset_no FROM acct_assets WHERE asset_no LIKE ? ORDER BY asset_no DESC LIMIT 1 FOR UPDATE'); $q->execute(["FA-$year-%"]);
                $no=sprintf('FA-%s-%04d',$year,(int)substr((string)$q->fetchColumn(),8)+1);
                $pdo->prepare('INSERT INTO acct_assets(asset_no,name,acquired_on,cost,salvage,life_months,asset_account_id,accum_account_id,expense_account_id,disposed_on,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$no,$name,$acq,number_format($cost/100,2,'.',''),number_format($salvage/100,2,'.',''),$life,$acc['asset_account_id'],$acc['accum_account_id'],$acc['expense_account_id'],$disp,$userId]);
                $id=(int)$pdo->lastInsertId();
            }
            $pdo->commit();
        } catch(Throwable $e) { $pdo->rollBack(); throw $e; }
        audit('accounting','asset_save','acct_asset',$id);
        return $id;
    }

    /**
     * Creates the depreciation draft for $month ('Y-m'). Assets already run for that month, not yet started, disposed earlier
     * or fully depreciated are skipped. Throws when nothing is due. Returns the journal entry id.
     */
    public static function run(string $month,int $userId): int
    {
        $d=DateTime::createFromFormat('!Y-m',trim($month));
        if(!$d || $d->format('Y-m')!==trim($month)) throw new RuntimeException('Choose the month to depreciate.');
        $m=$d->format('Y-m-01'); $end=$d->format('Y-m-t');
        if($m>date('Y-m-01')) throw new RuntimeException('A future month cannot be depreciated.');
        Accounting::assertOpen($end);
        $pdo=db(); $pdo->beginTransaction();
        try {
            // Lock every asset row: two runs for the same month cannot both pass the "already run" check.
            $assets=$pdo->query('SELECT * FROM acct_assets ORDER BY asset_no FOR UPDATE')->fetchAll();
            $st=$pdo->prepare('SELECT COALESCE(SUM(d.amount),0) AS done,COUNT(*) AS n,COALESCE(SUM(d.period_month=?),0) AS this_month FROM acct_depreciation d JOIN acct_journal_entries e ON e.id=d.entry_id WHERE d.asset_id=? AND '.self::LIVE);
            $due=[]; $groups=[];
            foreach($assets as $a) {
                $start=date('Y-m-01',strtotime(substr($a['acquired_on'],0,7).'-01 +1 month'));
                if($m<$start || ($a['disposed_on'] && $m>Accounting::monthOf($a['disposed_on']))) continue;
                $st->execute([$m,$a['id']]); $s=$st->fetch();
                if((int)$s['this_month']>0) continue;
                $base=(int)round($a['cost']*100)-(int)round($a['salvage']*100);
                $amt=self::amountFor($base,(int)$a['life_months'],(int)round($s['done']*100),(int)$s['n']);
                if($amt<=0) continue;
                $due[]=[(int)$a['id'],$amt];
                $k=$a['expense_account_id'].'-'.$a['accum_account_id']; $groups[$k]=($groups[$k]??0)+$amt;
            }
            if(!$due) throw new RuntimeException('Nothing to depreciate for '.$d->format('F Y').': every asset is already run for that month, not started, disposed or fully depreciated.');
            if(count($groups)>25) throw new RuntimeException('Too many account pairs for one entry (25 at most).');
            $label=$d->format('F Y');
            $entry=Accounting::insertHeader($end,"Depreciation for $label (".count($due).' asset'.(count($due)===1?'':'s').')',$userId,null);
            $lines=[];
            foreach($groups as $k=>$amt) { [$exp,$acc]=array_map('intval',explode('-',$k)); $lines[]=[$exp,"Depreciation $label",$amt,0]; $lines[]=[$acc,"Depreciation $label",0,$amt]; }
            Accounting::insertLines($entry,$lines);
            $ins=$pdo->prepare('INSERT INTO acct_depreciation(asset_id,period_month,amount,entry_id) VALUES(?,?,?,?)');
            foreach($due as [$aid,$amt]) $ins->execute([$aid,$m,number_format($amt/100,2,'.',''),$entry]);
            $pdo->commit();
        } catch(Throwable $e) { $pdo->rollBack(); throw $e; }
        audit('accounting','depreciation_run','acct_journal_entry',$entry,['month'=>$m,'assets'=>count($due)]);
        return $entry;
    }

    /** Depreciation entries, newest month first. */
    public static function runs(): array
    {
        return db()->query("SELECT d.entry_id,MIN(d.period_month) AS period_month,COUNT(*) AS assets,SUM(d.amount) AS total,e.entry_no,e.status,
            (SELECT x.entry_no FROM acct_journal_entries x WHERE x.reverses_entry_id=e.id AND x.status='POSTED') AS reversed_by_no
            FROM acct_depreciation d JOIN acct_journal_entries e ON e.id=d.entry_id GROUP BY d.entry_id,e.entry_no,e.status ORDER BY period_month DESC,d.entry_id DESC LIMIT 36")->fetchAll();
    }

    /** Month (Y-m-01) a journal entry depreciates, or null when it is not a depreciation entry. */
    public static function entryMonth(int $entryId): ?string
    {
        if(!self::ready()) return null;
        $q=db()->prepare('SELECT MIN(period_month) FROM acct_depreciation WHERE entry_id=?'); $q->execute([$entryId]);
        $m=$q->fetchColumn(); return $m?:null;
    }
}
