<?php
function fixtureStatusLabel(array $f):string {
 $status=strtoupper($f['status']??'NS');
 $labels=['PST'=>'Postponed','CANC'=>'Cancelled','ABD'=>'Abandoned','SUSP'=>'Suspended','TBD'=>'Time to be confirmed','1H'=>'In progress','2H'=>'In progress','HT'=>'Half-time','ET'=>'Extra time','BT'=>'Break','P'=>'Penalties','INT'=>'Interrupted','LIVE'=>'In progress','AWD'=>'Awarded','WO'=>'Walkover'];
 if(isset($labels[$status]))return $labels[$status];
 if(in_array($status,['FT','AET','PEN'],true)&&$f['home_score']!==null&&$f['away_score']!==null)return 'Full-time';
 $kick=new DateTimeImmutable($f['kickoff_utc'],new DateTimeZone('UTC'));
 return $kick->getTimestamp()<=time()?'Result pending':'Scheduled';
}
function dataFreshnessHtml(PDO $db):string {
 $meta=$db->query("SELECT key,value FROM sync_meta")->fetchAll(PDO::FETCH_KEY_PAIR);
 $checks=array_filter(array_map(fn($k)=>$meta[$k]??null,['season_refresh_success','published_refresh_success','upcoming_sync']));
 usort($checks,fn($a,$b)=>strtotime($b)<=>strtotime($a));$fixture=$checks[0]??null;
 $result=$meta['results_checked']??null;
 $format=function($raw){if(!$raw)return 'Not yet recorded';try{$d=new DateTimeImmutable($raw,new DateTimeZone('UTC'));return $d->setTimezone(new DateTimeZone('Europe/London'))->format('j M, H:i').' UK';}catch(Exception $e){return 'Not yet recorded';}};
 return '<details class="datafreshness"><summary>Data updates</summary><div><p>Fixtures checked: '.htmlspecialchars($format($fixture)).'</p><p>Results checked: '.htmlspecialchars($format($result)).'</p><p>Times can change. A pending result means a final score has not yet been confirmed.</p></div></details>';
}
