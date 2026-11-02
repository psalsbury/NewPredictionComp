<?php
declare(strict_types=1);
// Per-device logins (table user_sessions). The pc_token cookie holds "s_<random>" and only its sha256
// is stored, so each device can be signed out on its own. Older cookies that hold users.token still work
// and are swapped for a device login on the next page view (pc_user_from_cookie with $upgrade=true).
// Signing out deletes the device login and replaces users.token, so copies of an old cookie stop working.
const PC_COOKIE_OPTS=['path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax'];
function pc_session_hash(string $raw):string{return hash('sha256','pcsess|'.$raw);}
function pc_set_cookie(string $value,int $expires):void{
 if(!headers_sent())setcookie('pc_token',$value,['expires'=>$expires]+PC_COOKIE_OPTS);
 $_COOKIE['pc_token']=$value;
}
// Returns the signed-in user row (with session_id when it came from a device login), or null.
function pc_user_from_cookie(PDO $db,bool $upgrade=false):?array{
 $raw=(string)($_COOKIE['pc_token']??'');if($raw==='')return null;
 if(str_starts_with($raw,'s_')){
  $q=$db->prepare('SELECT u.*,s.id session_id,s.last_seen_at session_seen FROM user_sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=?');
  $q->execute([pc_session_hash($raw)]);$u=$q->fetch(PDO::FETCH_ASSOC);if(!$u)return null;
  if(empty($u['session_seen'])||strtotime((string)$u['session_seen'])<time()-3600)
   $db->prepare('UPDATE user_sessions SET last_seen_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$u['session_id']]);
  unset($u['session_seen']);return $u;
 }
 $q=$db->prepare('SELECT * FROM users WHERE token=?');$q->execute([$raw]);$u=$q->fetch(PDO::FETCH_ASSOC);if(!$u)return null;
 if($upgrade&&!headers_sent()){$u['session_id']=pc_start_session($db,(int)$u['id'],false);}
 return $u;
}
// Signs this device in as $userId with a new device login; returns the session id.
function pc_start_session(PDO $db,int $userId,bool $dropCurrent=true):int{
 $old=(string)($_COOKIE['pc_token']??'');
 if($dropCurrent&&str_starts_with($old,'s_'))$db->prepare('DELETE FROM user_sessions WHERE token_hash=?')->execute([pc_session_hash($old)]);
 $raw='s_'.bin2hex(random_bytes(32));
 $db->prepare('INSERT INTO user_sessions(token_hash,user_id,user_agent) VALUES(?,?,?)')->execute([pc_session_hash($raw),$userId,substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,200)]);
 pc_set_cookie($raw,time()+31536000*3);return (int)$db->lastInsertId();
}
// Sign out this device: delete its login, replace users.token (old-style cookie copies stop working), clear the cookie.
function pc_end_session(PDO $db,int $userId):void{
 $raw=(string)($_COOKIE['pc_token']??'');
 if(str_starts_with($raw,'s_'))$db->prepare('DELETE FROM user_sessions WHERE token_hash=?')->execute([pc_session_hash($raw)]);
 if($userId>0)$db->prepare('UPDATE users SET token=? WHERE id=?')->execute([bin2hex(random_bytes(16)),$userId]);
 pc_set_cookie('',time()-3600);
}
// After a password change: sign out every other device and old-style cookie copies.
function pc_end_other_sessions(PDO $db,int $userId):void{
 $raw=(string)($_COOKIE['pc_token']??'');
 $db->prepare('DELETE FROM user_sessions WHERE user_id=? AND token_hash<>?')->execute([$userId,str_starts_with($raw,'s_')?pc_session_hash($raw):'']);
 $db->prepare('UPDATE users SET token=? WHERE id=?')->execute([bin2hex(random_bytes(16)),$userId]);
}
// CSRF token: an HMAC of a long-lived random browser cookie (pc_bid). It doesn't change when the player
// signs in or out (unlike pc_token), and doesn't expire with the PHP session (~24 idle minutes).
function pc_csrf_token():string{
 static $key=null;
 if($key===null){$f='/etc/predictioncomp/csrf.key';$key=is_readable($f)?trim((string)file_get_contents($f)):'';if($key==='')$key='pc-csrf-fallback';}
 $bid=(string)($_COOKIE['pc_bid']??'');
 if(!preg_match('/^[a-f0-9]{32}$/',$bid)){
  $bid=bin2hex(random_bytes(16));
  if(!headers_sent())setcookie('pc_bid',$bid,['expires'=>time()+31536000*5]+PC_COOKIE_OPTS);
  $_COOKIE['pc_bid']=$bid;
 }
 return hash_hmac('sha256','csrf|'.$bid,$key);
}
function pc_csrf_ok(array $in):bool{return $_SERVER['REQUEST_METHOD']==='POST'&&hash_equals(pc_csrf_token(),(string)($in['csrf']??''));}
