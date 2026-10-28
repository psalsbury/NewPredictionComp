<?php
declare(strict_types=1);

function personalOddsResults(PDO $db,int $uid,int $year):array {
 $s=['settled'=>0,'wins'=>0,'excluded'=>0,'stake'=>0,'returns'=>0,'net'=>0,'best'=>null];
 $hasOdds=(bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='fixture_odds'")->fetchColumn();
 $join=$hasOdds?'LEFT JOIN fixture_odds o ON o.fixture_id=f.id':'';
 $prices=$hasOdds?'o.home price_home,o.draw price_draw,o.away price_away,o.observed_at,o.fetched_at':'NULL price_home,NULL price_draw,NULL price_away,NULL observed_at,NULL fetched_at';
 $q=$db->prepare("SELECT f.id,f.home,f.away,f.kickoff_utc,f.home_score actual_home,f.away_score actual_away,p.home_score,p.away_score,$prices FROM predictions p JOIN fixtures f ON f.id=p.fixture_id $join WHERE p.user_id=? AND f.status IN ('FT','AET','PEN') AND f.home_score IS NOT NULL AND f.away_score IS NOT NULL AND julianday(f.kickoff_utc)>=julianday(?) AND julianday(f.kickoff_utc)<julianday(?) AND julianday(f.kickoff_utc)<=julianday('now') ORDER BY julianday(f.kickoff_utc),f.id");
 $q->execute([$uid,$year.'-07-01T00:00:00Z',($year+1).'-07-01T00:00:00Z']);
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
function renderPersonalOddsResults(array $s):string {
 $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
 $money=fn($p)=>'£'.number_format($p/100,2);
 $html='<section class="performancepanel resultsodds" aria-labelledby="resultsOddsTitle"><h2 id="resultsOddsTitle">Your predictions at £1 a match</h2><p class="performancenote">This season · Hypothetical £1 on the winner or draw implied by each score prediction, using stored pre-match decimal odds. These are result odds, not exact-score odds.</p><h3>Best prediction by odds</h3>';
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
