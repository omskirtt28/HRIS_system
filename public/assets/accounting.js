/* Journal entry and bill forms: live debit/credit totals and an Add line button. The server re-validates everything. */
(()=>{
const form=document.getElementById('acEntryForm')||document.getElementById('acBillForm')||document.getElementById('acInvoiceForm'); if(!form) return;
const body=form.querySelector('[data-ac-lines]');
const cents=v=>{const s=String(v).replace(/[,\s]/g,'');return /^\d+(\.\d{1,2})?$/.test(s)?Math.round(parseFloat(s)*100):0};
const fmt=c=>(c/100).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
function totals(){
 let d=0,c=0;body.querySelectorAll('[data-ac-dr]').forEach(i=>d+=cents(i.value));body.querySelectorAll('[data-ac-cr]').forEach(i=>c+=cents(i.value));
 form.querySelector('[data-ac-tdr]').textContent=fmt(d);
 const tcr=form.querySelector('[data-ac-tcr]'),diff=form.querySelector('[data-ac-diff]');if(!tcr)return; // bill form: one amount column
 tcr.textContent=fmt(c);
 diff.textContent=d===c?(d?'Balanced':''):'Difference '+fmt(Math.abs(d-c));diff.className=d===c&&d?'ac-good':'ac-muted';
}
form.querySelector('[data-ac-add]').addEventListener('click',()=>{
 const rows=body.querySelectorAll('tr');if(rows.length>=50)return;
 const n=rows.length,row=rows[0].cloneNode(true);
 row.querySelectorAll('input,select').forEach(el=>{el.name=el.name.replace(/lines\[\d+\]/,`lines[${n}]`);el.value='';el.setAttribute('aria-label',el.getAttribute('aria-label').replace(/line \d+/,`line ${n+1}`))});
 body.appendChild(row);row.querySelector('select').focus();
});
body.addEventListener('input',totals);totals();
})();
/* Payment form: running total and "Full" buttons. Reconciliation: live cleared balance and difference. The server re-validates. */
(()=>{
const cents=v=>{const s=String(v).replace(/[,\s]/g,'');return /^\d+(\.\d{1,2})?$/.test(s)?Math.round(parseFloat(s)*100):0};
const signed=v=>{let s=String(v).trim(),n=1;const m=s.match(/^\((.*)\)$/);if(m){s=m[1];n=-1}else if(s.startsWith('-')){s=s.slice(1);n=-1}return s.trim()===''?null:n*cents(s)};
const fmt=c=>{const s=(Math.abs(c)/100).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});return c<0?'('+s+')':s};
const pay=document.getElementById('acPayForm');
if(pay){const out=pay.querySelector('[data-ac-ptotal]');const sum=()=>{if(!out)return;let t=0;pay.querySelectorAll('[data-ac-amt]').forEach(i=>t+=cents(i.value));out.textContent=fmt(t)};
 pay.addEventListener('click',e=>{const b=e.target.closest('[data-ac-fill]');if(!b)return;const i=b.parentElement.querySelector('[data-ac-amt]');i.value=b.dataset.acFill;sum()});
 pay.addEventListener('input',sum);sum();}
const rec=document.getElementById('acReconForm');
if(rec){const base=parseInt(rec.dataset.cleared,10)||0,cl=rec.querySelector('[data-ac-cleared]'),df=rec.querySelector('[data-ac-rdiff]'),st=rec.querySelector('[data-ac-stmt]');
 const upd=()=>{let c=base;rec.querySelectorAll('input[name="cleared[]"]:checked').forEach(x=>c+=parseInt(x.dataset.amt,10)||0);cl.textContent=fmt(c);
  const s=signed(st.value);if(s===null){df.textContent='-';df.className='ac-num';return}df.textContent=s===c?'0.00 Balanced':fmt(s-c);df.className='ac-num '+(s===c?'ac-good':'ac-bad')};
 rec.addEventListener('input',upd);rec.addEventListener('change',upd);upd();}
})();
/* Dashboard card: period All/Clear, mode change reloads, Bar style remembered per browser. */
(()=>{
const f=document.querySelector('[data-ac-period]'); if(!f) return;
const boxes=[...f.querySelectorAll('input[name="periods[]"]')],count=f.querySelector('legend span');
const upd=()=>{count.textContent=`(${boxes.filter(b=>b.checked).length} selected)`};
f.querySelectorAll('[data-ac-select]').forEach(b=>b.addEventListener('click',()=>{boxes.forEach(x=>x.checked=b.dataset.acSelect==='all');upd()}));
boxes.forEach(b=>b.addEventListener('change',upd));
f.querySelector('[data-ac-mode]').addEventListener('change',()=>{f.querySelector('[name=selection_mode]').value='';f.requestSubmit()});
const sel=f.querySelector('[data-ac-barstyle]'),chart=document.querySelector('[data-ac-chart]');
let style='extruded';try{style=localStorage.getItem('acBarStyle')||style}catch(e){}
if(!['extruded','deep','floor','flat'].includes(style))style='extruded';
sel.value=style;if(chart)chart.dataset.style=style;
sel.addEventListener('change',()=>{if(chart)chart.dataset.style=sel.value;try{localStorage.setItem('acBarStyle',sel.value)}catch(e){}});
})();
