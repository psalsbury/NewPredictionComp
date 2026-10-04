<?php
declare(strict_types=1);
/* Shared season-aware club metadata. File cache is also used by Python jobs. */
function pcSeasonYear():int {
 $now=new DateTimeImmutable('now',new DateTimeZone('Europe/London'));
 return (int)$now->format('Y')-((int)$now->format('n')<7?1:0);
}
function pcClubCatalog(?PDO $db=null):array {
 static $catalog=null;
 if($catalog!==null)return $catalog;
 $path='/var/cache/predictioncomp/clubs.json';
 $read=function()use($path){$value=json_decode((string)@file_get_contents($path),true);return is_array($value)&&isset($value['clubs'],$value['aliases'],$value['seasons'],$value['generated_at'])?$value:null;};
 $cached=$read();
 if($cached&&time()-(int)$cached['generated_at']<21600)return $catalog=$cached;
 $lock=@fopen(dirname($path).'/clubs.lock','c');
 if($lock&&!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);if($cached)return $catalog=$cached;$lock=null;}
 try {
  $latest=$read();if($latest&&time()-(int)$latest['generated_at']<21600)return $catalog=$latest;
  $db=$db??new PDO('sqlite:/var/lib/predictioncomp/predictioncomp.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $result=['generated_at'=>time(),'clubs'=>[],'aliases'=>[],'seasons'=>[]];
  foreach($db->query('SELECT name,badge FROM clubs') as $row){$result['clubs'][$row['name']]=['name'=>$row['name'],'badge'=>$row['badge']];$result['aliases'][strtolower($row['name'])]=$row['name'];}
  foreach($db->query('SELECT alias,club_name FROM club_aliases') as $row)$result['aliases'][$row['alias']]=$row['club_name'];
  $sql="SELECT home AS name,CAST(substr(kickoff_utc,1,4) AS INTEGER)-(CASE WHEN CAST(substr(kickoff_utc,6,2) AS INTEGER)<7 THEN 1 ELSE 0 END) AS season FROM fixtures UNION SELECT away,CAST(substr(kickoff_utc,1,4) AS INTEGER)-(CASE WHEN CAST(substr(kickoff_utc,6,2) AS INTEGER)<7 THEN 1 ELSE 0 END) FROM fixtures";
  foreach($db->query($sql) as $row){$raw=trim($row['name']);$name=$result['aliases'][strtolower($raw)]??$raw;$result['clubs'][$name]=$result['clubs'][$name]??['name'=>$name,'badge'=>null];$result['aliases'][strtolower($raw)]=$name;$result['seasons'][(string)$row['season']][$name]=true;}
  foreach($result['seasons'] as &$names){$names=array_keys($names);sort($names,SORT_STRING);}unset($names);
  $temp=@tempnam(dirname($path),'clubs-');if($temp!==false){if(file_put_contents($temp,json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))!==false){chmod($temp,0664);rename($temp,$path);}else @unlink($temp);}
  return $catalog=$result;
 }catch(Throwable $error){if($cached){error_log('Club cache refresh failed: '.$error->getMessage());return $catalog=$cached;}throw $error;}
 finally{if($lock){flock($lock,LOCK_UN);fclose($lock);}}
}
function pcClubName(string $name,?PDO $db=null):string {
 $name=trim(preg_replace('/\s+/u',' ',$name));$catalog=pcClubCatalog($db);
 return $catalog['aliases'][strtolower($name)]??$name;
}
function pcSeasonClubs(?PDO $db=null,?int $season=null):array {
 return pcClubCatalog($db)['seasons'][(string)($season??pcSeasonYear())]??[];
}

function pcClubAliases(string $name,?PDO $db=null):array {
 $canonical=pcClubName($name,$db);$names=[$canonical];
 foreach(pcClubCatalog($db)['aliases'] as $alias=>$club)if($club===$canonical)$names[]=$alias;
 return array_values(array_unique($names));
}
