<?php
declare(strict_types=1);
require_once __DIR__.'/HrDashboard.php';

/**
 * Ticketing Report for HR: what was filed, where it came from, how long it takes, and what is stuck.
 * Read-only over tickets, employees, branches and the per-type detail tables. Incident Reports are a count only, never
 * listed. Throughput is per day, never per person. The CSV carries one row per request with name and branch only.
 */
final class TicketReport
{
    public const PERIODS=['this_week'=>'This week','last_week'=>'Last week','this_month'=>'This month','last_month'=>'Last month','last_30'=>'Last 30 days','last_90'=>'Last 90 days','this_year'=>'This year','all'=>'All time','custom'=>'Custom dates'];
    public const COMPANIES=['ALL'=>'All companies','OC'=>'Ocampo\'s','SW'=>'SW'];
    public const GROUP_LABEL=['OC'=>'Ocampo\'s','SW'=>'SW'];
    public const CSV_CAP=5000;
    public const AGING_DAYS=5;
    private const FILED='COALESCE(t.filed_at,t.created_at)';
    private const FROM=' FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id JOIN employees e ON e.id=t.subject_employee_id JOIN branches b ON b.id=t.branch_id ';
    private const OPEN_STATUSES='"SUBMITTED","PENDING_APPROVAL","PENDING_HR","IN_PROGRESS","RETURNED"';
    private const UNIFORM_BRANCH='CAST(JSON_UNQUOTE(JSON_EXTRACT(t.payload_json,"$.branch")) AS UNSIGNED)';

    public static function can(): bool { return TicketRepository::isHr() && Auth::can('ticket.report.view'); }
    public static function require(): void
    {
        if(!self::can())throw new RuntimeException('The Ticketing Report is available to HR roles with report access.');
    }

    /** Whitelisted filters. Anything unknown falls back to the default. */
    public static function params(array $in): array
    {
        $date=static fn($v)=>is_string($v)&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)&&($d=DateTimeImmutable::createFromFormat('!Y-m-d',$v))&&$d->format('Y-m-d')===$v?$v:'';
        $period=array_key_exists((string)($in['period']??''),self::PERIODS)?(string)$in['period']:'this_month';
        $from=$date($in['from']??'');$to=$date($in['to']??'');
        if($period==='custom'&&($from===''||$to===''||$to<$from))$period='this_month';
        $type=is_string($in['type_code']??null)&&preg_match('/^[A-Z_]{1,40}$/',$in['type_code'])&&$in['type_code']!=='INCIDENT_REPORT'&&(isset(TicketTypes::definitions()[$in['type_code']])||$in['type_code']==='PAYROLL_CONCERN')?$in['type_code']:'';
        return ['period'=>$period,'from'=>$period==='custom'?$from:'','to'=>$period==='custom'?$to:'',
            'company'=>array_key_exists((string)($in['company']??''),self::COMPANIES)?(string)$in['company']:'ALL',
            'branch_id'=>max(0,(int)($in['branch_id']??0)),'type_code'=>$type];
    }
    public static function linkParams(array $p): array
    {
        $out=['period'=>$p['period'],'company'=>$p['company']];
        if($p['period']==='custom'){$out['from']=$p['from'];$out['to']=$p['to'];}
        if($p['branch_id'])$out['branch_id']=$p['branch_id'];
        if($p['type_code']!=='')$out['type_code']=$p['type_code'];
        return $out;
    }

    /** start inclusive, end exclusive, both Y-m-d. */
    public static function window(array $p,?DateTimeImmutable $today=null): array
    {
        $today=($today??new DateTimeImmutable('today'))->setTime(0,0);$tomorrow=$today->modify('+1 day');
        switch($p['period']){
            case 'last_week':$s=$today->modify('monday this week')->modify('-7 days');$e=$s->modify('+7 days');break;
            case 'this_month':$s=$today->modify('first day of this month');$e=$tomorrow;break;
            case 'last_month':$s=$today->modify('first day of previous month');$e=$today->modify('first day of this month');break;
            case 'last_30':$s=$today->modify('-29 days');$e=$tomorrow;break;
            case 'last_90':$s=$today->modify('-89 days');$e=$tomorrow;break;
            case 'this_year':$s=$today->setDate((int)$today->format('Y'),1,1);$e=$tomorrow;break;
            case 'custom':$s=new DateTimeImmutable($p['from']);$e=(new DateTimeImmutable($p['to']))->modify('+1 day');break;
            case 'all':$min=db()->query('SELECT MIN('.self::FILED.') FROM tickets t WHERE t.status<>"DRAFT"')->fetchColumn();$s=$min?(new DateTimeImmutable(substr((string)$min,0,10))):$today;$e=$tomorrow;break;
            default:$s=$today->modify('monday this week');$e=$tomorrow;
        }
        return ['start'=>$s->format('Y-m-d'),'end'=>$e->format('Y-m-d'),'last_day'=>$e->modify('-1 day')->format('Y-m-d'),'days'=>max(1,(int)$s->diff($e)->days),'week_start'=>$today->modify('monday this week')->format('Y-m-d'),'today'=>$today->format('Y-m-d')];
    }

    /** @return array{0:string,1:array} WHERE (no keyword) and params for the shared FROM. */
    private static function scope(array $p,array $w,array $o=[]): array
    {
        $where=[($o['incident']??false)?'ty.code="INCIDENT_REPORT" AND t.status<>"DRAFT"':'ty.code<>"INCIDENT_REPORT" AND t.status<>"DRAFT"'];$args=[];
        if($p['company']!=='ALL'){$where[]='t.company_group_snapshot=?';$args[]=$p['company'];}
        if(($o['branch']??true)&&$p['branch_id']){$where[]='CASE WHEN ty.code="UNIFORM_REQUEST" THEN '.self::UNIFORM_BRANCH.' ELSE t.branch_id END=?';$args[]=$p['branch_id'];}
        if(($o['type']??true)&&$p['type_code']!==''){$where[]='ty.code=?';$args[]=$p['type_code'];}
        if($o['period']??true){$where[]=self::FILED.'>=? AND '.self::FILED.'<?';array_push($args,$w['start'].' 00:00:00',$w['end'].' 00:00:00');}
        return [implode(' AND ',$where),$args];
    }
    private static function rows(string $sql,array $args=[]): array { $q=db()->prepare($sql);$q->execute($args);return $q->fetchAll(); }
    private static function one(string $sql,array $args=[]): int { $q=db()->prepare($sql);$q->execute($args);return (int)$q->fetchColumn(); }
    public static function bucket(string $status): string
    {
        return match($status){'PENDING_HR'=>'pending','IN_PROGRESS'=>'progress','COMPLETED'=>'served','CANCELLED'=>'cancelled',default=>'other'};
    }
    private static function days(mixed $minutes): ?float { return $minutes===null?null:round((float)$minutes/1440,1); }
    private static function typeNames(): array { return db()->query('SELECT code,name FROM ticket_types WHERE active=1')->fetchAll(PDO::FETCH_KEY_PAIR); }
    private static function show(array $p,string $code): bool { return $p['type_code']===''||$p['type_code']===$code; }
    private static function has(string $table): bool { return FoundationRepository::tableExists($table); }

    public static function data(array $p,?DateTimeImmutable $today=null): array
    {
        self::require();
        $w=self::window($p,$today);$names=self::typeNames();[$sw,$sa]=self::scope($p,$w);
        $d=['window'=>$w,'params'=>$p,'generated'=>date('Y-m-d H:i')];
        // ---- summary
        $types=[];foreach(HrDashboard::TYPE_ORDER as $code)if(isset($names[$code]))$types[$code]=['name'=>$names[$code],'pending'=>0,'progress'=>0,'served'=>0,'cancelled'=>0,'other'=>0,'total'=>0,'avg_days'=>null,'served_n'=>0];
        foreach(self::rows('SELECT ty.code,t.status,COUNT(*) n'.self::FROM.'WHERE '.$sw.' GROUP BY ty.code,t.status',$sa) as $r){
            if(!isset($types[$r['code']]))$types[$r['code']]=['name'=>$names[$r['code']]??$r['code'],'pending'=>0,'progress'=>0,'served'=>0,'cancelled'=>0,'other'=>0,'total'=>0,'avg_days'=>null,'served_n'=>0];
            $types[$r['code']][self::bucket($r['status'])]+=(int)$r['n'];$types[$r['code']]['total']+=(int)$r['n'];
        }
        if($p['type_code']!=='')$types=array_intersect_key($types,[$p['type_code']=>1]);
        [$nw,$na]=self::scope($p,$w,['period'=>false]);
        $served=self::rows('SELECT ty.code,COUNT(*) n,AVG(TIMESTAMPDIFF(MINUTE,'.self::FILED.',t.closed_at)) m'.self::FROM.'WHERE '.$nw.' AND t.status="COMPLETED" AND t.closed_at>=? AND t.closed_at<? GROUP BY ty.code',array_merge($na,[$w['start'].' 00:00:00',$w['end'].' 00:00:00']));
        $sumN=0;$sumM=0.0;foreach($served as $r){if(isset($types[$r['code']])){$types[$r['code']]['avg_days']=self::days($r['m']);$types[$r['code']]['served_n']=(int)$r['n'];}$sumN+=(int)$r['n'];$sumM+=(float)$r['m']*(int)$r['n'];}
        $status=['pending'=>0,'progress'=>0,'served'=>0,'cancelled'=>0,'other'=>0,'total'=>0];foreach($types as $t)foreach($status as $k=>$_)$status[$k]+=$t[$k];
        $d['summary']=['types'=>$types,'status'=>$status,'new_this_week'=>self::one('SELECT COUNT(*)'.self::FROM.'WHERE '.$nw.' AND '.self::FILED.'>=?',array_merge($na,[$w['week_start'].' 00:00:00'])),
            'avg_days'=>$sumN?self::days($sumM/$sumN):null,'served_n'=>$sumN];
        // ---- aging (live: ignores the period, honours company, branch and type)
        $ageSql='DATEDIFF(?,DATE('.self::FILED.'))';
        $age=[0,0,0];foreach(self::rows('SELECT CASE WHEN a<=2 THEN 0 WHEN a<=5 THEN 1 ELSE 2 END k,COUNT(*) n FROM (SELECT '.$ageSql.' a'.self::FROM.'WHERE '.$nw.' AND t.status="PENDING_HR") x GROUP BY k',array_merge([$w['today']],$na)) as $r)$age[(int)$r['k']]=(int)$r['n'];
        $overByType=self::rows('SELECT ty.code,COUNT(*) n'.self::FROM.'WHERE '.$nw.' AND t.status="PENDING_HR" AND '.$ageSql.'>'.self::AGING_DAYS.' GROUP BY ty.code ORDER BY n DESC',array_merge($na,[$w['today']]));
        $oldest=self::rows('SELECT t.id,t.ticket_no,ty.name type_name,e.first_name,e.last_name,b.name branch_name,'.$ageSql.' age_days'.self::FROM.'WHERE '.$nw.' AND t.status="PENDING_HR" ORDER BY COALESCE(t.filed_at,t.created_at) ASC,t.id ASC LIMIT 10',array_merge([$w['today']],$na));
        $d['aging']=['buckets'=>$age,'over'=>$age[2],'pending'=>array_sum($age),'over_by_type'=>array_map(fn($r)=>['name'=>$names[$r['code']]??$r['code'],'n'=>(int)$r['n']],$overByType),'oldest'=>array_map(fn($r)=>$r+['age_days'=>(int)$r['age_days']],$oldest)];
        // ---- where requests come from
        $company=[];foreach(self::rows('SELECT COALESCE(t.company_group_snapshot,"") g,COUNT(*) n'.self::FROM.'WHERE '.$sw.' GROUP BY g ORDER BY n DESC',$sa) as $r)$company[]=['name'=>self::GROUP_LABEL[$r['g']]??'Unassigned','n'=>(int)$r['n']];
        $area=[];if(self::has('areas'))foreach(self::rows('SELECT COALESCE(a.name,"No area (Head Office or unassigned)") name,COUNT(*) n'.self::FROM.'LEFT JOIN areas a ON a.id=b.area_id WHERE '.$sw.' GROUP BY a.id,a.name ORDER BY n DESC,name',$sa) as $r)$area[]=['name'=>$r['name'],'n'=>(int)$r['n']];
        $branch=[];foreach(self::rows('SELECT b.id,b.name,COUNT(*) n,SUM(t.status IN ('.self::OPEN_STATUSES.')) open_n'.self::FROM.'WHERE '.$sw.' GROUP BY b.id,b.name ORDER BY n DESC,b.name',$sa) as $r)$branch[]=['id'=>(int)$r['id'],'name'=>$r['name'],'n'=>(int)$r['n'],'open'=>(int)$r['open_n']];
        $none=null;
        if(!$p['branch_id']){
            [$fw,$fa]=self::scope($p,$w,['branch'=>false]);
            $join=$p['company']!=='ALL'?' JOIN business_units bu ON bu.id=e.business_unit_id AND bu.company_group=?':'';$ja=$p['company']!=='ALL'?[$p['company']]:[];
            $none=array_map(fn($r)=>['id'=>(int)$r['id'],'name'=>$r['name'],'employees'=>(int)$r['emps']],self::rows('SELECT b.id,b.name,COUNT(DISTINCT e.id) emps FROM branches b JOIN employees e ON e.branch_id=b.id AND e.status IN ("ACTIVE","PROBATIONARY","ON_LEAVE")'.$join.'
                WHERE b.active=1 AND NOT EXISTS(SELECT 1 FROM tickets t JOIN ticket_types ty ON ty.id=t.ticket_type_id WHERE t.branch_id=b.id AND '.$fw.') GROUP BY b.id,b.name ORDER BY b.name',array_merge($ja,$fa)));
        }
        $d['origin']=['company'=>$company,'area'=>$area,'branch'=>$branch,'no_filing'=>$none];
        // ---- per type
        $d['detail']=self::detail($p,$w,$types);
        // ---- throughput per day (all types together, never per person)
        $d['throughput']=self::throughput($p,$w);
        // ---- Incident Reports: a count only
        [$iw,$ia]=self::scope($p,$w,['incident'=>true]);
        $d['incident']=$p['type_code']===''?self::one('SELECT COUNT(*)'.self::FROM.'WHERE '.$iw,$ia):null;
        $d['setup']=self::setup();
        return $d;
    }

    private static function detail(array $p,array $w,array $types): array
    {
        $out=[];[$sw,$sa]=self::scope($p,$w);
        $only=static fn(string $code)=>self::show($p,$code);
        foreach(['ATM_REQUEST','ID_REQUEST','NETSUITE_CONCERN'] as $code)if($only($code)&&isset($types[$code])){
            $out[$code]=['kind'=>'basic','branches'=>array_map(fn($r)=>['name'=>$r['name'],'n'=>(int)$r['n']],self::rows('SELECT b.name,COUNT(*) n'.self::FROM.'WHERE '.$sw.' AND ty.code=? GROUP BY b.id,b.name ORDER BY n DESC,b.name LIMIT 5',array_merge($sa,[$code])))];
        }
        if($only('UNIFORM_REQUEST')&&self::has('uniform_requests')){
            $sizes=TicketTypes::definition('UNIFORM_REQUEST')['fields']['size']['options'];$grid=[];$req=0;
            foreach(self::rows('SELECT ub.id,ub.name,ur.size,SUM(ur.quantity) q,COUNT(*) r'.self::FROM.'JOIN uniform_requests ur ON ur.ticket_id=t.id JOIN branches ub ON ub.id=ur.branch_id WHERE '.$sw.' AND ty.code="UNIFORM_REQUEST" AND t.status NOT IN ("CANCELLED","REJECTED") GROUP BY ub.id,ub.name,ur.size',$sa) as $r){
                $grid[$r['id']]['name']=$r['name'];$grid[$r['id']]['sizes'][$r['size']]=(int)$r['q'];$req+=(int)$r['r'];
            }
            foreach($grid as &$g){$g['total']=array_sum($g['sizes']);}unset($g);usort($grid,fn($a,$b)=>[$b['total'],$a['name']]<=>[$a['total'],$b['name']]);
            $totals=array_fill_keys($sizes,0);foreach($grid as $g)foreach($g['sizes'] as $s=>$q)$totals[$s]=($totals[$s]??0)+$q;
            $out['UNIFORM_REQUEST']=['kind'=>'uniform','sizes'=>$sizes,'rows'=>$grid,'totals'=>$totals,'pieces'=>array_sum($totals),'requests'=>$req];
        }
        if($only('PAYROLL_CONCERN')&&self::has('payroll_concerns')){
            $out['PAYROLL_CONCERN']=['kind'=>'payroll','rows'=>array_map(fn($r)=>['cutoff'=>$r['code']??'No cutoff','start'=>$r['period_start'],'total'=>(int)$r['total'],'open'=>(int)$r['open_n'],'served'=>(int)$r['served'],'late'=>(int)$r['late']],
                self::rows('SELECT pc.code,pc.period_start,COUNT(*) total,SUM(t.status IN ('.self::OPEN_STATUSES.')) open_n,SUM(t.status="COMPLETED") served,SUM(c.is_late) late'.self::FROM.'JOIN payroll_concerns c ON c.ticket_id=t.id LEFT JOIN payroll_cutoffs pc ON pc.id=t.payroll_period_id WHERE '.$sw.' AND ty.code="PAYROLL_CONCERN" GROUP BY pc.id,pc.code,pc.period_start ORDER BY pc.period_start DESC LIMIT 12',$sa))];
        }
        if($only('MANPOWER_REQUEST')&&self::has('ticket_manpower_requests')){
            $grid=[];foreach(self::rows('SELECT m.position_code,m.request_type,COUNT(*) r,SUM(m.quantity) q'.self::FROM.'JOIN ticket_manpower_requests m ON m.ticket_id=t.id WHERE '.$sw.' AND ty.code="MANPOWER_REQUEST" AND t.status NOT IN ("CANCELLED","REJECTED") GROUP BY m.position_code,m.request_type',$sa) as $r){
                $g=&$grid[$r['position_code']];$g['position']=$r['position_code'];$g[$r['request_type']]=($g[$r['request_type']]??0)+(int)$r['q'];$g['requests']=($g['requests']??0)+(int)$r['r'];unset($g);
            }
            foreach($grid as &$g){$g['Replacement']=$g['Replacement']??0;$g['Additional']=$g['Additional']??0;$g['total']=$g['Replacement']+$g['Additional'];}unset($g);
            $out['MANPOWER_REQUEST']=['kind'=>'manpower','rows'=>array_values($grid)];
        }
        if($only('BRANCH_TRANSFER')&&self::has('branch_transfers')){
            $out['BRANCH_TRANSFER']=['kind'=>'transfer','rows'=>array_map(fn($r)=>['from'=>$r['f'],'to'=>$r['t'],'n'=>(int)$r['n'],'served'=>(int)$r['served']],
                self::rows('SELECT fb.name f,tb.name t,COUNT(*) n,SUM(t.status="COMPLETED") served'.self::FROM.'JOIN branch_transfers bt ON bt.ticket_id=t.id JOIN branches fb ON fb.id=bt.from_branch_id JOIN branches tb ON tb.id=bt.to_branch_id WHERE '.$sw.' AND ty.code="BRANCH_TRANSFER" GROUP BY fb.id,fb.name,tb.id,tb.name ORDER BY n DESC,fb.name LIMIT 10',$sa))];
        }
        if($only('COE_REQUEST')&&self::has('coe_requests')){
            $out['COE_REQUEST']=['kind'=>'coe','rows'=>array_map(fn($r)=>['purpose'=>$r['purpose'],'n'=>(int)$r['n'],'served'=>(int)$r['served']],
                self::rows('SELECT c.purpose,COUNT(*) n,SUM(t.status="COMPLETED") served'.self::FROM.'JOIN coe_requests c ON c.ticket_id=t.id WHERE '.$sw.' AND ty.code="COE_REQUEST" GROUP BY c.purpose ORDER BY n DESC,c.purpose',$sa))];
        }
        if($only('OIC_AUTHORIZATION')&&self::has('oic_authorizations')){
            $out['OIC_AUTHORIZATION']=['kind'=>'oic','active_now'=>self::one('SELECT COUNT(*) FROM oic_authorizations WHERE status="APPROVED" AND start_date<=CURDATE() AND end_date>=CURDATE()'),
                'rows'=>array_map(fn($r)=>['branch'=>$r['name'],'n'=>(int)$r['n'],'approved'=>(int)$r['approved']],
                self::rows('SELECT ob.name,COUNT(*) n,SUM(o.status IN ("APPROVED","EXPIRED")) approved'.self::FROM.'JOIN oic_authorizations o ON o.ticket_id=t.id JOIN branches ob ON ob.id=o.branch_id WHERE '.$sw.' AND ty.code="OIC_AUTHORIZATION" GROUP BY ob.id,ob.name ORDER BY n DESC,ob.name LIMIT 10',$sa))];
        }
        return $out;
    }

    /** Filed and Served per day, or per week when the period is longer than 62 days. */
    private static function throughput(array $p,array $w): array
    {
        $weekly=$w['days']>62;$bucket=static fn(string $col)=>$weekly?'DATE(DATE_SUB('.$col.',INTERVAL WEEKDAY('.$col.') DAY))':'DATE('.$col.')';
        [$sw,$sa]=self::scope($p,$w,['period'=>false]);$series=[];
        $cursor=new DateTimeImmutable($w['start']);$end=new DateTimeImmutable($w['end']);
        if($weekly)$cursor=$cursor->modify('monday this week');
        while($cursor<$end){$series[$cursor->format('Y-m-d')]=['label'=>$cursor->format($weekly?'M j':'D M j'),'filed'=>0,'served'=>0];$cursor=$cursor->modify($weekly?'+7 days':'+1 day');}
        $range=[$w['start'].' 00:00:00',$w['end'].' 00:00:00'];
        foreach(self::rows('SELECT '.$bucket(self::FILED).' d,COUNT(*) n'.self::FROM.'WHERE '.$sw.' AND '.self::FILED.'>=? AND '.self::FILED.'<? GROUP BY d',array_merge($sa,$range)) as $r)if(isset($series[$r['d']]))$series[$r['d']]['filed']=(int)$r['n'];
        foreach(self::rows('SELECT '.$bucket('t.closed_at').' d,COUNT(*) n'.self::FROM.'WHERE '.$sw.' AND t.status="COMPLETED" AND t.closed_at>=? AND t.closed_at<? GROUP BY d',array_merge($sa,$range)) as $r)if(isset($series[$r['d']]))$series[$r['d']]['served']=(int)$r['n'];
        $served=array_sum(array_column($series,'served'));$filed=array_sum(array_column($series,'filed'));
        return ['weekly'=>$weekly,'series'=>array_values($series),'served'=>$served,'filed'=>$filed,'max'=>max(1,...array_map(fn($s)=>max($s['filed'],$s['served']),$series?array_values($series):[['filed'=>0,'served'=>0]]))];
    }

    /** The existing unresolved-viewers report reduced to counts (names stay on the Ticket Viewers screen). Null when unavailable. */
    private static function setup(): ?array
    {
        try{
            $r=TicketViewerService::report(1);
            return ['employees'=>(int)$r['counts']['employees'],'without_viewer'=>(int)$r['missing_total'],'ho_missing'=>(int)$r['counts']['ho_missing'],'retail_missing'=>(int)$r['counts']['retail_missing'],
                'branches_without_sc'=>array_map(fn($b)=>$b['name'],$r['branches_without_sc']),'branches_sc_no_account'=>count($r['branches_sc_no_account'])];
        }catch(Throwable){return null;}
    }

    // ------------------------------------------------------------------ CSV

    public const CSV_HEADER=['Request no','Type','Status','Company','Branch','Area','Employee','Filed','Closed','Days open or to Served','Detail'];
    public static function csvRows(array $p,?DateTimeImmutable $today=null): array
    {
        self::require();$w=self::window($p,$today);[$sw,$sa]=self::scope($p,$w);$names=self::typeNames();
        $branchNames=db()->query('SELECT id,name FROM branches')->fetchAll(PDO::FETCH_KEY_PAIR);
        $areaJoin=self::has('areas')?'LEFT JOIN areas a ON a.id=b.area_id':'';$areaCol=self::has('areas')?'a.name':'NULL';
        $cutoffs=db()->query('SELECT id,code FROM payroll_cutoffs')->fetchAll(PDO::FETCH_KEY_PAIR);
        $coe=self::has('coe_requests')?db()->query('SELECT ticket_id,purpose FROM coe_requests')->fetchAll(PDO::FETCH_KEY_PAIR):[];
        $mp=self::has('ticket_manpower_requests')?array_column(db()->query('SELECT ticket_id,position_code,request_type,quantity FROM ticket_manpower_requests')->fetchAll(),null,'ticket_id'):[];
        $q=db()->prepare('SELECT t.id,t.ticket_no,ty.code,t.status,t.company_group_snapshot g,b.name branch_name,'.$areaCol.' area_name,e.first_name,e.last_name,'.self::FILED.' filed,t.closed_at,t.payroll_period_id,t.payload_json'.self::FROM.$areaJoin.' WHERE '.$sw.' ORDER BY '.self::FILED.' DESC,t.id DESC LIMIT '.(self::CSV_CAP+1));
        $q->execute($sa);$rows=$q->fetchAll();$truncated=count($rows)>self::CSV_CAP;$rows=array_slice($rows,0,self::CSV_CAP);$out=[];
        foreach($rows as $r){
            $pl=json_decode((string)$r['payload_json'],true)?:[];$detail='';
            switch($r['code']){
                case 'UNIFORM_REQUEST':$detail='Size '.($pl['size']??'?').' x'.($pl['quantity']??'?').' for '.($branchNames[(int)($pl['branch']??0)]??'?');break;
                case 'MANPOWER_REQUEST':$m=$mp[$r['id']]??null;$detail=$m?$m['position_code'].' '.$m['request_type'].' x'.$m['quantity']:'';break;
                case 'PAYROLL_CONCERN':$detail='Cutoff '.($cutoffs[$r['payroll_period_id']]??'?');break;
                case 'COE_REQUEST':$detail='Purpose: '.($coe[$r['id']]??'?');break;
                case 'BRANCH_TRANSFER':$detail=($branchNames[(int)($pl['from_branch']??0)]??'?').' to '.($branchNames[(int)($pl['to_branch']??0)]??'?');break;
                case 'OIC_AUTHORIZATION':$detail='Branch '.($branchNames[(int)($pl['branch']??0)]??'?');break;
            }
            $closed=in_array($r['status'],['COMPLETED','CANCELLED','REJECTED'],true)?$r['closed_at']:null;
            $from=new DateTimeImmutable(substr((string)$r['filed'],0,10));$to=$closed?new DateTimeImmutable(substr((string)$closed,0,10)):new DateTimeImmutable($w['today']);
            $out[]=[$r['ticket_no'],$names[$r['code']]??$r['code'],TicketTypes::label($r['status'],$r['code']),self::GROUP_LABEL[$r['g']]??'',$r['branch_name'],(string)$r['area_name'],trim($r['first_name'].' '.$r['last_name']),substr((string)$r['filed'],0,10),$closed?substr((string)$closed,0,10):'',max(0,(int)$from->diff($to)->days),$detail];
        }
        return ['rows'=>$out,'truncated'=>$truncated];
    }
}
