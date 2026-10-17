<?php
// Dry-run: which Ocampo's Head Office employees could go to which new section. READ-ONLY, assigns nothing, prints no names.
//   C:\xampp\php\php.exe scripts\oc_head_office_section_dryrun.php
// Suggestions follow the position only (Goldsmith, Production Admin, Stockcontrol/Production Staff -> Ocampos Production).
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'app/bootstrap.php';

if(!DirectorySectionService::ready()) { fwrite(STDERR,"Import database/migrations/20261017_oc_head_office_sections.sql first.\n"); exit(1); }
$rows=DirectorySectionService::suggestions();
printf("%-6s %-10s %-24s %-32s %s\n",'ID','Emp No','Department','Position','Suggested section');
foreach($rows as $r) printf("%-6d %-10s %-24s %-32s %s\n",$r['employee_id'],(string)$r['employee_no'],(string)$r['department'],(string)$r['position'],$r['suggested_section'] ?? '- (no suggestion)');
echo count($rows)." employee(s) without a section; ".count(array_filter($rows,static fn($r)=>$r['suggested_code']))." with a suggestion. Nothing was changed.\n";
