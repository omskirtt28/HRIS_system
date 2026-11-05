<?php
declare(strict_types=1);
// applicant-tracking-link (SPEC-applicant-tracking-link.md, John approved 2026-11-05). Pure unit test, no database.
require __DIR__.'/../app/ApplicantTracking.php';
$bad=0; $ok=0;
function t(string $label,bool $pass): void { global $bad,$ok; echo ($pass?'PASS ':'FAIL ').$label.PHP_EOL; $pass?$ok++:$bad++; }
function h(string $code,string $at,string $comment='internal note'): array { return ['to_code'=>$code,'to_name'=>$code,'changed_at'=>$at,'comment'=>$comment,'changed_by'=>7]; }
function view(string $current,array $history): array { return ApplicantTracking::publicView(['application_no'=>'PMBSI-2026-000001','applied_at'=>'2026-11-01 09:00:00','title'=>'Role','client_name'=>'PMBSI','stage_code'=>$current,'stage_name'=>'Hiring Review','history'=>$history]); }

// 1. the five labels (John's mapping) plus legacy Applied
foreach(['APPLIED'=>'Screening','SCREENING'=>'Screening','INTERVIEW'=>'Interview','ENDORSED'=>'Interview','CLIENT_REVIEW'=>'Interview','OFFER'=>'Offer','DEPLOYMENT'=>'Deployment','DEPLOYED'=>'Deployed'] as $code=>$label)
    t("$code shows as $label",ApplicantTracking::label($code)===$label);
t('steps are exactly the five public labels',ApplicantTracking::STEPS===['Screening','Interview','Offer','Deployment','Deployed']);

// 2. Endorsed / Hiring Review collapse into one Interview milestone, no internal words or comments
$v=view('CLIENT_REVIEW',[h('SCREENING','2026-11-01 09:00:00'),h('INTERVIEW','2026-11-02 09:00:00'),h('ENDORSED','2026-11-03 09:00:00'),h('CLIENT_REVIEW','2026-11-04 09:00:00')]);
t('Hiring Review current status reads Interview',$v['status']==='Interview');
t('consecutive Interview events become one milestone',array_column($v['milestones'],'label')===['Screening','Interview']);
t('Interview milestone keeps the first (real) date',$v['milestones'][1]['date']==='2026-11-02 09:00:00');
$flat=json_encode($v);
t('no comment, actor or internal stage name leaves publicView',!str_contains($flat,'internal note')&&!str_contains($flat,'Hiring Review')&&!str_contains($flat,'ENDORSED')&&!str_contains($flat,'changed_by'));
t('milestones carry only label and date',array_keys($v['milestones'][0])===['label','date']);
t('Interview is the current step (index 1), not an outcome',$v['reached']===1&&$v['outcome']===null);

// 3. Offer / Deployment / Deployed map exactly; a genuine step back stays truthful
$v=view('DEPLOYED',[h('SCREENING','2026-11-01'),h('INTERVIEW','2026-11-02'),h('OFFER','2026-11-03'),h('DEPLOYMENT','2026-11-04'),h('DEPLOYED','2026-11-05')]);
t('full path shows all five steps once',array_column($v['milestones'],'label')===ApplicantTracking::STEPS&&$v['reached']===4);
$v=view('SCREENING',[h('SCREENING','2026-11-01'),h('INTERVIEW','2026-11-02'),h('SCREENING','2026-11-03')]);
t('moved back to Screening: status Screening, history not flattened',$v['status']==='Screening'&&$v['reached']===0&&array_column($v['milestones'],'label')===['Screening','Interview','Screening']);

// 4. outcomes never fake advancement
foreach(['REJECTED'=>'Not selected','WITHDRAWN'=>'Withdrawn','ON_HOLD'=>'On hold'] as $code=>$label){
    $v=view($code,[h('SCREENING','2026-11-01'),h('INTERVIEW','2026-11-02'),h($code,'2026-11-03')]);
    t("$code shows outcome '$label' and stops at the last real step",$v['status']===$label&&$v['outcome']===$label&&$v['reached']===1);
}
$v=view('SOMETHING_NEW',[h('SCREENING','2026-11-01'),h('SOMETHING_NEW','2026-11-02')]);
t('unknown stage is neutral Under review, never a step',$v['status']==='Under review'&&$v['reached']===0&&$v['outcome']===null);

// 4b. step states: current step only while in progress
$states=fn(array $v)=>array_map(fn($i)=>ApplicantTracking::stepState($v,$i),array_keys(ApplicantTracking::STEPS));
t('Interview in progress: done, cur, then upcoming',$states(view('INTERVIEW',[h('SCREENING','2026-11-01'),h('INTERVIEW','2026-11-02')]))===['done','cur','','','']);
t('Deployed: all five done, no current step',$states(view('DEPLOYED',[h('SCREENING','2026-11-01'),h('DEPLOYED','2026-11-05')]))===['done','done','done','done','done']);
t('Not selected after Interview: two done, no current step',$states(view('REJECTED',[h('SCREENING','2026-11-01'),h('INTERVIEW','2026-11-02'),h('REJECTED','2026-11-03')]))===['done','done','','','']);

// 5. token format guard (server-side, before any query)
t('64 lowercase hex is a valid token',ApplicantTracking::validToken(str_repeat('a1',32)));
foreach(['','abc',str_repeat('A1',32),str_repeat('a1',32).'0',str_repeat('g1',32),"' OR 1=1 --"] as $bogus) t('rejects token '.json_encode(substr($bogus,0,12)),!ApplicantTracking::validToken($bogus));
t('link has the token and no email or reference',($l=ApplicantTracking::link('http://localhost/HRIS_system/',str_repeat('b2',32)))==='http://localhost/HRIS_system/index.php?page=track&t='.str_repeat('b2',32));

echo "RESULT $ok passed, $bad failed\n"; exit($bad?1:0);
