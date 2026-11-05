<?php
declare(strict_types=1);

/**
 * My Team roster (plan 2026-11-05-hr-role-change-retail-parity). Read-only list of the viewer's own people.
 * Scope: employees reporting directly to the viewer's active linked employee record, plus the branch/area scope
 * TicketViewerService already resolves for SC/OIC/ADL. No assignment = empty roster, never everyone.
 * Fields are limited to code, name, position, area/branch and reporting head (no pay, IDs or documents).
 */
final class EmployeeTeamService
{
    private const ACTIVE='("ACTIVE","PROBATIONARY","ON_LEAVE")';

    public static function canView(): bool { return Auth::can('employees.view_team'); }

    /** @return array{0:string,1:array}|null SQL condition over e (employees) and b (branches), or null = no people */
    private static function scope(int $uid): ?array
    {
        $q=db()->prepare('SELECT id FROM employees WHERE user_id=? AND status IN '.self::ACTIVE);$q->execute([$uid]);
        $mine=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        if(!$mine) return null;
        $in=implode(',',array_fill(0,count($mine),'?'));
        $parts=['e.manager_employee_id IN ('.$in.')'];$params=$mine;
        $s=TicketViewerService::ready()?TicketViewerService::scope($uid):null;
        if($s){
            if($s['branch_ids']){$parts[]='(b.site_type<>"HEAD_OFFICE" AND e.branch_id IN ('.implode(',',array_fill(0,count($s['branch_ids']),'?')).'))';$params=array_merge($params,$s['branch_ids']);}
            if($s['area_ids']){$parts[]='(b.site_type<>"HEAD_OFFICE" AND b.area_id IN ('.implode(',',array_fill(0,count($s['area_ids']),'?')).'))';$params=array_merge($params,$s['area_ids']);}
        }
        return ['('.implode(' OR ',$parts).') AND e.id NOT IN ('.$in.')',array_merge($params,$mine)];
    }

    /** @return array{rows:array,total:int} */
    public static function roster(int $uid,string $search='',int $page=1,int $per=25): array
    {
        $empty=['rows'=>[],'total'=>0];
        if(!self::canView() || ($scope=self::scope($uid))===null) return $empty;
        [$where,$params]=$scope;
        $search=trim($search);
        if($search!==''){$like='%'.addcslashes(mb_substr($search,0,60),'%_\\').'%';$where.=' AND (e.employee_no LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)';array_push($params,$like,$like,$like);}
        $from=' FROM employees e LEFT JOIN branches b ON b.id=e.branch_id LEFT JOIN areas a ON a.id=b.area_id LEFT JOIN positions p ON p.id=e.position_id LEFT JOIN employees m ON m.id=e.manager_employee_id WHERE e.status IN '.self::ACTIVE.' AND '.$where;
        $c=db()->prepare('SELECT COUNT(*)'.$from);$c->execute($params);$total=(int)$c->fetchColumn();
        $per=max(1,min(100,$per));$offset=(max(1,$page)-1)*$per;
        $q=db()->prepare('SELECT e.id,e.employee_no,e.first_name,e.last_name,p.name position_name,b.name branch_name,a.name area_name,TRIM(CONCAT(COALESCE(m.first_name,"")," ",COALESCE(m.last_name,""))) reporting_to'.$from.' ORDER BY e.last_name,e.first_name,e.id LIMIT '.$per.' OFFSET '.$offset);
        $q->execute($params);
        return ['rows'=>$q->fetchAll(),'total'=>$total];
    }
}
