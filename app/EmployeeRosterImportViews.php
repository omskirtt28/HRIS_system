<?php
declare(strict_types=1);

function employee_roster_select(string $name, array $choices, string $selected, string $label): void
{
    echo '<select name="'.e($name).'" aria-label="'.e($label).'">';
    foreach ($choices as $value=>$text) echo '<option value="'.e((string)$value).'" '.((string)$value === $selected ? 'selected' : '').'>'.e($text).'</option>';
    echo '</select>';
}

function employee_roster_page(): void
{
    render_portal_header('hr','hr-employees','Import Employees');
    echo '<link rel="stylesheet" href="public/assets/employee-roster.css?v=20261010">';
    page_head('HR / Employees','Import Employees','<a class="btn" href="'.url('hr-employees').'">Employee Directory</a>');
    if (!EmployeeRosterImportService::ready()) {
        echo '<div class="alert error">Install the original roster SQL, then <code>database/migrations/20261010_employee_roster_followup_details.sql</code> in phpMyAdmin and reload.</div>';
        render_portal_footer(); return;
    }
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        $history = EmployeeRosterImportService::history(); ?>
        <section class="panel roster-upload"><div class="panel-head"><div><h2>Upload employee roster</h2><p>One Excel file with Retail and/or HO sheets. Up to 20 MB and 5,000 employees.</p></div></div>
          <form method="post" enctype="multipart/form-data" class="panel-body">
            <?=csrf_field()?><input type="hidden" name="action" value="employee_roster_upload">
            <p>Upload Excel, review the filled details, then import the employees. Missing details can be finished later in Employee 201.</p>
            <div class="roster-note">Employee Code, Name, Branch, Department, Position, Company and Start Date come from Excel. Existing matches are used automatically. New departments, positions and companies are added when you import; a new branch also needs its Branch Code in Excel.<br>Retail COMPANY is the <strong>brand / client</strong>. HO COMPANY is the <strong>employer</strong>. Finish any other details later in Employee 201.</div>
            <div class="field"><label for="roster-file">Excel file</label><input id="roster-file" type="file" name="roster_file" accept=".xlsx" required></div>
            <button class="btn primary" type="submit">Upload & preview</button>
            <p class="tiny muted">Existing Employee Codes are skipped by default. Accounts and passwords stay as they are. New employees can be linked to an account later in Employee 201.</p>
          </form>
        </section>
        <section class="panel"><div class="panel-head"><div><h2>My roster uploads</h2><p>Continue checking rows from an earlier upload.</p></div></div>
          <?php if (!$history):?><div class="empty">No roster uploads yet.</div><?php else:?>
          <div class="roster-table-wrap"><table class="tbl"><thead><tr><th>File</th><th>Uploaded</th><th>Rows</th><th>Imported</th><th></th></tr></thead><tbody>
          <?php foreach ($history as $h):?><tr><td><?=e($h['original_name'])?></td><td><?=e(date('M j, Y g:i A',strtotime($h['created_at'])))?></td><td><?=(int)$h['row_count']?></td><td><?=(int)$h['imported_count']?></td><td><a class="btn sm" href="<?=url('hr-employee-import',['id'=>$h['id']])?>">Open preview</a></td></tr><?php endforeach;?>
          </tbody></table></div><?php endif;?>
        </section>
        <?php render_portal_footer(); return;
    }
    try { $batch = EmployeeRosterImportService::review($id); }
    catch (Throwable $e) {
        echo '<div class="alert error">'.e($e instanceof PDOException || $e instanceof JsonException ? 'This roster preview could not be opened. Check the SQL patch.' : $e->getMessage()).'</div>';
        render_portal_footer(); return;
    }
    $catalog = $batch['catalog']; $review = $batch['review'];
    $labels = ['READY'=>'Ready','FOLLOWUP'=>'Import & Fix Later','CHECK'=>'Needs Checking','EXISTS'=>'Already Exists','IMPORTED'=>'Imported','DETAILS'=>'Needs Details','IGNORED'=>'Skipped'];
    $tab = (string)($_GET['tab'] ?? ($review['counts']['READY'] ? 'READY' : ($review['counts']['FOLLOWUP'] ? 'FOLLOWUP' : 'IMPORTED')));
    if (!isset($labels[$tab])) $tab = 'READY';
    $step = (string)($_GET['step'] ?? 'rows');
    $rowId = (int)($_GET['row_id'] ?? 0);
    $token = EmployeeRosterImportService::token($batch);
    $oldForm = $_SESSION['roster_failed_form'] ?? []; unset($_SESSION['roster_failed_form']);
    ?>
    <div class="roster-toolbar"><div><strong><?=e($batch['original_name'])?></strong><div class="tiny muted"><?=(int)$batch['row_count']?> rows · <?=(int)$review['counts']['IMPORTED']?> imported</div></div><a class="btn sm" href="<?=url('hr-employee-import')?>">Upload another file</a></div>
    <nav class="employee-tabs roster-tabs" aria-label="Import steps">
      <a class="<?=$step!=='matches'?'active':''?>" href="<?=url('hr-employee-import',['id'=>$id])?>">Review & Import</a>
      <a class="<?=$step==='matches'?'active':''?>" href="<?=url('hr-employee-import',['id'=>$id,'step'=>'matches'])?>">Change Matches (optional)</a>
    </nav>
    <div class="roster-note">Details from Excel are filled automatically. Import Ready and Import &amp; Fix Later rows together, then finish missing details in Employee 201. Imported profiles with missing details have a <strong>Needs Details</strong> mark. Missing or duplicate codes stay unconfirmed until HR fixes the identity.</div>
    <?php if ($rowId):
        $chosen = null; foreach ($review['rows'] as $r) if ((int)$r['id'] === $rowId) $chosen = $r;
        if ($chosen && !$chosen['imported_at']) employee_roster_row_editor($batch,$chosen,$oldForm);
        else echo '<div class="alert error">That row is unavailable or already imported.</div>';
    elseif ($step === 'matches'):
        $kindLabels = ['department'=>'Departments','site'=>'Branches / Sites','position'=>'Positions','brand'=>'Retail Brands / Clients','employer'=>'HO Employers'];
        ?>
        <section class="panel"><div class="panel-head"><div><h2>Change matches</h2><p>Details are matched from Excel automatically. Change a match only if it is incorrect.</p></div></div>
          <form method="post" class="panel-body">
            <?=csrf_field()?><input type="hidden" name="action" value="employee_roster_matches"><input type="hidden" name="import_id" value="<?=$id?>"><input type="hidden" name="version" value="<?=(int)$batch['version']?>">
            <div class="roster-note"><strong>Add from Excel</strong> saves a new department, position, company or branch when you import an employee. A new branch needs its name and Branch Code from Excel. Existing matches and any changes you saved are kept.</div>
            <?php foreach ($kindLabels as $kind=>$heading):
                $sources = array_filter($batch['sources'],static fn($s)=>$s['kind']===$kind);
                if (!$sources) continue;
                $unmatched = 0; foreach ($sources as $key=>$s) if (($batch['mapping'][$key] ?? '') === '') $unmatched++;
                ?>
                <details class="roster-matches" <?=$unmatched ? 'open' : ''?>><summary><?=e($heading)?> <span class="badge <?=$unmatched?'amber':'gray'?>"><?=$unmatched?> to match</span></summary>
                  <div class="roster-match-list">
                  <?php foreach ($sources as $key=>$source): $selected = (string)($batch['mapping'][$key] ?? '');
                      if (($oldForm['import_id'] ?? 0) == $id && isset($oldForm['matches'][$key])) $selected = (string)$oldForm['matches'][$key]; ?>
                    <div class="roster-match-row"><div><strong><?=e($source['name'] ?: '(blank in Excel)')?></strong><small><?=e($source['context'])?> · <?=$source['count']?> rows</small></div>
                    <?php employee_roster_select('matches['.$key.']',EmployeeRosterImportService::choices($source,$catalog),$selected,$heading.' match for '.$source['name']); ?></div>
                  <?php endforeach;?>
                  </div>
                </details>
            <?php endforeach;?>
            <div class="roster-form-actions"><button class="btn primary" type="submit">Save matches & review</button><a class="btn" href="<?=url('hr-employee-import',['id'=>$id])?>">Back to rows</a></div>
          </form>
        </section>
    <?php else:
        $rows = array_values(array_filter($review['rows'],static fn($r)=>$tab==='DETAILS' ? $r['state']==='IMPORTED' && !empty($r['pending']) : $r['state']===$tab));
        $pages = max(1,(int)ceil(count($rows)/25)); $p = max(1,min($pages,(int)($_GET['p'] ?? 1)));
        $readyUpdates = count(array_filter($review['rows'],static fn($r)=>in_array($r['state'],['READY','FOLLOWUP'],true) && $r['action']==='UPDATE'));
        $importable = $review['counts']['READY'] + $review['counts']['FOLLOWUP'];
        $readyNew = $importable - $readyUpdates;
        ?>
        <nav class="roster-filters" aria-label="Preview rows"><?php foreach ($labels as $key=>$label):?><a class="btn sm <?=$tab===$key?'primary':''?>" href="<?=url('hr-employee-import',['id'=>$id,'tab'=>$key])?>"><?=e($label)?> <span><?=(int)$review['counts'][$key]?></span></a><?php endforeach;?></nav>
        <section class="panel"><div class="panel-head"><div><h2><?=e($labels[$tab])?></h2><p><?=e(match($tab) {'READY'=>'These rows have the details needed for Employee 201.','FOLLOWUP'=>'These profiles can be imported now. Finish the listed details later in Employee 201.','CHECK'=>'Correct the values that cannot be saved, then import.','EXISTS'=>'These records already exist and are skipped by default.','IMPORTED'=>'These rows have been saved to Employee 201.','DETAILS'=>'Already imported. Open Employee 201 and finish the listed details.',default=>'These rows were marked Skip.'})?></p></div></div>
          <?php if (!$rows):?><div class="empty">No rows here. Open another tab to see the employees.</div><?php else:?>
          <div class="roster-table-wrap"><table class="tbl roster-table"><thead><tr><th>Excel row</th><th>Employee</th><th>Branch / Department</th><th>Position / Company</th><th>Start Date</th><th></th></tr></thead><tbody>
          <?php foreach (array_slice($rows,($p-1)*25,25) as $r): $d=$r['data']; $a=$r['assignment']; $company=$d['source_sheet']==='RETAIL'?'brand':'employer'; ?>
            <tr><td><?=e($r['source_sheet'])?> <?=$r['source_row']?></td><td><strong><?=e($d['raw']['NAME'])?></strong><small>Code: <?=e($d['employee_no'] ?: 'Missing')?></small><small><?=e(trim($d['first_name'].' '.$d['middle_name'].' '.$d['last_name'].' '.$d['suffix']))?></small>
              <?php if ($r['existing']):?><small>In HRIS: <?=e(EmployeeRepository::fullName($r['existing']))?></small><?php endif;?>
              <?php if ($r['action']==='UPDATE'):?><span class="badge amber">Update selected</span><?php endif;?></td>
            <td><?=e($a['site']['name'])?><small><?=e($a['department']['name'])?></small></td><td><?=e($a['position']['name'])?><small><?=e($a[$company]['name'])?> · <?=e($company==='brand'?'Brand / Client':'Employer')?></small></td><td><?=e($d['hire_date'] ?: 'Missing / invalid')?></td>
            <td><?php if ($r['pending']):?><span class="badge amber">Needs Details</span><?php endif;?><?php if ($r['imported_at'] && $r['employee_id']):?><a class="btn sm" href="<?=url('hr-employee',['id'=>$r['employee_id']])?>">Open Employee 201</a><?php elseif($r['state']==='EXISTS' && $r['existing']):?><a class="btn sm" href="<?=url('hr-employee',['id'=>$r['existing']['id']])?>">Open Employee 201</a><a class="btn sm" href="<?=url('hr-employee-import',['id'=>$id,'row_id'=>$r['id']])?>">Review update</a><?php else:?><a class="btn sm" href="<?=url('hr-employee-import',['id'=>$id,'row_id'=>$r['id']])?>"><?=$tab==='CHECK'?'Fix row':'Review row (optional)'?></a><?php endif;?></td></tr>
            <?php if ($r['errors']):?><tr class="roster-error-row"><td colspan="6"><?=e(implode(' ', $r['errors']))?></td></tr><?php endif;?>
          <?php endforeach;?></tbody></table></div>
          <div class="roster-pagination"><span>Page <?=$p?> of <?=$pages?> · <?=count($rows)?> rows</span><div><?php if ($p>1):?><a class="btn sm" href="<?=url('hr-employee-import',['id'=>$id,'tab'=>$tab,'p'=>$p-1])?>">Previous</a><?php endif;?><?php if ($p<$pages):?><a class="btn sm" href="<?=url('hr-employee-import',['id'=>$id,'tab'=>$tab,'p'=>$p+1])?>">Next</a><?php endif;?></div></div>
          <?php endif;?>
        </section>
        <section class="panel"><div class="panel-head"><div><h2>Import employees</h2><p><?=$readyNew?> new profiles and <?=$readyUpdates?> selected updates can be imported. <?=$review['counts']['FOLLOWUP']?> rows have details to finish later.</p></div></div>
          <form method="post" class="panel-body roster-commit">
            <?=csrf_field()?><input type="hidden" name="action" value="employee_roster_commit"><input type="hidden" name="import_id" value="<?=$id?>"><input type="hidden" name="version" value="<?=(int)$batch['version']?>"><input type="hidden" name="review_token" value="<?=e($token)?>">
            <p>Import Ready and Import &amp; Fix Later rows together. Missing Start Dates and unconfirmed codes stay blank. Duplicate or missing codes are not used to match accounts or biometric logs.</p>
            <label class="roster-check"><input type="checkbox" name="review_confirmed" value="1" required> I reviewed the roster and will finish the marked details in Employee 201.</label>
            <?php if ($readyUpdates):?><label class="roster-check"><input type="checkbox" name="include_updates" value="1"> Include the <?=$readyUpdates?> updates I selected after reviewing the existing employees.</label><?php endif;?>
            <button class="btn primary" type="submit" <?=$importable===0?'disabled':''?>>Import employees (<?=$importable?>)</button>
          </form>
        </section>
    <?php endif;
    render_portal_footer();
}

function employee_roster_row_editor(array $batch, array $row, array $oldForm): void
{
    $d = $row['data']; $id = (int)$batch['id']; $catalog = $batch['catalog'];
    if ((int)($oldForm['import_id'] ?? 0)===$id && (int)($oldForm['row_id'] ?? 0)===(int)$row['id']) {
        foreach (['employee_no','first_name','middle_name','last_name','suffix','hire_date','override_department','override_position','override_site','override_brand','override_employer'] as $field) if (isset($oldForm[$field])) $d[$field]=(string)$oldForm[$field];
        $row['action'] = (string)($oldForm['row_action'] ?? $row['action']);
        $d['assignment_checked'] = !empty($oldForm['assignment_checked']);
        $d['formula_checked'] = !empty($oldForm['formula_checked']);
        $d['update_checked'] = !empty($oldForm['same_person']);
    }
    ?>
    <section class="panel"><div class="panel-head"><div><h2><?=e($row['source_sheet'])?> row <?=$row['source_row']?></h2><p><?=e($d['raw']['NAME'])?> · Original code: <?=e($d['raw']['EMPLOYEECODE'] ?: '(blank)')?></p></div><a class="btn sm" href="<?=url('hr-employee-import',['id'=>$id,'tab'=>$row['state']])?>">Back to preview</a></div>
      <form method="post" class="panel-body">
        <?=csrf_field()?><input type="hidden" name="action" value="employee_roster_row"><input type="hidden" name="import_id" value="<?=$id?>"><input type="hidden" name="version" value="<?=(int)$batch['version']?>"><input type="hidden" name="row_id" value="<?=(int)$row['id']?>">
        <?php if ($row['errors']):?><div class="alert error"><?=e(implode(' ',$row['errors']))?></div><?php endif;?>
        <p class="tiny muted">These details are filled from Excel. Edit only a value that needs correction.</p>
        <div class="roster-edit-grid">
          <div class="field"><label for="roster-employee-code">Employee Code</label><input id="roster-employee-code" name="employee_no" maxlength="50" value="<?=e($d['employee_no'])?>"><small>Use the real employee number. It also matches biometric logs.</small></div>
          <div class="field"><label for="roster-start-date">Start Date</label><input id="roster-start-date" name="hire_date" type="date" value="<?=e($d['hire_date'])?>"><small>Excel value: <?=e($d['raw']['STARTDATE'] ?: '(blank)')?></small></div>
          <?php foreach (['first_name'=>'First Name','middle_name'=>'Middle Name / Initial','last_name'=>'Last Name','suffix'=>'Suffix'] as $field=>$label):?><div class="field"><label for="roster-<?=e($field)?>"><?=e($label)?></label><input id="roster-<?=e($field)?>" name="<?=e($field)?>" maxlength="<?=$field==='suffix'?30:100?>" value="<?=e($d[$field])?>"></div><?php endforeach;?>
          <?php foreach (['site'=>'Branch / Site','department'=>'Department','position'=>'Position','brand'=>'Brand / Client','employer'=>'Employer'] as $kind=>$label):
              $optional = ($kind==='brand' && $d['source_sheet']==='HO') || ($kind==='employer' && $d['source_sheet']==='RETAIL');
              if ($optional) continue;
              $choices=[''=>'From Excel: '.$row['assignment'][$kind]['name']];
              foreach ($catalog[$kind] as $key=>$r) if ($r['active']) {
                  $text=$r['name'];
                  if ($kind==='site') $text.=' · '.($r['code'] ?: 'No branch code');
                  if ($kind==='position' && !empty($r['department_id'])) $text.=' · '.($catalog['department'][(int)$r['department_id']]['name'] ?? '');
                  $choices[(string)$key]=$text;
              } ?>
              <div class="field"><label><?=e($label)?></label><?php employee_roster_select('override_'.$kind,$choices,(string)($d['override_'.$kind] ?? ''),$label); ?></div>
          <?php endforeach;?>
          <div class="field"><label>Row action</label><?php employee_roster_select('row_action',['AUTO'=>'Add if new / skip if code already exists','UPDATE'=>'Update existing employee after review','IGNORE'=>'Skip this row'],$row['action'],'Row action'); ?></div>
        </div>
        <?php $other = $d['source_sheet']==='RETAIL' ? 'employer' : 'brand'; $otherLabel = $other==='employer' ? 'Employer' : 'Brand / Client'; $extraChoices=[''=>'Not supplied in this sheet'];
            foreach ($catalog[$other] as $key=>$r) if ($r['active']) $extraChoices[(string)$key]=$r['name']; ?>
        <details class="roster-current" <?=!empty($d['override_'.$other])?'open':''?>><summary>Extra company details (optional)</summary><div class="roster-note"><div class="field"><label><?=e($otherLabel)?></label><?php employee_roster_select('override_'.$other,$extraChoices,(string)($d['override_'.$other] ?? ''),$otherLabel); ?></div></div></details>
        <label class="roster-check"><input type="checkbox" name="assignment_checked" value="1" <?=!empty($d['assignment_checked'])?'checked':''?>> I checked this row's branch and department (needed for a branch employee listed in HO).</label>
        <?php if ($d['formula_fields']):?><label class="roster-check"><input type="checkbox" name="formula_checked" value="1" <?=!empty($d['formula_checked'])?'checked':''?>> I checked the details from formula cells and entered the correct values.</label><?php endif;?>
        <label class="roster-check"><input type="checkbox" name="same_person" value="1" <?=!empty($d['update_checked'])?'checked':''?>> For Update: I confirm this Employee Code belongs to the same person already in HRIS.</label>
        <?php if ($row['existing']): $existing=$row['existing']; $companies=EmployeeRosterImportService::companyDetails($existing); ?>
          <details class="roster-current" open><summary>Existing employee — check before choosing Update</summary><div class="roster-note">
            <strong><?=e($existing['employee_no'].' · '.EmployeeRepository::fullName($existing))?></strong><br>
            Current Start Date: <?=e($existing['hire_date'])?><br>
            Branch: <?=e($catalog['site'][(int)$existing['branch_id']]['name'] ?? 'Not set')?> · Department: <?=e($catalog['department'][(int)$existing['department_id']]['name'] ?? 'Not set')?><br>
            Position: <?=e($catalog['position'][(int)$existing['position_id']]['name'] ?? 'Not set')?><br>
            Brand / Client: <?=e($companies['brand'])?> · Employer: <?=e($companies['employer'])?><br>
            Update uses the fields in this form. It keeps the linked account, contact details, manager, employment type, status and other Employee 201 records.
          </div></details>
        <?php endif;?>
        <details class="roster-current"><summary>Original Excel details</summary><div class="roster-note"><?php $rawLabels=['EMPLOYEECODE'=>'Employee Code','NAME'=>'Name','ADDRESSOFMALL'=>'Mall / Site','DEPARTMENT'=>'Department','POSITION'=>'Position','BRANCHCODE'=>'Branch Code','COMPANY'=>'Company','STARTDATE'=>'Start Date']; foreach ($d['raw'] as $key=>$value):?><div><strong><?=e($rawLabels[$key] ?? $key)?>:</strong> <?=e($value ?: '(blank)')?></div><?php endforeach;?></div></details>
        <div class="roster-form-actions"><button class="btn primary" type="submit">Save row & review</button><a class="btn" href="<?=url('hr-employee-import',['id'=>$id,'step'=>'matches'])?>">Change matches (optional)</a></div>
      </form>
    </section>
    <?php
}
