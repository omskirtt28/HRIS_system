<?php
declare(strict_types=1);
require __DIR__.'/ticket_test_support.php';
db()->beginTransaction();
try{
    $f=fixtures();
    // Expected contract independent of TicketPolicy's transition constant.
    $expected=[
        'DRAFT'=>['SUBMITTED','CANCELLED'],'SUBMITTED'=>['PENDING_APPROVAL','PENDING_HR'],
        'PENDING_APPROVAL'=>['PENDING_APPROVAL','PENDING_HR','REJECTED','RETURNED','CANCELLED'],
        'PENDING_HR'=>['IN_PROGRESS','COMPLETED','REJECTED','RETURNED','CANCELLED'],
        'IN_PROGRESS'=>['COMPLETED','REJECTED','RETURNED'],'RETURNED'=>['SUBMITTED','CANCELLED'],
        'COMPLETED'=>[],'REJECTED'=>[],'CANCELLED'=>[],
    ];$states=array_keys($expected);
    foreach($states as $from)foreach($states as $to){
        $allowed=in_array($to,$expected[$from],true);
        check('transition '.$from.' -> '.$to.($allowed?' allowed':' rejected'),rejects(fn()=>TicketPolicy::assertTransition($from,$to,'Test remarks'))!==$allowed);
    }
    check('return requires remarks',rejects(fn()=>TicketPolicy::assertTransition('PENDING_HR','RETURNED',' ')));
    check('reject requires remarks',rejects(fn()=>TicketPolicy::assertTransition('PENDING_APPROVAL','REJECTED')));
    $profiles=db()->query('SELECT code,steps_json FROM ticket_routing_profiles')->fetchAll(PDO::FETCH_KEY_PAIR);
    check('HO routing is config',json_decode($profiles['HO_MANAGER_THEN_HR'],true)===['MANAGER','HR']);
    check('branch routing is config',json_decode($profiles['BRANCH_ADL_THEN_HR'],true)===['ADL','HR']);
    check('HR-only routing is config',json_decode($profiles['HR_ONLY'],true)===['HR']);
    $type=insert('INSERT INTO ticket_types(code,name,routing_profile) VALUES(?,"Test","HR_ONLY")',[$f['tag']]);
    check('type code uppercase enforced',rejects(fn()=>insert('INSERT INTO ticket_types(code,name,routing_profile) VALUES("lowercase","Test","HR_ONLY")')));
    $id=insert('INSERT INTO tickets(ticket_no,ticket_type_id,requester_user_id,subject_employee_id,company_id,branch_id,company_group_snapshot,company_name_snapshot,payload_json,filed_at) VALUES(?,?,?,?,?,?,"SW","Snapshot Company","{}",NOW())',[$f['tag'],$type,$f['employee']['id'],$f['requester'],$f['legal'],$f['ho']]);
    check('immutable ticket number',rejects(fn()=>db()->prepare('UPDATE tickets SET ticket_no="changed" WHERE id=?')->execute([$id])));
    check('immutable filed company snapshot',rejects(fn()=>db()->prepare('UPDATE tickets SET company_name_snapshot="changed" WHERE id=?')->execute([$id])));
    check('illegal status rejected in DB',rejects(fn()=>db()->prepare('UPDATE tickets SET status="APPROVED" WHERE id=?')->execute([$id])));
    $event=insert('INSERT INTO ticket_events(ticket_id,actor_user_id,event_type,to_status) VALUES(?,?,"CREATED","DRAFT")',[$id,$f['employee']['id']]);
    check('BR_116 events cannot update',rejects(fn()=>db()->prepare('UPDATE ticket_events SET event_type="EDITED" WHERE id=?')->execute([$event])));
    check('BR_116 events cannot delete',rejects(fn()=>db()->prepare('DELETE FROM ticket_events WHERE id=?')->execute([$event])));
    $step=insert('INSERT INTO ticket_steps(ticket_id,step_no,approver_type,approver_user_id,routing_profile_snapshot) VALUES(?,1,"MANAGER",?,"HO_MANAGER_THEN_HR")',[$id,$f['manager']['id']]);
    check('frozen approver chain',rejects(fn()=>db()->prepare('UPDATE ticket_steps SET approver_user_id=? WHERE id=?')->execute([$f['adl']['id'],$step])));
    check('approval chain cannot delete',rejects(fn()=>db()->prepare('DELETE FROM ticket_steps WHERE id=?')->execute([$step])));
    check('step status must be uppercase',rejects(fn()=>db()->prepare('UPDATE ticket_steps SET status="approved" WHERE id=?')->execute([$step])));
    db()->prepare('UPDATE ticket_steps SET status="APPROVED",acted_by=?,acted_at=NOW() WHERE id=?')->execute([$f['manager']['id'],$step]);
    check('frozen chain permits decisions',row('SELECT status FROM ticket_steps WHERE id=?',[$step])['status']==='APPROVED');
    insert('INSERT INTO ticket_effects(ticket_id,effect_type,instruction_json,created_by) VALUES(?,"TEST_EFFECT","{}",?)',[$id,$f['hr']['id']]);
    check('effect uniqueness on replay',rejects(fn()=>insert('INSERT INTO ticket_effects(ticket_id,effect_type,instruction_json,created_by) VALUES(?,"TEST_EFFECT","{}",?)',[$id,$f['hr']['id']])));
}finally{db()->rollBack();}
finish();
