<?php
declare(strict_types=1);
require_once __DIR__.'/listing.php';
// Correct-score market helpers. Prices come from fixture_score_odds (odds_sync.sync_score_odds),
// taken before kick-off. Probabilities are the implied 1/price values normalised to sum to 1.
// Expected competition points for a pick = 3·P(that result) + 2·P(that exact score).

function pcScoreModels(PDO $db,array $ids,bool $preKickoffOnly=true):array{
 static $cache=[];$ids=array_values(array_unique(array_map('intval',$ids)));$need=array_values(array_diff($ids,array_keys($cache)));
 if($need){
  foreach($need as $id)$cache[$id]=null;
  if((bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='fixture_score_odds'")->fetchColumn()){
   $raw=[];
   foreach($db->query('SELECT s.fixture_id,s.home_goals,s.away_goals,s.price,s.bookmaker,s.observed_at,f.kickoff_utc FROM fixture_score_odds s JOIN fixtures f ON f.id=s.fixture_id WHERE s.fixture_id IN ('.implode(',',$need).')') as $r){
    if($preKickoffOnly&&strtotime($r['observed_at'])>=strtotime($r['kickoff_utc']))continue;
    $fid=(int)$r['fixture_id'];$raw[$fid]['prices'][(int)$r['home_goals'].'-'.(int)$r['away_goals']]=(float)$r['price'];$raw[$fid]['bookmaker']=$r['bookmaker'];$raw[$fid]['observed_at']=$r['observed_at'];
   }
   // Finished matches pruned by bin/prune_odds.py keep only the needed rows plus a summary of the full market.
   if($raw&&(bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='fixture_score_summary'")->fetchColumn())
    foreach($db->query('SELECT * FROM fixture_score_summary WHERE fixture_id IN ('.implode(',',array_keys($raw)).')') as $r)$raw[(int)$r['fixture_id']]['summary']=$r;
   foreach($raw as $fid=>$m)$cache[$fid]=pcScoreModelBuild($m);
  }
 }
 $out=[];foreach($ids as $id)if($cache[$id]!==null)$out[$id]=$cache[$id];return $out;
}
function pcScoreModel(PDO $db,int $id):?array{return pcScoreModels($db,[$id])[$id]??null;}

function pcScoreModelBuild(array $m):array{
 $sm=$m['summary']??null;
 $inv=[];foreach($m['prices'] as $k=>$p)if($p>1)$inv[$k]=1/$p;
 $sum=$sm?(float)$sm['inv_total']:array_sum($inv);$probs=[];foreach($inv as $k=>$v)$probs[$k]=$v/$sum;
 arsort($probs);
 if($sm)$res=[1=>(float)$sm['p_home'],0=>(float)$sm['p_draw'],-1=>(float)$sm['p_away']];
 else{$res=[1=>0.0,0=>0.0,-1=>0.0];foreach($probs as $k=>$p){[$h,$a]=array_map('intval',explode('-',$k));$res[$h<=>$a]+=$p;}}
 if($sm)$ranks=json_decode((string)$sm['ranks'],true)?:[];else{$ranks=[];$i=0;foreach($probs as $k=>$p)$ranks[$k]=++$i;}
 $ev=[];foreach($probs as $k=>$p){[$h,$a]=array_map('intval',explode('-',$k));$ev[$k]=3*$res[$h<=>$a]+2*$p;}
 arsort($ev);
 return ['probs'=>$probs,'prices'=>$m['prices'],'results'=>$res,'ev'=>$ev,'fav'=>array_key_first($probs),'best'=>array_key_first($ev),'bookmaker'=>$m['bookmaker'],'observed_at'=>$m['observed_at'],'ranks'=>$ranks,'scorelines'=>$sm?(int)$sm['scorelines']:count($probs)];
}
function pcScoreProb(array $model,int $h,int $a):float{return $model['probs'][$h.'-'.$a]??0.0;}
function pcScoreEv(array $model,int $h,int $a):float{return 3*$model['results'][$h<=>$a]+2*pcScoreProb($model,$h,$a);}
function pcOneIn(float $p):string{if($p<=0)return 'a very long shot';$n=1/$p;return '1 in '.($n<10?number_format($n,1):number_format(round($n)));}
function pcScoreLabel(string $k):string{return str_replace('-','–',$k);}

// Before kick-off: likely scores + the player's pick chance and expected points (updated live by /assets/score-chance.js).
function pcScoreChanceHtml(PDO $db,int $fid,?array $pick):string{
 $m=pcScoreModel($db,$fid);if(!$m)return '';
 $top=array_slice($m['probs'],0,3,true);$data=[];foreach($m['probs'] as $k=>$p)if($p>0)$data[$k]=round($p,5);
 $parts=[];foreach($top as $k=>$p)$parts[]='<b>'.pcScoreLabel($k).'</b> '.round(100*$p).'%';
 $h='<div class="scorechance" data-cs="'.htmlspecialchars(json_encode(['p'=>$data,'r'=>[round($m['results'][1],4),round($m['results'][0],4),round($m['results'][-1],4)],'best'=>$m['best']]),ENT_QUOTES).'"'.($pick!==null?' data-pick="'.(int)$pick[0].'-'.(int)$pick[1].'"':'').'><span class="cslikely">Most likely: '.implode(' · ',$parts).'</span><span class="cspick">';
 if($pick!==null)$h.=pcPickLine($m,(int)$pick[0],(int)$pick[1]);
 return $h.'</span></div>';
}
function pcPickLine(array $m,int $h,int $a):string{
 $p=pcScoreProb($m,$h,$a);$ev=pcScoreEv($m,$h,$a);$best=$m['best'];$bev=$m['ev'][$best];
 $s='Your '.$h.'–'.$a.': '.pcOneIn($p).' · '.number_format($ev,2).' expected pts';
 if($best!==$h.'-'.$a)$s.=' <small>(best: '.pcScoreLabel($best).' '.number_format($bev,2).')</small>';else $s.=' <small>(the best pick)</small>';
 return $s;
}

// After matches: the Bookie (always the market's favourite score), favourite hit rates,
// expected v actual points per real player, and "called it" exact scores by price.
function pcMarketStats(PDO $db,array $ids):array{
 $st=['matches'=>0,'bookie'=>['points'=>0,'exact'=>0,'correct'=>0],'sim'=>['points'=>0.0,'n'=>0],'humans'=>['points'=>0,'n'=>0],'fav'=>['bookie'=>0,'sim'=>0,'simN'=>0,'crowd'=>0,'crowdN'=>0],'players'=>[],'called'=>[]];
 $ids=array_values(array_filter(array_map('intval',$ids)));if(!$ids)return $st;
 $models=pcScoreModels($db,$ids);if(!$models)return $st;
 $in=implode(',',array_keys($models));
 $fx=[];foreach($db->query("SELECT id,home,away,home_score,away_score,kickoff_utc FROM fixtures WHERE id IN ($in) AND status IN ('FT','AET','PEN') AND home_score IS NOT NULL AND away_score IS NOT NULL AND julianday(kickoff_utc)<=julianday('now')") as $f)$fx[(int)$f['id']]=$f;
 if(!$fx)return $st;$in=implode(',',array_keys($fx));
 $pts=fn(int $h,int $a,int $x,int $y)=>pc_points($h,$a,$x,$y);
 $simPicks=[];foreach($db->query("SELECT p.fixture_id,p.home_score,p.away_score FROM predictions p JOIN users u ON u.id=p.user_id WHERE p.fixture_id IN ($in) AND u.is_bot=1 AND u.bot_grade=1") as $r)$simPicks[(int)$r['fixture_id']][]=[(int)$r['home_score'],(int)$r['away_score']];
 $hum=$db->query("SELECT u.id,u.display_name,p.fixture_id,p.home_score,p.away_score FROM predictions p JOIN users u ON u.id=p.user_id WHERE p.fixture_id IN ($in) AND COALESCE(u.is_bot,0)=0 AND ".pc_listed_sql())->fetchAll(PDO::FETCH_ASSOC);
 $crowd=[];
 foreach($hum as $r){$fid=(int)$r['fixture_id'];$f=$fx[$fid];$m=$models[$fid];$h=(int)$r['home_score'];$a=(int)$r['away_score'];$x=(int)$f['home_score'];$y=(int)$f['away_score'];$p=$pts($h,$a,$x,$y);
  $id=(int)$r['id'];if(!isset($st['players'][$id]))$st['players'][$id]=['name'=>$r['display_name'],'n'=>0,'points'=>0,'expected'=>0.0];
  $st['players'][$id]['n']++;$st['players'][$id]['points']+=$p;$st['players'][$id]['expected']+=pcScoreEv($m,$h,$a);
  $st['humans']['points']+=$p;$st['humans']['n']++;$crowd[$fid][$h.'-'.$a]=($crowd[$fid][$h.'-'.$a]??0)+1;
  if($p===5){$price=$m['prices'][$h.'-'.$a]??null;if($price!==null)$st['called'][]=['name'=>$r['display_name'],'fixture'=>$f,'price'=>$price,'prob'=>pcScoreProb($m,$h,$a)];}
 }
 foreach($fx as $fid=>$f){$m=$models[$fid];$x=(int)$f['home_score'];$y=(int)$f['away_score'];$st['matches']++;
  [$bh,$ba]=array_map('intval',explode('-',$m['fav']));$bp=$pts($bh,$ba,$x,$y);$st['bookie']['points']+=$bp;$st['bookie']['exact']+=(int)($bp===5);$st['bookie']['correct']+=(int)($bp>0);$st['fav']['bookie']+=(int)($bp===5);
  if(!empty($simPicks[$fid])){$sum=0;$lines=[];foreach($simPicks[$fid] as [$h,$a]){$sum+=$pts($h,$a,$x,$y);$lines[$h.'-'.$a]=($lines[$h.'-'.$a]??0)+1;}$st['sim']['points']+=$sum/count($simPicks[$fid]);$st['sim']['n']++;arsort($lines);$st['fav']['simN']++;$st['fav']['sim']+=(int)(array_key_first($lines)===$x.'-'.$y);}
  if(!empty($crowd[$fid])&&array_sum($crowd[$fid])>=2){$c=$crowd[$fid];arsort($c);$k=array_keys($c);if(count($c)===1||$c[$k[0]]>$c[$k[1]]){$st['fav']['crowdN']++;$st['fav']['crowd']+=(int)($k[0]===$x.'-'.$y);}}
 }
 usort($st['called'],fn($a,$b)=>$b['price']<=>$a['price']);
 foreach($st['players'] as &$p)$p['luck']=$p['points']-$p['expected'];unset($p);
 return $st;
}
function pcSeasonFixtureIds(PDO $db):array{
 $y=(int)date('n')>=7?(int)date('Y'):(int)date('Y')-1;
 $q=$db->prepare("SELECT id FROM fixtures WHERE julianday(kickoff_utc)>=julianday(?) AND julianday(kickoff_utc)<julianday(?)");$q->execute([$y.'-07-01',($y+1).'-07-01']);
 return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
}

// Match page panel: likely scores before kick-off; how likely the result was afterwards.
function pcScoreOddsPanel(PDO $db,array $match):string{
 $m=pcScoreModel($db,(int)$match['id']);if(!$m)return '';
 $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
 $done=in_array($match['status'],['FT','AET','PEN'],true)&&$match['home_score']!==null&&$match['away_score']!==null;
 $h='<section class="panel scoreoddspanel"><h2>'.($done?'How likely was that score?':'Most likely scores').'</h2>';
 if($done){$x=(int)$match['home_score'];$y=(int)$match['away_score'];$p=pcScoreProb($m,$x,$y);$rank=isset($m['ranks'][$x.'-'.$y])?$m['ranks'][$x.'-'.$y]-1:false;
  $h.='<p>The bookmakers made <strong>'.$x.'–'.$y.'</strong> '.($p>0?'a <strong>'.pcOneIn($p).'</strong> chance ('.number_format(100*$p,1).'%)'.($rank!==false?', the '.pcScoreOrdinal($rank+1).' most likely score':''):'too unlikely to price').'. Their favourite was '.pcScoreLabel($m['fav']).'.</p>';}
 $h.='<table class="scoreoddstable"><thead><tr><th>Score</th><th>Chance</th><th>Expected pts</th></tr></thead><tbody>';
 foreach(array_slice($m['probs'],0,6,true) as $k=>$p){[$a,$b]=array_map('intval',explode('-',$k));$h.='<tr'.($done&&$k===$match['home_score'].'-'.$match['away_score']?' class="hit"':'').'><td><b>'.pcScoreLabel($k).'</b></td><td>'.number_format(100*$p,1).'% <small>('.pcOneIn($p).')</small></td><td>'.number_format(pcScoreEv($m,$a,$b),2).'</td></tr>';}
 $h.='</tbody></table><p class="note">Best pick for points: <strong>'.pcScoreLabel($m['best']).'</strong> ('.number_format($m['ev'][$m['best']],2).' expected points). Expected points = 3 × chance of the result + 2 × chance of the exact score. Chances come from '.$e($m['bookmaker']).' correct-score prices taken before kick-off, with the bookmaker margin removed.</p></section>';
 return $h;
}
function pcScoreOrdinal(int $n):string{$s=['th','st','nd','rd'];$v=$n%100;return $n.($s[($v-20)%10]??$s[$v]??$s[0]);}
