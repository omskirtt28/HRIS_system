<?php
// Options and routing belong to configuration, never to browser-supplied profiles.
return [
    'ATM_REQUEST'=>['name'=>'ATM Request','profile'=>'HR_ONLY','fields'=>['reason'=>['label'=>'Reason','kind'=>'text','max'=>5000]]],
    'ID_REQUEST'=>['name'=>'ID Request','profile'=>'HR_ONLY','fields'=>['reason'=>['label'=>'Reason','kind'=>'text','max'=>5000]]],
];
