<?php
declare(strict_types=1);
// League head-to-heads. Each gameweek every member of a friends league is paired with another member
// (round robin, circle method, so pairings rotate through the league). With an odd number of members one
// player meets the "League average" that week. Gameweek points (predictions.points, joker included) decide
// the match: win 3, draw 1. Members take part from the first gameweek that starts after they joined.
// Nothing is stored; everything is worked out from predictions on each view.

const PC_H2H_AVERAGE=-1; // pseudo member id for the league-average opponent

function pcH2HWeeks(PDO $db,int $season):array{
 // [gameweek => ['first'=>ts,'complete'=>bool,'started'=>bool]]; complete = every match in its first 7 days is over
 $rows=$db->prepare("SELECT g.gameweek,f.kickoff_utc,f.status,f.home_score FROM fixture_gameweeks g JOIN fixtures f ON f.id=g.fixture_id WHERE g.season=? ORDER BY g.gameweek");
 $rows->execute([$season]);$by=[];
 foreach($rows as $r)$by[(int)$r['gameweek']][]=$r;
 $out=[];
 foreach($by as $gw=>$fs){
  $first=min(array_map(fn($f)=>strtotime($f['kickoff_utc']),$fs));$open=false;$anyFinal=false;
  foreach($fs as $f){
   if(strtotime($f['kickoff_utc'])>$first+7*86400)continue;
   $final=in_array($f['status'],['FT','AET','PEN'],true)&&$f['home_score']!==null;$anyFinal=$anyFinal||$final;
   if(!$final&&!in_array($f['status'],['PST','CANC','ABD'],true))$open=true;
  }
  $out[$gw]=['first'=>$first,'complete'=>!$open&&$anyFinal,'started'=>$first<=time()];
 }
 return $out;
}
function pcH2HPairs(array $ids,int $round):array{
 sort($ids);if(count($ids)<2)return [];
 if(count($ids)%2)$ids[]=PC_H2H_AVERAGE;
 $n=count($ids);$fixed=$ids[0];$rest=array_slice($ids,1);$r=$round%($n-1);
 $rest=array_merge(array_slice($rest,$r),array_slice($rest,0,$r));$all=array_merge([$fixed],$rest);$pairs=[];
 for($i=0;$i<$n/2;$i++)$pairs[]=[$all[$i],$all[$n-1-$i]];
 return $pairs;
}
// Returns ['names'=>[id=>name],'weeks'=>[gw=>['state'=>'done|live|next','pairs'=>[[a,b,pa,pb]...]]],'table'=>[rows],'current'=>gw|null]
function pcLeagueH2H(PDO $db,int $leagueId,int $season):array{
 $weeks=pcH2HWeeks($db,$season);if(!$weeks)return ['names'=>[],'weeks'=>[],'table'=>[],'current'=>null];
 $m=$db->prepare('SELECT m.user_id,u.display_name,COALESCE(u.is_bot,0) is_bot,m.joined_at FROM league_members m JOIN users u ON u.id=m.user_id WHERE m.league_id=?');$m->execute([$leagueId]);
 $members=$m->fetchAll(PDO::FETCH_ASSOC);$names=[PC_H2H_AVERAGE=>'League average'];foreach($members as $r)$names[(int)$r['user_id']]=$r['display_name'];
 $p=$db->prepare("SELECT g.gameweek,p.user_id,SUM(CASE WHEN f.status IN ('FT','AET','PEN') THEN COALESCE(p.points,0) ELSE 0 END) pts
  FROM predictions p JOIN fixture_gameweeks g ON g.fixture_id=p.fixture_id JOIN fixtures f ON f.id=p.fixture_id JOIN league_members lm ON lm.user_id=p.user_id AND lm.league_id=?
  WHERE g.season=? GROUP BY g.gameweek,p.user_id");$p->execute([$leagueId,$season]);
 $pts=[];foreach($p as $r)$pts[(int)$r['gameweek']][(int)$r['user_id']]=(int)$r['pts'];
 $current=null;foreach($weeks as $gw=>$w)if(!$w['complete']){$current=$gw;break;}
 $table=[];$out=[];$round=0;
 foreach($weeks as $gw=>$w){
  if($current!==null&&$gw>$current)break;
  $ids=[];foreach($members as $r)if(strtotime($r['joined_at'].' UTC')<=$w['first'])$ids[]=(int)$r['user_id'];
  if($gw===$current&&count($ids)<2){$ids=array_map(fn($r)=>(int)$r['user_id'],$members);} // new league: let this gameweek include everyone
  $pairs=pcH2HPairs($ids,$round);if(!$pairs)continue;$round++;
  $avg=$ids?array_sum(array_map(fn($id)=>$pts[$gw][$id]??0,$ids))/count($ids):0;
  $state=$w['complete']?'done':($w['started']?'live':'next');$rows=[];
  foreach($pairs as [$a,$b]){
   $pa=$a===PC_H2H_AVERAGE?round($avg,1):($pts[$gw][$a]??0);$pb=$b===PC_H2H_AVERAGE?round($avg,1):($pts[$gw][$b]??0);$rows[]=[$a,$b,$pa,$pb];
   if($state!=='done')continue;
   foreach([[$a,$pa,$pb],[$b,$pb,$pa]] as [$id,$for,$against]){
    if($id===PC_H2H_AVERAGE)continue;
    $t=&$table[$id];$t??=['id'=>$id,'name'=>$names[$id]??'?','p'=>0,'w'=>0,'d'=>0,'l'=>0,'pf'=>0,'pa'=>0,'pts'=>0];
    $t['p']++;$t['pf']+=$for;$t['pa']+=$against;
    if($for>$against){$t['w']++;$t['pts']+=3;}elseif($for==$against){$t['d']++;$t['pts']+=1;}else $t['l']++;
    unset($t);
   }
  }
  $out[$gw]=['state'=>$state,'pairs'=>$rows];
 }
 $table=array_values($table);usort($table,fn($x,$y)=>($y['pts']<=>$x['pts'])?:(($y['pf']-$y['pa'])<=>($x['pf']-$x['pa']))?:($y['pf']<=>$x['pf'])?:strcmp($x['name'],$y['name']));
 return ['names'=>$names,'weeks'=>$out,'table'=>$table,'current'=>$current];
}
function pcH2HHtml(array $h,int $viewer=0,bool $full=true):string{
 if(!$h['weeks'])return '';
 $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$n=fn($id)=>$id===$viewer?'You':($h['names'][$id]??'?');
 $fmt=fn($v)=>is_float($v)?number_format($v,1):(string)$v;
 $gw=$h['current']??array_key_last($h['weeks']);$week=$h['weeks'][$gw]??null;
 $html='<section class="h2hbox"><h3>Head-to-head</h3>';
 if($week){
  $html.='<p class="h2hsub">Gameweek '.(int)$gw.' · '.($week['state']==='next'?'starts soon':($week['state']==='live'?'in play (points so far)':'final')).'</p><ul class="h2hpairs">';
  foreach($week['pairs'] as [$a,$b,$pa,$pb]){
   $mine=$a===$viewer||$b===$viewer;$show=$week['state']!=='next';
   $html.='<li'.($mine?' class="mine"':'').'><span class="h2ha'.($show&&$pa>$pb?' lead':'').'">'.$e($n($a)).'</span><b>'.($show?$e($fmt($pa)).'–'.$e($fmt($pb)):'v').'</b><span class="h2hb'.($show&&$pb>$pa?' lead':'').'">'.$e($n($b)).'</span></li>';
  }
  $html.='</ul>';
 }
 if($full&&$h['table']){
  $html.='<div class="h2htablewrap"><table class="h2htable"><thead><tr><th class="l">Player</th><th>P</th><th>W</th><th>D</th><th>L</th><th title="Gameweek points for and against">+/−</th><th>Pts</th></tr></thead><tbody>';
  foreach($h['table'] as $r)$html.='<tr'.($r['id']===$viewer?' class="mine"':'').'><td class="l">'.$e($r['name']).'</td><td>'.$r['p'].'</td><td>'.$r['w'].'</td><td>'.$r['d'].'</td><td>'.$r['l'].'</td><td>'.$e($fmt($r['pf']-$r['pa'])).'</td><td><b>'.$r['pts'].'</b></td></tr>';
  $html.='</tbody></table></div><p class="h2hnote">Each gameweek you are paired with another member; more gameweek points wins (3 for a win, 1 for a draw). With an odd number of players, one meets the league average.</p>';
 }
 return $html.'</section>';
}
