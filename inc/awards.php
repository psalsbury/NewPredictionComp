<?php
declare(strict_types=1);
// Player awards, worked out by bin/awards.py (player_awards table). Display helpers only.
function pcAwardsTable(PDO $db):bool{static $has=null;return $has??=(bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='player_awards'")->fetchColumn();}
function pcAwardsFor(PDO $db,int $userId,int $limit=30):array{
 if($userId<1||!pcAwardsTable($db))return [];
 $q=$db->prepare('SELECT award,period,label,detail,fixture_id FROM player_awards WHERE user_id=? ORDER BY awarded_at DESC,period DESC LIMIT '.(int)$limit);$q->execute([$userId]);
 return $q->fetchAll(PDO::FETCH_ASSOC);
}
// Awards for a recap period: $period like 'gw:2026-9' or 'month:2026-10'; listed players only. Joker jackpots
// are keyed by fixture, so pass the period's fixture ids to include them.
function pcAwardsForPeriod(PDO $db,string $period,array $fixtureIds=[]):array{
 if(!pcAwardsTable($db))return [];
 $ids=implode(',',array_map('intval',$fixtureIds))?:'0';
 $q=$db->prepare("SELECT a.award,a.label,a.detail,a.fixture_id,u.display_name FROM player_awards a JOIN users u ON u.id=a.user_id
  WHERE (a.period=? OR (a.award='joker_hit' AND a.fixture_id IN ($ids))) AND ".pc_listed_sql()." ORDER BY a.award,u.display_name");
 $q->execute([$period]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function pcAwardBadge(array $a,bool $withName=false):string{
 $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
 return '<li class="awardbadge award-'.$e($a['award']).'" title="'.$e($a['detail']).'"><b>'.$e($a['label']).'</b><small>'.($withName?$e($a['display_name']).' · ':'').$e($a['detail']).'</small></li>';
}
function pcAwardsHtml(array $awards,string $title='Your awards',string $empty=''):string{
 if(!$awards)return $empty===''?'':'<section class="awardsbox"><h3>'.htmlspecialchars($title).'</h3><p class="awardsempty">'.htmlspecialchars($empty).'</p></section>';
 return '<section class="awardsbox"><h3>'.htmlspecialchars($title).'</h3><ul class="awardlist">'.implode('',array_map('pcAwardBadge',$awards)).'</ul></section>';
}
