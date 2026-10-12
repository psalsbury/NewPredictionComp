<?php
function matchweekSummary(PDO $db,array $pred,int $now):array{
 $q=$db->prepare("SELECT * FROM fixtures WHERE julianday(kickoff_utc)>julianday(?) AND COALESCE(status,'NS') NOT IN ('PST','CANC','ABD','SUSP','FT','AET','PEN','SIM') ORDER BY julianday(kickoff_utc),id LIMIT 1");
 $q->execute([gmdate('c',$now)]);$next=$q->fetch(PDO::FETCH_ASSOC);
 if(!$next)return ['fixtures'=>[],'saved'=>0,'total'=>0,'missing'=>[],'lockedMissing'=>0,'deadline'=>null,'label'=>'No upcoming matchweek'];
 $mapped=false;$gameweek=null;$season=null;
 if($db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='fixture_gameweeks'")->fetchColumn()){
  $q=$db->prepare('SELECT season,gameweek FROM fixture_gameweeks WHERE fixture_id=?');$q->execute([$next['id']]);$membership=$q->fetch(PDO::FETCH_ASSOC);
  if($membership){
   $season=(int)$membership['season'];$gameweek=(int)$membership['gameweek'];
   $q=$db->prepare("SELECT f.id,f.kickoff_utc,f.status FROM fixtures f JOIN fixture_gameweeks g ON g.fixture_id=f.id WHERE g.season=? AND g.gameweek=? AND COALESCE(f.status,'NS') NOT IN ('CANC','ABD','SIM') ORDER BY julianday(f.kickoff_utc),f.id");
   $q->execute([$season,$gameweek]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$mapped=true;
  }
 }
 if(!$mapped){$rows=[['id'=>$next['id'],'kickoff_utc'=>$next['kickoff_utc'],'status'=>$next['status']]];}
 $label=$mapped?'Gameweek '.$gameweek.' · '.$season.'/'.substr((string)($season+1),2):'Upcoming match · gameweek awaiting confirmation';
 $saved=0;$missing=[];$locked=0;$deadline=null;
 foreach($rows as $f){$id=(int)$f['id'];$open=strtotime($f['kickoff_utc'])>$now&&!in_array($f['status'],['FT','AET','PEN'],true);
 if(array_key_exists($id,$pred))$saved++;elseif($open)$missing[]=$id;else $locked++;
 if($open&&$deadline===null)$deadline=$f['kickoff_utc'];}
 return ['fixtures'=>$rows,'saved'=>$saved,'total'=>count($rows),'missing'=>$missing,'lockedMissing'=>$locked,'deadline'=>$deadline,'label'=>$label];
}
