<?php
declare(strict_types=1);
require_once __DIR__.'/TicketViews.php';
require_once __DIR__.'/TicketQueueViews.php';// hq_age(): the queue's aging chip

/**
 * Incident Reports, the case page (plan 2026-10-25-incident-report-module, I3). Built from the shared tk- components and tokens only.
 * Everything private by role is left out of the markup, never hidden with CSS: the role labels other than Reporter and CC, who can read the case,
 * people-change events, internal notes and the HR panel exist only for the HR reviewer handling the case (BR-773, BR-775).
 * Every value is escaped here. The data comes from TicketRepository::detail(), which already filters by role.
 */

/** The five steps of a case. A step a case never went through (Resolved straight from Under review) shows as skipped, never as a number. */
function incident_stepper(array $t):void
{
    $steps=['SUBMITTED'=>'Submitted','UNDER_REVIEW'=>'Under review','IN_PROGRESS'=>'In progress','RESOLVED'=>'Resolved','CLOSED'=>'Closed'];$keys=array_keys($steps);$status=(string)$t['status'];
    $reached=['SUBMITTED'=>$status!=='DRAFT'];
    foreach($t['events'] as $ev){$to=(string)($ev['to_status']??'');if(isset($steps[$to]))$reached[$to]=true;}
    $stopped=in_array($status,['DISMISSED','CANCELLED'],true);$pos=-1;
    if($stopped){foreach($keys as $i=>$k)if(!empty($reached[$k]))$pos=$i;}
    elseif($status!=='DRAFT')$pos=(int)array_search($status,$keys,true);
    $end=TicketTypes::label($status,'INCIDENT_REPORT');
    echo '<nav class="tk-steps-wrap" aria-label="Case progress"><ol class="tk-steps">';
    foreach($keys as $i=>$key){
        if($pos<0)$state='todo';
        elseif($i<$pos)$state=!empty($reached[$key])?'done':'skipped';
        elseif($i===$pos)$state=$stopped?'stop':($status==='CLOSED'?'done':'current');
        else $state='todo';
        $mark=match($state){'done'=>'✓','stop'=>'✕','skipped'=>'–',default=>(string)($i+1)};
        $text=match($state){'done'=>'completed','stop'=>'ended here: '.$end,'skipped'=>'skipped, not needed','current'=>'current step',default=>'not reached yet'};
        echo '<li class="tk-step is-'.$state.'"'.($state==='current'||$state==='stop'?' aria-current="step"':'').'><span class="tk-dot" aria-hidden="true">'.$mark.'</span><span class="tk-step-label">'.e($steps[$key]).'</span><span class="tk-sr"> ('.e($text).')</span></li>';
    }
    echo '</ol></nav>';
}

/** Messages, HR updates and (handler only) internal notes in one chronological list. */
function incident_thread_items(array $t,bool $handler):array
{
    $items=[];
    foreach($t['comments'] as $c){
        $note=($c['visibility']??'ALL')==='STAFF_ONLY';if($note&&!$handler)continue;// defence in depth: detail() already drops these
        $items[]=['kind'=>$note?'note':'msg','at'=>(string)$c['created_at'],'seq'=>(int)$c['id'],'by'=>(int)$c['author_user_id'],'name'=>(string)$c['author_name'],'body'=>(string)$c['body']];
    }
    foreach($t['responses'] as $r)$items[]=['kind'=>'update','at'=>(string)$r['at'],'seq'=>(int)$r['event_id'],'name'=>(string)$r['actor_name'],'to'=>(string)$r['to_status'],'body'=>(string)$r['remarks']];
    if($handler)foreach($t['hr_notes'] as $n)$items[]=['kind'=>'note','at'=>(string)$n['created_at'],'seq'=>(int)$n['id'],'by'=>(int)$n['author_user_id'],'name'=>(string)$n['author_name'],'body'=>(string)$n['note']];
    usort($items,fn($a,$b)=>[strtotime($a['at']),$a['seq']]<=>[strtotime($b['at']),$b['seq']]);
    return $items;
}

function incident_thread(array $t,bool $handler,int $uid):void
{
    $labels=[];$onCase=[];foreach($t['participants'] as $p){$onCase[$p['user_id']]=true;if($p['label']!==null)$labels[$p['user_id']]=$p['label'];}
    $items=incident_thread_items($t,$handler);
    echo '<section class="tk-card" aria-labelledby="inc-thread-h"><h2 id="inc-thread-h">Conversation</h2>';
    if(!$items){echo ticket_empty('No messages yet.',TicketIncident::mayComment($t)?'Write the first message below. Everyone on this case can read it.':'');echo '</section>';return;}
    echo '<ol class="tk-thread" role="log" aria-label="Conversation" aria-live="off">';
    foreach($items as $it){
        if($it['kind']==='update'){
            echo '<li class="tk-post-update"><section class="tk-response" aria-label="HR update"><p class="tk-response-by"><strong>HR update</strong>'.($it['name']!==''?' · '.e($it['name']):'').' · '.ticket_time($it['at']).' · '.ticket_status_chip($it['to'],'INCIDENT_REPORT').'</p><p class="ticket-text tk-response-text">'.e($it['body']).'</p></section></li>';
        }elseif($it['kind']==='note'){
            echo '<li class="tk-post-update"><section class="tk-internal" aria-label="Internal note, HR only"><p class="tk-response-by"><strong>Internal note</strong> <span class="badge amber">HR only</span> · '.e($it['name']).' · '.ticket_time($it['at']).'</p><p class="ticket-text">'.e($it['body']).'</p></section></li>';
        }else{
            $mine=$it['by']===$uid;$chip=$labels[$it['by']]??(isset($onCase[$it['by']])?'':'HR');// a person whose role is not shown to this reader gets no chip, never "HR"
            echo '<li class="tk-post'.($mine?' is-mine':'').'">'.ticket_avatar($it['name']).'<div class="tk-post-body"><p class="tk-post-by"><strong>'.e($mine?'You':$it['name']).'</strong> '.($chip!==''?'<span class="tk-chip is-gray">'.e($chip).'</span> ':'').'<small>'.ticket_time($it['at']).'</small></p><p class="ticket-text">'.e($it['body']).'</p></div></li>';
        }
    }
    echo '</ol></section>';
}

/** Sticky composer, or the reason there is none. */
function incident_composer(array $t,array $p,bool $handler):void
{
    if(!$p['comment']){echo '<p class="tk-card tk-closed" role="note">This case is closed to new messages.'.($handler?' You can still add an internal note.':'').'</p>';return;}
    $old=(string)($_SESSION['core_ticket_comment'][$t['id']]??'');unset($_SESSION['core_ticket_comment'][$t['id']]);// shown once after a failed send
    echo '<form method="post" action="'.e(url('incident-act')).'" class="tk-composer tk-sticky"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="core_incident_message"><input type="hidden" name="ticket_id" value="'.(int)$t['id'].'">',
        '<div class="field"><label for="inc-message">Write a message</label><textarea id="inc-message" name="body" rows="2" maxlength="3000" required aria-describedby="inc-message-help">'.e($old).'</textarea><small id="inc-message-help" class="tk-hint">Everyone on this case can read your message.</small></div>',
        '<button class="btn primary" type="submit">Send message</button></form>';
}

function incident_people_rail(array $t,bool $handler,int $uid):void
{
    echo '<section class="tk-card" aria-labelledby="inc-people-h"><h2 id="inc-people-h">People on this case</h2><ul class="tk-rail-people">';
    foreach($t['participants'] as $p){
        $you=$p['user_id']===$uid;$chip=$p['label']!==null?'<span class="tk-chip '.($p['role']==='REPORTER'?'is-blue':'is-gray').'">'.e($p['label']).'</span>':'';
        echo '<li class="tk-rail-person">'.ticket_avatar($p['name']).'<div class="tk-rail-name"><span class="tk-person-name">'.e($p['name']).($you?' (you)':'').'</span> '.$chip;
        if($handler&&isset($p['has_access']))echo '<small class="tk-hint">'.($p['has_access']?($p['added_by_hr']?'Included by HR; can read this case':'Can read this case'):'Recorded for HR only; cannot read this case').'</small>';
        echo '</div>';
        if($handler&&$p['role']!=='REPORTER')echo '<form method="post" action="'.e(url('incident-act')).'" class="tk-inline"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="core_incident_participant_remove"><input type="hidden" name="ticket_id" value="'.(int)$t['id'].'"><input type="hidden" name="user_id" value="'.(int)$p['user_id'].'"><button class="btn sm" type="submit" aria-label="Remove '.e($p['name']).' from this case" onclick="return confirm(\'Remove this person from the case? They will no longer be able to read it.\')">Remove</button></form>';
        echo '</li>';
    }
    echo '</ul></section>';
}

function incident_summary(array $t):void
{
    $pl=$t['payload'];$at=(string)($pl['incident_at']??'');
    echo '<section class="tk-card" aria-labelledby="inc-summary-h"><h2 id="inc-summary-h">Case summary</h2>';
    echo ticket_kv(['When it happened'=>['html'=>$at!==''?ticket_time($at):'Not recorded'],'Where'=>(string)($pl['location_text']??$pl['location']??'Not recorded'),'Branch'=>(string)($pl['branch_name']??'')!==''?(string)$pl['branch_name']:'Not a specific branch','Category'=>(string)($pl['category_label']??$pl['category']??'Not recorded'),'Filed'=>['html'=>$t['filed_at']?ticket_time((string)$t['filed_at']):'Not filed yet']]);
    echo '<h3 class="tk-sub">What happened</h3><p class="ticket-text">'.e((string)($pl['explanation']??'')).'</p></section>';
}

function incident_attachments(array $t,array $p):void
{
    echo '<section class="tk-card" aria-labelledby="inc-files-h"><h2 id="inc-files-h">Attachments</h2>';
    if(!$t['attachments'])echo ticket_empty('No attachments.');
    else{echo '<ul class="tk-files">';foreach($t['attachments'] as $f)echo '<li><a href="'.e(url('hr-request-attachment',['id'=>$f['id']])).'">'.e($f['original_name']).'</a> <small class="tk-hint">'.e(number_format($f['file_size']/1024,1)).' KB</small></li>';echo '</ul>';}
    if($p['attach'])echo '<form method="post" enctype="multipart/form-data" class="tk-attach"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="core_ticket_attach"><input type="hidden" name="ticket_id" value="'.(int)$t['id'].'">';
    if($p['attach']){ticket_upload_input('inc-attach');echo '<button class="btn" type="submit">Add attachments</button></form>';}
    echo '</section>';
}

/** Readable event list. Names are shown as on messages (everyone who reads a case sees the names on it); remarks live in the conversation, not here. People-change events reach this list for the handler only. */
function incident_timeline(array $t,bool $handler):void
{
    $names=[];$ids=[];
    if($handler)foreach($t['events'] as $ev){$d=json_decode((string)($ev['details_json']?:'{}'),true);if(!empty($d['user_id']))$ids[]=(int)$d['user_id'];}
    $ids=array_values(array_unique($ids));
    if($ids){$q=db()->prepare('SELECT id,full_name FROM users WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);$names=array_column($q->fetchAll(),'full_name','id');}
    $rows=[];$uid=TicketRepository::uid();
    foreach(array_reverse($t['events']) as $ev){
        $type=(string)$ev['event_type'];$d=json_decode((string)($ev['details_json']?:'{}'),true)?:[];$tone='gray';
        $label=match($type){
            'PUBLISHED','SUBMITTED'=>'Report filed','EDITED'=>'Report edited','ATTACHMENT_ADDED'=>'Attachment added',
            'TRANSITION'=>'Status changed to '.TicketTypes::label((string)$ev['to_status'],'INCIDENT_REPORT'),
            'PARTICIPANT_ADDED'=>'Person included'.(isset($names[$d['user_id']??0])?': '.$names[$d['user_id']]:'').(!empty($d['role'])&&isset(TicketIncident::ROLES[$d['role']])?' ('.TicketIncident::ROLES[$d['role']].')':''),
            'PARTICIPANT_REMOVED'=>'Person removed'.(isset($names[$d['user_id']??0])?': '.$names[$d['user_id']]:''),
            default=>null};
        if($label===null||($type==='PARTICIPANT_ADDED'||$type==='PARTICIPANT_REMOVED')&&!$handler)continue;
        if($type==='TRANSITION')$tone=ticket_tone((string)$ev['to_status']);
        $actor=(int)$ev['actor_user_id']===$uid?'You':(string)$ev['actor_name'];
        $rows[]=['label'=>$label,'actor'=>$actor,'at'=>(string)$ev['created_at'],'tone'=>$tone];
    }
    echo '<section class="tk-card" aria-labelledby="inc-tl-h"><h2 id="inc-tl-h">Timeline</h2>';
    if(!$rows)echo ticket_empty('Nothing has happened yet.');
    else{echo '<ol class="tk-timeline">';foreach($rows as $r)echo '<li class="tk-tl-item is-'.e($r['tone']).'">'.ticket_avatar($r['actor']).'<div class="tk-tl-body"><p class="tk-tl-name">'.e($r['label']).'</p><p class="tk-tl-when">'.e($r['actor']).' · '.ticket_time($r['at']).'</p></div></li>';echo '</ol>';}
    echo '</section>';
}

/** Handling HR reviewer only: status with remarks, include or remove people, internal note. Not rendered for anyone else. */
function incident_hr_panel(array $t,array $p):void
{
    $id=(int)$t['id'];$csrf='<input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="ticket_id" value="'.$id.'">';$act=e(url('incident-act'));$old=$_SESSION['core_incident_status'][$id]??[];unset($_SESSION['core_incident_status'][$id]);$oldNote=(string)($_SESSION['core_incident_note'][$id]??'');unset($_SESSION['core_incident_note'][$id]);// shown once after a failed save
    echo '<aside class="tk-card tk-hr-panel" aria-labelledby="inc-hr-h"><h2 id="inc-hr-h">HR actions <span class="badge amber">HR only</span></h2>';
    // 1. status
    echo '<details class="tk-hr-block" id="inc-status-panel"'.($old?' open':'').'><summary class="tk-sub">Update status</summary>';
    if(!$p['status'])echo '<p class="tk-hint">This case is final. No further status change is possible.</p>';
    else{
        echo '<form method="post" action="'.$act.'">'.$csrf.'<input type="hidden" name="action" value="core_incident_status"><input type="hidden" name="version" value="'.(int)$t['version'].'">',
            '<div class="field"><label for="inc-to">New status</label><select id="inc-to" name="to" required>';
        foreach($p['status'] as $to){$label=TicketTypes::label($to,'INCIDENT_REPORT');if($to==='IN_PROGRESS'&&in_array($t['status'],['RESOLVED','CLOSED'],true))$label='Reopen: '.$label;echo '<option value="'.e($to).'"'.(($old['to']??'')===$to?' selected':'').'>'.e($label).'</option>';}
        echo '</select></div>',
            '<div class="field tk-field-public"><label for="inc-remarks">Remarks <span class="badge blue">Everyone on the case can read this</span></label><textarea id="inc-remarks" name="remarks" rows="3" maxlength="5000" required>'.e((string)($old['remarks']??'')).'</textarea><small>Required for every change. A dismissal needs the reason.</small></div>',
            '<div class="field tk-field-internal"><label for="inc-status-note">Internal note <span class="badge amber">HR only</span></label><textarea id="inc-status-note" name="internal_note" rows="2" maxlength="2000">'.e((string)($old['internal_note']??'')).'</textarea><small>Optional. Never shown to anyone else.</small></div>',
            '<button class="btn primary" type="submit">Save status</button></form>';
    }
    echo '</details>';
    // 2. people
    $endpoint=e(url('incident-people',['case'=>$id]));
    echo '<div class="tk-hr-block"><h3 class="tk-sub">Include someone</h3>';
    if(!$p['participants'])echo '<p class="tk-hint">People cannot be changed on this case.</p>';
    else{
        echo '<form method="post" action="'.$act.'" data-hr-picker data-endpoint="'.$endpoint.'">'.$csrf.'<input type="hidden" name="action" value="core_incident_participant_add"><input type="hidden" name="user_id" value="" id="inc-add-user">',
            '<p class="tk-hint" id="inc-add-help">A person you include can read this case and its conversation. Choose Involved or Witness to give them access too.</p>',
            '<div class="field"><label for="inc-add-q">Find a person by name</label><input id="inc-add-q" type="search" autocomplete="off" aria-describedby="inc-add-help inc-add-status"></div>',
            '<p id="inc-add-status" class="tk-hint" role="status" aria-live="polite"></p><ul class="tk-results" id="inc-add-results" aria-label="Search results"></ul>',
            '<p class="tk-chosen" id="inc-add-chosen" hidden></p>',
            '<div class="field"><label for="inc-add-role">Role on this case</label><select id="inc-add-role" name="role">';
        foreach(TicketIncident::ROLES as $code=>$label)echo '<option value="'.e($code).'">'.e($label).'</option>';
        echo '</select></div><button class="btn" type="submit" id="inc-add-go" disabled>Include in case</button></form>';
        ?>
<script>
(()=>{const f=document.querySelector('[data-hr-picker]');if(!f)return;
  const q=f.querySelector('#inc-add-q'),res=f.querySelector('#inc-add-results'),st=f.querySelector('#inc-add-status'),uid=f.querySelector('#inc-add-user'),chosen=f.querySelector('#inc-add-chosen'),go=f.querySelector('#inc-add-go');
  let timer=null,seq=0;
  const el=(tag,props={},kids=[])=>{const n=document.createElement(tag);Object.assign(n,props);kids.forEach(k=>n.append(k));return n;};
  async function search(){const term=q.value.trim(),mine=++seq;res.replaceChildren();
    if(term.length<2){st.textContent=term.length?'Type at least 2 letters.':'';return;}
    try{const r=await fetch(f.dataset.endpoint+'&q='+encodeURIComponent(term),{headers:{Accept:'application/json'},credentials:'same-origin'});if(!r.ok)throw new Error();const data=await r.json();if(mine!==seq)return;
      st.textContent=data.people.length?data.people.length+' found. Choose one.':'No one found.';
      data.people.forEach(p=>{const b=el('button',{type:'button',className:'tk-result-btn'},[el('strong',{textContent:p.name}),el('small',{textContent:p.branch||'No branch'})]);
        b.addEventListener('click',()=>{uid.value=String(p.id);chosen.textContent='Selected: '+p.name;chosen.hidden=false;go.disabled=false;res.replaceChildren();q.value='';st.textContent=p.name+' selected. Choose a role, then include them.';f.querySelector('#inc-add-role').focus();});
        res.append(el('li',{className:'tk-result'},[b]));});
    }catch(_){if(mine===seq)st.textContent='Search is unavailable right now. Try again.';}}
  q.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(search,250);});
  q.addEventListener('keydown',e=>{if(e.key==='Enter')e.preventDefault();});
})();
</script>
        <?php
    }
    echo '</div>';
    // 3. internal note
    echo '<div class="tk-hr-block"><h3 class="tk-sub">Internal note</h3><form method="post" action="'.$act.'">'.$csrf.'<input type="hidden" name="action" value="core_incident_note">',
        '<div class="field tk-field-internal"><label for="inc-note">Note <span class="badge amber">HR only</span></label><textarea id="inc-note" name="body" rows="3" maxlength="3000" required>'.e($oldNote).'</textarea><small>Only HR reviewers handling this case can read it. It is not shown in the conversation to anyone else.</small></div>',
        '<button class="btn" type="submit">Save internal note</button></form></div>';
    echo '</aside>';
}

/** The reporter may withdraw a report while it is still Submitted. */
function incident_withdraw_card(array $t):void
{
    echo '<section class="tk-card" aria-labelledby="inc-withdraw-h"><h2 id="inc-withdraw-h">Withdraw this report</h2><p class="tk-hint">You can withdraw a report until HR starts reviewing it. This cannot be undone.</p>',
        '<form method="post" action="'.e(url('incident-act')).'"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="core_incident_status"><input type="hidden" name="ticket_id" value="'.(int)$t['id'].'"><input type="hidden" name="version" value="'.(int)$t['version'].'"><input type="hidden" name="to" value="CANCELLED">',
        '<div class="field"><label for="inc-withdraw-why">Reason (optional)</label><textarea id="inc-withdraw-why" name="remarks" rows="2" maxlength="5000"></textarea></div>',
        '<button class="btn" type="submit" onclick="return confirm(\'Withdraw this report? This cannot be undone.\')">Withdraw report</button></form></section>';
}

/**
 * Query-string parameters that describe a queue view, defaults left out (Open tab, page 1, no filter), so links stay short and equal views give equal links.
 * $f is TicketIncident::queueFilters() output; $override replaces single keys (a tab link passes ['status'=>..,'p'=>1]).
 * @return array<string,string|int>
 */
function incident_queue_params(array $f,array $override=[]):array
{
    $f=array_replace($f,$override);$out=[];
    if(($f['status']??'open')!=='open'&&isset(TicketIncident::QUEUE_TABS[$f['status']]))$out['status']=(string)$f['status'];
    foreach(['category','from','to','q'] as $k)if((string)($f[$k]??'')!=='')$out[$k]=(string)$f[$k];
    if((int)($f['branch_id']??0)>0)$out['branch_id']=(int)$f['branch_id'];
    if((int)($f['p']??1)>1)$out['p']=(int)$f['p'];
    return $out;
}

/** The queue view the reader came from, read from the "back" query string. Never a URL: it is parsed and rebuilt through the same whitelist the queue itself uses, so nothing outside the queue can be reached. */
function incident_queue_back():array
{
    $raw=$_GET['back']??'';if(!is_string($raw)||$raw===''||strlen($raw)>500)return [];
    parse_str($raw,$in);return incident_queue_params(TicketIncident::queueFilters(is_array($in)?$in:[]));
}

/**
 * HR Incident Cases queue (I4): status tabs with counts, search and filters, one row per case. Reuses the HR Request Queue look (hq- classes in hr-ops.css) so both queues read the same.
 * $result is TicketRepository::listing('feed'), $counts is TicketRepository::incidentQueueCounts() (tab counts follow the filters, not the tab).
 */
function incident_queue_page(array $result,array $counts):void
{
    $f=$result['filters'];$tab=(string)$f['status'];
    $tabLabel=static fn(string $k):string=>match($k){'open'=>'Open','all'=>'All',default=>TicketTypes::label(strtoupper($k),'INCIDENT_REPORT')};
    $self=static fn(array $o=[])=>url(TicketIncident::QUEUE_PAGE,incident_queue_params($f,$o));
    $filtered=$f['category']!==''||$f['branch_id']>0||$f['from']!==''||$f['to']!==''||$f['q']!=='';
    ticket_header('hr-incidents','Incident Cases');
    echo '<link rel="stylesheet" href="public/assets/hr-ops.css?v=20261025-1">';
    page_head('Incident Reports','Incident Cases');
    echo '<p class="tk-confidential" role="note"><strong>Confidential.</strong> Every case here is private to the people on it and to HR.</p>';
    echo '<nav class="hq-tabs" aria-label="Case status">';
    foreach(TicketIncident::QUEUE_TABS as $key=>$_){$on=$tab===$key;echo '<a class="hq-tab'.($on?' is-active':'').'" href="'.e($self(['status'=>$key,'p'=>1])).'"'.($on?' aria-current="page"':'').'>'.e($tabLabel($key)).' <span class="hq-count">'.(int)($counts[$key]??0).'</span></a>';}
    echo '</nav>';
    $branches=FoundationRepository::branches();
    echo '<form method="get" class="hq-toolbar tk-iq-bar" role="search" action="index.php"><input type="hidden" name="page" value="'.e(TicketIncident::QUEUE_PAGE).'">'.($tab!=='open'?'<input type="hidden" name="status" value="'.e($tab).'">':''),
        '<div class="hq-search"><label class="hq-sr" for="iq-q">Search by case number, title or reporter</label>'.icon_svg('search').'<input id="iq-q" type="search" name="q" value="'.e($f['q']).'" placeholder="Search case no., title or reporter" maxlength="80"></div>',
        '<div class="field"><label for="iq-category">Category</label><select id="iq-category" name="category"><option value="">All categories</option>';
    foreach(TicketIncident::CATEGORIES as $code=>$label)echo '<option value="'.e($code).'"'.($f['category']===$code?' selected':'').'>'.e($label).'</option>';
    echo '</select></div><div class="field"><label for="iq-branch">Where it happened</label><select id="iq-branch" name="branch_id"><option value="0">All branches</option>';
    foreach($branches as $b)echo '<option value="'.(int)$b['id'].'"'.($f['branch_id']===(int)$b['id']?' selected':'').'>'.e($b['name']).'</option>';
    echo '</select></div>',
        '<div class="field"><label for="iq-from">Filed from</label><input id="iq-from" type="date" name="from" value="'.e($f['from']).'"></div>',
        '<div class="field"><label for="iq-to">Filed to</label><input id="iq-to" type="date" name="to" value="'.e($f['to']).'"></div>',
        '<button class="btn primary" type="submit">Apply</button>'.($filtered?'<a class="btn" href="'.e(url(TicketIncident::QUEUE_PAGE,$tab==='open'?[]:['status'=>$tab])).'">Clear filters</a>':''),
        '</form>';
    $back=http_build_query(incident_queue_params($f));
    echo '<section class="panel hq-card"><table class="tbl hq-table tk-iq-table"><caption class="hq-sr">Incident cases, '.e(strtolower($tabLabel($tab))).'</caption>',
        '<thead><tr><th>Case</th><th>Category</th><th>Reporter</th><th>Where</th><th>Filed · Age</th><th>Status</th><th><span class="hq-sr">Open</span></th></tr></thead><tbody>';
    foreach($result['rows'] as $r){
        $link=e(url('hr-request',['id'=>(int)$r['id']]+($back!==''?['back'=>$back]:[])));
        $title=(string)$r['title']!==''?(string)$r['title']:'Untitled report';
        echo '<tr><td data-label="Case"><div><a class="tk-iq-title" href="'.$link.'">'.e($title).'</a><span class="hq-mono tk-iq-no">'.e($r['ticket_no']).'</span></div></td>',
            '<td data-label="Category">'.($r['category_label']!==null?e($r['category_label']):'<span class="hq-muted">Not recorded</span>').'</td>',
            '<td data-label="Reporter">'.e((string)($r['reporter_name']??'')!==''?(string)$r['reporter_name']:trim($r['first_name'].' '.$r['last_name'])).'</td>',
            '<td data-label="Where">'.e((string)($r['case_branch_name']??'')).'</td>',
            '<td data-label="Filed · Age">'.e(substr((string)$r['filed'],0,10)).' '.hq_age($r['age_days'],(string)$r['age_tone']).'</td>',
            '<td data-label="Status">'.ticket_status_chip((string)$r['status'],'INCIDENT_REPORT').'</td>',
            '<td data-label="Open" class="hq-actions"><a class="btn sm" href="'.$link.'" aria-label="Open case '.e($r['ticket_no']).'">Open</a></td></tr>';
    }
    if(!$result['rows']){
        $all=(int)($counts['all']??0);
        if($filtered)[$head,$hint]=['No cases match these filters.','Try removing a filter or searching for something else.'];
        elseif($tab==='all'||$all===0)[$head,$hint]=['No incident reports have been filed yet.','Reports appear here as soon as an employee files one.'];
        else[$head,$hint]=['No cases in '.strtolower($tabLabel($tab)).'.','Cases show here when they reach this status.'];
        echo '<tr class="hq-empty-row"><td colspan="7"><div class="hq-empty">'.icon_svg('clipboard').'<strong>'.e($head).'</strong><span>'.e($hint).'</span>';
        if($filtered)echo '<a class="btn" href="'.e(url(TicketIncident::QUEUE_PAGE,$tab==='open'?[]:['status'=>$tab])).'">Clear filters</a>';
        elseif($tab!=='all'&&$all>0)echo '<a class="btn" href="'.e($self(['status'=>'all','p'=>1])).'">Show all cases</a>';
        echo '</div></td></tr>';
    }
    echo '</tbody></table></section>';
    echo '<nav class="hq-pager" aria-label="Incident case pages"><span>Page '.(int)$result['page'].'</span>';
    if($result['page']>1)echo '<a class="btn sm" href="'.e($self(['p'=>$result['page']-1])).'">Previous</a>';
    if($result['has_next'])echo '<a class="btn sm" href="'.e($self(['p'=>$result['page']+1])).'">Next</a>';
    echo '</nav>';
    render_portal_footer();
}

/** The original report is stricter than conversation access: reporter and eligible handling HR only. */
function incident_document_card(array $t,bool $handler):void
{
    if(!IncidentDocument::canRead($t))return;
    $doc=IncidentDocument::info($t);$id=(int)$t['id'];
    echo '<section class="tk-card tk-document" aria-labelledby="inc-document-h"><h2 id="inc-document-h">Original incident report</h2>';
    if(!$doc){
        echo '<p class="tk-hint">This case has no generated official document. Reports filed before the document workflow keep their original case details.</p></section>';return;
    }
    echo '<p class="tk-hint">Submitted answers are saved in the original template. Only the reporter and eligible handling HR can access this document.</p>',
        '<div class="ticket-actions"><a class="btn" href="'.e(url('incident-document',['id'=>$id,'format'=>'docx'])).'">Download original (.docx)</a>';
    if($doc['pdf_status']==='ready')echo '<a class="btn" href="'.e(url('incident-document',['id'=>$id,'format'=>'pdf'])).'">Open PDF preview</a>';
    echo '</div>';
    if($doc['pdf_status']==='ready'){
        echo '<iframe class="tk-document-preview" title="Original incident report PDF preview" src="'.e(url('incident-document',['id'=>$id,'format'=>'pdf'])).'" loading="lazy"></iframe>';
    }else{
        echo '<p class="tk-hint" role="status">The PDF preview is unavailable. Your report and original Word document are saved; you can download the document above.</p>';
        if($handler)echo '<form method="post" action="'.e(url('incident-act')).'">'.csrf_field().'<input type="hidden" name="action" value="core_incident_pdf_retry"><input type="hidden" name="ticket_id" value="'.$id.'"><input type="hidden" name="version" value="'.(int)$t['version'].'"><button class="btn" type="submit">Retry PDF preview</button></form>';
    }
    echo '</section>';
}

/** Header and progress, original document, then conversation + composer beside the existing HR/people/details rail. */
function incident_case_page(array $t):void
{
    $uid=TicketRepository::uid();$handler=TicketIncident::isHandler($t);$p=TicketIncident::permissions($t);$own=(int)$t['requester_user_id']===$uid;
    $title=(string)($t['payload']['title']??$t['ticket_no']);
    $backTo=$handler?url(TicketIncident::QUEUE_PAGE,incident_queue_back()):url('incident-mine');// the queue view the handler came from (same tab, filters, page), else the plain queue
    ticket_header($handler?'hr-incidents':'incident-mine',$t['ticket_no']);
    page_head('Incident Reports',$title,'<a class="btn" href="'.e($backTo).'">'.($handler?'Back to Incident Cases':'My Incidents').'</a>');
    echo '<p class="tk-confidential" role="note"><strong>Confidential.</strong> Only the people listed on this page can read this case.</p>';
    echo '<section class="tk-head" aria-label="Case summary"><div class="tk-head-main"><span class="tk-eyebrow">Incident report</span><p class="tk-no">'.e($t['ticket_no']).'</p>',
        '<dl class="tk-dates"><div><dt>Filed</dt><dd>'.($t['filed_at']?ticket_time((string)$t['filed_at']):'Not filed yet').'</dd></div><div><dt>Last update</dt><dd>'.ticket_time((string)($t['updated_at']??$t['created_at'])).'</dd></div></dl></div>',
        '<span class="tk-pill is-'.e(ticket_tone((string)$t['status'])).'"><span class="tk-sr">Status: </span>'.e(TicketTypes::label((string)$t['status'],'INCIDENT_REPORT')).'</span></section>';
    incident_stepper($t);
    if($handler&&$p['status'])echo '<p class="tk-status-action"><a class="btn primary" href="#inc-status-panel" data-incident-status aria-controls="inc-status-panel">Update status</a></p>';
    elseif(TicketRepository::isHr()&&!$handler)echo '<p class="tk-hint" role="note">You are the reporter or a participant on this case, including CC. Another eligible HR reviewer must handle its status.</p>';
    echo '<div class="tk-case-grid"><div class="tk-case-col">';
    incident_document_card($t,$handler);
    incident_thread($t,$handler,$uid);
    incident_composer($t,$p,$handler);
    echo '</div><div class="tk-case-rail">';
    if($handler)incident_hr_panel($t,$p);
    incident_people_rail($t,$handler,$uid);
    incident_summary($t);
    incident_attachments($t,$p);
    incident_timeline($t,$handler);
    if($own&&$p['cancel'])incident_withdraw_card($t);
    echo '</div></div>';
    if($handler&&$p['status'])echo '<script>document.querySelector("[data-incident-status]")?.addEventListener("click",()=>{const panel=document.getElementById("inc-status-panel");panel.open=true;panel.querySelector("select")?.focus();});</script>';
    render_portal_footer();
}
