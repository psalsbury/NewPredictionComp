<?php
require_once __DIR__.'/clubs.php';
function leagueStandings(PDO $db,string $start,string $end):array{
 $q=$db->prepare("SELECT * FROM fixtures WHERE julianday(kickoff_utc)>=julianday(?) AND julianday(kickoff_utc)<julianday(?) ORDER BY julianday(kickoff_utc),id");$q->execute([$start,$end]);$teams=[];$played=0;$latest=null;
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $f){
 foreach(['home','away'] as $side)$f[$side]=pcClubName($f[$side],$db);
 foreach([$f['home'],$f['away']] as $t)if(!isset($teams[$t]))$teams[$t]=['team'=>$t,'p'=>0,'w'=>0,'d'=>0,'l'=>0,'gf'=>0,'ga'=>0,'gd'=>0,'pts'=>0,'form'=>[]];
 if($f['status']!=='FT'||$f['home_score']===null||$f['away_score']===null)continue;
 $a=(int)$f['home_score'];$b=(int)$f['away_score'];$played++;$latest=$f['kickoff_utc'];
 foreach([[$f['home'],$a,$b],[$f['away'],$b,$a]] as [$t,$gf,$ga]){
 $r=&$teams[$t];$r['p']++;$r['gf']+=$gf;$r['ga']+=$ga;$r['gd']=$r['gf']-$r['ga'];$result=$gf>$ga?'W':($gf===$ga?'D':'L');$r[strtolower($result)]++;$r['pts']+=$result==='W'?3:($result==='D'?1:0);$r['form'][]=$result;$r['form']=array_slice($r['form'],-5);unset($r);
 }}
 $rows=array_values($teams);usort($rows,fn($a,$b)=>($b['pts']<=>$a['pts'])?:($b['gd']<=>$a['gd'])?:($b['gf']<=>$a['gf'])?:strcmp($a['team'],$b['team']));
 $previous=null;$position=0;foreach($rows as $i=>&$r){$key=[$r['pts'],$r['gd'],$r['gf']];if($key!==$previous)$position=$i+1;$r['position']=$position;$previous=$key;}unset($r);
 return ['rows'=>$rows,'matches'=>$played,'latest'=>$latest];
}
