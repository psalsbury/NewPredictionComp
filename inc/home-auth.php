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
 setcookie('pc_token','',['expires'=>time()-3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
 header('Location: /');exit;
}
if(isset($_GET['login_token'])){
 $raw=(string)$_GET['login_token'];$hash=hash('sha256',$raw);
 $s=$db->prepare("SELECT * FROM auth_magic_links WHERE token_hash=? AND used_at IS NULL AND expires_at>datetime('now')");
 $s->execute([$hash]);$link=$s->fetch(PDO::FETCH_ASSOC);
 if($link){
  $db->prepare('UPDATE auth_magic_links SET used_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$link['id']]);
  $token=pc_merge_account($db,$uid,(string)$link['email']);pc_cookie($token);pc_join_pending($db,$token);
  $resetUser=$db->prepare('SELECT id FROM users WHERE token=?');$resetUser->execute([$token]);session_regenerate_id(true);$_SESSION['password_reset_user_id']=(int)$resetUser->fetchColumn();
  header('Location: /account?reset_password=1');exit;
 }
 header('Location: /?auth_error=expired_link');exit;
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
