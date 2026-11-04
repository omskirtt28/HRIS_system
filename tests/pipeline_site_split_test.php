<?php
declare(strict_types=1);
// pipeline-site-split (Plans/2026-11-04-pipeline-site-split.md). Synthetic data only; run with HRIS_TEST_DB on a scratch copy that has migration 036.
require __DIR__.'/ticket_test_support.php';
$sfx=strtr(bin2hex(random_bytes(3)),'0123456789','ghijklmnop');
$client=insert('INSERT INTO clients(code,name) VALUES(?,?)',['PS'.$sfx,'Ps '.$sfx.' Trading']);
$ho=(int)row('SELECT id FROM branches WHERE site_type="HEAD_OFFICE" ORDER BY id LIMIT 1')['id'];
$retail=array_map('intval',db()->query('SELECT id FROM branches WHERE site_type="RETAIL_STORE" AND active=1 ORDER BY id LIMIT 3')->fetchAll(PDO::FETCH_COLUMN));
[$b1,$b2,$b3]=$retail;

/** One job + one Screening application. $siteType null = rely on the manpower request branch; $mrBranch the request's branch; $jobBranches the job's own Retail list. */
function mkjob(int $client,string $sfx,string $tag,?string $siteType,?int $mrBranch,array $jobBranches): array {
    $mr=insert('INSERT INTO manpower_requests(request_no,client_id,branch_id,position_title,requested_headcount,filled_headcount,employment_type,priority,status,requested_by) VALUES(?,?,?,?,5,0,"REGULAR","NORMAL","APPROVED",NULL)',['MR-PS-'.$sfx.$tag,$client,$mrBranch,'Ps '.$tag.' '.$sfx]);
    $job=insert('INSERT INTO job_openings(job_code,slug,title,description,client_id,manpower_request_id,openings,status,location_text,employment_type,site_type) VALUES(?,?,?,?,?,?,5,"OPEN","Synthetic","REGULAR",?)',['PS'.$sfx.$tag,'ps-'.$sfx.strtolower($tag),'Ps '.$tag.' '.$sfx,'x',$client,$mr,$siteType]);
    foreach($jobBranches as $b)insert('INSERT INTO job_opening_branches(job_opening_id,branch_id) VALUES(?,?)',[$job,$b]);
    $a=insert('INSERT INTO applicants(applicant_no,first_name,last_name,email,mobile_no,source,consent_at,status) VALUES(?,?,?,?,?,"CAREERS_SITE",NOW(),"ACTIVE")',['APP-PS-'.$sfx.$tag,$tag,'Ps'.$sfx,'ps'.$sfx.strtolower($tag).'@example.test','09170000000']);
    $app=insert('INSERT INTO applications(application_no,applicant_id,job_opening_id,manpower_request_id,client_id,current_stage_id,applied_at,last_stage_changed_at,status) VALUES(?,?,?,?,?,(SELECT id FROM recruitment_stages WHERE code="SCREENING"),NOW(),NOW(),"ACTIVE")',['AP-PS-'.$sfx.$tag,$a,$job,$mr,$client]);
    return ['job'=>$job,'app'=>$app];
}
$j=[];
$j['HO']=mkjob($client,$sfx,'HO','HEAD_OFFICE',null,[]);          // explicit Head Office
$j['HO2']=mkjob($client,$sfx,'HOB',null,$ho,[]);                  // no site on the job: resolved from the request's Head Office branch
$j['R1']=mkjob($client,$sfx,'R1','RETAIL',null,[$b1]);            // Retail, one branch
$j['R2']=mkjob($client,$sfx,'R2','RETAIL',null,[$b1,$b2]);        // Retail, two branches
$j['R3']=mkjob($client,$sfx,'R3',null,$b3,[]);                    // Retail resolved from the request branch, no job branch list
$j['OT']=mkjob($client,$sfx,'OT','OTHER',null,[]);                // neither site
$j['UN']=mkjob($client,$sfx,'UN',null,null,[]);                   // unknown site
$f=['q'=>'Ps'.$sfx];
$n=fn(array $x)=>RecruitmentRepository::applicationsCount($x+$f);

// site split
check('Head Office view = explicit + resolved-from-branch (2)',$n(['site'=>'HEAD_OFFICE'])===2);
check('Retail view = 3 Retail jobs',$n(['site'=>'RETAIL'])===3);
check('UNASSIGNED = Other + unknown (2)',$n(['site'=>'UNASSIGNED'])===2);
check('nobody lost or doubled: sum of the three = all (7)',$n(['site'=>'HEAD_OFFICE'])+$n(['site'=>'RETAIL'])+$n(['site'=>'UNASSIGNED'])===$n([])&&$n([])===7);
check('unknown site value is ignored, never injected',$n(['site'=>"x' OR 1=1 -- "])===7);

// Retail branch filter
check('branch b1 = R1 + R2',$n(['site'=>'RETAIL','branch_ids'=>[$b1]])===2);
check('branch b2 = R2 only',$n(['site'=>'RETAIL','branch_ids'=>[$b2]])===1);
check('branch b3 (from request branch, no job list) = R3',$n(['site'=>'RETAIL','branch_ids'=>[$b3]])===1);
check('two branches: a two-branch job counts once',$n(['site'=>'RETAIL','branch_ids'=>[$b1,$b2]])===2);
check('hostile branch ids are cast to ints (only b1 survives)',$n(['site'=>'RETAIL','branch_ids'=>[$b1.'; DROP TABLE applications',0,-5]])===2);
check('empty branch list = every Retail branch',$n(['site'=>'RETAIL','branch_ids'=>[]])===3);

// board and filter options follow the same filter
$cnt=RecruitmentRepository::boardStageCounts($f+['site'=>'RETAIL']);
check('board stage counts honour the site',($cnt['SCREENING']??0)===3);
$cols=RecruitmentRepository::boardColumns($f+['site'=>'HEAD_OFFICE'],8);
$scr=array_values(array_filter($cols,fn($c)=>$c['code']==='SCREENING'))[0];
check('board cards honour the site',$scr['total']===2&&count($scr['items'])===2);
$ids=array_column(RecruitmentRepository::boardFilterOptions(['site'=>'HEAD_OFFICE'])['jobs'],'id');
check('job dropdown lists only that site\'s jobs',in_array($j['HO']['job'],array_map('intval',$ids),true)&&!in_array($j['R1']['job'],array_map('intval',$ids),true));

// saved view per HR user
$role=(int)row('SELECT id FROM roles WHERE code="HR_USER"')['id'];
$u=[];foreach(['A','B'] as $k)$u[$k]=insert('INSERT INTO users(role_id,full_name,email,password_hash,status) VALUES(?,?,?,?,"ACTIVE")',[$role,'Ps '.$k.' '.$sfx,'ps'.strtolower($k).$sfx.'@example.test',password_hash('x',PASSWORD_DEFAULT)]);
check('first time: Head Office, no branches',RecruitmentRepository::pipelineView($u['A'])===['site'=>'HEAD_OFFICE','branch_ids'=>[]]);
RecruitmentRepository::savePipelineView($u['A'],'RETAIL',[$b2,$b1]);
$v=RecruitmentRepository::pipelineView($u['A']);sort($v['branch_ids']);
check('saved Retail view comes back',$v['site']==='RETAIL'&&$v['branch_ids']===[min($b1,$b2),max($b1,$b2)]);
check('another HR is unaffected',RecruitmentRepository::pipelineView($u['B'])['site']==='HEAD_OFFICE');
RecruitmentRepository::savePipelineView($u['A'],'RETAIL',[$b3]);
check('saving again replaces the branches',RecruitmentRepository::pipelineView($u['A'])['branch_ids']===[$b3]);
RecruitmentRepository::savePipelineView($u['A'],'RETAIL',[$b3,$ho,999999999]);
check('Head Office branch and unknown ids are dropped',RecruitmentRepository::pipelineView($u['A'])['branch_ids']===[$b3]);
RecruitmentRepository::savePipelineView($u['A'],'HEAD_OFFICE',null);
$v=RecruitmentRepository::pipelineView($u['A']);
check('switching to Head Office keeps the saved Retail branches for the way back',$v['site']==='HEAD_OFFICE'&&$v['branch_ids']===[$b3]);
RecruitmentRepository::savePipelineView($u['A'],'RETAIL',null);
check('switching back to Retail restores them',RecruitmentRepository::pipelineView($u['A'])===['site'=>'RETAIL','branch_ids'=>[$b3]]);
RecruitmentRepository::savePipelineView($u['A'],'RETAIL',[]);
check('an explicit empty list clears them (= all Retail branches)',RecruitmentRepository::pipelineView($u['A'])['branch_ids']===[]);
check('bad site is refused',rejects(fn()=>RecruitmentRepository::savePipelineView($u['A'],'ALL',[])));
foreach($u as $id){db()->exec('DELETE FROM user_pipeline_view_branches WHERE user_id='.$id);db()->exec('DELETE FROM user_pipeline_view WHERE user_id='.$id);db()->exec('DELETE FROM users WHERE id='.$id);} // leave no HR users behind: other suites count them
finish();
