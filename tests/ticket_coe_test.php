<?php
declare(strict_types=1);
// Patch 1 (migration 014): Certificate of Employment ticket, HR-managed .docx template, preview-before-Served, private download.
// Run: HRIS_TEST_DB=hris_slice15_test_YYYYMMDD_HHMMSS php tests/ticket_coe_test.php   (isolated database only, synthetic data)
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/View.php';
require_once dirname(__DIR__).'/app/TicketService.php';
require_once dirname(__DIR__).'/app/TicketQueue.php';
require_once dirname(__DIR__).'/app/TicketViews.php';
require_once dirname(__DIR__).'/app/TicketCoeViews.php';
require_once dirname(__DIR__).'/app/TicketAccountingViews.php';
function need_db():void{db();}
$f=fixtures();$hr=$f['hr'];$req=$f['employee'];
db()->exec('DELETE FROM coe_documents');db()->exec('DELETE FROM coe_templates');db()->exec('DELETE FROM coe_settings');// reruns on the same scratch database start with no template
function form(string $code,array $extra=[]):array{return array_replace(incident_fields($code),['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code=?',[$code])['id'],'submission_key'=>bin2hex(random_bytes(32))],$extra);}
function perm(int $role,string $code,bool $on=true):void{$p=row('SELECT id FROM permissions WHERE code=?',[$code]);if(!$p)throw new RuntimeException('missing permission '.$code);
    if($on)db()->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) VALUES(?,?)')->execute([$role,$p['id']]);else db()->prepare('DELETE FROM role_permissions WHERE role_id=? AND permission_id=?')->execute([$role,$p['id']]);Auth::clearPermissionCache();}
$W='http://schemas.openxmlformats.org/wordprocessingml/2006/main';
/** Minimal .docx. $paras: list of paragraphs, each a list of run texts (so placeholders can be split across runs). */
function mkdocx(array $paras,array $o=[]):string{
    global $W;$path=tempnam(sys_get_temp_dir(),'tcoe');$z=new ZipArchive();$z->open($path,ZipArchive::OVERWRITE);
    $body='';foreach($paras as $runs){$body.='<w:p>';foreach($runs as $r)$body.='<w:r><w:t xml:space="preserve">'.htmlspecialchars($r,ENT_XML1).'</w:t></w:r>';$body.='</w:p>';}
    $main='application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';
    $z->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="'.($o['ct']??$main).'"/></Types>');
    $z->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $prefix=$o['doctype']??'';
    if(empty($o['no_document']))$z->addFromString('word/document.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$prefix.'<w:document xmlns:w="'.$W.'"><w:body>'.$body.'</w:body></w:document>');
    if(!empty($o['vba']))$z->addFromString('word/vbaProject.bin','x');
    if(!empty($o['header']))$z->addFromString('word/header1.xml','<?xml version="1.0" encoding="UTF-8"?><w:hdr xmlns:w="'.$W.'"><w:p><w:r><w:t>'.$o['header'].'</w:t></w:r></w:p></w:hdr>');
    $z->close();return $path;
}
$all=[['{{employee_name}} / {{position}} / {{hire_date}} / {{company}} / {{purpose}} / {{date_issued}}']];
function docText(string $docxPath,string $part='word/document.xml'):string{$z=new ZipArchive();$z->open($docxPath);$x=(string)$z->getFromName($part);$z->close();return $x;}
function coeId(array $extra=[]):int{return TicketService::create(form('COE_REQUEST',array_replace(['purpose'=>'Bank','purpose_other'=>'','needed_by'=>date('Y-m-d',strtotime('+7 days')),'delivery'=>'Email'],$extra)),true);}
function previewAndServe(int $id,bool $salary=false):void{
    $t=TicketRepository::find($id);TicketCoe::preview($id,$salary);
    TicketService::act($id,(int)$t['version'],'COMPLETE','',  '',0,['include_salary'=>$salary]);
}

// ---------------------------------------------------------------- fixtures: roles, permissions, employee data
foreach(['ticket.file.coe'] as $p){perm($req['role_id'],$p);perm($f['outsider']['role_id'],$p);perm($f['manager']['role_id'],$p);perm($f['adl']['role_id'],$p);}
perm($hr['role_id'],'ticket.coe.template.manage');
$pos=insert('INSERT INTO positions(code,name) VALUES(?,?)',[$f['tag'].'_P','Accounting Clerk']);
db()->prepare('UPDATE employees SET position_id=?,first_name="Kate Anne",middle_name=NULL,last_name="Cadiz",hire_date="2021-03-15" WHERE id=?')->execute([$pos,$f['requester']]);
$legal=row('SELECT name FROM legal_entities WHERE id=?',[$f['legal']])['name'];

// ---------------------------------------------------------------- migration seeds
check('permissions seeded',(int)row('SELECT COUNT(*) n FROM permissions WHERE code IN ("ticket.file.coe","ticket.coe.template.manage")')['n']===2);
check('ticket type is HR_ONLY and active',row('SELECT routing_profile,active FROM ticket_types WHERE code="COE_REQUEST"')==['routing_profile'=>'HR_ONLY','active'=>1]);
$tplRoles=db()->query('SELECT r.code FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE p.code="ticket.coe.template.manage" AND r.code NOT LIKE "T15\_%" ORDER BY r.code')->fetchAll(PDO::FETCH_COLUMN);
check('template permission only on HR roles',!array_diff($tplRoles,['HR_USER','HRIS_ADMIN']));
$fileRoles=db()->query('SELECT r.code FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE p.code="ticket.file.coe" AND r.code NOT LIKE "T15\_%"')->fetchAll(PDO::FETCH_COLUMN);
$atmRoles=db()->query('SELECT r.code FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE p.code="ticket.file.atm" AND r.code NOT LIKE "T15\_%"')->fetchAll(PDO::FETCH_COLUMN);
sort($fileRoles);sort($atmRoles);check('ticket.file.coe seeded to the same roles that file ATM',$fileRoles===$atmRoles&&$fileRoles!==[]);

// ---------------------------------------------------------------- filing
act($req);
check('COE is in the filing dropdown',in_array('COE_REQUEST',array_column(TicketRepository::types(),'code'),true));
check('filing without the permission is denied',(function()use($f){perm($f['outsider']['role_id'],'ticket.file.coe',false);act($f['outsider']);$r=rejects(fn()=>coeId());perm($f['outsider']['role_id'],'ticket.file.coe');act($f['req']??$f['employee']);return $r;})());
act($req);
check('Other without details is rejected',rejects(fn()=>coeId(['purpose'=>'Other','purpose_other'=>''])));
check('past needed-by date is rejected',rejects(fn()=>coeId(['needed_by'=>date('Y-m-d',strtotime('-1 day'))])));
check('needed-by more than a year out is rejected',rejects(fn()=>coeId(['needed_by'=>date('Y-m-d',strtotime('+2 years'))])));
check('invalid date text is rejected',rejects(fn()=>coeId(['needed_by'=>'2026-13-45'])));
check('unknown purpose is rejected',rejects(fn()=>coeId(['purpose'=>'Hobby'])));
check('unknown delivery is rejected',rejects(fn()=>coeId(['delivery'=>'Courier'])));
$id1=coeId();$t1=TicketRepository::find($id1);
check('valid COE files straight to HR (Pending)',$t1['status']==='PENDING_HR'&&TicketTypes::label($t1['status'],'COE_REQUEST')==='Pending');
check('single HR step, no approver above',count(db()->query('SELECT * FROM ticket_steps WHERE ticket_id='.$id1)->fetchAll())===1&&row('SELECT approver_type FROM ticket_steps WHERE ticket_id=?',[$id1])['approver_type']==='HR');
check('salary flag defaults to no',(int)row('SELECT include_salary FROM coe_requests WHERE ticket_id=?',[$id1])['include_salary']===0);
$idOther=coeId(['purpose'=>'Other','purpose_other'=>'Scholarship application']);
check('Other with text is accepted and kept',row('SELECT purpose_other FROM coe_requests WHERE ticket_id=?',[$idOther])['purpose_other']==='Scholarship application');
$idNonOther=coeId(['purpose'=>'Loan','purpose_other'=>'ignored text']);
check('details are dropped when purpose is not Other',row('SELECT purpose_other FROM coe_requests WHERE ticket_id=?',[$idNonOther])['purpose_other']===null);

// ---------------------------------------------------------------- HR queue: included, but never bulk/quick Served
act($hr);
$queue=TicketQueue::page(TicketQueue::filters(['type_code'=>'COE_REQUEST','sort'=>'newest']));
check('COE appears in the HR queue',(bool)array_filter($queue['rows'],fn($r)=>(int)$r['id']===$id1));
$ver=(int)TicketRepository::find($id1)['version'];
$res=TicketQueue::apply([$id1=>$ver],'SERVED','',true);
check('bulk Served skips COE',$res['done']===0&&count($res['skipped'])===1&&TicketRepository::find($id1)['status']==='PENDING_HR');
$res=TicketQueue::apply([$id1=>$ver],'SERVED','',false);
check('quick Served skips COE',$res['done']===0&&TicketRepository::find($id1)['status']==='PENDING_HR');
check('Served without a preview is refused on the service',rejects(fn()=>TicketService::act($id1,$ver,'COMPLETE','','',0,['include_salary'=>false]))&&TicketRepository::find($id1)['status']==='PENDING_HR');
check('forged preview values/ticket token do not work',rejects(fn()=>TicketService::act($id1,$ver,'COMPLETE','','',0,['include_salary'=>false,'coe_preview_hash'=>str_repeat('a',64)])));
check('no certificate row after refused attempts',(int)row('SELECT COUNT(*) n FROM coe_documents WHERE ticket_id=?',[$id1])['n']===0);

// ---------------------------------------------------------------- preview values
$pv=TicketCoe::preview($id1,false);$v=$pv['values'];
check('preview: values match the employee record',$v['employee_name']==='Kate Anne Cadiz'&&$v['position']==='Accounting Clerk'&&$v['hire_date']==='March 15, 2021'&&$v['company']===$legal&&$v['purpose']==='Bank');
check('preview: date_issued is the Served date (today)',$v['date_issued']===date('F j, Y'));
check('preview: salary blank by default',$v['salary']===''&&$pv['problems']===[]);
check('preview: purpose Other shows the typed text',TicketCoe::preview($idOther,false)['values']['purpose']==='Scholarship application');
$pvSalary=TicketCoe::preview($id1,true);
check('preview: salary ticked with no rate on file is a blocking problem',$pvSalary['values']['salary']===''&&count($pvSalary['problems'])===1);
insert('INSERT INTO payroll_employee_rates(employee_id,effective_from,salary_basis,salary_amount,hourly_divisor,created_by) VALUES(?,?,?,?,?,?)',[$f['requester'],'2021-03-15','MONTHLY','25000',208,$hr['id']]);
$pvSalary=TicketCoe::preview($id1,true);
check('preview: salary ticked uses the rate on file',$pvSalary['values']['salary']==='PHP 25,000.00 per month'&&$pvSalary['problems']===[]);
db()->prepare('UPDATE employees SET hire_date=NULL WHERE id=?')->execute([$f['requester']]);
$pvMissing=TicketCoe::preview($id1,false);
check('preview: missing hire date blocks and is named',count($pvMissing['problems'])===1&&str_contains($pvMissing['problems'][0],'Hire date'));
check('Served is refused while data is missing',rejects(fn()=>TicketService::act($id1,$ver,'COMPLETE','','',0,['include_salary'=>false])));
db()->prepare('UPDATE employees SET hire_date="2021-03-15" WHERE id=?')->execute([$f['requester']]);

// ---------------------------------------------------------------- who may preview
foreach(['requester'=>$req,'outsider'=>$f['outsider'],'manager'=>$f['manager'],'adl'=>$f['adl']] as $who=>$user){act($user);check('preview denied for '.$who,rejects(fn()=>TicketCoe::preview($id1,false)));}
act($hr);

// ---------------------------------------------------------------- serve with the built-in default layout
TicketCoe::preview($id1,false);
TicketService::act($id1,$ver,'COMPLETE','','',0,['include_salary'=>false]);
$served=TicketRepository::find($id1);$doc=row('SELECT * FROM coe_documents WHERE ticket_id=?',[$id1]);
check('Served after preview completes the ticket and stores one certificate',$served['status']==='COMPLETED'&&$doc['template_id']===null&&(int)$doc['include_salary']===0&&$doc['date_issued']===date('Y-m-d'));
$stored=dirname(__DIR__).'/storage/coe_documents/'.$doc['stored_name'];
check('file is in protected storage, not under a public path',is_file($stored)&&str_contains(str_replace('\\','/',$stored),'/storage/coe_documents/')&&!str_contains($stored,'/public/'));
$xml=docText($stored);
check('default layout filled with the employee values and no placeholders left',str_contains($xml,'Kate Anne Cadiz')&&str_contains($xml,'Accounting Clerk')&&str_contains($xml,'March 15, 2021')&&!str_contains($xml,'{{')&&!str_contains($xml,'compensation'));
$dom=new DOMDocument();check('generated document.xml is well-formed',@$dom->loadXML($xml));
TicketService::act($id1,(int)$served['version'],'COMPLETE','','',0,['include_salary'=>false]);check('replaying Served is a no-op and creates no second certificate',(int)row('SELECT COUNT(*) n FROM coe_documents WHERE ticket_id=?',[$id1])['n']===1);
check('COE_GENERATED audited without values',(bool)row('SELECT id FROM audit_logs WHERE action="COE_GENERATED" AND record_id=?',[$doc['id']])&&!str_contains(row('SELECT details_json FROM audit_logs WHERE action="COE_GENERATED" AND record_id=? ORDER BY id DESC LIMIT 1',[$doc['id']])['details_json'],'Cadiz'));

// ---------------------------------------------------------------- download access
$downloads=fn()=>(int)row('SELECT COUNT(*) n FROM audit_logs WHERE action="COE_DOWNLOADED" AND record_id=?',[$doc['id']])['n'];
act($req);$d=TicketCoe::download($id1);check('requester can download',is_file($d['path'])&&str_ends_with($d['name'],'.docx'));
act($hr);$d=TicketCoe::download($id1);check('HR can download',is_file($d['path']));
check('each download is audited',$downloads()===2);
// a Head Office manager with view_team sees an ATM by the same employee, but never the COE
db()->prepare('UPDATE employees SET manager_employee_id=? WHERE id=?')->execute([$f['manager_employee'],$f['requester']]);
perm($f['manager']['role_id'],'ticket.view_team');perm($f['manager']['role_id'],'payroll.approve_manager');
act($req);$atm=TicketService::create(form('ATM_REQUEST',['reason'=>'Synthetic']),true);
act($f['manager']);
$viewerSeesAtm=true;try{$a=TicketRepository::find($atm);$viewerSeesAtm=!empty($a['viewer_only']);}catch(Throwable){$viewerSeesAtm=false;}
check('control: the viewer rule does reach this employee\'s ATM',$viewerSeesAtm);
check('viewer cannot open the COE ticket',rejects(fn()=>TicketRepository::find($id1)));
check('viewer cannot download the certificate',rejects(fn()=>TicketCoe::download($id1)));
check('COE is absent from Team Requests',!array_filter(TicketRepository::listing('team',[])['rows'],fn($r)=>(int)$r['id']===$id1));
foreach(['outsider'=>$f['outsider'],'adl'=>$f['adl']] as $who=>$user){act($user);check('download denied for '.$who,rejects(fn()=>TicketCoe::download($id1)));}
act($req);check('forged download of a different ticket id is denied',rejects(fn()=>TicketCoe::download($atm)));
check('forged download of a missing id is denied',rejects(fn()=>TicketCoe::download(99999999)));
act($f['outsider']);$other=coeId();act($f['outsider']);check('requester cannot download before it is Served',rejects(fn()=>TicketCoe::download($other)));
act($req);check('another employee\'s pending COE is not downloadable',rejects(fn()=>TicketCoe::download($other)));
check('denied attempts left no download audit',$downloads()===2);
// tampering with the stored file is caught
file_put_contents($stored,'tampered',FILE_APPEND);act($req);check('a changed stored file is refused',rejects(fn()=>TicketCoe::download($id1)));
// restore file from template-free regeneration is not possible; remove row so later checks stay independent

// ---------------------------------------------------------------- template upload validation
act($hr);
$good=mkdocx([['Custom letterhead'],['{{employee_na','me}} works as {{pos','ition}} since {{hire_date}} at {{company}}.'],['Purpose: {{purpose}}'],['Issued {{date_issued}}']],['header'=>'HEADER {{company}}']);
$nm=fn(string $p,string $name)=>rejects(fn()=>TicketCoe::addTemplate($p,$name));
$txt=tempnam(sys_get_temp_dir(),'t');file_put_contents($txt,'plain text pretending to be a docx {{employee_name}}');
check('plain text with a .docx extension is rejected',$nm($txt,'a.docx'));
$png=tempnam(sys_get_temp_dir(),'t');file_put_contents($png,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
check('a PNG renamed .docx is rejected',$nm($png,'a.docx'));
check('a valid docx with a .txt extension is rejected',$nm($good,'a.txt'));
$noDoc=mkdocx($all,['no_document'=>true]);check('a zip without word/document.xml (e.g. xlsx renamed) is rejected',$nm($noDoc,'a.docx'));
check('a macro-enabled content type is rejected',$nm(mkdocx($all,['ct'=>'application/vnd.ms-word.document.macroEnabled.main+xml']),'a.docx'));
check('a vbaProject part is rejected',$nm(mkdocx($all,['vba'=>true]),'a.docx'));
check('XML with a DOCTYPE/entity is rejected',$nm(mkdocx($all,['doctype'=>'<!DOCTYPE w:document [<!ENTITY x "y">]>']),'a.docx'));
check('a missing required placeholder is rejected and named',(function(){try{TicketCoe::addTemplate(mkdocx([['{{employee_name}} {{position}} {{hire_date}} {{company}} {{purpose}}']]),'a.docx');return false;}catch(RuntimeException $e){return str_contains($e->getMessage(),'date_issued');}})());
check('an unknown placeholder is rejected',$nm(mkdocx([['{{employee_name}} {{position}} {{hire_date}} {{company}} {{purpose}} {{date_issued}} {{ssn}}']]),'a.docx'));
$big=tempnam(sys_get_temp_dir(),'t');$z=new ZipArchive();$z->open($big,ZipArchive::OVERWRITE);$z->addFromString('x.bin',random_bytes(2200000));$z->close();
check('a file over 2 MB is rejected',$nm($big,'a.docx'));
check('nothing was stored by rejected uploads',(int)row('SELECT COUNT(*) n FROM coe_templates')['n']===0);
foreach(['requester'=>$req,'outsider'=>$f['outsider'],'manager'=>$f['manager']] as $who=>$user){act($user);check('template upload denied for '.$who,rejects(fn()=>TicketCoe::addTemplate($good,'a.docx')));}
perm($hr['role_id'],'ticket.coe.template.manage',false);act($hr);check('HR without the template permission cannot upload',rejects(fn()=>TicketCoe::addTemplate($good,'a.docx')));
perm($hr['role_id'],'ticket.coe.template.manage');act($hr);
$tid=TicketCoe::addTemplate($good,'letter.docx','first');
$tpl=row('SELECT * FROM coe_templates WHERE id=?',[$tid]);
check('valid template (placeholders split over runs, header too) accepted as v1 active',(int)$tpl['version_no']===1&&(int)$tpl['is_active']===1&&preg_match('/^[a-f0-9]{48}\.docx$/',$tpl['stored_name'])===1);
check('template stored under protected storage',is_file(dirname(__DIR__).'/storage/coe_templates/'.$tpl['stored_name']));
check('template upload audited',(bool)row('SELECT id FROM audit_logs WHERE action="COE_TEMPLATE_UPLOADED" AND record_id=?',[$tid]));

// ---------------------------------------------------------------- fill with the uploaded template, incl. malicious data
db()->prepare('UPDATE employees SET last_name=? WHERE id=?')->execute(['Cadiz <w:evil/> & "q" ]]> {{salary}}'.str_repeat('x',50),$f['requester']]);
TicketCoe::saveSettings(['signatory_name'=>'Sig Natory','signatory_title'=>'HR Head','company_display_name'=>'PMBSI Test Inc.']);
check('settings saved and audited',TicketCoe::settings()['signatory_name']==='Sig Natory'&&(bool)row('SELECT id FROM audit_logs WHERE action="COE_SETTINGS_CHANGED" ORDER BY id DESC LIMIT 1'));
$id2=(function()use($req){act($req);return coeId(['purpose'=>'Other','purpose_other'=>'Visa <script>&']);})();act($hr);
previewAndServe($id2);
$doc2=row('SELECT * FROM coe_documents WHERE ticket_id=?',[$id2]);$path2=dirname(__DIR__).'/storage/coe_documents/'.$doc2['stored_name'];
$x2=docText($path2);$d2=new DOMDocument();$ok=@$d2->loadXML($x2);
check('uploaded template used (version recorded)',(int)$doc2['template_version']===1&&(int)$doc2['template_id']===$tid);
check('filled XML is still well-formed with hostile values',$ok);
check('hostile markup is escaped text, not elements',$ok&&$d2->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main','evil')->length===0&&str_contains($d2->textContent,'<w:evil/>')&&str_contains($d2->textContent,'Visa <script>&'));
check('placeholders split across runs were filled; template text kept',str_contains($d2->textContent,'works as Accounting Clerk since March 15, 2021 at PMBSI Test Inc.')&&str_contains($d2->textContent,'Custom letterhead'));
check('a placeholder typed into a value is not substituted again',substr_count($d2->textContent,'{{salary}}')===1);
check('header placeholder filled from the config company name',str_contains(docText($path2,'word/header1.xml'),'HEADER PMBSI Test Inc.'));
// optional signatory placeholders and salary only when ticked
$withSig=mkdocx([['{{employee_name}} {{position}} {{hire_date}} {{company}} {{purpose}} {{date_issued}}'],['Salary: {{salary}}'],['Signed {{signatory_name}}, {{signatory_title}}']]);
$tid2=TicketCoe::addTemplate($withSig,'v2.docx');
check('v2 becomes active, v1 kept in history',(int)row('SELECT version_no FROM coe_templates WHERE is_active=1')['version_no']===2&&(int)row('SELECT COUNT(*) n FROM coe_templates')['n']===2&&(int)row('SELECT COUNT(*) n FROM coe_templates WHERE is_active=1')['n']===1);
db()->prepare('UPDATE employees SET last_name="Cadiz" WHERE id=?')->execute([$f['requester']]);
act($req);$id3=coeId();act($hr);previewAndServe($id3,true);
$x3=docText(dirname(__DIR__).'/storage/coe_documents/'.row('SELECT stored_name FROM coe_documents WHERE ticket_id=?',[$id3])['stored_name']);
check('salary printed only when HR ticked it',str_contains($x3,'PHP 25,000.00 per month')&&str_contains($x3,'Sig Natory')&&str_contains($x3,'HR Head')&&(int)row('SELECT include_salary FROM coe_documents WHERE ticket_id=?',[$id3])['include_salary']===1&&(int)row('SELECT include_salary FROM coe_requests WHERE ticket_id=?',[$id3])['include_salary']===1);
act($req);$id4=coeId();act($hr);previewAndServe($id4,false);
$x4=docText(dirname(__DIR__).'/storage/coe_documents/'.row('SELECT stored_name FROM coe_documents WHERE ticket_id=?',[$id4])['stored_name']);
check('salary stays blank when not ticked even if the template has {{salary}}',str_contains($x4,'Salary: <')||str_contains($x4,'Salary: </w:t>')||!str_contains($x4,'25,000'));
check('roll back to v1',(function()use($tid){TicketCoe::activate($tid);return (int)row('SELECT id FROM coe_templates WHERE is_active=1')['id']===$tid;})());
check('activation audited',(bool)row('SELECT id FROM audit_logs WHERE action="COE_TEMPLATE_ACTIVATED"'));
act($hr);$tf=TicketCoe::templateFile($tid);check('HR can download a template version, audited',is_file($tf['path'])&&(bool)row('SELECT id FROM audit_logs WHERE action="COE_TEMPLATE_DOWNLOADED"'));
act($req);check('requester cannot download a template',rejects(fn()=>TicketCoe::templateFile($tid)));
// swapping the stored template invalidates it
act($hr);$tp=dirname(__DIR__).'/storage/coe_templates/'.row('SELECT stored_name FROM coe_templates WHERE id=?',[$tid])['stored_name'];$orig=file_get_contents($tp);file_put_contents($tp,$orig.'x');
act($req);$id5=coeId();act($hr);check('a modified stored template is refused at Served',rejects(fn()=>previewAndServe($id5)));file_put_contents($tp,$orig);

// ---------------------------------------------------------------- rollback leaves no orphan file
$before=count(glob(dirname(__DIR__).'/storage/coe_documents/*.docx'));
db()->exec('CREATE TRIGGER coe_fail BEFORE INSERT ON coe_documents FOR EACH ROW SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT="forced"');
try{act($hr);previewAndServe($id5);$failed=false;}catch(Throwable){$failed=true;}
db()->exec('DROP TRIGGER coe_fail');
check('a failure during Served rolls back the ticket and removes the file',$failed&&TicketRepository::find($id5)['status']==='PENDING_HR'&&count(glob(dirname(__DIR__).'/storage/coe_documents/*.docx'))===$before);
previewAndServe($id5);check('and Served works again afterwards',TicketRepository::find($id5)['status']==='COMPLETED');

// ---------------------------------------------------------------- pages render without PHP warnings
$clean=fn(string $h)=>!preg_match('/(Warning|Notice|Deprecated|Fatal error)\s*:/',$h);
act($hr);$id6=(function()use($req){act($req);return coeId();})();act($hr);
ob_start();ticket_coe_preview_page(TicketCoe::preview($id6,false));$html=ob_get_clean();
check('preview page renders with the values and a confirm form',$clean($html)&&str_contains($html,'Confirm Served')&&str_contains($html,'Kate Anne Cadiz'));
ob_start();ticket_coe_template_page(TicketCoe::templates(),TicketCoe::settings(),TicketCoe::activeTemplate());$html=ob_get_clean();
check('template page renders',$clean($html)&&str_contains($html,'Version history')&&str_contains($html,'{{employee_name}}'));
ob_start();ticket_service_detail(TicketRepository::detail($id6));$html=ob_get_clean();
check('detail page shows Preview & Served instead of a direct Served button',$clean($html)&&str_contains($html,'Preview &amp; Served')&&!str_contains($html,'value="COMPLETE"'));
act($req);ob_start();ticket_service_detail(TicketRepository::detail($id1));$html=ob_get_clean();
check('requester detail page shows the download link',$clean($html)&&str_contains($html,'hr-coe-download'));
act($req);ob_start();ticket_new_page(['branch_name'=>'x'],array_values(array_filter(TicketRepository::types(),fn($t)=>$t['code']==='COE_REQUEST')),'k',['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code="COE_REQUEST"')['id']]);$html=ob_get_clean();
check('filing form renders the COE fields',$clean($html)&&str_contains($html,'name="purpose"')&&str_contains($html,'name="needed_by"')&&str_contains($html,'name="delivery"'));

finish();
