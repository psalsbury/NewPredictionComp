<?php
declare(strict_types=1); require_once __DIR__.'/inc/config.php'; require_once __DIR__.'/seo-policy.php';
header('Content-Type: application/xml; charset=UTF-8');
$db=new PDO('sqlite:'.pc_db_path());
require_once __DIR__.'/clubs.php';
function xml($v){return htmlspecialchars((string)$v,ENT_XML1|ENT_QUOTES,'UTF-8');}
function slug($v){return strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',$v),'-'));}
echo '<?xml version="1.0" encoding="UTF-8"?>'.PHP_EOL.'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
/* lastmod = when a page's content really changed, not when the sync last touched the row (it runs every few
   minutes, which made every page look new every day). A match page changes when either team plays (form, head-to-head)
   and when its own result comes in; a club page when the club plays; the list pages when any result comes in. */
function url(string $path,?string $date):void{echo '<url><loc>https://predictioncomp.com'.xml($path).'</loc>'.($date?'<lastmod>'.xml(substr($date,0,10)).'</lastmod>':'').'</url>';}
$fx=$db->query('SELECT id,kickoff_utc,home,away,status,home_score,away_score FROM fixtures ORDER BY kickoff_utc')->fetchAll(PDO::FETCH_ASSOC);
$played=[];$teamDays=[];$lastResult='';
foreach($fx as $r)if($r['home_score']!==null&&$r['home_score']!==''){$d=substr($r['kickoff_utc'],0,10);$played[]=$r;$teamDays[$r['home']][]=$d;$teamDays[$r['away']][]=$d;if($d>$lastResult)$lastResult=$d;}
$firstDay=$fx?substr($fx[0]['kickoff_utc'],0,10):date('Y-m-d');if($lastResult==='')$lastResult=$firstDay;
$latestUpTo=function(string $team,string $upTo)use($teamDays):string{$best='';foreach($teamDays[$team]??[] as $d)if($d<=$upTo&&$d>$best)$best=$d;return $best;};
$fixDate=[];
foreach($fx as $r){$k=substr($r['kickoff_utc'],0,10);$upTo=min($k,date('Y-m-d'));$d=max($latestUpTo($r['home'],$upTo),$latestUpTo($r['away'],$upTo));if($d==='')$d=$firstDay;$fixDate[$r['id']]=$d;}
$infoDate=date('Y-m-d',(int)filemtime(__DIR__.'/info.php'));
foreach(['/','/clubs','/fixtures','/results','/table','/prediction-guides'] as $path)url($path,max($lastResult,date('Y-m-d',(int)filemtime(__DIR__.($path==='/'?'/templates/home.php':'/public.php')))));
foreach(['/about','/beat-the-bots','/how-it-works','/privacy','/contact'] as $path)url($path,$infoDate);
$clubDates=[];foreach($played as $r)foreach([$r['home'],$r['away']] as $t){$t=pcClubName($t,$db);$d=substr($r['kickoff_utc'],0,10);if(($clubDates[$t]??'')<$d)$clubDates[$t]=$d;}
foreach(pcSeasonClubs($db) as $team)url('/clubs/'.slug($team),$clubDates[$team]??$firstDay);
foreach($fx as $r)if(pcSeoPreviewReady($r))url('/fixtures/'.(int)$r['id'],$fixDate[$r['id']]);
require __DIR__.'/seo-content.php';
$weeks=[];foreach($fx as $r){$k=weekKey($r['kickoff_utc']);if(($weeks[$k]??'')<$fixDate[$r['id']])$weeks[$k]=$fixDate[$r['id']];}
$guideCutoff=date('Y-m-d',strtotime('+14 days'));
foreach($weeks as $key=>$updated)if($key<=$guideCutoff)url('/prediction-guides/'.$key,$updated);
echo '</urlset>';
