<?php
declare(strict_types=1);
// Static source checks for the folded sidebar rail: no bootstrap, request or database.
$fail = 0;
function check(string $label, bool $ok): void { global $fail; echo ($ok ? 'PASS ' : 'FAIL ').$label."\n"; if (!$ok) $fail++; }
$css = (string)file_get_contents(__DIR__.'/../public/assets/sidebar-fold.css');
$js = (string)file_get_contents(__DIR__.'/../public/assets/hris-app.js');
$view = (string)file_get_contents(__DIR__.'/../app/View.php');

check('css: rail scrollbar hidden (firefox)', str_contains($css, 'scrollbar-width:none'));
check('css: rail scrollbar hidden (webkit)', str_contains($css, '.side::-webkit-scrollbar{display:none}'));
check('css: rail logo shows the full logo, not a crop', preg_match('/\.brand-full-logo\{width:100%!important;max-width:68px;height:auto!important;object-fit:contain!important/', $css) === 1);
check('css: rail shows one icon per group with a flyout', str_contains($css, '.nav-group.foldable.fly .nav-items{display:block;position:fixed') && str_contains($css, '.gl-toggle .gl-ico{width:34px'));
check('js: rail flyout opens on hover, focus and click; Escape closes', str_contains($js, "addEventListener('mouseenter'") && str_contains($js, "addEventListener('focusin'") && str_contains($js, "e.key === 'Escape') closeFly()"));
check('css: tooltip is desktop only and hidden until shown', str_contains($css, '.side-tip{display:none}') && str_contains($css, '.side-tip.show{opacity:1}'));
check('css: tooltip never blocks the pointer', str_contains($css, 'pointer-events:none'));
check('css: reduced motion covers the tooltip', str_contains($css, 'prefers-reduced-motion:reduce){.shell,.side-tip{transition:none}'));
check('js: tooltip shows on hover and keyboard focus', str_contains($js, "['mouseover', 'focusin']") && str_contains($js, "['mouseout', 'focusout']"));
check('js: tooltip only in folded state', str_contains($js, "dataset.side !== 'folded') return;"));
check('js: tooltip hidden by Escape, scroll and toggle', str_contains($js, "e.key === 'Escape'") && str_contains($js, "'scroll', hideSideTip") && str_contains($js, "hideSideTip();\n  const btn"));
check('js: tooltip is aria-hidden (labels stay in .nl)', str_contains($js, "setAttribute('aria-hidden', 'true')"));
check('js: no native title duplicate', !str_contains($js, 'link.title ='));
check('view: fold toggle keeps aria wiring', str_contains($view, 'onclick="toggleSideFold()"') && str_contains($view, 'aria-controls="side"'));
check('view: rail assets versioned', str_contains($view, 'sidebar-fold.css?v=20261031-v6') && str_contains($view, 'hris-app.js?v=20261104-draft-v1'));
check('view: nav labels stay in .nl for screen readers', str_contains($view, '<span class="nl"><?=e($label)?></span>'));
exit($fail ? 1 : 0);
