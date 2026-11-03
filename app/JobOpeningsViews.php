<?php
declare(strict_types=1);

/** HR > Recruitment > Job Openings (rec-jobs): list with filters, and one plain form (no script) for create and edit. Output is escaped; POSTs go to index.php. */
function job_openings_view(array $rows,string $filter,string $search,bool $canManage,?array $form,array $clients,array $departments):void
{
    $tabs=['all'=>'All','draft'=>'Draft','published'=>'Published','closed'=>'Closed'];
    echo '<link rel="stylesheet" href="public/assets/hr-ops.css?v=20261102-mi1">';
    if($form!==null)job_openings_form($form,$clients,$departments);
    echo '<nav class="hq-tabs" aria-label="Job filter" style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">';
    foreach($tabs as $key=>$label)echo '<a class="btn sm'.($filter===$key?' primary':'').'" href="'.e(url('hr-jobs',array_filter(['filter'=>$key==='all'?'':$key,'q'=>$search]))).'"'.($filter===$key?' aria-current="page"':'').'>'.e($label).'</a>';
    echo '<form method="get" style="margin-left:auto;display:flex;gap:8px"><input type="hidden" name="page" value="hr-jobs"><input type="hidden" name="filter" value="'.e($filter==='all'?'':$filter).'">'
        .'<label class="sr-only" for="job-search" style="position:absolute;left:-9999px">Search jobs</label><input id="job-search" name="q" value="'.e($search).'" placeholder="Search title or code" maxlength="80"><button class="btn sm">Search</button></form></nav>';
    $badge=fn(string $s)=>match($s){'PUBLISHED'=>['green','Published'],'EXPIRED'=>['amber','Expired'],'CLOSED'=>['gray','Closed'],default=>['blue','Draft']};
    echo '<div class="card" style="overflow-x:auto"><table class="tbl"><thead><tr><th>Code</th><th>Job</th><th>Employer</th><th>Type</th><th>Openings</th><th>Applications</th><th>Status</th><th>Closes</th>'.($canManage?'<th>Actions</th>':'').'</tr></thead><tbody>';
    foreach($rows as $r){
        $status=JobOpenings::displayStatus($r);[$cls,$label]=$badge($status);
        echo '<tr><td class="mono tiny">'.e($r['job_code']).'</td><td><strong>'.e($r['title']).'</strong>'.($r['location_text']?'<div class="tiny muted">'.e($r['location_text']).'</div>':'').($r['is_featured']?' <span class="badge amber">Featured</span>':'').'</td>'
            .'<td>'.e($r['employer']).'<div class="tiny muted">Shown to the public as PMBSI</div></td><td>'.e(stage_label($r['employment_type'])).'</td><td>'.(int)$r['openings'].'</td><td>'.(int)$r['applications'].'</td>'
            .'<td><span class="badge '.$cls.'">'.e($label).'</span></td><td>'.e($r['closes_at']?date('M j, Y',strtotime((string)$r['closes_at'])):'-').'</td>';
        if($canManage){
            $post=fn(string $action,string $text,string $cls='')=>'<form method="post" action="'.e(url('hr-jobs')).'" style="display:inline">'.csrf_field().'<input type="hidden" name="action" value="'.$action.'"><input type="hidden" name="job_id" value="'.(int)$r['id'].'"><button class="btn sm'.$cls.'" aria-label="'.$text.' '.e($r['title']).'">'.$text.'</button></form> ';
            echo '<td style="white-space:nowrap">';
            if($r['status']!=='CLOSED')echo '<a class="btn sm" href="'.e(url('hr-jobs',['edit'=>(int)$r['id']])).'" aria-label="Edit '.e($r['title']).'">Edit</a> ';
            if($r['status']==='DRAFT')echo $post('publish_job','Publish',' primary');
            if($r['status']==='PUBLISHED')echo $post('unpublish_job','Unpublish');
            if($r['status']!=='CLOSED')echo $post('close_job','Close');
            else echo $post('reopen_job','Reopen');
            echo '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
    if(!$rows)echo '<div class="empty">'.($search!==''||$filter!=='all'?'No jobs match this filter.':'No job openings yet. Use + New job, or Post job on an approved manpower request.').'</div>';
    echo '</div>';
}

function job_openings_form(array $f,array $clients,array $departments):void
{
    $token=bin2hex(random_bytes(16));$_SESSION['job_form_tokens']=array_slice([$token=>1]+($_SESSION['job_form_tokens']??[]),0,10,true);
    $id=(int)($f['job_id']??0);$fromRequest=(int)($f['manpower_request_id']??0)>0;$v=fn(string $k)=>e((string)($f[$k]??''));
    echo '<form class="card pad" method="post" action="'.e(url('hr-jobs')).'" style="margin-bottom:18px">'.csrf_field().'<input type="hidden" name="action" value="save_job"><input type="hidden" name="form_token" value="'.$token.'"><input type="hidden" name="job_id" value="'.$id.'"><input type="hidden" name="manpower_request_id" value="'.($fromRequest?(int)$f['manpower_request_id']:'').'">'
        .'<h2 style="margin:0 0 4px;font-size:17px">'.($id?'Edit job':($fromRequest?'Post job from manpower request':'New job')).'</h2><p class="tiny muted" style="margin:0 0 14px">The public sees the employer as PMBSI. Nothing goes live until you publish.</p>';
    echo '<div class="split"><div class="field"><label for="jf-title">Job title</label><input id="jf-title" name="title" required maxlength="180" value="'.$v('title').'"></div>'
        .'<div class="field"><label for="jf-loc">Location</label><input id="jf-loc" name="location_text" maxlength="180" value="'.$v('location_text').'"></div></div><div class="split">'
        .'<div class="field"><label for="jf-type">Employment type</label><select id="jf-type" name="employment_type">';
    $cur=(string)($f['employment_type']??'REGULAR');if($cur==='FULL_TIME')$cur='REGULAR'; // older jobs saved before the new list
    foreach(JobOpenings::TYPES as $k=>$l)echo '<option value="'.$k.'"'.($cur===$k?' selected':'').'>'.e($l).'</option>';
    echo '</select></div></div>';
    if(RecruitmentRepository::screeningReady()){ // decides who may rate the applicants (rec-screening-score)
        $site=(string)($f['site_type']??'');
        echo '<div class="field"><label for="jf-site">Site type</label><select id="jf-site" name="site_type"><option value="">Use the request branch</option>';
        foreach(['RETAIL'=>'Retail','HEAD_OFFICE'=>'Head office','OTHER'=>'Other company'] as $k=>$l)echo '<option value="'.$k.'"'.($site===$k?' selected':'').'>'.e($l).'</option>';
        echo '</select><div class="tiny muted">Decides who may rate the applicants. Other company: HR only.</div></div>';
        if(RecruitmentRepository::branchScopeReady()){ // Retail: which areas and branches may review this job's applicants
            $on=[];if($id>0){$q=db()->prepare('SELECT branch_id FROM job_opening_branches WHERE job_opening_id=?');$q->execute([$id]);$on=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));}
            $byArea=[];foreach(FoundationRepository::branches() as $b){if(!(int)$b['active']||($b['site_type']??'')==='HEAD_OFFICE')continue;$byArea[(string)($b['area_name']??'No area')][]=$b;}
            // Shown only for Retail (hidden + disabled otherwise, so no branch is posted). Every area is open at once; no inner scroll.
            $isRetail=$site==='RETAIL';
            echo '<style>.jb[hidden]{display:none}.jb{border:0;padding:0;min-width:0}.jb-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0}.jb-bar input[type=search]{flex:1 1 200px;min-width:0}.jb-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px}.jb-area{border:1px solid var(--border,#e5e7eb);border-radius:10px;padding:10px;background:var(--panel-2,transparent)}.jb-area[hidden]{display:none}.jb-head{display:flex;align-items:center;gap:8px;margin-bottom:8px}.jb-head strong{flex:1}.jb-n{font-size:12px;color:var(--muted,#6b7280)}.jb-link{background:none;border:0;padding:2px 4px;font-size:12px;color:var(--hris-brand,#b87900);cursor:pointer;text-decoration:underline}.jb-chips{display:flex;flex-wrap:wrap;gap:6px}.jb-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;min-height:36px;border:1px solid var(--border,#d1d5db);border-radius:999px;font-size:13px;cursor:pointer}.jb-chip[hidden]{display:none}.jb-chip:has(input:checked){border-color:var(--hris-brand,#f5a800);background:color-mix(in srgb,var(--hris-brand,#f5a800) 18%,transparent);font-weight:600}.jb-chip:has(input:focus-visible){outline:2px solid var(--hris-brand,#f5a800);outline-offset:2px}</style>';
            echo '<fieldset class="field jb" id="jf-branches"'.($isRetail?'':' hidden disabled').'><legend>Retail area and branches</legend><div class="tiny muted">Applicants are passed to the area\'s ADL, then to the OIC, MT and SC of these branches. Until you choose at least one, only HR can pass Retail applicants on.</div>'
                .'<div class="jb-bar"><input type="search" id="jb-q" placeholder="Filter branches" aria-label="Filter branches" autocomplete="off"><button type="button" class="btn" data-jb="all">Select all</button><button type="button" class="btn" data-jb="none">Clear all</button><span class="tiny muted" id="jb-count" aria-live="polite"></span></div><div class="jb-grid">';
            foreach($byArea as $area=>$bs){
                echo '<section class="jb-area"><div class="jb-head"><strong>'.e($area).'</strong><span class="jb-n"></span><button type="button" class="jb-link" data-jb="all">All</button><button type="button" class="jb-link" data-jb="none">Clear</button></div><div class="jb-chips">';
                foreach($bs as $b)echo '<label class="jb-chip" data-name="'.e(strtolower((string)$b['name'])).'"><input type="checkbox" name="branch_ids[]" value="'.(int)$b['id'].'"'.(in_array((int)$b['id'],$on,true)?' checked':'').'><span>'.e($b['name']).'</span></label>';
                echo '</div></section>';
            }
            echo '</div></fieldset><script>(function(){var f=document.getElementById("jf-branches"),s=document.getElementById("jf-site"),q=document.getElementById("jb-q");if(!f||!s)return;'
                .'function count(){var t=0;f.querySelectorAll(".jb-area").forEach(function(a){var c=a.querySelectorAll("input:checked").length;a.querySelector(".jb-n").textContent=c+" / "+a.querySelectorAll("input").length;t+=c});document.getElementById("jb-count").textContent=t?t+" selected":"None selected"}'
                .'function sync(){var on=s.value==="RETAIL";f.hidden=!on;f.disabled=!on}'
                .'s.addEventListener("change",sync);f.addEventListener("change",count);'
                .'f.addEventListener("click",function(e){var b=e.target.closest("[data-jb]");if(!b)return;var v=b.dataset.jb==="all";(b.closest(".jb-area")||f).querySelectorAll(".jb-chip:not([hidden]) input").forEach(function(i){i.checked=v});count()});'
                .'q.addEventListener("input",function(){var t=q.value.trim().toLowerCase();f.querySelectorAll(".jb-area").forEach(function(a){var n=0;a.querySelectorAll(".jb-chip").forEach(function(c){var m=c.dataset.name.indexOf(t)>-1;c.hidden=!m;if(m)n++});a.hidden=!n})});'
                .'sync();count()})();</script>';
        }
    }
    echo '<div class="split"><div class="field"><label for="jf-open">Openings</label><input id="jf-open" name="openings" type="number" min="1" max="999" required value="'.e((string)($f['openings']??1)).'"></div>'
        .'<div class="field"><label for="jf-close">Closes on (optional)</label><input id="jf-close" name="closes_on" type="date" value="'.$v('closes_on').'"></div></div>'
        .'<div class="split"><div class="field"><label for="jf-min">Salary minimum (optional)</label><input id="jf-min" name="salary_min" type="number" min="0" step="0.01" value="'.$v('salary_min').'"></div>'
        .'<div class="field"><label for="jf-max">Salary maximum (optional)</label><input id="jf-max" name="salary_max" type="number" min="0" step="0.01" value="'.$v('salary_max').'"></div></div>'
        .'<div class="field"><label for="jf-desc">Job description</label><textarea id="jf-desc" name="description" rows="5" required>'.$v('description').'</textarea></div>'
        .'<div class="field"><label for="jf-req">Requirements (one per line)</label><textarea id="jf-req" name="requirements" rows="4">'.$v('requirements').'</textarea></div>'
        .'<div class="field"><label><input type="checkbox" name="is_featured" value="1"'.(!empty($f['is_featured'])?' checked':'').'> Feature this job on Careers</label></div>'
        .'<div class="hq-dialog-actions" style="justify-content:flex-start"><button class="btn">Save as draft</button>'
        .(($f['status']??'DRAFT')==='DRAFT'?'<button class="btn primary" name="publish_now" value="1">Save and publish</button>':'')
        .'<a class="btn" href="'.e(url('hr-jobs')).'">Cancel</a></div></form>';
}

/** Stage tracker for the HR applicant page: the normal flow (sequence up to Deployed) with done / current marks. Read-only; moving stays with the Move to form. */
function applicant_stage_tracker(array $a,array $stages):string
{
    $flow=array_values(array_filter($stages,fn($s)=>(int)$s['sequence_no']<=80));
    $cur=array_search($a['stage_code'],array_column($flow,'code'),true);
    $o='<ol class="stage-track" aria-label="Recruitment stage">';
    foreach($flow as $i=>$s){
        $cls=$cur===false?'':($i<$cur?' done':($i===$cur?' cur':''));
        $o.='<li class="'.trim($cls).'"'.($i===$cur?' aria-current="step"':'').'><span class="dot"></span><span>'.e($s['name']).'</span></li>';
    }
    $o.='</ol>';
    if($cur===false)$o.='<div class="tiny muted" style="margin-top:6px">Outside the normal flow: '.e($a['stage_name']).'</div>';
    return $o;
}

/** Read-only job details for the HR applicant page, the same facts HR sees on Job Openings. Needs the extra columns that RecruitmentRepository::application() selects. */
function applicant_job_details(array $a):string
{
    $type=(string)$a['job_employment_type'];$type=JobOpenings::TYPES[$type]??stage_label($type);
    $sal=$a['salary_min']!==null&&$a['salary_max']!==null?'₱'.number_format((float)$a['salary_min'],0).'–₱'.number_format((float)$a['salary_max'],0):($a['salary_min']!==null||$a['salary_max']!==null?'₱'.number_format((float)($a['salary_min']??$a['salary_max']),0):'Not set');
    $st=JobOpenings::displayStatus(['status'=>$a['job_status'],'closes_at'=>$a['closes_at']]);
    $badge=match($st){'PUBLISHED'=>['green','Published'],'EXPIRED'=>['amber','Expired'],'CLOSED'=>['gray','Closed'],default=>['blue','Draft']};
    $cell=fn(string $k,string $v)=>'<div><div class="tiny muted">'.$k.'</div><div style="font-weight:600">'.$v.'</div></div>';
    $o='<div class="section-t">Job details</div><div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:12px">'
        .$cell('JOB',e($a['job_title']).' <span class="tiny muted mono">'.e((string)$a['job_code']).'</span>')
        .$cell('STATUS','<span class="badge '.$badge[0].'">'.$badge[1].'</span>')
        .$cell('TYPE',e($type)).$cell('LOCATION',e((string)($a['location_text']?:'Not set')))
        .$cell('OPENINGS',e((string)$a['job_openings'])).$cell('SALARY',e($sal))
        .$cell('CLOSES',e($a['closes_at']?date('M j, Y',strtotime((string)$a['closes_at'])):'No closing date')).'</div>';
    $reqs=array_filter(array_map('trim',preg_split('/\R/',(string)$a['job_requirements'])?:[]));
    $o.='<details><summary style="cursor:pointer;font-weight:600">Description and requirements</summary><p class="muted" style="margin-top:10px">'.nl2br(e((string)$a['job_description'])).'</p>';
    if($reqs)$o.='<ul class="muted" style="padding-left:18px">'.implode('',array_map(fn($r)=>'<li>'.e($r).'</li>',$reqs)).'</ul>';
    return $o.'</details>';
}
