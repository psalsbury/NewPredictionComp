<?php
declare(strict_types=1);
// Bot predictions as public content: per-match consensus, a one-line summary for weekly guides, and the weekly "Can you beat SIM1?" table.
// Before kick-off only aggregates are shown: the result split, popular scores from SIM2-SIM5, and SIM1's lean on the result. SIM1's exact picks
// (and SIM1's share of the popular scores) appear once a match locks, so nobody can copy the strongest bots' scores.
require_once __DIR__.'/inc/listing.php';

function pcBotPicks(PDO $db,int $fixtureId):array{
 static $cache=[];
 if(isset($cache[$fixtureId]))return $cache[$fixtureId];
 $q=$db->prepare('SELECT p.home_score h,p.away_score a,p.points,CAST(COALESCE(u.bot_grade,5) AS INTEGER) grade FROM predictions p JOIN users u ON u.id=p.user_id WHERE p.fixture_id=? AND COALESCE(u.is_bot,0)=1');
 $q->execute([$fixtureId]);return $cache[$fixtureId]=$q->fetchAll(PDO::FETCH_ASSOC);
}
/** Summarise picks: n, result split [home,draw,away] and scores ordered by popularity. */
function pcPickSummary(array $picks):array{
 $out=[0,0,0];$scores=[];
 foreach($picks as $p){$h=(int)$p['h'];$a=(int)$p['a'];$out[$h>$a?0:($h===$a?1:2)]++;$k=$h.'–'.$a;$scores[$k]=($scores[$k]??0)+1;}
 uksort($scores,fn($x,$y)=>($scores[$y]<=>$scores[$x])?:strcmp($x,$y));
 return ['n'=>count($picks),'out'=>$out,'scores'=>$scores];
}
function pcLean(array $s,string $home,string $away):string{
 if(!$s['n'])return '';
 $labels=[$home.' win','Draw',$away.' win'];$max=max($s['out']);$top=array_keys($s['out'],$max);
 return count($top)>1?'Split':$labels[$top[0]];
}
/** Short club name for narrow table headers. */
function pcShortName(string $club):string{$map=['Brighton & Hove Albion'=>'Brighton','Manchester City'=>'Man City','Manchester United'=>'Man Utd','Newcastle United'=>'Newcastle','Nottingham Forest'=>'Forest','Tottenham Hotspur'=>'Spurs','Wolverhampton Wanderers'=>'Wolves','West Ham United'=>'West Ham','AFC Bournemouth'=>'Bournemouth','Crystal Palace'=>'Palace','Leeds United'=>'Leeds','Coventry City'=>'Coventry','Ipswich Town'=>'Ipswich','Hull City'=>'Hull','Leicester City'=>'Leicester','Sheffield United'=>'Sheff Utd','Aston Villa'=>'Villa','Norwich City'=>'Norwich','Stoke City'=>'Stoke','Cardiff City'=>'Cardiff','Swansea City'=>'Swansea'];return $map[$club]??preg_replace('/ (FC|City|Town|United|Albion)$/','',$club);}
/** Picks whose scores may be shown: before kick-off SIM1 is left out so its exact scores can't be read off the totals. */
function pcVisibleScorePicks(array $picks,array $match):array{return strtotime($match['kickoff_utc'])<=time()?$picks:array_values(array_filter($picks,fn($p)=>(int)$p['grade']!==1));}
function pcIsFinished(array $f):bool{return in_array($f['status'],['FT','AET','PEN'],true)&&$f['home_score']!==null&&$f['away_score']!==null;}
/** Postponed, cancelled or abandoned fixtures don't count towards a week being complete. */
function pcIsCalledOff(array $f):bool{return in_array($f['status'],['PST','CANC','ABD'],true);}
/** True once a finished fixture's points have been written for every prediction. */
function pcIsScored(PDO $db,int $fixtureId):bool{$q=$db->prepare('SELECT COUNT(*) FROM predictions WHERE fixture_id=? AND points IS NULL');$q->execute([$fixtureId]);return (int)$q->fetchColumn()===0;}
function pcPct(int $part,int $n):int{return $n?(int)round(100*$part/$n):0;}

function botConsensusBlock(PDO $db,array $match):void{
 $picks=pcBotPicks($db,(int)$match['id']);
 echo '<section class="panel botconsensus"><h2>What the bots predict</h2>';
 if(!$picks){echo '<p>The bots have not predicted this match yet. Their picks usually appear a few days before kick-off.</p></section>';return;}
 $all=pcPickSummary($picks);$sim1=pcPickSummary(array_filter($picks,fn($p)=>(int)$p['grade']===1));
 $locked=strtotime($match['kickoff_utc'])<=time();
 $finished=pcIsFinished($match);$scored=$finished&&pcIsScored($db,(int)$match['id']);
 [$hw,$dr,$aw]=$all['out'];$n=$all['n'];
 echo '<p>'.pcCount($n,'bot prediction').' across five skill levels.</p>';
 echo '<div class="botsplit" role="img" aria-label="'.e($match['home'].' win '.pcPct($hw,$n).'%, draw '.pcPct($dr,$n).'%, '.$match['away'].' win '.pcPct($aw,$n).'%').'">';
 foreach([[$hw,'home',$match['home']],[$dr,'draw','Draw'],[$aw,'away',$match['away']]] as [$c,$cls,$label])
  if($c)echo '<span class="botsplit-'.$cls.'" style="flex:'.$c.'"><b>'.pcPct($c,$n).'%</b><small>'.e($label).'</small></span>';
 echo '</div>';
 $visible=pcPickSummary(pcVisibleScorePicks($picks,$match));
 $top=array_slice($visible['scores'],0,3,true);$bits=[];foreach($top as $score=>$c)$bits[]='<strong>'.e($score).'</strong> ('.$c.')';
 if($bits)echo '<p>Most picked scores'.($locked?'':' (SIM2–SIM5)').': '.implode(', ',$bits).'.</p>';
 if($sim1['n']){
  $lean=pcLean($sim1,$match['home'],$match['away']);$leanCount=max($sim1['out']);
  if(!$locked){
   echo '<p><span class="simbadge">SIM1</span> The strongest bots lean <strong>'.e($lean).'</strong>'.($lean==='Split'?'':' ('.$leanCount.' of '.$sim1['n'].')').'. Their exact scores are revealed at kick-off.</p>';
  }else{
   $simTop=array_slice($sim1['scores'],0,3,true);$b=[];foreach($simTop as $score=>$c)$b[]='<strong>'.e($score).'</strong> ('.$c.')';
   echo '<p><span class="simbadge">SIM1</span> The strongest bots picked '.implode(', ',$b).'.</p>';
  }
 }
 if($finished&&!$scored){
  echo '<p class="note">Points for this match are still being worked out. Check back shortly to see how each level scored.</p>';
 }elseif($scored){
  $byGrade=[];foreach($picks as $p){$g=(int)$p['grade'];$byGrade[$g][]=(int)$p['points'];}
  ksort($byGrade);
  echo '<h3>How each level scored</h3><div class="botgrades">';
  foreach($byGrade as $g=>$pts)echo '<div><span class="simbadge">SIM'.$g.'</span><b>'.number_format(array_sum($pts)/count($pts),1).'</b><small>avg points · '.count(array_filter($pts,fn($x)=>$x===5)).' exact</small></div>';
  echo '</div>';
  $q=$db->prepare('SELECT AVG(p.points) avg,COUNT(*) n FROM predictions p JOIN users u ON u.id=p.user_id WHERE p.fixture_id=? AND COALESCE(u.is_bot,0)=0');$q->execute([(int)$match['id']]);$hum=$q->fetch(PDO::FETCH_ASSOC);
  if((int)$hum['n']>=3)echo '<p>Real players averaged <strong>'.number_format((float)$hum['avg'],1).'</strong> points from '.pcCount((int)$hum['n'],'prediction').'.</p>';
 }elseif(!$locked){
  echo '<a class="cta" href="/?fixture='.(int)$match['id'].'#predict">Think you know better? Predict it</a>';
 }
 echo '<p class="note">SIM bots are computer players with different styles. SIM1 is the strongest; SIM5 guesses at random. <a href="/beat-the-bots">Meet the bots</a>.</p></section>';
}

/** One line for a weekly guide card. */
function botConsensusLine(PDO $db,array $match):string{
 $picks=pcBotPicks($db,(int)$match['id']);if(!$picks)return '';
 $s=pcPickSummary($picks);$lean=pcLean($s,$match['home'],$match['away']);$score=array_key_first(pcPickSummary(pcVisibleScorePicks($picks,$match))['scores']);
 $sim1=pcPickSummary(array_filter($picks,fn($p)=>(int)$p['grade']===1));
 $text='Bot consensus: '.($lean==='Split'?'split on the result':$lean.' '.pcPct(max($s['out']),$s['n']).'%').($score!==null?' · most picked '.$score:'');
 if($sim1['n'])$text.=' · SIM1 lean: '.pcLean($sim1,$match['home'],$match['away']);
 return '<p class="botline">'.e($text).'</p>';
}

/**
 * Weekly human-vs-SIM1 comparison for finished, scored fixtures in $rows. Lists players who appear on the global table
 * (unlisted guests are counted but not named).
 */
function beatSim1Data(PDO $db,array $rows,?array $memberIds=null):?array{
 $done=array_values(array_filter($rows,fn($f)=>pcIsFinished($f)&&pcIsScored($db,(int)$f['id'])));
 if(!$done)return null;
 $playable=array_filter($rows,fn($f)=>!pcIsCalledOff($f));
 $ids=implode(',',array_map(fn($f)=>(int)$f['id'],$done));
 $sim=$db->query("SELECT u.display_name,SUM(p.points) pts FROM predictions p JOIN users u ON u.id=p.user_id WHERE p.fixture_id IN ($ids) AND COALESCE(u.is_bot,0)=1 AND COALESCE(u.bot_grade,5)=1 GROUP BY u.id ORDER BY pts DESC,u.id")->fetchAll(PDO::FETCH_ASSOC);
 if(!$sim)return null;
 $best=(int)$sim[0]['pts'];$avg=array_sum(array_map(fn($r)=>(int)$r['pts'],$sim))/count($sim);
 // Inside a league every member is already visible on its page, so nobody is hidden there.
 $scope=$memberIds===null?'':' AND u.id IN ('.(implode(',',array_map('intval',$memberIds))?:'0').')';
 $listed=$memberIds===null?pc_listed_sql():'1';
 $humans=$db->query("SELECT u.display_name,$listed listed,SUM(p.points) pts,COUNT(*) picks FROM predictions p JOIN users u ON u.id=p.user_id WHERE p.fixture_id IN ($ids) AND COALESCE(u.is_bot,0)=0$scope GROUP BY u.id ORDER BY pts DESC,u.id")->fetchAll(PDO::FETCH_ASSOC);
 return ['matches'=>count($done),'complete'=>count($done)===count($playable),'best'=>$best,'best_name'=>(string)$sim[0]['display_name'],'avg'=>$avg,'humans'=>$humans,'beat'=>count(array_filter($humans,fn($h)=>(int)$h['pts']>$best))];
}
function beatSim1Block(PDO $db,array $rows,string $heading='Can you beat SIM1?',string $weekLabel='',?array $memberIds=null):void{
 $d=beatSim1Data($db,$rows,$memberIds);if(!$d)return;
 echo '<section class="panel beatsim1"><h2>'.e($heading).'</h2><p>'.($weekLabel!==''?e($weekLabel).': ':'').'The best <span class="simbadge">SIM1</span> bot, '.e($d['best_name']).', scored <strong>'.$d['best'].' points</strong> from '.pcCount($d['matches'],'finished match','finished matches').' (SIM1 average '.number_format($d['avg'],1).').</p>';
 if(!$d['humans']){echo '<p>No real players predicted these matches. <a href="/#predict">Be the first to take SIM1 on</a>.</p></section>';return;}
 echo '<p>'.($d['beat']?pcCount($d['beat'],'player').' '.($d['complete']?'beat':((int)$d['beat']===1?'is beating':'are beating')).' SIM1’s best.':($d['complete']?'Nobody beat SIM1’s best.':'Nobody has beaten SIM1’s best yet.')).'</p><ol class="beatlist">';
 $shown=0;$hidden=0;
 foreach($d['humans'] as $h){
  if(empty($h['listed'])){$hidden++;continue;}
  if($shown++>=10)continue;
  $diff=(int)$h['pts']-$d['best'];
  echo '<li class="'.($diff>0?'beat':'').'"><span>'.e($h['display_name']).'</span><b>'.(int)$h['pts'].'</b><small>'.($diff>0?'+'.$diff.' vs SIM1':($diff===0?'level with SIM1':$diff.' vs SIM1')).'</small></li>';
 }
 echo '</ol>';
 if($hidden)echo '<p class="note">'.pcCount($hidden,'new player is','new players are').' not shown until they appear on the global table.</p>';
 echo '<a class="cta" href="/#predict">Take on SIM1 in the next matches</a></section>';
}
