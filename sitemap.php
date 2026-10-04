<?php
declare(strict_types=1);
header('Content-Type: application/xml; charset=UTF-8');
$db=new PDO('sqlite:/var/lib/predictioncomp/predictioncomp.sqlite');
require_once __DIR__.'/clubs.php';
function xml($v){return htmlspecialchars((string)$v,ENT_XML1|ENT_QUOTES,'UTF-8');}
function slug($v){return strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',$v),'-'));}
echo '<?xml version="1.0" encoding="UTF-8"?>'.PHP_EOL.'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
foreach(['/','/about','/how-it-works','/privacy','/contact','/clubs','/fixtures','/table','/prediction-guides'] as $path)echo '<url><loc>https://predictioncomp.com'.xml($path).'</loc></url>';
foreach(pcSeasonClubs($db) as $team)echo '<url><loc>https://predictioncomp.com/clubs/'.xml(slug($team)).'</loc></url>';
foreach($db->query('SELECT id,updated_at FROM fixtures') as $r){echo '<url><loc>https://predictioncomp.com/fixtures/'.(int)$r['id'].'</loc>';if(!empty($r['updated_at']))echo '<lastmod>'.xml(substr($r['updated_at'],0,10)).'</lastmod>';echo '</url>';}
require __DIR__.'/seo-content.php';
$weeks=[];foreach($db->query('SELECT kickoff_utc FROM fixtures') as $r)$weeks[weekKey($r['kickoff_utc'])]=true;
$guideCutoff=date('Y-m-d',strtotime('+14 days'));
foreach(array_keys($weeks) as $key)if($key<=$guideCutoff)echo '<url><loc>https://predictioncomp.com/prediction-guides/'.xml($key).'</loc></url>';
echo '</urlset>';
