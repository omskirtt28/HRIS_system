<?php
declare(strict_types=1);
// Seeds synthetic fixtures for coe_browser.cjs in the isolated database. Never run on the configured database.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/View.php';
require_once dirname(__DIR__).'/app/TicketService.php';
$f=fixtures();
function perm(int $role,string $code):void{$p=row('SELECT id FROM permissions WHERE code=?',[$code]);if($p)db()->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) VALUES(?,?)')->execute([$role,$p['id']]);}
perm($f['employee']['role_id'],'ticket.file.coe');perm($f['hr']['role_id'],'ticket.coe.template.manage');perm($f['outsider']['role_id'],'ticket.file.coe');
$pos=insert('INSERT INTO positions(code,name) VALUES(?,?)',[$f['tag'].'_P','Accounting Clerk']);
db()->prepare('UPDATE employees SET position_id=?,first_name="Kate Anne",last_name="Cadiz",hire_date="2021-03-15" WHERE id=?')->execute([$pos,$f['requester']]);
function form(string $code,array $extra=[]):array{return array_replace(incident_fields($code),['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code=?',[$code])['id'],'submission_key'=>bin2hex(random_bytes(32))],$extra);}
$base=['purpose'=>'Bank','purpose_other'=>'','needed_by'=>date('Y-m-d',strtotime('+5 days')),'delivery'=>'Email'];
act($f['employee']);$f['pending']=TicketService::create(form('COE_REQUEST',$base),true);
act($f['outsider']);$f['other']=TicketService::create(form('COE_REQUEST',array_replace($base,['purpose'=>'Other','purpose_other'=>'Embassy <b>visa</b> & loan'])),true);
// A valid sample template and a fake one for the upload checks.
$W='http://schemas.openxmlformats.org/wordprocessingml/2006/main';$dir=dirname(__DIR__).'/storage/slice15-private';
$z=new ZipArchive();$z->open($dir.'/coe-sample.docx',ZipArchive::CREATE|ZipArchive::OVERWRITE);
$z->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
$z->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
$z->addFromString('word/document.xml','<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="'.$W.'"><w:body><w:p><w:r><w:t>Sample letterhead</w:t></w:r></w:p><w:p><w:r><w:t>{{employee_na</w:t></w:r><w:r><w:t>me}}, {{position}}, {{hire_date}}, {{company}}, {{purpose}}, {{date_issued}}</w:t></w:r></w:p></w:body></w:document>');
$z->close();
file_put_contents($dir.'/coe-fake.docx','this is not a word document');
$f['sample']=$dir.'/coe-sample.docx';$f['fake']=$dir.'/coe-fake.docx';
file_put_contents($dir.'/coe-fixtures.json',json_encode($f,JSON_THROW_ON_ERROR));
echo "PASS COE browser fixtures created\n";
