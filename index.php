<?php
declare(strict_types=1); session_start();
$db=new PDO('sqlite:/var/lib/predictioncomp/predictioncomp.sqlite');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
function token(){return bin2hex(random_bytes(16));} function code(){return strtoupper(substr(bin2hex(random_bytes(5)),0,8));}
if(empty($_COOKIE['pc_token'])){$t=token();setcookie('pc_token',$t,['expires'=>time()+31536000*3,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);$_COOKIE['pc_token']=$t;}
$t=$_COOKIE['pc_token'];$q=$db->prepare('SELECT * FROM users WHERE token=?');$q->execute([$t]);$user=$q->fetch(PDO::FETCH_ASSOC);
if(!$user){
 $user=['id'=>0,'token'=>$t,'display_name'=>'Guest','email'=>null,'google_sub'=>null,'supported_club'=>null];
}
$uid=(int)$user['id'];
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
   $s=$db->prepare('INSERT INTO users(token,display_name,email,email_verified_at,google_sub,avatar_url) VALUES(?,?,?,CURRENT_TIMESTAMP,?,?)');
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
 unset($_SESSION['pending_league_code']);return (string)$league['name'];
}
$pendingInviteName=null;
if(isset($_GET['invite'])){
 $inviteCode=strtoupper(trim((string)$_GET['invite']));
 $s=$db->prepare('SELECT name FROM leagues WHERE code=?');$s->execute([$inviteCode]);$pendingInviteName=$s->fetchColumn()?:null;
 if($pendingInviteName)$_SESSION['pending_league_code']=$inviteCode;
}
if(!$pendingInviteName&&!empty($_SESSION['pending_league_code'])){
 $s=$db->prepare('SELECT name FROM leagues WHERE code=?');$s->execute([(string)$_SESSION['pending_league_code']]);$pendingInviteName=$s->fetchColumn()?:null;
}
if($pendingInviteName&&!empty($user['email'])){
 pc_join_pending($db,(string)$user['token']);header('Location: /?joined_league=1');exit;
}
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
  header('Location: /?signed_in=1');exit;
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
 header('Location: /?signed_in=1');exit;
}
if(isset($_GET['api'])){
 header('Content-Type: application/json');$in=json_decode(file_get_contents('php://input'),true)?:[];
 if($_GET['api']==='register_name'){
  if(!hash_equals((string)$_SESSION['registration_csrf'],(string)($in['csrf']??''))){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Please refresh the page and try again']);exit;}
  if($uid>0){echo json_encode(['ok'=>true]);exit;}
  $name=trim((string)($in['name']??''));
  if(strlen($name)<2||strlen($name)>24||!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} _.-]*$/u',$name)||in_array(strtolower($name),['guest','admin','administrator'],true)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Choose a display name of 2–24 characters using letters, numbers, spaces, dots, underscores or hyphens']);exit;}
  $db->beginTransaction();
  $existing=$db->prepare('SELECT id FROM users WHERE LOWER(display_name)=LOWER(?)');$existing->execute([$name]);
  if($existing->fetchColumn()){$db->rollBack();http_response_code(409);echo json_encode(['ok'=>false,'error'=>'That display name is taken. Please choose another']);exit;}
  $newToken=token();
  $db->prepare('INSERT INTO users(token,display_name) VALUES(?,?)')->execute([$newToken,$name]);
  $db->commit();pc_cookie($newToken);
  echo json_encode(['ok'=>true]);exit;
 }
 if($_GET['api']==='google_login'){
  $cfg=pc_google_config();$credential=(string)($in['credential']??'');
  if(!$cfg||$credential===''){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Google sign-in is unavailable']);exit;}
  $json=@file_get_contents('https://oauth2.googleapis.com/tokeninfo?id_token='.rawurlencode($credential));$info=is_string($json)?json_decode($json,true):null;
  if(!is_array($info)||($info['aud']??'')!==$cfg['client_id']||!in_array($info['iss']??'', ['accounts.google.com','https://accounts.google.com'],true)||($info['email_verified']??'')!=='true'||empty($info['sub'])||empty($info['email'])){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Google could not verify this sign-in']);exit;}
  $token=pc_merge_account($db,$uid,(string)$info['email'],(string)$info['sub'],(string)($info['name']??''),(string)($info['picture']??''));pc_cookie($token);$joinedLeague=pc_join_pending($db,$token);
  echo json_encode(['ok'=>true,'joined_league'=>$joinedLeague]);exit;
 }
 if($_GET['api']==='email_login'){
  $email=strtolower(trim((string)($in['email']??'')));
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Enter a valid email address']);exit;}
  $recent=$db->prepare("SELECT COUNT(*) FROM auth_magic_links WHERE email=? AND created_at>datetime('now','-60 seconds')");$recent->execute([$email]);
  if((int)$recent->fetchColumn()>0){echo json_encode(['ok'=>true]);exit;}
  $raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);
  $db->prepare("DELETE FROM auth_magic_links WHERE expires_at<datetime('now','-1 day') OR used_at IS NOT NULL")->execute();
  $db->prepare("INSERT INTO auth_magic_links(email,token_hash,originating_user_id,expires_at) VALUES(?,?,?,datetime('now','+15 minutes'))")->execute([$email,$hash,$uid]);
  $url='https://predictioncomp.com/?login_token='.rawurlencode($raw);
  $subject='Your PredictionComp sign-in link';
  $body="Sign in to PredictionComp\n\nOpen this secure link within 15 minutes:\n".$url."\n\nIf you did not request this, you can ignore this email.";
  $headers=["From: PredictionComp <admin@clubdailyfive.com>","Reply-To: admin@clubdailyfive.com","Content-Type: text/plain; charset=UTF-8"];
  if(!mail($email,$subject,$body,implode("\r\n",$headers))){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'We could not send the email. Please try again.']);exit;}
  echo json_encode(['ok'=>true]);exit;
 }
 if($_GET['api']==='player_results'){
  $playerId=(int)($in['user_id']??0);$period=(string)($in['period']??'season');
  $now=new DateTimeImmutable('now',new DateTimeZone('Europe/London'));
  $year=((int)$now->format('n')>=7)?(int)$now->format('Y'):(int)$now->format('Y')-1;
  $start=new DateTimeImmutable($year.'-08-01 00:00:00',new DateTimeZone('Europe/London'));$end=$start->modify('+10 months');$title='Season '.substr((string)$year,2).'/'.substr((string)($year+1),2);
  if($period!=='season'){
   if(!preg_match('/^\d{4}-\d{2}$/',$period)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Invalid period']);exit;}
   $month=DateTimeImmutable::createFromFormat('!Y-m',$period,new DateTimeZone('Europe/London'));
   if(!$month||$month<$start||$month>=$end){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Invalid period']);exit;}
   $start=$month;$end=$month->modify('+1 month');$title=$month->format('F Y');
  }
  $person=$db->prepare("SELECT u.display_name,COALESCE(u.is_bot,0) is_bot,COALESCE(u.bot_grade,5) bot_grade,b.title,b.bio FROM users u LEFT JOIN bot_profiles b ON b.user_id=u.id WHERE u.id=?");$person->execute([$playerId]);$personInfo=$person->fetch(PDO::FETCH_ASSOC);
  if(!$personInfo){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Player not found']);exit;}
  $name=(string)$personInfo['display_name'];
  $botInfo=null;
  if((int)$personInfo['is_bot']===1){$botInfo=['id'=>$playerId,'grade'=>max(1,min(5,(int)$personInfo['bot_grade'])),'title'=>(string)($personInfo['title']??''),'bio'=>(string)($personInfo['bio']??''),'avatar'=>'/bot-avatar.php?id='.$playerId];}
  $results=$db->prepare("SELECT f.kickoff_utc,f.home,f.away,f.home_score actual_home,f.away_score actual_away,p.home_score predicted_home,p.away_score predicted_away,COALESCE(p.points,0) points
   FROM predictions p JOIN fixtures f ON f.id=p.fixture_id
   WHERE p.user_id=:user_id AND f.kickoff_utc>=:period_start AND f.kickoff_utc<:period_end AND f.kickoff_utc<=datetime('now')
   ORDER BY f.kickoff_utc DESC LIMIT 100");
  $results->execute([':user_id'=>$playerId,':period_start'=>$start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),':period_end'=>$end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
  echo json_encode(['ok'=>true,'name'=>$name,'period_title'=>$title,'results'=>$results->fetchAll(PDO::FETCH_ASSOC),'bot'=>$botInfo]);exit;
 }
 if($_GET['api']==='save'){
  if($uid<1){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Register with Google or a display name before saving predictions']);exit;}
  $id=(int)($in['fixture_id']??0);$h=max(0,min(15,(int)($in['home']??0)));$a=max(0,min(15,(int)($in['away']??0)));
  $f=$db->prepare("SELECT kickoff_utc FROM fixtures WHERE id=?");$f->execute([$id]);$kick=$f->fetchColumn();
  if(!$kick||strtotime($kick)<=time()){http_response_code(409);echo json_encode(['ok'=>false,'error'=>'Prediction locked']);exit;}
  $playerState=$db->prepare('SELECT display_name,email,COALESCE(is_bot,0) is_bot,play_alert_sent_at FROM users WHERE id=?');$playerState->execute([$uid]);$player=$playerState->fetch(PDO::FETCH_ASSOC)?:[];
  $prior=$db->prepare('SELECT COUNT(*) FROM predictions WHERE user_id=?');$prior->execute([$uid]);
  $isFirstPlay=((int)($player['is_bot']??0)===0 && empty($player['play_alert_sent_at']) && (int)$prior->fetchColumn()===0);
  $s=$db->prepare("INSERT INTO predictions(user_id,fixture_id,home_score,away_score) VALUES(?,?,?,?) ON CONFLICT(user_id,fixture_id) DO UPDATE SET home_score=excluded.home_score,away_score=excluded.away_score,saved_at=CURRENT_TIMESTAMP");$s->execute([$uid,$id,$h,$a]);
  if($isFirstPlay){
   $db->prepare('UPDATE users SET first_played_at=COALESCE(first_played_at,CURRENT_TIMESTAMP) WHERE id=?')->execute([$uid]);
   $identity=!empty($player['email'])?(string)$player['email']:'Guest player';
   $subject='New player on PredictionComp';
   $body="A new player has made their first PredictionComp prediction.\n\nDisplay name: ".(string)($player['display_name']??'Unknown')."\nAccount: ".$identity."\nFirst prediction: ".$h."-".$a."\nTime: ".date('d M Y H:i')." UK\n\nAdmin: https://predictioncomp.com/admin.php";
   $headers=["From: PredictionComp <admin@clubdailyfive.com>","Reply-To: admin@clubdailyfive.com","Content-Type: text/plain; charset=UTF-8"];
   if(@mail('pete@salsbury.co.uk',$subject,$body,implode("\r\n",$headers))){
    $db->prepare('UPDATE users SET play_alert_sent_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$uid]);
   }
  }
  echo json_encode(['ok'=>true]);exit;
 }
 if($_GET['api']==='profile'){$n=trim((string)($in['name']??''));$club=trim((string)($in['club']??''));$clubs=['Arsenal','Aston Villa','AFC Bournemouth','Brentford','Brighton & Hove Albion','Burnley','Chelsea','Crystal Palace','Everton','Fulham','Leeds United','Liverpool','Manchester City','Manchester United','Newcastle United','Nottingham Forest','Sunderland','Tottenham Hotspur','West Ham United','Wolverhampton Wanderers'];if(strlen($n)<2||strlen($n)>24||($club!==''&&!in_array($club,$clubs,true))){http_response_code(400);echo json_encode(['ok'=>false]);exit;}$s=$db->prepare('UPDATE users SET display_name=?,supported_club=? WHERE id=?');$s->execute([$n,$club,$uid]);echo json_encode(['ok'=>true]);exit;}
 if($_GET['api']==='league_table'){
  $leagueId=(int)($in['league_id']??0);$period=(string)($in['period']??'season');
  $league=$db->prepare('SELECT l.id,l.name,l.code,l.owner_user_id FROM leagues l JOIN league_members mine ON mine.league_id=l.id AND mine.user_id=? WHERE l.id=?');$league->execute([$uid,$leagueId]);$leagueInfo=$league->fetch(PDO::FETCH_ASSOC);
  if(!$leagueInfo){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'You are not a member of this league']);exit;}
  $now=new DateTimeImmutable('now',new DateTimeZone('Europe/London'));$year=((int)$now->format('n')>=7)?(int)$now->format('Y'):(int)$now->format('Y')-1;
  $start=new DateTimeImmutable($year.'-08-01 00:00:00',new DateTimeZone('Europe/London'));$end=$start->modify('+10 months');$title='Season '.substr((string)$year,2).'/'.substr((string)($year+1),2);
  if($period!=='season'){
   if(!preg_match('/^\d{4}-\d{2}$/',$period)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Invalid period']);exit;}
   $month=DateTimeImmutable::createFromFormat('!Y-m',$period,new DateTimeZone('Europe/London'));
   if(!$month||$month<$start||$month>=$end){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Invalid period']);exit;}
   $start=$month;$end=$month->modify('+1 month');$title=$month->format('F Y');
  }
  $members=$db->prepare("SELECT u.id,u.display_name,CAST(COALESCE(u.is_bot,0) AS INTEGER) is_bot,CAST(COALESCE(u.bot_grade,5) AS INTEGER) bot_grade,CASE WHEN u.id=:owner_id THEN 1 ELSE 0 END is_owner,COALESCE(SUM(CASE WHEN f.id IS NOT NULL THEN p.points ELSE 0 END),0) pts
   FROM league_members lm JOIN users u ON u.id=lm.user_id
   LEFT JOIN predictions p ON p.user_id=u.id
   LEFT JOIN fixtures f ON f.id=p.fixture_id AND f.kickoff_utc>=:period_start AND f.kickoff_utc<:period_end
   WHERE lm.league_id=:league_id GROUP BY u.id ORDER BY pts DESC,u.id");
  $members->execute([':owner_id'=>(int)$leagueInfo['owner_user_id'],':period_start'=>$start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),':period_end'=>$end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),':league_id'=>$leagueId]);
  echo json_encode(['ok'=>true,'league'=>['id'=>(int)$leagueInfo['id'],'name'=>$leagueInfo['name'],'code'=>$leagueInfo['code'],'can_manage'=>(int)$leagueInfo['owner_user_id']===$uid],'period_title'=>$title,'members'=>$members->fetchAll(PDO::FETCH_ASSOC)]);exit;
 }
 if($_GET['api']==='add_league_bot'){
  if(empty($user['email'])){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Please sign in to manage a friends league']);exit;}
  $leagueId=(int)($in['league_id']??0);$grade=(int)($in['grade']??0);
  if($grade<1||$grade>5){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Choose a SIM grade from 1 to 5']);exit;}
  $owner=$db->prepare('SELECT id FROM leagues WHERE id=? AND owner_user_id=?');$owner->execute([$leagueId,$uid]);
  if(!$owner->fetchColumn()){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Only the league creator can add bots']);exit;}
  $available=$db->prepare("SELECT u.id,u.display_name FROM users u
   WHERE COALESCE(u.is_bot,0)=1 AND COALESCE(u.bot_grade,5)=CAST(? AS INTEGER)
   AND NOT EXISTS(SELECT 1 FROM league_members lm WHERE lm.league_id=? AND lm.user_id=u.id)
   ORDER BY RANDOM() LIMIT 1");
  $available->execute([$grade,$leagueId]);$bot=$available->fetch(PDO::FETCH_ASSOC);
  if(!$bot){http_response_code(409);echo json_encode(['ok'=>false,'error'=>'All available SIM'.$grade.' bots are already in this league']);exit;}
  $db->prepare('INSERT INTO league_members(league_id,user_id) VALUES(?,?)')->execute([$leagueId,(int)$bot['id']]);
  echo json_encode(['ok'=>true,'bot'=>['id'=>(int)$bot['id'],'display_name'=>$bot['display_name'],'bot_grade'=>$grade]]);exit;
 }
 if($_GET['api']==='remove_league_member'){
  if(empty($user['email'])){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Please sign in to manage a friends league']);exit;}
  $leagueId=(int)($in['league_id']??0);$memberId=(int)($in['user_id']??0);
  $owner=$db->prepare('SELECT owner_user_id FROM leagues WHERE id=?');$owner->execute([$leagueId]);$ownerId=(int)$owner->fetchColumn();
  if($ownerId<1||$ownerId!==$uid){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Only the league creator can remove players']);exit;}
  if($memberId===$ownerId){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'The league creator cannot be removed']);exit;}
  $member=$db->prepare('SELECT u.display_name FROM league_members lm JOIN users u ON u.id=lm.user_id WHERE lm.league_id=? AND lm.user_id=?');$member->execute([$leagueId,$memberId]);$memberName=$member->fetchColumn();
  if($memberName===false){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'That player is not in this league']);exit;}
  $db->prepare('DELETE FROM league_members WHERE league_id=? AND user_id=?')->execute([$leagueId,$memberId]);
  echo json_encode(['ok'=>true,'display_name'=>$memberName]);exit;
 }
 if($_GET['api']==='league'){if(empty($user['email'])){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Please sign in before creating a friends league']);exit;}$n=trim((string)($in['name']??''));if(strlen($n)<2){http_response_code(400);echo json_encode(['ok'=>false]);exit;}$c=code();$s=$db->prepare('INSERT INTO leagues(code,name,owner_user_id) VALUES(?,?,?)');$s->execute([$c,$n,$uid]);$lid=$db->lastInsertId();$db->prepare('INSERT INTO league_members(league_id,user_id) VALUES(?,?)')->execute([$lid,$uid]);echo json_encode(['ok'=>true,'code'=>$c]);exit;}
 if($_GET['api']==='join'){if(empty($user['email'])){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Please sign in before joining a friends league']);exit;}$c=strtoupper(trim((string)($in['code']??'')));$s=$db->prepare('SELECT id FROM leagues WHERE code=?');$s->execute([$c]);$lid=$s->fetchColumn();if(!$lid){http_response_code(404);echo json_encode(['ok'=>false]);exit;}$db->prepare('INSERT OR IGNORE INTO league_members(league_id,user_id) VALUES(?,?)')->execute([$lid,$uid]);echo json_encode(['ok'=>true]);exit;}
 exit;
}
$fixtures=$db->query("SELECT * FROM fixtures WHERE kickoff_utc>=datetime('now','-3 hours') ORDER BY kickoff_utc LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
$ps=$db->prepare('SELECT fixture_id,home_score,away_score FROM predictions WHERE user_id=?');$ps->execute([$uid]);$pred=[];foreach($ps as $p)$pred[$p['fixture_id']]=[$p['home_score'],$p['away_score']];
$historyStmt=$db->prepare("SELECT f.kickoff_utc,f.home,f.away,f.home_score actual_home,f.away_score actual_away,f.status,p.home_score predicted_home,p.away_score predicted_away,COALESCE(p.points,0) points
 FROM predictions p JOIN fixtures f ON f.id=p.fixture_id
 WHERE p.user_id=? AND f.kickoff_utc<=datetime('now') AND COALESCE(f.status,'')<>'SIM'
 ORDER BY f.kickoff_utc DESC LIMIT 100");
$historyStmt->execute([$uid]);$predictionHistory=$historyStmt->fetchAll(PDO::FETCH_ASSOC);
$ukNow=new DateTimeImmutable('now',new DateTimeZone('Europe/London'));
$seasonYear=((int)$ukNow->format('n')>=7)?(int)$ukNow->format('Y'):(int)$ukNow->format('Y')-1;
$seasonStart=new DateTimeImmutable($seasonYear.'-08-01 00:00:00',new DateTimeZone('Europe/London'));
$seasonEnd=$seasonStart->modify('+10 months');
$leaderPeriods=['season'=>['Season '.substr((string)$seasonYear,2).'/'.substr((string)($seasonYear+1),2),$seasonStart,$seasonEnd]];
for($m=0;$m<10;$m++){
 $ms=$seasonStart->modify('+'.$m.' months');$key=$ms->format('Y-m');
 $leaderPeriods[$key]=[$ms->format('M'),$ms,$ms->modify('+1 month')];
}
$leaderPeriod=(string)($_GET['table']??'season');
if(!isset($leaderPeriods[$leaderPeriod]))$leaderPeriod='season';
[$leaderTitle,$leaderStart,$leaderEnd]=$leaderPeriods[$leaderPeriod];
$leaderPageSize=20;
$leaderCount=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$leaderPages=max(1,(int)ceil($leaderCount/$leaderPageSize));
$leaderPage=max(1,min($leaderPages,(int)($_GET['page']??1)));
$leaderOffset=($leaderPage-1)*$leaderPageSize;
$leaderSql="SELECT u.id,u.display_name,CAST(COALESCE(u.is_bot,0) AS INTEGER) is_bot,CAST(COALESCE(u.bot_grade,5) AS INTEGER) bot_grade,COALESCE(SUM(CASE WHEN f.id IS NOT NULL THEN p.points ELSE 0 END),0) pts
 FROM users u LEFT JOIN predictions p ON p.user_id=u.id
 LEFT JOIN fixtures f ON f.id=p.fixture_id AND f.kickoff_utc>=:period_start AND f.kickoff_utc<:period_end
 GROUP BY u.id ORDER BY pts DESC,u.is_bot ASC,u.id LIMIT ".$leaderPageSize." OFFSET ".$leaderOffset;
$leaderStmt=$db->prepare($leaderSql);
$leaderStmt->execute([':period_start'=>$leaderStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),':period_end'=>$leaderEnd->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
$leaders=$leaderStmt->fetchAll(PDO::FETCH_ASSOC);
$leagues=$db->prepare("SELECT l.id,l.name,l.code,(SELECT COUNT(*) FROM league_members mc WHERE mc.league_id=l.id) member_count FROM leagues l JOIN league_members m ON m.league_id=l.id WHERE m.user_id=? ORDER BY l.created_at DESC");$leagues->execute([$uid]);$myLeagues=$leagues->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Premier League Score Prediction Game | PredictionComp</title><meta name="description" content="Predict Premier League scores, earn points for correct results and exact scores, and compete with friends in private leagues on PredictionComp."><meta name="robots" content="index,follow,max-image-preview:large"><link rel="canonical" href="https://predictioncomp.com/"><link rel="icon" href="/favicon.svg" type="image/svg+xml"><meta property="og:type" content="website"><meta property="og:site_name" content="PredictionComp"><meta property="og:title" content="Premier League Score Prediction Game | PredictionComp"><meta property="og:description" content="Predict Premier League scores, earn points and compete with friends in private leagues."><meta property="og:url" content="https://predictioncomp.com/"><meta name="twitter:card" content="summary"><meta name="twitter:title" content="Premier League Score Prediction Game | PredictionComp"><meta name="twitter:description" content="Predict Premier League scores, earn points and compete with friends."><script type="application/ld+json">{"@context":"https://schema.org","@type":"WebSite","name":"PredictionComp","alternateName":"Prediction Comp","url":"https://predictioncomp.com/","description":"A free Premier League score prediction game with public and friends leagues.","inLanguage":"en-GB"}</script><style>*{box-sizing:border-box}body{margin:0;font-family:system-ui;background:#f4f7f8;color:#10202b}.top,.head,.promo,.footer{background:#071522;color:white}.nav{height:68px;max-width:1160px;margin:auto;padding:0 20px;display:flex;align-items:center;justify-content:space-between}.brand{font-size:23px;font-weight:900}.brand b,.hero span{color:#35df7b}.nav a{color:white;text-decoration:none;margin-left:22px;font-weight:700}.hero{max-width:1160px;margin:auto;padding:55px 20px}.hero h1{font-size:clamp(44px,6vw,70px);line-height:1;letter-spacing:-3px;margin:0}.hero p{font-size:19px;color:#c9d4dc}.btn{background:#35df7b;color:#062014;border:0;border-radius:9px;padding:12px 17px;font-weight:900;cursor:pointer}.features{background:white}.features>div{max-width:1160px;margin:auto;display:grid;grid-template-columns:repeat(4,1fr);padding:20px}.f{text-align:center}.f b{display:block}.main{max-width:1160px;margin:26px auto;padding:0 18px;display:grid;grid-template-columns:2fr 1fr;gap:20px}.card{background:white;border:1px solid #dce4e8;border-radius:14px;overflow:hidden}.head{padding:17px 19px}.head h2{margin:0}.fixture{display:grid;grid-template-columns:105px 1fr 120px 1fr;gap:8px;align-items:center;padding:13px;border-bottom:1px solid #edf1f3;font-size:14px}.home{text-align:right;font-weight:750}.away{font-weight:750}.date{font-size:11px;color:#71808a}.score{display:flex;gap:5px;align-items:center}.score input{width:48px;height:40px;text-align:center;border:1px solid #cbd5db;border-radius:8px;font-weight:800}.locked{color:#8b98a1;font-size:12px}.side{padding:15px}.row{display:grid;grid-template-columns:28px 1fr 45px;padding:10px;border-bottom:1px solid #eee}.tools{padding:16px}.tools input{width:100%;padding:10px;margin:5px 0 10px;border:1px solid #ccd5db;border-radius:7px}.league{padding:8px 0;border-bottom:1px solid #eee}.league code{float:right}.promo{border-radius:13px;padding:20px;margin-top:18px}.footer{text-align:center;padding:22px;color:#8798a6;font-size:12px}.footerlinks{display:flex;justify-content:center;flex-wrap:wrap;gap:8px 18px;margin-bottom:11px}.footerlinks a{color:#b9c9d3;text-decoration:none;font-weight:700}.footerlinks a:hover,.footerlinks a:focus{color:#46e98a;text-decoration:underline}.infomodal{position:fixed;inset:0;z-index:1500;background:#071522dd;display:grid;place-items:center;padding:20px}.infomodal[hidden]{display:none!important}.infodialog{position:relative;width:min(760px,100%);max-height:calc(100dvh - 40px);overflow:auto;border:1px solid #2b4252;border-radius:18px;background:#0e1d29;color:#eef5f8;padding:clamp(24px,5vw,42px);box-shadow:0 24px 80px #0008}.infodialog h1{font-size:clamp(34px,7vw,52px);line-height:1.05;margin:5px 48px 20px 0;letter-spacing:-1.5px}.infodialog h2{font-size:20px;margin-top:26px}.infodialog p,.infodialog li{color:#b6c4cd;line-height:1.65}.infodialog strong{color:#f4f8fa}.infodialog a{color:#46e98a}.infoeyebrow{color:#35df7b;font-size:10px;font-weight:900;letter-spacing:1.5px}.infoclose{position:absolute;right:15px;top:12px;border:0;background:transparent;color:#a9bac5;font-size:31px;cursor:pointer}.infoclose:hover,.infoclose:focus{color:#46e98a}.scoringlist{display:grid;gap:9px;padding:0;list-style:none}.scoringlist li{padding:12px 14px;border:1px solid #2b4252;border-radius:10px;background:#122431}body.infoopen{overflow:hidden}@media(max-width:600px){.infomodal{padding:0}.infodialog{width:100%;height:100dvh;max-height:none;border:0;border-radius:0;padding:25px 20px}.footer{padding-left:16px;padding-right:16px}}.empty{padding:35px;text-align:center;color:#657681}@media(max-width:720px){.nav .links{display:none}.main{grid-template-columns:1fr;padding:0 8px}.fixture{grid-template-columns:65px 1fr 100px 1fr;padding:10px 7px;font-size:12px}.features>div{grid-template-columns:repeat(2,1fr);gap:15px}.hero{padding:40px 18px}.hero h1{font-size:48px}}.heroactions{display:flex;gap:10px;margin-top:24px}.ghost,.outline{display:inline-block;background:transparent;color:white;border:1px solid #ffffff55;border-radius:9px;padding:11px 16px;font-weight:800;text-decoration:none;cursor:pointer}.features .f span{display:inline-grid;place-items:center;width:26px;height:26px;border-radius:50%;background:#e8fbf0;color:#117341;font-weight:900;margin-bottom:5px}.f small{display:block;color:#71808a;margin-top:3px}.head{display:flex;align-items:center;justify-content:space-between}.head small,.sectionlabel{font-size:10px;font-weight:900;letter-spacing:1.5px;color:#35df7b}.headhint{font-size:11px;color:#aebdc7}.tools h3{margin:5px 0 6px;font-size:20px}.explain{font-size:13px;line-height:1.45;color:#687985;margin:0 0 14px}.leagueactions{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px}.leagueactions>div{background:#f5f8f9;border:1px solid #e1e8eb;border-radius:10px;padding:12px}.leagueactions small{display:block;color:#73828c;margin-top:2px}.tools .outline{color:#10202b;border-color:#bdc9d0;width:100%}.profile{margin-top:18px}.hero{position:relative}.hero:after{content:"⚽";position:absolute;right:7%;top:30px;font-size:130px;opacity:.08;transform:rotate(-12deg)}@media(max-width:720px){.heroactions{flex-direction:column;align-items:stretch}.heroactions a{text-align:center}.leagueactions{grid-template-columns:1fr}.headhint{display:none}}.team{display:flex;align-items:center;gap:8px}.home.team{justify-content:flex-end}.team img{width:28px;height:28px;object-fit:contain;flex:0 0 28px}@media(max-width:720px){.team{gap:5px}.team img{width:22px;height:22px;flex-basis:22px}}.profilebtn{margin-left:20px;background:#35df7b;color:#062014;border:0;border-radius:8px;padding:9px 13px;font-weight:900;cursor:pointer}dialog{border:0;border-radius:16px;padding:0;width:min(92vw,460px);box-shadow:0 24px 70px #0006}dialog::backdrop{background:#071522aa;backdrop-filter:blur(3px)}.profilemodal{position:relative;padding:28px}.profilemodal h2{margin:5px 0 7px;font-size:26px}.profilemodal label{display:block;font-size:12px;font-weight:800;margin-top:18px;color:#41515c}.profilemodal input,.profilemodal select{width:100%;height:46px;margin-top:6px;border:1px solid #cbd5db;border-radius:9px;padding:0 12px;background:white;font:inherit;color:#10202b}.closex{position:absolute;right:16px;top:13px;border:0;background:transparent;font-size:29px;color:#687985;cursor:pointer}.clubselect{display:grid;grid-template-columns:42px 1fr;align-items:center;gap:8px}.clubselect img{width:36px;height:36px;object-fit:contain;margin-top:6px}.profilesave{width:100%;margin-top:22px}#profileDialog .profilemodal{max-height:calc(100dvh - 24px);overflow-y:auto}.clubfieldnote{display:block;color:#8fa1ad;font-size:11px;font-weight:500;line-height:1.35;margin-top:5px}@media(max-width:720px){.profilebtn{margin-left:0}.nav{gap:10px}.brand{font-size:20px}}.leaguebtn{margin-left:20px;background:transparent;color:white;border:0;font-weight:700;cursor:pointer;font:inherit}.leaguesmodal{max-height:85vh;overflow:auto}.myleagues{margin:20px 0}.myleagues h3{margin:0 0 9px}.leagueitem{display:grid;grid-template-columns:1fr auto auto;gap:10px;align-items:center;padding:11px 12px;border:1px solid #e0e7ea;border-radius:10px;margin-bottom:8px}.leagueitem small{display:block;color:#7a8992;margin-top:2px}.leagueitem code{font-weight:900;letter-spacing:1px}.copybtn{border:1px solid #ccd6dc;background:white;border-radius:7px;padding:7px 9px;cursor:pointer;font-weight:800}.emptyleagues{background:#f5f8f9;border-radius:9px;padding:13px;color:#71808a;font-size:13px}.modalactions{grid-template-columns:1fr}.modalactions .btn,.modalactions .outline{width:100%}.leaguesmodal input{width:100%;padding:10px;margin:8px 0 10px;border:1px solid #ccd5db;border-radius:7px}.leaguesmodal .outline{color:#10202b;border-color:#bdc9d0}@media(max-width:720px){.leaguebtn{margin-left:0}.nav .links{display:flex;gap:7px}.nav .links>a{display:none}.leaguebtn,.profilebtn{font-size:12px;padding:8px 9px}.brand{font-size:18px}}.signinbtn{margin-left:10px;background:#132635;color:#eef5f8;border:1px solid #40596a;border-radius:8px;padding:9px 13px;font-weight:800;cursor:pointer}
.authmodal label{display:block;font-size:12px;font-weight:800;color:#b6c4cd}
.authmodal input{width:100%;height:46px;margin-top:7px;border:1px solid #3a5364;border-radius:9px;padding:0 12px;background:#132635;color:#f4f8fa;font:inherit}
.googlebtn{display:flex;align-items:center;justify-content:center;gap:11px;width:100%;height:48px;border:1px solid #506778;border-radius:9px;background:#f7f9fa;color:#17232b;text-decoration:none;font-weight:800}
.googlemark{display:grid;place-items:center;width:23px;height:23px;border-radius:50%;background:#fff;color:#4285f4;font-size:17px;font-weight:900}
.googlebtn.isdisabled{opacity:.55;cursor:not-allowed}
.googleloginwrap{position:relative;width:100%;min-height:58px;border-radius:14px;overflow:hidden}
.googlelogin{display:flex;align-items:center;justify-content:center;gap:14px;width:100%;min-height:58px;padding:12px 18px;background:#eef3f8;border:1px solid #e2eaf0;border-radius:14px;color:#17232b;font-size:17px;font-weight:800;box-shadow:0 2px 8px #0002;pointer-events:none}
.googlelogo{width:25px;height:25px;flex:0 0 25px}
.googlehit{position:absolute;inset:0;z-index:2;opacity:.001;overflow:hidden;display:flex;align-items:stretch;justify-content:center;cursor:pointer}
.googlehit>div,.googlehit iframe{width:100%!important;height:100%!important;max-width:none!important}
.authdivider{display:flex;align-items:center;gap:13px;margin:20px 0;color:#91a6b4;font-size:13px;font-weight:800}
.authdivider:before,.authdivider:after{content:"";height:1px;background:#2b4252;flex:1}
.authsubmit{width:100%;margin-top:12px}
.authmessage{min-height:20px;margin-top:10px;color:#46e98a;font-size:13px}
.authnote{color:#8fa1ad;font-size:12px;line-height:1.4;margin:8px 0 0}
.inviteprompt{margin:13px 0 17px;padding:12px 13px;border:1px solid #315846;border-radius:10px;background:#102d22;color:#bfeacf;font-size:13px;line-height:1.45}.inviteprompt strong{color:#46e98a}
.accountemail{background:#132635;border:1px solid #2b4252;border-radius:9px;padding:13px;margin:17px 0;font-weight:800}
.linkedprovider{color:#46e98a;font-size:13px;margin:-7px 0 18px}
.signoutlink{display:block;width:100%;margin-top:10px;text-align:center}
@media(max-width:720px){.signinbtn{margin-left:0;font-size:11px;padding:8px}.nav .links{gap:5px}.leaguebtn,.profilebtn{font-size:11px;padding:8px}.brand{font-size:16px}.nav{padding:0 10px}}
/* PredictionComp dark theme + stable fixture alignment */
:root{color-scheme:dark}
body{background:#07111b;color:#eef5f8}
.top,.head,.promo,.footer{background:#06131f}
.features{background:#0a1824;border-bottom:1px solid #1d3444}
.features .f span{background:#123624;color:#46e98a}
.f small,.date,.explain,.leagueitem small,.emptyleagues{color:#9aabb7}
.main{align-items:start}
.card{background:#0e1d29;border-color:#263b49;box-shadow:0 12px 32px #00000024}
.fixture{grid-template-columns:105px minmax(0,1fr) 120px minmax(0,1fr);border-bottom-color:#223442}
.team{display:grid;align-items:center;min-width:0}
.home.team{grid-template-columns:minmax(0,1fr) 28px;justify-content:stretch;text-align:right}
.away.team{grid-template-columns:28px minmax(0,1fr);justify-content:stretch;text-align:left}
.team span{min-width:0;line-height:1.16}
.score{justify-content:center}
.score input,.tools input,.leaguesmodal input,.profilemodal input,.profilemodal select{background:#132635;color:#f4f8fa;border-color:#3a5364}
.score input:focus,.tools input:focus,.leaguesmodal input:focus,.profilemodal input:focus,.profilemodal select:focus{outline:2px solid #35df7b;outline-offset:1px}
.row{border-bottom-color:#223442}.periodtabs{display:flex;gap:6px;overflow-x:auto;margin-top:14px;padding:0 0 3px;scrollbar-width:thin}.periodtabs a{flex:0 0 auto;color:#b9c9d3;text-decoration:none;border:1px solid #375063;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:800}.periodtabs a:hover,.periodtabs a.active{background:#35df7b;border-color:#35df7b;color:#062014}.simbadge{display:inline-block;margin-left:7px;padding:2px 5px;border:1px solid #3a596b;border-radius:4px;color:#91a9b8;font-size:9px;font-weight:900;letter-spacing:.7px;vertical-align:1px}.tablepager{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:8px;padding:13px 15px;border-top:1px solid #223442;color:#91a9b8;font-size:12px;font-weight:800}.tablepager a{color:#46e98a;text-decoration:none;padding:7px 3px}.tablepager a:last-child{text-align:right}.tablepager a.disabled{color:#536876;pointer-events:none}.tablepager span{text-align:center;white-space:nowrap}
.league{border-bottom-color:#223442}
.leagueactions>div,.emptyleagues{background:#122431;border-color:#2a4050}
.tools .outline,.leaguesmodal .outline{color:#eef5f8;border-color:#4c6373}
dialog{background:#0e1d29;color:#eef5f8;border:1px solid #2b4252}
.profilemodal label{color:#b6c4cd}
.closex{color:#a9bac5}
.leagueitem{border-color:#2b4252}
.copybtn{background:#172b3a;color:#eef5f8;border-color:#40596a}
.empty{color:#9aabb7}.leagueviewbtn{border:0;background:none;color:#46e98a;font-weight:900;cursor:pointer;padding:7px 4px;white-space:nowrap}.leagueviewbtn:hover,.leagueviewbtn:focus{text-decoration:underline}.friendtable{margin-top:18px;border:1px solid #2b4252;border-radius:11px;overflow:hidden}.friendrow{display:grid;grid-template-columns:30px minmax(0,1fr) auto;gap:9px;align-items:center;padding:12px 13px;border-bottom:1px solid #2b4252}.friendrow:last-child{border-bottom:0}.friendrow .rank{color:#91a9b8;font-weight:900}.friendrow .membername{font-weight:800;min-width:0;overflow-wrap:anywhere}.friendrow .owner{display:block;color:#91a9b8;font-size:10px;font-weight:900;letter-spacing:.7px;margin-top:2px}.friendrow .points{font-weight:900;color:#46e98a;white-space:nowrap}.friendactions{display:flex;align-items:center;justify-content:flex-end;gap:8px}.removeplayerbtn{border:1px solid #704553;background:#271923;color:#ffb5c1;border-radius:7px;padding:5px 7px;font:inherit;font-size:10px;font-weight:900;cursor:pointer}.removeplayerbtn:hover,.removeplayerbtn:focus{background:#5a2638;color:#fff}.removeplayerbtn:disabled{opacity:.55;cursor:wait}.friendactions{display:grid;grid-template-columns:68px 62px;align-items:center;justify-content:end;gap:8px;width:138px}.friendactions .points{text-align:right}.friendactions .removeplayerbtn{grid-column:2}@media(max-width:480px){.friendrow{grid-template-columns:24px minmax(0,1fr) 104px;gap:7px;padding-left:9px;padding-right:9px}.friendactions{grid-template-columns:1fr;width:104px;gap:4px}.friendactions .points{grid-column:1}.friendactions .removeplayerbtn{grid-column:1;justify-self:end}}.friendperiodtabs{margin:13px 0 4px}.friendperiodtabs button{flex:0 0 auto;color:#b9c9d3;background:transparent;border:1px solid #375063;border-radius:999px;padding:7px 10px;font:inherit;font-size:12px;font-weight:800;cursor:pointer}.friendperiodtabs button:hover,.friendperiodtabs button.active{background:#35df7b;border-color:#35df7b;color:#062014}
.botcontrols{display:grid;grid-template-columns:minmax(0,1fr) 145px auto;gap:9px;align-items:center;margin:14px 0;padding:12px;border:1px solid #315846;border-radius:11px;background:#102d22}.botcontrols[hidden]{display:none}.botcontrols b{display:block;color:#eef5f8;font-size:13px}.botcontrols small{display:block;color:#9fcbb0;font-size:11px;margin-top:2px}.botcontrols select{width:100%;height:40px;margin:0;border:1px solid #3b6953;border-radius:8px;background:#132635;color:#eef5f8;padding:0 9px;font:inherit;font-size:12px;font-weight:800}.botcontrols .btn{height:40px;padding:0 13px;white-space:nowrap}.botmessage{grid-column:1/-1;min-height:0;color:#46e98a;font-size:12px}.botmessage:not(:empty){margin-top:2px}.botcontrols button:disabled{opacity:.6;cursor:wait}@media(max-width:560px){.botcontrols{grid-template-columns:1fr auto}.botcontrols>div:first-child{grid-column:1/-1}.botcontrols select{min-width:0}}
@media(max-width:720px){
 .fixture{grid-template-columns:70px minmax(0,1fr) 96px minmax(0,1fr);gap:5px;padding:12px 8px;font-size:12px}
 .date{font-size:11px;line-height:1.18}
 .home.team{grid-template-columns:minmax(0,1fr) 23px}
 .away.team{grid-template-columns:23px minmax(0,1fr)}
 .team img{width:23px;height:23px;flex-basis:23px}
 .team span{font-size:12px;line-height:1.15}
 .score{gap:4px}
 .score input{width:41px;height:42px;padding:0}
}

/* Mobile readability and overflow fix */
html,body{max-width:100%;overflow-x:hidden}
.main,.card,#predict,aside{min-width:0}
#leader .head{display:block}
@media(max-width:900px){
 .top,.features,.main,.footer{width:100%;max-width:100%}
 .nav{width:100%;min-height:64px;height:auto;padding:8px 12px;gap:8px}
 .brand{font-size:17px;white-space:nowrap}
 .nav .links{display:flex;align-items:center;justify-content:flex-end;gap:5px;min-width:0}
 .nav .links>a{display:none}
 .leaguebtn,.signinbtn,.profilebtn{margin-left:0;font-size:12px;line-height:1.1;padding:9px 8px;white-space:nowrap}
 .hero{width:100%;padding:24px 18px 30px}
 .hero h1{font-size:clamp(30px,8.5vw,42px);line-height:1.04;letter-spacing:-1.5px}
 .hero p{font-size:17px;line-height:1.4}
 .features>div{width:100%;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 8px;padding:18px 10px}
 .f b{font-size:15px}.f small{font-size:13px;line-height:1.3}
 .main{width:100%;grid-template-columns:minmax(0,1fr);margin:18px auto;padding:0 8px;gap:18px}
 .card{width:100%}
 .head{padding:18px;min-width:0}
 .head h2{font-size:22px;line-height:1.18}
 .fixture{
   width:100%;min-width:0;
   grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);
   grid-template-areas:"date date date" "home score away";
   column-gap:9px;row-gap:10px;
   padding:13px 10px 16px;font-size:15px
 }
 .date{grid-area:date;text-align:center;font-size:13px;line-height:1.25}
 .home.team{grid-area:home;grid-template-columns:minmax(0,1fr) 29px;gap:7px}
 .score{grid-area:score;gap:5px}
 .away.team{grid-area:away;grid-template-columns:29px minmax(0,1fr);gap:7px}
 .team img{width:29px;height:29px;flex-basis:29px}
 .team span{font-size:15px;line-height:1.2;overflow-wrap:anywhere}
 .score input{width:45px;height:48px;font-size:17px;padding:0}
 .locked{font-size:14px}
 .periodtabs{width:100%;margin-top:13px;padding-bottom:6px}
 .periodtabs a{font-size:13px;padding:8px 11px}
 .row{font-size:15px;padding:12px 8px}
}
@media(max-width:430px){
 .brand{font-size:15px}
 .leaguebtn{font-size:0}
 .leaguebtn:after{content:"Leagues";font-size:12px}
 .signinbtn,.profilebtn{font-size:12px;padding:8px 7px}
 .fixture{grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);padding-left:8px;padding-right:8px}
 .team span{font-size:14px}
 .team img{width:27px;height:27px}
 .home.team{grid-template-columns:minmax(0,1fr) 27px}
 .away.team{grid-template-columns:27px minmax(0,1fr)}
 .score input{width:43px}
}

.displaynamesummary{display:flex;flex-direction:column;gap:4px;background:#132635;border:1px solid #2b4252;border-radius:9px;padding:12px 13px;margin:0 0 12px}
.displaynamesummary small{color:#8fa1ad;font-size:10px;font-weight:900;letter-spacing:1.2px}
.displaynamesummary strong{font-size:18px;color:#f4f8fa}
.editdisplayname{width:100%;margin-bottom:10px}
.computerdisclosure{margin:10px 0 0;color:#8fa1ad;font-size:12px;line-height:1.35}
.pickactions{display:flex;align-items:center;gap:10px}
.historybtn{border:1px solid #40596a;background:#132635;color:#eef5f8;border-radius:8px;padding:8px 11px;font-weight:800;cursor:pointer;white-space:nowrap}
.historybtn:hover{border-color:#35df7b;color:#46e98a}
.historymodal{padding-bottom:20px}
.historylist{max-height:min(65vh,620px);overflow:auto;margin:16px -8px 0;padding:0 8px}
.historyrow{border:1px solid #2b4252;background:#122431;border-radius:11px;padding:13px;margin-bottom:10px}
.historyrow time{display:block;color:#8fa1ad;font-size:12px;margin-bottom:7px}
.historymatch{display:grid;grid-template-columns:minmax(0,1fr) 18px minmax(0,1fr);align-items:center;gap:6px;font-size:14px}
.historymatch strong:first-child{text-align:right}.historymatch strong:last-child{text-align:left}.historymatch span{text-align:center;color:#718493}
.historyscores{display:grid;grid-template-columns:1fr 1fr auto;align-items:center;gap:8px;margin-top:10px;padding-top:9px;border-top:1px solid #2b4252;font-size:12px;color:#a9bac5}
.historyscores b{color:#f4f8fa;font-size:14px;margin-left:3px}.historyscores em{font-style:normal;font-weight:900;color:#91a9b8}.historyscores em.won{color:#46e98a}
.emptyhistory{padding:28px 12px;text-align:center;color:#9aabb7;line-height:1.45}
@media(max-width:720px){.pickactions{margin-left:auto}.pickactions .headhint{display:none}.historybtn{font-size:12px;padding:8px}.historyscores{grid-template-columns:1fr 1fr}.historyscores em{grid-column:1/-1;text-align:right}.historymodal{padding:24px 18px 18px}}
.playerlink{appearance:none;border:0;background:none;color:#eef5f8;padding:0;font:inherit;font-weight:800;text-align:left;cursor:pointer;text-decoration:underline;text-decoration-color:#4a6778;text-underline-offset:3px}.playerlink:hover,.playerlink:focus{color:#46e98a;text-decoration-color:#46e98a}.playerresults-error{color:#ffb5b5}
.botresulthead{display:flex;align-items:center;gap:14px;margin:2px 42px 12px 0}.botresulthead img{width:68px;height:68px;border-radius:16px;flex:0 0 68px;box-shadow:0 6px 18px #0002}.botresulthead h2{margin:0}.botprofilelink{display:inline-flex;margin-top:5px;padding:0;border:0;background:transparent;color:#117341;font:inherit;font-size:13px;font-weight:850;text-decoration:underline;cursor:pointer}.botprofilelink[hidden],.botresulthead img[hidden]{display:none!important}.botprofilecard{text-align:center;padding:30px}.botprofilecard .botlarge{width:112px;height:112px;border-radius:24px;box-shadow:0 12px 30px #0003}.botprofilecard h2{margin:13px 0 3px}.botprofiletitle{display:inline-block;color:#117341;background:#e5f9ed;border-radius:999px;padding:6px 10px;font-size:11px;font-weight:900;letter-spacing:.7px}.botbio{font-size:16px;line-height:1.65;color:#40535f;margin:18px 0 4px;text-align:left;background:#f4f8f9;border:1px solid #dbe7eb;border-radius:13px;padding:17px}.botgradekey{font-size:12px;color:#71808a;margin-top:13px}@media(max-width:600px){.botresulthead img{width:62px;height:62px;flex-basis:62px}.botresulthead{gap:12px;margin-right:34px}.botprofilecard{padding:26px 20px}.botprofilecard .botlarge{width:104px;height:104px}}
.registrationgate{padding:20px;display:flex;align-items:center;gap:16px;border-bottom:1px solid #2b4252;background:#122431;color:#eef5f8}.registrationgate p{margin:6px 0 0;color:#b6c4cd;font-size:14px;line-height:1.5}.registrationgate .btn{flex-shrink:0}.registerscore{width:100%;padding:10px 4px;border:1px solid #35df7b;border-radius:8px;background:#122431;color:#35df7b;font-size:11px;font-weight:800;cursor:pointer}.authmodal details{margin-top:15px;color:#b6c4cd}.authmodal summary{cursor:pointer;font-size:13px}@media(max-width:600px){.registrationgate{align-items:stretch;flex-direction:column}.registrationgate .btn{width:100%}}
.joincode{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-top:8px;font-size:12px;color:#b6c4cd}.joincode strong{color:#eef5f8;letter-spacing:1px;user-select:all}.joincode .copyjoincode{float:none;margin:0;padding:7px 10px;min-width:88px;min-height:36px;font-size:12px}</style></head><body><header class="top"><nav class="nav"><div class="brand">⚽ Prediction<b>Comp</b></div><div class="links"><a href="#predict">Predictions</a><a href="#leader">Leaderboard</a><button class="leaguebtn" onclick="openLeagues()">Friends Leagues</button><button class="signinbtn" onclick="<?=$uid>0?'openProfile()':'openAuth()'?>"><?=$uid>0?'Account':'Register / Sign in'?></button></div></nav><div class="hero"><h1>Predict. Compete.<br><span>Climb the table.</span></h1><p>Call the scores. Earn points. Beat your mates — and the bots.</p><div class="heroactions"><?php if($uid>0):?><a class="btn" href="#predict">Make predictions</a><?php else:?><button type="button" class="btn" onclick="openAuth()">Register to make predictions</button><?php endif;?><button class="ghost" onclick="openLeagues()">Play with friends</button></div></div></header><section class="features"><div><div class="f"><span>1</span><b>Predict</b><small>Choose every score</small></div><div class="f"><span>2</span><b>Lock in</b><small>Editable until kick-off</small></div><div class="f"><span>3</span><b>Score points</b><small>5 exact · 3 result</small></div><div class="f"><span>4</span><b>Beat the bots</b><small>Five graded challenges</small></div></div></section><main class="main"><section class="card" id="predict"><div class="head"><div><small>YOUR PICKS</small><h2>Next Premier League fixtures</h2></div><div class="pickactions"><span class="headhint"><?=$uid>0?'Saved automatically':'Register to enter your scores'?></span><button type="button" class="historybtn" onclick="openHistory()">Past predictions</button></div></div><?php if($uid<1):?><div class="registrationgate"><div><b>Join the competition</b><p>Register with Google or choose a display name to enter your predictions and start earning points.</p></div><button type="button" class="btn" onclick="openAuth()">Register to play</button></div><?php endif;?><?php if(!$fixtures):?><div class="empty">The next Premier League fixtures are being loaded.</div><?php endif;?><?php foreach($fixtures as $f):$p=$pred[$f['id']]??[0,0];$lock=strtotime($f['kickoff_utc'])<=time();?><div class="fixture"><div class="date"><?=(new DateTimeImmutable($f['kickoff_utc'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/London'))->format('D d M H:i')?> UK</div><div class="home team"><span><?=htmlspecialchars($f['home'])?></span><img src="/club-logo.php?team=<?=urlencode($f['home'])?>" alt="" loading="lazy"></div><div class="score"><?php if($lock):?><span class="locked">🔒 <?=$p[0]?>–<?=$p[1]?></span><?php elseif($uid<1):?><button type="button" class="registerscore" onclick="openAuth()" aria-label="Register to predict this fixture">Join to predict</button><?php else:?><input type="number" min="0" max="15" value="<?=$p[0]?>" onchange="save(<?=$f['id']?>,this.value,this.parentNode.querySelector('.a').value)"><span>–</span><input class="a" type="number" min="0" max="15" value="<?=$p[1]?>" onchange="save(<?=$f['id']?>,this.parentNode.querySelector('input').value,this.value)"><?php endif;?></div><div class="away team"><img src="/club-logo.php?team=<?=urlencode($f['away'])?>" alt="" loading="lazy"><span><?=htmlspecialchars($f['away'])?></span></div></div><?php endforeach;?></section><aside><section class="card" id="leader"><div class="head"><small>PUBLIC LEAGUE</small><h2><?=htmlspecialchars($leaderTitle)?> table</h2><nav class="periodtabs" aria-label="Choose leaderboard period"><?php foreach($leaderPeriods as $periodKey=>$period):?><a href="?table=<?=urlencode($periodKey)?>#leader" class="<?=$leaderPeriod===$periodKey?'active':''?>" <?=$leaderPeriod===$periodKey?'aria-current="page"':''?>><?=htmlspecialchars($period[0])?></a><?php endforeach;?></nav><p class="computerdisclosure">Beat the bots: SIM1 is the toughest challenge; SIM5 is completely unpredictable.</p></div><div class="side"><?php $n=$leaderOffset+1;foreach($leaders as $l):?><div class="row"><b><?=$n++?></b><span><button type="button" class="playerlink" onclick="openPlayerResults(<?=(int)$l['id']?>)"><?=htmlspecialchars($l['display_name'])?></button><?php if(!empty($l['is_bot'])):?><small class="simbadge">SIM<?=max(1,min(5,(int)($l['bot_grade']??5)))?></small><?php endif;?></span><b><?=$l['pts']?></b></div><?php endforeach;?></div><?php if($leaderPages>1):?><nav class="tablepager" aria-label="Leaderboard pages"><a class="<?=$leaderPage<=1?'disabled':''?>" <?=$leaderPage>1?'href="?table='.urlencode($leaderPeriod).'&page='.($leaderPage-1).'#leader"':'aria-disabled="true"'?>>Previous</a><span>Page <?=$leaderPage?> of <?=$leaderPages?></span><a class="<?=$leaderPage>=$leaderPages?'disabled':''?>" <?=$leaderPage<$leaderPages?'href="?table='.urlencode($leaderPeriod).'&page='.($leaderPage+1).'#leader"':'aria-disabled="true"'?>>Next</a></nav><?php endif;?></section><div class="promo"><b>Scoring</b><p>5 points exact score · 3 points correct result · 0 otherwise.</p></div></aside><dialog id="playerResultsDialog"><div class="profilemodal historymodal"><button type="button" class="closex" onclick="closePlayerResults()" aria-label="Close">×</button><div class="sectionlabel" id="playerResultsPeriod">PLAYER RESULTS</div><div class="botresulthead"><img id="playerResultsAvatar" alt="" hidden><div><h2 id="playerResultsName">Player predictions</h2><button type="button" class="botprofilelink" id="botProfileLink" onclick="openBotProfile()" hidden>Meet this bot</button></div></div><p class="explain">Predictions are shown once each match has kicked off.</p><div class="historylist" id="playerResultsList"><div class="emptyhistory">Loading predictions…</div></div></div></dialog><dialog id="botProfileDialog"><div class="profilemodal botprofilecard"><button type="button" class="closex" onclick="closeBotProfile()" aria-label="Close">×</button><div class="sectionlabel">BOT PROFILE</div><img class="botlarge" id="botProfileAvatar" alt=""><h2 id="botProfileName">Bot</h2><div class="botprofiletitle" id="botProfileTitle"></div><p class="botbio" id="botProfileBio"></p><p class="botgradekey" id="botProfileGrade"></p></div></dialog><dialog id="historyDialog"><div class="profilemodal historymodal"><button type="button" class="closex" onclick="closeHistory()" aria-label="Close">×</button><div class="sectionlabel">YOUR RESULTS</div><h2>Past predictions</h2><p class="explain">Your most recent predictions, results and points.</p><div class="historylist"><?php if(!$predictionHistory):?><div class="emptyhistory">Your completed predictions will appear here after the matches are played.</div><?php endif;?><?php foreach($predictionHistory as $h):$finished=$h['actual_home']!==null&&$h['actual_away']!==null;?><article class="historyrow"><time><?=gmdate('D d M Y, H:i',strtotime($h['kickoff_utc']))?></time><div class="historymatch"><strong><?=htmlspecialchars($h['home'])?></strong><span>v</span><strong><?=htmlspecialchars($h['away'])?></strong></div><div class="historyscores"><span>Prediction <b><?=$h['predicted_home']?>–<?=$h['predicted_away']?></b></span><span>Result <b><?=$finished?$h['actual_home'].'–'.$h['actual_away']:'Pending'?></b></span><em class="<?=$finished&&$h['points']>0?'won':''?>"><?=$finished?'+'.$h['points'].' pts':'Awaiting result'?></em></div></article><?php endforeach;?></div></div></dialog><dialog id="authDialog"><div class="profilemodal authmodal"><button type="button" class="closex" onclick="closeAuth()" aria-label="Close">×</button><div class="sectionlabel">YOUR ACCOUNT</div><?php if(!empty($user['email'])):?><h2>You're signed in</h2><p class="explain">Your predictions and friends leagues will now follow you across devices.</p><div class="accountemail"><?=htmlspecialchars((string)$user['email'])?></div><?php if(!empty($user['google_sub'])):?><div class="linkedprovider">✓ Google account linked</div><?php endif;?><div class="displaynamesummary"><small>LEADERBOARD DISPLAY NAME</small><strong><?=htmlspecialchars((string)$user['display_name'])?></strong></div><button type="button" class="btn editdisplayname" onclick="closeAuth();openProfile();document.getElementById('profileName').focus()">Edit display name</button><a class="outline signoutlink" href="?auth=logout">Sign out on this device</a><?php else:?><h2>Join PredictionComp</h2><p class="explain">Create your player profile before making predictions. Earn points, climb the table and beat the bots.</p><?php if($pendingInviteName):?><div class="inviteprompt">You’ve been invited to join <strong><?=htmlspecialchars((string)$pendingInviteName)?></strong>. Sign in and you’ll be added automatically.</div><?php endif;?><div id="authMessage" class="authmessage" role="status"></div><div class="googleloginwrap"><div class="googlelogin" aria-hidden="true"><svg class="googlelogo" viewBox="0 0 18 18" aria-hidden="true"><path fill="#4285F4" d="M17.64 9.205c0-.638-.057-1.252-.164-1.841H9v3.481h4.844a4.14 4.14 0 0 1-1.797 2.716v2.258h2.909c1.702-1.567 2.684-3.877 2.684-6.614Z"/><path fill="#34A853" d="M9 18c2.43 0 4.468-.806 5.956-2.181l-2.909-2.258c-.806.54-1.836.859-3.047.859-2.344 0-4.328-1.585-5.037-3.714H.956v2.332A9 9 0 0 0 9 18Z"/><path fill="#FBBC05" d="M3.963 10.706A5.41 5.41 0 0 1 3.682 9c0-.592.102-1.168.281-1.706V4.962H.956A9 9 0 0 0 0 9c0 1.452.347 2.827.956 4.038l3.007-2.332Z"/><path fill="#EA4335" d="M9 3.58c1.321 0 2.507.454 3.441 1.346l2.581-2.581C13.464.892 11.426 0 9 0A9 9 0 0 0 .956 4.962l3.007 2.332C4.672 5.165 6.656 3.58 9 3.58Z"/></svg><span>Continue with Google</span></div><div id="googleButton" class="googlehit" data-client-id="<?=htmlspecialchars((string)(pc_google_config()['client_id']??''))?>" aria-label="Continue with Google"></div></div><p class="authnote">Use your Google account to keep your predictions across devices.</p><div class="authdivider"><span>or choose a display name</span></div><form onsubmit="registerName(event)"><label>Display name<input id="registerName" name="nickname" autocomplete="nickname" minlength="2" maxlength="24" placeholder="Your name on the leaderboard" required></label><button class="btn authsubmit" type="submit">Create profile &amp; start predicting</button></form><p class="authnote">Your profile is remembered in this browser. Use Google if you want to play on another device.</p><details><summary>Already use email sign-in?</summary><form onsubmit="sendMagicLink(event)"><label>Email address<input id="loginEmail" type="email" autocomplete="email" placeholder="you@example.com" required></label><button class="btn authsubmit" type="submit">Email me a sign-in link</button></form></details><?php endif;?></div></dialog><dialog id="friendLeagueDialog"><div class="profilemodal leaguesmodal"><button type="button" class="closex" onclick="closeFriendLeague()" aria-label="Close">×</button><div class="sectionlabel" id="friendLeaguePeriod">FRIENDS LEAGUE</div><h2 id="friendLeagueName">League table</h2><p class="explain" id="friendLeagueCode"></p><div class="botcontrols" id="friendBotControls" hidden><div><b>Add a bot opponent</b><small>League creators can add one bot at a time.</small></div><select id="friendBotGrade" aria-label="Bot difficulty"><option value="1">SIM1 · Expert</option><option value="2">SIM2 · Strong</option><option value="3" selected>SIM3 · Standard</option><option value="4">SIM4 · Basic</option><option value="5">SIM5 · Random</option></select><button type="button" class="btn" id="addBotButton" onclick="addBotToLeague()">Add bot</button><div class="botmessage" id="friendBotMessage" role="status"></div></div><nav class="periodtabs friendperiodtabs" id="friendLeaguePeriods" aria-label="Choose friends league period"><?php foreach($leaderPeriods as $periodKey=>$period):?><button type="button" data-period="<?=htmlspecialchars($periodKey)?>" onclick="loadFriendLeaguePeriod('<?=htmlspecialchars($periodKey)?>')"><?=htmlspecialchars($period[0])?></button><?php endforeach;?></nav><div class="friendtable" id="friendLeagueMembers"><div class="emptyhistory">Loading league…</div></div></div></dialog><dialog id="leaguesDialog"><div class="profilemodal leaguesmodal"><button type="button" class="closex" onclick="closeLeagues()" aria-label="Close">×</button><div class="sectionlabel">PLAY WITH FRIENDS</div><h2>Friends Leagues</h2><p class="explain">Create a private league and share its invite code, or join a league created by somebody else.</p><div class="myleagues"><h3>Your leagues</h3><?php if(!$myLeagues):?><div class="emptyleagues">You haven't joined a friends league yet.</div><?php endif;?><?php foreach($myLeagues as $l):?><div class="leagueitem"><div><b><?=htmlspecialchars($l['name'])?></b><small><?=(int)$l['member_count']?> <?=(int)$l['member_count']===1?'member':'members'?></small><div class="joincode"><span>Join code <strong><?=htmlspecialchars($l['code'])?></strong></span><button type="button" class="copybtn copyjoincode" data-code="<?=htmlspecialchars($l['code'],ENT_QUOTES)?>" onclick="copyCode(this.dataset.code,this)" aria-live="polite">Copy code</button></div></div><button type="button" class="leagueviewbtn" onclick="openFriendLeague(<?=(int)$l['id']?>)">View league</button><button type="button" class="copybtn shareinvitebtn" onclick="shareLeague('<?=htmlspecialchars($l['name'],ENT_QUOTES)?>','<?=$l['code']?>')">Share invite</button></div><?php endforeach;?></div><div class="leagueactions modalactions"><div><b>Create a league</b><small>Start a competition for your group.</small><input id="lname" placeholder="e.g. Friday Night Football"><button class="btn" type="button" onclick="createLeague()">Create league</button></div><div><b>Join a league</b><small>Enter the invite code you were sent.</small><input id="lcode" placeholder="Enter invite code"><button class="outline" type="button" onclick="joinLeague()">Join league</button></div></div></div></dialog><dialog id="profileDialog"><form class="profilemodal" onsubmit="saveProfile(event)"><button type="button" class="closex" onclick="closeProfile()" aria-label="Close">×</button><div class="sectionlabel">YOUR PROFILE</div><h2>Account &amp; profile</h2><?php if(!empty($user['email'])):?><div class="accountemail"><?=htmlspecialchars((string)$user['email'])?></div><?php if(!empty($user['google_sub'])):?><div class="linkedprovider">✓ Google account linked</div><?php endif;?><p class="explain">Update how you appear on PredictionComp.</p><?php else:?><p class="explain">Choose how you appear on PredictionComp and tell us which club you support.</p><?php endif;?><label>Display name<input id="profileName" name="nickname" autocomplete="nickname" minlength="2" maxlength="24" value="<?=htmlspecialchars($user['display_name'])?>" required></label><label>Favourite Premier League club<small class="clubfieldnote">Shown with your profile and remembered across devices.</small><div class="clubselect"><img id="selectedClubLogo" alt=""><select id="supportedClub"><option value="">None</option><?php foreach(['Arsenal','Aston Villa','AFC Bournemouth','Brentford','Brighton & Hove Albion','Burnley','Chelsea','Crystal Palace','Everton','Fulham','Leeds United','Liverpool','Manchester City','Manchester United','Newcastle United','Nottingham Forest','Sunderland','Tottenham Hotspur','West Ham United','Wolverhampton Wanderers'] as $club):?><option value="<?=htmlspecialchars($club)?>" <?=($user['supported_club']??'')===$club?'selected':''?>><?=htmlspecialchars($club)?></option><?php endforeach;?></select></div></label><button class="btn profilesave" type="submit">Save changes</button><?php if(!empty($user['email'])):?><a class="outline signoutlink" href="?auth=logout">Sign out on this device</a><?php endif;?></form></dialog></main><footer class="footer"><nav class="footerlinks" aria-label="Site information"><a href="/about">About</a><a href="/how-it-works">How it works</a><a href="/privacy">Privacy Policy</a><a href="/contact">Contact</a></nav><span>PredictionComp.com · Independent prediction game · Not affiliated with or endorsed by the Premier League or any club</span></footer><div class="infomodal" id="infoModal" hidden aria-hidden="true"><article class="infodialog" role="dialog" aria-modal="true" aria-labelledby="infoTitle"><button class="infoclose" id="infoClose" type="button" aria-label="Close">×</button><div id="infoContent"></div></article></div><script src="https://accounts.google.com/gsi/client" defer></script><script>const infoModal=document.getElementById('infoModal'),infoContent=document.getElementById('infoContent'),infoClose=document.getElementById('infoClose');let infoLastFocus=null,infoScrollY=0;async function openInfoLink(a){infoLastFocus=a;infoScrollY=window.scrollY;document.body.classList.add('infoopen');infoModal.hidden=false;infoModal.setAttribute('aria-hidden','false');infoContent.innerHTML='<p>Loading…</p>';try{let r=await fetch(a.href,{cache:'no-store',headers:{'X-PredictionComp-Overlay':'1'}});if(!r.ok)throw Error();infoContent.innerHTML=await r.text();history.pushState({info:true},'',a.getAttribute('href'));infoClose.focus()}catch(e){location.href=a.href}}function closeInfoLink(fromPop=false){infoModal.hidden=true;infoModal.setAttribute('aria-hidden','true');document.body.classList.remove('infoopen');window.scrollTo(0,infoScrollY);if(!fromPop&&location.pathname!=='/')history.back();if(infoLastFocus)infoLastFocus.focus()}document.querySelectorAll('.footerlinks a').forEach(a=>a.addEventListener('click',e=>{e.preventDefault();openInfoLink(a)}));infoClose.addEventListener('click',()=>closeInfoLink());infoModal.addEventListener('click',e=>{if(e.target===infoModal)closeInfoLink()});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!infoModal.hidden)closeInfoLink()});window.addEventListener('popstate',()=>{if(!infoModal.hidden)closeInfoLink(true)});if(location.pathname!=='/'&&['/about','/how-it-works','/privacy','/contact'].includes(location.pathname)){let a=document.querySelector('.footerlinks a[href="'+location.pathname+'"]');if(a)openInfoLink(a)};async function handleGoogleCredential(response){let m=document.getElementById('authMessage');if(m)m.textContent='Signing you in with Google…';try{await api('google_login',{credential:response.credential});location.href='/?signed_in=1'}catch(e){if(m)m.textContent=e.message}}function initGoogleButton(){let el=document.getElementById('googleButton');if(!el||el.dataset.rendered)return;if(!(window.google&&google.accounts&&google.accounts.id)){setTimeout(initGoogleButton,200);return}google.accounts.id.initialize({client_id:el.dataset.clientId,callback:handleGoogleCredential,use_fedcm_for_button:false});let buttonWidth=Math.max(240,Math.min(400,Math.floor(el.getBoundingClientRect().width)));google.accounts.id.renderButton(el,{type:'standard',theme:'outline',size:'large',text:'continue_with',shape:'rectangular',logo_alignment:'left',width:buttonWidth});el.dataset.rendered='1'}document.addEventListener('DOMContentLoaded',initGoogleButton);async function api(a,d){let r=await fetch('?api='+a,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)}),t=await r.text(),j={};try{j=t?JSON.parse(t):{}}catch(e){}if(!r.ok)throw Error(j.error||'The sign-in service could not complete the request');return j}async function save(id,h,a){try{await api('save',{fixture_id:id,home:h,away:a})}catch(e){alert(e.message);location.reload()}}function openAuth(){<?php if($uid>0):?>openProfile()<?php else:?>document.getElementById('authDialog').showModal()<?php endif;?>}async function registerName(e){e.preventDefault();let b=e.submitter,m=document.getElementById('authMessage');b.disabled=true;m.textContent='Creating your player profile…';try{await api('register_name',{name:document.getElementById('registerName').value,csrf:<?=json_encode($_SESSION['registration_csrf'])?>});location.href='/#predict'}catch(x){m.textContent=x.message}finally{b.disabled=false}}function closeAuth(){document.getElementById('authDialog').close()}function showGoogleSetup(e){e.preventDefault();let m=document.getElementById('authMessage');if(m)m.textContent='Google sign-in is awaiting its secure Google credentials.'}async function sendMagicLink(e){e.preventDefault();let b=e.submitter,m=document.getElementById('authMessage');b.disabled=true;m.textContent='Sending your secure link…';try{await api('email_login',{email:document.getElementById('loginEmail').value});m.textContent='Email sent. Open the link on this device to finish signing in.'}catch(x){m.textContent=x.message}finally{b.disabled=false}}function esc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}let activeBotProfile=null;async function openPlayerResults(userId){let d=document.getElementById('playerResultsDialog'),list=document.getElementById('playerResultsList'),avatar=document.getElementById('playerResultsAvatar'),profileLink=document.getElementById('botProfileLink');activeBotProfile=null;avatar.hidden=true;profileLink.hidden=true;document.getElementById('playerResultsName').textContent='Player predictions';document.getElementById('playerResultsPeriod').textContent='PLAYER RESULTS';list.innerHTML='<div class="emptyhistory">Loading predictions…</div>';d.showModal();try{let j=await api('player_results',{user_id:userId,period:<?=json_encode($leaderPeriod)?>});document.getElementById('playerResultsName').textContent=j.name;document.getElementById('playerResultsPeriod').textContent=j.period_title.toUpperCase()+' RESULTS';if(j.bot){activeBotProfile={...j.bot,name:j.name};avatar.src=j.bot.avatar;avatar.alt=j.name+' illustrated bot avatar';avatar.hidden=false;profileLink.textContent='Meet '+j.name;profileLink.hidden=false}if(!j.results.length){list.innerHTML='<div class="emptyhistory">No completed predictions for this period.</div>';return}list.innerHTML=j.results.map(h=>{let finished=h.actual_home!==null&&h.actual_away!==null,rawDate=String(h.kickoff_utc).replace(' ','T'),date=new Date(/[zZ]$|[+-]\d{2}:?\d{2}$/.test(rawDate)?rawDate:rawDate+'Z').toLocaleString('en-GB',{weekday:'short',day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit',timeZone:'Europe/London'});return '<article class="historyrow"><time>'+esc(date)+'</time><div class="historymatch"><strong>'+esc(h.home)+'</strong><span>v</span><strong>'+esc(h.away)+'</strong></div><div class="historyscores"><span>Prediction <b>'+esc(h.predicted_home)+'–'+esc(h.predicted_away)+'</b></span><span>Result <b>'+(finished?esc(h.actual_home)+'–'+esc(h.actual_away):'Pending')+'</b></span><em class="'+(finished&&Number(h.points)>0?'won':'')+'">'+(finished?'+'+esc(h.points)+' pts':'Awaiting result')+'</em></div></article>'}).join('')}catch(e){list.innerHTML='<div class="emptyhistory playerresults-error">'+esc(e.message)+'</div>'}}function closePlayerResults(){document.getElementById('playerResultsDialog').close()}function openBotProfile(){if(!activeBotProfile)return;document.getElementById('botProfileAvatar').src=activeBotProfile.avatar;document.getElementById('botProfileAvatar').alt=activeBotProfile.name+' illustrated bot avatar';document.getElementById('botProfileName').textContent=activeBotProfile.name;document.getElementById('botProfileTitle').textContent=activeBotProfile.title||('SIM'+activeBotProfile.grade+' BOT');document.getElementById('botProfileBio').textContent=activeBotProfile.bio;document.getElementById('botProfileGrade').textContent='SIM'+activeBotProfile.grade+(activeBotProfile.grade===1?' · Elite data-driven predictor':activeBotProfile.grade===5?' · Completely random predictor':' · Level '+activeBotProfile.grade+' predictor');document.getElementById('botProfileDialog').showModal()}function closeBotProfile(){document.getElementById('botProfileDialog').close()}function openHistory(){document.getElementById('historyDialog').showModal()}function closeHistory(){document.getElementById('historyDialog').close()}function openProfile(){document.getElementById('profileDialog').showModal();updateClubLogo()}function closeProfile(){document.getElementById('profileDialog').close()}function updateClubLogo(){let c=document.getElementById('supportedClub').value,i=document.getElementById('selectedClubLogo');i.src=c?'/club-logo.php?team='+encodeURIComponent(c):'';i.style.visibility=c?'visible':'hidden'}async function saveProfile(e){e.preventDefault();await api('profile',{name:document.getElementById('profileName').value,club:document.getElementById('supportedClub').value});location.reload()}let activeFriendLeagueId=null,activeFriendPeriod=<?=json_encode($leaderPeriod)?>;async function openFriendLeague(leagueId){closeLeagues();activeFriendLeagueId=leagueId;let d=document.getElementById('friendLeagueDialog');document.getElementById('friendLeagueName').textContent='League table';document.getElementById('friendLeaguePeriod').textContent='FRIENDS LEAGUE';document.getElementById('friendLeagueCode').textContent='';document.getElementById('friendBotControls').hidden=true;document.getElementById('friendBotMessage').textContent='';d.showModal();await loadFriendLeaguePeriod(activeFriendPeriod)}async function loadFriendLeaguePeriod(period){if(!activeFriendLeagueId)return;activeFriendPeriod=period;let box=document.getElementById('friendLeagueMembers');box.innerHTML='<div class="emptyhistory">Loading league…</div>';document.querySelectorAll('#friendLeaguePeriods button').forEach(b=>b.classList.toggle('active',b.dataset.period===period));try{let j=await api('league_table',{league_id:activeFriendLeagueId,period});document.getElementById('friendLeagueName').textContent=j.league.name;document.getElementById('friendLeaguePeriod').textContent=j.period_title.toUpperCase();document.getElementById('friendLeagueCode').textContent=j.members.length+' '+(j.members.length===1?'member':'members')+' · Invite code '+j.league.code;document.getElementById('friendBotControls').hidden=!j.league.can_manage;box.innerHTML=j.members.map((m,i)=>'<div class="friendrow"><span class="rank">'+(i+1)+'</span><span class="membername">'+esc(m.display_name)+(Number(m.is_bot)?'<small class="simbadge">SIM'+Math.max(1,Math.min(5,Number(m.bot_grade)||5))+'</small>':'')+(Number(m.is_owner)?'<small class="owner">LEAGUE CREATOR</small>':'')+'</span><span class="friendactions"><span class="points">'+esc(m.pts)+' pts</span>'+(j.league.can_manage&&!Number(m.is_owner)?'<button type="button" class="removeplayerbtn" data-user-id="'+Number(m.id)+'" data-player-name="'+esc(m.display_name)+'" onclick="confirmRemoveLeagueMember(this)">Remove</button>':'')+'</span></div>').join('')}catch(e){box.innerHTML='<div class="emptyhistory playerresults-error">'+esc(e.message)+'</div>'}}async function addBotToLeague(){if(!activeFriendLeagueId)return;let button=document.getElementById('addBotButton'),message=document.getElementById('friendBotMessage'),grade=Number(document.getElementById('friendBotGrade').value);button.disabled=true;message.textContent='Adding a SIM'+grade+' opponent…';try{let j=await api('add_league_bot',{league_id:activeFriendLeagueId,grade});message.textContent=j.bot.display_name+' (SIM'+j.bot.bot_grade+') has joined the league.';await loadFriendLeaguePeriod(activeFriendPeriod)}catch(e){message.textContent=e.message}finally{button.disabled=false}}async function confirmRemoveLeagueMember(button){if(!activeFriendLeagueId)return;let userId=Number(button.dataset.userId),name=button.dataset.playerName||'this player';if(!window.confirm('Remove '+name+' from this league?\n\nTheir account and predictions will not be deleted.'))return;button.disabled=true;try{let j=await api('remove_league_member',{league_id:activeFriendLeagueId,user_id:userId});document.getElementById('friendBotMessage').textContent=j.display_name+' has been removed from the league.';await loadFriendLeaguePeriod(activeFriendPeriod)}catch(e){alert(e.message);button.disabled=false}}function closeFriendLeague(){document.getElementById('friendLeagueDialog').close();openLeagues()}function openLeagues(){document.getElementById('leaguesDialog').showModal()}function closeLeagues(){document.getElementById('leaguesDialog').close()}async function copyCode(c,button){try{await navigator.clipboard.writeText(c);if(button){button.textContent='✓ Copied';clearTimeout(button.copyReset);button.copyReset=setTimeout(()=>{button.textContent='Copy code'},2500)}else{alert('Join code copied')}}catch(e){prompt('Copy this join code:',c)}}async function shareLeague(name,code){let url=location.origin+'/?invite='+encodeURIComponent(code),data={title:'Join '+name+' on PredictionComp',text:'Join my '+name+' friends league on PredictionComp.',url};if(navigator.share){try{await navigator.share(data);return}catch(e){if(e&&e.name==='AbortError')return}}try{await navigator.clipboard.writeText(url);alert('League invite link copied')}catch(e){prompt('Copy this league invite link:',url)}}async function createLeague(){try{let j=await api('league',{name:document.getElementById('lname').value});alert('League created. Invite code: '+j.code);location.reload()}catch(e){alert(e.message)}}async function joinLeague(){try{await api('join',{code:document.getElementById('lcode').value});location.reload()}catch(e){alert(e.message)}}document.getElementById('supportedClub').addEventListener('change',updateClubLogo);document.getElementById('playerResultsDialog').addEventListener('click',e=>{if(e.target.id==='playerResultsDialog')closePlayerResults()});document.getElementById('botProfileDialog').addEventListener('click',e=>{if(e.target.id==='botProfileDialog')closeBotProfile()});document.getElementById('historyDialog').addEventListener('click',e=>{if(e.target.id==='historyDialog')closeHistory()});document.getElementById('profileDialog').addEventListener('click',e=>{if(e.target.id==='profileDialog')closeProfile()});document.getElementById('friendLeagueDialog').addEventListener('click',e=>{if(e.target.id==='friendLeagueDialog')closeFriendLeague()});document.getElementById('leaguesDialog').addEventListener('click',e=>{if(e.target.id==='leaguesDialog')closeLeagues()});document.getElementById('authDialog').addEventListener('click',e=>{if(e.target.id==='authDialog')closeAuth()});<?php if($pendingInviteName&&empty($user['email'])):?>openAuth();<?php endif;?>if(new URLSearchParams(location.search).has('auth_error')){openAuth();let m=document.getElementById('authMessage');if(m)m.textContent='That sign-in attempt could not be completed. Please try again.'}</script></body></html>