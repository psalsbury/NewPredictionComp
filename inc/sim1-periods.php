<?php
declare(strict_types=1);
function sim1Periods(PDO $db,int $year,string $requested=''):array{
 $tz=new DateTimeZone('Europe/London');$now=new DateTimeImmutable('now',$tz);$month=$now->format('Y-m');
 $options=['season:'.$year=>['type'=>'Season','label'=>$year.'/'.substr((string)($year+1),2).' season','rows'=>[]],'month:'.$month=>['type'=>'Month','label'=>$now->format('F Y'),'rows'=>[]]];
 $mapped=(bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='fixture_gameweeks'")->fetchColumn();
 $sql=$mapped?'SELECT f.*,g.season mapped_season,g.gameweek FROM fixtures f LEFT JOIN fixture_gameweeks g ON g.fixture_id=f.id':'SELECT f.*,NULL mapped_season,NULL gameweek FROM fixtures f';
 $rows=$db->query($sql.' ORDER BY julianday(f.kickoff_utc) DESC,f.id DESC')->fetchAll(PDO::FETCH_ASSOC);
 $finishedWeeks=[];$default=null;
 foreach($rows as $r){
  $date=(new DateTimeImmutable($r['kickoff_utc']))->setTimezone($tz);$sy=(int)$date->format('Y')-((int)$date->format('n')<7?1:0);$m=$date->format('Y-m');
  if($sy<=$year){$key='season:'.$sy;if(!isset($options[$key]))$options[$key]=['type'=>'Season','label'=>$sy.'/'.substr((string)($sy+1),2).' season','rows'=>[]];$options[$key]['rows'][]=$r;}
  if($m<=$month){$key='month:'.$m;if(!isset($options[$key]))$options[$key]=['type'=>'Month','label'=>$date->format('F Y'),'rows'=>[]];$options[$key]['rows'][]=$r;}
  if($r['gameweek']!==null){
   $key='week:'.$r['mapped_season'].':'.$r['gameweek'];
   if(!isset($options[$key]))$options[$key]=['type'=>'Week','label'=>'Gameweek '.$r['gameweek'].' · '.$r['mapped_season'].'/'.substr((string)((int)$r['mapped_season']+1),2),'rows'=>[]];
   $options[$key]['rows'][]=$r;
   if(in_array($r['status'],['FT','AET','PEN'],true)&&$r['home_score']!==null&&$r['away_score']!==null&&$date<=$now){$finishedWeeks[$key]=true;if($default===null)$default=$key;}
  }
 }
 foreach($options as $key=>$p)if($p['type']==='Week'&&!isset($finishedWeeks[$key]))unset($options[$key]);
 $key=isset($options[$requested])?$requested:($default??'season:'.$year);
 return ['key'=>$key,'selected'=>$options[$key],'options'=>$options];
}
function renderSim1Periods(array $periods):string{
 $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
 $html='<form action="/can-you-beat-sim1" method="get" class="sim1periods"><label for="sim1Period">Choose a week, month or season</label><div><select id="sim1Period" name="period" onchange="this.form.requestSubmit()">';
 foreach(['Week','Month','Season'] as $type){
  $html.='<optgroup label="'.$type.'">';
  foreach($periods['options'] as $key=>$p)if($p['type']===$type)$html.='<option value="'.$e($key).'"'.($key===$periods['key']?' selected':'').'>'.$e($p['label']).'</option>';
  $html.='</optgroup>';
 }
 return $html.'</select><button type="submit" class="sharebtn">View</button></div><p class="note">Weeks follow official Premier League gameweeks. Months use UK kick-off dates. Only finished matches with recorded prediction points count; an ongoing period updates as results arrive.</p></form><style>.sim1periods{margin:24px 0}.sim1periods label{display:block;font-weight:700;margin-bottom:10px}.sim1periods>div{display:flex;flex-wrap:wrap;gap:10px;align-items:center}.sim1periods select{max-width:100%;min-height:46px;padding:10px 14px;border:1px solid var(--line,#304655);border-radius:8px;background:var(--ink-2,#102330);color:var(--text,#eef5f8);font:inherit}.sim1periods .note{margin-top:12px}</style>';
}
