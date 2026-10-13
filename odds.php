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
 $selected=$pick===null?null:($pick[0]>$pick[1]?'home':($pick[0]<$pick[1]?'away':'draw'));
 $html='<div class="matchodds" aria-label="Pre-match result odds"><div class="oddsprices">';
 foreach(['home'=>'Home','draw'=>'Draw','away'=>'Away'] as $key=>$label){
  $html.='<span class="oddsprice'.($selected===$key?' oddspick':'').'">'.$label.' <b>'.number_format((float)$o[$key],2).'</b></span>';
 }
 return $html.'</div></div>';
}
