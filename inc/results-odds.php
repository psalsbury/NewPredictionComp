<?php
declare(strict_types=1);

function personalOddsResults(PDO $db,int $uid,int $year,?array $period=null):array {
 // Two hypothetical £1 bets per finished prediction: the result it implies (1X2 prices, fixture_odds)
 // and the exact score (correct-score prices, fixture_score_odds, stored from November 2026).
 // Only prices observed and fetched before kick-off count. Money is in pence.
 $s=['settled'=>0,'wins'=>0,'excluded'=>0,'stake'=>0,'returns'=>0,'net'=>0,'best'=>null,
  'score'=>['settled'=>0,'wins'=>0,'excluded'=>0,'stake'=>0,'returns'=>0,'net'=>0,'best'=>null],'matches'=>[]];
 $has=fn($t)=>(bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='$t'")->fetchColumn();
 $hasOdds=$has('fixture_odds');$hasScore=$has('fixture_score_odds');
 $join=$hasOdds?'LEFT JOIN fixture_odds o ON o.fixture_id=f.id':'';
 $prices=$hasOdds?'o.home price_home,o.draw price_draw,o.away price_away,o.observed_at,o.fetched_at':'NULL price_home,NULL price_draw,NULL price_away,NULL observed_at,NULL fetched_at';
 if($hasScore){$join.=' LEFT JOIN fixture_score_odds cs ON cs.fixture_id=f.id AND cs.home_goals=p.home_score AND cs.away_goals=p.away_score';$prices.=',cs.price score_price,cs.observed_at score_observed,cs.fetched_at score_fetched,(SELECT COUNT(*) FROM fixture_score_odds x WHERE x.fixture_id=f.id) score_market,(SELECT MAX(observed_at) FROM fixture_score_odds x WHERE x.fixture_id=f.id) market_observed,(SELECT MAX(fetched_at) FROM fixture_score_odds x WHERE x.fixture_id=f.id) market_fetched';}
 else $prices.=',NULL score_price,NULL score_observed,NULL score_fetched,0 score_market,NULL market_observed,NULL market_fetched';
 $scope='';$params=[$uid,$period['start']??$year.'-07-01T00:00:00Z',$period['end']??($year+1).'-07-01T00:00:00Z'];
 if(isset($period['week'])){$scope=' AND EXISTS(SELECT 1 FROM fixture_gameweeks g WHERE g.fixture_id=f.id AND g.season=? AND g.gameweek=?)';$params=[$uid,$period['season'].'-07-01T00:00:00Z',($period['season']+1).'-07-01T00:00:00Z',$period['season'],$period['week']];}
 $q=$db->prepare("SELECT f.id,f.home,f.away,f.kickoff_utc,f.home_score actual_home,f.away_score actual_away,p.home_score,p.away_score,$prices FROM predictions p JOIN fixtures f ON f.id=p.fixture_id $join WHERE p.user_id=? AND f.status IN ('FT','AET','PEN') AND f.home_score IS NOT NULL AND f.away_score IS NOT NULL AND julianday(f.kickoff_utc)>=julianday(?) AND julianday(f.kickoff_utc)<julianday(?) AND julianday(f.kickoff_utc)<=julianday('now') $scope ORDER BY julianday(f.kickoff_utc),f.id");
 $q->execute($params);
 $before=function($kick,$obs,$fet){$o=$obs?strtotime((string)$obs):false;$f=$fet?strtotime((string)$fet):false;return $kick!==false&&$o!==false&&$f!==false&&$o<$kick&&$f<$kick;};
 foreach($q as $r){
  $side=(int)$r['home_score']<=>(int)$r['away_score'];$actual=(int)$r['actual_home']<=>(int)$r['actual_away'];
  $kick=strtotime($r['kickoff_utc']);$row=$r+['result_net'=>null,'score_net'=>null,'result_price'=>null];
  $price=(float)$r[['-1'=>'price_away','0'=>'price_draw','1'=>'price_home'][(string)$side]];
  if(!is_finite($price)||$price<=1||!$before($kick,$r['observed_at'],$r['fetched_at']))$s['excluded']++;
  else{
   $s['settled']++;$s['stake']+=100;$row['result_price']=$price;$row['result_net']=-100;
   if($side===$actual){
    $s['wins']++;$ret=(int)round($price*100);$s['returns']+=$ret;$row['result_net']=$ret-100;
    if($s['best']===null||$price>$s['best']['price'])$s['best']=array_merge($r,['price'=>$price,'selection'=>$side===0?'Draw':($side===1?$r['home'].' win':$r['away'].' win')]);
   }
  }
  // Exact-score bet: needs a pre-kick-off correct-score market for the match. A pick the market didn't list
  // (e.g. 9–3) is treated as a losing £1 at no price rather than skipped, since the market covered the match.
  $sc=&$s['score'];$sp=$r['score_price']!==null?(float)$r['score_price']:null;
  if((int)$r['score_market']>0&&$before($kick,$r['market_observed'],$r['market_fetched'])){
   $sc['settled']++;$sc['stake']+=100;$row['score_net']=-100;$row['score_price']=$sp;
   if((int)$r['home_score']===(int)$r['actual_home']&&(int)$r['away_score']===(int)$r['actual_away']&&$sp!==null&&$sp>1){
    $sc['wins']++;$ret=(int)round($sp*100);$sc['returns']+=$ret;$row['score_net']=$ret-100;
    if($sc['best']===null||$sp>$sc['best']['price'])$sc['best']=array_merge($r,['price'=>$sp]);
   }
  }else{$sc['excluded']++;$row['score_price']=null;}
  unset($sc);
  $s['matches'][]=$row;
 }
 $s['net']=$s['returns']-$s['stake'];$s['score']['net']=$s['score']['returns']-$s['score']['stake'];return $s;
}
function renderPersonalOddsResults(array $s,string $label='This season'):string {
 $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
 $money=fn($p)=>'£'.number_format($p/100,2);
 $signed=fn($p)=>($p>0?'+':($p<0?'−':'')).'£'.number_format(abs($p)/100,2);
 $cls=fn($p)=>$p>0?'betup':($p<0?'betdown':'betflat');
 $date=fn($k)=>(new DateTimeImmutable($k))->setTimezone(new DateTimeZone('Europe/London'))->format('j M');
 $sc=$s['score']??['settled'=>0,'wins'=>0,'excluded'=>0,'stake'=>0,'returns'=>0,'net'=>0,'best'=>null];
 $html='<section class="performancepanel resultsodds" aria-labelledby="resultsOddsTitle"><h2 id="resultsOddsTitle">Your predictions at £1 a bet</h2><p class="performancenote">'.$e($label).' · What you would have won or lost with two pretend £1 bets on each prediction: one on the <strong>result</strong> it implies (home win, draw or away win) and one on the <strong>exact score</strong>. Prices are bookmaker decimal odds stored before kick-off. Exact-score prices are stored from November 2026 onwards.</p>';
 if(!$s['settled']&&!$sc['settled']){
  return $html.'<p>Your figures will appear after a predicted match finishes with usable pre-match odds.</p>'.($s['excluded']?'<p class="performancenote">'.$s['excluded'].' finished prediction'.($s['excluded']===1?' has':'s have').' no stored pre-match odds. No real money is placed.</p>':'').'</section>';
 }
 // Summary: result bets, exact-score bets, both together
 $tot=['settled'=>$s['settled']+$sc['settled'],'wins'=>$s['wins']+$sc['wins'],'stake'=>$s['stake']+$sc['stake'],'returns'=>$s['returns']+$sc['returns']];$tot['net']=$tot['returns']-$tot['stake'];
 $html.='<div class="betsummary">';
 foreach([['£1 on the result',$s],['£1 on the exact score',$sc],['Both bets',$tot]] as [$title,$t]){
  $html.='<div class="betcard'.($title==='Both bets'?' bettotal':'').'"><h3>'.$e($title).'</h3>';
  if(!$t['settled'])$html.='<p class="betnone">No priced bets in this period yet.</p>';
  else $html.='<strong class="betnet '.$cls($t['net']).'">'.$signed($t['net']).'</strong><dl><div><dt>Bets</dt><dd>'.$t['settled'].'</dd></div><div><dt>Winners</dt><dd>'.$t['wins'].'</dd></div><div><dt>Staked</dt><dd>'.$money($t['stake']).'</dd></div><div><dt>Returned</dt><dd>'.$money($t['returns']).'</dd></div></dl>';
  $html.='</div>';
 }
 $html.='</div>';
 // Best wins
 $html.='<div class="betbest">';
 $b=$s['best'];
 $html.='<div><h3>Best result win</h3>'.($b?'<p><a href="/fixtures/'.(int)$b['id'].'"><strong>'.$e($b['home'].' v '.$b['away']).'</strong></a> · '.$e($date($b['kickoff_utc'])).'<br>'.$e($b['selection']).' at <strong>'.number_format($b['price'],2).'</strong> · £1 returned '.$money((int)round($b['price']*100)).'<br><small>Your score '.$e($b['home_score'].'–'.$b['away_score']).' · Result '.$e($b['actual_home'].'–'.$b['actual_away']).'</small></p>':'<p>No winning result bet yet.</p>').'</div>';
 $b=$sc['best'];
 $html.='<div><h3>Best exact-score win</h3>'.($b?'<p><a href="/fixtures/'.(int)$b['id'].'"><strong>'.$e($b['home'].' v '.$b['away']).'</strong></a> · '.$e($date($b['kickoff_utc'])).'<br>'.$e($b['home_score'].'–'.$b['away_score']).' at <strong>'.number_format($b['price'],2).'</strong> · £1 returned '.$money((int)round($b['price']*100)).'</p>':'<p>'.($sc['settled']?'No exact score landed yet. Each one pays far more than a result bet.':'No exact-score bets yet. Prices are stored from November 2026.').'</p>').'</div>';
 $html.='</div>';
 // Match by match
 $rows=array_reverse(array_filter($s['matches'],fn($m)=>$m['result_net']!==null||$m['score_net']!==null));
 if($rows){
  $cell=function($net,$price,$noPrice)use($signed,$cls){if($net===null)return '<td class="betflat"><small>'.$noPrice.'</small></td>';return '<td class="'.$cls($net).'">'.$signed($net).($price?'<small>@ '.number_format($price,2).'</small>':'<small>not priced</small>').'</td>';};
  $html.='<details class="betmatches"'.(count($rows)<=12?' open':'').'><summary>Match by match ('.count($rows).')</summary><div class="bettablewrap"><table class="bettable"><thead><tr><th class="l">Match</th><th>Pick · Result</th><th>Result bet</th><th>Score bet</th><th>Total</th></tr></thead><tbody>';
  foreach($rows as $m){
   $t=(int)($m['result_net']??0)+(int)($m['score_net']??0);
   $exact=(int)$m['home_score']===(int)$m['actual_home']&&(int)$m['away_score']===(int)$m['actual_away'];
   $html.='<tr'.($exact?' class="betexact"':'').'><td class="l"><a href="/fixtures/'.(int)$m['id'].'">'.$e($m['home'].' v '.$m['away']).'</a><small>'.$e($date($m['kickoff_utc'])).'<span class="betpickm"> · '.$e($m['home_score'].'–'.$m['away_score']).' v '.$e($m['actual_home'].'–'.$m['actual_away']).($exact?' ✓':'').'</span></small></td><td>'.$e($m['home_score'].'–'.$m['away_score']).' · '.$e($m['actual_home'].'–'.$m['actual_away']).($exact?'<small>exact ✓</small>':'').'</td>'.$cell($m['result_net'],$m['result_price'],'no odds').$cell($m['score_net'],$m['score_price'],'no prices').'<td class="'.$cls($t).'"><b>'.$signed($t).'</b></td></tr>';
  }
  $html.='</tbody></table></div></details>';
 }
 $ex=[];if($s['excluded'])$ex[]=$s['excluded'].' without result odds';if($sc['excluded'])$ex[]=$sc['excluded'].' without exact-score prices';
 $html.='<p class="performancenote">Returns include the £1 stake, so a winning bet at 9.00 returns £9.00 (+£8.00) and a losing bet is −£1.00. An exact-score pick the bookmaker didn\'t price counts as a losing £1. Finished matches only'.($ex?'; of your finished predictions, '.implode(' and ',$ex).' are left out':'').'. Stored prices may differ from those available when you saved your pick. No real money is placed.</p></section>';
 return $html;
}

function personalOddsPeriods(PDO $db,int $uid,int $year,string $requested=''):array {
 $tz=new DateTimeZone('Europe/London');$now=new DateTimeImmutable('now',$tz);
 $options=['season:'.$year=>['type'=>'Season','label'=>$year.'/'.substr((string)($year+1),2).' season','start'=>$year.'-07-01T00:00:00Z','end'=>($year+1).'-07-01T00:00:00Z']];
 $current=$now->format('Y-m');$start=$now->modify('first day of this month')->setTime(0,0);
 $options['month:'.$current]=['type'=>'Month','label'=>$start->format('F Y'),'start'=>$start->format('c'),'end'=>$start->modify('+1 month')->format('c')];
 $mapped=(bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='fixture_gameweeks'")->fetchColumn();
 $join=$mapped?'LEFT JOIN fixture_gameweeks g ON g.fixture_id=f.id':'';
 $cols=$mapped?'g.season,g.gameweek':'NULL season,NULL gameweek';
 $q=$db->prepare("SELECT f.id,f.kickoff_utc,$cols FROM predictions p JOIN fixtures f ON f.id=p.fixture_id $join WHERE p.user_id=? AND f.status IN ('FT','AET','PEN') AND f.home_score IS NOT NULL AND f.away_score IS NOT NULL AND julianday(f.kickoff_utc)<=julianday('now') ORDER BY julianday(f.kickoff_utc) DESC");
 $q->execute([$uid]);
 foreach($q as $r){
  $date=(new DateTimeImmutable($r['kickoff_utc']))->setTimezone($tz);$y=(int)$date->format('Y')-((int)$date->format('n')<7?1:0);
  $options['season:'.$y]=['type'=>'Season','label'=>$y.'/'.substr((string)($y+1),2).' season','start'=>$y.'-07-01T00:00:00Z','end'=>($y+1).'-07-01T00:00:00Z'];
  $m=$date->format('Y-m');$first=$date->modify('first day of this month')->setTime(0,0);
  $options['month:'.$m]=['type'=>'Month','label'=>$first->format('F Y'),'start'=>$first->format('c'),'end'=>$first->modify('+1 month')->format('c')];
  if($r['gameweek']!==null){$key='week:'.$r['season'].':'.$r['gameweek'];$options[$key]=['type'=>'Week','label'=>'Gameweek '.$r['gameweek'].' · '.$r['season'].'/'.substr((string)((int)$r['season']+1),2),'season'=>(int)$r['season'],'week'=>(int)$r['gameweek']];}
 }
 $key=isset($options[$requested])?$requested:'season:'.$year;
 return ['key'=>$key,'selected'=>$options[$key],'options'=>$options];
}
function renderPersonalOddsSelector(array $periods):string {
 $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
 $html='<form action="/my-results" method="get" style="margin:0 0 20px"><input type="hidden" name="tab" value="odds"><label for="oddsPeriod"><strong>Choose a week, month or season</strong></label><div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:10px"><select id="oddsPeriod" name="odds_period" onchange="this.form.requestSubmit()" style="max-width:100%;min-height:44px;padding:10px;font:inherit;background:#102330;color:#eef5f8;border:1px solid #40596a;border-radius:8px">';
 foreach(['Week','Month','Season'] as $type){
  $html.='<optgroup label="'.$type.'">';
  foreach($periods['options'] as $key=>$p)if($p['type']===$type)$html.='<option value="'.$e($key).'"'.($key===$periods['key']?' selected':'').'>'.$e($p['label']).'</option>';
  $html.='</optgroup>';
 }
 return $html.'</select><button type="submit" class="ghost">View</button></div><p class="performancenote">Weeks follow official Premier League gameweeks. Months use UK kick-off dates. Past periods appear when you have completed predictions.</p></form>';
}
