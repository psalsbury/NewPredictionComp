<?php
declare(strict_types=1);
require_once __DIR__.'/inc/config.php';
require_once __DIR__.'/inc/recap.php';
require_once __DIR__.'/player-nav.php';
date_default_timezone_set('Europe/London');
$db=new PDO('sqlite:file:'.pc_db_path().'?mode=ro',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function re($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function rf(array $r):string{return '<a href="/fixtures/'.(int)$r['id'].'">'.re($r['home'].' '.$r['actual_home'].'–'.$r['actual_away'].' '.$r['away']).'</a>';}
$period=($_GET['period']??'week')==='month'?'month':'week';
$now=new DateTimeImmutable('now',new DateTimeZone('Europe/London'));$choices=[];
if($period==='month'){
 $month=(string)($_GET['month']??$now->format('Y-m'));
 if(!preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D',$month)||$month>$now->format('Y-m')){http_response_code(404);exit('Recap not found');}
 $start=new DateTimeImmutable($month.'-01',new DateTimeZone('Europe/London'));$end=$start->modify('+1 month');
 $q=$db->prepare("SELECT id,status,home_score,away_score,kickoff_utc FROM fixtures WHERE julianday(kickoff_utc)>=julianday(?) AND julianday(kickoff_utc)<julianday(?)");
 $q->execute([$start->format('c'),$end->format('c')]);$fixtures=$q->fetchAll(PDO::FETCH_ASSOC);
 $label=$start->format('F Y');$progress=$month===$now->format('Y-m');
 $previous='/recap?period=month&amp;month='.$start->modify('-1 month')->format('Y-m');
 $next=$start->modify('+1 month')->format('Y-m')<=$now->format('Y-m')?'/recap?period=month&amp;month='.$start->modify('+1 month')->format('Y-m'):null;
}else{
 $choices=$db->query("SELECT g.season,g.gameweek,MIN(f.kickoff_utc) first_match FROM fixture_gameweeks g JOIN fixtures f ON f.id=g.fixture_id WHERE EXISTS(SELECT 1 FROM fixture_gameweeks x JOIN fixtures y ON y.id=x.fixture_id WHERE x.season=g.season AND x.gameweek=g.gameweek AND y.status IN ('FT','AET','PEN') AND y.home_score IS NOT NULL AND y.away_score IS NOT NULL AND julianday(y.kickoff_utc)<=julianday('now')) GROUP BY g.season,g.gameweek ORDER BY g.season DESC,g.gameweek DESC")->fetchAll(PDO::FETCH_ASSOC);
 $selected=null;
 foreach($choices as $i=>$c)if(!isset($_GET['week'])||((string)$c['gameweek']===(string)$_GET['week']&&(string)$c['season']===(string)($_GET['season']??$c['season']))){$selected=$c;$index=$i;break;}
 if(!$selected){$fixtures=[];$label='Weekly recap';$progress=false;$previous=$next=null;
  if(isset($_GET['week'])){http_response_code(404);exit('Recap not found');}
 }else{
  $q=$db->prepare('SELECT f.id,f.status,f.home_score,f.away_score,f.kickoff_utc FROM fixtures f JOIN fixture_gameweeks g ON g.fixture_id=f.id WHERE g.season=? AND g.gameweek=?');
  $q->execute([$selected['season'],$selected['gameweek']]);$fixtures=$q->fetchAll(PDO::FETCH_ASSOC);
  $label='Gameweek '.$selected['gameweek'].' · '.$selected['season'].'/'.substr((string)($selected['season']+1),2);$progress=false;
  $url=fn($c)=>'/recap?period=week&amp;season='.(int)$c['season'].'&amp;week='.(int)$c['gameweek'];
  $previous=isset($choices[$index+1])?$url($choices[$index+1]):null;$next=$index>0?$url($choices[$index-1]):null;
 }
}
$done=[];$goals=0;
foreach($fixtures as $f){
 if(in_array($f['status'],['FT','AET','PEN'],true)&&$f['home_score']!==null&&$f['away_score']!==null&&strtotime($f['kickoff_utc'])<=time()){$done[]=(int)$f['id'];$goals+=(int)$f['home_score']+(int)$f['away_score'];}
 elseif(!in_array($f['status'],['PST','CANC','ABD'],true))$progress=true;
}
$s=recapData($db,$done);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=re($label)?> recap | PredictionComp</title><meta name="robots" content="noindex,follow"><link rel="icon" href="/favicon.svg"><link rel="stylesheet" href="/player-nav.css?v=6"><link rel="stylesheet" href="/assets/theme.css?v=20261019"><style>
*{box-sizing:border-box}body{margin:0}main,body>header,footer{max-width:1050px;margin:auto;padding:24px}h1{font-size:clamp(30px,5vw,46px);line-height:1.15}.recapgrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.recapcard{min-width:0;border:1px solid var(--line,#304655);border-radius:14px;background:var(--ink-1,#102330);padding:22px}.recapcard h2{font-size:23px;margin:0 0 14px}.recapcard p{line-height:1.65;overflow-wrap:anywhere}.recapstats{display:flex;flex-wrap:wrap;gap:14px;margin:24px 0}.recapstats div{flex:1;min-width:120px;padding:16px;border:1px solid var(--line,#304655);border-radius:10px}.recapstats b{display:block;font-size:28px}.recapstats span,.recapnote{font-size:14px;color:var(--text-2,#b4c7d2);line-height:1.65}.recapnav{display:flex;flex-wrap:wrap;gap:18px;margin:20px 0}.recapnav a{padding:10px 0}li{line-height:1.7;margin-bottom:10px}@media(max-width:650px){main,body>header,footer{padding:16px}.recapgrid{grid-template-columns:1fr}.recapcard{padding:18px}}
</style></head><body><header class="siteheader"><?php siteHeaderNavigation();?></header><main><p><a href="/my-results">← My results</a></p><nav class="recapnav" aria-label="Recap period"><a href="/recap?period=week">Weekly recap</a><a href="/recap?period=month">Current month</a></nav><h1><?=re($label)?> recap</h1><p class="recapnote"><?=$progress?'So far — highlights update as matches finish.':'Finished matches — the highlights are in.'?> Weekly recaps follow official Premier League gameweeks; monthly recaps use UK kick-off dates.</p><nav class="recapnav" aria-label="Browse recaps"><?php if($previous):?><a href="<?=$previous?>">← Previous <?=$period==='week'?'gameweek':'month'?></a><?php endif;if($next):?><a href="<?=$next?>">Next <?=$period==='week'?'gameweek':'month'?> →</a><?php endif;?></nav>
<div class="recapstats"><?php foreach([[count($done),'finished matches'],[count($s['players']),'players this period'],[$s['exact'],'exact score calls'],[$goals,'goals scored']] as [$value,$caption]):?><div><b><?=$value?></b><span><?=re($caption)?></span></div><?php endforeach;?></div>
<?php if(!$s['picks']):?><section class="recapcard"><h2>The story is still to come</h2><p>No eligible real-player predictions on finished matches in this period yet. Check back after the next results.</p></section><?php else:?>
<div class="recapgrid">
<section class="recapcard"><h2>Best prediction by odds</h2><p class="recapnote">The highest stored pre-match odds on a correct winner or draw. Joint winners are included.</p><?php if(!$s['best']):?><p>No winning calls with valid pre-match odds yet.</p><?php else:?><ul><?php foreach($s['best'] as $r):?><li><strong><?=re($r['display_name'])?></strong> · <?=number_format($r['price'],2)?> odds<br><?=rf($r)?> · predicted <?=re($r['home_score'].'–'.$r['away_score'])?></li><?php endforeach;?></ul><?php endif;?></section>
<section class="recapcard"><h2>Most impressive exact score</h2><p class="recapnote">The rarest exact score: the lowest share of players predicting that match who got it exactly right. All ties are shown.</p><?php if(!$s['rare']):?><p>No exact scores yet.</p><?php else:foreach($s['rare'] as $group):$r=$group['entries'][0];?><p><?=rf($r)?><br><strong><?=re(implode(', ',array_column($group['entries'],'display_name')))?></strong><br>Only <?=count($group['entries'])?> of <?=$group['total']?> players nailed the score.</p><?php endforeach;endif;?></section>
<section class="recapcard"><h2>Points leaders</h2><?php foreach($s['top'] as $p):?><p><strong><?=re($p['name'])?> · <?=$p['points']?> points</strong><br><?=$p['picks']?> predictions · <?=$p['exact']?> exact · <?=$p['correct']?> correct results</p><?php endforeach;?><p class="recapnote">5 points for an exact score, 3 for the right winner or draw, otherwise 0. Correct results include exact scores.</p></section>
<section class="recapcard"><h2>Sharpest scoring rate</h2><?php if(!$s['sharp']):?><p>Needs at least three finished predictions per player.</p><?php else:foreach($s['sharp'] as $p):?><p><strong><?=re($p['name'])?> · <?=number_format($p['points']/$p['picks'],2)?> points per match</strong><br><?=$p['points']?> points from <?=$p['picks']?> predictions</p><?php endforeach;endif;?><p class="recapnote">Minimum three predictions. Helps compare players who entered different numbers of matches.</p></section>
<section class="recapcard"><h2>The toughest result to call</h2><p class="recapnote">Counts below include only players who predicted each match. The total at the top counts everyone who played during this period.</p><?php if(!$s['hard']):?><p>Needs at least three real-player predictions on a match.</p><?php else:foreach($s['hard'] as $m):?><p><?=rf($m['fixture'])?><br><?php if($m['correct']===0):?><strong><?=$m['picks']?> players</strong> predicted this match; none got the result right.<?php else:?>Only <strong><?=$m['correct']?> of <?=$m['picks']?></strong> players who predicted this match got the result right.<?php endif;?></p><?php endforeach;endif;?></section>
<section class="recapcard"><h2>How everyone did</h2><p><strong><?=$s['correct']?> of <?=$s['picks']?> predictions</strong> got the winner or draw right (<?=round(100*$s['correct']/$s['picks'])?>%).</p><p><?=$s['exact']?> exact scores earned five points. <?=$s['correct']-$s['exact']?> other correct results earned three.</p><p class="recapnote">A shared snapshot of real-player performance, excluding all SIM bots.</p></section></div><?php endif;?>
<p class="recapnote">Highlights include real players eligible for the global table. New guests remain unnamed and excluded until eligible. Finished matches only; predictions for upcoming matches are never revealed. Odds awards use stored result odds observed and fetched before kick-off, not exact-score prices. Odds coverage can be incomplete. “Most impressive” is based on rarity, not betting odds.</p><p><a href="/#predict">Make your next predictions →</a></p></main><footer><a href="/my-results">My results</a> · <a href="/">PredictionComp</a></footer><?php playerNavigation();?></body></html>
