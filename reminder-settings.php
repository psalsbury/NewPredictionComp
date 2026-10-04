<?php
declare(strict_types=1);
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$db=new PDO('sqlite:/var/lib/predictioncomp/predictioncomp.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$token=(string)($_POST['token']??$_GET['token']??'');$uid=0;$done=false;
if(preg_match('/^[A-Za-z0-9_-]{40,100}$/',$token)){$q=$db->prepare('SELECT user_id FROM prediction_reminder_log WHERE unsubscribe_hash=?');$q->execute([hash('sha256',$token)]);$uid=(int)$q->fetchColumn();}
if($uid&&$_SERVER['REQUEST_METHOD']==='POST'){$q=$db->prepare('UPDATE users SET prediction_reminders=0 WHERE id=?');$q->execute([$uid]);$done=true;}
if(!$uid)http_response_code(400);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Prediction reminder settings | PredictionComp</title><style>body{background:#08151d;color:#edf5fa;font:16px system-ui;margin:0;padding:30px 18px}main{max-width:480px;margin:10vh auto;background:#102530;border:1px solid #34505e;border-radius:14px;padding:24px}p{color:#b6cbd6;line-height:1.6}button,a{color:#a9edc3}button{background:#214831;border:1px solid #50775f;border-radius:8px;padding:12px;font:700 14px system-ui;cursor:pointer}</style></head><body><main><h1>Prediction reminders</h1><?php if($done):?><p>Your prediction reminders are now switched off.</p><?php elseif($uid):?><p>Turn off optional prediction reminder emails?</p><form method="post" action="/reminder-settings.php"><input type="hidden" name="token" value="<?=htmlspecialchars($token,ENT_QUOTES)?>"><button type="submit">Turn off reminders</button></form><?php else:?><p>This reminder link is unavailable. You can change your preferences in Account.</p><?php endif;?><p><a href="/?view=account">Go to Account</a></p></main></body></html>
