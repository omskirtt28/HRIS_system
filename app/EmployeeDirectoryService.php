<?php
declare(strict_types=1);

final class EmployeeDirectoryService
{
    private const STATUSES = ['ACTIVE','PROBATIONARY','ON_LEAVE','INACTIVE','RESIGNED','TERMINATED'];

    public static function departmentLabel(string $name): string
    {
        $label = trim(preg_replace('/^(HO|SW)\s+/i', '', trim($name)) ?? $name);
        $label = trim(preg_replace('/\s+(DEPT\.?|DEPARTMENT)$/i', '', $label) ?? $label);
        $aliases = ['IT'=>'MIS','IT DEPT'=>'MIS','MIS / IT'=>'MIS','MIS/IT'=>'MIS','DIGITAL MKTG'=>'Digital','CREATIVE'=>'VM & Creatives','PMMD-SANGIMIGNANO'=>'PMMD'];
        return $aliases[strtoupper($label)] ?? $label;
    }

    public static function companyLabel(string $name): string
    {
        return strtoupper(trim($name)) === 'VISCENZA' ? 'VICENZA' : trim($name);
    }

    private static function areaLabel(array $area): string
    {
        $labels=['MM1'=>'MM1','MM2'=>'MM2','MM3'=>'MM3','NORTH'=>'North','P.SOUTH'=>'Province South','METRO SOUTH'=>'Metro South','VIS'=>'Visayas','MIN'=>'Mindanao','OCAMPOS'=>'Ocampos'];
        return $labels[strtoupper(trim((string)$area['code']))] ?? $area['name'];
    }

    private static function has(string $table, string $column): bool
    {
        return FoundationRepository::columnExists($table, $column);
    }

    public static function page(array $input): array
    {
        Auth::requirePermission('employees.view_all');
        $ready = EmployeeRepository::ready();
        $mode = strtoupper((string)($input['group'] ?? 'SW_RETAIL'));
        $mode=['RETAIL'=>'SW_RETAIL','HO'=>'SW_HO'][$mode] ?? $mode;
        if (!isset(EmployeeDirectoryPlacement::GROUPS[$mode])) $mode = 'SW_RETAIL';
        $status = strtoupper((string)($input['status'] ?? ''));
        if (!in_array($status, self::STATUSES, true)) $status = '';
        $mainDepartments=EmployeeDirectoryHierarchy::groups();
        $main=strtoupper((string)($input['main_department'] ?? ''));
        if(!isset($mainDepartments[$main])) $main='';
        $state = [
            'group'=>$mode, 'area_id'=>max(-1, (int)($input['area_id'] ?? 0)),
            'branch_id'=>max(0, (int)($input['branch_id'] ?? 0)),
            'department'=>substr((string)($input['department'] ?? ''), 0, 40),
            'department_id'=>max(0, (int)($input['department_id'] ?? 0)),
            'main_department'=>$main,
            'company_group'=>in_array(($input['company_group'] ?? ''),['SW','OC'],true)?$input['company_group']:'',
            'assigned_area_id'=>max(0,(int)($input['assigned_area_id'] ?? 0)),
            'q'=>mb_substr(trim((string)($input['q'] ?? '')), 0, 150),
            'status'=>$status, 'needs_details'=>!empty($input['needs_details']) ? 1 : 0,
            'view'=>(($input['view'] ?? '') === 'list') ? 'list' : '',
            'p'=>max(1, (int)($input['p'] ?? 1)),
        ];
        $result = ['ready'=>$ready,'grouping_ready'=>false,'placement_ready'=>false,'state'=>$state,'areas'=>[], 'branches'=>[], 'departments'=>[], 'counts'=>array_fill_keys(array_keys(EmployeeDirectoryPlacement::GROUPS),0), 'cards'=>[], 'rows'=>[], 'total'=>0,'pages'=>1,'title'=>'Employees','subtitle'=>'Find employees by area, branch or department.','parent'=>[], 'crumbs'=>[], 'list'=>false,'grid_label'=>'areas','back_label'=>'areas','note'=>''];
        if (!$ready) return $result;

        $hasArea = FoundationRepository::tableExists('areas') && self::has('branches','area_id');
        $result['grouping_ready'] = $hasArea;
        $result['placement_ready']=EmployeeDirectoryPlacement::ready();
        $branches = [];
        foreach (FoundationRepository::branches() as $branch) $branches[(int)$branch['id']] = $branch;
        if (in_array(strtoupper((string)($input['group'] ?? '')),['RETAIL','HO'],true) && $state['branch_id']) {
            $branch=$branches[$state['branch_id']] ?? [];
            $state['group']=EmployeeDirectoryPlacement::branchCompany($branch)==='OC'?'OC_RETAIL':'SW_RETAIL';
        }
        $areas = [];
        foreach (FoundationRepository::areas() as $area) {
            $area['name']=self::areaLabel($area);
            $areas[(int)$area['id']] = $area;
        }
        $departments = []; $departmentAliases=[];
        foreach (FoundationRepository::departments() as $department) {
            $label = self::departmentLabel((string)$department['name']);
            $placement=EmployeeDirectoryHierarchy::department($label);
            $key = substr(hash('sha256', mb_strtoupper($placement['name'])), 0, 16);
            $departmentAliases[substr(hash('sha256',mb_strtoupper($label)),0,16)]=$key;
            $departmentAliases[$key]=$key;
            if (!isset($departments[$key])) $departments[$key] = ['key'=>$key,'name'=>$label,'display_name'=>$placement['name'],'main'=>$placement['main'],'icon'=>EmployeeDirectoryHierarchy::icon($label),'ids'=>[],'count'=>0,'active'=>false];
            $departments[$key]['ids'][] = (int)$department['id'];
            $departments[$key]['active'] = $departments[$key]['active'] || !empty($department['active']);
            if ($state['department_id'] === (int)$department['id']) $state['department'] = $key;
        }
        $state['department']=$departmentAliases[$state['department']] ?? $state['department'];
        $departmentLookup = [];
        foreach ($departments as $key=>$department) foreach ($department['ids'] as $id) $departmentLookup[$id] = $key;

        // Current Employee 201 assignments own placement. Roster sheet is only a fallback.
        $hoName='UPPER(TRIM(REPLACE(REPLACE(COALESCE(b.name,""),CHAR(39),""),"’","")))';
        $ho = '('.$hoName.' IN ("HEAD OFFICE","SW HEAD OFFICE","HEAD OFFICE SW","OCAMPOS HEAD OFFICE","HEAD OFFICE OCAMPOS","OCAMPO HEAD OFFICE","HEAD OFFICE OCAMPO") OR UPPER(TRIM(COALESCE(b.code,""))) IN ("HO","HEAD-OFFICE","HEAD_OFFICE","SW_HO","SWHO","OC_HO","OCHO","OCAMPOS_HO","OCAMPOS_HEAD_OFFICE"))';
        if (self::has('branches','site_type')) $ho = '('.$ho.' OR b.site_type="HEAD_OFFICE")';
        $retail = $hasArea ? 'b.area_id IS NOT NULL' : '0=1';
        if (self::has('branches','site_type')) $retail = '('.$retail.' OR b.site_type="RETAIL_STORE")';
        $roster = self::has('employees','roster_group') ? 'UPPER(TRIM(COALESCE(e.roster_group,"")))' : '""';
        $workplace = 'CASE WHEN '.$ho.' THEN "HO" WHEN '.$retail.' THEN "RETAIL" WHEN '.$roster.'="RETAIL" THEN "RETAIL" WHEN '.$roster.' IN ("HO","HEAD OFFICE") THEN "HO" ELSE "UNASSIGNED" END';
        $placement=EmployeeDirectoryPlacement::expressions($workplace,$hasArea);
        $classification=$placement['group'];
        $from = ' FROM employees e LEFT JOIN branches b ON b.id=e.branch_id LEFT JOIN positions p ON p.id=e.position_id'.($hasArea?' LEFT JOIN areas a ON a.id=b.area_id':'');
        // Resolve old branch/team links while retaining the selected company tab.
        if ($state['branch_id'] && !EmployeeDirectoryPlacement::isRetail($state['group'])) {
            $branch=$branches[$state['branch_id']] ?? [];
            $state['group']=EmployeeDirectoryPlacement::branchCompany($branch)==='OC'?'OC_RETAIL':'SW_RETAIL';
        }
        if ($state['department_id'] || $state['department']!=='' || $state['main_department']!=='') {
            if (!EmployeeDirectoryPlacement::isHeadOffice($state['group'])) $state['group']='SW_HO';
            $state['branch_id']=0; $state['area_id']=0;
            if($state['department']!=='') $state['main_department']=$departments[$state['department']]['main'] ?? '';
        }
        $where = ['1=1']; $params = [];
        if ($state['status'] !== '') { $where[]='e.status=?'; $params[]=$state['status']; }
        $hasPending = self::has('employees','roster_needs_details');
        if ($state['needs_details'] && $hasPending) $where[]='e.roster_needs_details=1';
        $aggregate = db()->prepare('SELECT '.$classification.' directory_group,e.branch_id,e.department_id,COUNT(*) employee_count'.$from.' WHERE '.implode(' AND ', $where).' GROUP BY directory_group,e.branch_id,e.department_id');
        $aggregate->execute($params);
        $branchCounts = []; $areaCounts = []; $unassignedDepartmentCount = 0;
        foreach ($aggregate->fetchAll() as $row) {
            $rowGroup = $row['directory_group']; $count = (int)$row['employee_count'];
            $result['counts'][$rowGroup] += $count;
            $result['counts']['ALL'] += $count;
            if ($rowGroup!==$state['group']) continue;
            if (EmployeeDirectoryPlacement::isRetail($rowGroup)) {
                $branchId=(int)$row['branch_id'];
                $branchCounts[$branchId]=($branchCounts[$branchId] ?? 0)+$count;
                $areaId=(int)($branches[$branchId]['area_id'] ?? 0);
                $areaCounts[$areaId]=($areaCounts[$areaId] ?? 0)+$count;
            } elseif (EmployeeDirectoryPlacement::isHeadOffice($rowGroup)) {
                $key=$departmentLookup[(int)$row['department_id']] ?? null;
                if ($key !== null) $departments[$key]['count'] += $count;
                else $unassignedDepartmentCount += $count;
            }
        }
        $areaBranchCounts=[];
        foreach($branches as $id=>$branch) {
            if(strtoupper(trim((string)$branch['name']))==='HEAD OFFICE' || ($branch['site_type'] ?? '')==='HEAD_OFFICE') continue;
            $expectedCompany=$state['group']==='OC_RETAIL'?'OC':'SW';
            if(EmployeeDirectoryPlacement::branchCompany($branch)!==$expectedCompany && empty($branchCounts[$id])) continue;
            if(empty($branch['active']) && empty($branchCounts[$id])) continue;
            $areaId=(int)($branch['area_id'] ?? 0);
            $areaBranchCounts[$areaId]=($areaBranchCounts[$areaId] ?? 0)+1;
        }
        if ($state['branch_id'] && isset($branches[$state['branch_id']])) {
            $branch=$branches[$state['branch_id']];
            $state['area_id']=(int)($branch['area_id'] ?? 0) ?: -1;
            if (strtoupper(trim((string)$branch['name']))==='HEAD OFFICE' || ($branch['site_type'] ?? '')==='HEAD_OFFICE') {
                $state['group']='ALL'; $state['area_id']=0;
            }
        } elseif ($state['branch_id']) {
            $state['group']=EmployeeDirectoryPlacement::isRetail($state['group'])?$state['group']:'SW_RETAIL';
        }
        if ($state['department_id'] || $state['department'] !== '' || $state['main_department']!=='') {
            $state['group']=EmployeeDirectoryPlacement::isHeadOffice($state['group'])?$state['group']:'SW_HO'; $state['branch_id']=0; $state['area_id']=0;
            if($state['department']!=='') $state['main_department']=$departments[$state['department']]['main'] ?? '';
        }
        $directEmployees=EmployeeDirectoryPlacement::isHeadOffice($state['group']) && !empty($mainDepartments[$state['main_department']]['direct']);
        if($directEmployees) $state['department']='';
        $result['list'] = $directEmployees || $state['branch_id'] > 0 || $state['department'] !== '' || in_array($state['group'], ['ALL','UNASSIGNED','MANAGERS','ADL'], true) || $state['view']==='list';
        $base = ['group'=>$state['group']];
        if ($state['needs_details']) $base['needs_details']=1;
        if ($state['status'] !== '') $base['status']=$state['status'];
        $result['crumbs'][]=['label'=>EmployeeDirectoryPlacement::GROUPS[$state['group']], 'params'=>$base];
        $result['parent']=$base;

        if (EmployeeDirectoryPlacement::isRetail($state['group'])) {
            $result['title']=EmployeeDirectoryPlacement::GROUPS[$state['group']]; $result['subtitle']='Choose an area to view its branches.';
            $result['grid_label']=$state['area_id'] ? 'branches' : 'areas';
            if ($state['area_id'] !== 0) {
                $area=$areas[$state['area_id']] ?? null;
                $result['title']=$state['area_id']===-1 ? 'To assign' : ($area['name'] ?? 'Area not found');
                $result['subtitle']='Choose a branch to view its employees.';
                $result['crumbs'][]=['label'=>$result['title'],'params'=>$base+['area_id'=>$state['area_id']]];
                foreach ($branches as $id=>$branch) {
                    if(EmployeeDirectoryPlacement::branchCompany($branch)!==($state['group']==='OC_RETAIL'?'OC':'SW') && empty($branchCounts[$id])) continue;
                    $areaId=(int)($branch['area_id'] ?? 0);
                    if (($state['area_id']===-1 ? $areaId!==0 : $areaId!==$state['area_id'])) continue;
                    if (strtoupper(trim((string)$branch['name']))==='HEAD OFFICE' || ($branch['site_type'] ?? '')==='HEAD_OFFICE') continue;
                    if (empty($branch['active']) && empty($branchCounts[$id])) continue;
                    $result['cards'][]=['title'=>$branch['code'] ?: $branch['name'],'detail'=>$branch['name'],'count'=>$branchCounts[$id] ?? 0,'params'=>$base+['area_id'=>$state['area_id'],'branch_id'=>$id],'action'=>'Open employees','icon'=>'store','notice'=>strtoupper(trim((string)$branch['code']))==='NOM2' && !$areaId ? 'Needs checking' : ''];
                }
                if ($state['area_id']===-1 && !empty($branchCounts[0])) $result['cards'][]=['title'=>'No branch yet','detail'=>'Update the branch in Employee 201','count'=>$branchCounts[0],'params'=>$base+['area_id'=>-1,'view'=>'list'],'action'=>'View employees','icon'=>'users','notice'=>'Needs details'];
            } elseif($state['group']==='OC_RETAIL') {
                $result['subtitle']='Choose a branch to view its employees.'; $result['grid_label']='branches';
                foreach($branches as $id=>$branch) {
                    if(EmployeeDirectoryPlacement::branchCompany($branch)!=='OC' && empty($branchCounts[$id])) continue;
                    if(empty($branch['active']) && empty($branchCounts[$id])) continue;
                    $result['cards'][]=['title'=>$branch['code'] ?: $branch['name'],'detail'=>$branch['name'],'count'=>$branchCounts[$id] ?? 0,'params'=>$base+['branch_id'=>$id],'action'=>'Open employees','icon'=>'store','notice'=>''];
                }
            } else {
                foreach ($areas as $id=>$area) {
                    if (empty($area['active']) && empty($areaCounts[$id])) continue;
                    if(empty($areaBranchCounts[$id]) && empty($areaCounts[$id])) continue;
                    $result['cards'][]=['title'=>$area['name'],'detail'=>'','count'=>$areaCounts[$id] ?? 0,'params'=>$base+['area_id'=>$id],'action'=>'Open branches','icon'=>'map-pin','notice'=>''];
                }
                $unmappedBranchCount=0;
                foreach($branches as $id=>$branch) if(empty($branch['area_id']) && !empty($branchCounts[$id])) $unmappedBranchCount++;
                if (!empty($areaCounts[0]) || $unmappedBranchCount) $result['cards'][]=['title'=>'To assign','detail'=>'Area or branch details needed','count'=>$areaCounts[0] ?? 0,'params'=>$base+['area_id'=>-1],'action'=>'Open branches','icon'=>'users','notice'=>'Needs checking'];
            }
            if ($state['branch_id']) {
                $branch=$branches[$state['branch_id']] ?? null;
                $result['title']=$branch ? ($branch['code'] ?: $branch['name']) : 'Branch not found';
                $result['subtitle']=$branch['name'] ?? 'Choose another branch.';
                $result['parent']=$state['group']==='OC_RETAIL'?$base:$base+['area_id'=>$state['area_id']];
                $result['back_label']='branches';
                $result['crumbs'][]=['label'=>$result['title'],'params'=>[]];
            }
        } elseif (EmployeeDirectoryPlacement::isHeadOffice($state['group'])) {
            $result['title']=EmployeeDirectoryPlacement::GROUPS[$state['group']]; $result['subtitle']='Choose a department to view its teams or employees.';
            $result['grid_label']='groups'; $result['back_label']='main departments';
            if($state['main_department']!=='') {
                $main=$mainDepartments[$state['main_department']];
                $result['title']=$main['name']; $result['subtitle']='Choose a team to view its employees.'; $result['grid_label']='teams';
                $result['crumbs'][]=['label'=>$main['name'],'params'=>$base+['main_department'=>$state['main_department']]];
                if($directEmployees) {
                    $result['subtitle']=$state['main_department']==='MARKETING' ? 'Marketing, Digital Marketing and VM & Creatives employees.' : $main['name'].' employees.';
                    $result['parent']=$base; $result['back_label']=EmployeeDirectoryPlacement::GROUPS[$state['group']];
                } else foreach($departments as $key=>$department) {
                    if($department['main']!==$state['main_department'] || (!$department['active'] && !$department['count'])) continue;
                    $direct=$department['display_name']===$main['name'];
                    $result['cards'][]=['title'=>$direct ? $main['name'].' employees' : $department['display_name'],'detail'=>$direct?'Employees assigned directly to '.$main['name']:'','count'=>$department['count'],'params'=>$base+['main_department'=>$state['main_department'],'department'=>$key],'action'=>'Open employees','icon'=>$department['icon'],'notice'=>''];
                }
            } else {
                foreach($mainDepartments as $code=>$main) {
                    $count=0;
                    foreach($departments as $department) if($department['main']===$code) $count+=$department['count'];
                    $result['cards'][]=['title'=>$main['name'],'detail'=>$main['detail'],'count'=>$count,'params'=>$base+['main_department'=>$code],'action'=>!empty($main['direct'])?'Open employees':'Open teams','icon'=>$main['icon'],'notice'=>'','section'=>'Main departments','order'=>array_search($code,array_keys($mainDepartments),true)];
                }
                foreach($departments as $key=>$department) {
                    if($department['main']!=='' || !$department['count']) continue;
                    $result['cards'][]=['title'=>$department['display_name'],'detail'=>'','count'=>$department['count'],'params'=>$base+['department'=>$key],'action'=>'Open employees','icon'=>$department['icon'],'notice'=>'','section'=>'Other departments','order'=>10];
                }
                if($unassignedDepartmentCount) $result['cards'][]=['title'=>'To assign','detail'=>'Department details needed','count'=>$unassignedDepartmentCount,'params'=>$base+['department'=>'unassigned'],'action'=>'View employees','icon'=>'users','notice'=>'Needs details','section'=>'Other departments','order'=>11];
            }
            if ($state['department'] !== '') {
                $result['title']=$state['department']==='unassigned' ? 'To assign' : ($departments[$state['department']]['display_name'] ?? 'Department not found');
                if($state['main_department']!=='' && $result['title']===$mainDepartments[$state['main_department']]['name']) $result['title'].=' employees';
                $result['subtitle']='Head Office employees';
                $result['parent']=$state['main_department']!=='' ? $base+['main_department'=>$state['main_department']] : $base;
                $result['back_label']=$state['main_department']!=='' ? $mainDepartments[$state['main_department']]['name'] : EmployeeDirectoryPlacement::GROUPS[$state['group']];
                $result['note']='';
                $result['crumbs'][]=['label'=>$result['title'],'params'=>[]];
            }
        } elseif(in_array($state['group'],['MANAGERS','ADL'],true)) {
            $result['title']=$state['group']==='ADL'?'Area Development Leaders':EmployeeDirectoryPlacement::GROUPS[$state['group']];
            $result['subtitle']=$state['group']==='ADL'?'View ADLs by company and assigned area.':'Department Managers only. Open Employee 201 to view their details.';
        } else {
            $result['title']=$state['group']==='ALL' ? ($state['q']!==''?'Search results':'All employees') : 'To assign';
            $result['subtitle']=$state['group']==='ALL' ? 'All employees, including Department Managers and ADLs.' : 'Set the company group, branch or department in Employee 201.';
        }
        if ($result['list']) {
            if ($state['group']!=='ALL') { $where[]='('.$classification.')=?'; $params[]=$state['group']; }
            if ($state['branch_id']) { $where[]='e.branch_id=?'; $params[]=$state['branch_id']; }
            elseif (EmployeeDirectoryPlacement::isRetail($state['group']) && $state['area_id']!==0) {
                if ($state['area_id']===-1) $where[]=$state['view']==='list' ? 'b.id IS NULL' : ($hasArea ? 'b.area_id IS NULL' : '1=1');
                else { $where[]=$hasArea ? 'b.area_id=?' : '0=1'; if($hasArea) $params[]=$state['area_id']; }
            }
            if ($state['department']!=='') {
                if ($state['department']==='unassigned') $where[]='d.id IS NULL';
                elseif (isset($departments[$state['department']])) {
                    $ids=$departments[$state['department']]['ids'];
                    $where[]='e.department_id IN ('.implode(',', array_fill(0,count($ids),'?')).')';
                    $params=array_merge($params,$ids);
                } else $where[]='0=1';
            } elseif(EmployeeDirectoryPlacement::isHeadOffice($state['group']) && $state['main_department']!=='') {
                $ids=[];
                foreach($departments as $department) if($department['main']===$state['main_department']) $ids=array_merge($ids,$department['ids']);
                if($ids) { $where[]='e.department_id IN ('.implode(',',array_fill(0,count($ids),'?')).')'; $params=array_merge($params,$ids); }
                else $where[]='0=1';
            }
            if(in_array($state['group'],['MANAGERS','ADL'],true) && $state['company_group']!=='') { $where[]='('.$placement['company'].')=?'; $params[]=$state['company_group']; }
            if($state['group']==='ADL' && $state['assigned_area_id']) {
                $where[]=$result['placement_ready']?'LOCATE(CONCAT(",",?,","),COALESCE(e.directory_area_ids,""))>0':'0=1';
                if($result['placement_ready']) $params[]=(string)$state['assigned_area_id'];
            }
            if ($state['q']!=='') {
                $fields=['e.employee_no','e.first_name','e.middle_name','e.last_name','e.company_email','e.personal_email','CONCAT_WS(" ",e.first_name,e.middle_name,e.last_name)','p.name'];
                if($state['group']==='ALL') $fields=array_merge($fields,['d.name','b.name','b.code']);
                if(self::has('employees','roster_full_name')) $fields[]='e.roster_full_name';
                if(self::has('employees','roster_original_code')) $fields[]='e.roster_original_code';
                $where[]='('.implode(' OR ', array_map(static fn($field)=>$field.' LIKE ?', $fields)).')';
                foreach($fields as $field) $params[]='%'.$state['q'].'%';
            }
            $joins=$from.' LEFT JOIN departments d ON d.id=e.department_id';
            $countStatement=db()->prepare('SELECT COUNT(*)'.$joins.' WHERE '.implode(' AND ',$where));
            $countStatement->execute($params); $result['total']=(int)$countStatement->fetchColumn();
            $result['pages']=max(1,(int)ceil($result['total']/25));
            $state['p']=min($state['p'],$result['pages']); $offset=($state['p']-1)*25;
            $company='NULL business_unit_name,NULL legal_entity_name';
            if(FoundationRepository::tableExists('business_units') && self::has('employees','business_unit_id')) { $joins.=' LEFT JOIN business_units bu ON bu.id=e.business_unit_id'; $company='bu.name business_unit_name,NULL legal_entity_name'; }
            if(FoundationRepository::tableExists('legal_entities') && self::has('employees','legal_entity_id')) { $joins.=' LEFT JOIN legal_entities le ON le.id=e.legal_entity_id'; $company=str_replace('NULL legal_entity_name','le.name legal_entity_name',$company); }
            $statement=db()->prepare('SELECT e.*,d.name department_name,p.name position_name,b.name branch_name,b.code branch_code,'.$company.','.$workplace.' workplace,'.$placement['company'].' directory_company,'.$placement['role'].' directory_section'.$joins.' WHERE '.implode(' AND ',$where).' ORDER BY e.last_name,e.first_name,e.id LIMIT 25 OFFSET '.$offset);
            $statement->execute($params); $result['rows']=$statement->fetchAll();
        } else {
            if($state['q']!=='') $result['cards']=array_values(array_filter($result['cards'],static fn($card)=>mb_stripos($card['title'].' '.$card['detail'],$state['q'])!==false));
            $areaOrder=array_flip(['MM1','MM2','MM3','North','Province South','Metro South','Visayas','Mindanao','Ocampos']);
            $areaGrid=$state['group']==='SW_RETAIL' && $state['area_id']===0;
            usort($result['cards'],static function($a,$b) use($areaGrid,$areaOrder): int {
                $sectionOrder=($a['order'] ?? 0) <=> ($b['order'] ?? 0);
                if($sectionOrder) return $sectionOrder;
                $pending=($a['title']==='To assign') <=> ($b['title']==='To assign');
                if($pending) return $pending;
                if($areaGrid) { $order=($areaOrder[$a['title']] ?? 99) <=> ($areaOrder[$b['title']] ?? 99); if($order) return $order; }
                return strnatcasecmp($a['title'],$b['title']);
            });
        }
        $result['state']=$state; $result['areas']=$areas; $result['branches']=$branches; $result['departments']=$departments;
        return $result;
    }
}
