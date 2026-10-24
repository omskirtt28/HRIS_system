<?php
// Options and routing belong to configuration, never to browser-supplied profiles.
return [
    'COE_REQUEST'=>['name'=>'Certificate of Employment','profile'=>'HR_ONLY','fields'=>[
        'purpose'=>['label'=>'Purpose','options'=>['Loan','Visa/Embassy','Bank','New employer','Other']],
        'purpose_other'=>['label'=>'Purpose details (required when Other)','kind'=>'text','max'=>200,'optional'=>true,'rows'=>2],
        'needed_by'=>['label'=>'Needed by','kind'=>'date'],
        'delivery'=>['label'=>'Delivery','options'=>['HR pickup','Email']],
    ]],
    'INCIDENT_REPORT'=>['name'=>'Incident Report','profile'=>null,'fields'=>[
        'title'=>['label'=>'Incident title','kind'=>'text','max'=>200],
        'explanation'=>['label'=>'What happened','kind'=>'text','max'=>5000],
        'incident_at'=>['label'=>'When it happened','kind'=>'datetime-local'],
        'location'=>['label'=>'Where it happened','kind'=>'text','max'=>200,'rows'=>2],
        'category'=>['label'=>'Category','options'=>['Workplace conduct','Harassment or discrimination','Safety and health','Property or equipment','Attendance-related','Other']],
    ]],
    'BRANCH_TRANSFER'=>['name'=>'Branch Transfer','profile'=>'HR_ONLY','fields'=>[
        'employee'=>['label'=>'Employee','kind'=>'employee'],
        'from_branch'=>['label'=>'From branch','kind'=>'branch'],
        'to_branch'=>['label'=>'To branch','kind'=>'branch'],
        'effective_date'=>['label'=>'Effective date','kind'=>'date'],
        'company'=>['label'=>'Company label','options'=>['SW','OFJI']],
    ]],
    'OIC_AUTHORIZATION'=>['name'=>'OIC Authorization','profile'=>'HR_ONLY','fields'=>[
        'employee'=>['label'=>'Proposed OIC (employee)','kind'=>'employee'],
        'branch'=>['label'=>'Branch','kind'=>'branch'],
        'reason'=>['label'=>'Reason','kind'=>'text','max'=>2000],
        'start_date'=>['label'=>'Start date','kind'=>'date'],
        'end_date'=>['label'=>'End date','kind'=>'date'],
    ]],
    'MANPOWER_REQUEST'=>['name'=>'Manpower Request','profile'=>'HR_ONLY','fields'=>[
        'section_branch'=>['label'=>'Section / Branch','kind'=>'text','max'=>160],
        'position_code'=>['label'=>'Position code','options'=>['BS','SC','VS']],
        'employment_type'=>['label'=>'Employment type','options'=>['Seasonal','Probationary']],
        'request_type'=>['label'=>'Manpower request type','options'=>['Replacement','Additional']],
        'quantity'=>['label'=>'Quantity','kind'=>'number','min'=>1,'max_value'=>2147483647],
        'replacement_of_employee_id'=>['label'=>'Replacement of employee','kind'=>'employee','optional'=>true],
        'job_description_qualifications'=>['label'=>'Job description / Qualifications','kind'=>'text','max'=>5000],
        'area_department'=>['label'=>'Area / Department','kind'=>'text','max'=>160],
    ]],
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
