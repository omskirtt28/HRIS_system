const path=require('node:path'),os=require('node:os'),fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(path.join(os.homedir(),'.cache','codex-runtimes','codex-primary-runtime','dependencies','node','node_modules','playwright'));
const f=JSON.parse(fs.readFileSync('storage/slice15-private/concern-browser-fixtures.json','utf8'));
const base='http://127.0.0.1:8896/index.php';let passed=0;
function url(page,extra={}){return base+'?'+new URLSearchParams({page,...extra});}
async function login(browser,u){const c=await browser.newContext(),p=await c.newPage();await p.goto(url('login'));await p.locator('[name=email]').fill(u.email);await p.locator('[name=password]').fill('Slice15-Test-Only!9');await p.getByRole('button',{name:'Sign in',exact:true}).click();return {c,p};}
(async()=>{const browser=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});try{
 const employee=await login(browser,f.employee),hr=await login(browser,f.hr);
 for(const name of ['ATM Request','ID Request']){
  const p=employee.p;await p.goto(url('hr-request-new'));await p.getByLabel('Request type',{exact:true}).selectOption({label:name});await p.getByLabel('Reason',{exact:true}).waitFor();assert.equal(await p.locator('[name=concern_date]').count(),0);
  await p.getByLabel('Reason',{exact:true}).fill('Browser service request');await p.getByRole('button',{name:'Submit HR request',exact:true}).click();await p.getByRole('heading',{name:'Approval chain',exact:true}).waitFor();const id=Number(new URL(p.url()).searchParams.get('id'));
  assert.equal(await p.locator('[name=outcome]').count(),0);await hr.p.goto(url('hr-request',{id}));await hr.p.getByRole('button',{name:'In Progress',exact:true}).click();await hr.p.getByRole('button',{name:'Served',exact:true}).click();await hr.p.getByText('Served',{exact:true}).waitFor();
  console.log('PASS browser '+name+' file without cutoff, HR serve');passed++;
 }
 await hr.p.goto(url('hr-service-requests',{type_code:'ID_REQUEST',status:'COMPLETED'}));await hr.p.getByRole('heading',{name:'HR Request Queue',exact:true}).waitFor();assert.equal(await hr.p.locator('[name=payroll_period_id]').count(),0);console.log('PASS shared queue filters without payroll controls');passed++;
 await hr.p.screenshot({path:'storage/slice15-private/stageb-queue.png',fullPage:true});
 await employee.p.setViewportSize({width:390,height:844});await employee.p.goto(url('hr-request-new'));await employee.p.getByLabel('Request type',{exact:true}).selectOption({label:'ATM Request'});await employee.p.getByLabel('Reason',{exact:true}).waitFor();assert.ok(await employee.p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));console.log('PASS mobile filing has no horizontal overflow');passed++;
 if(Number(process.env.HRIS_STAGEB_SLICE||4)>=5){
  const p=employee.p;await p.setViewportSize({width:1365,height:900});await p.goto(url('hr-request-new'));await p.getByLabel('Request type',{exact:true}).selectOption({label:'Uniform Request'});
  await p.getByLabel('Position',{exact:true}).fill('Sales Crew');await p.getByLabel('Size',{exact:true}).selectOption('M');await p.getByLabel('Company label',{exact:true}).selectOption('OFJI');await p.getByLabel('Branch',{exact:true}).selectOption(String(f.branch));await p.getByLabel('Quantity',{exact:true}).fill('2');await p.getByLabel('ID lace',{exact:true}).selectOption('YES');await p.getByRole('button',{name:'Submit HR request',exact:true}).click();await p.getByRole('heading',{name:'Approval chain',exact:true}).waitFor();const id=Number(new URL(p.url()).searchParams.get('id'));
  await hr.p.goto(url('hr-request',{id}));await hr.p.getByRole('button',{name:'Served',exact:true}).click();await hr.p.getByText('Served',{exact:true}).waitFor();console.log('PASS Uniform browser filing and HR completion');passed++;
 }
 if(Number(process.env.HRIS_STAGEB_SLICE||4)>=6){
  await hr.p.goto(url('hr-ticket-accounting-members'));await hr.p.getByLabel('Account',{exact:true}).selectOption(String(f.outsider.id));await hr.p.getByRole('button',{name:'Add Accounting member',exact:true}).click();
  const accounting=await login(browser,f.outsider),p=employee.p;await p.goto(url('hr-request-new'));await p.getByLabel('Request type',{exact:true}).selectOption({label:'NetSuite Concern'});await p.getByLabel('Concern remarks',{exact:true}).fill('Browser NetSuite concern');await p.getByRole('button',{name:'Submit HR request',exact:true}).click();await p.getByRole('heading',{name:'Approval chain',exact:true}).waitFor();const id=Number(new URL(p.url()).searchParams.get('id'));
  assert.equal((await accounting.c.request.get(url('hr-request',{id}))).status(),403);
  await hr.p.goto(url('hr-request',{id}));await hr.p.getByLabel('HR remarks',{exact:true}).fill('HR browser note');await hr.p.getByRole('button',{name:'Save HR remarks',exact:true}).click();await hr.p.getByRole('button',{name:'Complete',exact:true}).click();await hr.p.getByText('Complete',{exact:true}).waitFor();
  await accounting.p.goto(url('hr-request',{id}));await accounting.p.getByLabel('ACCOUNTING remarks',{exact:true}).fill('Accounting browser note');await accounting.p.getByRole('button',{name:'Save ACCOUNTING remarks',exact:true}).click();await accounting.p.getByText('Accounting browser note',{exact:true}).waitFor();assert.equal(await accounting.p.getByLabel('HR remarks',{exact:true}).count(),0);
  console.log('PASS NetSuite browser gates Accounting until HR completion and separates remarks');passed++;
 }
 console.log('RESULT '+passed+' passed, 0 failed');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
