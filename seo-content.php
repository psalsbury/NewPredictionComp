<?php
require_once __DIR__.'/clubs.php';
function priorResults(PDO $db,string $team,string $before,?string $side=null,int $limit=5):array{
 $names=pcClubAliases($team,$db);$marks=implode(',',array_fill(0,count($names),'?'));
 $where=$side==='home'?"home COLLATE NOCASE IN ($marks)":($side==='away'?"away COLLATE NOCASE IN ($marks)":"(home COLLATE NOCASE IN ($marks) OR away COLLATE NOCASE IN ($marks))");
 $args=$side?array_merge($names,[$before]):array_merge($names,$names,[$before]);
 $q=$db->prepare("SELECT * FROM fixtures WHERE $where AND status IN ('FT','AET','PEN') AND home_score IS NOT NULL AND away_score IS NOT NULL AND julianday(kickoff_utc)<julianday(?) ORDER BY julianday(kickoff_utc) DESC LIMIT ".(int)$limit);
 $q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function formSummary(array $rows,string $team):string{
 if(!$rows)return 'No earlier completed league matches are available in our records.';
 $w=$d=$l=$gf=$ga=0;
 foreach($rows as $r){$a=(int)(pcClubName($r['home'])===pcClubName($team)?$r['home_score']:$r['away_score']);$b=(int)(pcClubName($r['home'])===pcClubName($team)?$r['away_score']:$r['home_score']);$gf+=$a;$ga+=$b;if($a>$b)$w++;elseif($a===$b)$d++;else $l++;}
 return 'Across '.count($rows).' recorded matches: '.$w.' wins, '.$d.' draws and '.$l.' losses; '.$gf.' goals scored and '.$ga.' conceded.';
}
function weekKey(string $date):string{$d=(new DateTimeImmutable($date,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/London'))->setTime(0,0);$offset=((int)$d->format('N')+2)%7;return $d->modify('-'.$offset.' days')->format('Y-m-d');}
function insightBlock(PDO $db,array $match):void{
 echo '<section class="panel insights"><h2>Player prediction insights</h2>';
 if(strtotime($match['kickoff_utc'])>time()){echo '<p>Prediction trends will appear after kick-off. Players’ individual picks stay private until then.</p></section>';return;}
 foreach([0=>'Real players',1=>'Bot predictions'] as $bot=>$label){
  $q=$db->prepare('SELECT p.home_score,p.away_score,COUNT(*) n FROM predictions p JOIN users u ON u.id=p.user_id WHERE p.fixture_id=? AND u.is_bot=? GROUP BY p.home_score,p.away_score ORDER BY n DESC,p.home_score,p.away_score');$q->execute([$match['id'],$bot]);$scores=$q->fetchAll(PDO::FETCH_ASSOC);$n=array_sum(array_column($scores,'n'));
  echo '<h3>'.e($label).'</h3>';
  if(($bot===0&&$n<5)||!$n){echo '<p>'.($bot===0?'Trends appear once at least five real players have predicted this match.':'No bot predictions recorded.').'</p>';continue;}
  $out=[0,0,0];foreach($scores as $r){$out[$r['home_score']>$r['away_score']?0:($r['home_score']===$r['away_score']?1:2)]+=(int)$r['n'];}
  echo '<p>'.(int)$n.' predictions · Home win '.round(100*$out[0]/$n).'% · Draw '.round(100*$out[1]/$n).'% · Away win '.round(100*$out[2]/$n).'%</p>';
  $top=array_filter($scores,fn($r)=>(int)$r['n']===(int)$scores[0]['n']);$labels=[];foreach($top as $r)$labels[]=$r['home_score'].'–'.$r['away_score'];
  echo '<p>Most popular score'.(count($top)>1?'s (tied)':'').': <strong>'.e(implode(', ',$labels)).'</strong> · '.(int)$scores[0]['n'].' pick'.($scores[0]['n']==1?'':'s').' each.</p>';
 }
 echo '<p class="note">Counts are separated by player type. These are game predictions, not betting odds.</p></section>';
}
function previewBlock(PDO $db,array $match,bool $compact=false):void{
 $home=priorResults($db,$match['home'],$match['kickoff_utc']);$away=priorResults($db,$match['away'],$match['kickoff_utc']);
 echo '<section class="panel preview"><h2>'.($compact?e($match['home'].' v '.$match['away']):'Match preview').'</h2><p><strong>'.e($match['home']).':</strong> '.e(formSummary($home,$match['home'])).'</p><p><strong>'.e($match['away']).':</strong> '.e(formSummary($away,$match['away'])).'</p>';
 if(!$compact){
 echo '<div class="columns"><div><h3>'.e($match['home']).' at home</h3><p>'.e(formSummary(priorResults($db,$match['home'],$match['kickoff_utc'],'home'),$match['home'])).'</p></div><div><h3>'.e($match['away']).' away</h3><p>'.e(formSummary(priorResults($db,$match['away'],$match['kickoff_utc'],'away'),$match['away'])).'</p></div></div>';
 $q=$db->prepare("SELECT * FROM fixtures WHERE ((home=? AND away=?) OR (home=? AND away=?)) AND julianday(kickoff_utc)<julianday(?) AND status IN ('FT','AET','PEN') AND home_score IS NOT NULL AND away_score IS NOT NULL ORDER BY julianday(kickoff_utc) DESC LIMIT 5");$q->execute([$match['home'],$match['away'],$match['away'],$match['home'],$match['kickoff_utc']]);$meetings=$q->fetchAll(PDO::FETCH_ASSOC);
 echo '<h3>Previous meetings in our records</h3>';if($meetings)fixtureRows($meetings);else echo '<p>No earlier completed meeting is recorded for this season.</p>';
 }
 echo '<p class="note">Summary calculated from up to five completed matches in this season’s records before this fixture. Missing results can limit the sample.</p>';
 if($compact){echo '<p>'.e(uk($match['kickoff_utc'])).' UK';if($match['home_score']!==null&&$match['away_score']!==null)echo ' · Result: '.e($match['home_score'].'–'.$match['away_score']);echo '</p><a href="'.matchLink($match).'" class="compactlink">Match details <span aria-hidden="true">→</span></a>';}
 echo '</section>';
}
