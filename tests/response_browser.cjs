// Real-browser check of the ticket detail page: HR response, internal note separation, stepper, chat, history (isolated database).
// Start the server first:  HRIS_TEST_DB=... php -S 127.0.0.1:8896 tests/response_browser_router.php
const path=require('node:path'),os=require('node:os'),fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(path.join(os.homedir(),'.cache','codex-runtimes','codex-primary-runtime','dependencies','node','node_modules','playwright'));
const f=JSON.parse(fs.readFileSync('storage/slice15-private/response-fixtures.json','utf8'));
const base='http://127.0.0.1:8896/index.php';let passed=0;const out='storage/slice15-private';
const url=(page,extra={})=>base+'?'+new URLSearchParams({page,...extra});
const ok=m=>{passed++;console.log('PASS '+m);};
(async()=>{const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});
 try{
  const problems=[];
  const login=async who=>{const ctx=await browser.newContext({viewport:{width:1280,height:900}}),p=await ctx.newPage();
   p.on('console',m=>{if(m.type()==='error')problems.push('console: '+m.text());});p.on('pageerror',e=>problems.push('pageerror: '+e.message));p.on('response',x=>{if(x.status()>=400)problems.push('http '+x.status()+' '+new URL(x.url()).search);});
   await p.goto(url('login'));await p.locator('[name=email]').fill(f[who].email);await p.locator('[name=password]').fill('Slice15-Test-Only!9');await p.getByRole('button',{name:'Sign in'}).click();await p.waitForLoadState();return p;};
  const clean=async(p,label)=>{const t=await p.locator('body').innerText();assert.ok(!/(Warning|Notice|Deprecated|Fatal error)\s*:/.test(t),label+' shows a PHP message');
    const over=await p.evaluate(()=>document.documentElement.scrollWidth-window.innerWidth);assert.ok(over<=1,label+' scrolls sideways by '+over+'px');};
  const emp=await login('employee');
  for(const [w,h] of [[1280,900],[768,1024],[360,780]]){
    await emp.setViewportSize({width:w,height:h});
    for(const theme of ['light','dark']){
      await emp.goto(url('hr-request',{id:f.served}));await emp.evaluate(t=>{document.documentElement.dataset.theme=t;},theme);
      await emp.getByRole('heading',{name:'HR response'}).waitFor();await clean(emp,'employee served '+w+' '+theme);
      if(theme==='light')await emp.screenshot({path:`${out}/response-employee-${w}.png`,fullPage:true});
    }
    ok(`employee detail renders at ${w}px, light and dark: no PHP messages, no sideways scroll`);
  }
  await emp.setViewportSize({width:1280,height:900});await emp.goto(url('hr-request',{id:f.served}));
  const body=await emp.locator('body').innerText();
  assert.ok(body.includes('Please collect your ATM card at HR'));assert.ok(!body.includes('ZZ-INTERNAL'));assert.equal(await emp.locator('#internal-note').count(),0);
  assert.ok(/Served/.test(await emp.locator('.tk-pill').innerText()));assert.equal(await emp.locator('.tk-step').count(),4);
  assert.ok(await emp.locator('.tk-step.is-done').count()>=3);assert.ok(await emp.locator('.tk-msg.is-mine').count()===1&&await emp.locator('.tk-msg.is-theirs').count()===1);
  ok('employee sees the HR remarks, status pill, 4-step stepper and chat thread, and no internal note');
  assert.equal(await emp.locator('details.tk-collapse > summary:has-text("History")').count(),1);assert.equal(await emp.locator('details.tk-collapse[open]').count(),0);ok('history is collapsed by default');
  await emp.goto(url('hr-request',{id:f.pending}));assert.ok(await emp.getByText('No response yet').isVisible());await clean(emp,'pending');ok('pending request shows a plain "no response yet" state');
  await emp.goto(url('hr-request',{id:f.cancelled}));assert.ok(await emp.getByText('Duplicate of an earlier request.').first().isVisible());assert.equal(await emp.locator('.tk-step.is-stop').count(),1);ok('cancelled request shows the response and a stopped step');
  await emp.goto(url('hr-request',{id:f.served}));await emp.locator('details.tk-collapse > summary').first().focus();await emp.keyboard.press('Enter');assert.equal(await emp.locator('details.tk-collapse[open]').count(),1);ok('History opens from the keyboard');
  const hr=await login('hr');
  for(const [w,h] of [[1280,900],[768,1024],[360,780]]){
    await hr.setViewportSize({width:w,height:h});await hr.goto(url('hr-request',{id:f.served}));await hr.getByRole('heading',{name:/Internal notes/}).waitFor();await clean(hr,'hr served '+w);
    await hr.goto(url('hr-request',{id:f.pending}));await hr.locator('#internal-note').waitFor();await clean(hr,'hr pending '+w);
    if(w===1280||w===360)await hr.screenshot({path:`${out}/response-hr-${w}.png`,fullPage:true});
  }
  ok('HR detail renders at 1280/768/360 with the internal-note field and no PHP messages');
  await hr.setViewportSize({width:1280,height:900});await hr.goto(url('hr-request',{id:f.served}));assert.ok((await hr.locator('body').innerText()).includes('ZZ-INTERNAL'));ok('HR sees the internal note');
  await hr.goto(url('hr-request',{id:f.pending}));
  const l1=await hr.locator('label[for=decision-remarks]').innerText(),l2=await hr.locator('label[for=internal-note]').innerText();
  assert.ok(/Response to the employee/.test(l1)&&/Visible to the employee/i.test(l1));assert.ok(/Internal note/.test(l2)&&/HR only/i.test(l2));ok('HR form labels the two fields clearly');
  await hr.locator('#decision-remarks').fill('Ready for pickup.');await hr.locator('#internal-note').fill('ZZ-INTERNAL second');await hr.getByRole('button',{name:'Served'}).click();await hr.waitForLoadState();
  assert.ok(await hr.getByText('Ticket action recorded').first().isVisible());ok('HR can mark Served with response + internal note through the real handler');
  await emp.goto(url('hr-request',{id:f.pending}));const t2=await emp.locator('body').innerText();assert.ok(t2.includes('Ready for pickup.')&&!t2.includes('ZZ-INTERNAL'));ok('employee sees the new response and not the note');
  assert.deepEqual(problems.filter(x=>!/favicon|http 403 [?]page=hr-requests-my|fonts|Failed to load resource|http 404 [?]v=/.test(x)),[]);ok('no console errors');
  console.log(`RESULT ${passed} passed`);
 }finally{await browser.close();}
})().catch(e=>{console.error('FAIL',e.message);process.exit(1);});
