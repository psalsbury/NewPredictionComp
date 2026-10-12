<?php
declare(strict_types=1);
// JSON API: /?api=<action>. Every branch echoes JSON and exits.
if(isset($_GET['api'])){
 header('Content-Type: application/json');$in=json_decode(file_get_contents('php://input'),true)?:[];
 pc_password_api($db,$user,$uid,(string)$_GET['api'],$in);
 pc_guest_api($db,$uid,(string)$t,(string)$_GET['api'],$in);
 if($_GET['api']==='onboarding_done'){
  if($uid<1||$_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals((string)$_SESSION['registration_csrf'],(string)($in['csrf']??''))){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Please refresh and try again.']);exit;}
  $db->prepare('UPDATE users SET onboarding_seen=1 WHERE id=?')->execute([$uid]);echo json_encode(['ok'=>true]);exit;
 }

 if($_GET['api']==='register_name'){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Register with email and password or Google.']);exit;}
 if($_GET['api']==='google_login'){
  $cfg=pc_google_config();$credential=(string)($in['credential']??'');
  if(!$cfg||$credential===''){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Google sign-in is unavailable']);exit;}
  $json=@file_get_contents('https://oauth2.googleapis.com/tokeninfo?id_token='.rawurlencode($credential));$info=is_string($json)?json_decode($json,true):null;
  if(!is_array($info)||($info['aud']??'')!==$cfg['client_id']||!in_array($info['iss']??'', ['accounts.google.com','https://accounts.google.com'],true)||($info['email_verified']??'')!=='true'||empty($info['sub'])||empty($info['email'])){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Google could not verify this sign-in']);exit;}
  $token=pc_merge_account($db,$uid,(string)$info['email'],(string)$info['sub'],(string)($info['name']??''),(string)($info['picture']??''));pc_cookie($token);$joinedLeague=pc_join_pending($db,$token);
  echo json_encode(['ok'=>true,'joined_league'=>$joinedLeague]);exit;
 }
 if($_GET['api']==='email_login'){
  if($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals((string)$_SESSION['registration_csrf'],(string)($in['csrf']??''))){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Please refresh the page and try again.']);exit;}
  $email=strtolower(trim((string)($in['email']??'')));
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Enter a valid email address']);exit;}
  $known=$db->prepare('SELECT id FROM users WHERE LOWER(email)=? AND COALESCE(is_bot,0)=0');$known->execute([$email]);if(!$known->fetchColumn()){echo json_encode(['ok'=>true]);exit;}
  $recent=$db->prepare("SELECT COUNT(*) FROM auth_magic_links WHERE email=? AND created_at>datetime('now','-60 seconds')");$recent->execute([$email]);
  if((int)$recent->fetchColumn()>0){echo json_encode(['ok'=>true]);exit;}
  $raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);
  $db->prepare("DELETE FROM auth_magic_links WHERE expires_at<datetime('now','-1 day') OR used_at IS NOT NULL")->execute();
  $db->prepare("INSERT INTO auth_magic_links(email,token_hash,originating_user_id,expires_at) VALUES(?,?,?,datetime('now','+15 minutes'))")->execute([$email,$hash,$uid]);
  $url='https://predictioncomp.com/?login_token='.rawurlencode($raw);
  $subject='Your PredictionComp sign-in link';
  $body="Sign in to PredictionComp\n\nOpen this secure link within 15 minutes:\n".$url."\n\nIf you did not request this, you can ignore this email.";
  $headers=["From: PredictionComp <admin@predictioncomp.com>","Reply-To: admin@predictioncomp.com","Content-Type: text/plain; charset=UTF-8"];
  if(!mail($email,$subject,$body,implode("\r\n",$headers))){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'We could not send the email. Please try again.']);exit;}
  echo json_encode(['ok'=>true]);exit;
 }
 if($_GET['api']==='player_results'){
  $playerId=(int)($in['user_id']??0);$period=(string)($in['period']??'season');
  $now=new DateTimeImmutable('now',new DateTimeZone('Europe/London'));
  $year=pcSeasonYear();
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
  $results=$db->prepare("SELECT f.round,f.kickoff_utc,f.home,f.away,f.home_score actual_home,f.away_score actual_away,p.home_score predicted_home,p.away_score predicted_away,COALESCE(p.points,0) points
   FROM predictions p JOIN fixtures f ON f.id=p.fixture_id
   WHERE p.user_id=:user_id AND julianday(f.kickoff_utc)>=julianday(:period_start) AND julianday(f.kickoff_utc)<julianday(:period_end) AND julianday(f.kickoff_utc)<=julianday('now') AND COALESCE(f.status,'') NOT IN ('SIM','PST','CANC','POSTPONED','CANCELLED')
   ORDER BY f.kickoff_utc DESC LIMIT 100");
  $results->execute([':user_id'=>$playerId,':period_start'=>$start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),':period_end'=>$end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
  echo json_encode(['ok'=>true,'name'=>$name,'period_title'=>$title,'results'=>$results->fetchAll(PDO::FETCH_ASSOC),'bot'=>$botInfo]);exit;
 }
 if($_GET['api']==='save'){
  if($uid<1){http_response_code(401);echo json_encode(['ok'=>false,'need_name'=>true,'error'=>'Choose a display name to save your predictions']);exit;}
  $id=(int)($in['fixture_id']??0);$h=max(0,min(15,(int)($in['home']??0)));$a=max(0,min(15,(int)($in['away']??0)));
  $f=$db->prepare("SELECT kickoff_utc FROM fixtures WHERE id=?");$f->execute([$id]);$kick=$f->fetchColumn();
  if(!$kick||strtotime($kick)<=time()){http_response_code(409);echo json_encode(['ok'=>false,'error'=>'Prediction locked']);exit;}
  $s=$db->prepare("INSERT INTO predictions(user_id,fixture_id,home_score,away_score) VALUES(?,?,?,?) ON CONFLICT(user_id,fixture_id) DO UPDATE SET home_score=excluded.home_score,away_score=excluded.away_score,saved_at=CURRENT_TIMESTAMP");$s->execute([$uid,$id,$h,$a]);
  // New players are reported in the daily owner summary (bin/daily_summary.py), not one email each.
  $db->prepare('UPDATE users SET first_played_at=CURRENT_TIMESTAMP WHERE id=? AND first_played_at IS NULL')->execute([$uid]);
  echo json_encode(['ok'=>true]);exit;
 }
 if($_GET['api']==='profile'){if($uid<1){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Sign in to update your account']);exit;}$reminders=array_key_exists('reminders',$in)?(!empty($in['reminders'])?1:0):(int)($user['prediction_reminders']??0);$lead=(int)($in['reminder_lead_hours']??$user['reminder_lead_hours']??24);if(!in_array($lead,[2,24],true)){$lead=24;}if($reminders&&(empty($user['email'])||(empty($user['email_verified_at'])&&empty($user['google_sub']))||!empty($user['is_bot']))){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Sign in with Google or a verified email to enable reminders']);exit;}$n=trim((string)($in['name']??''));$club=trim((string)($in['club']??''));$clubs=$premierLeagueClubs;if(strlen($n)<2||strlen($n)>24||($club!==''&&!in_array($club,$clubs,true))){http_response_code(400);echo json_encode(['ok'=>false]);exit;}if($n!==(string)$user['display_name']&&($nameError=pc_display_name_error($db,$n,$uid))){http_response_code($nameError[0]);echo json_encode(['ok'=>false,'error'=>$nameError[1]]);exit;}$s=$db->prepare('UPDATE users SET display_name=?,supported_club=?,prediction_reminders=?,reminder_lead_hours=? WHERE id=?');$s->execute([$n,$club,$reminders,$lead,$uid]);echo json_encode(['ok'=>true]);exit;}
 if($_GET['api']==='league_table'){
  $leagueId=(int)($in['league_id']??0);$period=(string)($in['period']??'season');
  $league=$db->prepare('SELECT l.id,l.name,l.code,l.owner_user_id FROM leagues l JOIN league_members mine ON mine.league_id=l.id AND mine.user_id=? WHERE l.id=?');$league->execute([$uid,$leagueId]);$leagueInfo=$league->fetch(PDO::FETCH_ASSOC);
  if(!$leagueInfo){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'You are not a member of this league']);exit;}
  $year=pcSeasonYear();
  $start=new DateTimeImmutable($year.'-08-01 00:00:00',new DateTimeZone('Europe/London'));$end=$start->modify('+10 months');$title='Season '.substr((string)$year,2).'/'.substr((string)($year+1),2);
  if($period!=='season'){
   if(!preg_match('/^\d{4}-\d{2}$/',$period)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Invalid period']);exit;}
   $month=DateTimeImmutable::createFromFormat('!Y-m',$period,new DateTimeZone('Europe/London'));
   if(!$month||$month<$start||$month>=$end){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Invalid period']);exit;}
   $start=$month;$end=$month->modify('+1 month');$title=$month->format('F Y');
  }
  $progress=friendLeagueProgress($db,$leagueId,$uid,$start,$end);
  echo json_encode(['ok'=>true,'league'=>['id'=>(int)$leagueInfo['id'],'name'=>$leagueInfo['name'],'code'=>$leagueInfo['code'],'can_manage'=>(int)$leagueInfo['owner_user_id']===$uid],'period_title'=>$title,'members'=>$progress['members'],'progress'=>$progress]);exit;
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
 if($_GET['api']==='league'){
  if($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals((string)$_SESSION['registration_csrf'],(string)($in['csrf']??'')))pc_auth_error(403,'Please refresh and try again.');
  $n=trim((string)($in['name']??''));// Count characters, not bytes (no mbstring here); invalid UTF-8 fails the match too.
  if(!preg_match('/^.{2,80}$/us',$n))pc_auth_error(400,'Enter a league name of 2–80 characters.');
  if($uid<1){http_response_code(401);echo json_encode(['ok'=>false,'need_name'=>true,'league_name'=>$n,'error'=>'Choose your player name to create your league.']);exit;}
  $db->beginTransaction();
  try{$c=code();$db->prepare('INSERT INTO leagues(code,name,owner_user_id) VALUES(?,?,?)')->execute([$c,$n,$uid]);$lid=(int)$db->lastInsertId();$db->prepare('INSERT INTO league_members(league_id,user_id) VALUES(?,?)')->execute([$lid,$uid]);$db->commit();}
  catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  echo json_encode(['ok'=>true,'code'=>$c,'league_id'=>$lid]);exit;
 }
 if($_GET['api']==='join'){
  if($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals((string)$_SESSION['registration_csrf'],(string)($in['csrf']??'')))pc_auth_error(403,'Please refresh and try again.');
  $c=strtoupper(trim((string)($in['code']??'')));$s=$db->prepare('SELECT id,name FROM leagues WHERE code=?');$s->execute([$c]);$league=$s->fetch(PDO::FETCH_ASSOC);
  if(!$league)pc_auth_error(404,'That invite code was not found. Please check it and try again.');
  if($uid<1){$_SESSION['pending_league_code']=$c;http_response_code(401);echo json_encode(['ok'=>false,'need_name'=>true,'league_name'=>$league['name'],'error'=>'Choose your player name to join.']);exit;}
  $db->prepare('INSERT OR IGNORE INTO league_members(league_id,user_id) VALUES(?,?)')->execute([$league['id'],$uid]);
  unset($_SESSION['pending_league_code']);echo json_encode(['ok'=>true,'league_id'=>(int)$league['id'],'league_name'=>$league['name']]);exit;
 }
 exit;
}
