<?php
declare(strict_types=1);
// Database, the current player (from the pc_token cookie) and shared helpers.
session_start();
$db=new PDO('sqlite:'.pc_db_path());$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
function pc_front_result(array $f,?array $pick):string{
 $finished=in_array($f['status'],['FT','AET','PEN'],true)&&$f['home_score']!==null&&$f['away_score']!==null;
 $class='pending';$label='Awaiting result';$points='';
 if($finished&&$pick!==null){
  $exact=(int)$pick[0]===(int)$f['home_score']&&(int)$pick[1]===(int)$f['away_score'];
  $correct=((int)$pick[0]<=>(int)$pick[1])===((int)$f['home_score']<=>(int)$f['away_score']);
  $class=$exact?'exact':($correct?'correct':'wrong');
  $label=$exact?'Exact score':($correct?'Correct result':'Incorrect result');$points=$exact?' +5':($correct?' +3':' +0');
 }elseif($pick===null){$label='No prediction';$class='missing';}
 $html='<div class="frontresult '.$class.'">';
 if($pick!==null)$html.='<span class="frontpick" aria-label="'.$label.$points.' points"><small>Your pick</small><b>'.(int)$pick[0].'–'.(int)$pick[1].'</b></span>';
 else $html.='<span class="frontpick"><small>No pick</small><b>—</b></span>';
 $html.='<span class="frontactual"><small>'.($finished?'Final':'Result').'</small><b>'.($finished?(int)$f['home_score'].'–'.(int)$f['away_score']:'—').'</b></span>';
 return $html.'<small class="frontoutcome">'.$label.$points.'</small></div>';
}
function token(){return bin2hex(random_bytes(16));} function code(){return strtoupper(substr(bin2hex(random_bytes(5)),0,8));}
if(empty($_COOKIE['pc_token'])){$t=token();setcookie('pc_token',$t,['expires'=>time()+31536000*3,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);$_COOKIE['pc_token']=$t;}
$t=$_COOKIE['pc_token'];$q=$db->prepare('SELECT * FROM users WHERE token=?');$q->execute([$t]);$user=$q->fetch(PDO::FETCH_ASSOC);
if(!$user){
 $user=['id'=>0,'token'=>$t,'display_name'=>'Guest','email'=>null,'google_sub'=>null,'supported_club'=>null];
}
$uid=(int)$user['id'];
require_once PC_ROOT.'/standings.php';
require_once PC_ROOT.'/password-auth.php';
$premierLeagueClubs=pcSeasonClubs($db);
if(!empty($user['supported_club']))$user['supported_club']=pcClubName($user['supported_club'],$db);
if(empty($_SESSION['registration_csrf']))$_SESSION['registration_csrf']=bin2hex(random_bytes(32));

function pc_cookie(string $token): void {
 setcookie('pc_token',$token,['expires'=>time()+31536000*3,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
}
function pc_google_config(): ?array {
 $p='/etc/predictioncomp/google-oauth.json';
 if(!is_readable($p)) return null;
 $c=json_decode((string)file_get_contents($p),true);
 return is_array($c)&&!empty($c['client_id'])?$c:null;
}
function pc_merge_account(PDO $db,int $currentId,string $email,?string $googleSub=null,?string $name=null,?string $avatar=null): string {
 $email=strtolower(trim($email));$db->beginTransaction();
 try{
  $target=null;
  if($googleSub){$s=$db->prepare('SELECT * FROM users WHERE google_sub=?');$s->execute([$googleSub]);$target=$s->fetch(PDO::FETCH_ASSOC)?:null;}
  if(!$target){$s=$db->prepare('SELECT * FROM users WHERE email=?');$s->execute([$email]);$target=$s->fetch(PDO::FETCH_ASSOC)?:null;}
  if(!$target && $currentId<=0){
   $newToken=token();$display=trim((string)($name??''));if($display==='')$display=strstr($email,'@',true)?:'Player';
   $s=$db->prepare('INSERT INTO users(token,display_name,email,email_verified_at,google_sub,avatar_url,onboarding_seen) VALUES(?,?,?,CURRENT_TIMESTAMP,?,?,0)');
   $s->execute([$newToken,substr($display,0,24),$email,$googleSub,$avatar]);$db->commit();return $newToken;
  }
  if($target && (int)$target['id']!==$currentId){
   $tid=(int)$target['id'];
   $db->prepare('INSERT OR IGNORE INTO predictions(user_id,fixture_id,home_score,away_score,points,saved_at) SELECT ?,fixture_id,home_score,away_score,points,saved_at FROM predictions WHERE user_id=?')->execute([$tid,$currentId]);
   $db->prepare('INSERT OR IGNORE INTO league_members(league_id,user_id,joined_at) SELECT league_id,?,joined_at FROM league_members WHERE user_id=?')->execute([$tid,$currentId]);
   $db->prepare('UPDATE leagues SET owner_user_id=? WHERE owner_user_id=?')->execute([$tid,$currentId]);
   $db->prepare('DELETE FROM predictions WHERE user_id=?')->execute([$currentId]);
   $db->prepare('DELETE FROM league_members WHERE user_id=?')->execute([$currentId]);
   $db->prepare('DELETE FROM users WHERE id=?')->execute([$currentId]);
  } else {$tid=$currentId;$target=$GLOBALS['user'];}
  $display=$name&&preg_match('/^Player [A-F0-9]{4}$/',(string)($target['display_name']??''))?$name:null;
  $sql='UPDATE users SET email=?,email_verified_at=CURRENT_TIMESTAMP';
  $vals=[$email];
  if($googleSub){$sql.=',google_sub=?';$vals[]=$googleSub;}
  if($avatar){$sql.=',avatar_url=?';$vals[]=$avatar;}
  if($display){$sql.=',display_name=?';$vals[]=$display;}
  $sql.=' WHERE id=?';$vals[]=$tid;$db->prepare($sql)->execute($vals);
  $s=$db->prepare('SELECT token FROM users WHERE id=?');$s->execute([$tid]);$token=(string)$s->fetchColumn();
  $db->commit();return $token;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function pc_join_pending(PDO $db,string $token): ?string {
 $code=strtoupper(trim((string)($_SESSION['pending_league_code']??'')));
 if($code==='') return null;
 $s=$db->prepare('SELECT id,name FROM leagues WHERE code=?');$s->execute([$code]);$league=$s->fetch(PDO::FETCH_ASSOC);
 if(!$league){unset($_SESSION['pending_league_code']);return null;}
 $s=$db->prepare('SELECT id FROM users WHERE token=?');$s->execute([$token]);$userId=(int)$s->fetchColumn();
 if($userId>0)$db->prepare('INSERT OR IGNORE INTO league_members(league_id,user_id) VALUES(?,?)')->execute([(int)$league['id'],$userId]);
 $_SESSION['just_joined_league_id']=(int)$league['id'];unset($_SESSION['pending_league_code']);return (string)$league['name'];
}
require_once PC_ROOT.'/inc/listing.php';
