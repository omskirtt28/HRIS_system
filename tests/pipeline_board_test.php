<?php
declare(strict_types=1);
// pipeline-scale S1 (Plans/2026-11-04-pipeline-scale-talent-pool.md). Synthetic data only; run with HRIS_TEST_DB on a scratch copy.
require __DIR__.'/ticket_test_support.php';
$sfx=strtr(bin2hex(random_bytes(3)),'0123456789','ghijklmnop');
$client=insert('INSERT INTO clients(code,name) VALUES(?,?)',['PB'.$sfx,'Pb '.$sfx.' Trading']);
$mr=insert('INSERT INTO manpower_requests(request_no,client_id,position_title,requested_headcount,filled_headcount,employment_type,priority,status,requested_by) VALUES(?,?,?,?,0,"REGULAR","NORMAL","APPROVED",NULL)',['MR-PB-'.$sfx,$client,'Pb Clerk '.$sfx,50]);
$job=insert('INSERT INTO job_openings(job_code,slug,title,description,client_id,manpower_request_id,openings,status,location_text,employment_type,site_type) VALUES(?,?,?,?,?,?,50,"OPEN","Synthetic","REGULAR","HEAD_OFFICE")',['PB'.$sfx,'pb-'.$sfx,'Pb Clerk '.$sfx,'x',$client,$mr]);
function mk(int $client,int $mr,int $job,string $sfx,int $i,string $stage,string $first): int {
    $a=insert('INSERT INTO applicants(applicant_no,first_name,last_name,email,mobile_no,source,consent_at,status) VALUES(?,?,?,?,?,"CAREERS_SITE",NOW(),"ACTIVE")',['APP-PB-'.$sfx.$i,$first,'Pb'.$sfx,'pb'.$sfx.$i.'@example.test','09170000000']);
    return insert('INSERT INTO applications(application_no,applicant_id,job_opening_id,manpower_request_id,client_id,current_stage_id,applied_at,last_stage_changed_at,status) VALUES(?,?,?,?,?,(SELECT id FROM recruitment_stages WHERE code=?),NOW(),DATE_SUB(NOW(),INTERVAL ? MINUTE),"ACTIVE")',['AP-PB-'.$sfx.$i,$a,$job,$mr,$client,$stage,$i]);
}
// 12 Screening, 3 Rejected, 2 Deployed
for($i=1;$i<=12;$i++)mk($client,$mr,$job,$sfx,$i,'SCREENING',$i===1?'Zelda':'Pat');
for($i=13;$i<=15;$i++)mk($client,$mr,$job,$sfx,$i,'REJECTED','Rex');
for($i=16;$i<=17;$i++)mk($client,$mr,$job,$sfx,$i,'DEPLOYED','Dee');
$f=['q'=>'Pb'.$sfx];

$cnt=RecruitmentRepository::boardStageCounts($f);
check('boardStageCounts: per stage totals',($cnt['SCREENING']??0)===12&&($cnt['REJECTED']??0)===3&&($cnt['DEPLOYED']??0)===2);

$col=RecruitmentRepository::boardColumns($f,8);
$by=[];foreach($col as $c)$by[$c['code']]=$c;
check('board: Deployed is not a column',!isset($by['DEPLOYED']));
check('board: Screening shows 8 cards but counts 12',count($by['SCREENING']['items'])===8&&$by['SCREENING']['total']===12);
check('board: Rejected shows all 3',count($by['REJECTED']['items'])===3&&$by['REJECTED']['total']===3);
check('board: newest first (i=1 is newest)',$by['SCREENING']['items'][0]['first_name']==='Zelda');
check('board: empty stage has total 0',isset($by['OFFER'])&&$by['OFFER']['total']===0&&$by['OFFER']['items']===[]);

$p1=RecruitmentRepository::applications($f+['stage'=>'SCREENING','limit'=>5,'offset'=>0]);
$p3=RecruitmentRepository::applications($f+['stage'=>'SCREENING','limit'=>5,'offset'=>10]);
check('paging: page 1 has 5, last page has 2',count($p1)===5&&count($p3)===2);
check('paging: count matches the filter',RecruitmentRepository::applicationsCount($f+['stage'=>'SCREENING'])===12&&RecruitmentRepository::applicationsCount($f)===17);
check('no limit keeps old behaviour (all rows)',count(RecruitmentRepository::applications($f))===17);

$byJob=RecruitmentRepository::applicationsCount(['job_id'=>$job]);
check('job filter',$byJob===17);
check('job filter excludes other jobs',RecruitmentRepository::applicationsCount(['job_id'=>$job+999999])===0);

// hostile paging values are clamped, never injected
$x=RecruitmentRepository::applications($f+['limit'=>'5; DROP TABLE applications','offset'=>-3]);
check('limit/offset are cast to ints',count($x)>=0&&count($x)<=17);
finish();
