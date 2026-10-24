// Real-browser check of the Certificate of Employment pages (isolated database, synthetic data).
// Start the server first:  HRIS_TEST_DB=... php -S 127.0.0.1:8898 tests/coe_browser_router.php
const path=require('node:path'),os=require('node:os'),fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(path.join(os.homedir(),'.cache','codex-runtimes','codex-primary-runtime','dependencies','node','node_modules','playwright'));
const f=JSON.parse(fs.readFileSync('storage/slice15-private/coe-fixtures.json','utf8'));
const base='http://127.0.0.1:8898/index.php';let passed=0;const out='storage/slice15-private';
const url=(page,extra={})=>base+'?'+new URLSearchParams({page,...extra});
const ok=m=>{passed++;console.log('PASS '+m);};
(async()=>{const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});
 try{
  const login=async(user)=>{const ctx=await browser.newContext({viewport:{width:1280,height:900}}),p=await ctx.newPage();const problems=[];
    p.on('console',m=>{if(m.type()==='error')problems.push('console: '+m.text());});p.on('pageerror',e=>problems.push('pageerror: '+e.message));p.on('response',x=>{if(x.status()>=400)problems.push('http '+x.status()+' '+new URL(x.url()).search);});
    await p.goto(url('login'));await p.locator('[name=email]').fill(user.email);await p.locator('[name=password]').fill('Slice15-Test-Only!9');await p.getByRole('button',{name:'Sign in'}).click();
    await p.waitForURL(/hr-requests-my/);return {ctx,p,problems};};
  const clean=async(p,label)=>{const t=await p.locator('body').innerText();assert.ok(!/(Warning|Notice|Deprecated|Fatal error)\s*:/.test(t),label+' shows a PHP message');
    const over=await p.evaluate(()=>document.documentElement.scrollWidth-window.innerWidth);assert.ok(over<=1,label+' scrolls sideways by '+over+'px');};
  const widths=[[1280,900],[768,1024],[360,780]];
  // ---- employee: filing form and own request
  const emp=await login(f.employee);
  for(const [w,h] of widths){await emp.p.setViewportSize({width:w,height:h});
    await emp.p.goto(url('hr-request-new',{type:'x'}));
    const sel=emp.p.locator('#ticket-type');await sel.selectOption({label:'Certificate of Employment'});await emp.p.locator('#field-purpose').waitFor();
    await clean(emp.p,'filing form '+w);if(w===360)await emp.p.screenshot({path:`${out}/coe-form-360.png`,fullPage:true});
    await emp.p.goto(url('hr-request',{id:f.pending}));await clean(emp.p,'employee request '+w);
  }
  ok('filing form and own request render at 1280, 768 and 360 without PHP messages or sideways scroll');
  assert.equal(await emp.p.locator('a[href*="hr-coe-download"]').count(),0);ok('no download link before Served');
  // salary and signatory fields are not on the filing form
  await emp.p.goto(url('hr-request-new'));await emp.p.locator('#ticket-type').selectOption({label:'Certificate of Employment'});await emp.p.locator('#field-purpose').waitFor();
  assert.equal(await emp.p.locator('[name=include_salary]').count(),0);ok('the salary flag is not offered to the requester');
  // forged access by a user who is neither requester nor HR
  let r=await emp.ctx.request.get(url('hr-coe-download',{id:f.other}));assert.equal(r.status(),403);ok('requester cannot download someone else\'s COE (403)');
  r=await emp.ctx.request.get(url('hr-coe-preview',{id:f.pending}));assert.equal(r.status(),403);ok('requester cannot open the HR preview (403)');
  r=await emp.ctx.request.get(url('hr-coe-template'));assert.equal(r.status(),403);ok('requester cannot open the template page (403)');
  // ---- HR
  const hr=await login(f.hr);
  for(const [w,h] of widths){await hr.p.setViewportSize({width:w,height:h});
    for(const theme of ['light','dark']){
      await hr.p.goto(url('hr-coe-template'));await hr.p.evaluate(t=>{document.documentElement.dataset.theme=t;},theme);await hr.p.getByRole('heading',{name:'Upload a new template'}).waitFor();await clean(hr.p,'template page '+w+' '+theme);
      if(theme==='light'&&w===360)await hr.p.screenshot({path:`${out}/coe-template-360.png`,fullPage:true});
      await hr.p.goto(url('hr-coe-preview',{id:f.pending}));await hr.p.evaluate(t=>{document.documentElement.dataset.theme=t;},theme);await hr.p.getByText('exact values').waitFor();await clean(hr.p,'preview '+w+' '+theme);
      if(theme==='light'&&w===1280)await hr.p.screenshot({path:`${out}/coe-preview-1280.png`,fullPage:true});
    }
  }
  ok('template page and preview render at 1280, 768 and 360, light and dark, without PHP messages or sideways scroll');
  // upload checks through the real form
  await hr.p.setViewportSize({width:1280,height:900});await hr.p.goto(url('hr-coe-template'));
  await hr.p.locator('#coe-file').setInputFiles(f.fake);await hr.p.getByRole('button',{name:'Upload template'}).click();
  await hr.p.getByText('not a real .docx').waitFor();ok('a fake .docx is rejected with a plain message');
  await hr.p.locator('#coe-file').setInputFiles(f.sample);await hr.p.getByRole('button',{name:'Upload template'}).click();
  await hr.p.getByText('Template uploaded and set as the active version').waitFor();assert.ok(await hr.p.getByText(/Version [0-9]+ ·/).isVisible());ok('a valid .docx is accepted and shown as the current version');
  await hr.p.locator('#coe-signatory_name').fill('Signatory Test');await hr.p.getByRole('button',{name:'Save',exact:true}).click();await hr.p.getByText('settings saved').waitFor();ok('signatory saved');
  // detail shows Preview & Served; no direct Served button
  await hr.p.goto(url('hr-request',{id:f.pending}));assert.equal(await hr.p.locator('button[value=COMPLETE]').count(),0);
  await hr.p.getByRole('link',{name:/Preview/}).click();await hr.p.getByText('exact values').waitFor();
  const body=await hr.p.locator('body').innerText();assert.ok(body.includes('Kate Anne Cadiz')&&body.includes('Accounting Clerk')&&body.includes('March 15, 2021')&&/template version [0-9]+/.test(body));ok('preview shows the employee values and the active template version');
  await hr.p.getByRole('button',{name:/Confirm Served/}).click();await hr.p.getByText('Ticket action recorded').waitFor();
  assert.ok(await hr.p.getByRole('link',{name:/Download certificate/}).isVisible());ok('Served from the preview generates the certificate; HR sees the download link');
  const hrDl=await hr.ctx.request.get(url('hr-coe-download',{id:f.pending}));assert.equal(hrDl.status(),200);
  assert.match(hrDl.headers()['content-type'],/wordprocessingml\.document/);const bytes=await hrDl.body();assert.equal(bytes.slice(0,2).toString(),'PK');ok('HR download is a .docx (PK zip signature, Word content type)');
  // requester downloads the same file
  await emp.p.goto(url('hr-request',{id:f.pending}));assert.ok(await emp.p.getByRole('link',{name:/Download certificate/}).isVisible());
  const empDl=await emp.ctx.request.get(url('hr-coe-download',{id:f.pending}));assert.equal(empDl.status(),200);assert.ok(Buffer.compare(await empDl.body(),bytes)===0);ok('employee downloads the identical file from their own ticket');
  await emp.p.setViewportSize({width:360,height:780});await clean(emp.p,'served request 360');ok('served request page is clean at 360');
  // another employee cannot reach it
  const out2=await login(f.outsider);r=await out2.ctx.request.get(url('hr-coe-download',{id:f.pending}));assert.equal(r.status(),403);ok('a different employee gets 403 on the download');
  // the other COE (hostile text) previews with escaped text
  await hr.p.goto(url('hr-coe-preview',{id:f.other}));await hr.p.getByText('exact values').waitFor();assert.ok((await hr.p.locator('body').innerText()).includes('Embassy <b>visa</b> & loan'));ok('markup in the purpose is shown as plain text');
  for(const c of [emp,hr,out2])assert.deepEqual(c.problems.filter(x=>!/favicon|fonts|Failed to load resource|http 404 [?]v=|http 403 [?]page=hr-requests-my/.test(x)),[]);ok('no console errors');
  console.log(`RESULT ${passed} passed`);
 }finally{await browser.close();}
})().catch(e=>{console.error('FAIL',e.message);process.exit(1);});
