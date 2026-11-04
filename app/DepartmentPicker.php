<?php
declare(strict_types=1);

require_once __DIR__.'/EmployeeDirectoryHierarchy.php';
require_once __DIR__.'/EmployeeDirectoryService.php';

/**
 * Employee form Department + Position picker. Display/validation only: the stored `employees.department_id` is still the
 * team row (departments table); the main department shown here is derived with the same Directory mapping, so Directory,
 * payroll export and approver routing never change. See SPEC-department-picker.md.
 */
final class DepartmentPicker
{
    /** Order as John listed it (2026-11-04); Online last. */
    public const GROUPS = ['MANPOWER'=>'Manpower Planning','INVENTORY'=>'Inventory Management','FINANCE'=>'Finance','HR'=>'HR','RETAIL'=>'Retail','MARKETING'=>'Marketing & Creatives','ONLINE'=>'Online'];

    /** Normalised label of the stored department that stands for the group itself (used when no position is chosen). */
    private const HOME = ['MANPOWER'=>'MANPOWERPLANNING','INVENTORY'=>'INVENTORYMANAGEMENT','FINANCE'=>'FINANCE','HR'=>'HR','RETAIL'=>'RETAIL','MARKETING'=>'MARKETING','ONLINE'=>'ONLINE'];

    private static function key(string $label): string { return preg_replace('/[^A-Z0-9]/','',strtoupper($label)) ?? ''; }

    /** Main group key for a stored department name, or '' when it belongs to none of the picker groups. */
    public static function groupOf(string $departmentName): string
    {
        $label=EmployeeDirectoryService::departmentLabel($departmentName);
        $key=self::key($label);
        if(in_array($key,['RETAIL','RETAILDEVELOPMENT','RETAILANDDEVELOPMENT'],true)) return 'RETAIL';
        if(in_array($key,['HR','HUMANRESOURCES'],true)) return 'HR';
        $main=EmployeeDirectoryHierarchy::department($label)['main'];
        return isset(self::GROUPS[$main]) ? $main : '';
    }

    /** Team label (the Directory team name) for a stored department name. */
    public static function teamOf(string $departmentName): string
    {
        $label=EmployeeDirectoryService::departmentLabel($departmentName);
        if(in_array(self::key($label),['RETAILDEVELOPMENT','RETAILANDDEVELOPMENT'],true)) return 'Retail & Development';
        return EmployeeDirectoryHierarchy::department($label)['name'];
    }

    /**
     * group => ['name','company','teams'=>[team=>[position rows]]]. Active rows only, plus the employee's current
     * position/department ($keepPositionId/$keepDepartmentId) so an edit form never loses a saved value.
     * @return array<string,array{name:string,company:string,teams:array<string,array>}>
     */
    public static function tree(?int $keepPositionId=null, ?int $keepDepartmentId=null): array
    {
        $tree=[]; foreach(self::GROUPS as $k=>$name) $tree[$k]=['name'=>$name,'company'=>'','teams'=>[],'_companies'=>[]];
        $dept=[];
        foreach(FoundationRepository::departments() as $d) {
            if(!(int)$d['active'] && (int)$d['id']!==(int)$keepDepartmentId) continue;
            $g=self::groupOf((string)$d['name']);
            if($g==='') { // an employee's current department that is in no group (e.g. an inactive legacy one): kept as is, never lost
                if((int)$d['id']!==(int)$keepDepartmentId) continue;
                $g='KEEP:'.(int)$d['id']; $tree[$g]=['name'=>(string)$d['name'].' (current)','company'=>'','teams'=>[],'_companies'=>[(string)($d['work_company'] ?? '2LOG')=>true]];
            }
            $dept[(int)$d['id']]=['retail'=>strtoupper((string)($d['code'] ?? ''))==='RETAIL','group'=>$g,'team'=>self::teamOf((string)$d['name'])];
            $tree[$g]['_companies'][(string)($d['work_company'] ?? '2LOG')]=true;
        }
        foreach(FoundationRepository::positions() as $p) {
            if(!(int)$p['active'] && (int)$p['id']!==(int)$keepPositionId) continue;
            $id=(int)($p['department_id'] ?? 0);
            if(!isset($dept[$id])) { // a position with no department is listed only for the employee who holds it, under their group
                if((int)$p['id']!==(int)$keepPositionId || !isset($dept[(int)$keepDepartmentId])) continue;
                $tree[$dept[(int)$keepDepartmentId]['group']]['teams']['No team'][]=['id'=>(int)$p['id'],'name'=>(string)$p['name'],'department_id'=>0,'retail'=>false,'active'=>(int)$p['active']];
                continue;
            }
            $tree[$dept[$id]['group']]['teams'][$dept[$id]['team']][]=['id'=>(int)$p['id'],'name'=>(string)$p['name'],'department_id'=>$id,'retail'=>$dept[$id]['retail'],'active'=>(int)$p['active']];
        }
        foreach($tree as $k=>&$g) {
            $g['company']=count($g['_companies'])===1 ? (string)array_key_first($g['_companies']) : '';
            unset($g['_companies']); ksort($g['teams'],SORT_NATURAL|SORT_FLAG_CASE);
        }
        return $tree;
    }

    /**
     * The two form fields (Department group + Position). Position options carry data-group so the page script shows only the
     * chosen department's positions, grouped by Directory team; data-pmbsi-position/data-2log-position keep the Company rules.
     */
    public static function fields(?int $deptId=null, ?int $posId=null, bool $canManageOrg=false, string $positionHint=''): string
    {
        $tree=self::tree($posId,$deptId); $selected='';
        if($deptId) {
            $q=db()->prepare('SELECT name FROM departments WHERE id=?'); $q->execute([$deptId]); $name=$q->fetchColumn();
            if($name!==false) $selected=self::groupOf((string)$name) ?: 'KEEP:'.$deptId;
        }
        $d='<select name="department_group"><option value="">Unassigned</option>';
        foreach($tree as $k=>$g) {
            if(str_starts_with($k,'KEEP:') && $k!==$selected) continue;
            $d.='<option value="'.e($k).'" data-company="'.e($g['company']).'" data-active="'.(str_starts_with($k,'KEEP:')?'0':'1').'" data-retail="'.($k==='RETAIL'?'1':'0').'"'.($k===$selected?' selected':'').'>'.e($g['name']).'</option>';
        }
        $d.='</select>';
        $p='<select name="position_id"'.($canManageOrg?' data-org-url="'.e(url('admin-organization')).'"':'').'><option value="">Unassigned</option>';
        foreach($tree as $k=>$g) {
            if(str_starts_with($k,'KEEP:') && $k!==$selected) continue;
            foreach($g['teams'] as $team=>$rows) {
                $flat=strcasecmp($team,$g['name'])===0; if(!$flat) $p.='<optgroup label="'.e($team).'" data-group="'.e($k).'">';
                foreach($rows as $r) {
                    $p.='<option value="'.$r['id'].'" data-group="'.e($k).'" data-pmbsi-position="'.(EmployeeRepository::positionAllowedForWorkCompany($r['name'],'PMBSI')?'1':'0').'" data-2log-position="'.(EmployeeRepository::positionAllowedForWorkCompany($r['name'],'2LOG')?'1':'0').'" data-retail="'.($r['retail']?'1':'0').'" data-active="'.$r['active'].'"'.($posId===$r['id']?' selected':'').'>'.e($r['name']).'</option>';
                }
                if(!$flat) $p.='</optgroup>';
            }
        }
        $p.='</select>';
        return '<div class="field"><label>Department</label>'.$d.'</div><div class="field"><label>Position</label>'.$p.($positionHint!==''?'<small>'.e($positionHint).'</small>':'').'</div>';
    }

    /** Stored department id of the group itself (e.g. Finance -> "Finance"), 0 when none is active. */
    public static function homeDepartmentId(string $group): int
    {
        foreach(FoundationRepository::departments() as $d) {
            if((int)$d['active'] && self::key(EmployeeDirectoryService::departmentLabel((string)$d['name']))===(self::HOME[$group] ?? '#')) return (int)$d['id'];
        }
        return 0;
    }

    /**
     * Stored department id for a posted form. Old callers (no `department_group` key) keep posting `department_id` and are
     * unchanged. With a group: the chosen position decides the stored department, and a position outside the group is
     * rejected (server-side trust boundary). `KEEP:<id>` keeps an employee's current department that is in no group.
     */
    public static function departmentId(array $post, ?array $current=null): int
    {
        if(!array_key_exists('department_group',$post)) return (int)($post['department_id'] ?? 0);
        $group=strtoupper(trim((string)$post['department_group']));
        if($group==='') return (int)($post['department_id'] ?? 0);
        if(str_starts_with($group,'KEEP:')) return max(0,(int)substr($group,5));
        if(!isset(self::GROUPS[$group])) throw new RuntimeException('Choose a valid Department.');
        $positionId=(int)($post['position_id'] ?? 0);
        // Saving an existing employee without changing group or position never moves their stored department.
        if($current && (int)($current['department_id'] ?? 0)>0 && $positionId===(int)($current['position_id'] ?? 0)) {
            $q=db()->prepare('SELECT name FROM departments WHERE id=?'); $q->execute([(int)$current['department_id']]);
            if(self::groupOf((string)$q->fetchColumn())===$group) return (int)$current['department_id'];
        }
        if($positionId>0) {
            $q=db()->prepare('SELECT p.name,p.department_id,d.name dname FROM positions p LEFT JOIN departments d ON d.id=p.department_id WHERE p.id=?');
            $q->execute([$positionId]); $row=$q->fetch();
            if(!$row) throw new RuntimeException('Choose a valid Position.');
            if($row['department_id']!==null && $row['dname']!==null) {
                $actual=self::groupOf((string)$row['dname']);
                if($actual!==$group) throw new RuntimeException('Position "'.$row['name'].'" belongs to '.(self::GROUPS[$actual] ?? $row['dname']).', not '.self::GROUPS[$group].'. Choose a position under the selected department.');
                return (int)$row['department_id'];
            }
        }
        return self::homeDepartmentId($group);
    }
}
