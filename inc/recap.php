<?php
declare(strict_types=1);
require_once __DIR__.'/listing.php';
function recapPoints(array $r):int{
 return (int)$r['home_score']===(int)$r['actual_home']&&(int)$r['away_score']===(int)$r['actual_away']?5:(((int)$r['home_score']<=>(int)$r['away_score'])===((int)$r['actual_home']<=>(int)$r['actual_away'])?3:0);
}
function recapPrice(array $r):?float{
 $k=strtotime($r['kickoff_utc']);$o=!empty($r['observed_at'])?strtotime($r['observed_at']):false;$f=!empty($r['fetched_at'])?strtotime($r['fetched_at']):false;
 $key=(int)$r['home_score']>(int)$r['away_score']?'price_home':((int)$r['home_score']<(int)$r['away_score']?'price_away':'price_draw');
 $p=(float)($r[$key]??0);
 return $k!==false&&$o!==false&&$f!==false&&$o<$k&&$f<$k&&is_finite($p)&&$p>1?$p:null;
}
function recapData(PDO $db,array $ids):array{
 $s=['players'=>[],'matches'=>[],'picks'=>0,'correct'=>0,'exact'=>0,'best'=>[],'rare'=>[],'top'=>[],'sharp'=>[],'hard'=>[]];
 if(!$ids)return $s;
 $ids=implode(',',array_map('intval',$ids));
 $odds=(bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='fixture_odds'")->fetchColumn();
 $prices=$odds?'o.home price_home,o.draw price_draw,o.away price_away,o.observed_at,o.fetched_at':'NULL price_home,NULL price_draw,NULL price_away,NULL observed_at,NULL fetched_at';
 $join=$odds?'LEFT JOIN fixture_odds o ON o.fixture_id=f.id':'';
 $rows=$db->query("SELECT u.id user_id,u.display_name,f.id,f.home,f.away,f.kickoff_utc,f.home_score actual_home,f.away_score actual_away,p.home_score,p.away_score,$prices FROM predictions p JOIN users u ON u.id=p.user_id JOIN fixtures f ON f.id=p.fixture_id $join WHERE f.id IN ($ids) AND COALESCE(u.is_bot,0)=0 AND ".pc_listed_sql()." AND f.status IN ('FT','AET','PEN') AND f.home_score IS NOT NULL AND f.away_score IS NOT NULL AND julianday(f.kickoff_utc)<=julianday('now') ORDER BY julianday(f.kickoff_utc),f.id,u.id")->fetchAll(PDO::FETCH_ASSOC);
 $bestPrice=0;$exacts=[];
 foreach($rows as $r){
  $uid=(int)$r['user_id'];$fid=(int)$r['id'];$pts=recapPoints($r);
  if(!isset($s['players'][$uid]))$s['players'][$uid]=['name'=>$r['display_name'],'points'=>0,'picks'=>0,'correct'=>0,'exact'=>0];
  $p=&$s['players'][$uid];$p['points']+=$pts;$p['picks']++;$p['correct']+=(int)($pts>0);$p['exact']+=(int)($pts===5);unset($p);
  if(!isset($s['matches'][$fid]))$s['matches'][$fid]=['fixture'=>$r,'picks'=>0,'correct'=>0,'exact'=>0];
  $s['matches'][$fid]['picks']++;$s['matches'][$fid]['correct']+=(int)($pts>0);$s['matches'][$fid]['exact']+=(int)($pts===5);
  $s['picks']++;$s['correct']+=(int)($pts>0);$s['exact']+=(int)($pts===5);
  if($pts===5)$exacts[$fid][]=$r;
  $price=recapPrice($r);
  if($pts>0&&$price!==null){
   $r['price']=$price;
   if($price>$bestPrice){$bestPrice=$price;$s['best']=[$r];}elseif($price===$bestPrice)$s['best'][]=$r;
  }
 }
 if($s['players']){
  $max=max(array_column($s['players'],'points'));
  $s['top']=array_values(array_filter($s['players'],fn($p)=>$p['points']===$max));
  $qualified=array_filter($s['players'],fn($p)=>$p['picks']>=3);
  if($qualified){$rate=max(array_map(fn($p)=>$p['points']/$p['picks'],$qualified));$s['sharp']=array_values(array_filter($qualified,fn($p)=>abs($p['points']/$p['picks']-$rate)<1e-9));}
 }
 // Rarity = the fraction of eligible real players on that match who nailed it.
 $rarity=null;
 foreach($exacts as $fid=>$entries){$rate=count($entries)/$s['matches'][$fid]['picks'];
  if($rarity===null||$rate<$rarity-1e-9){$rarity=$rate;$s['rare']=[['entries'=>$entries,'total'=>$s['matches'][$fid]['picks']]];}
  elseif(abs($rate-$rarity)<1e-9)$s['rare'][]=['entries'=>$entries,'total'=>$s['matches'][$fid]['picks']];
 }
 $qualified=array_filter($s['matches'],fn($m)=>$m['picks']>=3);
 if($qualified){$rate=min(array_map(fn($m)=>$m['correct']/$m['picks'],$qualified));$s['hard']=array_values(array_filter($qualified,fn($m)=>abs($m['correct']/$m['picks']-$rate)<1e-9));}
 return $s;
}
function recapLinks():string{
 return '<section class="performancepanel"><h2>Real-player recaps</h2><p>Who made the best call? Discover the standout predictions, points leaders and toughest matches.</p><p><a class="btn" href="/recap?period=week">Weekly recap →</a> <a class="ghost" href="/recap?period=month">This month’s recap →</a></p></section>';
}
