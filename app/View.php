<?php
declare(strict_types=1);

function render_head(string $title): void { ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($title)?> · PMBSI HRIS</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="public/assets/app.css"><style>.alert{padding:11px 14px;border-radius:9px;margin-bottom:14px;font-size:13px}.alert.success{background:var(--green-soft);color:var(--green)}.alert.error{background:var(--red-soft);color:var(--red)}.alert.info{background:var(--blue-soft);color:var(--blue)}.req{color:var(--red)}.actions-inline{display:flex;gap:6px;flex-wrap:wrap}.nowrap{white-space:nowrap}.empty{padding:36px;text-align:center;color:var(--slate)}form.inline{display:inline}.status-select{min-width:150px}.flash-wrap{max-width:1140px;margin:14px auto 0;padding:0 24px}</style>
</head><body><div id="toast" class="toast"></div>
<?php }
function render_flashes(): void { $f=pull_flashes(); if(!$f)return; echo '<div class="flash-wrap">'; foreach($f as $x) echo '<div class="alert '.e($x['type']).'">'.e($x['message']).'</div>'; echo '</div>'; }
function render_public_header(string $active=''): void { ?>
<nav class="pubnav"><div class="in">
<a href="<?=url('home')?>" class="brand"><span class="logo">P</span><span>PMBSI<span class="sub">Careers</span></span></a>
<div class="links">
<?php foreach([['Home','home'],['Careers','careers'],['Track Application','track'],['About Us','about'],['Contact','contact']] as [$label,$page]): ?>
<a href="<?=url($page)?>" class="<?=$active===$page?'active':''?>"><?=e($label)?></a>
<?php endforeach; ?>
</div><a href="<?=url('apply')?>" class="btn primary sm" style="margin-left:8px">Apply Now</a>
</div></nav><?php render_flashes(); }
function render_public_footer(): void { ?>
<footer class="foot"><div class="in">
<div><div class="brand" style="color:#fff"><span class="logo">P</span><span style="color:#fff">PMBSI<span class="sub" style="color:#9A9DA0">Prime Mover Business Solutions</span></span></div><p style="max-width:280px;margin-top:12px">Connecting Filipino talent with meaningful careers through trusted workforce management.</p></div>
<div><div style="color:#fff;font-weight:600;margin-bottom:8px">Careers</div><a href="<?=url('careers')?>">Browse jobs</a><a href="<?=url('apply')?>">Apply now</a><a href="<?=url('track')?>">Track application</a></div>
<div><div style="color:#fff;font-weight:600;margin-bottom:8px">Company</div><a href="<?=url('about')?>">About us</a><a href="<?=url('contact')?>">Contact</a></div>
<div><div style="color:#fff;font-weight:600;margin-bottom:8px">Portals</div><a href="<?=url('login',['portal'=>'hr'])?>">HR Portal</a><a href="<?=url('login',['portal'=>'client'])?>">Client Portal</a><a href="<?=url('login',['portal'=>'admin'])?>">Admin</a></div>
</div><div class="in" style="border-top:1px solid #333;margin-top:28px;padding-top:18px;font-size:12px">© 2026 Prime Mover Business Solutions, Inc. · HRIS Recruitment</div></footer>
<script>function toast(m){const t=document.getElementById('toast');t.textContent=m;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2200)}function toggleTheme(){const r=document.documentElement;r.setAttribute('data-theme',r.getAttribute('data-theme')==='dark'?'light':'dark')}</script></body></html><?php }
function nav_config(string $kind): array {
    $base=[
      'hr'=>['title'=>'HR Portal','sub'=>'Recruitment Team','groups'=>[
        ['Workspace',[['Dashboard','hr-dashboard','▤'],['Manpower Requests','hr-manpower','▣'],['Applicants','hr-applicants','👤'],['Pipeline','hr-pipeline','▦'],['Endorsements','hr-endorsements','✓']]],
        ['Insights',[['Reports','hr-reports','▧']]]]],
      'client'=>['title'=>'Client Portal','sub'=>Auth::user()['client_name'] ?? 'Client','groups'=>[
        ['Workspace',[['Dashboard','client-dashboard','▤'],['Candidates','client-candidates','👤'],['Approvals','client-approvals','✓']]],
        ['Account',[['Reports','client-reports','▧']]]]],
      'admin'=>['title'=>'Super Admin','sub'=>'System Administration','groups'=>[
        ['Overview',[['Dashboard','admin-dashboard','▤']]],['Access Control',[['Users','admin-users','👤'],['Security','admin-security','🛡']]],['Organization',[['Clients','admin-clients','▣'],['Branches','admin-branches','◈']]],['Configuration',[['Recruitment Config','admin-recruitment-config','⚙']]],['Operations',[['Audit Logs','admin-audit','▤']]]]]
    ]; return $base[$kind];
}
function render_portal_header(string $kind,string $active,string $title): void {
    $c=nav_config($kind); $u=Auth::user(); render_head($title); ?>
<div class="shell"><aside class="side" id="side"><a href="<?=url($kind.'-dashboard')?>" class="brand"><span class="logo <?=$kind==='client'?'':'dark'?>">P</span><span><?=e($c['title'])?><span class="sub"><?=e($c['sub'])?></span></span></a>
<div class="navs"><?php foreach($c['groups'] as [$group,$items]): ?><div class="nav-group"><div class="gl"><?=e($group)?></div><?php foreach($items as [$label,$page,$icon]): ?><a href="<?=url($page)?>" class="nav-item <?=$active===$page?'active':''?>"><span class="ni"><?=e($icon)?></span><span><?=e($label)?></span></a><?php endforeach;?></div><?php endforeach;?></div>
<div class="prof"><span class="avatar"><?=e(initials($u['name']))?></span><div style="min-width:0"><div class="small" style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=e($u['name'])?></div><div class="tiny muted"><?=e($u['role_name'])?></div></div></div></aside>
<div class="main"><div class="topbar"><button class="iconbtn mobtoggle" onclick="document.getElementById('side').classList.toggle('open')">☰</button><div class="search">🔍<input placeholder="Search recruitment…"></div><div style="margin-left:auto;display:flex;gap:9px;align-items:center"><button class="iconbtn" onclick="toggleTheme()">◐</button><span class="avatar"><?=e(initials($u['name']))?></span><a href="<?=url('logout')?>" class="btn sm">Sign out</a></div></div><main class="content"><?php render_flashes(); }
function render_portal_footer(): void { ?></main></div></div><script>function toast(m){const t=document.getElementById('toast');t.textContent=m;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2200)}function toggleTheme(){const r=document.documentElement;r.setAttribute('data-theme',r.getAttribute('data-theme')==='dark'?'light':'dark')}</script></body></html><?php }
function page_head(string $crumb,string $title,string $actions=''): void { ?><div class="pagehead"><div><div class="crumb"><?=e($crumb)?></div><h1><?=e($title)?></h1></div><?php if($actions):?><div class="actions"><?=$actions?></div><?php endif;?></div><?php }
function kpi_card(string $label,string|int $value,string $delta='',string $ico='◆'): void { ?><div class="kpi"><span class="ico"><?=e($ico)?></span><div class="lab"><?=e($label)?></div><div class="val"><?=e((string)$value)?></div><?php if($delta):?><div class="delta up"><?=e($delta)?></div><?php endif;?></div><?php }
function job_card(array $j): void { ?><a href="<?=url('job',['slug'=>$j['slug']])?>" class="card jobcard"><div style="display:flex;justify-content:space-between;align-items:flex-start"><span class="badge amber"><?=e($j['department_name'] ?: 'General')?></span><span class="tiny muted"><?=e($j['published_at']?date('M j',strtotime($j['published_at'])):'')?></span></div><h3 style="font-size:17px"><?=e($j['title'])?></h3><div class="small muted"><?=e($j['client_name'])?></div><div class="small" style="display:flex;gap:14px;flex-wrap:wrap"><span class="muted">📍 <?=e($j['location_text'])?></span><span class="muted">🕑 <?=e(stage_label($j['employment_type']))?></span></div><div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;padding-top:12px;border-top:1px solid var(--border)"><span class="mono small" style="font-weight:600"><?=e(money($j['salary_min']!==null?(float)$j['salary_min']:null,$j['salary_max']!==null?(float)$j['salary_max']:null))?></span><span class="badge green"><?=e((string)$j['openings'])?> openings</span></div></a><?php }
