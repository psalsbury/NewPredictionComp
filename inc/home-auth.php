<?php
declare(strict_types=1);
// League invites and sign-in routes (Google, email links, sign-out). Each route redirects and exits.
$pendingInviteName=null;
if(isset($_GET['invite'])){
 $inviteCode=strtoupper(trim((string)$_GET['invite']));
 $s=$db->prepare('SELECT name FROM leagues WHERE code=?');$s->execute([$inviteCode]);$pendingInviteName=$s->fetchColumn()?:null;
 if($pendingInviteName)$_SESSION['pending_league_code']=$inviteCode;
}
if(!$pendingInviteName&&!empty($_SESSION['pending_league_code'])){
 $s=$db->prepare('SELECT name FROM leagues WHERE code=?');$s->execute([(string)$_SESSION['pending_league_code']]);$pendingInviteName=$s->fetchColumn()?:null;
}
if($pendingInviteName&&$uid>0&&!isset($_GET['api'])){
 $s=$db->prepare('SELECT id FROM leagues WHERE code=?');$s->execute([$_SESSION['pending_league_code']]);$joinedId=(int)$s->fetchColumn();pc_join_pending($db,(string)$user['token']);header('Location: /?joined_league=1&league='.$joinedId.'#predict');exit;
}
if(isset($_GET['verify_email']))pc_verify_email_route($db);
if(($_GET['auth']??'')==='logout'){
 // POST with the CSRF token only, so other sites can't sign players out.
 if(pc_csrf_ok($_POST))pc_end_session($db,$uid);
 header('Location: /');exit;
}
if(isset($_GET['login_token'])){
 // Opening the link only shows a confirmation page; signing in needs the button (a POST with the session's
 // CSRF token). Email link scanners therefore can't use the link up, and nobody is switched into someone
 // else's account by a forwarded link without seeing whose it is. Guest picks on this device are merged
 // automatically only on the device that asked for the link; elsewhere the player chooses.
 $raw=(string)$_GET['login_token'];$hash=hash('sha256',$raw);
 $s=$db->prepare("SELECT * FROM auth_magic_links WHERE token_hash=? AND used_at IS NULL AND expires_at>datetime('now')");
 $s->execute([$hash]);$link=$s->fetch(PDO::FETCH_ASSOC);
 if(!$link){header('Location: /?auth_error=expired_link');exit;}
 $sameDevice=$uid>0&&(int)$link['originating_user_id']===$uid;
 $isGuest=$uid>0&&empty($user['email'])&&empty($user['google_sub'])&&empty($user['password_hash']);
 $guestPicks=0;if($isGuest){$c=$db->prepare('SELECT COUNT(*) FROM predictions WHERE user_id=?');$c->execute([$uid]);$guestPicks=(int)$c->fetchColumn();}
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals((string)($_SESSION['registration_csrf']??''),(string)($_POST['csrf']??''))){header('Location: '.strtok((string)$_SERVER['REQUEST_URI'],'#'));exit;}
  $mark=$db->prepare('UPDATE auth_magic_links SET used_at=CURRENT_TIMESTAMP WHERE id=? AND used_at IS NULL');$mark->execute([$link['id']]);
  if($mark->rowCount()!==1){header('Location: /?auth_error=expired_link');exit;}
  $token=pc_merge_account($db,$uid,(string)$link['email'],null,null,null,$sameDevice||!empty($_POST['merge_guest']));pc_cookie($token);pc_join_pending($db,$token);
  $resetUser=$db->prepare('SELECT id FROM users WHERE token=?');$resetUser->execute([$token]);session_regenerate_id(true);$_SESSION['password_reset_user_id']=(int)$resetUser->fetchColumn();
  header('Location: /account?reset_password=1');exit;
 }
 $who=$db->prepare('SELECT display_name FROM users WHERE LOWER(email)=? AND COALESCE(is_bot,0)=0');$who->execute([strtolower((string)$link['email'])]);$whoName=(string)($who->fetchColumn()?:'');
 $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
 [$local,$domain]=array_pad(explode('@',(string)$link['email'],2),2,'');$masked=strlen($local)<=2?$local:substr($local,0,2).'…';
 header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex');
 echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in | PredictionComp</title><meta name="robots" content="noindex">'
  .'<link rel="stylesheet" href="/assets/home.css?v=4"><link rel="stylesheet" href="/assets/theme.css?v=20261019"><link rel="stylesheet" href="/assets/responsive.css?v=20261030">'
  .'<style>.signinconfirm{max-width:460px;margin:12vh auto 0;padding:28px 26px}.signinconfirm h1{font-size:26px;margin:0 0 10px}.signinconfirm p{line-height:1.5}.signinconfirm label{display:flex;gap:10px;align-items:flex-start;margin:16px 0;line-height:1.4}.signinconfirm input[type=checkbox]{width:20px;height:20px;flex:none;margin-top:2px}.signinconfirm .btn{width:100%;margin-top:8px}.signinconfirm .cancel{display:block;text-align:center;margin-top:14px}</style></head><body>'
  .'<main class="standalonepanel signinconfirm"><div class="sectionlabel">YOUR ACCOUNT</div><h1>Sign in to PredictionComp</h1>'
  .'<p>This link signs you in to the account for <strong>'.$e($masked.'@'.$domain).'</strong>'.($whoName!==''?' (player name <strong>'.$e($whoName).'</strong>)':'').'.</p>'
  .($uid>0&&!$isGuest&&!$sameDevice?'<p>You are currently signed in as <strong>'.$e($user['display_name']).'</strong>. That account stays as it is; this device will switch accounts.</p>':'')
  .'<form method="post"><input type="hidden" name="csrf" value="'.$e($_SESSION['registration_csrf']).'">'
  .($isGuest&&$guestPicks>0&&!$sameDevice?'<label><input type="checkbox" name="merge_guest" value="1"><span>Also move the <strong>'.$guestPicks.'</strong> prediction'.($guestPicks===1?'':'s').' made on this device as guest <strong>'.$e($user['display_name']).'</strong> into this account</span></label>':'')
  .($isGuest&&$guestPicks>0&&$sameDevice?'<p>Your '.$guestPicks.' guest prediction'.($guestPicks===1?'':'s').' on this device will be added to this account.</p>':'')
  .'<button type="submit" class="btn">Sign in</button></form><a class="cancel" href="/">Cancel</a>'
  .'<p class="performancenote" style="margin-top:18px">If you did not ask for this link, press Cancel. Nothing changes until you press Sign in.</p></main></body></html>';
 exit;
}
if(($_GET['auth']??'')==='google_start'){
 $cfg=pc_google_config();
 if(!$cfg){header('Location: /?auth_error=google_not_configured');exit;}
 $_SESSION['google_oauth_state']=bin2hex(random_bytes(24));
 $params=['client_id'=>$cfg['client_id'],'redirect_uri'=>'https://predictioncomp.com/?auth=google_callback','response_type'=>'code','scope'=>'openid email profile','state'=>$_SESSION['google_oauth_state'],'prompt'=>'select_account'];
 header('Location: https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params));exit;
}
if(($_GET['auth']??'')==='google_callback'){
 $cfg=pc_google_config();$state=(string)($_GET['state']??'');
 if(!$cfg||!hash_equals((string)($_SESSION['google_oauth_state']??''),$state)||empty($_GET['code'])){header('Location: /?auth_error=google_failed');exit;}
 unset($_SESSION['google_oauth_state']);
 $ch=curl_init('https://oauth2.googleapis.com/token');
 curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['code'=>(string)$_GET['code'],'client_id'=>$cfg['client_id'],'client_secret'=>$cfg['client_secret'],'redirect_uri'=>'https://predictioncomp.com/?auth=google_callback','grant_type'=>'authorization_code']),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12]);
 $tok=json_decode((string)curl_exec($ch),true);curl_close($ch);
 if(empty($tok['id_token'])){header('Location: /?auth_error=google_failed');exit;}
 $ch=curl_init('https://oauth2.googleapis.com/tokeninfo?id_token='.rawurlencode($tok['id_token']));curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12]);$info=json_decode((string)curl_exec($ch),true);curl_close($ch);
 if(($info['aud']??'')!==$cfg['client_id']||!in_array($info['iss']??'', ['accounts.google.com','https://accounts.google.com'],true)||($info['email_verified']??'')!=='true'||empty($info['sub'])||empty($info['email'])){header('Location: /?auth_error=google_failed');exit;}
 $token=pc_merge_account($db,$uid,(string)$info['email'],(string)$info['sub'],(string)($info['name']??''),(string)($info['picture']??''));pc_cookie($token);pc_join_pending($db,$token);
 header('Location: '.(!empty($_SESSION['just_joined_league_id'])?'/?joined_league=1&league='.(int)$_SESSION['just_joined_league_id'].'#predict':'/?signed_in=1#predict'));unset($_SESSION['just_joined_league_id']);exit;
}
