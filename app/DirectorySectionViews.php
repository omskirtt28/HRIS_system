<?php
declare(strict_types=1);

/** Employment-tab field for the Ocampo's Head Office section. Shown only for Company Group Ocampo's + a Head Office site. */
final class DirectorySectionViews
{
    /**
     * @param array $employee the employee row (directory_company_group, branch_id, directory_section_id)
     * Always posts directory_section_present so the server can clear a section the employee no longer qualifies for.
     */
    public static function field(array $employee): string
    {
        if (!DirectorySectionService::ready()) return '';
        $current = isset($employee['directory_section_id']) && $employee['directory_section_id'] !== null ? (int)$employee['directory_section_id'] : null;
        $sections = DirectorySectionService::forScope(DirectorySectionService::COMPANY_GROUP, DirectorySectionService::SITE_TYPE, $current);
        $hoBranches = DirectorySectionService::headOfficeBranchIds();
        $visible = strtoupper((string)($employee['directory_company_group'] ?? '')) === DirectorySectionService::COMPANY_GROUP && in_array((int)($employee['branch_id'] ?? 0), $hoBranches, true);
        $options = '<option value="">No section</option>';
        foreach ($sections as $s) {
            $options .= '<option value="'.(int)$s['id'].'"'.($current === (int)$s['id'] ? ' selected' : '').'>'.e($s['name']).(empty($s['active']) ? ' · Retired' : '').'</option>';
        }
        $html = '<input type="hidden" name="directory_section_present" value="1">'
            .'<div class="field" id="directory-section-field" data-ho-branches="'.e(json_encode($hoBranches)).'" data-group="'.e(DirectorySectionService::COMPANY_GROUP).'"'.($visible ? '' : ' style="display:none"').'>'
            .'<label for="directory_section_id">Section (Ocampo\'s Head Office)</label>'
            .'<select id="directory_section_id" name="directory_section_id"'.($visible ? '' : ' disabled').'>'.$options.'</select>'
            .'<small>Only for Ocampo\'s employees at Head Office. Leave on "No section" if none applies.</small></div>'
            .'<script>(function(){var f=document.getElementById("directory-section-field"),s=document.getElementById("directory_section_id"),g=document.querySelector("[data-company-group]"),b=document.querySelector(\'select[name="branch_id"]\');if(!f||!s||!g||!b)return;var ho=[];try{ho=JSON.parse(f.getAttribute("data-ho-branches")||"[]")}catch(e){}function sync(){var on=g.value===f.getAttribute("data-group")&&ho.indexOf(parseInt(b.value,10))!==-1;f.style.display=on?"":"none";s.disabled=!on;}g.addEventListener("change",sync);b.addEventListener("change",sync);sync();})();</script>';
        return $html;
    }
}
