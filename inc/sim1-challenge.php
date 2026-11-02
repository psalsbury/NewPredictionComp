<?php
declare(strict_types=1);
// "Can you beat SIM1?" page: real players v the five SIM1 (expert) bots, judged match by match.
// The benchmark on each match is the SIM1 average, so players are only compared on matches they predicted.
require_once __DIR__.'/listing.php';

function sim1ChallengeData(PDO $db,array $rows):?array{
 $done=[];foreach($rows as $f)if(pcIsFinished($f)&&strtotime($f['kickoff_utc'])<=time()&&pcIsScored($db,(int)$f['id']))$done[(int)$f['id']]=$f;
 if(!$done)return null;
 $ids=implode(',',array_keys($done));
 $bots=$db->query("SELECT u.id,u.display_name,p.fixture_id,p.home_score,p.away_score,p.points FROM predictions p JOIN users u ON u.id=p.user_id WHERE p.fixture_id IN ($ids) AND u.is_bot=1 AND u.bot_grade=1 AND p.points IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
 if(!$bots)return null;
 $sim=[];$squad=[];$scorelines=[];$simPicks=0;$simDraws=0;
 foreach($bots as $b){$fid=(int)$b['fixture_id'];$pts=(int)$b['points'];
  $sim[$fid]['pts'][]=$pts;$line=$b['home_score'].'–'.$b['away_score'];$sim[$fid]['lines'][$line]=($sim[$fid]['lines'][$line]??0)+1;
  $id=(int)$b['id'];if(!isset($squad[$id]))$squad[$id]=['name'=>$b['display_name'],'points'=>0,'exact'=>0,'correct'=>0,'picks'=>0];
  $squad[$id]['points']+=$pts;$squad[$id]['exact']+=(int)($pts===5);$squad[$id]['correct']+=(int)($pts>0);$squad[$id]['picks']++;
  $scorelines[$line]=($scorelines[$line]??0)+1;$simPicks++;$simDraws+=(int)((int)$b['home_score']===(int)$b['away_score']);
 }
 foreach($sim as $fid=>&$m){$m['avg']=array_sum($m['pts'])/count($m['pts']);$m['max']=max($m['pts']);arsort($m['lines']);$m['pick']=array_key_first($m['lines']);}unset($m);
 usort($squad,fn($a,$b)=>[$b['points'],$b['exact']]<=>[$a['points'],$a['exact']]);
 arsort($scorelines);
 $humans=$db->query("SELECT u.id,u.display_name,p.fixture_id,p.home_score,p.away_score,p.points FROM predictions p JOIN users u ON u.id=p.user_id WHERE p.fixture_id IN ($ids) AND COALESCE(u.is_bot,0)=0 AND p.points IS NOT NULL AND ".pc_listed_sql()." ORDER BY p.fixture_id,u.id")->fetchAll(PDO::FETCH_ASSOC);
 $players=[];$match=[];$giant=[];$hTotal=0;$sTotal=0.0;$cmp=0;
 foreach($humans as $h){$fid=(int)$h['fixture_id'];if(!isset($sim[$fid]))continue;$pts=(int)$h['points'];$avg=$sim[$fid]['avg'];
  $id=(int)$h['id'];if(!isset($players[$id]))$players[$id]=['name'=>$h['display_name'],'picks'=>0,'points'=>0,'sim'=>0.0,'w'=>0,'d'=>0,'l'=>0];
  $p=&$players[$id];$p['picks']++;$p['points']+=$pts;$p['sim']+=$avg;
  if($pts>$avg+1e-9)$p['w']++;elseif($pts<$avg-1e-9)$p['l']++;else $p['d']++;unset($p);
  $match[$fid]['h'][]=$pts;$hTotal+=$pts;$sTotal+=$avg;$cmp++;
  if($pts>0&&$pts>$sim[$fid]['max'])$giant[]=['name'=>$h['display_name'],'fixture'=>$done[$fid],'pred'=>$h['home_score'].'–'.$h['away_score'],'pts'=>$pts,'simPick'=>$sim[$fid]['pick'],'simMax'=>$sim[$fid]['max']];
 }
 // Match-by-match scoreboard: the average real player v the average SIM1 bot on each match.
 $board=['humans'=>0,'level'=>0,'sim'=>0];
 foreach($match as $fid=>$m){$avg=array_sum($m['h'])/count($m['h']);$board[$avg>$sim[$fid]['avg']+1e-9?'humans':($avg<$sim[$fid]['avg']-1e-9?'sim':'level')]++;}
 foreach($players as &$p){$p['diff']=$p['points']-$p['sim'];$p['rate']=$p['diff']/$p['picks'];}unset($p);
 $players=array_values($players);
 usort($players,fn($a,$b)=>[$b['diff'],$b['points']]<=>[$a['diff'],$a['points']]);
 usort($giant,fn($a,$b)=>[$b['pts'],$b['fixture']['kickoff_utc']]<=>[$a['pts'],$a['fixture']['kickoff_utc']]);
 // Blind spots: matches where no SIM1 bot scored; how did real players do?
 $blind=[];foreach($sim as $fid=>$m)if($m['max']===0){$h=$match[$fid]['h']??[];$blind[]=['fixture'=>$done[$fid],'pick'=>$m['pick'],'picks'=>count($h),'right'=>count(array_filter($h,fn($x)=>$x>0))];}
 usort($blind,fn($a,$b)=>[$b['right'],$b['picks']]<=>[$a['right'],$a['picks']]);
 $draws=count(array_filter($done,fn($f)=>(int)$f['home_score']===(int)$f['away_score']));
 return ['matches'=>count($done),'squad'=>$squad,'players'=>$players,'board'=>$board,'hAvg'=>$cmp?$hTotal/$cmp:0,'sAvg'=>$cmp?$sTotal/$cmp:0,'giant'=>$giant,'blind'=>$blind,'scorelines'=>array_slice($scorelines,0,3,true),'simPicks'=>$simPicks,'simDrawPct'=>round(100*$simDraws/$simPicks),'drawPct'=>round(100*$draws/count($done)),'simExactPct'=>round(100*array_sum(array_column($squad,'exact'))/$simPicks),'simCorrectPct'=>round(100*array_sum(array_column($squad,'correct'))/$simPicks)];
}

function renderSim1Challenge(PDO $db,array $rows,string $label,bool $ongoing):string{
 $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
 $fx=fn($f)=>'<a href="/fixtures/'.(int)$f['id'].'">'.$e($f['home'].' '.$f['home_score'].'–'.$f['away_score'].' '.$f['away']).'</a>';
 $d=sim1ChallengeData($db,$rows);
 if(!$d)return '<section class="panel beatsim1"><h2>Players v SIM1</h2><p>No comparison is available for this period yet. It will appear once matches have finished and SIM1 points have been recorded.</p></section>';
 $b=$d['board'];$played=$b['humans']+$b['level']+$b['sim'];
 $h='<section class="s1hero"><span class="s1kicker">'.$e($label).($ongoing?' · so far':'').'</span><div class="s1score"><div><b>'.$b['humans'].'</b><span>Humans</span></div><i>–</i><div><b>'.$b['sim'].'</b><span>SIM1</span></div></div>';
 if($played){
  $h.='<p>Matches won, judged on the average real player against the average SIM1 bot'.($b['level']?' · '.$b['level'].' level':'').'.</p>';
  $h.='<p class="s1avg">Points per prediction: <strong>Humans '.number_format($d['hAvg'],2).'</strong> · <strong>SIM1 '.number_format($d['sAvg'],2).'</strong></p>';
 }else $h.='<p>No real players predicted these matches yet.</p>';
 $h.='</section>';
 $beaters=array_values(array_filter($d['players'],fn($p)=>$p['diff']>1e-9));
 if($beaters){$top=$beaters[0];$h.='<section class="s1star"><span class="s1kicker">Top SIM1 slayer</span><h2>'.$e($top['name']).'</h2><p>+'.number_format($top['diff'],1).' points better than SIM1 over '.$top['picks'].' match'.($top['picks']===1?'':'es').' · won '.$top['w'].', lost '.$top['l'].' match by match</p></section>';}
 if($d['players']){
  $h.='<section class="panel s1card"><h2>Players v SIM1</h2><p class="note">Each player against the average SIM1 bot on the same matches they predicted, so skipping a match never counts against you.</p><div class="s1wrap"><table class="s1table"><thead><tr><th class="l">Player</th><th>Played</th><th>Pts</th><th>SIM1</th><th>+/−</th><th class="m">W‑D‑L</th></tr></thead><tbody>';
  foreach($d['players'] as $p){$cls=$p['diff']>1e-9?'up':($p['diff']<-1e-9?'down':'');
   $h.='<tr'.($cls==='up'?' class="beat"':'').'><td class="l">'.$e($p['name']).($cls==='up'?' <span class="s1badge">Beating SIM1</span>':'').'</td><td>'.$p['picks'].'</td><td><b>'.$p['points'].'</b></td><td>'.number_format($p['sim'],1).'</td><td class="'.$cls.'">'.($p['diff']>0.05?'+':'').number_format($p['diff'],1).'</td><td class="m">'.$p['w'].'‑'.$p['d'].'‑'.$p['l'].'</td></tr>';}
  $h.='</tbody></table></div><p class="note">'.count($beaters).' of '.count($d['players']).' player'.(count($d['players'])===1?'':'s').' '.($ongoing?'are':'were').' ahead of SIM1. W‑D‑L counts matches where you scored more, the same or fewer points than the SIM1 average. New guests appear once they qualify for the global table.</p></section>';
 }
 $h.='<div class="s1grid">';
 $h.='<section class="panel s1card"><h2>Outsmarted SIM1</h2>';
 if(!$d['giant'])$h.='<p>Nobody has outscored every SIM1 bot on a match'.($ongoing?' yet':'').'.</p>';
 else{$h.='<p class="note">Predictions that beat all five SIM1 bots on the match.</p><ul class="s1list">';
  foreach(array_slice($d['giant'],0,6) as $g)$h.='<li><strong>'.$e($g['name']).'</strong> '.($g['pts']===5?'<span class="s1badge">Exact</span> ':'').'predicted '.$e($g['pred']).' · '.$fx($g['fixture']).'<br><span class="note">'.$g['pts'].' points; SIM1 went '.$e($g['simPick']).' and scored '.($g['simMax']?'at most '.$g['simMax']:'nothing').'</span></li>';
  $h.='</ul>'.(count($d['giant'])>6?'<p class="note">…and '.(count($d['giant'])-6).' more.</p>':'');}
 $h.='</section>';
 $h.='<section class="panel s1card"><h2>SIM1’s blind spots</h2>';
 if(!$d['blind'])$h.='<p>Every match so far earned at least one SIM1 bot some points.</p>';
 else{$h.='<p class="note">'.count($d['blind']).' of '.$d['matches'].' matches where no SIM1 bot scored a point.</p><ul class="s1list">';
  foreach(array_slice($d['blind'],0,4) as $m)$h.='<li>'.$fx($m['fixture']).'<br><span class="note">SIM1 mostly went '.$e($m['pick']).'. '.($m['picks']?($m['right']?$m['right'].' of '.$m['picks'].' real player'.($m['picks']===1?'':'s').' called the result':'No real player called it either'):'No real players predicted it').'.</span></li>';
  $h.='</ul>';}
 $h.='</section>';
 $lines=[];foreach($d['scorelines'] as $l=>$c)$lines[]='<strong>'.$e($l).'</strong> ('.round(100*$c/$d['simPicks']).'%)';
 $h.='<section class="panel s1card"><h2>How SIM1 plays</h2><p>Favourite scores: '.implode(', ',$lines).'.</p><p>SIM1 predicted a draw <strong>'.$d['simDrawPct'].'%</strong> of the time; <strong>'.$d['drawPct'].'%</strong> of these matches were drawn.</p><p>It called the result '.$d['simCorrectPct'].'% of the time and the exact score '.$d['simExactPct'].'%.</p><p class="note">'.($d['simDrawPct']+5<$d['drawPct']?'SIM1 rarely backs a draw — a well-timed draw is one of the best ways to beat it.':'SIM1 backs the likeliest results; the points are in the surprises it will not predict.').'</p></section>';
 $h.='<section class="panel s1card"><h2>The SIM1 squad</h2><p class="note">Five expert bots, each with slightly different picks.</p><ol class="s1squad">';
 foreach($d['squad'] as $s)$h.='<li><span>'.$e($s['name']).'</span><b>'.$s['points'].'</b><small>'.$s['exact'].' exact · '.$s['correct'].' results</small></li>';
 $h.='</ol></section></div>';
 $h.='<style>.s1hero{margin:22px 0 18px;padding:24px;border-radius:14px;border:1px solid var(--gold-line,rgba(120,215,207,.45));background:var(--gold-soft,rgba(120,215,207,.12));text-align:center}.s1kicker{font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:var(--gold,#78d7cf);font-weight:700}.s1score{display:flex;justify-content:center;align-items:center;gap:22px;margin:10px 0}.s1score b{display:block;font-size:clamp(44px,8vw,64px);line-height:1}.s1score span{font-size:14px;color:var(--text-2,#b4c7d2);text-transform:uppercase;letter-spacing:.06em}.s1score i{font-style:normal;font-size:40px;color:var(--text-3,#7d93a1)}.s1hero p{margin:6px 0;color:var(--text-2,#b4c7d2)}.s1hero .s1avg{color:var(--text,#eef5f8)}.s1star{margin:0 0 18px;padding:20px 22px;border-radius:14px;border:1px solid var(--line,#304655);background:var(--ink-1,#142230)}.s1star h2{margin:6px 0;font-size:clamp(24px,4vw,34px)}.s1star p{margin:0;color:var(--text-2,#b4c7d2)}.s1card{margin-top:0;margin-bottom:18px}.s1grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.s1grid .s1card{margin:0}.s1wrap{overflow-x:auto}.s1table{width:100%;border-collapse:collapse}.s1table th,.s1table td{padding:9px 8px;text-align:center;border-bottom:1px solid var(--line,#304655);white-space:nowrap}.s1table th{font-size:13px;color:var(--text-2,#b4c7d2);font-weight:600}.s1table .l{text-align:left}.s1table tr.beat td{background:var(--gold-soft,rgba(120,215,207,.12))}.s1table .up{color:var(--green,#46cf8f);font-weight:700}.s1table .down{color:var(--red,#f0707e)}.s1badge{display:inline-block;font-size:11px;font-weight:700;padding:2px 7px;margin-left:6px;border-radius:99px;background:var(--gold,#78d7cf);color:var(--gold-ink,#09252a);vertical-align:middle}.s1list{list-style:none;padding:0}.s1list li{padding:9px 0;border-bottom:1px solid var(--line,#304655);line-height:1.6}.s1list li:last-child{border-bottom:0}.s1squad{padding-left:20px}.s1squad li{padding:6px 0}.s1squad li span{display:inline-block;min-width:110px}.s1squad li b{margin-right:10px}.s1squad small{color:var(--text-2,#b4c7d2)}@media(max-width:650px){.s1grid{grid-template-columns:1fr}.s1table .m{display:none}.s1table th,.s1table td{padding:8px 5px}.s1table .s1badge{display:none}}</style>';
 return $h;
}
