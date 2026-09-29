<?php
declare(strict_types=1);
date_default_timezone_set('Europe/London');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow', true);
function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$db=new PDO('sqlite:/var/lib/predictioncomp/predictioncomp.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA busy_timeout=3000');
$tz=new DateTimeZone('Europe/London');$utc=new DateTimeZone('UTC');$now=new DateTimeImmutable('now',$tz);
$periods=[
 'day'=>['Today',$now->setTime(0,0)],
 'week'=>['This week',$now->modify('monday this week')->setTime(0,0)],
 'month'=>['This month',$now->modify('first day of this month')->setTime(0,0)]
];
function scalar(PDO $db,string $sql,array $params=[]): int {$s=$db->prepare($sql);$s->execute($params);return (int)$s->fetchColumn();}
$cards=[];
foreach($periods as $key=>[$label,$start]){
 $from=$start->setTimezone($utc)->format('Y-m-d H:i:s');
 $cards[$key]=[
  'label'=>$label,'from'=>$start->format('j M Y'),
  'players'=>scalar($db,"SELECT COUNT(*) FROM users WHERE COALESCE(is_bot,0)=0 AND first_played_at>=?",[$from]),
  'predictions'=>scalar($db,"SELECT COUNT(*) FROM predictions p JOIN users u ON u.id=p.user_id WHERE COALESCE(u.is_bot,0)=0 AND p.saved_at>=?",[$from])
 ];
}
$totals=[
 'players'=>scalar($db,"SELECT COUNT(*) FROM users WHERE COALESCE(is_bot,0)=0 AND first_played_at IS NOT NULL"),
 'signed_in'=>scalar($db,"SELECT COUNT(*) FROM users WHERE COALESCE(is_bot,0)=0 AND first_played_at IS NOT NULL AND email IS NOT NULL"),
 'guests'=>scalar($db,"SELECT COUNT(*) FROM users WHERE COALESCE(is_bot,0)=0 AND first_played_at IS NOT NULL AND email IS NULL"),
 'bots'=>scalar($db,"SELECT COUNT(*) FROM users WHERE COALESCE(is_bot,0)=1"),
 'predictions'=>scalar($db,"SELECT COUNT(*) FROM predictions p JOIN users u ON u.id=p.user_id WHERE COALESCE(u.is_bot,0)=0"),
 'leagues'=>scalar($db,"SELECT COUNT(*) FROM leagues")
];
$recent=$db->query("SELECT display_name,email,supported_club,first_played_at,play_alert_sent_at FROM users WHERE COALESCE(is_bot,0)=0 AND first_played_at IS NOT NULL ORDER BY first_played_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>PredictionComp Admin</title>
<style>
:root{color-scheme:dark;--bg:#08151d;--panel:#102530;--line:#29424f;--muted:#9ab0bc;--green:#35df7b;--cyan:#58d7ff}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:#f3f8fa;font:16px system-ui,-apple-system,sans-serif}main{max-width:1180px;margin:auto;padding:24px 16px 44px}a{color:var(--cyan);text-decoration:none}.eyebrow{margin-top:22px;color:var(--green);font-size:.76rem;font-weight:900;letter-spacing:.14em}h1{font-size:clamp(2rem,6vw,3rem);margin:.35rem 0 .3rem}p{color:var(--muted);line-height:1.5}.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:22px 0}.card,.total{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px}.card small,.total small{display:block;color:var(--muted)}.card strong,.total strong{display:block;font-size:2rem;margin:5px 0}.card span{color:var(--green);font-size:.88rem}.totals{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:24px}.total strong{font-size:1.55rem}.tablewrap{overflow:auto;border:1px solid var(--line);border-radius:14px;background:var(--panel)}table{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums}th,td{padding:11px 12px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap}thead{background:#15303d}td:nth-child(4),td:nth-child(5){color:var(--muted)}.ok{color:var(--green)}.pending{color:#ffc75f}
@media(max-width:800px){main{padding:16px 10px 32px}.cards{grid-template-columns:1fr}.card{display:grid;grid-template-columns:1fr auto}.card strong{grid-row:1/3;grid-column:2}.totals{grid-template-columns:repeat(2,1fr)}.tablewrap{font-size:.82rem}}
</style></head><body><main>
<a href="/">← PredictionComp</a><div class="eyebrow">PASSWORD-PROTECTED OWNER VIEW</div><h1>Site usage</h1><p>Real-player activity in UK time. SIM players are excluded from all activity figures.</p>
<div class="cards"><?php foreach($cards as $c):?><section class="card"><small><?=h($c['label'])?> · from <?=h($c['from'])?></small><strong><?=number_format($c['players'])?></strong><span>new players · <?=number_format($c['predictions'])?> predictions</span></section><?php endforeach;?></div>
<div class="totals">
<section class="total"><small>All real players</small><strong><?=number_format($totals['players'])?></strong></section>
<section class="total"><small>Signed in</small><strong><?=number_format($totals['signed_in'])?></strong></section>
<section class="total"><small>Guest players</small><strong><?=number_format($totals['guests'])?></strong></section>
<section class="total"><small>Predictions</small><strong><?=number_format($totals['predictions'])?></strong></section>
<section class="total"><small>Friends leagues</small><strong><?=number_format($totals['leagues'])?></strong></section>
<section class="total"><small>SIM players</small><strong><?=number_format($totals['bots'])?></strong></section>
</div>
<h2>Recent first-time players</h2><div class="tablewrap"><table><thead><tr><th>Display name</th><th>Account</th><th>Club</th><th>First played</th><th>Email alert</th></tr></thead><tbody>
<?php if(!$recent):?><tr><td colspan="5">No real players have saved a prediction yet.</td></tr><?php endif;?>
<?php foreach($recent as $r):?><tr><td><?=h($r['display_name'])?></td><td><?=h($r['email']?:'Guest')?></td><td><?=h($r['supported_club']?:'—')?></td><td><?=h((new DateTimeImmutable($r['first_played_at'],new DateTimeZone('UTC')))->setTimezone($tz)->format('d M Y H:i'))?></td><td class="<?=empty($r['play_alert_sent_at'])?'pending':'ok'?>"><?=empty($r['play_alert_sent_at'])?'Pending retry':'Sent'?></td></tr><?php endforeach;?>
</tbody></table></div><p>Alerts are sent to pete@salsbury.co.uk when a real player saves their first prediction. Failed sends remain pending and are retried on that player’s next save.</p>
</main></body></html>