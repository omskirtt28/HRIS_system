// Real-browser check of the HR dashboard and HR Request Queue (isolated database, synthetic data).
// Start the server first:  HRIS_TEST_DB=... php -S 127.0.0.1:8897 tests/hr_ops_browser_router.php
const path=require('node:path'),os=require('node:os'),fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(path.join(os.homedir(),'.cache','codex-runtimes','codex-primary-runtime','dependencies','node','node_modules','playwright'));
const f=JSON.parse(fs.readFileSync('storage/slice15-private/hr-ops-fixtures.json','utf8'));
const base='http://127.0.0.1:8897/index.php';let passed=0;const out='storage/slice15-private';
const url=(page,extra={})=>base+'?'+new URLSearchParams({page,...extra});
const ok=m=>{passed++;console.log('PASS '+m);};
(async()=>{const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});
 try{
  const ctx=await browser.newContext({viewport:{width:1280,height:900}}),p=await ctx.newPage();const problems=[];
  p.on('console',m=>{if(m.type()==='error')problems.push('console: '+m.text());});p.on('response',r=>{if(r.status()>=400)problems.push('http '+r.status()+' '+new URL(r.url()).pathname);});p.on('pageerror',e=>problems.push('pageerror: '+e.message));
  await p.goto(url('login'));await p.locator('[name=email]').fill(f.hr.email);await p.locator('[name=password]').fill('Slice15-Test-Only!9');await p.getByRole('button',{name:'Sign in'}).click();
  await p.getByRole('heading',{name:/Good (morning|afternoon|evening)/}).waitFor();
  const clean=async label=>{const t=await p.locator('body').innerText();assert.ok(!/(Warning|Notice|Deprecated|Fatal error)\s*:/.test(t),label+' shows a PHP message');
    const over=await p.evaluate(()=>document.documentElement.scrollWidth-window.innerWidth);assert.ok(over<=1,label+' scrolls sideways by '+over+'px');};
  for(const [w,h] of [[1280,900],[768,1024],[360,780]]){
    await p.setViewportSize({width:w,height:h});
    for(const theme of ['light','dark']){
      await p.goto(url('hr-dashboard'));await p.evaluate(t=>{document.documentElement.dataset.theme=t;},theme);
      await p.getByText('Needs your action today').waitFor();await clean('dashboard '+w+' '+theme);
      if(theme==='light')await p.screenshot({path:`${out}/hr-dashboard-${w}.png`,fullPage:true});
      await p.goto(url('hr-service-requests',{tab:'pending'}));await p.evaluate(t=>{document.documentElement.dataset.theme=t;},theme);
      await p.getByRole('heading',{name:'HR Request Queue',exact:true}).waitFor();await clean('queue '+w+' '+theme);
      if(theme==='light')await p.screenshot({path:`${out}/hr-queue-${w}.png`,fullPage:true});
    }
    ok(`dashboard and queue render at ${w}px, light and dark: no PHP messages, no sideways scroll`);
  }
  // mobile layout turns rows into cards
  await p.setViewportSize({width:360,height:780});await p.goto(url('hr-service-requests',{tab:'pending'}));
  assert.equal(await p.locator('.hq-table thead').isVisible(),false);assert.ok(await p.locator('.hq-table tbody tr').first().evaluate(e=>getComputedStyle(e).display==='block'));ok('360px: table header hidden, rows are cards');
  await p.setViewportSize({width:1280,height:900});
  // dashboard content
  await p.goto(url('hr-dashboard'));
  assert.equal(await p.locator('a.hd-kpi').count(),6);assert.ok(await p.getByText('Requests by type').isVisible());assert.ok(await p.getByText('Setup warnings').isVisible());
  const href=await p.locator('a.hd-kpi',{hasText:'Pending requests'}).getAttribute('href');assert.match(href,/tab=pending/);ok('dashboard: six KPI cards, charts, setup warnings; Pending KPI links to the Pending tab');
  await p.locator('a.hd-kpi',{hasText:'Pending requests'}).click();await p.getByRole('heading',{name:'HR Request Queue',exact:true}).waitFor();
  assert.ok(await p.locator('.hq-tab.is-active',{hasText:'Pending'}).isVisible());ok('KPI click opens the filtered queue');
  // keyboard: select with Space, bulk bar appears
  const boxes=p.locator('.hq-pick');const total=await boxes.count();assert.ok(total>=4);
  await boxes.nth(0).focus();await p.keyboard.press('Space');assert.equal(await p.locator('[data-bulkbar]').isVisible(),true);assert.match(await p.locator('[data-bulk-count]').innerText(),/1 selected/);
  await p.locator('#hq-all').check();assert.match(await p.locator('[data-bulk-count]').innerText(),new RegExp(total+' selected'));await p.locator('#hq-all').uncheck();assert.equal(await p.locator('[data-bulkbar]').isVisible(),false);
  ok('keyboard selection, select all and clear drive the bulk bar');
  // preview drawer
  await p.locator('a[data-preview]').first().click();await p.locator('#hq-drawer[open]').waitFor();await p.locator('.hq-drawer-head').waitFor();
  assert.ok(await p.locator('#hq-drawer').getByRole('link',{name:'Open full request'}).isVisible());await p.screenshot({path:`${out}/hr-queue-drawer.png`});
  await p.keyboard.press('Escape');assert.equal(await p.locator('#hq-drawer[open]').count(),0);ok('preview drawer opens in place and closes with Escape');
  // bulk Served on two Uniform tickets with a shared remark
  await p.goto(url('hr-service-requests',{tab:'pending',type_code:'UNIFORM_REQUEST'}));
  const rows=p.locator('.hq-table tbody tr[data-id]');const n=await rows.count();assert.ok(n>=2);
  await rows.nth(0).locator('.hq-pick').check();await rows.nth(1).locator('.hq-pick').check();
  await p.getByRole('button',{name:'Mark as Served'}).click();await p.locator('#hq-bulk-dialog[open]').waitFor();
  assert.match(await p.locator('[data-bulk-summary]').innerText(),/2 requests will be processed: Uniform Request × 2/);await p.screenshot({path:`${out}/hr-queue-bulk-dialog.png`});
  await p.locator('#hq-bulk-remarks').fill('Released to the branch');await p.locator('[data-bulk-confirm]').click();
  await p.locator('.hq-result').waitFor();assert.match(await p.locator('.hq-result').innerText(),/2 served/);ok('bulk Served on two Uniform requests: confirmation, shared remark, result summary "2 served"');
  const served=f.ids.slice(0,2);
  // single quick action: Served confirm dialog on an ATM row
  await p.goto(url('hr-service-requests',{tab:'pending',type_code:'ATM_REQUEST'}));
  const first=p.locator('.hq-table tbody tr[data-id]').first();const ticket=await first.locator('.hq-mono').innerText();
  await first.getByRole('button',{name:'Served',exact:true}).click();await p.locator('#hq-quick-dialog[open]').waitFor();assert.match(await p.locator('[data-quick-summary]').innerText(),new RegExp(ticket));
  await p.locator('#hq-quick-dialog').getByRole('button',{name:'Confirm'}).click();await p.locator('.hq-result').waitFor();assert.match(await p.locator('.hq-result').innerText(),/1 served/);
  ok('quick Served on one ATM row asks first and reports "1 served"');
  // mixed bulk: ATM + Manpower selected together => skipped with reason
  await p.goto(url('hr-service-requests',{tab:'pending'}));
  await p.locator('.hq-table tbody tr[data-type="MANPOWER_REQUEST"] .hq-pick').check();await p.locator('.hq-table tbody tr[data-type="ATM_REQUEST"] .hq-pick').first().check();
  await p.getByRole('button',{name:'Mark as Served'}).click();assert.match(await p.locator('[data-bulk-skip]').innerText(),/1 request will be skipped \(Manpower Request × 1\)/);
  await p.locator('[data-bulk-confirm]').click();await p.locator('.hq-result').waitFor();const txt=await p.locator('.hq-result').innerText();assert.match(txt,/1 served, 1 skipped/);assert.match(txt,/Manpower Request — .*one by one/);
  ok('mixed selection: Manpower skipped with a clear reason, "1 served, 1 skipped"');
  // search, chips, export, uniform summary
  await p.goto(url('hr-service-requests',{tab:'all',type_code:'UNIFORM_REQUEST'}));
  assert.ok(await p.locator('.hq-uniform').isVisible());await p.locator('.hq-uniform summary').click();assert.ok(await p.locator('.hq-uniform table').isVisible());await p.screenshot({path:`${out}/hr-queue-uniform.png`,fullPage:true});
  await p.locator('#hq-q').fill('zzzz-nothing');await p.getByRole('button',{name:'Apply'}).click();await p.getByText('No requests match these filters').waitFor();
  const dl=ctx.request.get(url('hr-service-requests-export',{type_code:'UNIFORM_REQUEST'}));const r=await dl;assert.equal(r.status(),200);assert.match(r.headers()['content-type'],/text\/csv/);assert.match(await r.text(),/Ticket no/);
  ok('uniform order summary, empty state with clear filters, CSV export download');
  console.log(JSON.stringify([...new Set(problems)]).slice(0,900));assert.deepEqual(problems.filter(x=>!/fonts.g|ERR_|favicon|Failed to load resource/i.test(x)),[]);ok('no browser console errors');
  console.log(`RESULT ${passed} passed, 0 failed`);
 }catch(e){console.log('FAIL '+e.message.split('\n')[0]);console.log('RESULT '+passed+' passed, 1 failed');process.exitCode=1;}
 finally{await browser.close();}
})();
