<?php
function predictionPoints(int $h,int $a,int $actualH,int $actualA):int{
 return $h===$actualH&&$a===$actualA?5:(($h<=>$a)===($actualH<=>$actualA)?3:0);
}
function personalPerformance(PDO $db,int $uid,int $year):array{
 $start=$year.'-07-01T00:00:00Z';$end=($year+1).'-07-01T00:00:00Z';
 $q=$db->prepare("SELECT f.id,f.kickoff_utc,f.home_score actual_home,f.away_score actual_away,p.home_score,p.away_score FROM predictions p JOIN fixtures f ON f.id=p.fixture_id WHERE p.user_id=? AND f.status='FT' AND f.home_score IS NOT NULL AND f.away_score IS NOT NULL AND julianday(f.kickoff_utc)>=julianday(?) AND julianday(f.kickoff_utc)<julianday(?) AND julianday(f.kickoff_utc)<=julianday('now') ORDER BY julianday(f.kickoff_utc)");
 $q->execute([$uid,$start,$end]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
 $q=$db->prepare("SELECT p.fixture_id,p.home_score,p.away_score FROM predictions p JOIN users u ON u.id=p.user_id JOIN fixtures f ON f.id=p.fixture_id WHERE u.is_bot=1 AND u.bot_grade=1 AND u.id<>? AND f.status='FT' AND f.home_score IS NOT NULL AND f.away_score IS NOT NULL AND julianday(f.kickoff_utc)>=julianday(?) AND julianday(f.kickoff_utc)<julianday(?) AND julianday(f.kickoff_utc)<=julianday('now')");
 $q->execute([$uid,$start,$end]);$bots=[];foreach($q as $b)$bots[(int)$b['fixture_id']][]=$b;
 $stats=['played'=>0,'points'=>0,'exact'=>0,'resultOnly'=>0,'correct'=>0,'accuracy'=>0,'monthly'=>[],'sim'=>['compared'=>0,'wins'=>0,'ties'=>0,'losses'=>0]];
 foreach($rows as $r){
 $h=(int)$r['actual_home'];$a=(int)$r['actual_away'];$pts=predictionPoints((int)$r['home_score'],(int)$r['away_score'],$h,$a);
 $key=(new DateTimeImmutable($r['kickoff_utc'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m');
 if(!isset($stats['monthly'][$key]))$stats['monthly'][$key]=['label'=>date('M Y',strtotime($key.'-01')),'played'=>0,'points'=>0,'exact'=>0,'correct'=>0];
 $stats['played']++;$stats['points']+=$pts;$stats['monthly'][$key]['played']++;$stats['monthly'][$key]['points']+=$pts;
 if($pts===5){$stats['exact']++;$stats['monthly'][$key]['exact']++;}elseif($pts===3)$stats['resultOnly']++;
 if($pts>0){$stats['correct']++;$stats['monthly'][$key]['correct']++;}
 if(isset($bots[(int)$r['id']])){
 $sum=0;$n=count($bots[(int)$r['id']]);foreach($bots[(int)$r['id']] as $b)$sum+=predictionPoints((int)$b['home_score'],(int)$b['away_score'],$h,$a);
 $stats['sim']['compared']++;$stats['sim'][$pts*$n>$sum?'wins':($pts*$n===$sum?'ties':'losses')]++;
 }}
 $stats['accuracy']=$stats['played']?round(100*$stats['correct']/$stats['played']):0;return $stats;
}
function renderPersonalPerformance(array $s):void{
 echo '<div class="performanceintro">Your season so far · Completed matches you predicted</div>';
 if(!$s['played']){echo '<div class="emptyhistory">Your performance will appear after your first predicted match finishes.</div>';return;}
 echo '<div class="performancegrid">';
 foreach([[$s['points'],'Points'],[$s['exact'],'Exact scores'],[$s['correct'].' / '.$s['played'],'Correct results'],[$s['accuracy'].'%','Result accuracy']] as [$value,$label])echo '<div><strong>'.htmlspecialchars((string)$value).'</strong><span>'.$label.'</span></div>';
 echo '</div><p class="performancenote">Correct results include exact scores. '.$s['resultOnly'].' earned 3 points for the result without the exact score.</p>';
 $sim=$s['sim'];echo '<section class="performancepanel"><h3>Beat SIM1</h3>';
 if(!$sim['compared'])echo '<p>No completed matches with both your prediction and SIM1 predictions yet.</p>';
 else {echo '<div class="simheadline"><strong>'.round(100*$sim['wins']/$sim['compared']).'%</strong><span>beat the SIM1 average</span></div><p>'.$sim['wins'].' wins · '.$sim['ties'].' ties · '.$sim['losses'].' losses across '.$sim['compared'].' matches.</p>';}
 echo '<p class="performancenote">Your points versus the average points earned by SIM1 bots on each same match. Only matches with both predictions count.</p></section>';
 echo '<section class="performancepanel"><h3>Monthly progress</h3><div class="performancemonths">';
 foreach($s['monthly'] as $m){$acc=round(100*$m['correct']/$m['played']);$share=round(100*$m['points']/($m['played']*5));echo '<div class="performancemonth"><div><strong>'.htmlspecialchars($m['label']).'</strong><b>'.$m['points'].' pts</b></div><div class="performancebar" role="img" aria-label="'.$m['points'].' of '.($m['played']*5).' possible points"><span style="width:'.$share.'%"></span></div><small>'.$m['played'].' matches · '.$m['exact'].' exact · '.$acc.'% correct results · '.number_format($m['points']/$m['played'],1).' pts per match</small></div>';}
 echo '</div><p class="performancenote">Bars show the share of possible points earned. Points per match helps compare months with different numbers of games.</p></section>';
}
