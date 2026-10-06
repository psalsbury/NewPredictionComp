<?php
declare(strict_types=1);
header('Content-Type: application/xml; charset=UTF-8');
$db=new PDO('sqlite:/var/lib/predictioncomp/predictioncomp.sqlite');
require_once __DIR__.'/clubs.php';
function xml($v){return htmlspecialchars((string)$v,ENT_XML1|ENT_QUOTES,'UTF-8');}
function slug($v){return strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',$v),'-'));}
echo '<?xml version="1.0" encoding="UTF-8"?>'.PHP_EOL.'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
/* lastmod: data pages change when fixtures sync; info pages when info.php changes. */
function url(string $path,?string $date):void{echo '<url><loc>https://predictioncomp.com'.xml($path).'</loc>'.($date?'<lastmod>'.xml(substr($date,0,10)).'</lastmod>':'').'</url>';}
$latest=(string)$db->query('SELECT MAX(updated_at) FROM fixtures')->fetchColumn();$infoDate=date('Y-m-d',(int)filemtime(__DIR__.'/info.php'));
foreach(['/','/clubs','/fixtures','/table','/prediction-guides'] as $path)url($path,$latest);
foreach(['/about','/beat-the-bots','/how-it-works','/privacy','/contact'] as $path)url($path,$infoDate);
$clubDates=[];foreach($db->query('SELECT home,away,updated_at FROM fixtures') as $r)foreach([$r['home'],$r['away']] as $t){$t=pcClubName($t,$db);if(($clubDates[$t]??'')<$r['updated_at'])$clubDates[$t]=$r['updated_at'];}
foreach(pcSeasonClubs($db) as $team)url('/clubs/'.slug($team),$clubDates[$team]??null);
foreach($db->query('SELECT id,updated_at FROM fixtures') as $r){echo '<url><loc>https://predictioncomp.com/fixtures/'.(int)$r['id'].'</loc>';if(!empty($r['updated_at']))echo '<lastmod>'.xml(substr($r['updated_at'],0,10)).'</lastmod>';echo '</url>';}
require __DIR__.'/seo-content.php';
$weeks=[];foreach($db->query('SELECT kickoff_utc,updated_at FROM fixtures') as $r){$k=weekKey($r['kickoff_utc']);if(($weeks[$k]??'')<$r['updated_at'])$weeks[$k]=(string)$r['updated_at'];}
$guideCutoff=date('Y-m-d',strtotime('+14 days'));
foreach($weeks as $key=>$updated)if($key<=$guideCutoff)url('/prediction-guides/'.$key,$updated);
echo '</urlset>';
