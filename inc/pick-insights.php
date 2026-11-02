<?php
declare(strict_types=1);
// "What did everyone pick?" for matches that have kicked off (never before: picks stay private until then).
// Real players only (bots excluded). Returns [fixture_id => ['n','top'=>[[score,count]...],'mine'=>?string,'same'=>int,'result'=>int,'exact'=>int]].
function pcPickInsights(PDO $db,array $fixtureIds,int $uid=0):array{
 $ids=array_values(array_unique(array_filter(array_map('intval',$fixtureIds))));if(!$ids)return [];
 $out=[];$in=implode(',',$ids);
 $rows=$db->query("SELECT p.fixture_id,p.home_score,p.away_score,p.user_id,f.home_score ah,f.away_score aa,f.status FROM predictions p JOIN users u ON u.id=p.user_id JOIN fixtures f ON f.id=p.fixture_id
  WHERE p.fixture_id IN ($in) AND COALESCE(u.is_bot,0)=0 AND julianday(f.kickoff_utc)<=julianday('now')")->fetchAll(PDO::FETCH_ASSOC);
 foreach($rows as $r){
  $fid=(int)$r['fixture_id'];$k=(int)$r['home_score'].'-'.(int)$r['away_score'];
  $o=&$out[$fid];$o??=['n'=>0,'counts'=>[],'mine'=>null,'same'=>0,'result'=>0,'exact'=>0,'final'=>false];
  $o['n']++;$o['counts'][$k]=($o['counts'][$k]??0)+1;
  if((int)$r['user_id']===$uid)$o['mine']=$k;
  if(in_array($r['status'],['FT','AET','PEN'],true)&&$r['ah']!==null){
   $o['final']=true;$pts=pc_points((int)$r['home_score'],(int)$r['away_score'],(int)$r['ah'],(int)$r['aa']);
   $o['result']+=(int)($pts>0);$o['exact']+=(int)($pts===PC_POINTS_EXACT);
  }
  unset($o);
 }
 foreach($out as &$o){arsort($o['counts']);$o['top']=array_slice($o['counts'],0,3,true);$o['same']=$o['mine']!==null?$o['counts'][$o['mine']]:0;}unset($o);
 return $out;
}
function pcPickInsightHtml(?array $i):string{
 if(!$i||$i['n']<2)return ''; // one pick on its own says nothing (and would reveal that player's pick)
 $lab=fn($k)=>str_replace('-','–',$k);$n=$i['n'];$parts=[];
 foreach($i['top'] as $k=>$c)$parts[]='<b>'.$lab($k).'</b> '.($n>=10?round(100*$c/$n).'%':'×'.$c);
 $h='<div class="pickinsight"><span class="pihead">'.$n.' players picked:</span> '.implode(' · ',$parts);
 if($i['mine']!==null){
  $same=$i['same']-1;
  $h.='<span class="pimine">'.($same===0?'Only you went for <b>'.$lab($i['mine']).'</b>':'You and '.$same.' other'.($same===1?'':'s').' went for <b>'.$lab($i['mine']).'</b>').'</span>';
 }
 if($i['final'])$h.='<span class="piresult">'.$i['result'].' of '.$n.' got the result'.($i['exact']?', '.$i['exact'].' the exact score':'').'</span>';
 return $h.'</div>';
}
