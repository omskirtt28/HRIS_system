<?php
declare(strict_types=1);

/** rec-endorse-manager (SPEC-rec-endorse-manager.md): the HR "Endorse to Manager review" card and the reviewer's own pages. */

/** [css class, label] for a reviewer's decision; $waiting is used while there is none. */
function review_decision_badge(?string $decision,array $waiting):array
{
    return match($decision){'PROCEED'=>['green','Proceed'],'NOT_SUITABLE'=>['red','Not suitable'],'ANOTHER_INTERVIEW'=>['amber','Another interview'],default=>$waiting};
}

/** HR applicant page: pick one or more Manager / ADL / SC / TC users, plus who was endorsed and what they answered. */
function applicant_reviewer_card(array $a):string
{
    $answers=RecruitmentRepository::reviewersFor((int)$a['id']);
    $o='';
    $done=RecruitmentRepository::finalDone((int)$a['id']);
    if($done&&Auth::can('recruitment.manage')){
        $o.='<form method="post" class="card pad" style="margin-bottom:16px;border-left:4px solid var(--green,#2a9d5c)">'.csrf_field()
            .'<input type="hidden" name="action" value="move_stage"><input type="hidden" name="application_id" value="'.(int)$a['id'].'"><input type="hidden" name="target_stage" value="OFFER"><input type="hidden" name="comment" value="Final interview done"><input type="hidden" name="return_page" value="hr-applicant"><input type="hidden" name="return_id" value="'.(int)$a['id'].'">'
            .'<div class="section-t">Final interview done, next: Offer</div><div class="small">'.e($done['full_name']).' answered Proceed'.($done['decision_comment']?': '.e($done['decision_comment']):'').'.</div>'
            .'<div class="tiny muted" style="margin:6px 0 12px">Nothing moves by itself. You decide when to start the offer.</div><button class="btn primary">Move to Offer →</button></form>';
    }
    $canEndorse=Auth::can('recruitment.manage')&&in_array($a['stage_code'],['INTERVIEW','ENDORSED','CLIENT_REVIEW','RETURNED'],true);
    if($canEndorse){
        $choices=RecruitmentRepository::reviewerChoices();
        $pre=RecruitmentRepository::defaultReviewers((int)$a['id']);
        $endorsed=array_column($answers,'reviewer_user_id');
        $o.='<form method="post" class="card pad" style="margin-bottom:16px">'.csrf_field()
            .'<input type="hidden" name="action" value="endorse"><input type="hidden" name="application_id" value="'.(int)$a['id'].'"><input type="hidden" name="return_page" value="hr-applicant"><input type="hidden" name="return_id" value="'.(int)$a['id'].'">'
            .'<div class="section-t">Endorse to Manager review</div>';
        if(!$choices)$o.='<p class="tiny muted">No active Manager, ADL, SC or TC accounts were found.</p>';
        else{
            $o.='<div class="field"><label for="rv-filter">Find a person</label><input id="rv-filter" type="search" autocomplete="off" placeholder="Type a name" aria-controls="rv-list"></div>'
                .'<div id="rv-list" role="group" aria-label="People to endorse to" style="max-height:220px;overflow:auto;margin-bottom:12px">';
            foreach($choices as $c){
                $on=in_array((int)$c['id'],$pre,true);
                $o.='<div class="srow rv-item" data-name="'.e(strtolower((string)$c['full_name'])).'"><label style="flex:1;display:flex;align-items:center;cursor:pointer"><input type="checkbox" name="reviewers[]" value="'.(int)$c['id'].'"'.($on?' checked':'').'> <span class="small" style="flex:1;margin-left:8px">'.e($c['full_name']).'</span><span class="tiny muted">'.e($c['role_name']).(in_array((int)$c['id'],$endorsed,true)?' · already endorsed':'').($on?' · requester':'').'</span></label><label class="tiny" style="margin-left:12px;cursor:pointer" title="The person who does the final interview"><input type="checkbox" name="final[]" value="'.(int)$c['id'].'"> Final</label></div>';
            }
            $o.='</div><div class="tiny muted" id="rv-empty" hidden>No one matches.</div>'
                .'<div class="field"><label for="rv-note">Endorsement note</label><textarea id="rv-note" name="note" rows="2" maxlength="2000" placeholder="Short summary for the reviewers"></textarea></div>'
                .'<button class="btn primary">Endorse →</button>'
                .'<script>(function(){var f=document.getElementById("rv-filter"),items=document.querySelectorAll(".rv-item"),none=document.getElementById("rv-empty");if(!f)return;f.addEventListener("input",function(){var q=f.value.trim().toLowerCase(),n=0;items.forEach(function(i){var s=i.dataset.name.indexOf(q)>-1;i.hidden=!s;if(s)n++});none.hidden=n>0})})();</script>';
        }
        $o.='</form>';
    }
    if($answers){
        $o.='<div class="card pad" style="margin-bottom:16px"><div class="section-t">Manager review</div>';
        foreach($answers as $r){
            $badge=review_decision_badge($r['decision'],['gray','Waiting']);
            $o.='<div class="srow"><div style="flex:1"><div class="small" style="font-weight:600">'.e($r['full_name']).' <span class="tiny muted">'.e($r['role_name']).'</span>'.((int)$r['is_final']===1?' <span class="badge blue">Final interviewer</span>':'').($r['passed_by_name']?' <span class="tiny muted">· passed by '.e($r['passed_by_name']).'</span>':'').'</div>'
                .($r['decision_comment']?'<div class="tiny muted">'.e($r['decision_comment']).'</div>':'').'</div><span class="badge '.$badge[0].'">'.$badge[1].'</span></div>';
        }
        $o.='<div class="tiny muted" style="margin-top:8px">HR can continue the candidate at any time, even if no one has answered.</div></div>';
    }
    return $o;
}

/** The reviewer's own list. */
function review_candidates_list(array $rows):string
{
    if(!$rows)return '<div class="card pad"><p class="muted">Nothing has been endorsed to you yet.</p></div>';
    $o='<div class="card" style="overflow-x:auto"><table class="tbl"><thead><tr><th>Candidate</th><th>Position</th><th>Endorsed</th><th>Stage</th><th>Your answer</th><th></th></tr></thead><tbody>';
    foreach($rows as $r){
        $badge=review_decision_badge($r['decision'],['blue','Waiting for you']);
        $o.='<tr><td>'.e($r['first_name'].' '.$r['last_name']).'</td><td>'.e($r['job_title']).'<div class="tiny muted">'.e((string)$r['location_text']).'</div></td><td>'.e(date('M j, Y',strtotime((string)$r['endorsed_at']))).'</td><td>'.stage_badge((string)$r['stage_code']).'</td><td><span class="badge '.$badge[0].'">'.$badge[1].'</span></td><td><a class="btn sm" href="'.e(url('review-candidate',['id'=>$r['id']])).'">Open</a></td></tr>';
    }
    return $o.'</tbody></table></div>';
}

/** The reviewer's candidate page: facts, resume, interviews, decision and schedule forms. No email, phone or other applicants. */
function review_candidate_detail(array $a):string
{
    $id=(int)$a['id'];$name=$a['first_name'].' '.$a['last_name'];
    $hidden=csrf_field().'<input type="hidden" name="application_id" value="'.$id.'"><input type="hidden" name="return_page" value="review-candidate"><input type="hidden" name="return_id" value="'.$id.'">';
    $o='<div class="split3"><div><div class="card pad" style="margin-bottom:16px"><div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap"><span class="avatar" style="width:52px;height:52px;font-size:18px">'.e(initials($name)).'</span><div><h2 style="font-size:20px">'.e($name).'</h2><div class="muted small">Applied for <strong>'.e($a['job_title']).'</strong>'.($a['location_text']?' · '.e((string)$a['location_text']):'').'</div></div><div style="margin-left:auto">'.stage_badge((string)$a['stage_code']).'</div></div>'
        .'<div class="small muted" style="margin-top:14px"><strong>Endorsed by '.e((string)$a['endorsed_by_name']).' on '.e(date('M j, Y',strtotime((string)$a['endorsed_at']))).':</strong><br>'.nl2br(e($a['note']?:'No note.')).'</div>'
        .'<div class="small muted" style="margin-top:14px"><strong>Screening answer:</strong><br>'.nl2br(e($a['why_fit']?:'No answer provided.')).'</div></div>';
    $o.='<div class="card pad" style="margin-bottom:16px"><div class="section-t">Resume</div>';
    if(!$a['resume'])$o.='<div class="tiny muted">No resume uploaded.</div>';
    else{
        $o.='<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px"><strong>'.e($a['resume']['original_name']).'</strong><span class="tiny muted">'.e(number_format((int)$a['resume']['file_size']/1024,0)).' KB</span><span style="margin-left:auto;display:flex;gap:8px"><a class="btn sm" target="_blank" rel="noopener" href="'.e(url('review-resume',['id'=>$id])).'">Open in new tab</a><a class="btn sm" href="'.e(url('review-resume',['id'=>$id,'download'=>1])).'">Download</a></span></div>';
        $o.=$a['resume']['mime_type']==='application/pdf'?'<iframe title="Resume of '.e($name).'" src="'.e(url('review-resume',['id'=>$id])).'" style="width:100%;height:560px;border:1px solid var(--border);border-radius:8px"></iframe>':'<div class="tiny muted">Word documents cannot be previewed here. Use Download.</div>';
    }
    $o.='</div><div class="card pad"><div class="section-t">Interviews</div>';
    if(!$a['interviews'])$o.='<div class="tiny muted">No interviews yet.</div>';
    foreach($a['interviews'] as $i)$o.='<div class="srow"><div style="flex:1"><div class="small" style="font-weight:600">'.e($i['interview_type']).'</div><div class="tiny muted">'.e(date('M j, Y g:i A',strtotime((string)$i['scheduled_at']))).' · '.e($i['location_or_link']?:'TBD').($i['interviewer_name']?' · '.e($i['interviewer_name']):'').'</div></div><span class="badge blue">'.e($i['status']).'</span></div>';
    $o.='</div></div><div>';
    $hint='<div class="tiny muted" style="margin-bottom:10px">'.((int)$a['is_final']===1?'You are the final interviewer. Your Proceed tells HR the final interview is done.':'Your Proceed is recorded as your opinion. Pass the candidate to the next interviewer below, or HR continues.').'</div>';
    $mine=$a['decision']?'<div class="small" style="margin-bottom:10px">Your answer: <strong>'.e(RecruitmentRepository::REVIEW_DECISIONS[$a['decision']]??$a['decision']).'</strong>'.($a['decision_comment']?' - '.e($a['decision_comment']):'').'</div>':'';
    if($a['decision']==='PROCEED')$o.='<div class="card pad" style="margin-bottom:16px"><div class="section-t">Your decision <span class="badge green">Closed</span></div>'.$mine.'<div class="tiny muted">'.((int)$a['is_final']===1?'HR was told the final interview is done.':'Your Proceed is recorded. HR continues from here.').'</div></div>';
    else $o.='<form method="post" class="card pad" style="margin-bottom:16px">'.$hidden.'<input type="hidden" name="action" value="review_decide"><div class="section-t">Your decision'.((int)$a['is_final']===1?' <span class="badge blue">Final interviewer</span>':'').'</div>'.$hint.$mine
        .'<div class="field"><label for="rv-comment">Comment</label><textarea id="rv-comment" name="comment" rows="3" maxlength="2000" placeholder="Required for Not suitable"></textarea></div>'
        .'<div class="actions-inline"><button name="decision" value="PROCEED" class="btn primary">Proceed</button><button name="decision" value="ANOTHER_INTERVIEW" class="btn">Needs another interview</button><button name="decision" value="NOT_SUITABLE" class="btn">Not suitable</button></div></form>';
    if($a['decision']!=='PROCEED')$o.='<details class="card pad"><summary style="font-weight:600;cursor:pointer">Schedule an interview</summary><form method="post" style="margin-top:14px">'.$hidden.'<input type="hidden" name="action" value="review_schedule">'
        .'<div class="field"><label for="rv-type">Interview type</label><select id="rv-type" name="interview_type"><option>Initial Interview</option><option>Mid Interview</option><option>Final Interview</option></select></div>'
        .'<div class="field"><label for="rv-when">Date &amp; time</label><input id="rv-when" type="datetime-local" name="scheduled_at" required></div>'
        .'<div class="field"><label for="rv-where">Location or meeting link</label><input id="rv-where" name="location_or_link" maxlength="255"><div class="tiny muted">The applicant is emailed the date, time and this link.</div></div>'
        .'<div class="field"><label for="rv-notes">Notes</label><textarea id="rv-notes" name="notes" rows="2"></textarea></div><button class="btn primary">Schedule interview</button></form></details>';
    if($a['stage_code']==='CLIENT_REVIEW'&&$a['decision']!=='PROCEED'){
        $me=(int)(Auth::user()['id']??0);$have=array_map('intval',array_column(RecruitmentRepository::reviewersFor($id),'reviewer_user_id'));
        $o.='<form method="post" class="card pad" style="margin-top:16px">'.$hidden.'<input type="hidden" name="action" value="review_pass_on"><div class="section-t">Pass to next interviewer</div>';
        $any=false;
        foreach(RecruitmentRepository::reviewerChoices() as $c){
            if((int)$c['id']===$me||in_array((int)$c['id'],$have,true))continue;$any=true;
            $o.='<div class="srow"><label style="flex:1;display:flex;align-items:center;cursor:pointer"><input type="checkbox" name="to[]" value="'.(int)$c['id'].'"> <span class="small" style="flex:1;margin-left:8px">'.e($c['full_name']).'</span><span class="tiny muted">'.e($c['role_name']).'</span></label><label class="tiny" style="margin-left:12px;cursor:pointer"><input type="checkbox" name="final[]" value="'.(int)$c['id'].'"> Final</label></div>';
        }
        if(!$any)$o.='<div class="tiny muted">There is no one else to pass the candidate to.</div></form>';
        else $o.='<div class="field" style="margin-top:10px"><label for="rv-pass-note">Note</label><textarea id="rv-pass-note" name="note" rows="2" maxlength="2000" placeholder="Short note for the next interviewer"></textarea></div><button class="btn primary">Pass candidate</button></form>';
    }
    return $o.'</div></div>';
}
