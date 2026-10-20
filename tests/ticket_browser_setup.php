<?php
declare(strict_types=1);
require __DIR__.'/ticket_test_support.php';
$f=fixtures();
$f['requester_two']=insert('INSERT INTO employees(employee_no,first_name,last_name,branch_id,legal_entity_id,business_unit_id,status) VALUES(?,"Slice15","Second Target",?,?,?,"ACTIVE")',[$f['tag'].'_second',$f['ho'],$f['legal'],$f['company']]);
file_put_contents(dirname(__DIR__).'/storage/slice15-private/browser-fixtures.json',json_encode($f,JSON_THROW_ON_ERROR));
echo "PASS synthetic browser fixtures created in isolated database\n";
