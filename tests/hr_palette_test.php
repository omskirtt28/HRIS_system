<?php
declare(strict_types=1);
// HR appearance palettes (SPEC-hr-appearance-palettes.md, Task 1). Synthetic data only; run with HRIS_TEST_DB on a scratch copy of pmbsi_hris.
require __DIR__.'/ticket_test_support.php';
require_once dirname(__DIR__).'/app/UserPalette.php';
$f=fixtures();
$stored=fn(int $id)=>row('SELECT ui_palette FROM users WHERE id=?',[$id])['ui_palette']??null;

check('the column exists and every new user defaults to light',$stored($f['hr']['id'])==='light'&&$stored($f['employee']['id'])==='light');
check('the allowed values are light, dark, beige, earth, pastel and pink in that order',UserPalette::KEYS===['light','dark','beige','earth','pastel','pink']);
foreach(UserPalette::KEYS as $k) check("$k is valid",UserPalette::isValid($k));
foreach(['','Light','BEIGE','gradient','beige ','<script>','light;DROP'] as $bad) check('"'.$bad.'" is not valid',!UserPalette::isValid($bad));

check('the HR portal role may choose a palette',UserPalette::canChoose($f['hr']));
check('an employee-portal role may not',!UserPalette::canChoose($f['employee'])&&!UserPalette::canChoose($f['manager']));
check('Admin may not (HR portal only)',!UserPalette::canChoose(['role_portal'=>'admin','role_code'=>'SUPER_ADMIN']));
check('a missing user or role_portal may not',!UserPalette::canChoose([])&&!UserPalette::canChoose(['role_portal'=>null]));

check('HR saves a valid palette and it reads back',UserPalette::save($f['hr'],'beige')&&$stored($f['hr']['id'])==='beige');
check('HR can switch to dark and back to light',UserPalette::save($f['hr'],'dark')&&$stored($f['hr']['id'])==='dark'&&UserPalette::save($f['hr'],'light')&&$stored($f['hr']['id'])==='light');
check('an unknown value is refused and nothing changes',!UserPalette::save($f['hr'],'gradient')&&$stored($f['hr']['id'])==='light');
UserPalette::save($f['hr'],'earth');
check('a non-HR user cannot save: refused and the row is unchanged',!UserPalette::save($f['employee'],'pastel')&&$stored($f['employee']['id'])==='light');
check('saving for one user never touches another user',$stored($f['hr']['id'])==='earth'&&$stored($f['manager']['id'])==='light'&&$stored($f['outsider']['id'])==='light');
check('read() returns the saved value for HR',UserPalette::read(['id'=>$f['hr']['id'],'role_portal'=>'hr'])==='earth');
check('read() is empty for a non-HR user even if a value is stored',(function() use($f){db()->prepare('UPDATE users SET ui_palette="pastel" WHERE id=?')->execute([$f['employee']['id']]);return UserPalette::read(['id'=>$f['employee']['id'],'role_portal'=>'employee'])==='';})());
check('read() falls back to empty when the stored value is not on the list',(function() use($f){db()->prepare('UPDATE users SET ui_palette="neon" WHERE id=?')->execute([$f['hr']['id']]);return UserPalette::read(['id'=>$f['hr']['id'],'role_portal'=>'hr'])==='';})());
check("htmlAttrs: a palette sets Light base plus data-palette and marks the HR user",UserPalette::htmlAttrs("beige")===" data-theme=\"light\" data-palette=\"beige\" data-palette-user");
check("htmlAttrs: Soft Pink sets Light base plus data-palette pink",UserPalette::htmlAttrs("pink")===" data-theme=\"light\" data-palette=\"pink\" data-palette-user");
check("htmlAttrs: Dark sets data-theme dark only",UserPalette::htmlAttrs("dark")===" data-theme=\"dark\" data-palette-user");
check("htmlAttrs: Light, empty and unknown values all fall back to Light with no palette",UserPalette::htmlAttrs("light")===" data-theme=\"light\" data-palette-user"&&UserPalette::htmlAttrs("")===UserPalette::htmlAttrs("light")&&UserPalette::htmlAttrs("neon\"><script>")===UserPalette::htmlAttrs("light"));
check("every palette has an English label",array_keys(UserPalette::LABELS)===UserPalette::KEYS&&UserPalette::LABELS["beige"]==="Soft Beige"&&UserPalette::LABELS["earth"]==="Earth Tones"&&UserPalette::LABELS["pastel"]==="Soft Pastel"&&UserPalette::LABELS["pink"]==="Soft Pink");
finish();
