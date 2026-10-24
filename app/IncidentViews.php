<?php
declare(strict_types=1);
require_once __DIR__.'/TicketViews.php';

/** Incident Reports, employee screens (plan 2026-10-25-incident-report-module, I2). tk- components and tokens only. Every value is escaped here. */

/** My Incidents: cases I filed or was given access to. No role labels of other people, no internal notes. */
function incident_mine_page(array $result,array $summaries):void
{
    ticket_header('incident-mine','My Incidents');
    $uid=TicketRepository::uid();
    page_head('Incident Reports','My Incidents',TicketTypes::canFile('INCIDENT_REPORT')?'<a class="btn primary" href="'.e(url('incident-new')).'">File an incident</a>':'');
    echo '<p class="ticket-note">Incident reports are confidential. You see only the reports you filed and the reports someone shared with you.</p>';
    if(!$result['rows']){
        echo ticket_empty('No incident reports yet.',TicketTypes::canFile('INCIDENT_REPORT')?'Use File an incident to report something that happened.':'Reports shared with you will appear here.');
    }else{
        echo '<ul class="tk-case-list" aria-label="Incident reports">';
        foreach($result['rows'] as $t){
            $payload=json_decode((string)$t['payload_json'],true)?:[];$s=$summaries[(int)$t['id']]??[];$mine=(int)$t['requester_user_id']===$uid;
            echo '<li class="tk-card tk-case"><div class="tk-case-main"><a class="tk-case-title" href="'.e(url('hr-request',['id'=>$t['id']])).'">'.e((string)($payload['title']??$t['ticket_no'])).'</a>',
                '<p class="tk-case-meta">'.e($t['ticket_no']).(!empty($s['category_label'])?' · '.e($s['category_label']):'').(!empty($s['incident_at'])?' · happened '.ticket_time($s['incident_at']):'').'</p>',
                '<p class="tk-case-meta">Last update '.ticket_time((string)($t['updated_at']??$t['created_at'])).'</p></div>',
                '<div class="tk-case-side">'.ticket_status_chip((string)$t['status'],'INCIDENT_REPORT').'<span class="tk-chip is-gray">'.($mine?'You reported this':'Shared with you').'</span></div></li>';
        }
        echo '</ul>';
    }
    if($result['page']>1||$result['has_next']){
        echo '<nav aria-label="Incident report pages"><p>Page '.(int)$result['page'].' ';
        if($result['page']>1)echo '<a class="btn" href="'.e(url('incident-mine',['p'=>$result['page']-1])).'">Previous</a> ';
        if($result['has_next'])echo '<a class="btn" href="'.e(url('incident-mine',['p'=>$result['page']+1])).'">Next</a>';
        echo '</p></nav>';
    }
    render_portal_footer();
}

/** File an Incident: one page, Submit only. $people maps user id => name for people picked before an error. */
function incident_new_page(?array $employee,string $key,array $old,array $people):void
{
    ticket_header('incident-new','File an Incident');
    page_head('Incident Reports','File an Incident','<a class="btn" href="'.e(url('incident-mine')).'">My Incidents</a>');
    if(!$employee){
        echo '<section class="tk-card" role="alert"><h2>We cannot file this for you yet</h2><p>An incident report is filed under your employee record, and we could not find exactly one active Employee 201 record linked to your account. Please ask HR to link your account, then try again.</p><a class="btn" href="'.e(url('incident-mine')).'">Back to My Incidents</a></section>';
        render_portal_footer();return;
    }
    $cats=TicketIncident::CATEGORIES;$val=fn(string $k)=>(string)($old[$k]??'');
    ?>
    <p class="tk-confidential" role="note"><strong>Confidential.</strong> Only you, the HR reviewers handling this report, any CC you choose and anyone HR adds can read it. Please keep the details to the report.</p>
    <form method="post" enctype="multipart/form-data" class="tk-card tk-form" id="incident-form"><?=csrf_field()?><input type="hidden" name="action" value="core_incident_create"><input type="hidden" name="submission_key" value="<?=e($key)?>">
      <h2>What happened</h2>
      <div class="field"><label for="inc-title">Short title</label><input id="inc-title" name="title" type="text" maxlength="200" required value="<?=e($val('title'))?>"></div>
      <div class="field"><label for="inc-category">Category</label><select id="inc-category" name="category" required aria-describedby="inc-category-help"><option value="">Choose a category</option><?php foreach($cats as $label):?><option <?=$val('category')===$label?'selected':''?>><?=e($label)?></option><?php endforeach;?></select><small id="inc-category-help">Helps HR sort the report. If you choose Other, please explain in at least <?=TicketIncident::OTHER_MIN_CHARS?> characters in the next box.</small></div>
      <div class="field"><label for="inc-explanation">Describe what happened</label><textarea id="inc-explanation" name="explanation" rows="6" maxlength="5000" required><?=e($val('explanation'))?></textarea></div>
      <div class="field"><label for="inc-at">When did it happen</label><input id="inc-at" name="incident_at" type="datetime-local" max="<?=e(date('Y-m-d\TH:i'))?>" required value="<?=e($val('incident_at'))?>"></div>
      <div class="field"><label for="inc-location">Where did it happen</label><input id="inc-location" name="location" type="text" maxlength="200" required value="<?=e($val('location'))?>"></div>
      <div class="field"><label for="inc-branch">Branch (optional)</label><select id="inc-branch" name="branch_id"><option value="">Not a specific branch</option><?php foreach(FoundationRepository::branches() as $b):?><option value="<?=(int)$b['id']?>" <?=$val('branch_id')===(string)$b['id']?'selected':''?>><?=e($b['name'])?></option><?php endforeach;?></select></div>

      <fieldset class="tk-picker" data-incident-picker data-endpoint="<?=e(url('incident-people'))?>">
        <legend>People on this report (optional)</legend>
        <p class="tk-hint" id="people-help">A <strong>CC</strong> can read this report and its updates. Someone you mark <strong>Involved</strong> or <strong>Witness</strong> is recorded for HR, but cannot read the report unless HR adds them. Up to <?=TicketIncident::MAX_PARTICIPANTS?> people.</p>
        <div class="field"><label for="people-q">Find a person by name</label><input id="people-q" type="search" autocomplete="off" aria-describedby="people-help people-status"></div>
        <p id="people-status" class="tk-hint" role="status" aria-live="polite"></p>
        <ul class="tk-results" id="people-results" aria-label="Search results"></ul>
        <ul class="tk-people" id="people-list" aria-label="People added to this report">
          <?php $n=0;foreach((array)($old['participants']??[]) as $p):if(!is_array($p)||!isset($people[(int)($p['user_id']??0)]))continue;$role=(string)($p['role']??'CC');?>
          <li class="tk-person"><span class="tk-person-name"><?=e($people[(int)$p['user_id']])?></span><input type="hidden" name="participants[<?=$n?>][user_id]" value="<?=(int)$p['user_id']?>"><label class="tk-sr" for="role-<?=$n?>">Role of <?=e($people[(int)$p['user_id']])?></label><select id="role-<?=$n?>" name="participants[<?=$n?>][role]"><?php foreach(TicketIncident::ROLES as $code=>$label):?><option value="<?=e($code)?>" <?=$role===$code?'selected':''?>><?=e($label)?></option><?php endforeach;?></select><button type="button" class="btn sm" data-remove aria-label="Remove <?=e($people[(int)$p['user_id']])?>">Remove</button></li>
          <?php $n++;endforeach;?>
        </ul>
        <noscript><p class="tk-hint">Adding people needs JavaScript. You can file the report without it.</p></noscript>
      </fieldset>

      <?php ticket_upload_input('incident-attachments');?>
      <div class="ticket-actions"><button class="btn primary" type="submit" name="intent" value="SUBMIT">Submit incident report</button><a class="btn" href="<?=e(url('incident-mine'))?>">Cancel</a></div>
    </form>
    <script>
    (()=>{const root=document.querySelector('[data-incident-picker]');if(!root)return;
      const q=root.querySelector('#people-q'),results=root.querySelector('#people-results'),list=root.querySelector('#people-list'),status=root.querySelector('#people-status');
      const roles=<?=json_encode(TicketIncident::ROLES,JSON_HEX_TAG|JSON_HEX_AMP)?>,max=<?=TicketIncident::MAX_PARTICIPANTS?>;
      let index=list.children.length,timer=null,seq=0;
      const chosen=()=>new Set([...list.querySelectorAll('input[type=hidden]')].map(i=>i.value));
      const el=(tag,props={},kids=[])=>{const n=document.createElement(tag);Object.assign(n,props);kids.forEach(k=>n.append(k));return n;};
      function add(p){
        if(chosen().has(String(p.id))||list.children.length>=max){status.textContent=list.children.length>=max?'You have added the most people allowed.':p.name+' is already added.';return;}
        const i=index++;
        const sel=el('select',{id:'role-'+i,name:'participants['+i+'][role]'},Object.entries(roles).map(([c,l])=>el('option',{value:c,textContent:l})));
        const li=el('li',{className:'tk-person'},[el('span',{className:'tk-person-name',textContent:p.name}),el('input',{type:'hidden',name:'participants['+i+'][user_id]',value:String(p.id)}),
          el('label',{className:'tk-sr',htmlFor:'role-'+i,textContent:'Role of '+p.name}),sel,el('button',{type:'button',className:'btn sm',textContent:'Remove'})]);
        li.querySelector('button').setAttribute('aria-label','Remove '+p.name);
        list.append(li);results.replaceChildren();q.value='';status.textContent=p.name+' added. Choose their role.';sel.focus();
      }
      list.addEventListener('click',e=>{const b=e.target.closest('button');if(b&&b.closest('.tk-person')){const name=b.closest('.tk-person').querySelector('.tk-person-name').textContent;b.closest('.tk-person').remove();status.textContent=name+' removed.';q.focus();}});
      async function search(){
        const term=q.value.trim();const mine=++seq;results.replaceChildren();
        if(term.length<2){status.textContent=term.length?'Type at least 2 letters.':'';return;}
        try{const r=await fetch(root.dataset.endpoint+'&q='+encodeURIComponent(term),{headers:{Accept:'application/json'},credentials:'same-origin'});
          if(!r.ok)throw new Error();const data=await r.json();if(mine!==seq)return;
          const taken=chosen();const people=data.people.filter(p=>!taken.has(String(p.id)));
          status.textContent=people.length?people.length+' found. Choose one to add.':'No one found.';
          people.forEach(p=>{const b=el('button',{type:'button',className:'tk-result-btn'},[el('strong',{textContent:p.name}),el('small',{textContent:p.branch||'No branch'})]);b.addEventListener('click',()=>add(p));results.append(el('li',{className:'tk-result'},[b]));});
        }catch(_){if(mine===seq)status.textContent='Search is unavailable right now. Try again.';}
      }
      q.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(search,250);});
      q.addEventListener('keydown',e=>{if(e.key==='Enter')e.preventDefault();});
    })();
    </script>
    <?php render_portal_footer();
}
