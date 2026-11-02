<?php
declare(strict_types=1);

/** HR > Recruitment > Manpower Requests list with the Approve / Decline dialogs (rec-manpower-intake). Output is escaped; POSTs go to index.php. */
function manpower_intake_view(array $rows,string $filter,array $clients,bool $canDecide,bool $canPost=false,array $jobCounts=[]):void
{
    $tabs=['open'=>'Open','declined'=>'Declined','all'=>'All'];
    $badge=fn(string $s)=>match($s){'AWAITING'=>['amber','Awaiting HR'],'DECLINED'=>['red','Declined'],default=>['blue',stage_label($s)]};
    echo '<link rel="stylesheet" href="public/assets/hr-ops.css?v=20261102-mi1">';
    echo '<nav class="hq-tabs" aria-label="Manpower request filter" style="display:flex;gap:8px;margin-bottom:14px">';
    foreach($tabs as $key=>$label)echo '<a class="btn sm'.($filter===$key?' primary':'').'" href="'.e(url('hr-manpower',$key==='open'?[]:['filter'=>$key])).'"'.($filter===$key?' aria-current="page"':'').'>'.e($label).'</a>';
    echo '</nav><div class="card" style="overflow-x:auto"><table class="tbl"><thead><tr><th>Request</th><th>Source</th><th>Employer</th><th>Position</th><th>Requested</th><th>Filled</th><th>Priority</th><th>Status</th><th>Target</th>'.(($canDecide||$canPost)?'<th>Actions</th>':'').'</tr></thead><tbody>';
    foreach($rows as $r){
        [$cls,$label]=$badge($r['status']);$wait=$r['status']==='AWAITING';
        echo '<tr><td class="mono tiny">'.e($r['request_no']??'Not approved yet').'</td><td>'.e($r['source']).'</td><td>'.e($r['employer']??'-').'</td><td><strong>'.e($r['position_title']).'</strong>'
            .($r['detail']!==''?'<div class="tiny muted">'.e($r['detail']).'</div>':'').($r['requester']?'<div class="tiny muted">Requested by '.e($r['requester']).'</div>':'').'</td>'
            .'<td>'.(int)$r['requested'].'</td><td>'.(int)$r['filled'].' / '.(int)$r['requested'].'</td><td>'.($r['priority']===null?'-':'<span class="badge '.($r['priority']==='URGENT'?'red':'gray').'">'.e($r['priority']).'</span>').'</td>'
            .'<td><span class="badge '.$cls.'">'.e($label).'</span></td><td>'.e($r['target_start_date']?:'-').'</td>';
        if($canDecide||$canPost){
            echo '<td style="white-space:nowrap">';
            if($wait&&$canDecide){
                $d=' data-ticket="'.(int)$r['ticket_id'].'" data-version="'.(int)$r['version'].'" data-title="'.e($r['position_title']).'" data-count="'.(int)$r['requested'].'"';
                echo '<button type="button" class="btn sm primary" data-mi-open="mi-approve"'.$d.'>Approve</button> <button type="button" class="btn sm" data-mi-open="mi-decline"'.$d.'>Decline</button>';
            }
            if($canPost&&$r['id']!==null&&in_array($r['status'],['APPROVED','PARTIALLY_FILLED'],true)){
                $open=(int)($jobCounts[(int)$r['id']]??0);
                echo '<a class="btn sm primary" href="'.e(url('hr-jobs',['request_id'=>(int)$r['id']])).'">Post job</a>'.($open?' <span class="tiny muted">'.$open.' open job'.($open>1?'s':'').'</span>':'');
            }
            echo '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
    if(!$rows)echo '<div class="empty">'.($filter==='declined'?'No declined manpower requests.':'No manpower requests yet. Requests filed by managers and ADLs appear here for HR to approve.').'</div>';
    echo '</div>';
    if(!$canDecide)return;
    $hidden=fn(string $action)=>csrf_field().'<input type="hidden" name="action" value="'.$action.'"><input type="hidden" name="ticket_id" value=""><input type="hidden" name="version" value="">';
    echo '<dialog class="hq-dialog" id="mi-approve" aria-labelledby="mi-approve-title"><form method="post" action="'.e(url('hr-manpower')).'">'.$hidden('approve_manpower')
        .'<h2 id="mi-approve-title">Approve manpower request</h2><p class="tiny muted" data-mi-summary></p>'
        .'<div class="field"><label for="mi-client">Employer</label><select id="mi-client" name="client_id"><option value="">Select an employer</option>';
    foreach($clients as $c)echo '<option value="'.(int)$c['id'].'">'.e($c['name']).'</option>';
    echo '</select></div><div class="field"><label for="mi-new">Or add a new employer</label><input id="mi-new" name="new_employer" maxlength="180" autocomplete="off"></div>'
        .'<div class="field"><label for="mi-title">Position title</label><input id="mi-title" name="position_title" maxlength="160" required></div>'
        .'<div class="field"><label for="mi-priority">Priority</label><select id="mi-priority" name="priority"><option>NORMAL</option><option>URGENT</option></select></div>'
        .'<div class="field"><label for="mi-remarks">Remarks (optional)</label><textarea id="mi-remarks" name="remarks" rows="2" maxlength="500"></textarea></div>'
        .'<div class="hq-dialog-actions"><button type="button" class="btn" data-mi-close>Go back</button><button class="btn primary">Approve</button></div></form></dialog>';
    echo '<dialog class="hq-dialog" id="mi-decline" aria-labelledby="mi-decline-title"><form method="post" action="'.e(url('hr-manpower')).'">'.$hidden('decline_manpower')
        .'<h2 id="mi-decline-title">Decline manpower request</h2><p class="tiny muted" data-mi-summary></p>'
        .'<div class="field"><label for="mi-reason">Reason (the requester can see this)</label><textarea id="mi-reason" name="remarks" rows="3" maxlength="500" required></textarea></div>'
        .'<div class="hq-dialog-actions"><button type="button" class="btn" data-mi-close>Go back</button><button class="btn primary">Decline</button></div></form></dialog>';
    echo '<script>(function(){document.querySelectorAll("[data-mi-open]").forEach(function(b){b.addEventListener("click",function(){var d=document.getElementById(b.dataset.miOpen);if(!d||!d.showModal)return;'
        .'d.querySelector("[name=ticket_id]").value=b.dataset.ticket;d.querySelector("[name=version]").value=b.dataset.version;'
        .'d.querySelector("[data-mi-summary]").textContent=b.dataset.title+" x"+b.dataset.count;var t=d.querySelector("[name=position_title]");if(t)t.value=b.dataset.title;d.showModal();});});'
        .'document.querySelectorAll("[data-mi-close]").forEach(function(b){b.addEventListener("click",function(){b.closest("dialog").close();});});})();</script>';
}
