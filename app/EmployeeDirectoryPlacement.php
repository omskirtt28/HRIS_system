<?php
declare(strict_types=1);

/** Directory display only: never changes payroll approvers or account permissions. */
final class EmployeeDirectoryPlacement
{
    public const GROUPS = ['SW_RETAIL'=>'Sw Retail','OC_RETAIL'=>"Ocampo's Retail",'SW_HO'=>'Head Office Sw','OC_HO'=>"Head Office Ocampo's",'MANAGERS'=>'Department Managers','ADL'=>'ADL','UNASSIGNED'=>'To assign','ALL'=>'All employees'];

    public static function ready(): bool
    {
        foreach (['directory_company_group','directory_role','directory_area_ids'] as $column) {
            if (!FoundationRepository::columnExists('employees',$column)) return false;
        }
        return true;
    }

    public static function isHeadOffice(string $group): bool { return in_array($group,['SW_HO','OC_HO'],true); }
    public static function isRetail(string $group): bool { return in_array($group,['SW_RETAIL','OC_RETAIL'],true); }
    public static function companyLabel(string $code): string { return ['SW'=>'Sw','OC'=>"Ocampo's"][$code] ?? 'Not set'; }

    public static function branchCompany(array $branch): string
    {
        $code=strtoupper(trim((string)($branch['area_code'] ?? '')));
        if (in_array($code,['OCAMPOS','OCAMPO'],true) || in_array(strtoupper(trim((string)($branch['code'] ?? ''))),['OGAL','OMEG','OFAR','ORMI','OMOA','OBIC'],true)) return 'OC';
        return in_array($code,['MM1','MM2','MM3','NORTH','P.SOUTH','METRO SOUTH','VIS','MIN'],true) ? 'SW' : '';
    }

    public static function expressions(string $workplace, bool $hasArea): array
    {
        $area=$hasArea ? 'UPPER(TRIM(COALESCE(a.code,"")))' : '""';
        $branch='UPPER(TRIM(COALESCE(b.code,"")))';
        $fallback='CASE WHEN ('.$workplace.')="RETAIL" AND ('.$area.' IN ("OCAMPOS","OCAMPO") OR '.$branch.' IN ("OGAL","OMEG","OFAR","ORMI","OMOA","OBIC")) THEN "OC" WHEN ('.$workplace.')="RETAIL" AND '.$area.' IN ("MM1","MM2","MM3","NORTH","P.SOUTH","METRO SOUTH","VIS","MIN") THEN "SW" ELSE "" END';
        $company=self::ready() ? 'COALESCE(NULLIF(e.directory_company_group,""),('.$fallback.'))' : $fallback;
        $position='UPPER(TRIM(COALESCE(p.name,"")))';
        $autoRole='CASE WHEN '.$position.' IN ("ADL","SR ADL","JR ADL","AREA DEVELOPMENT LEADER","SENIOR AREA DEVELOPMENT LEADER","JUNIOR AREA DEVELOPMENT LEADER","SR. ADL","JR. ADL") THEN "ADL" WHEN ('.$workplace.')="HO" AND '.$position.' IN ("DEPARTMENT MANAGER","FINANCE MANAGER","INVENTORY MANAGEMENT MANAGER","MANPOWER PLANNING MANAGER","MARKETING MANAGER","MARKETING & CREATIVES MANAGER","HR MANAGER","HUMAN RESOURCES MANAGER","INFORMATION SYSTEM MANAGER","INFORMATION SYSTEMS MANAGER","MIS MANAGER","ACCOUNTING MANAGER") THEN "DEPARTMENT_MANAGER" ELSE "EMPLOYEE" END';
        $role=self::ready() ? 'COALESCE(NULLIF(e.directory_role,""),('.$autoRole.'))' : $autoRole;
        $group='CASE WHEN ('.$role.')="ADL" THEN "ADL" WHEN ('.$role.')="DEPARTMENT_MANAGER" THEN "MANAGERS" WHEN ('.$company.')="SW" AND ('.$workplace.')="RETAIL" THEN "SW_RETAIL" WHEN ('.$company.')="OC" AND ('.$workplace.')="RETAIL" THEN "OC_RETAIL" WHEN ('.$company.')="SW" AND ('.$workplace.')="HO" THEN "SW_HO" WHEN ('.$company.')="OC" AND ('.$workplace.')="HO" THEN "OC_HO" ELSE "UNASSIGNED" END';
        return ['company'=>$company,'role'=>$role,'group'=>$group];
    }

    public static function areaIds(?string $value): array
    {
        return array_values(array_unique(array_filter(array_map('intval',explode(',',(string)$value)),static fn($id)=>$id>0)));
    }

    public static function areaNames(?string $value,array $areas): string
    {
        $names=[];
        foreach(self::areaIds($value) as $id) $names[]=$areas[$id]['name'] ?? 'Area to check';
        return $names ? implode(' · ',$names) : 'Not set';
    }

    public static function save(int $id,array $data,array $old): array
    {
        if (empty($data['directory_fields_present'])) return [];
        if (!self::ready()) throw new RuntimeException('Import 20261010_employee_company_directory.sql before saving directory groups.');
        if (!db()->inTransaction()) throw new RuntimeException('Directory assignments must be saved with the employee transaction.');
        $company=strtoupper(trim((string)($data['directory_company_group'] ?? '')));
        $role=strtoupper(trim((string)($data['directory_role'] ?? '')));
        if (!in_array($company,['','SW','OC'],true) || !in_array($role,['','EMPLOYEE','DEPARTMENT_MANAGER','ADL'],true)) throw new RuntimeException('Choose a valid company group and directory section.');
        $input=$data['directory_area_ids'] ?? [];
        if (!is_array($input) || count($input)>100) throw new RuntimeException('Choose valid assigned areas.');
        $ids=[]; $areas=[];
        foreach(FoundationRepository::areas() as $area) $areas[(int)$area['id']]=$area;
        $previous=self::areaIds($old['directory_area_ids'] ?? null);
        foreach($input as $value) {
            if (!is_scalar($value) || !ctype_digit((string)$value) || (int)$value<1) throw new RuntimeException('Choose a valid assigned area.');
            $areaId=(int)$value;
            if (!isset($areas[$areaId]) || (empty($areas[$areaId]['active']) && !in_array($areaId,$previous,true))) throw new RuntimeException('Choose active assigned areas.');
            $ids[]=$areaId;
        }
        $ids=array_values(array_unique($ids)); sort($ids,SORT_NUMERIC);
        $areaValue=$ids ? ','.implode(',',$ids).',' : null;
        if (strlen((string)$areaValue)>1000) throw new RuntimeException('Too many assigned areas.');
        $after=['directory_company_group'=>$company ?: null,'directory_role'=>$role ?: null,'directory_area_ids'=>$areaValue];
        db()->prepare('UPDATE employees SET directory_company_group=?,directory_role=?,directory_area_ids=? WHERE id=?')->execute([$after['directory_company_group'],$after['directory_role'],$areaValue,$id]);
        return $after;
    }
}
