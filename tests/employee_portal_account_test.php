<?php
declare(strict_types=1);
// Employee portal account (SPEC-employee-portal-account.md). Synthetic data only; run with HRIS_TEST_DB on a copy with migration 020 applied.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/EmployeeAccountService.php';
$f=fixtures();$tag=$f['tag'];
$GLOBALS['config']['security']['credential_key']=base64_encode(random_bytes(32));

// --- T2 generator, email, encryption -------------------------------------------------
$seen=[];$okShape=true;
for($i=0;$i<500;$i++){$p=EmployeeAccountService::generatePassword();$seen[$p]=1;
    if(strlen($p)!==12||!preg_match('/[a-z]/',$p)||!preg_match('/[A-Z]/',$p)||!preg_match('/[2-9]/',$p)||preg_match('/[01OIl]/',$p))$okShape=false;}
check('generated password: 12 chars, upper+lower+digit, no look-alikes',$okShape);
check('generated passwords do not repeat in 500 runs',count($seen)===500);
check('generated password passes the existing policy',strlen($p)>=10&&preg_match('/[A-Za-z]/',$p)&&preg_match('/[0-9]/',$p));

check('email local part: plain name',EmployeeAccountService::localPart('Juan','Dela Cruz')==='juandelacruz');
check('email local part: accents and punctuation folded',EmployeeAccountService::localPart('José Ma.','Peña-Cruz')==='josemapenacruz');
check('email local part: suffix is not part of last name',EmployeeAccountService::localPart('Nelson','Tambal Jr.')==='nelsontambal');
check('email local part: empty after cleaning is rejected',rejects(fn()=>EmployeeAccountService::localPart('...','  ')));
check('domain: PMBSI',EmployeeAccountService::domainFor('PMBSI')==='pmbsi.com');
check('domain: 2LOG',EmployeeAccountService::domainFor('2LOG')==='2log.com');
check('domain: unknown company is rejected',rejects(fn()=>EmployeeAccountService::domainFor('NOPE')));
check('domain: missing company is rejected',rejects(fn()=>EmployeeAccountService::domainFor(null)));

$enc=EmployeeAccountService::encrypt('Abc-Test-Only-9x');
check('encrypted copy is not plaintext',!str_contains($enc,'Abc-Test'));
check('encrypt/decrypt round trip',EmployeeAccountService::decrypt($enc)==='Abc-Test-Only-9x');
check('two encryptions of one value differ (random IV)',EmployeeAccountService::encrypt('Abc-Test-Only-9x')!==$enc);
$tampered=substr($enc,0,-4).(substr($enc,-4)==='AAAA'?'BBBB':'AAAA');
check('tampered ciphertext is rejected (null)',EmployeeAccountService::decrypt($tampered)===null);
check('decrypt of null or garbage is null',EmployeeAccountService::decrypt(null)===null&&EmployeeAccountService::decrypt('not-base64!!')===null);
$good=$GLOBALS['config']['security']['credential_key'];
$GLOBALS['config']['security']['credential_key']=base64_encode(random_bytes(32));
check('wrong key cannot decrypt',EmployeeAccountService::decrypt($enc)===null);
$GLOBALS['config']['security']['credential_key']='';
check('missing key fails closed on encrypt',rejects(fn()=>EmployeeAccountService::encrypt('x')));
$GLOBALS['config']['security']['credential_key']='short';
check('short key fails closed on encrypt',rejects(fn()=>EmployeeAccountService::encrypt('x')));
$GLOBALS['config']['security']['credential_key']=$good;

finish();
