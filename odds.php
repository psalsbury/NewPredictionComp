<?php
function pcOddsHtml(PDO $db,int $fixtureId,string $kickoff,?array $pick=null):string{
 static $odds=null;
 if($odds===null){
  $odds=[];
  if($db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='fixture_odds'")->fetchColumn()){
   foreach($db->query('SELECT * FROM fixture_odds')->fetchAll(PDO::FETCH_ASSOC) as $row)$odds[(int)$row['fixture_id']]=$row;
  }
 }
 $o=$odds[$fixtureId]??null;if(!$o)return '';
 $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
 $past=strtotime($kickoff)<=time();
 $selected=$pick===null?null:($pick[0]>$pick[1]?'home':($pick[0]<$pick[1]?'away':'draw'));
 $html='<div class="matchodds" aria-label="Pre-match result odds"><div class="oddsprices">';
 foreach(['home'=>'Home','draw'=>'Draw','away'=>'Away'] as $key=>$label){
  $html.='<span class="oddsprice'.($selected===$key?' oddspick':'').'">'.$label.' <b>'.number_format((float)$o[$key],2).'</b></span>';
 }
 $stamp=(new DateTimeImmutable($o['observed_at']))->setTimezone(new DateTimeZone('Europe/London'))->format('d M, H:i');
 $stale=time()-strtotime($o['observed_at'])>172800&&!$past;
 return $html.'</div><small>'.($past?'Pre-match odds':'Match odds').' · '.$e($o['bookmaker']).' · '.$e($stamp).' UK'.($stale?' · Older snapshot':'').' · Decimal</small></div>';
}
