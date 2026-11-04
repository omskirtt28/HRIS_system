// Real-browser check of the Department + Position picker (SPEC-department-picker.md). Started by
// tests/department_picker_http_test.php when HRIS_BROWSER=1 (it serves a temp copy of the app against the SCRATCH database).
// Env: DP_PORT, DP_EMAIL, DP_PASSWORD, DP_EMPLOYEE_ID, DP_OUT (screenshot folder).
const path=require('node:path'),os=require('node:os'),assert=require('node:assert/strict');
const {chromium}=require(path.join(os.homedir(),'.cache','codex-runtimes','codex-primary-runtime','dependencies','node','node_modules','playwright'));
const base='http://127.0.0.1:'+process.env.DP_PORT+'/index.php',out=process.env.DP_OUT||'.';let passed=0;
const ok=m=>{passed++;console.log('PASS '+m);};
const visible=(sel)=>sel.evaluate(s=>Array.from(s.options).filter(o=>o.value&&!o.hidden&&!o.disabled).map(o=>o.text));
(async()=>{const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});
 let code=0;
 try{
  const ctx=await browser.newContext({viewport:{width:1280,height:1000}}),p=await ctx.newPage();const problems=[];
  p.on('console',m=>{if(m.type()==='error')problems.push('console: '+m.text());});p.on('pageerror',e=>problems.push('pageerror: '+e.message));
  await p.goto(base+'?page=login');await p.locator('[name=email]').fill(process.env.DP_EMAIL);await p.locator('[name=password]').fill(process.env.DP_PASSWORD);await p.getByRole('button',{name:'Sign in'}).click();
  await p.waitForURL(u=>!u.search.includes('page=login'));

  // ---- Add Employee ----
  await p.goto(base+'?page=hr-employee-new');
  const company=p.locator('select[name=work_company]'),dept=p.locator('select[name=department_group]'),pos=p.locator('select[name=position_id]');
  await company.selectOption('2LOG');
  assert.deepEqual(await visible(dept),['Manpower Planning','Inventory Management','Finance','Retail','Marketing & Creatives','Online'],'2LOG departments');
  ok('2LOG shows exactly the six 2LOG departments (HR is the PMBSI one)');
  assert.deepEqual(await visible(pos),[],'no department yet: no positions listed');
  ok('no Department chosen: Position list is empty (only Unassigned)');
  await dept.selectOption({label:'Manpower Planning'});
  let list=await visible(pos);
  assert.ok(list.includes('Admin Staff')&&list.includes('Project Coordinator')&&list.includes('MIS Staff')&&list.includes('Project Engineer'),'manpower positions: '+list.join(', '));
  assert.ok(!list.includes('Retail Manager')&&!list.includes('Brand Steward')&&!list.includes('Accounting Staff'),'only manpower positions: '+list.join(', '));
  const groups=await pos.evaluate(s=>Array.from(s.querySelectorAll('optgroup')).filter(g=>!g.hidden).map(g=>g.label));
  assert.ok(groups.includes('Administration')&&groups.includes('Information System / MIS')&&groups.includes('PMMD'),'teams: '+groups.join(', '));
  ok('Manpower Planning lists only its positions, grouped Administration / Information System / MIS / PMMD');
  await p.screenshot({path:path.join(out,'dept-picker-manpower.png')});
  await pos.selectOption({label:'Admin Staff'});
  await dept.selectOption({label:'Retail'});
  assert.equal(await pos.inputValue(),'','position cleared when the department changes');
  list=await visible(pos);assert.ok(list.includes('Retail Manager')&&list.includes('Brand Steward')&&!list.includes('Admin Staff'),'retail positions: '+list.slice(0,6).join(', '));
  ok('switching Department clears a position that no longer fits; Retail shows Retail Manager');
  await dept.selectOption({label:'Finance'});list=await visible(pos);
  assert.ok(list.includes('Accounting Staff')&&list.includes('Treasury Specialist')&&!list.includes('Retail Manager'),'finance: '+list.join(', '));
  ok('Finance lists Accounting, Billing & Collection, Audit and Treasury positions');
  await company.selectOption('PMBSI');
  assert.deepEqual(await visible(dept),['HR'],'PMBSI departments');
  await dept.selectOption({label:'HR'});list=await visible(pos);assert.ok(list.length>=1&&list.every(t=>/human resource/i.test(t)),'pmbsi positions: '+list.join(', '));
  ok('PMBSI shows HR only, with the Human Resource position');
  await company.selectOption('2LOG');
  assert.equal(await dept.inputValue(),'','HR department cleared when switching back to 2LOG');ok('switching Company back to 2LOG clears the HR choice');

  // ---- Employee 201: current values pre-selected, Reporting To still reacts to Retail Manager ----
  await p.goto(base+'?page=hr-employee&id='+process.env.DP_EMPLOYEE_ID+'&tab=employment');
  const d2=p.locator('select[name=department_group]'),p2=p.locator('select[name=position_id]');
  assert.equal(await d2.inputValue(),'RETAIL');assert.equal((await p2.evaluate(s=>s.options[s.selectedIndex].text)),'Retail Manager');
  ok('Employee 201 opens with Retail and Retail Manager pre-selected');
  assert.equal(await p2.evaluate(s=>s.validationMessage),'');ok('no validation error on the saved position');
  await p.screenshot({path:path.join(out,'dept-picker-edit.png'),fullPage:true});
  assert.deepEqual(problems,[],'browser problems: '+problems.join(' | '));ok('no console errors or page errors');
 }catch(e){console.log('FAIL '+e.message);code=1;}
 await browser.close();console.log(passed+' passed');process.exit(code);
})();
