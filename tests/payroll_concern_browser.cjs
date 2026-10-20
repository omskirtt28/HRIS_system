const path=require('node:path'),os=require('node:os'),fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.HRIS_PLAYWRIGHT_MODULE||path.join(os.homedir(),'.cache','codex-runtimes','codex-primary-runtime','dependencies','node','node_modules','playwright'));
const f=JSON.parse(fs.readFileSync('storage/slice15-private/concern-browser-fixtures.json','utf8'));
const base='http://127.0.0.1:8896/index.php';let passed=0;
function pass(name){console.log('PASS '+name);passed++;}
function url(page,params={}){return base+'?'+new URLSearchParams({page,...params});}
async function login(browser,user,destination){
 const context=await browser.newContext({viewport:{width:1365,height:900}}),page=await context.newPage();
 await page.goto(url('login'));await page.locator('input[name=email]').fill(user.email);await page.locator('input[name=password]').fill('Slice15-Test-Only!9');
 await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.waitForLoadState('domcontentloaded');
 if(destination)await page.goto(url(destination));return {context,page};
}
async function file(page,date,note,attachment=false){
 await page.goto(url('hr-request-new'));await page.getByLabel('Request type',{exact:true}).selectOption(String(f.type));
 await page.getByLabel('Date',{exact:true}).fill(date);await page.getByLabel('Remarks / Explanation',{exact:true}).fill(note);
 if(attachment)await page.getByLabel('Supporting attachments (optional)',{exact:true}).setInputFiles({name:'proof.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a/TsAAAAASUVORK5CYII=','base64')});
 await page.getByRole('button',{name:'Submit HR request',exact:true}).click();await page.getByRole('heading',{name:'Approval chain',exact:true}).waitFor();
 return Number(new URL(page.url()).searchParams.get('id'));
}
(async()=>{const browser=await chromium.launch({channel:'msedge',headless:true});
 try{
  const employee=await login(browser,f.employee,'employee-dashboard');const page=employee.page;
  await page.getByRole('heading',{name:'Quick actions',exact:true}).waitFor();
  const tiles=page.locator('.quick-action');assert.equal(await tiles.count(),5);
  for(const [label,destination] of [['Apply for leave','employee-leave'],['Payroll & Timekeeping','employee-request-new'],['Request COE','#'],['My requests','employee-requests'],['HR Concerns','hr-requests-my']]){
   const tile=tiles.filter({has:page.locator('strong',{hasText:new RegExp('^'+label.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+'$')})});
   const href=await tile.getAttribute('href');assert.ok(destination==='#'?href==='#':href.includes('page='+destination));
  }pass('Home preserves four old tiles/routes and adds HR Concerns tile');
  assert.equal(await page.locator('.quick-action').filter({hasText:'HR Concerns'}).locator('span span').last().innerText(),'Payroll concern and other HR requests');pass('HR Concerns tile uses requested subtitle');
  const nav=page.locator('.nav-group').filter({has:page.locator('.gl',{hasText:/^Requests$/})});assert.equal(await nav.getByRole('link',{name:'HR Concerns',exact:true}).count(),1);pass('new ticket entry appears under sidebar Requests');
  await page.goto(url('hr-request-new'));assert.equal(await page.getByLabel('Request type',{exact:true}).locator('option:not([value=""])').count(),1);pass('filing form offers Payroll Concern type');
  assert.ok((await page.locator('.ticket-facts').innerText()).includes(f.tag+'_requester'));pass('employee identity is prefilled');
  await page.getByLabel('Request type',{exact:true}).selectOption(String(f.type));await page.getByLabel('Date',{exact:true}).fill('2021-01-10');await page.getByLabel('Remarks / Explanation',{exact:true}).fill('Preserve explanation on upload validation failure');
  await page.getByLabel('Supporting attachments (optional)',{exact:true}).setInputFiles({name:'bad.txt',mimeType:'text/plain',buffer:Buffer.from('not a supported attachment')});
  await page.getByRole('button',{name:'Submit HR request',exact:true}).click();await page.getByText('Attach PDF, JPG, PNG, or WEBP files with a matching file extension.',{exact:true}).waitFor();
  assert.equal(await page.getByLabel('Remarks / Explanation',{exact:true}).inputValue(),'Preserve explanation on upload validation failure');pass('invalid upload rejected and form explanation preserved');
  const ticketId=await file(page,'2021-01-10','Browser HO payroll concern',true);pass('employee files HO concern with real multipart attachment');
  await page.getByRole('link',{name:'proof.png',exact:true}).waitFor();const attachmentUrl=await page.getByRole('link',{name:'proof.png',exact:true}).getAttribute('href');
  const download=await employee.context.request.get(new URL(attachmentUrl,base).toString());assert.equal(download.status(),200);assert.match(download.headers()['content-type'],/image\/png/);pass('requester can download protected attachment');
  await page.getByLabel('Add a comment',{exact:true}).fill('Browser employee follow-up');await page.getByRole('button',{name:'Post comment',exact:true}).click();await page.getByText('Browser employee follow-up',{exact:true}).waitFor();pass('comment persists and appears in detail');
  assert.equal(await page.getByRole('button',{name:'Approve and send to HR',exact:true}).count(),0);pass('requester has no self-approval action');
  await page.goto(url('employee-dashboard'));await page.getByText('HR Concern · Payroll Concern',{exact:true}).waitFor();pass('Home Recent requests labels new engine clearly');
  await page.screenshot({path:'storage/slice15-private/hr-concerns-home-desktop.png',fullPage:true});
  await page.setViewportSize({width:390,height:844});await page.waitForFunction(()=>document.getElementById('side').getBoundingClientRect().right<=1);await page.evaluate(()=>document.fonts.ready);
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));await page.screenshot({path:'storage/slice15-private/hr-concerns-home-mobile.png',fullPage:true});await page.setViewportSize({width:1365,height:900});pass('Home with all five quick actions fits mobile viewport');
  const manager=await login(browser,f.manager,'hr-request-inbox');
  await manager.page.getByRole('heading',{name:'HR Request Inbox',exact:true}).waitFor();await manager.page.locator('a[href="index.php?page=hr-request&id='+ticketId+'"]').click();
  await manager.page.getByLabel('Review remarks / HR outcome explanation',{exact:true}).fill('Manager checked concern');await manager.page.getByRole('button',{name:'Approve and send to HR',exact:true}).click();
  await manager.page.getByText('Pending Hr',{exact:true}).first().waitFor();pass('Manager inbox approval advances HO concern to HR');
  const hr=await login(browser,f.hr,'hr-payroll-concerns');await hr.page.goto(url('hr-payroll-concerns',{business_unit_id:f.company,status:'PENDING_HR',branch_id:f.ho,payroll_period_id:f.open}));
  await hr.page.locator('a[href="index.php?page=hr-request&id='+ticketId+'"]').waitFor();pass('HR queue filters company, branch, status and period');
  await hr.page.goto(url('hr-request',{id:ticketId}));await hr.page.getByRole('button',{name:'Start HR review',exact:true}).click();await hr.page.getByText('In Progress',{exact:true}).first().waitFor();
  await hr.page.getByLabel('Review remarks / HR outcome explanation',{exact:true}).fill('Browser HR reviewed: no change');await hr.page.getByLabel('HR outcome',{exact:true}).selectOption('NO_CHANGE');await hr.page.getByRole('button',{name:'Complete HR concern',exact:true}).click();
  await hr.page.getByRole('heading',{name:'HR outcome: No Change',exact:true}).waitFor();pass('HO browser chain completes with no change');
  const branch=await login(browser,f.outsider,'hr-requests-my');const branchId=await file(branch.page,'2021-01-10','Browser branch payroll concern');
  const adl=await login(browser,f.adl,'hr-request-inbox');await adl.page.goto(url('hr-request',{id:branchId}));await adl.page.getByRole('button',{name:'Approve and send to HR',exact:true}).click();
  await adl.page.getByText('Pending Hr',{exact:true}).first().waitFor();await hr.page.goto(url('hr-request',{id:branchId}));await hr.page.getByLabel('Review remarks / HR outcome explanation',{exact:true}).fill('Branch checked: no change');await hr.page.getByLabel('HR outcome',{exact:true}).selectOption('NO_CHANGE');await hr.page.getByRole('button',{name:'Complete HR concern',exact:true}).click();
  await hr.page.getByRole('heading',{name:'HR outcome: No Change',exact:true}).waitFor();pass('branch browser chain completes through assigned ADL and HR');
  const lateId=await file(page,'2020-12-25','Browser late concern against finalized cutoff');await page.getByText(/Late concern: Finalized/).waitFor();pass('finalized-cutoff filing visibly takes late HR-only path');
  const denied=await branch.context.request.get(url('hr-request',{id:ticketId}));assert.equal(denied.status(),403);
  const deniedFile=await branch.context.request.get(new URL(attachmentUrl,base).toString());assert.equal(deniedFile.status(),403);pass('outsider cannot read ticket or download attachment');
  const deniedQueue=await employee.context.request.get(url('hr-payroll-concerns'));assert.equal(deniedQueue.status(),403);pass('employee direct HR queue access denied');
  const csrf=await page.locator('input[name=csrf]').first().inputValue();const invalid=await employee.context.request.post(url('hr-request',{id:lateId}),{form:{action:'core_ticket_comment',ticket_id:lateId,body:'bad',csrf:'invalid'}});assert.equal(invalid.status(),419);pass('ticket mutation rejects invalid CSRF');
  await hr.page.goto(url('hr-request',{id:lateId}));await hr.page.getByLabel('Review remarks / HR outcome explanation',{exact:true}).fill('Browser manual next-period instruction');await hr.page.getByLabel('HR outcome',{exact:true}).selectOption('MANUAL_ADJUSTMENT');await hr.page.getByLabel('Next open cutoff (manual adjustment only)',{exact:true}).selectOption(String(f.open));
  const version=await hr.page.locator('[data-ticket-decision] input[name=version]').inputValue();const hrCsrf=await hr.page.locator('input[name=csrf]').first().inputValue();
  await hr.page.getByRole('button',{name:'Complete HR concern',exact:true}).click();await hr.page.getByText('Manual processing instruction recorded',{exact:true}).waitFor();pass('HR completes late concern with manual next-open-period instruction');
  const replay=await hr.context.request.post(url('hr-request',{id:lateId}),{form:{action:'core_ticket_act',ticket_id:lateId,version,decision:'COMPLETE',remarks:'Browser manual next-period instruction',outcome:'MANUAL_ADJUSTMENT',target_period_id:f.open,csrf:hrCsrf}});
  assert.equal((await replay.text()).match(/Manual processing instruction recorded/g).length,1);pass('completion replay displays one manual effect');
  await page.goto(url('hr-request',{id:ticketId}));await page.setViewportSize({width:390,height:844});await page.waitForFunction(()=>document.getElementById('side').getBoundingClientRect().right<=1);await page.evaluate(()=>document.fonts.ready);
  const width=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth}));assert.ok(width.scroll<=width.width+2);await page.screenshot({path:'storage/slice15-private/payroll-concern-mobile.png',fullPage:true});pass('ticket detail fits mobile viewport');
  await hr.page.setViewportSize({width:1365,height:900});await hr.page.screenshot({path:'storage/slice15-private/payroll-concern-desktop.png',fullPage:true});
  fs.writeFileSync('storage/slice15-private/concern-browser-result.json',JSON.stringify({ticketId,branchId,lateId,passed}));console.log('RESULT '+passed+' browser checks passed, 0 failed');
 }finally{await browser.close();}
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
