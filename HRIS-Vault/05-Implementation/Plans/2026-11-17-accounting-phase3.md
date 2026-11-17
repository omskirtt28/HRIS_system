---
tags: [plan, accounting]
---
# Accounting Phase 3: dashboard, ledger, tax books, fixed assets

Status: done 2026-11-17 - B1, B2, B6 (048), B3-B5 (049) applied hris_clean + hris_demo; local commits; walang pmbsi_hris/deploy (John: "go sa step 0 muna tapos tapusin natin hanggang phase 3, sa live hindi pa")

## Context
Phase 1-2 tapos (039-044) + payroll engine (045-047), committed 9c5d36e. Phase 3 = huling bahagi ng [[Accounting]] scope. Local lang (hris_clean + hris_demo), walang pmbsi_hris/deploy.

## Mga gagawin (order)
1. **B1 Accounting Dashboard** (walang migration): KPI strip live (cash, open AR, open AP, pending entries); card "Income and expenses" na may sariling period picker (Monthly/Quarterly/Yearly + All/Clear, default Monthly/All), 3D extruded bars + Bar style option, label sa itaas; Download report CSV na sumusunod sa period.
2. **B2 General ledger drill-down** (walang migration): ledger per account na may opening + running balance; link mula TB/IS/BS at COA.
3. **B6 Fixed assets** (migration): asset register, straight-line depreciation per buwan -> isang draft JE na dadaan sa normal approval; walang double run per asset/buwan.
4. **B3 Payroll posting accounts** (migration): settings screen para sa account mapping ng payroll release; opsyonal na Salaries Payable. Default = kasalukuyang codes (walang pagbabago hangga't walang sagot sa Q-P7).
5. **B4 VAT + B5 EWT** (migration): tax codes na ilalagay ng user (walang naka-seed na rate, NEEDS VERIFICATION); VAT sa bill/invoice line, EWT sa bill; VAT summary at EWT report. OFF by default.

## Hindi kasama
BIR CAS registration, 2307/2550 form printing, multi-currency, multi-company, budgeting, deploy/pmbsi_hris.

## Open questions (client)
VAT registered?; EWT ATC codes/rates; Q-P7 Salaries Payable; client COA; approver ng bills/vouchers.

## Verification
check.php task, bagong DB tests sa scratch copy, Stage B per migration (prepare/cycle/guard), browser crawl sa hris_demo copy (desktop + 390px).

## Execute prompt (i-paste sa PowerShell)
claude "Ituloy ang HRIS-Vault/05-Implementation/Plans/2026-11-17-accounting-phase3.md mula sa unang hindi pa tapos na slice; local lang, walang deploy."
