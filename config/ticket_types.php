<?php
// Options and routing belong to configuration, never to browser-supplied profiles.
return [
    'NETSUITE_CONCERN'=>['name'=>'NetSuite Concern','profile'=>'HR_ONLY','fields'=>['concern'=>['label'=>'Concern remarks','kind'=>'text','max'=>5000]]],
    'UNIFORM_REQUEST'=>['name'=>'Uniform Request','profile'=>'HR_ONLY','fields'=>[
        'position'=>['label'=>'Position','kind'=>'text','max'=>160],
        'size'=>['label'=>'Size','options'=>['XS','S','M','L','XL','2XL','3XL']],
        'company'=>['label'=>'Company label','options'=>['SW','OFJI']],
        'branch'=>['label'=>'Branch','kind'=>'branch'],
        'quantity'=>['label'=>'Quantity','kind'=>'number','min'=>1,'max_value'=>10],
        'id_lace'=>['label'=>'ID lace','options'=>['YES','NO']],
    ]],
    'ATM_REQUEST'=>['name'=>'ATM Request','profile'=>'HR_ONLY','fields'=>['reason'=>['label'=>'Reason','kind'=>'text','max'=>5000]]],
    'ID_REQUEST'=>['name'=>'ID Request','profile'=>'HR_ONLY','fields'=>['reason'=>['label'=>'Reason','kind'=>'text','max'=>5000]]],
];
