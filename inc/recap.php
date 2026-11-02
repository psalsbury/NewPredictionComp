<?php
declare(strict_types=1);
require_once __DIR__.'/listing.php';
function recapPoints(array $r):int{
 return pc_points((int)$r['home_score'],(int)$r['away_score'],(int)$r['actual_home'],(int)$r['actual_away']);
}
function recapPrice(array $r):?float{
 $k=strtotime($r['kickoff_utc']);$o=!empty($r['observed_at'])?strtotime($r['observed_at']):false;$f=!empty($r['fetched_at'])?strtotime($r['fetched_at']):false;
 $key=(int)$r['home_score']>(int)$r['away_score']?'price_home':((int)$r['home_score']<(int)$r['away_score']?'price_away':'price_draw');
 $p=(float)($r[$key]??0);
 return $k!==false&&$o!==false&&$f!==false&&$o<$k&&$f<$k&&is_finite($p)&&$p>1?$p:null;
}
function pcScorePrices(PDO $db,array $ids):array{
 if(!$ids||!(bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='fixture_score_odds'")->fetchColumn())return [];
 $out=[];foreach($db->query('SELECT s.fixture_id,s.home_goals,s.away_goals,s.price FROM fixture_score_odds s JOIN fixtures f ON f.id=s.fixture_id WHERE s.fixture_id IN ('.implode(',',array_map('intval',$ids)).') AND julianday(s.observed_at)<julianday(f.kickoff_utc)') as $r)$out[(int)$r['fixture_id']][$r['home_goals'].'-'.$r['away_goals']]=(float)$r['price'];
 return $out;
}
function recapData(PDO $db,array $ids):array{
 $s=['players'=>[],'matches'=>[],'picks'=>0,'correct'=>0,'exact'=>0,'best'=>[],'rare'=>[],'exacts'=>[],'top'=>[],'table'=>[],'sharp'=>[],'sharpMin'=>3,'hard'=>[],'sim'=>null,'crowd'=>['called'=>0,'right'=>0]];
 if(!$ids)return $s;
 $s['sharpMin']=max(3,(int)ceil(count($ids)/4));
 $ids=implode(',',array_map('intval',$ids));
 $odds=(bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='fixture_odds'")->fetchColumn();
 $prices=$odds?'o.home price_home,o.draw price_draw,o.away price_away,o.observed_at,o.fetched_at':'NULL price_home,NULL price_draw,NULL price_away,NULL observed_at,NULL fetched_at';
 $join=$odds?'LEFT JOIN fixture_odds o ON o.fixture_id=f.id':'';
 $rows=$db->query("SELECT u.id user_id,u.display_name,f.id,f.home,f.away,f.kickoff_utc,f.home_score actual_home,f.away_score actual_away,p.home_score,p.away_score,p.joker,$prices FROM predictions p JOIN users u ON u.id=p.user_id JOIN fixtures f ON f.id=p.fixture_id $join WHERE f.id IN ($ids) AND COALESCE(u.is_bot,0)=0 AND ".pc_listed_sql()." AND f.status IN ('FT','AET','PEN') AND f.home_score IS NOT NULL AND f.away_score IS NOT NULL AND julianday(f.kickoff_utc)<=julianday('now') ORDER BY julianday(f.kickoff_utc),f.id,u.id")->fetchAll(PDO::FETCH_ASSOC);
 // SIM1 average points per match, for the humans-v-bots comparison.
 $simAvg=[];
 foreach($db->query("SELECT f.id,f.home_score actual_home,f.away_score actual_away,p.home_score,p.away_score FROM predictions p JOIN users u ON u.id=p.user_id JOIN fixtures f ON f.id=p.fixture_id WHERE f.id IN ($ids) AND u.is_bot=1 AND u.bot_grade=1") as $b){$simAvg[(int)$b['id']][]=recapPoints($b);}
 foreach($simAvg as $fid=>$list)$simAvg[$fid]=array_sum($list)/count($list);
 $csPrices=pcScorePrices($db,array_map('intval',explode(',',$ids)));
 $bestPrice=0;$exacts=[];$sim=['human'=>0,'bot'=>0,'picks'=>0];$calls=[];
 foreach($rows as $r){
  $uid=(int)$r['user_id'];$fid=(int)$r['id'];$pts=recapPoints($r);
  if(!isset($s['players'][$uid]))$s['players'][$uid]=['name'=>$r['display_name'],'points'=>0,'picks'=>0,'correct'=>0,'exact'=>0,'simPicks'=>0,'simDiff'=>0.0];
  $p=&$s['players'][$uid];$p['points']+=pc_points((int)$r['home_score'],(int)$r['away_score'],(int)$r['actual_home'],(int)$r['actual_away'],!empty($r['joker'])); // table = competition points (joker doubles); other stats use raw 5/3/0
  $p['picks']++;$p['correct']+=(int)($pts>0);$p['exact']+=(int)($pts===5);
  if(isset($simAvg[$fid])){$p['simPicks']++;$p['simDiff']+=$pts-$simAvg[$fid];$sim['human']+=$pts;$sim['bot']+=$simAvg[$fid];$sim['picks']++;}
  unset($p);
  if(!isset($s['matches'][$fid]))$s['matches'][$fid]=['fixture'=>$r,'picks'=>0,'correct'=>0,'exact'=>0];
  $s['matches'][$fid]['picks']++;$s['matches'][$fid]['correct']+=(int)($pts>0);$s['matches'][$fid]['exact']+=(int)($pts===5);
  $s['picks']++;$s['correct']+=(int)($pts>0);$s['exact']+=(int)($pts===5);
  $calls[$fid][(int)$r['home_score']<=>(int)$r['away_score']]=($calls[$fid][(int)$r['home_score']<=>(int)$r['away_score']]??0)+1;
  $price=recapPrice($r);
  if($pts===5){$r['price']=$price;$r['cs']=$csPrices[$fid][$r['home_score'].'-'.$r['away_score']]??null;$exacts[$fid][]=$r;}
  if($pts>0&&$price!==null){
   $r['price']=$price;
   if($price>$bestPrice){$bestPrice=$price;$s['best']=[$r];}elseif($price===$bestPrice)$s['best'][]=$r;
  }
 }
 if($s['players']){
  $table=array_values($s['players']);
  usort($table,fn($a,$b)=>[$b['points'],$b['exact'],$a['picks'],strtolower($a['name'])]<=>[$a['points'],$a['exact'],$b['picks'],strtolower($b['name'])]);
  $rank=0;$prev=null;foreach($table as $i=>&$t){$key=[$t['points'],$t['exact']];if($key!==$prev)$rank=$i+1;$t['rank']=$rank;$prev=$key;}unset($t);
  $s['table']=$table;
  $max=max(array_column($s['players'],'points'));
  if($max>0)$s['top']=array_values(array_filter($table,fn($p)=>$p['points']===$max));
  $qualified=array_filter($s['players'],fn($p)=>$p['picks']>=$s['sharpMin']&&$p['points']>0);
  if($qualified){$rate=max(array_map(fn($p)=>$p['points']/$p['picks'],$qualified));$s['sharp']=array_values(array_filter($qualified,fn($p)=>abs($p['points']/$p['picks']-$rate)<1e-9));}
 }
 if($sim['picks']){
  $beat=array_filter($s['players'],fn($p)=>$p['simPicks']>0&&$p['simDiff']>1e-9);
  usort($beat,fn($a,$b)=>$b['simDiff']<=>$a['simDiff']);
  $s['sim']=['human'=>$sim['human']/$sim['picks'],'bot'=>$sim['bot']/$sim['picks'],'picks'=>$sim['picks'],'beat'=>$beat,'players'=>count(array_filter($s['players'],fn($p)=>$p['simPicks']>0))];
 }
 // Crowd call: the single most popular result among real players (needs 2+ picks and no tie for top).
 foreach($calls as $fid=>$c){if(array_sum($c)<2)continue;arsort($c);$top=array_keys($c);if(count($c)>1&&$c[$top[0]]===$c[$top[1]])continue;
  $f=$s['matches'][$fid]['fixture'];$s['crowd']['called']++;$s['crowd']['right']+=(int)($top[0]===((int)$f['actual_home']<=>(int)$f['actual_away']));}
 // Every exact score gets credit; rarest first (lowest share of real players on that match who nailed it), then longest odds.
 foreach($exacts as $fid=>$entries){$total=$s['matches'][$fid]['picks'];$s['exacts'][]=['entries'=>$entries,'total'=>$total,'share'=>count($entries)/$total,'price'=>$entries[0]['price']??null,'cs'=>$entries[0]['cs']??null];}
 usort($s['exacts'],fn($a,$b)=>[$a['share'],-($a['cs']??0),-($a['price']??0),-$a['total']]<=>[$b['share'],-($b['cs']??0),-($b['price']??0),-$b['total']]);
 if($s['exacts']){$min=$s['exacts'][0]['share'];$s['rare']=array_values(array_filter($s['exacts'],fn($e)=>abs($e['share']-$min)<1e-9));}
 $qualified=array_filter($s['matches'],fn($m)=>$m['picks']>=3);
 if($qualified){$rate=min(array_map(fn($m)=>$m['correct']/$m['picks'],$qualified));$s['hard']=array_values(array_filter($qualified,fn($m)=>abs($m['correct']/$m['picks']-$rate)<1e-9));usort($s['hard'],fn($a,$b)=>$b['picks']<=>$a['picks']);}
 return $s;
}
function recapLinks():string{
 return '<section class="recaplinks" aria-labelledby="recapLinksTitle"><div class="recaplinks-heading"><h2 id="recapLinksTitle">Real-player recaps</h2><p>Standout predictions, points leaders and the toughest matches.</p></div><div class="recaplinks-grid"><a class="recaplinks-card" href="/recap?period=week"><span class="recaplinks-copy"><strong>Weekly recap</strong><span>Highlights from the latest gameweek</span></span><span class="recaplinks-arrow" aria-hidden="true">→</span></a><a class="recaplinks-card" href="/recap?period=month"><span class="recaplinks-copy"><strong>Monthly recap</strong><span>Highlights from the current month</span></span><span class="recaplinks-arrow" aria-hidden="true">→</span></a></div></section>';
}
