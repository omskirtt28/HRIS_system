<?php
declare(strict_types=1);
// Pure checks only; no app bootstrap or database. Authored here, not executed under syntax-only rules.
require_once dirname(__DIR__).'/app/HrDashboard.php';
function hd_expect(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message); }

// 1. Defaults, malformed inputs, Clear and a mode switch.
$p=HrDashboard::filters([]);
hd_expect($p['mode']==='monthly'&&$p['periods']===range(1,12),'Default Monthly / All');
$p=HrDashboard::filters(['company'=>['OC'],'mode'=>['monthly'],'year'=>['2026'],'selection'=>1,'periods'=>['1','1','3','13',[],"1 OR 1=1"]]);
hd_expect($p['company']==='ALL'&&$p['mode']==='monthly'&&$p['periods']===[1,3],'Reject malformed filters and deduplicate');
$p=HrDashboard::filters(['selection'=>1]);
hd_expect(HrDashboard::selectedWindow($p)['ranges']===[],'Clear keeps an empty selection');
$p=HrDashboard::filters(['mode'=>'quarterly','selection_mode'=>'monthly','selection'=>1,'periods'=>[1,3,12]]);
hd_expect($p['periods']===[1,2,3,4],'Mode switch resets incompatible selections');

// 2. Disjoint months, partial current periods and leap-year comparisons.
$p=['mode'=>'monthly','year'=>2024,'periods'=>[1,3]];
$w=HrDashboard::selectedWindow($p,new DateTimeImmutable('2024-03-15'));
hd_expect($w['ranges']===[['2024-01-01','2024-02-01'],['2024-03-01','2024-03-16']],'Do not include February or future dates');
hd_expect($w['previous']===[['2023-01-01','2023-02-01'],['2023-03-01','2023-03-16']],'Same dates last year');
$p['periods']=[2];
$w=HrDashboard::selectedWindow($p,new DateTimeImmutable('2024-02-29'));
hd_expect($w['previous']===[['2023-02-01','2023-03-01']],'Leap day clamps to February');
$p['year']=2025;
$w=HrDashboard::selectedWindow($p,new DateTimeImmutable('2025-03-10'));
hd_expect($w['previous']===[['2024-02-01','2024-03-01']],'Complete February includes prior leap day');

// 3. Quarterly/yearly ranges, future-only selection and export parity by source.
$w=HrDashboard::selectedWindow(['mode'=>'quarterly','year'=>2026,'periods'=>[1,3]],new DateTimeImmutable('2026-11-06'));
hd_expect($w['ranges']===[['2026-01-01','2026-04-01'],['2026-07-01','2026-10-01']],'Disjoint quarters');
$w=HrDashboard::selectedWindow(['mode'=>'yearly','year'=>2026,'periods'=>[2024,2026]],new DateTimeImmutable('2026-11-06'));
hd_expect($w['ranges']===[['2024-01-01','2025-01-01'],['2026-01-01','2026-11-07']],'Disjoint years');
$w=HrDashboard::selectedWindow(['mode'=>'monthly','year'=>2026,'periods'=>[12]],new DateTimeImmutable('2026-11-06'));
hd_expect($w['ranges']===[],'Future-only periods have no activity');
$source=(string)file_get_contents(dirname(__DIR__).'/app/HrDashboardViews.php');
hd_expect(strpos($source,'HrDashboard::data($p)')<strpos($source,"['download']")&&str_contains($source,'name="download" value="summary"'),'Download shares validated dashboard filters');
