<?php
declare(strict_types=1);
// The PredictionComp iPhone app (Pete, 2026-11-20), plus deleting an account (the App Store requires it in apps
// with accounts; the website offers it too).
// * apple_login: Sign in with Apple. The app sends the identity token from Apple's sheet; it's a JWT signed with one
//   of Apple's published keys (RS256), checked here with openssl, for this app's bundle ID.
// * Google sign-in in the app uses the existing google_login API: Google's iOS SDK issues the ID token for the
//   website's own client, so it is checked exactly like the website's.
// * app_reminders: the matchweeks this player still has picks missing in, so the app can schedule local reminder
//   notifications before they lock (no push server involved).
// * delete_account: removes the account and everything linked to it.
const PC_APPLE_BUNDLES=['uk.salsbury.predictioncomp'];

function pc_b64u(string $s):string{return (string)base64_decode(strtr($s,'-_','+/').str_repeat('=',(4-strlen($s)%4)%4),true);}
// Apple's signing keys, kept for a day; $fresh re-fetches them (Apple rotates keys now and then).
function pc_apple_keys(bool $fresh=false):array{
 $file='/var/lib/predictioncomp/apple-keys.json';
 if(!$fresh&&is_file($file)&&filemtime($file)>time()-86400){$k=json_decode((string)file_get_contents($file),true);if(!empty($k['keys']))return $k['keys'];}
 $raw=@file_get_contents('https://appleid.apple.com/auth/keys',false,stream_context_create(['http'=>['timeout'=>8,'ignore_errors'=>true]]));
 $k=$raw?json_decode($raw,true):null;if(empty($k['keys']))return [];
 @file_put_contents($file,$raw);return $k['keys'];
}
// A PEM public key from an RSA JWK (SubjectPublicKeyInfo, built by hand in DER).
function pc_rsa_jwk_pem(string $n,string $e):string{
 $len=fn(int $l)=>$l<128?chr($l):(($b=ltrim(pack('N',$l),"\0"))!==''?chr(0x80|strlen($b)).$b:'');
 $int=function(string $x)use($len){if(ord($x[0])>127)$x="\0".$x;return "\x02".$len(strlen($x)).$x;};
 $seq=fn(string $x)=>"\x30".$len(strlen($x)).$x;
 $rsa=$seq($int(pc_b64u($n)).$int(pc_b64u($e)));
 $der=$seq($seq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00")."\x03".$len(strlen($rsa)+1)."\0".$rsa);
 return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PUBLIC KEY-----\n";
}
/** The claims of a valid Apple identity token for this app (['sub','email']), or null. */
function pc_apple_claims(string $jwt):?array{
 $parts=explode('.',$jwt);if(count($parts)!==3)return null;[$h,$p,$sig]=$parts;
 $head=json_decode(pc_b64u($h),true);if(($head['alg']??'')!=='RS256'||empty($head['kid']))return null;
 $keys=pc_apple_keys();if(!in_array($head['kid'],array_column($keys,'kid'),true))$keys=pc_apple_keys(true);
 foreach($keys as $k){
  if(($k['kid']??'')!==$head['kid']||($k['kty']??'')!=='RSA')continue;
  if(openssl_verify("$h.$p",pc_b64u($sig),pc_rsa_jwk_pem($k['n'],$k['e']),OPENSSL_ALGO_SHA256)!==1)return null;
  $c=json_decode(pc_b64u($p),true);$aud=$c['aud']??'';
  $ok=($c['iss']??'')==='https://appleid.apple.com'&&(is_array($aud)?(bool)array_intersect($aud,PC_APPLE_BUNDLES):in_array($aud,PC_APPLE_BUNDLES,true))
   &&(int)($c['exp']??0)>time()&&!empty($c['sub']);
  return $ok?['sub'=>(string)$c['sub'],'email'=>strtolower(trim((string)($c['email']??'')))]:null;
 }
 return null;
}

/** Deletes a player and everything linked to them. Leagues they own pass to the longest-standing other member, or go. */
function pc_delete_account(PDO $db,int $uid):void{
 $db->beginTransaction();
 try{
  $u=$db->prepare('SELECT email FROM users WHERE id=? AND COALESCE(is_bot,0)=0');$u->execute([$uid]);$email=$u->fetchColumn();
  if($email===false){$db->rollBack();return;}
  $own=$db->prepare('SELECT id FROM leagues WHERE owner_user_id=?');$own->execute([$uid]);
  foreach($own->fetchAll(PDO::FETCH_COLUMN) as $lid){
   $next=$db->prepare('SELECT m.user_id FROM league_members m JOIN users u ON u.id=m.user_id WHERE m.league_id=? AND m.user_id<>? AND COALESCE(u.is_bot,0)=0 ORDER BY m.joined_at,m.user_id LIMIT 1');
   $next->execute([$lid,$uid]);$heir=$next->fetchColumn();
   if($heir!==false)$db->prepare('UPDATE leagues SET owner_user_id=? WHERE id=?')->execute([(int)$heir,$lid]);
   else{$db->prepare('DELETE FROM league_members WHERE league_id=?')->execute([$lid]);$db->prepare('DELETE FROM leagues WHERE id=?')->execute([$lid]);}
  }
  foreach(['predictions','league_members','user_sessions','player_awards','prediction_reminder_log'] as $table)
   $db->prepare("DELETE FROM $table WHERE user_id=?")->execute([$uid]);
  $db->prepare('DELETE FROM auth_magic_links WHERE originating_user_id=?'.($email?' OR LOWER(email)=?':''))->execute($email?[$uid,strtolower((string)$email)]:[$uid]);
  $db->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function pc_app_api(PDO $db,array $user,int $uid,string $api,array $in):void{
 if($api==='apple_login'){
  if(!pc_csrf_ok($in))pc_auth_error(403,'Please refresh the page and try again.');
  $c=pc_apple_claims((string)($in['id_token']??''));
  if(!$c){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Apple could not confirm that sign-in. Please try again.']);exit;}
  $s=$db->prepare('SELECT email FROM users WHERE apple_sub=?');$s->execute([$c['sub']]);$known=(string)($s->fetchColumn()?:'');
  $email=$c['email']!==''?$c['email']:$known;
  if($email===''){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Apple did not share an email address. Please try again and choose to share or hide your email.']);exit;}
  $name=trim(preg_replace('/\s+/',' ',(string)($in['name']??'')));
  $token=pc_merge_account($db,$uid,$email,null,$name!==''?$name:null,null,true,$c['sub']);pc_cookie($token);$joinedLeague=pc_join_pending($db,$token);
  echo json_encode(['ok'=>true,'joined_league'=>$joinedLeague]);exit;
 }
 if($api==='delete_account'){
  if(!pc_csrf_ok($in))pc_auth_error(403,'Please refresh the page and try again.');
  if($uid<1){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'You are not signed in.']);exit;}
  pc_delete_account($db,$uid);pc_end_session($db,0);
  echo json_encode(['ok'=>true]);exit;
 }
 if($api==='app_reminders'){
  if($uid<1){echo json_encode(['ok'=>true,'weeks'=>[]]);exit;}
  $q=$db->prepare("SELECT f.id,f.home,f.away,f.kickoff_utc,g.season,g.gameweek FROM fixtures f JOIN fixture_gameweeks g ON g.fixture_id=f.id
   WHERE julianday(f.kickoff_utc)>julianday('now') AND julianday(f.kickoff_utc)<julianday('now','+21 days') AND COALESCE(f.status,'NS') NOT IN ('PST','CANC','ABD')
   AND NOT EXISTS(SELECT 1 FROM predictions p WHERE p.user_id=? AND p.fixture_id=f.id) ORDER BY f.kickoff_utc");
  $q->execute([$uid]);$weeks=[];
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $f){
   $k=$f['season'].'-'.$f['gameweek'];
   if(!isset($weeks[$k]))$weeks[$k]=['gameweek'=>(int)$f['gameweek'],'first_kickoff'=>gmdate('c',(int)strtotime($f['kickoff_utc'])),'first'=>$f['home'].' v '.$f['away'],'missing'=>0];
   $weeks[$k]['missing']++;
  }
  echo json_encode(['ok'=>true,'weeks'=>array_values($weeks)]);exit;
 }
}
