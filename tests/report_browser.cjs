// Real-browser check of the Ticketing Report (isolated database, synthetic data).
// Start the server first:  HRIS_TEST_DB=... php -S 127.0.0.1:8899 tests/report_browser_router.php
const path=require('node:path'),os=require('node:os'),fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(path.join(os.homedir(),'.cache','codex-runtimes','codex-primary-runtime','dependencies','node','node_modules','playwright'));
const f=JSON.parse(fs.readFileSync('storage/slice15-private/hr-ops-fixtures.json','utf8'));
const base='http://127.0.0.1:8899/index.php';let passed=0;const out='storage/slice15-private';
const url=(page,extra={})=>base+'?'+new URLSearchParams({page,...extra});
const ok=m=>{passed++;console.log('PASS '+m);};
(async()=>{const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});
 try{
  const ctx=await browser.newContext({viewport:{width:1280,height:900},acceptDownloads:true}),p=await ctx.newPage();const problems=[];
  p.on('console',m=>{if(m.type()==='error')problems.push('console: '+m.text());});p.on('pageerror',e=>problems.push('pageerror: '+e.message));p.on('response',x=>{if(x.status()>=400)problems.push('http '+x.status()+' '+new URL(x.url()).search);});
  await p.goto(url('login'));await p.locator('[name=email]').fill(f.hr.email);await p.locator('[name=password]').fill('Slice15-Test-Only!9');await p.getByRole('button',{name:'Sign in'}).click();
  await p.getByRole('heading',{name:'Ticketing Report',exact:true}).waitFor();
  const clean=async label=>{const t=await p.locator('body').innerText();assert.ok(!/(Warning|Notice|Deprecated|Fatal error)\s*:/.test(t),label+' shows a PHP message');
    const over=await p.evaluate(()=>document.documentElement.scrollWidth-window.innerWidth);assert.ok(over<=1,label+' scrolls sideways by '+over+'px');};
  for(const [w,h] of [[1280,900],[768,1024],[360,780]]){
    await p.setViewportSize({width:w,height:h});
    for(const theme of ['light','dark']){
      await p.goto(url('hr-ticket-report',{period:'last_30'}));await p.evaluate(t=>{document.documentElement.dataset.theme=t;},theme);
      await p.getByRole('heading',{name:'Summary',exact:true}).waitFor();await clean('report '+w+' '+theme);
      if(theme==='light')await p.screenshot({path:`${out}/report-${w}.png`,fullPage:true});
    }
    ok(`report renders at ${w}px, light and dark: no PHP messages, no sideways scroll`);
  }
  await p.setViewportSize({width:1280,height:900});await p.goto(url('hr-ticket-report',{period:'last_30'}));
  assert.equal(await p.locator('.hd-kpi').count(),6);assert.ok(await p.getByRole('heading',{name:'Aging'}).isVisible());assert.ok(await p.getByRole('heading',{name:'HR throughput'}).isVisible());assert.ok(await p.getByRole('heading',{name:'Setup health'}).isVisible());
  ok('six KPI cards and the Aging, Throughput and Setup health sections are present');
  // filter by type through the form
  await p.locator('#tr-type').selectOption('UNIFORM_REQUEST');await p.getByRole('button',{name:'Apply'}).click();await p.getByRole('heading',{name:'Uniform Request'}).waitFor();
  assert.match(await p.locator('.tr-scope').innerText(),/Uniform Request/);ok('type filter applies and shows the Uniform size grid');
  // zero data
  await p.goto(url('hr-ticket-report',{period:'custom',from:'2001-01-01',to:'2001-01-02'}));assert.ok(await p.getByText('No requests were filed in this period').isVisible());await clean('zero data');ok('a period with no requests shows plain empty states');
  // CSV download
  const dl=await ctx.request.get(url('hr-ticket-report-export',{period:'last_30'}));assert.equal(dl.status(),200);assert.match(dl.headers()['content-type'],/text\/csv/);
  const csv=(await dl.text());assert.ok(csv.includes('"Request no",Type,Status'));assert.ok(!/@|SECRET/i.test(csv.split('\n').slice(1).join('\n')));ok('CSV downloads with the documented header and no email addresses');
  // print page
  const pp=await ctx.newPage();await pp.goto(url('hr-ticket-report-print',{period:'last_30'}));await pp.getByRole('heading',{name:'HR Ticketing Summary'}).waitFor();
  await pp.emulateMedia({media:'print'});const pages=await pp.evaluate(()=>Math.ceil(document.documentElement.scrollHeight/ (297/25.4*96-2*(12/25.4*96))));
  assert.ok(pages<=1,'print version is '+pages+' pages');await pp.pdf({path:`${out}/report-print.pdf`,format:'A4',printBackground:true});ok('print summary fits one A4 page');
  await pp.setViewportSize({width:360,height:780});await pp.emulateMedia({media:'screen'});const wide=await pp.evaluate(()=>document.documentElement.scrollWidth-window.innerWidth);assert.ok(wide<=1);ok('print page is readable on a phone');
  assert.deepEqual(problems.filter(x=>!/favicon|fonts|Failed to load resource|http 404 [?]v=/.test(x)),[]);ok('no console errors');
  console.log(`RESULT ${passed} passed`);
 }finally{await browser.close();}
})().catch(e=>{console.error('FAIL',e.message);process.exit(1);});
