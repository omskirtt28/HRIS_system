# BlitzSync ERP direction (2026-11-21)

Status: DISCUSSED, NOT APPROVED TO BUILD. No code changed.

## Request (John)
Make a SIMSO Tech style ERP (Accounting, HR+Payroll, Inventory, E-commerce, linked) as BlitzSync, by splitting this HRIS into modules. Style/structure only; do not copy SIMSO text, logo, screenshots, testimonial or claims.

## Recommended (pending John's "go")
- Stack: keep PHP + MySQL/XAMPP. One codebase, modular monolith, modules enabled per client.
- Data: one DB per client (as hris_clean / pmbsi_hris), not shared multi-tenant.
- Build order: Core + module switches -> HR -> Payroll -> Accounting (payroll posts to ledger) -> Inventory (reuse Malbcoff POS) -> later POS / E-commerce.
- Public site: separate static HTML/CSS in Desktop\BlitzSync\website\, after modules are clear. Demo section uses real HRIS/ORDESK/Malbcoff screenshots. CTA: free consultation.
- Pricing stays one-time package + annual care (Desktop\BlitzSync\Price List - HR and Accounting.md), not monthly SaaS.

## SIMSO reference (read 2026-11-21, homepage only)
17 sections: hero + dashboard shot, 4 pillars, "how they link" example, 8 supporting modules, tabbed live demo screens, comparison table, why us, mobile app, industries, 3 pricing tiers, implementation steps, testimonial, FAQ, lead form, social feed, footer. Navy/orange, bold sans, white cards.

## Open
- John's "go" for the Friday audit (Core/HR/Payroll boundaries, per-client module on/off setting).
- Public site timing; Business/Growth care fees and VAT status still open in the BlitzSync price list.

## Next step
Friday audit, then plan in 05-Implementation/Plans/ + Session-Log entry.
