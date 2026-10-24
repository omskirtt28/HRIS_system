<?php
declare(strict_types=1);
require_once __DIR__.'/TicketService.php';

/**
 * Data and actions behind the HR Request Queue screen (tabs, search, age, uniform summary, CSV, quick and bulk actions).
 * Reads reuse TicketRepository::hrFilters(); every status change goes through TicketService::act(), one ticket at a
 * time, so permissions, version check, events and notifications are exactly those of the ticket detail page.
 */
final class TicketQueue
{
    public const TABS=['all'=>'All','pending'=>'Pending','progress'=>'In progress','served'=>'Served','cancelled'=>'Cancelled'];
    private const TAB_STATUS=['pending'=>'PENDING_HR','progress'=>'IN_PROGRESS','served'=>'COMPLETED','cancelled'=>'CANCELLED'];
    public const OPEN_STATUSES=['PENDING_HR','IN_PROGRESS'];
    /** Types HR may process from the list. Everything else needs the full page (outcome, approver or date). */
    public const BULK_TYPES=['ATM_REQUEST','ID_REQUEST','UNIFORM_REQUEST'];
    private const SKIP_REASON=[
        'PAYROLL_CONCERN'=>'Payroll Concern needs an HR outcome. Open it to resolve.',
        'BRANCH_TRANSFER'=>'Branch Transfer needs a destination approver and effective date. Open it to complete.',
        'MANPOWER_REQUEST'=>'Manpower Request is processed one by one. Open it.',
        'NETSUITE_CONCERN'=>'NetSuite Concern uses its own Pending/Complete flow. Open it.',
        'OIC_AUTHORIZATION'=>'OIC Authorization is processed one by one. Open it.',
        'INCIDENT_REPORT'=>'Incident Reports are handled in the Incident Feed.',
    ];
    private const ICONS=['ATM_REQUEST'=>'wallet','ID_REQUEST'=>'user','UNIFORM_REQUEST'=>'boxes','PAYROLL_CONCERN'=>'receipt','MANPOWER_REQUEST'=>'users','NETSUITE_CONCERN'=>'monitor','BRANCH_TRANSFER'=>'map-pin','OIC_AUTHORIZATION'=>'key','INCIDENT_REPORT'=>'alert'];
    public const PER_PAGE=25;
    public const MAX_BULK=100;
    public const EXPORT_CAP=5000;

    public static function icon(string $code):string { return self::ICONS[$code]??'clipboard'; }

    /** Whitelisted, trimmed filter set. Anything unknown is dropped. */
    public static function filters(array $in):array
    {
        $date=static fn($v)=>is_string($v)&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)&&strtotime($v)!==false?$v:'';
        $out=[
            'tab'=>array_key_exists((string)($in['tab']??''),self::TABS)?(string)$in['tab']:'all',
            'q'=>mb_substr(trim(is_string($in['q']??null)?$in['q']:''),0,80),
            'type_code'=>is_string($in['type_code']??null)&&preg_match('/^[A-Z_]{1,40}$/',$in['type_code'])?$in['type_code']:'',
            'company_label'=>in_array($in['company_label']??'',['SW','OFJI'],true)?$in['company_label']:'',
            'company_group'=>in_array($in['company_group']??'',['SW','OC'],true)?$in['company_group']:'',
            'business_unit_id'=>max(0,(int)($in['business_unit_id']??0)),
            'branch_id'=>max(0,(int)($in['branch_id']??0)),
            'age'=>in_array((string)($in['age']??''),['0','1','2'],true)?(string)$in['age']:'',
            'closed_from'=>$date($in['closed_from']??''),'closed_to'=>$date($in['closed_to']??''),
            'sort'=>in_array($in['sort']??'',['oldest','newest','type'],true)?$in['sort']:'oldest',
            'p'=>max(1,min(10000,(int)($in['p']??1))),
        ];
        // Older bookmarks used ?status=. Map the four tab statuses back to tabs; other statuses stay as a plain filter.
        if($out['tab']==='all'&&is_string($in['status']??null)&&$in['status']!==''){
            $tab=array_search($in['status'],self::TAB_STATUS,true);
            if($tab!==false)$out['tab']=$tab;else $out['status']=preg_match('/^[A-Z_]{1,30}$/',$in['status'])?$in['status']:'';
        }
        return $out;
    }
    /** Query-string pairs worth keeping in a link (no empty values, no page). */
    public static function linkParams(array $f,array $override=[]):array
    {
        $f=array_replace($f,$override);unset($f['p']);
        $f=array_filter($f,static fn($v,$k)=>$v!==''&&$v!==0&&!($k==='tab'&&$v==='all')&&!($k==='sort'&&$v==='oldest'),ARRAY_FILTER_USE_BOTH);
        return $f;
    }

    private static function requireHr():void
    {
        if(!TicketRepository::isHr())throw new RuntimeException('HR ticket review access is required.');
    }
    public static function today():string { return date('Y-m-d'); }

    /** @return array{0:string,1:array} WHERE (without the word) and params. $statusFree drops tab and status for the tab counts. */
    public static function where(array $f,bool $statusFree=false):array
    {
        $hr=array_intersect_key($f,array_flip(['type_code','company_label','company_group','business_unit_id','branch_id']));
        if(!$statusFree){
            if(isset(self::TAB_STATUS[$f['tab']]))$hr['status']=self::TAB_STATUS[$f['tab']];
            elseif(!empty($f['status']))$hr['status']=$f['status'];
        }
        [$where,$params]=TicketRepository::hrFilters('services',$hr);
        if($f['q']!==''){
            $like='%'.addcslashes($f['q'],'%_\\').'%';
            $where[]='(t.ticket_no LIKE ? OR CONCAT(e.first_name," ",e.last_name) LIKE ? OR CONCAT(e.last_name," ",e.first_name) LIKE ? OR CONCAT(e.last_name,", ",e.first_name) LIKE ? OR b.name LIKE ?)';
            array_push($params,$like,$like,$like,$like,$like);
        }
        if($f['age']!==''){
            $age='DATEDIFF(?,DATE(COALESCE(t.filed_at,t.created_at)))';
            $where[]='t.status IN ("PENDING_HR","IN_PROGRESS") AND '.match($f['age']){'0'=>$age.'<=2','1'=>$age.' BETWEEN 3 AND 5',default=>$age.'>5'};
            $params[]=self::today();
        }
        if($f['closed_from']!==''){$where[]='t.closed_at>=?';$params[]=$f['closed_from'].' 00:00:00';}
        if($f['closed_to']!==''){$where[]='t.closed_at<DATE_ADD(?,INTERVAL 1 DAY)';$params[]=$f['closed_to'];}
        return [implode(' AND ',$where),$params];
    }

    public static function ageDays(array $t):?int
    {
        if(in_array($t['status'],['COMPLETED','CANCELLED','REJECTED'],true))return null;
        $from=substr((string)($t['filed_at']??$t['created_at']),0,10);
        return max(0,(int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable(self::today()))->days);
    }
    /** 'ok' up to 2 days, 'warn' 3 to 5, 'late' over 5. */
    public static function ageTone(?int $days):string { return $days===null?'':($days<=2?'ok':($days<=5?'warn':'late')); }

    public static function page(array $f):array
    {
        self::requireHr();$uid=TicketRepository::uid();
        [$where,$params]=self::where($f);
        $order=match($f['sort']){'newest'=>'COALESCE(t.filed_at,t.created_at) DESC,t.id DESC','type'=>'ty.name,COALESCE(t.filed_at,t.created_at),t.id',default=>'COALESCE(t.filed_at,t.created_at) ASC,t.id ASC'};
        $offset=($f['p']-1)*self::PER_PAGE;
        $q=db()->prepare(TicketRepository::selectSql().' WHERE '.$where.' ORDER BY '.$order.' LIMIT '.(self::PER_PAGE+1).' OFFSET '.$offset);$q->execute($params);$rows=$q->fetchAll();
        $hasNext=count($rows)>self::PER_PAGE;$rows=array_slice($rows,0,self::PER_PAGE);
        foreach($rows as &$t){
            $t['payload']=json_decode((string)$t['payload_json'],true)?:[];
            $t['age_days']=self::ageDays($t);$t['age_tone']=self::ageTone($t['age_days']);
            $t['summary']=self::summary($t);
            $t['actions']=self::actionsFor($t,$uid);
            $t['bulk_ok']=in_array($t['type_code'],self::BULK_TYPES,true);
        }
        unset($t);
        return ['rows'=>$rows,'has_next'=>$hasNext,'page'=>$f['p']];
    }
    private static function summary(array $t):string
    {
        $p=$t['payload'];
        if(in_array($t['type_code'],['ATM_REQUEST','ID_REQUEST'],true))return mb_strimwidth(preg_replace('/\s+/',' ',(string)($p['reason']??'')),0,90,'…');
        if($t['type_code']==='UNIFORM_REQUEST')return 'Size '.($p['size']??'?').' × '.($p['quantity']??'?');
        return '';
    }
    /** Row quick actions. Only the three simple types, only Pending/In Progress, never your own request. */
    public static function actionsFor(array $t,int $uid):array
    {
        if(!in_array($t['type_code'],self::BULK_TYPES,true)||!in_array($t['status'],self::OPEN_STATUSES,true))return [];
        if((int)$t['requester_user_id']===$uid||(int)($t['subject_user_id']??0)===$uid)return [];
        return $t['status']==='PENDING_HR'?['START','SERVED','CANCEL']:['SERVED','CANCEL'];
    }

    /** Count per tab for the current filters (tab ignored). */
    public static function counts(array $f):array
    {
        self::requireHr();
        [$where,$params]=self::where($f,true);
        $q=db()->prepare('SELECT t.status,COUNT(*) n'.TicketRepository::fromSql().' WHERE '.$where.' GROUP BY t.status');$q->execute($params);
        $by=$q->fetchAll(PDO::FETCH_KEY_PAIR);$out=['all'=>array_sum($by)];
        foreach(self::TAB_STATUS as $tab=>$status)$out[$tab]=(int)($by[$status]??0);
        return $out;
    }

    /** Uniform sizes per target branch for the current filter. On the All tab only open requests are counted, so it is an order list. */
    public static function uniformSummary(array $f):array
    {
        self::requireHr();
        $sizes=TicketTypes::definition('UNIFORM_REQUEST')['fields']['size']['options'];
        if($f['type_code']!==''&&$f['type_code']!=='UNIFORM_REQUEST')return ['sizes'=>$sizes,'branches'=>[],'totals'=>[],'requests'=>0,'scope'=>''];
        $g=$f;$g['type_code']='UNIFORM_REQUEST';
        [$where,$params]=self::where($g);
        $scope=$f['tab']==='all'&&empty($f['status'])?'open (Pending and In progress) requests':'requests in this view';
        if($scope!=='requests in this view')$where.=' AND t.status IN ("PENDING_HR","IN_PROGRESS")';
        $q=db()->prepare('SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(t.payload_json,"$.branch")) AS UNSIGNED) bid,JSON_UNQUOTE(JSON_EXTRACT(t.payload_json,"$.size")) size,
            SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(t.payload_json,"$.quantity")) AS UNSIGNED)) qty,COUNT(*) n'.TicketRepository::fromSql().' WHERE '.$where.' GROUP BY bid,size');
        $q->execute($params);$rows=$q->fetchAll();
        $names=[];if($rows){$ids=array_values(array_unique(array_map(fn($r)=>(int)$r['bid'],$rows)));
            $n=db()->prepare('SELECT id,name FROM branches WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$n->execute($ids);$names=$n->fetchAll(PDO::FETCH_KEY_PAIR);}
        $branches=[];$totals=array_fill_keys($sizes,0);$requests=0;
        foreach($rows as $r){
            $name=$names[(int)$r['bid']]??('Branch '.(int)$r['bid']);
            $branches[$name]??=array_fill_keys($sizes,0)+['_total'=>0];
            $size=(string)$r['size'];if(!isset($totals[$size]))continue;
            $branches[$name][$size]+=(int)$r['qty'];$branches[$name]['_total']+=(int)$r['qty'];$totals[$size]+=(int)$r['qty'];$requests+=(int)$r['n'];
        }
        ksort($branches,SORT_NATURAL|SORT_FLAG_CASE);
        return ['sizes'=>$sizes,'branches'=>$branches,'totals'=>$totals,'requests'=>$requests,'scope'=>$scope];
    }

    /** CSV rows for the filtered queue. Names and branch only; no reasons, no contact data. */
    public static function exportRows(array $f):array
    {
        self::requireHr();
        [$where,$params]=self::where($f);
        $q=db()->prepare(TicketRepository::selectSql().' WHERE '.$where.' ORDER BY COALESCE(t.filed_at,t.created_at) ASC,t.id ASC LIMIT '.self::EXPORT_CAP);$q->execute($params);
        $out=[];
        foreach($q->fetchAll() as $t){
            $p=json_decode((string)$t['payload_json'],true)?:[];$uniform=$t['type_code']==='UNIFORM_REQUEST';
            $age=self::ageDays($t);
            $out[]=[$t['ticket_no'],$t['type_name'],trim($t['first_name'].' '.$t['last_name']),$t['branch_name'],$t['company_name_snapshot'],$p['company']??'',substr((string)($t['filed_at']??$t['created_at']),0,10),$age===null?'':$age,TicketTypes::label($t['status'],$t['type_code']),$uniform?($p['size']??''):'',$uniform?($p['quantity']??''):''];
        }
        return $out;
    }
    public const EXPORT_HEADER=['Ticket no','Type','Employee','Branch','Company','Company label','Filed','Age (days)','Status','Uniform size','Uniform quantity'];
    /** Spreadsheet programs run cells starting with = + - @ as formulas. */
    public static function csvCell(mixed $v):string
    {
        $v=(string)$v;return $v!==''&&strpbrk($v[0],"=+-@\t\r")!==false?"'".$v:$v;
    }

    /** Fragment data for the preview drawer. Incident Reports never open here. */
    public static function preview(int $id):array
    {
        self::requireHr();
        $t=TicketRepository::find($id);
        if($t['type_code']==='INCIDENT_REPORT')throw new RuntimeException('Incident Reports open on their own page.');
        $q=db()->prepare('SELECT s.approver_type,s.status,u.full_name FROM ticket_steps s LEFT JOIN users u ON u.id=s.approver_user_id WHERE s.ticket_id=? ORDER BY s.step_no');$q->execute([$id]);$t['steps']=$q->fetchAll();
        $t['age_days']=self::ageDays($t);$t['age_tone']=self::ageTone($t['age_days']);
        $t['actions']=self::actionsFor($t,TicketRepository::uid());
        return $t;
    }

    // ------------------------------------------------------------------ actions

    private const DO=['START'=>'START','SERVED'=>'COMPLETE','CANCEL'=>'CANCEL'];
    private const VERB=['START'=>'started','SERVED'=>'served','CANCEL'=>'cancelled'];

    /**
     * Quick action ($bulk=false, any of START/SERVED/CANCEL) or bulk action ($bulk=true, SERVED/CANCEL only) on tickets
     * identified by id => version seen on screen. Each ticket runs through TicketService::act() on its own; a ticket
     * that is not allowed is skipped with a reason, never forced.
     * @return array{done:int,skipped:array,verb:string,requested:int}
     */
    public static function apply(array $items,string $do,string $remarks,bool $bulk):array
    {
        self::requireHr();
        if(!isset(self::DO[$do])||($bulk&&$do==='START'))throw new RuntimeException('Unknown action.');
        $items=array_filter($items,static fn($v,$k)=>(int)$k>0,ARRAY_FILTER_USE_BOTH);
        if(!$items)throw new RuntimeException('Select at least one request.');
        if(count($items)>self::MAX_BULK)throw new RuntimeException('Select up to '.self::MAX_BULK.' requests at a time.');
        $remarks=trim($remarks);if(mb_strlen($remarks)>500)throw new RuntimeException('Remarks must be 500 characters or fewer.');
        $done=0;$skipped=[];$doneIds=[];
        foreach($items as $id=>$version){
            $id=(int)$id;
            try{$t=TicketRepository::find($id);}catch(RuntimeException){$skipped[]=['ticket_no'=>'#'.$id,'type'=>'','reason'=>'Not found or not available to you.'];continue;}
            $row=['ticket_no'=>$t['ticket_no'],'type'=>$t['type_name']];
            if(!in_array($t['type_code'],self::BULK_TYPES,true)){$skipped[]=$row+['reason'=>self::SKIP_REASON[$t['type_code']]??'Open this request to process it.'];continue;}
            if(!in_array($t['status'],self::OPEN_STATUSES,true)){$skipped[]=$row+['reason'=>'Already '.strtolower(TicketTypes::label($t['status'],$t['type_code'])).'; only Pending and In Progress requests are processed.'];continue;}
            if($do==='START'&&$t['status']!=='PENDING_HR'){$skipped[]=$row+['reason'=>'Already In Progress.'];continue;}
            if((int)$t['version']!==(int)$version){$skipped[]=$row+['reason'=>'Changed since you loaded the list. Reload and try again.'];continue;}
            try{TicketService::act($id,(int)$t['version'],self::DO[$do],$remarks);$done++;$doneIds[]=$id;}
            catch(PDOException){error_log('HR request queue action failed.');$skipped[]=$row+['reason'=>'Could not be saved. Reload and try again.'];}
            catch(Throwable $error){$skipped[]=$row+['reason'=>$error->getMessage()];}
        }
        audit('Tickets',($bulk?'BULK_':'QUICK_').$do,'ticket',null,['requested'=>count($items),'done_ticket_ids'=>$doneIds,'skipped'=>count($skipped),'remarks_given'=>$remarks!=='']);
        return ['done'=>$done,'skipped'=>$skipped,'verb'=>self::VERB[$do],'requested'=>count($items)];
    }
    public static function summaryLine(array $r):string
    {
        $parts=[];if($r['done'])$parts[]=$r['done'].' '.$r['verb'];
        if($r['skipped'])$parts[]=count($r['skipped']).' skipped';
        return $parts?implode(', ',$parts):'Nothing was processed';
    }
}
