<?php
function pc_auth_error(int $status,string $message):void{http_response_code($status);echo json_encode(['ok'=>false,'error'=>$message]);exit;}
function pc_password_api(PDO $db,array $user,int $uid,string $action,array $in):void{
 if(!in_array($action,['password_register','password_login','password_setup'],true))return;
 if($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals((string)($_SESSION['registration_csrf']??''),(string)($in['csrf']??'')))pc_auth_error(403,'Please refresh the page and try again.');
 $email=strtolower(trim((string)($in['email']??'')));$password=(string)($in['password']??'');
 if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254)pc_auth_error(400,'Enter a valid email address.');
 $key=hash('sha256',($_SERVER['REMOTE_ADDR']??'').'|'.$email);$now=time();
 $db->prepare('DELETE FROM password_login_attempts WHERE window_start<?')->execute([$now-900]);
 $q=$db->prepare('SELECT attempts FROM password_login_attempts WHERE key=?');$q->execute([$key]);
 if((int)$q->fetchColumn()>=10)pc_auth_error(429,'Too many attempts. Please try again in 15 minutes.');
 $db->prepare('INSERT INTO password_login_attempts(key,attempts,window_start) VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET attempts=attempts+1')->execute([$key,$now]);
 $q=$db->prepare('SELECT * FROM users WHERE LOWER(email)=? AND COALESCE(is_bot,0)=0');$q->execute([$email]);$existing=$q->fetch(PDO::FETCH_ASSOC);
 if($action==='password_login'){
  if(!$existing||empty($existing['password_hash'])||!password_verify($password,$existing['password_hash']))pc_auth_error(401,'Email or password is incorrect.');
  session_regenerate_id(true);pc_cookie((string)$existing['token']);pc_join_pending($db,(string)$existing['token']);
 }else{
  if(strlen($password)<10||strlen($password)>72)pc_auth_error(400,'Use a password of 10–72 characters.');
  if($password!==(string)($in['confirm_password']??''))pc_auth_error(400,'The passwords do not match.');
  if($action==='password_setup'){
   if($uid<1)pc_auth_error(401,'Sign in first.');
   if(!empty($user['email'])&&strtolower($user['email'])!==$email)pc_auth_error(400,'Use the email address linked to this account.');
   if($existing&&(int)$existing['id']!==$uid)pc_auth_error(409,'That email already has an account. Sign in to it instead.');
   if(!empty($user['password_hash'])&&(int)($_SESSION['password_reset_user_id']??0)!==$uid&&!password_verify((string)($in['current_password']??''),$user['password_hash']))pc_auth_error(401,'Enter your current password.');
   $db->prepare('UPDATE users SET email=?,password_hash=? WHERE id=?')->execute([$email,password_hash($password,PASSWORD_DEFAULT),$uid]);
   unset($_SESSION['password_reset_user_id']);session_regenerate_id(true);
  }else{
   if($uid>0)pc_auth_error(400,'Use Account to add email/password to your existing profile.');
   if($existing)pc_auth_error(409,'That email already has an account. Sign in or reset your password.');
   $name=trim((string)($in['name']??''));
   if(strlen($name)<2||strlen($name)>24||!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} _.-]*$/u',$name)||in_array(strtolower($name),['guest','admin','administrator'],true))pc_auth_error(400,'Choose a display name of 2–24 characters using letters, numbers, spaces, dots, underscores or hyphens.');
   $q=$db->prepare('SELECT id FROM users WHERE LOWER(display_name)=LOWER(?)');$q->execute([$name]);if($q->fetchColumn())pc_auth_error(409,'That display name is taken. Choose another.');
   $newToken=token();$db->prepare('INSERT INTO users(token,display_name,email,password_hash,onboarding_seen) VALUES(?,?,?,?,0)')->execute([$newToken,$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
   session_regenerate_id(true);pc_cookie($newToken);pc_join_pending($db,$newToken);
  }
 }
 $db->prepare('DELETE FROM password_login_attempts WHERE key=?')->execute([$key]);echo json_encode(['ok'=>true]);exit;
}
