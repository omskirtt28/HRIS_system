<?php
declare(strict_types=1);
// Fixture for rec_manpower_intake_http_test.php: files three synthetic Manpower Request tickets as a fixture employee on the scratch DB and prints their ids as JSON.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/TicketService.php';
$f=fixtures();act($f['employee']);$ids=[];
foreach([['SC','3'],['BS','1'],['VS','2']] as [$position,$qty]){
    $ids[]=TicketService::create(array_replace(incident_fields('MANPOWER_REQUEST'),['ticket_type_id'=>(int)row('SELECT id FROM ticket_types WHERE code="MANPOWER_REQUEST"')['id'],'submission_key'=>bin2hex(random_bytes(32)),
        'section_branch'=>'Synthetic section','position_code'=>$position,'employment_type'=>'Probationary','request_type'=>'Additional','quantity'=>$qty,'job_description_qualifications'=>'Synthetic qualifications','area_department'=>'Synthetic department']),true);
}
echo json_encode($ids);
