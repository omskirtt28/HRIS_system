// Real-browser check of the Pipeline Head Office / Retail control (SPEC-pipeline-head-office-retail.md). Started by
// tests/pipeline_site_split_http_test.php when HRIS_BROWSER=1 (temp copy of the app against the SCRATCH database).
// Env: PS_PORT, PS_EMAIL, PS_PASSWORD, PS_OUT (screenshot folder).
const path=require('node:path'),os=require('node:os'),assert=require('node:assert/strict');
const {chromium}=require(path.join(os.homedir(),'.cache','codex-runtimes','codex-primary-runtime','dependencies','node','node_modules','playwright'));
const base='http://127.0.0.1:'+process.env.PS_PORT+'/index.php',out=process.env.PS_OUT||'.';let passed=0;
const ok=m=>{passed++;console.log('PASS '+m);};
(async()=>{const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});
 let code=0;
 try{
  const ctx=await browser.newContext({viewport:{width:1535,height:900}}),p=await ctx.newPage();const problems=[];
  p.on('console',m=>{if(m.type()==='error'&&!/favicon|404/.test(m.text()))problems.push('console: '+m.text());});p.on('pageerror',e=>problems.push('pageerror: '+e.message));
  await p.goto(base+'?page=login');await p.locator('[name=email]').fill(process.env.PS_EMAIL);await p.locator('[name=password]').fill(process.env.PS_PASSWORD);await p.getByRole('button',{name:'Sign in'}).click();
  await p.waitForURL(u=>!u.search.includes('page=login'));
  const noScroll=async label=>{const over=await p.evaluate(()=>document.documentElement.scrollWidth-window.innerWidth);assert.ok(over<=1,label+' scrolls sideways by '+over+'px');};

  await p.goto(base+'?page=hr-pipeline');
  await p.locator('.pv-tab.on').waitFor();
  assert.match(await p.locator('.pv-tab.on').innerText(),/Head Office/);ok('opens on Head Office');
  await p.screenshot({path:out+'/pipeline-split-head-office-1535.png'});

  await p.getByRole('button',{name:/^Retail/}).click();
  await p.locator('.pv-pick summary').waitFor();
  assert.match(await p.locator('.pv-tab.on').innerText(),/Retail/);ok('clicking Retail switches and shows the branch picker');

  await p.locator('.pv-pick summary').click();
  const total=await p.locator('.pv-list input').count();assert.ok(total>10,'branch list has '+total);
  await p.locator('.pv-find').fill('angono');
  const shown=await p.locator('.pv-list label:visible').count();assert.ok(shown>=1&&shown<total,'search narrows '+total+' to '+shown);ok('branch search narrows the list');
  await p.screenshot({path:out+'/pipeline-split-retail-picker-1535.png'});
  await p.locator('.pv-find').fill('');
  await p.locator('.pv-pick [data-pv=all]').click();assert.equal(await p.locator('.pv-list input:checked').count(),total);
  await p.locator('.pv-pick [data-pv=clear]').click();assert.equal(await p.locator('.pv-list input:checked').count(),0);ok('All and Clear tick and untick every branch');
  await p.locator('.pv-list label').first().locator('input').check();
  await Promise.all([p.waitForNavigation(),p.locator('.pv-pop .btn.primary').click()]);
  assert.match(await p.locator('.pv-pick summary').innerText(),/1 selected/);ok('Apply saves one branch and the label says 1 selected');

  for(const [w,h] of [[1280,900],[768,1024],[390,844]]){await p.setViewportSize({width:w,height:h});await p.goto(base+'?page=hr-pipeline');await p.locator('.pv-tab.on').waitFor();await noScroll('pipeline '+w);
    await p.locator('.pv-pick summary').click();const box=await p.locator('.pv-pop').boundingBox();assert.ok(box.x>=0&&box.x+box.width<=w+1,'picker stays on screen at '+w+' (x='+box.x+', w='+box.width+')');
    if(w===390)await p.screenshot({path:out+'/pipeline-split-retail-390.png'});ok('layout holds at '+w+'px: no sideways scroll, picker on screen');}
  await p.setViewportSize({width:1535,height:900});await p.goto(base+'?page=hr-pipeline');
  await p.locator('.pv-tab:not(.on)').focus();assert.ok(await p.evaluate(()=>document.activeElement.classList.contains('pv-tab')));ok('tabs are keyboard focusable');
  const t=await p.locator('body').innerText();assert.ok(!/(Warning|Notice|Deprecated|Fatal error)\s*:/.test(t),'PHP message on page');ok('no PHP messages');
  assert.deepEqual(problems,[]);ok('browser console clean');
  console.log('RESULT '+passed+' passed');
 }catch(e){console.error('FAIL',e.message);code=1;}finally{await browser.close();}
 process.exit(code);})();
