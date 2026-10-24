<?php
declare(strict_types=1);
// Incident Reports, Case page (I3): what each reader's markup contains, stepper states, HR-only elements absent for everyone else,
// the people search for the handler and escaping. Isolated database only. Synthetic data. The HTTP side (CSRF, 403s, the real forms)
// is covered in a real browser by incident_browser.cjs.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/View.php';
require_once dirname(__DIR__).'/app/TicketService.php';
require_once dirname(__DIR__).'/app/TicketViews.php';
$f=fixtures();$tag=$f['tag'];
function mk(string $tag,string $key,string $name,array $perms,string $portal,array $f,bool $employee=true):array{
    $role=insert('INSERT INTO roles(code,name,portal) VALUES(?,?,?)',[$tag.'_c_'.$key,'Slice15 '.$key,$portal]);
    foreach($perms as $c){$p=row('SELECT id FROM permissions WHERE code=?',[$c]);if($p)db()->prepare('INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)')->execute([$role,$p['id']]);}
    $id=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$role,$name,strtolower($tag.'_c_'.$key).'@example.test',password_hash('x',PASSWORD_DEFAULT)]);
    if($employee)insert('INSERT INTO employees(employee_no,first_name,last_name,user_id,branch_id,legal_entity_id,business_unit_id,status,hire_date) VALUES(?,?,?,?,?,?,?,"ACTIVE",?)',[$tag.'_c_'.$key,'Synthetic',$key,$id,$f['branch'],$f['legal'],$f['company'],'2020-01-01']);
    return ['id'=>$id,'role_id'=>$role,'role_code'=>$tag.'_c_'.$key,'role_portal'=>$portal,'name'=>$name,'role_name'=>'Test '.$key,'email'=>'x@example.test','client_id'=>null];
}
$filePerms=['tickets.request_self','ticket.file.incident'];
$cc=mk($tag,'cc',"Cora CcPerson $tag",$filePerms,'employee',$f);
$inv=mk($tag,'inv',"Ivan InvolvedPerson $tag",$filePerms,'employee',$f);
$wit=mk($tag,'wit',"Wilma WitnessPerson $tag",$filePerms,'employee',$f);
$late=mk($tag,'late',"Lara LatePerson $tag",$filePerms,'employee',$f);
$hr2=mk($tag,'hr2',"Hana HrTwo $tag",['tickets.review_hr'],'hr',$f,false);
$rep=$f['employee'];$hr=$f['hr'];$out=$f['outsider'];
$ir=(int)row('SELECT id FROM ticket_types WHERE code="INCIDENT_REPORT"')['id'];
$ver=fn(int $id)=>(int)row('SELECT version FROM tickets WHERE id=?',[$id])['version'];
$stat=fn(int $id)=>(string)row('SELECT status FROM tickets WHERE id=?',[$id])['status'];
function html(array $user,int $id):string{act($user);ob_start();try{incident_case_page(TicketRepository::detail($id));}finally{$h=ob_get_clean();}return (string)$h;}
function steps(string $h):array{preg_match_all('/<li class="tk-step is-([a-z]+)"/',$h,$m);return $m[1];}
function newcase(array $f,int $ir,array $extra=[]):int{act($f['employee']);return TicketService::create(array_replace(incident_fields('INCIDENT_REPORT'),['ticket_type_id'=>$ir,'submission_key'=>bin2hex(random_bytes(32)),'title'=>'Case page title','explanation'=>'NARRATIVE-MARK about the event','location'=>'PLACE-MARK'],$extra),true);}
$people=[['user_id'=>$cc['id'],'role'=>'CC'],['user_id'=>$inv['id'],'role'=>'INVOLVED'],['user_id'=>$wit['id'],'role'=>'WITNESS']];

$id=newcase($f,$ir,['participants'=>$people]);
// ---------------------------------------------------------------- reporter and CC markup (BR-772, BR-773)
$r=html($rep,$id);
check('test_BR_772 reporter: header with case number, status chip and a five-step stepper',str_contains($r,'tk-head')&&str_contains($r,'Submitted')&&count(steps($r))===5&&steps($r)[0]==='current');
check('test_BR_772 reporter: conversation, composer and case summary are there',str_contains($r,'Conversation')&&str_contains($r,'id="inc-message"')&&str_contains($r,'NARRATIVE-MARK')&&str_contains($r,'PLACE-MARK')&&str_contains($r,'Case summary'));
check('test_BR_773 reporter: names everyone, labels only Reporter and CC',str_contains($r,'Ivan InvolvedPerson')&&str_contains($r,'Wilma WitnessPerson')&&str_contains($r,'>Reporter<')&&str_contains($r,'>CC<')&&!preg_match('/>(Involved|Witness)</',$r));
check('test_BR_773 reporter: no access wording, no remove buttons, no HR panel, no internal notes in the markup',!str_contains($r,'cannot read this case')&&!str_contains($r,'participant_remove')&&!str_contains($r,'HR actions')&&!str_contains($r,'core_incident_status"><input type="hidden" name="version"')&&!str_contains($r,'tk-internal')&&!str_contains($r,'core_incident_note')&&!str_contains($r,'inc-add-q'));
check('test_BR_771 reporter may withdraw while Submitted',str_contains($r,'Withdraw this report')&&str_contains($r,'name="to" value="CANCELLED"'));
check('test_BR_773 reporter: people-change events are not in the timeline',!str_contains($r,'Person included'));
$c=html($cc,$id);
check('test_BR_772 CC: reads the case, can message, cannot withdraw or use HR tools',str_contains($c,'NARRATIVE-MARK')&&str_contains($c,'id="inc-message"')&&!str_contains($c,'Withdraw this report')&&!str_contains($c,'HR actions')&&!preg_match('/>(Involved|Witness)</',$c));
check('test_BR_773 CC: sees the same names, with Reporter and CC chips only',str_contains($c,'Ivan InvolvedPerson')&&str_contains($c,'>Reporter<')&&str_contains($c,'>CC<'));
check('test_BR_769 a person named Involved or Witness has no access to the page',rejects(fn()=>html($inv,$id))&&rejects(fn()=>html($wit,$id))&&rejects(fn()=>html($out,$id)));

// ---------------------------------------------------------------- handler markup
$h=html($hr,$id);
check('test_BR_775 handler: HR panel, role labels, access wording and remove buttons',str_contains($h,'HR actions')&&preg_match('/>Involved</',$h)&&preg_match('/>Witness</',$h)&&str_contains($h,'Recorded for HR only; cannot read this case')&&str_contains($h,'Can read this case')&&substr_count($h,'core_incident_participant_remove')===3);
check('test_BR_771 handler: offers Under review and Dismissed from Submitted, never Cancelled; remarks required',str_contains($h,'value="UNDER_REVIEW"')&&str_contains($h,'value="DISMISSED"')&&!str_contains($h,'value="CANCELLED"')&&preg_match('/id="inc-remarks"[^>]*required/',$h)&&!str_contains($h,'Withdraw this report'));
check('test_BR_770 handler: has the people search wired to this case',str_contains($h,'incident-people&amp;case='.$id)||str_contains($h,'case='.$id));

// ---------------------------------------------------------------- HR update and internal note (BR-772)
act($hr);TicketService::incidentStatus($id,$ver($id),'UNDER_REVIEW','UPDATE-MARK we are looking into it','INTERNAL-MARK-A');
TicketService::comment($id,'INTERNAL-MARK-B','STAFF_ONLY');
act($hr);TicketService::comment($id,'HRMSG-MARK hello','ALL');
act($rep);TicketService::comment($id,'<b>REPMSG-MARK</b> & more','ALL');
act($cc);TicketService::comment($id,'CCMSG-MARK','ALL');
$r=html($rep,$id);$c=html($cc,$id);$h=html($hr,$id);
check('test_BR_772 reporter and CC read the HR update as an "HR update" card with the status chip',str_contains($r,'UPDATE-MARK')&&str_contains($c,'UPDATE-MARK')&&str_contains($r,'aria-label="HR update"')&&str_contains($r,'Under Review'));
check('test_BR_772 the internal notes appear in the handler markup only',str_contains($h,'INTERNAL-MARK-A')&&str_contains($h,'INTERNAL-MARK-B')&&str_contains($h,'tk-internal')&&!str_contains($r,'INTERNAL-MARK')&&!str_contains($c,'INTERNAL-MARK')&&!str_contains($r,'tk-internal')&&!str_contains($c,'tk-internal'));
check('test_BR_773 the HR author is named on the update, as on messages, for every reader',str_contains($h,'HR update</strong> · '.$hr['name'])&&str_contains($r,'HR update</strong> · '.$hr['name'])&&str_contains($c,'HR update</strong> · '.$hr['name']));
check('test_BR_772 messages: author names, Reporter/CC/HR chips, "You" for your own, and HTML in a message is escaped',str_contains($r,'&lt;b&gt;REPMSG-MARK&lt;/b&gt; &amp; more')&&!str_contains($r,'<b>REPMSG-MARK')&&str_contains($r,'<strong>You</strong>')&&str_contains($c,'HRMSG-MARK')&&str_contains($c,'>HR<')&&str_contains($c,'CCMSG-MARK'));
check('test_BR_772 messages read oldest first',strpos($r,'HRMSG-MARK')<strpos($r,'REPMSG-MARK')&&strpos($r,'REPMSG-MARK')<strpos($r,'CCMSG-MARK'));
check('test_BR_771 the reporter can no longer withdraw once HR has started (Under review)',!str_contains($r,'Withdraw this report'));
check('test_BR_773 timeline: readable status line with the actor\'s name, no raw status code, no remarks in it',str_contains($r,'Status changed to Under Review')&&str_contains($r,$hr['name'].' · ')&&!str_contains($r,'UNDER_REVIEW')&&!preg_match('/tk-tl-body[^<]*<p[^>]*>[^<]*<\/p><p[^>]*>[^<]*UPDATE-MARK/',$r));

// ---------------------------------------------------------------- stepper states (BR-771)
check('test_BR_771 stepper: Under review is current, the first step done',steps($r)===['done','current','todo','todo','todo']);
act($hr);TicketService::incidentStatus($id,$ver($id),'RESOLVED','Resolved straight away');
$r=html($rep,$id);check('test_BR_771 stepper: a step the case skipped (In progress) shows as skipped, not a number',steps($r)===['done','done','skipped','current','todo']);
act($hr);TicketService::incidentStatus($id,$ver($id),'CLOSED','Closing');
$r=html($rep,$id);check('test_BR_771 stepper: Closed is all done and the composer is replaced by the closed notice',steps($r)===['done','done','skipped','done','done']&&str_contains($r,'closed to new messages')&&!str_contains($r,'id="inc-message"'));
$h=html($hr,$id);check('test_BR_772 closed case: the handler is told they can still add an internal note and the form is there; reopen is offered',str_contains($h,'You can still add an internal note')&&str_contains($h,'id="inc-note"')&&str_contains($h,'Reopen: In Progress'));
act($hr);TicketService::incidentStatus($id,$ver($id),'IN_PROGRESS','Reopened for follow-up');
$r=html($rep,$id);check('test_BR_771 reopened: In progress is current again, Resolved and Closed go back to not reached',steps($r)===['done','done','current','todo','todo']&&str_contains($r,'id="inc-message"'));

$id2=newcase($f,$ir,['title'=>'Dismiss me']);act($hr);TicketService::incidentStatus($id2,$ver($id2),'DISMISSED','Not an incident');
$r=html($rep,$id2);check('test_BR_771 dismissed: the stepper stops at the step it reached and the pill says Dismissed',steps($r)===['stop','todo','todo','todo','todo']&&str_contains($r,'ended here: Dismissed')&&str_contains($r,'is-red')&&str_contains($r,'closed to new messages'));
$h=html($hr,$id2);check('test_BR_771 a final case shows the handler no status form',str_contains($h,'This case is final')&&!str_contains($h,'id="inc-to"'));
$id3=newcase($f,$ir,['title'=>'Withdrawn']);act($rep);TicketService::incidentStatus($id3,$ver($id3),'CANCELLED','');
$r=html($rep,$id3);check('test_BR_771 withdrawn: stepper stops at Submitted, Cancelled in the pill, no withdraw card',steps($r)===['stop','todo','todo','todo','todo']&&str_contains($r,'Cancelled')&&!str_contains($r,'Withdraw this report'));

// ---------------------------------------------------------------- HR includes and removes people (BR-770, BR-773)
$id4=newcase($f,$ir,['title'=>'People changes','participants'=>$people]);
act($hr);TicketService::incidentAddParticipant($id4,$late['id'],'WITNESS');
$h=html($hr,$id4);$l=html($late,$id4);$r=html($rep,$id4);
check('test_BR_770 a person HR includes can open the case and sees names without role labels except Reporter and CC',str_contains($l,'People changes')&&str_contains($l,"Lara LatePerson $tag")&&!preg_match('/>(Involved|Witness)</',$l)&&!str_contains($l,'HR actions'));
check('test_BR_773 the handler timeline names the person and the role; nobody else sees the event',str_contains($h,"Person included: Lara LatePerson $tag (Witness)")&&!str_contains($r,'Person included')&&!str_contains($l,'Person included'));
check('test_BR_773 the handler sees how the person got access',str_contains($h,'Included by HR; can read this case'));
act($hr);TicketService::incidentRemoveParticipant($id4,$late['id']);
check('test_BR_770 a removed person loses the page at once',rejects(fn()=>html($late,$id4)));
$h=html($hr,$id4);check('test_BR_773 the handler timeline records the removal',str_contains($h,"Person removed: Lara LatePerson $tag"));
check('test_BR_775 a second HR reviewer is also a handler and sees the panel',str_contains(html($hr2,$id4),'HR actions'));

$id6=newcase($f,$ir,['title'=>'Chip case','participants'=>$people]);act($hr);TicketService::incidentAddParticipant($id6,$late['id'],'WITNESS');
act($late);TicketService::comment($id6,'LATEMSG-MARK','ALL');act($hr);TicketService::comment($id6,'HRMSG6-MARK','ALL');
$r=html($rep,$id6);$h=html($hr,$id6);
check('test_BR_773 a message from a person whose role is hidden has no chip (never "HR"); a real HR message is still chipped HR',preg_match('/Lara LatePerson '.preg_quote($tag,'/').'<\/strong> <small>/',$r)===1&&!preg_match('/Lara LatePerson '.preg_quote($tag,'/').'<\/strong> <span/',$r)&&preg_match('/'.preg_quote($hr['name'],'/').'<\/strong> <span class="tk-chip is-gray">HR</',$r)===1);
check('test_BR_773 the handler sees the real role chip on that message',preg_match('/Lara LatePerson '.preg_quote($tag,'/').'<\/strong> <span class="tk-chip is-gray">Witness</',$h)===1);

// ---------------------------------------------------------------- people search for the handler (BR-778, BR-780)
act($hr);$onCase=TicketIncident::peopleOnCase($id4);
check('test_BR_780 people already reading the case are the reporter and those with access',in_array($rep['id'],$onCase,true)&&in_array($cc['id'],$onCase,true)&&!in_array($inv['id'],$onCase,true)&&!in_array($late['id'],$onCase,true));
$names=fn(string $term)=>array_column(TicketIncident::searchPeople($term,$onCase),'name');
check('test_BR_780 the handler search skips people already on the case but still offers a recorded Involved person',!in_array("Cora CcPerson $tag",$names($tag),true)&&in_array("Ivan InvolvedPerson $tag",$names($tag),true)&&in_array("Lara LatePerson $tag",$names($tag),true));
check('test_BR_778 the same account rule: no client-portal account in the handler search',(function()use($tag,$f,$onCase){$mk=mk($tag,'cl',"Client ClPerson $tag",[],'client',$f,false);return !in_array("Client ClPerson $tag",array_column(TicketIncident::searchPeople("ClPerson",$onCase),'name'),true);})());

// ---------------------------------------------------------------- escaping of case text
$id5=newcase($f,$ir,['title'=>'<script>alert(1)</script>','explanation'=>'<img src=x onerror=alert(2)> body','location'=>'"><svg onload=1>']);
$r=html($rep,$id5);
check('test_BR_776 title, narrative and place are escaped',!str_contains($r,'<script>alert(1)')&&!str_contains($r,'<img src=x')&&!str_contains($r,'<svg onload')&&str_contains($r,'&lt;script&gt;alert(1)&lt;/script&gt;'));

// ---------------------------------------------------------------- the generic ticket page now hands incident cases to the Case page
act($rep);ob_start();ticket_detail_page(TicketRepository::detail($id));$g=ob_get_clean();
check('test_BR_772 ticket_detail_page renders the Case page for an incident',str_contains($g,'tk-case-grid')&&!str_contains($g,'Request details'));
finish();
