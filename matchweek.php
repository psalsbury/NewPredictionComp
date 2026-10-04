<?php
function matchweekSummary(PDO $db,array $pred,int $now):array{
 $q=$db->prepare("SELECT * FROM fixtures WHERE julianday(kickoff_utc)>julianday(?) AND COALESCE(status,'NS') NOT IN ('PST','CANC','ABD','SUSP','FT','AET','PEN','SIM') ORDER BY julianday(kickoff_utc),id LIMIT 1");
 $q->execute([gmdate('c',$now)]);$next=$q->fetch(PDO::FETCH_ASSOC);
 if(!$next)return ['fixtures'=>[],'saved'=>0,'total'=>0,'missing'=>[],'lockedMissing'=>0,'deadline'=>null,'label'=>'No upcoming matchweek'];
 $day=(new DateTimeImmutable($next['kickoff_utc'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/London'))->setTime(0,0);
 $offset=((int)$day->format('N')+2)%7;$start=$day->modify('-'.$offset.' days');$end=$start->modify('+7 days');
 $q=$db->prepare("SELECT id,kickoff_utc,status FROM fixtures WHERE julianday(kickoff_utc)>=julianday(?) AND julianday(kickoff_utc)<julianday(?) AND COALESCE(status,'NS') NOT IN ('PST','CANC','ABD','SUSP','SIM') ORDER BY julianday(kickoff_utc),id");
 $q->execute([$start->format('c'),$end->format('c')]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
 $saved=0;$missing=[];$locked=0;$deadline=null;
 foreach($rows as $f){$id=(int)$f['id'];$open=strtotime($f['kickoff_utc'])>$now&&!in_array($f['status'],['FT','AET','PEN'],true);
 if(array_key_exists($id,$pred))$saved++;elseif($open)$missing[]=$id;else $locked++;
 if($open&&$deadline===null)$deadline=$f['kickoff_utc'];}
 return ['fixtures'=>$rows,'saved'=>$saved,'total'=>count($rows),'missing'=>$missing,'lockedMissing'=>$locked,'deadline'=>$deadline,'label'=>'Matchweek · '.$start->format('d M').'–'.$end->modify('-1 day')->format('d M')];
}
