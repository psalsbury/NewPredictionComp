<?php
declare(strict_types=1);
// Prediction reminder opt-in. Reminders need a confirmed email: Google accounts and email-link sign-ins are confirmed already;
// password and guest accounts confirm by clicking a link sent here (/?verify_email=TOKEN), which also switches reminders on.
// Confirmation links live in auth_magic_links, hashed as sha256('verify:'.token), so they never work as sign-in links (and vice versa).

/** 'bot', 'guest' (signed out), 'no_email', 'unverified' or 'ready'. */
function pc_reminder_state(array $user): string {
 if(!empty($user['is_bot']))return 'bot';
 if((int)($user['id']??0)<1)return 'guest';
 if(empty($user['email']))return 'no_email';
 return !empty($user['email_verified_at'])||!empty($user['google_sub'])?'ready':'unverified';
}
function pc_verify_hash(string $token): string { return hash('sha256','verify:'.$token); }

/** Email a confirmation link that verifies the address and turns reminders on. At most one email a minute per address. */
function pc_send_reminder_verification(PDO $db,array $user): bool {
 $email=strtolower(trim((string)$user['email']));
 if(!filter_var($email,FILTER_VALIDATE_EMAIL))return false;
 $recent=$db->prepare("SELECT COUNT(*) FROM auth_magic_links WHERE email=? AND created_at>datetime('now','-60 seconds')");$recent->execute([$email]);
 if((int)$recent->fetchColumn()>0)return true;
 $raw=bin2hex(random_bytes(32));
 $db->prepare("DELETE FROM auth_magic_links WHERE expires_at<datetime('now','-1 day') OR used_at IS NOT NULL")->execute();
 $db->prepare("INSERT INTO auth_magic_links(email,token_hash,originating_user_id,expires_at) VALUES(?,?,?,datetime('now','+24 hours'))")->execute([$email,pc_verify_hash($raw),(int)$user['id']]);
 $url='https://predictioncomp.com/?verify_email='.rawurlencode($raw);
 $body="Hi ".$user['display_name'].",\n\nConfirm your email to switch on PredictionComp prediction reminders:\n".$url."\n\nThe link works for 24 hours. We'll then email you before picks lock, at most once per matchweek and only when you have predictions missing. You can switch reminders off at any time from your account or from any reminder email.\n\nIf you didn't ask for this, ignore this email and nothing will change.";
 $headers=["From: PredictionComp <admin@predictioncomp.com>","Reply-To: admin@predictioncomp.com","Content-Type: text/plain; charset=UTF-8"];
 return mail($email,'Confirm your email for PredictionComp reminders',$body,implode("\r\n",$headers),'-f admin@predictioncomp.com');
}

/** Handle /?verify_email=TOKEN: confirm the address, switch reminders on, then redirect. */
function pc_verify_email_route(PDO $db): void {
 $raw=(string)($_GET['verify_email']??'');
 $s=$db->prepare("SELECT * FROM auth_magic_links WHERE token_hash=? AND used_at IS NULL AND expires_at>datetime('now')");$s->execute([pc_verify_hash($raw)]);$link=$s->fetch(PDO::FETCH_ASSOC);
 if(!$link){header('Location: /?reminders_error=expired#predict');exit;}
 $db->prepare('UPDATE auth_magic_links SET used_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$link['id']]);
 // Only the account that asked, and only while it still has that address.
 $u=$db->prepare('UPDATE users SET email_verified_at=COALESCE(email_verified_at,CURRENT_TIMESTAMP),prediction_reminders=1 WHERE id=? AND LOWER(email)=? AND COALESCE(is_bot,0)=0');
 $u->execute([(int)$link['originating_user_id'],strtolower((string)$link['email'])]);
 header('Location: '.($u->rowCount()?'/?reminders_on=1#predict':'/?reminders_error=expired#predict'));exit;
}

/**
 * Turn reminders on or off for the current player. Returns the JSON reply:
 * on=true -> 'on' (ready), 'verify_sent' (confirmation emailed) or an error with need_email.
 */
function pc_set_reminders(PDO $db,array $user,bool $on,int $lead): array {
 if(!in_array($lead,[2,24],true))$lead=24;
 $uid=(int)($user['id']??0);
 if(!$on){$db->prepare('UPDATE users SET prediction_reminders=0 WHERE id=?')->execute([$uid]);return ['ok'=>true,'status'=>'off'];}
 $state=pc_reminder_state($user);
 if($state==='ready'){$db->prepare('UPDATE users SET prediction_reminders=1,reminder_lead_hours=? WHERE id=?')->execute([$lead,$uid]);return ['ok'=>true,'status'=>'on','lead'=>$lead];}
 if($state==='unverified'){
  $db->prepare('UPDATE users SET reminder_lead_hours=? WHERE id=?')->execute([$lead,$uid]);
  if(!pc_send_reminder_verification($db,$user))return ['ok'=>false,'error'=>'We could not send the confirmation email. Please try again.'];
  return ['ok'=>true,'status'=>'verify_sent','email'=>(string)$user['email']];
 }
 return ['ok'=>false,'need_email'=>true,'error'=>'Add an email to your account to get reminders.'];
}
