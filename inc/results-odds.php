<?php
declare(strict_types=1);

function personalOddsResults(PDO $db,int $uid,int $year,?array $period=null):array {
 $s=['settled'=>0,'wins'=>0,'excluded'=>0,'stake'=>0,'returns'=>0,'net'=>0,'best'=>null];
 $hasOdds=(bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='fixture_odds'")->fetchColumn();
 $join=$hasOdds?'LEFT JOIN fixture_odds o ON o.fixture_id=f.id':'';
 $prices=$hasOdds?'o.home price_home,o.draw price_draw,o.away price_away,o.observed_at,o.fetched_at':'NULL price_home,NULL price_draw,NULL price_away,NULL observed_at,NULL fetched_at';
 $scope='';$params=[$uid,$period['start']??$year.'-07-01T00:00:00Z',$period['end']??($year+1).'-07-01T00:00:00Z'];
 if(isset($period['week'])){$scope=' AND EXISTS(SELECT 1 FROM fixture_gameweeks g WHERE g.fixture_id=f.id AND g.season=? AND g.gameweek=?)';$params=[$uid,$period['season'].'-07-01T00:00:00Z',($period['season']+1).'-07-01T00:00:00Z',$period['season'],$period['week']];}
 $q=$db->prepare("SELECT f.id,f.home,f.away,f.kickoff_utc,f.home_score actual_home,f.away_score actual_away,p.home_score,p.away_score,$prices FROM predictions p JOIN fixtures f ON f.id=p.fixture_id $join WHERE p.user_id=? AND f.status IN ('FT','AET','PEN') AND f.home_score IS NOT NULL AND f.away_score IS NOT NULL AND julianday(f.kickoff_utc)>=julianday(?) AND julianday(f.kickoff_utc)<julianday(?) AND julianday(f.kickoff_utc)<=julianday('now') $scope ORDER BY julianday(f.kickoff_utc),f.id");
 $q->execute($params);
 foreach($q as $r){
  $side=(int)$r['home_score']<=>(int)$r['away_score'];
  $price=(float)$r[['-1'=>'price_away','0'=>'price_draw','1'=>'price_home'][(string)$side]];
  $kick=strtotime($r['kickoff_utc']);$observed=$r['observed_at']?strtotime($r['observed_at']):false;$fetched=$r['fetched_at']?strtotime($r['fetched_at']):false;
  if(!is_finite($price)||$price<=1||$kick===false||$observed===false||$fetched===false||$observed>=$kick||$fetched>=$kick){$s['excluded']++;continue;}
  $s['settled']++;$s['stake']+=100;
  $won=$side===((int)$r['actual_home']<=>(int)$r['actual_away']);
  if($won){
   $s['wins']++;$s['returns']+=(int)round($price*100);
   if($s['best']===null||$price>$s['best']['price'])$s['best']=array_merge($r,['price'=>$price,'selection'=>$side===0?'Draw':($side===1?$r['home'].' win':$r['away'].' win')]);
  }
 }
 $s['net']=$s['returns']-$s['stake'];return $s;
}
function renderPersonalOddsResults(array $s,string $label='This season'):string {
 $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
 $money=fn($p)=>'£'.number_format($p/100,2);
 $html='<section class="performancepanel resultsodds" aria-labelledby="resultsOddsTitle"><h2 id="resultsOddsTitle">Your predictions at £1 a match</h2><p class="performancenote">'.$e($label).' · Hypothetical £1 on the winner or draw implied by each score prediction, using stored pre-match decimal odds. These are result odds, not exact-score odds.</p><h3>Best prediction by odds</h3>';
 $b=$s['best'];
 if($b){$date=(new DateTimeImmutable($b['kickoff_utc']))->setTimezone(new DateTimeZone('Europe/London'))->format('j M Y');
  $html.='<p><a href="/fixtures/'.(int)$b['id'].'"><strong>'.$e($b['home'].' v '.$b['away']).'</strong></a> · '.$e($date).'<br>'.$e($b['selection']).' at <strong>'.number_format($b['price'],2).'</strong><br>Your score: '.$e($b['home_score'].'–'.$b['away_score']).' · Result: '.$e($b['actual_home'].'–'.$b['actual_away']).'</p>';
 }else $html.='<p>No successful settled prediction with usable odds yet.</p>';
 if($s['settled']){
  $net=($s['net']>0?'+':($s['net']<0?'−':'')).$money(abs($s['net']));
  $html.='<div class="performancegrid">';
  foreach([[$s['settled'],'£1 stakes'],[$money($s['stake']),'Total staked'],[$money($s['returns']),'Total returned'],[$net,'Net position']] as [$value,$label])$html.='<div><strong>'.$e($value).'</strong><span>'.$label.'</span></div>';
  $html.='</div><p class="performancenote">'.$s['wins'].' winning results from '.$s['settled'].' settled predictions. Returns include the original stake; net position is returns minus all stakes.</p>';
 }else $html.='<p>Your net position will appear after a predicted match finishes with usable pre-match odds.</p>';
 $html.='<p class="performancenote">Best means the highest-odds correct winner or draw, including exact scores. Finished matches only; upcoming, live, postponed, cancelled and abandoned matches are excluded. '.$s['excluded'].' completed prediction'.($s['excluded']===1?' was':'s were').' excluded because valid pre-match odds were unavailable. Stored prices may differ from those available when you saved your pick. No real money is placed.</p></section>';
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
