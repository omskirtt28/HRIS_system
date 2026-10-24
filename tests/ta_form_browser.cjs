// File TA form shows the existing logs. Usage: node tests/ta_form_browser.cjs <before|after>   (server on 127.0.0.1:8895)
const path=require('node:path'),os=require('node:os'),fs=require('node:fs');
const {chromium}=require(process.env.HRIS_PLAYWRIGHT_MODULE||path.join(os.homedir(),'.cache','codex-runtimes','codex-primary-runtime','dependencies','node','node_modules','playwright'));
const f=JSON.parse(fs.readFileSync('storage/slice15-private/ta-form-fixtures.json','utf8'));
const tag=process.argv[2]||'after',base='http://127.0.0.1:8895/index.php';
let passed=0,failed=0;
function check(name,ok,detail=''){ok?passed++:failed++;console.log((ok?'PASS ':'FAIL ')+name+(ok||!detail?'':' -> '+detail));}
(async()=>{const browser=await chromium.launch({channel:'msedge',headless:true});
 try{
  for(const [label,width,height] of [['1440',1440,1000],['390',390,900]]){
   const context=await browser.newContext({viewport:{width,height}}),page=await context.newPage();
   await page.goto(base+'?page=login');await page.locator('input[name=email]').fill(f.email);await page.locator('input[name=password]').fill('Slice15-Test-Only!9');
   await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.waitForLoadState('domcontentloaded');
   for(const [day,key] of [['2026-10-12','ambiguous'],['2026-10-15','four']]){
    await page.goto(base+'?'+new URLSearchParams({page:'employee-request-new',type:'TA',date:day,cutoff_id:'47'}));
    const block=page.locator('.employee-ta-block');await block.waitFor({state:'visible'});await page.waitForTimeout(600);
    const clocks=await block.locator('[data-ta-clock]').allInnerTexts();
    const visible=[];for(const name of ['time_in','lunch_out','lunch_in','time_out'])visible.push(await block.locator('[data-ta-field="'+name+'"]').isVisible());
    const msg=(await block.locator('[data-ta-message]').innerText()).trim();
    console.log(label+' '+key+': logs='+clocks.join(' | ')+' inputs visible='+visible.join(',')+' msg="'+msg+'"');
    if(tag==='after'&&key==='ambiguous'){
     check(label+' ambiguous day: existing logs shown (12:17 PM, 5:08 PM)',/12:17 PM/.test(clocks[0])&&/5:08 PM/.test(clocks[1]),clocks.join('|'));
     check(label+' ambiguous day: only Break In and Time Out inputs shown',visible.join()==='false,false,true,true',visible.join());
     check(label+' ambiguous day: message asks for missing only',msg.startsWith('Add missing Break In, Time Out.'),msg);
    }
    if(tag==='after'&&key==='four')check(label+' four-punch day: no inputs, no TA needed',visible.every(v=>!v)&&/No TA is needed/.test(msg),visible.join()+' '+msg);
    if(key==='ambiguous')await page.screenshot({path:'storage/slice15-private/ta-form-'+tag+'-'+label+'.png',fullPage:true});
   }
   await context.close();
  }
 }finally{await browser.close();}
 console.log('RESULT '+passed+' passed, '+failed+' failed');process.exit(failed?1:0);
})().catch(e=>{console.error(e);process.exit(1);});
