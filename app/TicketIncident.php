<?php
declare(strict_types=1);

/**
 * Incident cases (plan 2026-10-25-incident-report-module, phase I1). An Incident Report is still a ticket; this class owns
 * who may read it, the case fields, the people on the case and the HR-set status flow. Nothing here is a legal or retention rule.
 *
 * Who may read a case (one rule, used by TicketRepository::find() and every list):
 *   the reporter; HR reviewers who are not themselves on the case; participants with has_access=1 (a CC the reporter picked, or anyone HR added).
 * Viewers (ADL, Sales Captain, OIC, Manager), Accounting, ticket.sensitive.view holders and other employees never read a case.
 * Only an HR reviewer who is neither the reporter nor on the case handles it (status, participants, internal notes).
 */
final class TicketIncident
{
    public const CODE='INCIDENT_REPORT';
    /** Six labels for sorting and filtering only (John delegated the list, 2026-10-25; Q56). Codes are stored, labels are shown. Not a legal classification (Q59). */
    public const CATEGORIES=['WORKPLACE_CONDUCT'=>'Workplace conduct','HARASSMENT_DISCRIMINATION'=>'Harassment or discrimination','SAFETY'=>'Safety and health','PROPERTY'=>'Property or equipment','ATTENDANCE_RELATED'=>'Attendance-related','OTHER'=>'Other'];
    /** A case filed as Other must say what it is about, since the category tells HR nothing. */
    public const OTHER_MIN_CHARS=30;
    public const ROLES=['CC'=>'CC','INVOLVED'=>'Involved','WITNESS'=>'Witness'];
    public const MAX_PARTICIPANTS=20;
    /** Statuses in which readers may still post messages. */
    public const OPEN=['SUBMITTED','UNDER_REVIEW','IN_PROGRESS','RESOLVED'];
    private static ?bool $ready=null;

    public static function ready():bool
    {
        return self::$ready??=FoundationRepository::tableExists('incident_participants')&&FoundationRepository::tableExists('incident_details');
    }
    public static function isIncident(array $t):bool { return ($t['type_code']??'')===self::CODE; }

    /**
     * SQL fragment (ticket alias t) for "the signed-in user may read this Incident Report", with its parameters.
     * Before migration 017 the old rule applies so the code can ship ahead of the table.
     * @return array{0:string,1:array}
     */
    public static function readClause(int $uid,bool $hr):array
    {
        if(!self::ready())return ['(t.requester_user_id=? OR ?=1)',[$uid,$hr?1:0]];
        return ['(t.requester_user_id=? OR (t.status<>"DRAFT" AND (
            (?=1 AND NOT EXISTS(SELECT 1 FROM incident_participants px WHERE px.ticket_id=t.id AND px.user_id=?))
            OR EXISTS(SELECT 1 FROM incident_participants pa WHERE pa.ticket_id=t.id AND pa.user_id=? AND pa.has_access=1))))',[$uid,$hr?1:0,$uid,$uid]];
    }
    /** "My Incidents": cases I filed or was given access to. HR reviewers get no blanket access here. */
    public static function mineClause(int $uid):array
    {
        if(!self::ready())return ['t.requester_user_id=?',[$uid]];
        return ['(t.requester_user_id=? OR (t.status<>"DRAFT" AND EXISTS(SELECT 1 FROM incident_participants pa WHERE pa.ticket_id=t.id AND pa.user_id=? AND pa.has_access=1)))',[$uid,$uid]];
    }
    public static function onCase(int $ticketId,int $uid):bool
    {
        if(!self::ready())return false;
        $q=db()->prepare('SELECT 1 FROM incident_participants WHERE ticket_id=? AND user_id=?');$q->execute([$ticketId,$uid]);return (bool)$q->fetchColumn();
    }
    /** The one HR reviewer rule: HR, not the reporter, not named on the case. */
    public static function isHandler(array $t):bool
    {
        $uid=TicketRepository::uid();
        return TicketRepository::isHr() && (int)$t['requester_user_id']!==$uid && !self::onCase((int)$t['id'],$uid);
    }
    public static function mayComment(array $t):bool { return in_array($t['status'],self::OPEN,true); }

    /** Permission map in the shape TicketService::permissions() returns, plus the incident-only keys. */
    public static function permissions(array $t):array
    {
        $uid=TicketRepository::uid();$own=(int)$t['requester_user_id']===$uid;$handler=self::isHandler($t);$s=$t['status'];
        return ['edit'=>$own&&$s==='DRAFT'&&TicketTypes::canFile(self::CODE),
            'cancel'=>($own||$handler)&&$s==='SUBMITTED',
            'approve'=>false,'hr'=>false,'return_reject'=>false,
            'attach'=>($own&&in_array($s,['DRAFT','SUBMITTED','UNDER_REVIEW','IN_PROGRESS'],true))||($handler&&in_array($s,['SUBMITTED','UNDER_REVIEW','IN_PROGRESS'],true)),
            'status'=>$handler?self::nextStatuses($s):[],'participants'=>$handler&&$s!=='DRAFT'&&$s!=='CANCELLED','internal_note'=>$handler,'comment'=>self::mayComment($t)];
    }
    /** Statuses HR may move to from $from (cancelling stays a separate action for the reporter). */
    public static function nextStatuses(string $from):array
    {
        return array_values(array_filter(TicketPolicy::INCIDENT_TRANSITIONS[$from]??[],fn($s)=>$s!=='CANCELLED'));
    }

    // ---- filing -------------------------------------------------------------------------------------------------------
    /**
     * Validates the case fields on top of TicketTypes::validate() (title, explanation, incident_at, location, category are already scalar-checked).
     * Returns the payload with a private '_incident' key carrying the rows for incident_details and the reporter's participants; storable() strips it.
     */
    public static function validate(array $out,array $data):array
    {
        if(!self::ready())throw new RuntimeException('Incident cases are not set up yet. Ask your administrator to run the database update.');
        $at=null;$raw=trim((string)($out['incident_at']??''));
        foreach(['Y-m-d\TH:i','Y-m-d H:i','Y-m-d\TH:i:s','Y-m-d H:i:s'] as $format){$d=DateTimeImmutable::createFromFormat('!'.$format,$raw);if($d&&$d->format($format)===$raw){$at=$d;break;}}
        if(!$at)throw new RuntimeException('When it happened must be a valid date and time.');
        if($at>new DateTimeImmutable('now'))throw new RuntimeException('When it happened cannot be in the future.');
        $location=trim((string)($out['location']??''));
        if($location===''||mb_strlen($location)>200)throw new RuntimeException('Where it happened is required and must be 200 characters or fewer.');
        $label=(string)($out['category']??'');$category=array_search($label,self::CATEGORIES,true);
        if($category===false&&isset(self::CATEGORIES[$label]))$category=$label;
        if($category===false)throw new RuntimeException('Choose a valid category.');
        $branch=null;$rawBranch=$data['branch_id']??'';
        if(is_scalar($rawBranch)&&trim((string)$rawBranch)!==''){
            $q=db()->prepare('SELECT id FROM branches WHERE id=? AND active=1');$q->execute([(int)$rawBranch]);
            if(!ctype_digit(trim((string)$rawBranch))||!$q->fetchColumn())throw new RuntimeException('Choose an active branch.');$branch=(int)$rawBranch;
        }
        if($category==='OTHER'&&mb_strlen(trim((string)($out['explanation']??'')))<self::OTHER_MIN_CHARS)throw new RuntimeException('Please explain what happened in at least '.self::OTHER_MIN_CHARS.' characters when the category is Other.');
        $out=array_diff_key($out,['incident_at'=>1,'location'=>1,'category'=>1]);
        $out['_incident']=['incident_at'=>$at->format('Y-m-d H:i:s'),'location_text'=>$location,'branch_id'=>$branch,'category'=>$category,'participants'=>self::pickedParticipants($data['participants']??[])];
        return $out;
    }
    /** The reporter's picks: CC, Involved or Witness, active accounts, never themselves, no repeats. */
    private static function pickedParticipants(mixed $picked):array
    {
        if($picked===''||$picked===null)return [];
        if(!is_array($picked)||count($picked)>self::MAX_PARTICIPANTS)throw new RuntimeException('Choose up to '.self::MAX_PARTICIPANTS.' people for this case.');
        $uid=TicketRepository::uid();$seen=[];$out=[];
        foreach($picked as $item){
            $userId=is_array($item)?($item['user_id']??null):null;$role=is_array($item)?($item['role']??''):'';
            if(!is_scalar($userId)||!ctype_digit((string)$userId)||!is_string($role)||!isset(self::ROLES[$role]))throw new RuntimeException('Each person on the case needs an account and a role.');
            $userId=(int)$userId;
            if($userId===$uid||isset($seen[$userId]))throw new RuntimeException('Choose each person once, and not yourself.');
            self::activeUser($userId);$seen[$userId]=true;$out[]=['user_id'=>$userId,'role'=>$role];
        }
        return $out;
    }
    /** @return int|null the linked employee id */
    private static function activeUser(int $userId):?int
    {
        $q=db()->prepare('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.status="ACTIVE" AND r.portal IN ("employee","hr","admin")');$q->execute([$userId]);
        if(!$q->fetchColumn())throw new RuntimeException('Choose an active account.');
        $q=db()->prepare('SELECT id FROM employees WHERE user_id=? AND status IN ("ACTIVE","PROBATIONARY","ON_LEAVE") ORDER BY id LIMIT 1');$q->execute([$userId]);$e=$q->fetchColumn();return $e?(int)$e:null;
    }
    /** payload_json keeps only title, explanation and the snapshot. */
    public static function storable(array $payload):array { unset($payload['_incident']);return $payload; }

    /** Writes incident_details and the reporter's own participant rows (the reporter may rewrite those while the case is a draft). */
    public static function save(int $ticketId,array $payload):void
    {
        $x=$payload['_incident']??null;if(!$x)return;$uid=TicketRepository::uid();
        db()->prepare('INSERT INTO incident_details(ticket_id,incident_at,location_text,branch_id,category) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE incident_at=VALUES(incident_at),location_text=VALUES(location_text),branch_id=VALUES(branch_id),category=VALUES(category)')
            ->execute([$ticketId,$x['incident_at'],$x['location_text'],$x['branch_id'],$x['category']]);
        $keep=array_column($x['participants'],'user_id');
        $q=db()->prepare('SELECT user_id FROM incident_participants WHERE ticket_id=? AND added_by_hr=0');$q->execute([$ticketId]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $existing)if(!in_array((int)$existing,$keep,true))db()->prepare('DELETE FROM incident_participants WHERE ticket_id=? AND user_id=? AND added_by_hr=0')->execute([$ticketId,$existing]);
        foreach($x['participants'] as $p){
            db()->prepare('INSERT INTO incident_participants(ticket_id,user_id,employee_id,role,has_access,added_by,added_by_hr) VALUES(?,?,?,?,?,?,0) ON DUPLICATE KEY UPDATE role=IF(added_by_hr=0,VALUES(role),role),has_access=IF(added_by_hr=0,VALUES(has_access),has_access),employee_id=VALUES(employee_id)')
                ->execute([$ticketId,$p['user_id'],self::activeUser($p['user_id']),$p['role'],$p['role']==='CC'?1:0,$uid]);
        }
    }

    // ---- employee screens (I2) ----------------------------------------------------------------------------------------
    /**
     * Name search for the participants picker. Active accounts only, never the signed-in user, two characters minimum, ten results at most;
     * returns the name and the branch and nothing else. The caller has already checked the filing permission.
     * @return array<int,array{id:int,name:string,branch:string}>
     */
    public static function searchPeople(string $term,array $exclude=[]):array
    {
        $term=trim($term);if(mb_strlen($term)<2)return [];
        $skip=array_values(array_unique(array_filter(array_map('intval',$exclude))));// ids already on the case (HR adding people)
        $like='%'.addcslashes(mb_substr($term,0,60),'\\%_').'%';
        $q=db()->prepare('SELECT u.id,u.full_name name,COALESCE(b.name,"") branch FROM users u
            LEFT JOIN employees e ON e.id=(SELECT MIN(x.id) FROM employees x WHERE x.user_id=u.id AND x.status IN ("ACTIVE","PROBATIONARY","ON_LEAVE"))
            LEFT JOIN branches b ON b.id=e.branch_id
            JOIN roles r ON r.id=u.role_id AND r.portal IN ("employee","hr","admin")
            WHERE u.status="ACTIVE" AND u.id<>? AND u.full_name LIKE ?'.($skip?' AND u.id NOT IN ('.implode(',',array_fill(0,count($skip),'?')).')':'').' ORDER BY u.full_name,u.id LIMIT 10');
        $q->execute(array_merge([TicketRepository::uid(),$like],$skip));
        return array_map(fn($r)=>['id'=>(int)$r['id'],'name'=>(string)$r['name'],'branch'=>(string)$r['branch']],$q->fetchAll());
    }
    /** Reporter and everyone who already reads the case: left out of HR's search for people to add. @return int[] */
    public static function peopleOnCase(int $ticketId):array
    {
        $q=db()->prepare('SELECT requester_user_id FROM tickets WHERE id=?');$q->execute([$ticketId]);$ids=[(int)$q->fetchColumn()];
        if(self::ready()){$q=db()->prepare('SELECT user_id FROM incident_participants WHERE ticket_id=? AND has_access=1');$q->execute([$ticketId]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)$ids[]=(int)$id;}
        return $ids;
    }
    /** Category and when, for a page of cases the caller has already been allowed to list. @return array<int,array> keyed by ticket id */
    public static function summaries(array $ticketIds):array
    {
        $ids=array_values(array_unique(array_map('intval',$ticketIds)));if(!$ids||!self::ready())return [];
        $q=db()->prepare('SELECT ticket_id,incident_at,category FROM incident_details WHERE ticket_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);$out=[];
        foreach($q->fetchAll() as $r)$out[(int)$r['ticket_id']]=['incident_at'=>$r['incident_at'],'category_label'=>self::CATEGORIES[$r['category']]??$r['category']];
        return $out;
    }
    /** Names for ids the reporter picked earlier (to redraw the form after an error); active accounts only. */
    public static function namesFor(array $userIds):array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$userIds))));if(!$ids)return [];
        $q=db()->prepare('SELECT u.id,u.full_name FROM users u JOIN roles r ON r.id=u.role_id AND r.portal IN ("employee","hr","admin") WHERE u.status="ACTIVE" AND u.id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);
        return array_column($q->fetchAll(),'full_name','id');
    }
    // ---- HR managing people -------------------------------------------------------------------------------------------
    /** Validated row for an HR addition: HR may include anyone with an active account in any role, and that always grants access. */
    public static function hrAddition(int $ticketId,array $t,int $userId,string $role):array
    {
        if(!isset(self::ROLES[$role]))throw new RuntimeException('Choose a role for this person.');
        if($userId===(int)$t['requester_user_id'])throw new RuntimeException('The reporter is already on the case.');
        if($userId===TicketRepository::uid())throw new RuntimeException('You handle this case, so you cannot also be a participant in it.');
        $employee=self::activeUser($userId);
        $q=db()->prepare('SELECT COUNT(*) FROM incident_participants WHERE ticket_id=?');$q->execute([$ticketId]);$count=(int)$q->fetchColumn();
        // A person the reporter named without access (Involved, Witness) can be given access by HR; someone who already reads the case cannot be added again.
        $q=db()->prepare('SELECT has_access FROM incident_participants WHERE ticket_id=? AND user_id=?');$q->execute([$ticketId,$userId]);$had=$q->fetchColumn();
        if($had!==false&&(int)$had===1)throw new RuntimeException('That person is already on the case.');
        if($had===false&&$count>=self::MAX_PARTICIPANTS)throw new RuntimeException('A case can have up to '.self::MAX_PARTICIPANTS.' people.');
        return ['employee_id'=>$employee];
    }

    // ---- reading ------------------------------------------------------------------------------------------------------
    /** Case fields for display, or [] when the case has none (a report filed before migration 017). */
    public static function details(int $ticketId):array
    {
        if(!self::ready())return [];
        $q=db()->prepare('SELECT d.incident_at,d.location_text,d.branch_id,d.category,b.name branch_name FROM incident_details d LEFT JOIN branches b ON b.id=d.branch_id WHERE d.ticket_id=?');$q->execute([$ticketId]);
        $d=$q->fetch();if(!$d)return [];$d['category_label']=self::CATEGORIES[$d['category']]??$d['category'];return $d;
    }
    /**
     * Everyone on the case for display. Role labels other than Reporter and CC are visible to the handling HR reviewer only;
     * everyone else sees the name without a label. has_access is revealed to the handler only.
     */
    public static function participants(array $t):array
    {
        $handler=self::isHandler($t);
        $q=db()->prepare('SELECT u.id user_id,u.full_name name FROM users u WHERE u.id=?');$q->execute([$t['requester_user_id']]);$rows=[];
        if($r=$q->fetch())$rows[]=['user_id'=>(int)$r['user_id'],'name'=>$r['name'],'role'=>'REPORTER','label'=>'Reporter'];
        if(self::ready()){
            $q=db()->prepare('SELECT p.user_id,u.full_name name,p.role,p.has_access,p.added_by_hr FROM incident_participants p JOIN users u ON u.id=p.user_id WHERE p.ticket_id=? ORDER BY p.added_at,p.user_id');$q->execute([$t['id']]);
            foreach($q->fetchAll() as $p){
                $show=$handler||$p['role']==='CC';
                $row=['user_id'=>(int)$p['user_id'],'name'=>$p['name'],'role'=>$show?$p['role']:null,'label'=>$show?self::ROLES[$p['role']]:null];
                if($handler)$row+=['has_access'=>(bool)$p['has_access'],'added_by_hr'=>(bool)$p['added_by_hr']];
                $rows[]=$row;
            }
        }
        return $rows;
    }
}
