<?php
require_once __DIR__.'/clubs.php';
function priorResults(PDO $db,string $team,string $before,?string $side=null,int $limit=5):array{
 $names=pcClubAliases($team,$db);$marks=implode(',',array_fill(0,count($names),'?'));
 $where=$side==='home'?"home COLLATE NOCASE IN ($marks)":($side==='away'?"away COLLATE NOCASE IN ($marks)":"(home COLLATE NOCASE IN ($marks) OR away COLLATE NOCASE IN ($marks))");
 $args=$side?array_merge($names,[$before]):array_merge($names,$names,[$before]);
 $q=$db->prepare("SELECT * FROM fixtures WHERE $where AND status IN ('FT','AET','PEN') AND home_score IS NOT NULL AND away_score IS NOT NULL AND julianday(kickoff_utc)<julianday(?) ORDER BY julianday(kickoff_utc) DESC LIMIT ".(int)$limit);
 $q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function pcCount(int $n,string $one,?string $many=null):string{return $n.' '.($n===1?$one:($many??$one.'s'));}
function pcWords(array $parts):string{$parts=array_values(array_filter($parts));$last=array_pop($parts);return $parts?implode(', ',$parts).' and '.$last:(string)$last;}
/* One row seen from $team's side: [goals for, goals against, opponent, venue]. */
function pcTeamView(array $r,string $team):array{$home=pcClubName($r['home'])===pcClubName($team);return [(int)($home?$r['home_score']:$r['away_score']),(int)($home?$r['away_score']:$r['home_score']),$home?$r['away']:$r['home'],$home?'home':'away'];}
/* Plain-English form summary, e.g. "Everton are unbeaten in their last 5 league matches (2 wins and 3 draws), scoring 6 and conceding 3. Most recently they drew 1–1 away at Fulham." */
function formSummary(array $rows,string $team,string $context=''):string{
 $kind=trim($context.' league match');
 if(!$rows)return 'No earlier completed '.$kind.'es are in our records yet.';
 $n=count($rows);$w=$d=$l=$gf=$ga=$cs=0;
 foreach($rows as $r){[$a,$b]=pcTeamView($r,$team);$gf+=$a;$ga+=$b;if($b===0)$cs++;if($a>$b)$w++;elseif($a===$b)$d++;else $l++;}
 $span=$n===1?'their last '.$kind:'their last '.$n.' '.$kind.'es';
 $record=pcWords([$w?pcCount($w,'win'):'',$d?pcCount($d,'draw'):'',$l?pcCount($l,'defeat'):'']);
 if($n===1)$s=$team.($w?' won ':($d?' drew ':' lost ')).$span;
 elseif($w===$n)$s=$team.' have won all of '.$span;
 elseif($l===$n)$s=$team.' have lost all of '.$span;
 elseif($l===0)$s=$team.' are unbeaten in '.$span.' ('.$record.')';
 elseif($w===0)$s=$team.' are without a win in '.$span.' ('.$record.')';
 else $s=$team.' have '.$record.' from '.$span;
 $s.=', scoring '.$gf.' and conceding '.$ga.($cs?' with '.pcCount($cs,'clean sheet'):'').'.';
 [$a,$b,$opp,$venue]=pcTeamView($rows[0],$team);$where=$context?'':($venue==='home'?' at home':' away');
 $s.=' Most recently they '.($a>$b?'beat '.$opp.' '.$a.'–'.$b.$where:($a===$b?'drew '.$a.'–'.$b.' with '.$opp.$where:'lost '.$b.'–'.$a.' to '.$opp.$where)).'.';
 return $s;
}
/* Short factual numbers for a match page, from each side's last five completed matches. */
function keyNumbersBlock(PDO $db,array $match):void{
 $items=[];$pts=[];
 foreach([$match['home'],$match['away']] as $team){
  $rows=priorResults($db,$team,$match['kickoff_utc']);$n=count($rows);if(!$n)continue;
  $gf=$ga=$btts=$over=$p=0;foreach($rows as $r){[$a,$b]=pcTeamView($r,$team);$gf+=$a;$ga+=$b;if($a&&$b)$btts++;if($a+$b>2)$over++;$p+=$a>$b?3:($a===$b?1:0);}
  $items[]=$team.' average '.number_format($gf/$n,1).' goals scored and '.number_format($ga/$n,1).' conceded per game over their last '.pcCount($n,'match','matches').'.';
  $items[]='Both teams scored in '.$btts.' of '.$team.'’s last '.$n.', and '.$over.' had three or more goals.';
  $pts[]=$team.' '.$p.' from '.($n*3);
 }
 if(!$items)return;
 echo '<section class="panel keynumbers"><h2>Key numbers</h2><ul>';foreach($items as $i)echo '<li>'.e($i).'</li>';
 if(count($pts)===2)echo '<li>'.e('Points from recent matches: '.implode(', ',$pts).'.').'</li>';
 echo '</ul></section>';
}
/* After matches finish: how PredictionComp players and bots called the week. */
function weekReviewBlock(PDO $db,array $rows):void{
 $done=array_values(array_filter($rows,fn($f)=>in_array($f['status'],['FT','AET','PEN'],true)&&$f['home_score']!==null&&$f['away_score']!==null));
 if(!$done)return;
 $calls=[];
 foreach($done as $f){
  $h=(int)$f['home_score'];$a=(int)$f['away_score'];$sign=$h<=>$a;
  $q=$db->prepare("SELECT COUNT(*) n,SUM(CASE WHEN home_score>away_score THEN 1 WHEN home_score<away_score THEN -1 ELSE 0 END=$sign) right_result,SUM(home_score=$h AND away_score=$a) exact FROM predictions WHERE fixture_id=?");
  $q->execute([(int)$f['id']]);$c=$q->fetch(PDO::FETCH_ASSOC);
  if((int)$c['n']>0)$calls[]=['f'=>$f,'n'=>(int)$c['n'],'pct'=>(int)round(100*$c['right_result']/$c['n']),'exact'=>(int)$c['exact']];
 }
 echo '<section class="panel weekreview"><h2>Week in review</h2><p>'.e(count($done)===count($rows)?'All '.pcCount(count($rows),'match','matches').' have been played.':count($done).' of '.pcCount(count($rows),'match','matches').' played so far this week.').'</p>';
 if($calls){
  usort($calls,fn($x,$y)=>$x['pct']<=>$y['pct']);$hard=$calls[0];$easy=end($calls);
  $label=fn($c)=>$c['f']['home'].' '.$c['f']['home_score'].'–'.$c['f']['away_score'].' '.$c['f']['away'];
  echo '<ul><li>'.e('Hardest to call: '.$label($hard).'. Only '.$hard['pct'].'% of predictions had the right result.').'</li>';
  if(count($calls)>1)echo '<li>'.e('Easiest to call: '.$label($easy).', with '.$easy['pct'].'% of predictions right.').'</li>';
  $exact=array_sum(array_column($calls,'exact'));echo '<li>'.e(pcCount($exact,'exact score').' predicted across '.pcCount(array_sum(array_column($calls,'n')),'prediction').'.').'</li></ul>';
  $ids=implode(',',array_map(fn($f)=>(int)$f['id'],$done));
  $top=$db->query("SELECT u.display_name,COALESCE(u.is_bot,0) is_bot,COALESCE(u.bot_grade,5) grade,SUM(p.points) pts FROM predictions p JOIN users u ON u.id=p.user_id WHERE p.fixture_id IN ($ids) AND p.points IS NOT NULL GROUP BY u.id HAVING pts>0 ORDER BY pts DESC,u.is_bot,u.id LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
  if($top){$names=array_map(fn($t)=>$t['display_name'].($t['is_bot']?' (SIM'.max(1,min(5,(int)$t['grade'])).' bot)':'').' '.$t['pts'].' pts',$top);echo '<p>'.e('Top scorers this week: '.implode(', ',$names).'.').'</p>';}
  echo '<p class="note">Includes predictions from real players and our clearly labelled SIM bots.</p>';
 }
 echo '</section>';
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
 echo '<div class="columns"><div><h3>'.e($match['home']).' at home</h3><p>'.e(formSummary(priorResults($db,$match['home'],$match['kickoff_utc'],'home'),$match['home'],'home')).'</p></div><div><h3>'.e($match['away']).' away</h3><p>'.e(formSummary(priorResults($db,$match['away'],$match['kickoff_utc'],'away'),$match['away'],'away')).'</p></div></div>';
 $q=$db->prepare("SELECT * FROM fixtures WHERE ((home=? AND away=?) OR (home=? AND away=?)) AND julianday(kickoff_utc)<julianday(?) AND status IN ('FT','AET','PEN') AND home_score IS NOT NULL AND away_score IS NOT NULL ORDER BY julianday(kickoff_utc) DESC LIMIT 5");$q->execute([$match['home'],$match['away'],$match['away'],$match['home'],$match['kickoff_utc']]);$meetings=$q->fetchAll(PDO::FETCH_ASSOC);
 echo '<h3>Previous meetings in our records</h3>';if($meetings)fixtureRows($meetings);else echo '<p>No earlier completed meeting is recorded for this season.</p>';
 }
 if(!$compact)echo '<p class="note">Summaries use up to five completed league matches in our records before this fixture. Missing results can limit the sample.</p>';
 if($compact){echo '<p>'.e(uk($match['kickoff_utc'])).' UK';if($match['home_score']!==null&&$match['away_score']!==null)echo ' · Result: '.e($match['home_score'].'–'.$match['away_score']);echo '</p><a href="'.matchLink($match).'" class="compactlink">Match details <span aria-hidden="true">→</span></a>';}
 echo '</section>';
}
