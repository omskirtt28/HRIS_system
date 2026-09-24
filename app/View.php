<?php
declare(strict_types=1);

function icon_svg(string $name, string $class=''): string {
    $paths = [
        'home'=>'<path d="M3 10.8 12 3l9 7.8"/><path d="M5.5 9.4V21h13V9.4"/><path d="M9.5 21v-6h5v6"/>',
        'users'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user'=>'<path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/>',
        'user-plus'=>'<path d="M15 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
        'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>',
        'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'file'=>'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h6"/>',
        'briefcase'=>'<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18M10 12v2h4v-2"/>',
        'pipeline'=>'<rect x="3" y="4" width="5" height="6" rx="1"/><rect x="10" y="4" width="5" height="6" rx="1"/><rect x="17" y="4" width="4" height="6" rx="1"/><path d="M5.5 10v4h7v-4M12.5 14v4h6.5v-8"/>',
        'check'=>'<path d="m5 12 4 4L19 6"/>',
        'check-square'=>'<rect x="3" y="3" width="18" height="18" rx="2"/><path d="m7 12 3 3 7-7"/>',
        'chart'=>'<path d="M3 3v18h18"/><path d="m7 16 4-5 4 3 5-7"/>',
        'building'=>'<path d="M3 21h18M6 21V5l6-2v18M18 21V9l-6-2"/><path d="M8.5 8h1M8.5 12h1M8.5 16h1M14.5 12h1M14.5 16h1"/>',
        'shield'=>'<path d="M12 22s8-3.5 8-10V5l-8-3-8 3v7c0 6.5 8 10 8 10Z"/><path d="m9 12 2 2 4-5"/>',
        'settings'=>'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.55V21h-4v-.08A1.7 1.7 0 0 0 9 19.37a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.63 15 1.7 1.7 0 0 0 3.08 14H3v-4h.08A1.7 1.7 0 0 0 4.63 9a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.63 1.7 1.7 0 0 0 10 3.08V3h4v.08A1.7 1.7 0 0 0 15 4.63a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.37 9 1.7 1.7 0 0 0 20.92 10H21v4h-.08A1.7 1.7 0 0 0 19.4 15Z"/>',
        'audit'=>'<path d="M9 5h10M9 10h10M9 15h6M5 5h.01M5 10h.01M5 15h.01"/><path d="M4 20h16"/>',
        'search'=>'<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'bell'=>'<path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
        'moon'=>'<path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/>',
        'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
        'chevron'=>'<path d="m9 10 3 3 3-3"/>',
        'logout'=>'<path d="M10 17l5-5-5-5M15 12H3"/><path d="M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/>',
        'alert'=>'<path d="M10.3 3.5 2.5 17a2 2 0 0 0 1.73 3h15.54A2 2 0 0 0 21.5 17L13.7 3.5a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/>',
        'arrow'=>'<path d="M5 12h14M13 6l6 6-6 6"/>',
        'plus'=>'<path d="M12 5v14M5 12h14"/>',
        'book'=>'<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V4H6.5A2.5 2.5 0 0 0 4 6.5v13Z"/><path d="M8 7h8M8 11h6"/>',
        'clipboard'=>'<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4.5V3h6v1.5M9 9h6M9 13h6M9 17h4"/>',
    ];
    $p = $paths[$name] ?? $paths['home'];
    return '<svg class="'.e($class).'" viewBox="0 0 24 24" aria-hidden="true">'.$p.'</svg>';
}

function render_head(string $title): void { ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($title)?> · PMBSI HRIS</title>
<link rel="shortcut icon" href="favicon.ico?v=20260924-full-wordmark" type="image/x-icon">
<link rel="icon" href="favicon.ico?v=20260924-full-wordmark" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="public/assets/branding/favicon-32x32.png?v=20260924-full-wordmark">
<link rel="icon" type="image/png" sizes="16x16" href="public/assets/branding/favicon-16x16.png?v=20260924-full-wordmark">
<link rel="apple-touch-icon" sizes="180x180" href="public/assets/branding/apple-touch-icon.png?v=20260924-full-wordmark">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;750&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="public/assets/app.css"><link rel="stylesheet" href="public/assets/hris-modern.css?v=20260924-login-logo-clean">
<style>.alert{padding:11px 14px;border-radius:10px;margin-bottom:14px;font-size:12.5px}.req{color:var(--red)}.actions-inline{display:flex;gap:6px;flex-wrap:wrap}.nowrap{white-space:nowrap}.empty{padding:36px;text-align:center;color:var(--hris-muted)}form.inline{display:inline}.status-select{min-width:150px}</style>
</head><body><div id="toast" class="toast"></div>
<?php }

function render_flashes(): void { $f=pull_flashes(); if(!$f)return; echo '<div class="flash-wrap">'; foreach($f as $x) echo '<div class="alert '.e($x['type']).'">'.e($x['message']).'</div>'; echo '</div>'; }

function render_public_header(string $active=''): void { ?>
<nav class="pubnav"><div class="in">
<a href="<?=url('home')?>" class="brand brand-official brand-official-public" aria-label="Prime Mover Business Solutions, Inc."><img src="public/assets/branding/pmbsi-logo.png" alt="Prime Mover Business Solutions, Inc." class="brand-full-logo"></a>
<div class="links">
<?php foreach([['Home','home'],['Careers','careers'],['About Us','about'],['Contact','contact']] as [$label,$page]): ?>
<a href="<?=url($page)?>" class="<?=$active===$page?'active':''?>"><?=e($label)?></a>
<?php endforeach; ?>
<a href="<?=url('track')?>">Track Application</a>
</div><a href="<?=url('login')?>" class="btn sm" style="margin-left:8px">Employee Login</a><a href="<?=url('apply')?>" class="btn primary sm">Apply Now</a>
</div></nav><?php render_flashes(); }

function render_public_footer(): void { ?>
<footer class="foot"><div class="in">
<div><div class="brand brand-official brand-official-dark"><img src="public/assets/branding/pmbsi-logo.png" alt="Prime Mover Business Solutions, Inc." class="brand-full-logo"></div><p style="max-width:280px;margin-top:12px">Connecting Filipino talent with meaningful careers through trusted workforce management.</p></div>
<div><div style="color:#fff;font-weight:600;margin-bottom:8px">Careers</div><a href="<?=url('careers')?>">Browse jobs</a><a href="<?=url('apply')?>">Apply now</a><a href="<?=url('track')?>">Track application</a></div>
<div><div style="color:#fff;font-weight:600;margin-bottom:8px">Company</div><a href="<?=url('about')?>">About us</a><a href="<?=url('contact')?>">Contact</a></div>
<div><div style="color:#fff;font-weight:600;margin-bottom:8px">HRIS</div><a href="<?=url('login')?>">Employee / HR Login</a><a href="<?=url('login',['portal'=>'client'])?>">Client Portal</a><a href="<?=url('login',['portal'=>'admin'])?>">Administration</a></div>
</div><div class="in" style="border-top:1px solid #333;margin-top:28px;padding-top:18px;font-size:12px">© 2026 Prime Mover Business Solutions, Inc. · HRIS</div></footer>
<script src="public/assets/hris-app.js"></script></body></html><?php }

function nav_config(string $kind): array {
    $base=[
      'employee'=>['title'=>'PMBSI HRIS','sub'=>'Employee Self-Service','groups'=>[
        ['Overview',[['Home','employee-dashboard','home',false,'dashboard.employee.view']]],
        ['My Workspace',[['My Profile','#','user',true,'employees.view_self'],['Attendance','#','clock',true,'attendance.view_self'],['Leave','#','calendar',true,'leave.view_self'],['Requests','#','clipboard',true,'requests.request_self']]],
        ['Growth',[['Performance','#','chart',true,null],['Training','#','book',true,null]]]
      ]],
      'hr'=>['title'=>'PMBSI HRIS','sub'=>'Human Resources','groups'=>[
        ['Overview',[['Dashboard','hr-dashboard','home',false,'dashboard.hr.view']]],
        ['People',[['Employees','#','users',true,'employees.view_all'],['Attendance','#','clock',true,'attendance.view_all'],['Leave','#','calendar',true,'leave.manage'],['HR Requests','#','clipboard',true,'requests.manage']]],
        ['Recruitment',[['Manpower Requests','hr-manpower','briefcase',false,'recruitment.view'],['Applicants','hr-applicants','user-plus',false,'recruitment.view'],['Pipeline','hr-pipeline','pipeline',false,'recruitment.view'],['Endorsements','hr-endorsements','check-square',false,'recruitment.view']]],
        ['HR Operations',[['Employee Relations','#','shield',true,null],['Performance','#','chart',true,null],['Training','#','book',true,null],['Offboarding','#','file',true,null]]],
        ['Insights',[['Reports','hr-reports','chart',false,'reports.view']]]
      ]],
      'client'=>['title'=>'PMBSI HRIS','sub'=>Auth::user()['client_name'] ?? 'Client Portal','groups'=>[
        ['Workspace',[['Dashboard','client-dashboard','home',false,null],['Candidates','client-candidates','users',false,null],['Approvals','client-approvals','check-square',false,null]]],
        ['Account',[['Reports','client-reports','chart',false,null]]]
      ]],
      'admin'=>['title'=>'PMBSI HRIS','sub'=>'HR Administration','groups'=>[
        ['Overview',[['Dashboard','admin-dashboard','home',false,'dashboard.admin.view']]],
        ['Access Control',[['Users','admin-users','users',false,'users.view'],['Roles & Permissions','admin-roles','shield',false,'roles.view'],['Security & Sessions','admin-security','shield',false,'audit.view']]],
        ['Organization',[['Organization Setup','admin-organization','building',false,'organization.view']]],
        ['Operations',[['Audit Logs','admin-audit','audit',false,'audit.view']]]
      ]]
    ];
    return $base[$kind] ?? $base['employee'];
}

function render_portal_header(string $kind,string $active,string $title): void {
    $c=nav_config($kind); $u=Auth::user() ?? ['name'=>'User','role_name'=>'Account']; render_head($title);
    $search=['employee'=>'Search your HRIS…','hr'=>'Search employees, applicants, requests…','client'=>'Search candidates…','admin'=>'Search users, branches, settings…'][$kind] ?? 'Search HRIS…'; ?>
<div class="shell"><aside class="side" id="side"><a href="<?=url($kind.'-dashboard')?>" class="brand brand-portal"><img src="public/assets/branding/pmbsi-mark.png" alt="" class="brand-mark"><span><?=e($c['title'])?><span class="sub"><?=e($c['sub'])?></span></span></a>
<div class="navs"><?php foreach($c['groups'] as [$group,$items]): ?><div class="nav-group"><div class="gl"><?=e($group)?></div><?php foreach($items as $item): [$label,$page,$icon,$disabled,$permission]=array_pad($item,5,null); if($permission && !Auth::can($permission)) continue; ?>
<a href="<?=$disabled?'#':url($page)?>" class="nav-item <?=$active===$page?'active':''?> <?=$disabled?'disabled':''?>"><span class="ni"><?=icon_svg($icon)?></span><span><?=e($label)?></span><?=$disabled?'<span class="soon">Soon</span>':''?></a>
<?php endforeach;?></div><?php endforeach;?></div>
<div class="prof"><span class="avatar"><?=e(initials($u['name']))?></span><div style="min-width:0"><div class="small" style="font-weight:650;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#f2f4f7"><?=e($u['name'])?></div><div class="tiny muted"><?=e($u['role_name'])?></div></div></div></aside>
<div class="main"><div class="topbar"><button class="iconbtn mobtoggle" onclick="toggleSidebar()" aria-label="Open navigation"><?=icon_svg('menu')?></button><div class="search"><?=icon_svg('search')?><input placeholder="<?=e($search)?>"></div>
<div class="topbar-actions"><div class="topbar-date" id="manilaClock"><strong>Philippine Time</strong>Loading…</div><button class="iconbtn notify-btn" type="button" aria-label="Notifications"><?=icon_svg('bell')?></button><button class="iconbtn" type="button" onclick="toggleTheme()" aria-label="Toggle theme"><?=icon_svg('moon')?></button>
<div class="profile-menu"><button class="profile-trigger" type="button" onclick="toggleProfileMenu()"><span class="avatar"><?=e(initials($u['name']))?></span><span class="ptxt"><strong><?=e($u['name'])?></strong><span><?=e($u['role_name'])?></span></span><?=icon_svg('chevron')?></button><div class="profile-pop" id="profilePop"><a href="#"><?=icon_svg('user')?>Profile</a><button type="button" onclick="toggleTheme()"><?=icon_svg('moon')?>Appearance</button><a href="<?=url('logout')?>"><?=icon_svg('logout')?>Sign out</a></div></div></div></div><main class="content"><?php render_flashes(); }

function render_portal_footer(): void { ?></main></div></div><script src="public/assets/hris-app.js"></script></body></html><?php }
function page_head(string $crumb,string $title,string $actions=''): void { ?><div class="pagehead"><div><div class="crumb"><?=e($crumb)?></div><h1><?=e($title)?></h1></div><?php if($actions):?><div class="actions"><?=$actions?></div><?php endif;?></div><?php }
function dashboard_hero(string $eyebrow,string $title,string $subtitle,string $actions=''): void { ?><div class="dashboard-hero"><div><div class="eyebrow"><?=e($eyebrow)?></div><h1><?=e($title)?></h1><p><?=e($subtitle)?></p></div><?php if($actions):?><div class="hero-actions"><?=$actions?></div><?php endif;?></div><?php }
function metric_card(string $label,string|int $value,string $foot='',string $icon='chart',string $tone=''): void { ?><div class="metric-card"><div class="metric-top"><div class="metric-label"><?=e($label)?></div><div class="metric-icon"><?=icon_svg($icon)?></div></div><div class="metric-value"><?=e((string)$value)?></div><?php if($foot):?><div class="metric-foot"><span class="<?=e($tone)?>"><?=e($foot)?></span></div><?php endif;?></div><?php }
function attention_item(string $title,string $subtitle,string|int $count,string $tone='warn',string $icon='alert'): void { ?><div class="attention-item"><div class="attention-icon <?=e($tone)?>"><?=icon_svg($icon)?></div><div class="txt"><strong><?=e($title)?></strong><span><?=e($subtitle)?></span></div><div class="count"><?=e((string)$count)?></div></div><?php }
function kpi_card(string $label,string|int $value,string $delta='',string $ico='chart'): void { ?><div class="kpi"><span class="ico"><?=icon_svg(in_array($ico,['home','users','user','user-plus','calendar','clock','file','briefcase','pipeline','check','check-square','chart','building','shield','settings','audit','clipboard','book'],true)?$ico:'chart')?></span><div class="lab"><?=e($label)?></div><div class="val"><?=e((string)$value)?></div><?php if($delta):?><div class="delta up"><?=e($delta)?></div><?php endif;?></div><?php }
function job_card(array $j): void { ?><a href="<?=url('job',['slug'=>$j['slug']])?>" class="card jobcard"><div style="display:flex;justify-content:space-between;align-items:flex-start"><span class="badge amber"><?=e($j['department_name'] ?: 'General')?></span><span class="tiny muted"><?=e($j['published_at']?date('M j',strtotime($j['published_at'])):'')?></span></div><h3 style="font-size:17px"><?=e($j['title'])?></h3><div class="small muted"><?=e($j['client_name'])?></div><div class="small" style="display:flex;gap:14px;flex-wrap:wrap"><span class="muted">📍 <?=e($j['location_text'])?></span><span class="muted">🕑 <?=e(stage_label($j['employment_type']))?></span></div><div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;padding-top:12px;border-top:1px solid var(--border)"><span class="mono small" style="font-weight:600"><?=e(money($j['salary_min']!==null?(float)$j['salary_min']:null,$j['salary_max']!==null?(float)$j['salary_max']:null))?></span><span class="badge green"><?=e((string)$j['openings'])?> openings</span></div></a><?php }
