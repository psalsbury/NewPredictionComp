<?php
function pcOddsHtml(PDO $db,int $fixtureId,string $kickoff,?array $pick=null,?array $result=null):string{
 static $odds=null;
 if($odds===null){
  $odds=[];
  if($db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='fixture_odds'")->fetchColumn()){
   foreach($db->query('SELECT * FROM fixture_odds')->fetchAll(PDO::FETCH_ASSOC) as $row)$odds[(int)$row['fixture_id']]=$row;
  }
 }
 $o=$odds[$fixtureId]??null;if(!$o)return '';
 $selected=$pick===null?null:($pick[0]>$pick[1]?'home':($pick[0]<$pick[1]?'away':'draw'));
 // Once the result is known, mark the outcome that happened (✓) as well as the one predicted (outlined).
 $actual=$result===null?null:((int)$result[0]>(int)$result[1]?'home':((int)$result[0]<(int)$result[1]?'away':'draw'));
 $html='<div class="matchodds'.($actual?' oddsdone':'').'" aria-label="Pre-match result odds"><div class="oddsprices">';
 foreach(['home'=>'Home','draw'=>'Draw','away'=>'Away'] as $key=>$label){
  $state=($actual===$key?' Result.':'').($selected===$key?' Your prediction.':'');
  $html.='<span class="oddsprice'.($selected===$key?' oddspick':'').($actual===$key?' oddsresult':'').'"'.($state?' title="'.trim($state).'"':'').'>'.($actual===$key?'<i aria-hidden="true">✓</i> ':'').$label.' <b>'.number_format((float)$o[$key],2).'</b>'.($state?'<span class="visually-hidden">'.$state.'</span>':'').'</span>';
 }
 $html.='</div>';
 if($actual)$html.='<small class="oddskey"><span class="keyresult">✓ Result</span>'.($selected!==null?'<span class="keypick">Your pick</span>':'').'</small>';
 return $html.'</div>';
}
