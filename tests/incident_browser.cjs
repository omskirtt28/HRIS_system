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
  const shot=async(p,name)=>{await p.addStyleTag({content:'.tk-sticky{position:static!important}'});for(const [w,h] of [[1440,900],[390,844]])for(const theme of ['light','dark']){await p.setViewportSize({width:w,height:h});await p.evaluate(t=>{document.documentElement.dataset.theme=t;},theme);await p.waitForTimeout(450);await clean(p,name+' '+w+' '+theme);await p.screenshot({path:`${out}/incident-${name}-${w}-${theme}.png`,fullPage:true});}await p.setViewportSize({width:1440,height:900});await p.evaluate(()=>{document.documentElement.dataset.theme='light';});};
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
  // =============================================================== I3: Case page
  const caseUrl=id=>url('hr-request',{id:String(id)});
  const getCsrf=async p=>{await p.goto(url('incident-new'));return p.locator('input[name=csrf]').first().getAttribute('value');};// any page with a form carries the session token
  const post=async(p,form,csrf)=>p.request.post(url('incident-act'),{form:{csrf,...form},maxRedirects:0});
  const current=async p=>p.locator('.tk-step.is-current').first().innerText();
  const rita=await login('rita');
  rita.on('dialog',d=>d.accept());
  await rita.goto(caseUrl(f.caseD));
  assert.ok(await rita.getByRole('heading',{name:'Flow case for the page'}).isVisible());
  assert.equal(await rita.locator('.tk-steps .tk-step').count(),5);assert.match(await current(rita),/Submitted/);
  assert.ok(await rita.getByText('Withdraw this report').first().isVisible());assert.equal(await rita.getByText('HR actions').count(),0);
  let html=await rita.content();assert.ok(!/Involved|Witness|tk-internal|cannot read this case/.test(html));
  await named(rita,'case page (reporter)');ok('reporter: case page with header, five-step stepper, withdraw card; no HR panel, no role labels of others, controls labelled');
  await rita.locator('#inc-message').fill('First message from the reporter');await rita.getByRole('button',{name:'Send message'}).click();await rita.waitForLoadState();
  assert.ok(await rita.getByText('Message sent.').isVisible());assert.ok(await rita.locator('.tk-post.is-mine').getByText('First message from the reporter').isVisible());ok('reporter: posts a message from the sticky composer; it shows as theirs');
  // HR picks it up
  const hr2=await login('hr');hr2.on('dialog',d=>d.accept());await hr2.goto(caseUrl(f.caseD));
  assert.ok(await hr2.getByRole('heading',{name:/HR actions/}).isVisible());html=await hr2.content();assert.ok(/Recorded for HR only; cannot read this case/.test(html)&&/Involved/.test(html));
  await named(hr2,'case page (HR)');ok('HR: panel, role labels and access wording visible to the handling reviewer');
  const version=await hr2.locator('input[name=version]').first().getAttribute('value');
  await hr2.locator('#inc-to').selectOption('UNDER_REVIEW');
  assert.ok(await hr2.evaluate(()=>document.querySelector('#inc-remarks').validity.valueMissing));ok('HR: remarks are required before a status change can be sent');
  await hr2.locator('#inc-remarks').fill('We have started reviewing this.');await hr2.locator('#inc-status-note').fill('PRIVATE-NOTE-ONE');await hr2.getByRole('button',{name:'Save status'}).click();await hr2.waitForLoadState();
  assert.ok(await hr2.getByText(/Status updated/).isVisible());assert.ok(await hr2.getByText('We have started reviewing this.').isVisible());assert.ok(await hr2.getByText('PRIVATE-NOTE-ONE').isVisible());
  assert.match(await current(hr2),/Under review/);ok('HR: status saved with remarks; HR update card and the internal note are in the thread; stepper moves');
  await hr2.locator('#inc-note').fill('PRIVATE-NOTE-TWO');await hr2.getByRole('button',{name:'Save internal note'}).click();await hr2.waitForLoadState();assert.ok(await hr2.getByText('PRIVATE-NOTE-TWO').isVisible());ok('HR: internal note saved');
  // include Lena by search
  await hr2.locator('#inc-add-q').fill('Lena Lenal');await hr2.locator('#inc-add-results .tk-result-btn').first().waitFor();
  assert.match(await hr2.locator('#inc-add-results').innerText(),/Lena Lenalatepick/);
  await hr2.locator('#inc-add-results .tk-result-btn').first().click();assert.ok(await hr2.evaluate(()=>document.activeElement.id==='inc-add-role'));
  await hr2.locator('#inc-add-role').selectOption('WITNESS');await hr2.getByRole('button',{name:'Include in case'}).click();await hr2.waitForLoadState();
  assert.ok(await hr2.getByText(/Person included/).first().isVisible());assert.ok(await hr2.locator('.tk-rail-person',{hasText:'Lena Lenalatepick'}).getByText(/Included by HR/).isVisible());ok('HR: finds a person, includes them as Witness; the rail says HR included them');
  const searchJson=await (await hr2.request.get(url('incident-people',{q:'Rita',case:String(f.caseD)}))).json();assert.ok(!searchJson.people.some(p=>/Rita/.test(p.name)));ok('HR: case search leaves out people who already read the case');
  await shot(hr2,'case-hr');
  // CC, reporter and included person
  const carl=await login('carl');await carl.goto(caseUrl(f.caseD));
  html=await carl.content();assert.ok(/We have started reviewing this\./.test(html)&&/First message from the reporter/.test(html));
  assert.ok(!/PRIVATE-NOTE|tk-internal|HR actions|Involved|Witness|Included by HR|Person included/.test(html));assert.equal(await carl.getByText('Withdraw this report').count(),0);ok('CC: sees the conversation and the HR update; no internal notes, HR panel, role labels or people events anywhere in the markup');
  await carl.locator('#inc-message').fill('CC says hello');await carl.getByRole('button',{name:'Send message'}).click();await carl.waitForLoadState();assert.ok(await carl.getByText('CC says hello').isVisible());
  await shot(carl,'case-cc');
  await rita.goto(caseUrl(f.caseD));html=await rita.content();assert.ok(/CC says hello/.test(html)&&!/PRIVATE-NOTE|tk-internal|Withdraw this report|Person included/.test(html));
  await shot(rita,'case-reporter');ok('reporter: sees the HR update and the CC message, no internal notes; withdraw card gone once HR started');
  const lena=await login('lena');const lr=await lena.goto(caseUrl(f.caseD));assert.equal(lr.status(),200);html=await lena.content();assert.ok(!/Involved|Witness|PRIVATE-NOTE/.test(html));ok('included person: can read the case, sees no role label of others');
  // access matrix over HTTP (GET)
  const inaPage=await login('ina'),viewerPage=await login('viewer'),sensPage=await login('sens');const got={};
  for(const [name,page,id] of [['ina',inaPage,f.caseD],['outsider',out2,f.caseD],['viewer',viewerPage,f.caseD],['sens',sensPage,f.caseD],['noemp',ne,f.caseD],['cc-other-case',cc,f.caseC]])got[name]=(await page.request.get(caseUrl(id),{maxRedirects:0})).status();
  assert.deepEqual(got,{ina:403,outsider:403,viewer:403,sens:403,noemp:403,'cc-other-case':403});
  ok('HTTP access matrix: Involved without access, outsider, viewer, sensitive-view holder, account without a record and a CC on another case all get 403 on the case page');
  // forged POSTs (valid CSRF, wrong person)
  const ritaCsrf=await getCsrf(rita),carlCsrf=await getCsrf(carl),inaCsrf=await getCsrf(inaPage),outCsrf=await getCsrf(out2);
  await post(rita,{action:'core_incident_status',ticket_id:String(f.caseD),version:String(version),to:'RESOLVED',remarks:'forged'},ritaCsrf);
  await post(rita,{action:'core_incident_note',ticket_id:String(f.caseD),body:'FORGED-NOTE'},ritaCsrf);
  await post(rita,{action:'core_incident_participant_add',ticket_id:String(f.caseD),user_id:String(f.involved.id),role:'CC'},ritaCsrf);
  await post(rita,{action:'core_incident_participant_remove',ticket_id:String(f.caseD),user_id:String(f.carl.id)},ritaCsrf);
  await post(carl,{action:'core_incident_status',ticket_id:String(f.caseD),version:String(version),to:'CLOSED',remarks:'forged'},carlCsrf);
  await post(carl,{action:'core_incident_note',ticket_id:String(f.caseD),body:'FORGED-NOTE-2'},carlCsrf);
  await post(inaPage,{action:'core_incident_message',ticket_id:String(f.caseD),body:'FORGED-MESSAGE'},inaCsrf);
  await post(out2,{action:'core_incident_message',ticket_id:String(f.caseD),body:'OUTSIDER-MESSAGE'},outCsrf);
  await hr2.goto(caseUrl(f.caseD));html=await hr2.content();
  assert.ok(!/FORGED|OUTSIDER-MESSAGE/.test(html));assert.match(await current(hr2),/Under review/);assert.equal(await hr2.locator('.tk-rail-person',{hasText:'Carl Carlcc'}).count(),1);assert.equal(await hr2.locator('.tk-rail-person',{hasText:'Ivan'}).count(),0);
  ok('forged POSTs from the reporter, a CC, an Involved person without access and an outsider change nothing (status, notes, people, messages)');
  const noCsrf=await rita.request.post(url('incident-act'),{form:{action:'core_incident_message',ticket_id:String(f.caseD),body:'no token'},maxRedirects:0});assert.equal(noCsrf.status(),419);
  assert.equal((await rita.request.get(url('incident-people',{q:'Carl',case:String(f.caseD)}))).status(),403);
  assert.equal((await out2.request.get(url('incident-people',{q:'Carl',case:String(f.caseD)}))).status(),403);
  await rita.goto(url('incident-act'));assert.ok(rita.url().includes('page=incident-mine'));
  ok('CSRF missing returns 419; the case people search is refused to the reporter and an outsider; GET on the action page just redirects');
  // remove Lena, then she is locked out
  await hr2.goto(caseUrl(f.caseD));await hr2.locator('.tk-rail-person',{hasText:'Lena Lenalatepick'}).getByRole('button',{name:/Remove/}).click();await hr2.waitForLoadState();
  assert.ok(await hr2.getByText(/no longer read/).first().isVisible());assert.equal((await lena.request.get(caseUrl(f.caseD),{maxRedirects:0})).status(),403);ok('HR: removes a person (confirmation accepted); their access ends at once');
  // resolve, close: composer replaced by a notice; reopen offered
  await hr2.locator('#inc-to').selectOption('RESOLVED');await hr2.locator('#inc-remarks').fill('Resolved after talking to everyone.');await hr2.getByRole('button',{name:'Save status'}).click();await hr2.waitForLoadState();
  assert.equal(await hr2.locator('.tk-step.is-skipped').count(),1);ok('HR: resolving straight from Under review marks In progress as skipped');
  await hr2.locator('#inc-to').selectOption('CLOSED');await hr2.locator('#inc-remarks').fill('Closing the case.');await hr2.getByRole('button',{name:'Save status'}).click();await hr2.waitForLoadState();
  await rita.goto(caseUrl(f.caseD));assert.ok(await rita.getByText('This case is closed to new messages.').isVisible());assert.equal(await rita.locator('#inc-message').count(),0);
  await hr2.goto(caseUrl(f.caseD));assert.equal(await hr2.locator('#inc-to option',{hasText:'Reopen: In Progress'}).count(),1);ok('closed case: reporter has no composer; HR is offered Reopen');
  await shot(rita,'case-reporter-closed');
  // withdraw
  await rita.goto(caseUrl(f.caseE));await rita.getByRole('button',{name:'Withdraw report'}).click();await rita.waitForLoadState();
  assert.ok(await rita.getByText('The report was withdrawn.').isVisible());assert.equal(await rita.locator('.tk-step.is-stop').count(),1);assert.equal(await rita.getByText('Withdraw this report').count(),0);ok('reporter: withdraws a Submitted report; stepper stops, pill says Cancelled');
  await rita.goto(url('incident-mine'));await rita.getByRole('link',{name:'Flow case for the page'}).click();await rita.waitForLoadState();assert.ok(rita.url().includes('page=hr-request'));assert.ok(await rita.locator('.tk-case-grid').isVisible());ok('My Incidents opens the Case page');
  assert.deepEqual(problems.filter(x=>!/favicon|fonts|Failed to load resource|http 40[0-9] /.test(x)),[]);ok('no console errors');
  console.log(`RESULT ${passed} passed`);
 }finally{await browser.close();}
})().catch(e=>{console.error('FAIL',e.message);process.exit(1);});
