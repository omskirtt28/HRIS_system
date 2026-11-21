<?php
declare(strict_types=1);
require_once __DIR__.'/Accounting.php';
require_once __DIR__.'/AccountsPayable.php';
require_once __DIR__.'/AccountsReceivable.php';
require_once __DIR__.'/AccountingViews.php';
require_once __DIR__.'/AccountsPayableViews.php';
require_once __DIR__.'/AccountsReceivableViews.php';
require_once __DIR__.'/CashBank.php';
require_once __DIR__.'/CashBankViews.php';
require_once __DIR__.'/Remittances.php';
require_once __DIR__.'/RemittancesViews.php';
require_once __DIR__.'/HrDashboard.php';
require_once __DIR__.'/AccountingDashboard.php';
require_once __DIR__.'/FixedAssets.php';
require_once __DIR__.'/FixedAssetsViews.php';
require_once __DIR__.'/AccountingSetup.php';
require_once __DIR__.'/AccountingSetupViews.php';
require_once __DIR__.'/PayrollRun.php'; // default payroll posting accounts on the settings screen

/** Accounting Phase 1 routes: accounting-coa | journal | entry | periods | reports. */
function accounting_handle(string $page): void
{
    $action=(string)($_POST['action'] ?? '');
    if(!str_starts_with($page,'accounting-') && !str_starts_with($action,'acct_')) return;
    need_db(); if(!Auth::check()) redirect('login');
    Auth::requirePermission('accounting.view');
    if(!Accounting::ready()) { flash('error','Accounting is not installed yet (migrations 039 to 044).'); redirect('dashboard'); }
    $uid=(int)(Auth::user()['id'] ?? 0);

    if($_SERVER['REQUEST_METHOD']==='POST' && str_starts_with($action,'acct_')) {
        verify_csrf();
        $id=(int)($_POST['id'] ?? 0); $back=['accounting-journal',[]];
        try {
            switch($action) {
                case 'acct_account_save':
                    Auth::requirePermission('accounting.approve'); $back=['accounting-coa',[]];
                    Accounting::saveAccount($id,(string)($_POST['code']??''),(string)($_POST['name']??''),(string)($_POST['type']??''),!empty($_POST['is_active']));
                    flash('success','Account saved.'); break;
                case 'acct_journal_save':
                    Auth::requirePermission('accounting.entry'); $back=['accounting-entry',$id>0?['id'=>$id,'edit'=>1]:['new'=>1]];
                    $lines=is_array($_POST['lines']??null)?$_POST['lines']:[];
                    $id=Accounting::saveDraft($id,(string)($_POST['entry_date']??''),(string)($_POST['memo']??''),$lines,$uid);
                    $back=['accounting-entry',['id'=>$id,'edit'=>1]];
                    if(!empty($_POST['submit_after'])) { Accounting::submit($id); flash('success','Entry saved and submitted for approval.'); }
                    else flash('success','Draft saved.');
                    $back=['accounting-entry',['id'=>$id]]; break;
                case 'acct_journal_submit':
                    Auth::requirePermission('accounting.entry'); $back=['accounting-entry',['id'=>$id]];
                    Accounting::submit($id); flash('success','Submitted for approval.'); break;
                case 'acct_journal_return':
                    Auth::requirePermission('accounting.approve'); $back=['accounting-entry',['id'=>$id]];
                    Accounting::returnToDraft($id,(string)($_POST['note']??'')); flash('success','Entry returned to draft.'); break;
                case 'acct_journal_approve':
                    Auth::requirePermission('accounting.approve'); $back=['accounting-entry',['id'=>$id]];
                    Accounting::approve($id,$uid); flash('success','Entry approved and posted to the ledger.'); break;
                case 'acct_journal_reverse':
                    Auth::requirePermission('accounting.approve'); $back=['accounting-entry',['id'=>$id]];
                    $new=Accounting::reverse($id,(string)($_POST['entry_date']??''),$uid);
                    flash('success','Reversing draft created. Submit it for approval to post it.'); $back=['accounting-entry',['id'=>$new]]; break;
                case 'acct_journal_void':
                    Auth::requirePermission('accounting.entry'); $back=['accounting-entry',['id'=>$id]];
                    Accounting::void($id,(string)($_POST['note']??''),$uid); flash('success','Entry voided. Its number stays in the journal.'); break;
                case 'acct_year_close':
                    Auth::requirePermission('accounting.approve'); $back=['accounting-periods',[]];
                    $new=Accounting::closeYear((int)($_POST['year']??0),(int)($_POST['equity_id']??0),$uid);
                    flash('success','Closing entry created as a draft. Submit it so another approver can post it.'); $back=['accounting-entry',['id'=>$new]]; break;
                case 'acct_vendor_save':
                    Auth::requirePermission('accounting.entry'); $back=['accounting-vendors',[]];
                    AccountsPayable::saveVendor($id,(string)($_POST['name']??''),(string)($_POST['tin']??''),(string)($_POST['contact']??''),(int)($_POST['terms_days']??30),!empty($_POST['is_active']));
                    flash('success','Vendor saved.'); break;
                case 'acct_bill_save':
                    Auth::requirePermission('accounting.entry'); $back=['accounting-bill',$id>0?['id'=>$id,'edit'=>1]:['new'=>1]];
                    $lines=is_array($_POST['lines']??null)?$_POST['lines']:[];
                    $id=AccountsPayable::saveBill($id,(int)($_POST['vendor_id']??0),(string)($_POST['vendor_ref']??''),(string)($_POST['bill_date']??''),(string)($_POST['due_date']??''),(string)($_POST['memo']??''),$lines,$uid,(int)($_POST['vat_code_id']??0),(int)($_POST['ewt_code_id']??0));
                    $back=['accounting-bill',['id'=>$id,'edit'=>1]];
                    if(!empty($_POST['submit_after'])) { Accounting::submit((int)AccountsPayable::bill($id)['entry_id']); flash('success','Bill saved and submitted for approval.'); }
                    else flash('success','Bill saved as a draft.');
                    $back=['accounting-bill',['id'=>$id]]; break;
                case 'acct_customer_save':
                    Auth::requirePermission('accounting.entry'); $back=['accounting-customers',[]];
                    AccountsReceivable::saveCustomer($id,(string)($_POST['name']??''),(string)($_POST['tin']??''),(string)($_POST['contact']??''),(int)($_POST['terms_days']??30),!empty($_POST['is_active']));
                    flash('success','Customer saved.'); break;
                case 'acct_invoice_save':
                    Auth::requirePermission('accounting.entry'); $back=['accounting-invoice',$id>0?['id'=>$id,'edit'=>1]:['new'=>1]];
                    $lines=is_array($_POST['lines']??null)?$_POST['lines']:[];
                    $id=AccountsReceivable::saveInvoice($id,(int)($_POST['customer_id']??0),(string)($_POST['customer_ref']??''),(string)($_POST['invoice_date']??''),(string)($_POST['due_date']??''),(string)($_POST['memo']??''),$lines,$uid,(int)($_POST['vat_code_id']??0));
                    $back=['accounting-invoice',['id'=>$id,'edit'=>1]];
                    if(!empty($_POST['submit_after'])) { Accounting::submit((int)AccountsReceivable::invoice($id)['entry_id']); flash('success','Invoice saved and submitted for approval.'); }
                    else flash('success','Invoice saved as a draft.');
                    $back=['accounting-invoice',['id'=>$id]]; break;
                case 'acct_bank_save':
                    // Mapping a bank to a ledger account shapes the books, like the chart of accounts.
                    Auth::requirePermission('accounting.approve'); $back=['accounting-banks',[]];
                    CashBank::saveBank($id,(string)($_POST['name']??''),(string)($_POST['bank_name']??''),(string)($_POST['account_no']??''),(int)($_POST['account_id']??0),!empty($_POST['is_active']),(string)($_POST['kind']??'BANK'));
                    flash('success','Bank account saved.'); break;
                case 'acct_cheque_save':
                    Auth::requirePermission('accounting.entry'); $back=['accounting-cheques',[]];
                    CashBank::saveCheque((int)($_POST['bank_account_id']??0),(string)($_POST['cheque_no']??''),(string)($_POST['payee']??''),(string)($_POST['cheque_date']??''),(string)($_POST['amount']??''),(int)($_POST['payment_id']??0),(string)($_POST['memo']??''),$uid);
                    flash('success','Cheque recorded as issued.'); break;
                case 'acct_cheque_status':
                    $st=(string)($_POST['status']??''); $back=['accounting-cheques',[]];
                    // Voiding your own cheque is data entry; bounced and stale are approver calls.
                    Auth::requirePermission($st==='VOID'?'accounting.entry':'accounting.approve');
                    CashBank::setChequeStatus($id,$st); flash('success','Cheque marked '.strtolower(CashBank::CHEQUE_STATUS[$st]).'.'); break;
                case 'acct_payment_save':
                    Auth::requirePermission('accounting.entry');
                    $kind=(string)($_POST['kind']??''); $party=(int)($_POST['party_id']??0);
                    $back=['accounting-payment',$id>0?['id'=>$id,'edit'=>1]:['new'=>$kind,'party'=>$party]];
                    $amounts=is_array($_POST['amounts']??null)?$_POST['amounts']:[];
                    $id=CashBank::savePayment($kind,$id,(int)($_POST['bank_account_id']??0),$party,(string)($_POST['pay_date']??''),(string)($_POST['reference']??''),(string)($_POST['memo']??''),$amounts,$uid);
                    $back=['accounting-payment',['id'=>$id,'edit'=>1]];
                    if(!empty($_POST['submit_after'])) { Accounting::submit((int)CashBank::payment($id)['entry_id']); flash('success','Payment saved and submitted for approval.'); }
                    else flash('success','Payment saved as a draft.');
                    $back=['accounting-payment',['id'=>$id]]; break;
                case 'acct_remittance_save':
                    Auth::requirePermission('accounting.entry'); $back=['accounting-remittance',$id>0?['id'=>$id,'edit'=>1]:['new'=>1]];
                    $lines=is_array($_POST['lines']??null)?$_POST['lines']:[];
                    $id=Remittances::save($id,(string)($_POST['agency']??''),(string)($_POST['period']??''),(int)($_POST['bank_account_id']??0),(string)($_POST['pay_date']??''),(string)($_POST['reference']??''),(string)($_POST['memo']??''),$lines,$uid);
                    $back=['accounting-remittance',['id'=>$id,'edit'=>1]];
                    if(!empty($_POST['submit_after'])) { Accounting::submit((int)Remittances::remittance($id)['entry_id']); flash('success','Remittance saved and submitted for approval.'); }
                    else flash('success','Remittance saved as a draft.');
                    $back=['accounting-remittance',['id'=>$id]]; break;
                case 'acct_recon_finish':
                    Auth::requirePermission('accounting.entry');
                    $bank=(int)($_POST['bank_account_id']??0); $m=(string)($_POST['month']??'');
                    $back=['accounting-recon',['bank'=>$bank,'month'=>$m]];
                    CashBank::finishRecon($bank,$m,(string)($_POST['statement_balance']??''),is_array($_POST['cleared']??null)?$_POST['cleared']:[],$uid,is_array($_POST['cheques']??null)?$_POST['cheques']:[]);
                    flash('success','Reconciliation saved. The ticked lines are now cleared.'); break;
                case 'acct_recon_undo':
                    Auth::requirePermission('accounting.approve'); $back=['accounting-recon',[]];
                    $bank=CashBank::undoRecon($id); $back=['accounting-recon',['bank'=>$bank]];
                    flash('success','Reconciliation undone. Its lines are uncleared again.'); break;
                case 'acct_period_close':
                case 'acct_period_reopen':
                    Auth::requirePermission('accounting.approve'); $back=['accounting-periods',[]];
                    $m=(string)($_POST['month']??'');
                    if($action==='acct_period_close') { Accounting::closeMonth($m,$uid); flash('success',date('F Y',strtotime($m)).' is closed.'); }
                    else { Accounting::reopenMonth($m); flash('success',date('F Y',strtotime($m)).' is open again.'); }
                    break;
                case 'acct_asset_save':
                    Auth::requirePermission('accounting.entry'); if(!FixedAssets::ready()) throw new RuntimeException('Fixed assets are not installed yet (migration 048).');
                    $back=['accounting-assets',$id>0?['edit'=>$id]:[]];
                    FixedAssets::save($id,$_POST,$uid); flash('success','Asset saved.'); $back=['accounting-assets',[]]; break;
                case 'acct_depreciation_run':
                    Auth::requirePermission('accounting.entry'); if(!FixedAssets::ready()) throw new RuntimeException('Fixed assets are not installed yet (migration 048).');
                    $back=['accounting-assets',[]];
                    $new=FixedAssets::run((string)($_POST['month']??''),$uid);
                    flash('success','Depreciation entry created as a draft. Submit it for approval to post it.'); $back=['accounting-entry',['id'=>$new]]; break;
                case 'acct_settings_save':
                    Auth::requirePermission('accounting.approve'); $back=['accounting-settings',[]];
                    if(!AccountingSetup::ready()) throw new RuntimeException('Accounting settings are not installed yet (migration 049).');
                    AccountingSetup::saveSettings($_POST,$uid); flash('success','Settings saved.'); break;
                case 'acct_tax_code_save':
                    Auth::requirePermission('accounting.approve'); $back=['accounting-settings',[]];
                    if(!AccountingSetup::ready()) throw new RuntimeException('Tax codes are not installed yet (migration 049).');
                    AccountingSetup::saveCode($id,(string)($_POST['kind']??''),(string)($_POST['code']??''),(string)($_POST['description']??''),(string)($_POST['rate']??''),!empty($_POST['is_active']));
                    flash('success','Tax code saved.'); break;
                default: throw new RuntimeException('Unknown action.');
            }
        } catch(Throwable $error) {
            if(!$error instanceof RuntimeException) error_log('Accounting action failed: '.$error->getMessage());
            flash('error',$error instanceof RuntimeException?$error->getMessage():'The change could not be saved. Try again.');
            if(in_array($action,['acct_journal_save','acct_bill_save','acct_invoice_save','acct_payment_save','acct_remittance_save','acct_recon_finish','acct_asset_save','acct_cheque_save'],true)) $_SESSION['acct_form']=$_POST; // keep what was typed
        }
        redirect($back[0],$back[1]);
    }

    $screen=substr($page,11);
    switch($screen) {
        case 'dashboard':
            $p=HrDashboard::filters($_GET); $today=date('Y-m-d'); $k=AccountingDashboard::kpis($today); $rows=AccountingDashboard::series($p,$today);
            if(!empty($_GET['csv'])) { accounting_dashboard_csv($k,$p,$rows); exit; }
            accounting_dashboard_page($k,$p,$rows); break;
        case 'ledger':
            $to=accounting_date((string)($_GET['to']??''),date('Y-m-d')); $from=accounting_date((string)($_GET['from']??''),substr($to,0,4).'-01-01');
            if($from>$to) [$from,$to]=[$to,$from];
            $accounts=Accounting::accounts(); $aid=(int)($_GET['account']??($accounts[0]['id']??0));
            $l=Accounting::ledger($aid,$from,$to); if(!$l) { flash('error','Account not found.'); redirect('accounting-coa'); }
            if(!empty($_GET['csv'])) { accounting_ledger_csv($l,$from,$to); exit; }
            accounting_ledger_page($l,$accounts,$from,$to); break;
        case 'assets':
            if(!FixedAssets::ready()) { flash('error','Fixed assets are not installed yet (migration 048).'); redirect('accounting-dashboard'); }
            $form=$_SESSION['acct_form']??null; unset($_SESSION['acct_form']);
            $edit=isset($_GET['edit'])?FixedAssets::asset((int)$_GET['edit']):null;
            if(!Auth::can('accounting.entry')) { $edit=null; $form=null; }
            accounting_assets_page(FixedAssets::assets(),FixedAssets::runs(),$edit,is_array($form)?$form:null); break;
        case 'settings':
            if(!AccountingSetup::ready()) { flash('error','Accounting settings are not installed yet (migration 049).'); redirect('accounting-dashboard'); }
            accounting_settings_page(AccountingSetup::settings(),Accounting::accounts(),AccountingSetup::codes(),PayrollRun::ACCOUNTS); break;
        case 'tax':
            $year=(int)($_GET['year']??date('Y')); if($year<2000 || $year>(int)date('Y')) $year=(int)date('Y');
            $m=(string)($_GET['month']??''); $month=preg_match('/^\d{4}-\d{2}$/',$m)?$m.'-01':date('Y-m-01');
            $vat=AccountingSetup::vatSummary($year); $ewt=AccountingSetup::ewtList($month);
            if(in_array($_GET['csv']??'',['vat','ewt'],true)) { accounting_tax_csv($_GET['csv'],$vat,$year,$ewt,$month); exit; }
            accounting_tax_page($vat,$year,$ewt,$month); break;
        case 'coa': accounting_coa_page(Accounting::accounts()); break;
        case 'journal':
            $st=strtoupper((string)($_GET['status']??''));
            accounting_journal_page(Accounting::entries($st),Accounting::statusCounts(),$st); break;
        case 'entry':
            $id=(int)($_GET['id']??0); $e=$id>0?Accounting::entry($id):null;
            if($id>0 && !$e) { flash('error','Journal entry not found.'); redirect('accounting-journal'); }
            $form=$_SESSION['acct_form']??null; unset($_SESSION['acct_form']);
            $editing=(!$e || !empty($_GET['edit'])) && Auth::can('accounting.entry') && (!$e || ($e['status']==='DRAFT' && $e['entry_kind']!=='CLOSING' && !$e['bill_id'] && !$e['invoice_id'] && !$e['payment_id'] && !$e['remittance_id'] && !FixedAssets::entryMonth($id)));
            if(!$e && !$editing) redirect('accounting-journal');
            accounting_entry_page($e,$editing,Accounting::accounts(true),is_array($form)?$form:null,$uid); break;
        case 'vendors': accounting_vendors_page(AccountsPayable::vendors()); break;
        case 'bills':
            if(($_GET['view']??'')==='aging') { $asOf=accounting_date((string)($_GET['to']??''),date('Y-m-d')); accounting_aging_page(AccountsPayable::aging($asOf),$asOf); break; }
            $st=strtoupper((string)($_GET['status']??''));
            accounting_bills_page(AccountsPayable::bills($st),AccountsPayable::statusCounts(),$st); break;
        case 'bill':
            $id=(int)($_GET['id']??0); $b=$id>0?AccountsPayable::bill($id):null;
            if($id>0 && !$b) { flash('error','Bill not found.'); redirect('accounting-bills'); }
            $form=$_SESSION['acct_form']??null; unset($_SESSION['acct_form']);
            $editing=(!$b || !empty($_GET['edit'])) && Auth::can('accounting.entry') && (!$b || $b['status']==='DRAFT');
            if(!$b && !$editing) redirect('accounting-bills');
            $accounts=array_filter(Accounting::accounts(true),fn($a)=>$a['code']!==AccountsPayable::AP_CODE);
            accounting_bill_page($b,$editing,AccountsPayable::vendors(true),$accounts,is_array($form)?$form:null); break;
        case 'customers': accounting_customers_page(AccountsReceivable::customers()); break;
        case 'invoices':
            if(($_GET['view']??'')==='aging') { $asOf=accounting_date((string)($_GET['to']??''),date('Y-m-d')); accounting_aging_page(AccountsReceivable::aging($asOf),$asOf,true); break; }
            $st=strtoupper((string)($_GET['status']??''));
            accounting_invoices_page(AccountsReceivable::invoices($st),AccountsReceivable::statusCounts(),$st); break;
        case 'invoice':
            $id=(int)($_GET['id']??0); $inv=$id>0?AccountsReceivable::invoice($id):null;
            if($id>0 && !$inv) { flash('error','Invoice not found.'); redirect('accounting-invoices'); }
            $form=$_SESSION['acct_form']??null; unset($_SESSION['acct_form']);
            $editing=(!$inv || !empty($_GET['edit'])) && Auth::can('accounting.entry') && (!$inv || $inv['status']==='DRAFT');
            if(!$inv && !$editing) redirect('accounting-invoices');
            $accounts=array_filter(Accounting::accounts(true),fn($a)=>$a['code']!==AccountsReceivable::AR_CODE);
            accounting_invoice_page($inv,$editing,AccountsReceivable::customers(true),$accounts,is_array($form)?$form:null); break;
        case 'banks': accounting_banks_page(CashBank::banks(),CashBank::cashAccounts()); break;
        case 'cheques':
            if(!CashBank::ready()) { flash('error','The cheque register is not installed yet (migration 052).'); redirect('accounting-banks'); }
            $form=$_SESSION['acct_form']??null; unset($_SESSION['acct_form']);
            $st=strtoupper((string)($_GET['status']??''));
            $banks=array_values(array_filter(CashBank::banks(true),fn($b)=>$b['kind']==='BANK'));
            accounting_cheques_page(CashBank::cheques($st),CashBank::chequeCounts(),$st,$banks,Auth::can('accounting.entry')?CashBank::chequePayments():[],is_array($form)?$form:null); break;
        case 'payments':
            $kind=($_GET['kind']??'')==='COLLECTION'?'COLLECTION':'DISBURSEMENT'; $st=strtoupper((string)($_GET['status']??''));
            accounting_payments_page($kind,CashBank::payments($kind,$st),CashBank::statusCounts($kind),$st); break;
        case 'payment':
            $id=(int)($_GET['id']??0); $p=$id>0?CashBank::payment($id):null;
            if($id>0 && !$p) { flash('error','Payment not found.'); redirect('accounting-payments'); }
            $form=$_SESSION['acct_form']??null; unset($_SESSION['acct_form']);
            $editing=(!$p || !empty($_GET['edit'])) && Auth::can('accounting.entry') && (!$p || $p['status']==='DRAFT');
            if(!$p && !$editing) redirect('accounting-payments');
            $kind=$p['kind'] ?? (($_GET['new']??'')==='COLLECTION'?'COLLECTION':'DISBURSEMENT');
            $party=$p ? $p['party_id'] : (int)($_GET['party']??0);
            $parties=$kind==='DISBURSEMENT'?AccountsPayable::vendors(true):AccountsReceivable::customers(true);
            accounting_payment_page($p,$editing,$kind,$party,$parties,CashBank::banks(true),$editing && $party?CashBank::openDocs($kind,$party,$id):[],is_array($form)?$form:null); break;
        case 'remittances':
            $st=strtoupper((string)($_GET['status']??''));
            $year=(int)($_GET['year']??0); if($year<2000 || $year>(int)date('Y')) $year=(int)date('Y');
            accounting_remittances_page(Remittances::remittances($st),Remittances::statusCounts(),$st,$year,Remittances::yearGrid($year)); break;
        case 'remittance':
            $id=(int)($_GET['id']??0); $r=$id>0?Remittances::remittance($id):null;
            if($id>0 && !$r) { flash('error','Remittance not found.'); redirect('accounting-remittances'); }
            $form=$_SESSION['acct_form']??null; unset($_SESSION['acct_form']);
            $editing=(!$r || !empty($_GET['edit'])) && Auth::can('accounting.entry') && (!$r || $r['status']==='DRAFT');
            if(!$r && !$editing) redirect('accounting-remittances');
            accounting_remittance_page($r,$editing,CashBank::banks(true),Remittances::payableAccounts(),is_array($form)?$form:null); break;
        case 'recon':
            $banks=array_values(array_filter(CashBank::banks(),fn($b)=>($b['kind']??'BANK')!=='PETTY')); $bank=null; // petty cash has no statement
            foreach($banks as $b) if((int)$b['id']===(int)($_GET['bank']??0) || (!$bank && !isset($_GET['bank']) && $b['is_active'])) $bank=$b;
            $form=$_SESSION['acct_form']??null; unset($_SESSION['acct_form']);
            $m=(string)($_GET['month']??'');
            // Default month: the one after the last reconciled month, else last month.
            if(!preg_match('/^\d{4}-\d{2}$/',$m)) $m=$bank && $bank['last_recon']?date('Y-m',strtotime($bank['last_recon'].' +1 month')):date('Y-m',strtotime('first day of last month'));
            if($m>date('Y-m')) $m=date('Y-m');
            accounting_recon_page($banks,$bank,$m,$bank?CashBank::reconData($bank,$m.'-01'):null,is_array($form)?$form:null); break;
        case 'periods': accounting_periods_page(Accounting::periods(),array_filter(Accounting::accounts(true),fn($a)=>$a['type']==='E')); break;
        case 'reports':
            $to=accounting_date((string)($_GET['to']??''),date('Y-m-d'));
            $from=accounting_date((string)($_GET['from']??''),substr($to,0,7).'-01');
            if($from>$to) $from=$to;
            $report=in_array($_GET['report']??'',['tb','is','bs'],true)?$_GET['report']:'tb';
            $data=$report==='tb'?Accounting::trialBalance($to):($report==='is'?Accounting::incomeStatement($from,$to):Accounting::balanceSheet($to));
            if(!empty($_GET['csv'])) { accounting_report_csv($report,$data,$from,$to); exit; }
            accounting_reports_page($report,$data,$from,$to); break;
        default: redirect('accounting-journal');
    }
    exit;
}

function accounting_date(string $raw,string $default): string
{
    $d=DateTime::createFromFormat('!Y-m-d',$raw);
    return $d && $d->format('Y-m-d')===$raw ? $raw : $default;
}
