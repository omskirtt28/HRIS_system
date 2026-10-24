// Real-browser check of the Incident Reports employee screens (I2) on the isolated database.
// Start the server first:  HRIS_TEST_DB=... php -S 127.0.0.1:8896 tests/incident_browser_router.php   (after incident_browser_setup.php)
const path=require('node:path'),os=require('node:os'),fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(path.join(os.homedir(),'.cache','codex-runtimes','codex-primary-runtime','dependencies','node','node_modules','playwright'));
const f=JSON.parse(fs.readFileSync('storage/slice15-private/incident-fixtures.json','utf8'));
const base='http://127.0.0.1:8896/index.php';let passed=0;const out='storage/slice15-private';
const url=(page,extra={})=>base+'?'+new URLSearchParams({page,...extra});
const ok=m=>{passed++;console.log('PASS '+m);};
(async()=>{const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});
 try{
  const problems=[];
  const login=async who=>{const ctx=await browser.newContext({viewport:{width:1440,height:900}}),p=await ctx.newPage();
   p.on('console',m=>{if(m.type()==='error')problems.push('console: '+m.text());});p.on('pageerror',e=>problems.push('pageerror: '+e.message));p.on('response',x=>{if(x.status()>=400)problems.push('http '+x.status()+' '+new URL(x.url()).search);});
   await p.goto(url('login'));await p.locator('[name=email]').fill(f[who].email);await p.locator('[name=password]').fill('Slice15-Test-Only!9');await p.getByRole('button',{name:'Sign in'}).click();await p.waitForLoadState();return p;};
  const clean=async(p,label)=>{const t=await p.locator('body').innerText();assert.ok(!/(Warning|Notice|Deprecated|Fatal error)\s*:/.test(t),label+' shows a PHP message');
    const over=await p.evaluate(()=>document.documentElement.scrollWidth-window.innerWidth);assert.ok(over<=1,label+' scrolls sideways by '+over+'px');};
  const named=async(p,label)=>{const bad=await p.evaluate(()=>[...document.querySelectorAll('main input:not([type=hidden]),main select,main textarea,.main input:not([type=hidden]):not([type=search]),.main select,.main textarea')].filter(e=>{if(e.closest('.topbar'))return false;const id=e.id;return !((id&&document.querySelector('label[for="'+id+'"]'))||e.getAttribute('aria-label')||e.closest('label'));}).map(e=>e.name||e.type));
    assert.deepEqual(bad,[],label+' has unlabeled controls');};
  const shot=async(p,name)=>{for(const [w,h] of [[1440,900],[390,844]])for(const theme of ['light','dark']){await p.setViewportSize({width:w,height:h});await p.evaluate(t=>{document.documentElement.dataset.theme=t;},theme);await p.waitForTimeout(450);await clean(p,name+' '+w+' '+theme);await p.screenshot({path:`${out}/incident-${name}-${w}-${theme}.png`,fullPage:true});}await p.setViewportSize({width:1440,height:900});await p.evaluate(()=>{document.documentElement.dataset.theme='light';});};
  const titles=async p=>(await p.locator('.tk-case-title').allInnerTexts());

  // ---- reporter
  const rep=await login('employee');
  await rep.goto(url('incident-mine'));
  assert.deepEqual((await titles(rep)).sort(),['Fixture case with a CC','Fixture case, reporter only']);assert.ok(await rep.getByText('You reported this').first().isVisible());
  const side=await rep.locator('.side').innerText();
  assert.ok(/Incident Reports/i.test(side)&&/My Incidents/.test(side)&&/File an Incident/.test(side)&&!/Incident Feed/i.test(side)&&!/Incident Cases/i.test(side));
  ok('reporter: My Incidents lists only their cases; sidebar group Incident Reports (no Feed, no HR Cases)');
  const body=await rep.locator('body').innerText();assert.ok(!/Someone else/.test(body)&&!/Involved/.test(body)&&!/Witness/.test(body));ok('reporter: no other case and no role label of others');
  await shot(rep,'mine-reporter');
  await rep.goto(url('incident-new'));await named(rep,'incident-new');await shot(rep,'new-reporter');
  assert.ok(await rep.getByText('Confidential.').isVisible());ok('File an Incident: confidential note, every control labelled, no sideways scroll at 1440 and 390, light and dark');
  // picker by mouse and keyboard
  await rep.locator('#people-q').fill('S');await rep.waitForTimeout(500);assert.equal(await rep.locator('.tk-result').count(),0);ok('picker: one letter searches nothing');
  await rep.locator('#people-q').fill('Sasha Search');await rep.locator('.tk-result').first().waitFor();
  const results=await rep.locator('.tk-result').allInnerTexts();assert.equal(results.length,2);assert.ok(results.every(t=>/Sasha Searchtarget/.test(t))&&!results.some(t=>/inactive/i.test(t)));ok('picker: active accounts only, name and branch');
  await rep.locator('.tk-result-btn').first().focus();await rep.keyboard.press('Enter');
  assert.equal(await rep.locator('#people-list .tk-person').count(),1);assert.ok(await rep.evaluate(()=>document.activeElement.tagName==='SELECT'));ok('picker: chosen from the keyboard, role select receives focus');
  await rep.locator('#people-list select').selectOption('CC');
  await rep.locator('#people-q').fill('Cora');await rep.locator('.tk-result').first().waitFor();await rep.locator('.tk-result-btn').first().click();
  await rep.locator('#people-list .tk-person').nth(1).locator('select').selectOption('WITNESS');
  await rep.locator('#people-q').fill('Sasha Search');await rep.locator('.tk-result').first().waitFor();assert.equal(await rep.locator('.tk-result').count(),1);ok('picker: already-added people drop out of the results');
  await rep.locator('#people-list .tk-person').nth(1).getByRole('button',{name:/Remove/}).click();assert.equal(await rep.locator('#people-list .tk-person').count(),1);ok('picker: remove works');
  // Other with a short explanation is refused and the form comes back filled in
  await rep.locator('#inc-title').fill('Browser filed case');await rep.locator('#inc-category').selectOption('Other');await rep.locator('#inc-explanation').fill('too short');
  await rep.locator('#inc-at').fill(new Date(Date.now()-86400000).toISOString().slice(0,16));await rep.locator('#inc-location').fill('Browser office');
  await rep.getByRole('button',{name:'Submit incident report'}).click();await rep.waitForLoadState();
  assert.ok(await rep.getByText(/explain what happened/i).first().isVisible());assert.equal(await rep.locator('#inc-title').inputValue(),'Browser filed case');assert.equal(await rep.locator('#people-list .tk-person').count(),1);ok('category Other needs an explanation; the form keeps what was typed, including the people');
  await rep.locator('#inc-category').selectOption('Workplace conduct');await rep.locator('#inc-explanation').fill('A longer, honest description of what happened at the office.');
  await rep.getByRole('button',{name:'Submit incident report'}).click();await rep.waitForLoadState();
  assert.ok(await rep.getByText(/submitted/i).first().isVisible());assert.ok((await titles(rep)).includes('Browser filed case'));ok('filing through the handler: submitted, listed in My Incidents');
  // CSRF and the generic filing action
  const resp=await rep.request.post(url('incident-new'),{form:{action:'core_incident_create',title:'No token'},maxRedirects:0});assert.equal(resp.status(),419);ok('CSRF: a filing POST without a token is refused (419)');
  await rep.goto(url('incident-new'));const csrf=await rep.locator('input[name=csrf]').first().getAttribute('value');
  const before=(await (async()=>{await rep.goto(url('incident-mine'));return titles(rep);})()).length;
  const gen=await rep.request.post(url('hr-request-new'),{form:{csrf,action:'core_ticket_create',ticket_type_id:String(f.irType),submission_key:'a'.repeat(64),title:'Via generic',explanation:'x',intent:'SUBMIT'},maxRedirects:0});
  assert.ok([302,303].includes(gen.status()));await rep.goto(url('incident-mine'));assert.equal((await titles(rep)).length,before);ok('core_ticket_create refuses the Incident Report type and files nothing');
  await rep.goto(url('hr-request-new',{type:String(f.irType)}));assert.ok(rep.url().includes('page=incident-new'));ok('old File an HR Request link for Incident Report redirects to File an Incident');
  await rep.goto(url('hr-request-new'));const sel=await rep.locator('#ticket-type').innerText();assert.ok(!/Incident/.test(sel));ok('File an HR Request no longer lists Incident Report');
  await rep.goto(url('hr-incidents'));assert.ok(rep.url().includes('page=incident-mine'));ok('non-HR opening the old feed lands on My Incidents');
  // people endpoint
  const j=await (await rep.request.get(url('incident-people',{q:'Sasha Search'}))).json();assert.equal(j.people.length,2);assert.ok(j.people.every(p=>Object.keys(p).sort().join()==='branch,id,name'));
  assert.ok(!JSON.stringify(j).includes('example.test'));assert.equal((await (await rep.request.get(url('incident-people',{q:'S'}))).json()).people.length,0);
  const many=await (await rep.request.get(url('incident-people',{q:'Slice15'}))).json();assert.ok(many.people.length<=10);assert.ok(!many.people.some(p=>p.id===f.employee.id));ok('people search: min 2 chars, max 10, name+branch only, not yourself, no inactive');
  // ---- CC
  const cc=await login('cc');await cc.goto(url('incident-mine'));
  assert.deepEqual(await titles(cc),['Fixture case with a CC']);assert.ok(await cc.getByText('Shared with you',{exact:true}).isVisible());
  const t=await cc.locator('body').innerText();assert.ok(!/Involved|Witness/.test(t));ok('CC: sees only the case shared with them, labelled "Shared with you", no role labels');
  await shot(cc,'mine-cc');await cc.goto(url('incident-new'));assert.ok(await cc.locator('#inc-title').isVisible());
  const denied=await cc.request.get(url('hr-request',{id:String(f.caseC)}));assert.equal(denied.status(),403);ok('CC: a case they are not on is refused');
  // ---- involved (no access)
  const inv=await login('involved');await inv.goto(url('incident-mine'));assert.deepEqual(await titles(inv),[]);assert.ok(await inv.getByText('No incident reports yet.').isVisible());ok('Involved person without access sees nothing');
  // ---- no employee record
  const ne=await login('noemp');await ne.goto(url('incident-new'));
  assert.ok(await ne.getByText(/cannot file this for you yet/i).isVisible());assert.equal(await ne.locator('#inc-title').count(),0);await shot(ne,'new-noemployee');ok('account without an Employee 201 record gets a clear message instead of a form');
  // ---- HR
  const hr=await login('hr');await hr.goto(url('incident-mine'));assert.deepEqual(await titles(hr),[]);
  const hs=await hr.locator('.side').innerText();assert.ok(/Incident Cases/i.test(hs)&&/My Incidents/.test(hs));ok('HR: My Incidents is only their own; sidebar also has Incident Cases');
  await shot(hr,'mine-hr');
  await hr.goto(url('hr-incidents'));assert.ok(await hr.getByRole('heading',{name:'Incident Cases'}).isVisible());assert.ok(rep.url()!==null);ok('HR: Incident Cases (the old feed) still opens');
  await hr.goto(url('incident-new'));assert.ok(await hr.locator('#inc-title').count()===0);ok('HR account without an employee record and filing permission is not offered the form');
  // ---- outsider
  const out2=await login('outsider');await out2.goto(url('incident-mine'));assert.deepEqual(await titles(out2),["Someone else's private case"]);ok('another employee sees only their own case');
  assert.deepEqual(problems.filter(x=>!/favicon|fonts|Failed to load resource|http 40[0-9] /.test(x)),[]);ok('no console errors');
  console.log(`RESULT ${passed} passed`);
 }finally{await browser.close();}
})().catch(e=>{console.error('FAIL',e.message);process.exit(1);});
