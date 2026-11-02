<?php
declare(strict_types=1);
require_once __DIR__.'/EmployeeAccountService.php';

/**
 * Own-password page and the first-login gate (SPEC-employee-portal-account.md, D2/T4).
 * While users.must_change_password=1 every page except change-password and logout redirects to the change form.
 */
function account_password_handle(string $page): void
{
    if(!(($GLOBALS['pdo'] ?? null) instanceof PDO) || !Auth::check()) return;
    $uid=(int)(Auth::user()['id'] ?? 0);
    $forced=EmployeeAccountService::mustChange($uid);
    if($page!=='change-password') {
        if($forced && $page!=='logout') redirect('change-password');
        return;
    }
    if($_SERVER['REQUEST_METHOD']==='POST') {
        verify_csrf();
        try {
            EmployeeAccountService::changeOwnPassword($uid,(string)($_POST['current_password']??''),(string)($_POST['new_password']??''),(string)($_POST['confirm_password']??''),hash('sha256',(string)($_SESSION['auth_session_token']??'')));
            flash('success','Your password was changed.');
            redirect(Auth::landingPage());
        } catch(RuntimeException $e) { flash('error',$e->getMessage()); redirect('change-password'); }
    }
    render_head('Change password'); render_flashes(); ?>
    <div class="wrap" style="padding:48px 16px;max-width:520px"><form class="card pad" method="post" autocomplete="off"><?=csrf_field()?>
      <h1 style="font-size:24px;margin:0 0 6px">Change your password</h1>
      <p class="tiny" style="margin:0 0 18px"><?=$forced?'HR created your account with a generated password. Choose your own password to continue.':'Choose a new password for your account.'?></p>
      <div class="field"><label for="cp_current">Current password</label><input id="cp_current" type="password" name="current_password" required autocomplete="current-password"></div>
      <div class="field"><label for="cp_new">New password</label><input id="cp_new" type="password" name="new_password" required minlength="10" autocomplete="new-password" aria-describedby="cp_help"><small id="cp_help">At least 10 characters with at least one letter and one number.</small></div>
      <div class="field"><label for="cp_confirm">Confirm new password</label><input id="cp_confirm" type="password" name="confirm_password" required minlength="10" autocomplete="new-password"></div>
      <div style="display:flex;gap:8px;margin-top:16px"><button class="btn primary" type="submit">Change password</button><?php if($forced):?><a class="btn" href="<?=url('logout')?>">Sign out</a><?php else:?><a class="btn" href="<?=url(Auth::landingPage())?>">Cancel</a><?php endif;?></div>
    </form></div></body></html><?php
    exit;
}
