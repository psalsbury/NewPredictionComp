<?php
declare(strict_types=1);
// Everything the homepage template needs: fixtures, picks, history, leaderboards.
// Browse complete official matchweeks, including rearranged fixtures.
$fixtureSeason=pcSeasonYear();
$q=$db->prepare('SELECT g.gameweek,MIN(julianday(f.kickoff_utc)) first_day,MAX(julianday(f.kickoff_utc)) last_day FROM fixture_gameweeks g JOIN fixtures f ON f.id=g.fixture_id WHERE g.season=? GROUP BY g.gameweek ORDER BY g.gameweek');
$q->execute([$fixtureSeason]);$fixtureWeeks=$q->fetchAll(PDO::FETCH_ASSOC);
$weekNumbers=array_map(fn($w)=>(int)$w['gameweek'],$fixtureWeeks);
$q=$db->prepare("SELECT g.gameweek FROM fixtures f JOIN fixture_gameweeks g ON g.fixture_id=f.id WHERE g.season=? AND julianday(f.kickoff_utc)>julianday('now') AND COALESCE(f.status,'NS') NOT IN ('FT','AET','PEN','CANC','ABD','SIM') ORDER BY julianday(f.kickoff_utc),f.id LIMIT 1");
$q->execute([$fixtureSeason]);$defaultWeek=(int)($q->fetchColumn()?:end($weekNumbers));
$selectedFixtureWeek=(int)($_GET['matchweek']??$defaultWeek);
$fixtureTarget=(int)($_GET['fixture']??0);
if($fixtureTarget>0){$q=$db->prepare('SELECT gameweek FROM fixture_gameweeks WHERE season=? AND fixture_id=?');$q->execute([$fixtureSeason,$fixtureTarget]);$selectedFixtureWeek=(int)($q->fetchColumn()?:$selectedFixtureWeek);}
if(!in_array($selectedFixtureWeek,$weekNumbers,true))$selectedFixtureWeek=$defaultWeek;
$weekIndex=array_search($selectedFixtureWeek,$weekNumbers,true);
$previousFixtureWeek=$weekIndex!==false&&$weekIndex>0?$weekNumbers[$weekIndex-1]:null;
$nextFixtureWeek=$weekIndex!==false&&isset($weekNumbers[$weekIndex+1])?$weekNumbers[$weekIndex+1]:null;
$q=$db->prepare('SELECT f.* FROM fixtures f JOIN fixture_gameweeks g ON g.fixture_id=f.id WHERE g.season=? AND g.gameweek=? ORDER BY julianday(f.kickoff_utc),f.id');
$q->execute([$fixtureSeason,$selectedFixtureWeek]);$fixtures=$q->fetchAll(PDO::FETCH_ASSOC);
$fixtureCount=count($fixtures);$fixturePage=0;
$fixtureWeekRange='';
if($fixtures){$first=(new DateTimeImmutable($fixtures[0]['kickoff_utc']))->setTimezone(new DateTimeZone('Europe/London'));$last=(new DateTimeImmutable($fixtures[count($fixtures)-1]['kickoff_utc']))->setTimezone(new DateTimeZone('Europe/London'));$fixtureWeekRange=$first->format('D j M').($first->format('Y-m-d')!==$last->format('Y-m-d')?' – '.$last->format('D j M'):'');}
// First-time visitors on a plain homepage see the next three open matches; the rest are one tap away.
// $dayExtra marks UK days whose matches are all hidden, so the template can hide the whole day without CSS :has().
$collapseFixtures=false;$fixtureExtra=[];$dayExtra=[];$openFixtureCount=0;
if($uid<1&&!$_GET){
 foreach($fixtures as $f){
  $isOpen=strtotime($f['kickoff_utc'])>time()&&!in_array($f['status'],['PST','CANC','ABD','SUSP','TBD'],true);
  $extra=!$isOpen||$openFixtureCount>=3;if($isOpen)$openFixtureCount++;$fixtureExtra[(int)$f['id']]=$extra;
  $day=(new DateTimeImmutable($f['kickoff_utc'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m-d');
  $dayExtra[$day]=($dayExtra[$day]??true)&&$extra;
 }
 $collapseFixtures=$openFixtureCount>3;if(!$collapseFixtures){$fixtureExtra=[];$dayExtra=[];}
}
// After signing in from an invite, land on the predictions with the league alongside (same as the other invite routes).
$afterSignIn='/?signed_in=1#predict';
if(!empty($_SESSION['pending_league_code'])){$q=$db->prepare('SELECT id FROM leagues WHERE code=?');$q->execute([(string)$_SESSION['pending_league_code']]);$pendingLeagueId=(int)$q->fetchColumn();$afterSignIn=$pendingLeagueId?'/?joined_league=1&league='.$pendingLeagueId.'#predict':'/leagues';}
$ps=$db->prepare('SELECT fixture_id,home_score,away_score FROM predictions WHERE user_id=?');$ps->execute([$uid]);$pred=[];foreach($ps as $p)$pred[$p['fixture_id']]=[$p['home_score'],$p['away_score']];
require PC_ROOT.'/player-nav.php';
require_once PC_ROOT.'/data-status.php';
require PC_ROOT.'/matchweek.php';
$matchweek=matchweekSummary($db,$pred,time(),['fixtures'=>$fixtures,'label'=>'Matchweek '.$selectedFixtureWeek.' · '.$fixtureSeason.'/'.substr((string)($fixtureSeason+1),2)]);
$historyStmt=$db->prepare("SELECT f.round,f.kickoff_utc,f.home,f.away,f.home_score actual_home,f.away_score actual_away,f.status,p.home_score predicted_home,p.away_score predicted_away,COALESCE(p.points,0) points
 FROM predictions p JOIN fixtures f ON f.id=p.fixture_id
 WHERE p.user_id=? AND julianday(f.kickoff_utc)<=julianday('now') AND COALESCE(f.status,'')<>'SIM'
 ORDER BY f.kickoff_utc DESC LIMIT 100");
$historyStmt->execute([$uid]);$predictionHistory=$historyStmt->fetchAll(PDO::FETCH_ASSOC);
$ukNow=new DateTimeImmutable('now',new DateTimeZone('Europe/London'));
$seasonYear=pcSeasonYear();
$seasonStart=new DateTimeImmutable($seasonYear.'-08-01 00:00:00',new DateTimeZone('Europe/London'));
$seasonEnd=$seasonStart->modify('+10 months');
require PC_ROOT.'/performance.php';
$performance=$uid>0?personalPerformance($db,$uid,$seasonYear):null;
$guestListed=true;
if($uid>0&&empty($user['email'])){$q=$db->prepare('SELECT COUNT(*) FROM users u WHERE u.id=? AND '.pc_listed_sql());$q->execute([$uid]);$guestListed=(bool)$q->fetchColumn();}
$leaderPeriods=['season'=>['Season '.substr((string)$seasonYear,2).'/'.substr((string)($seasonYear+1),2),$seasonStart,$seasonEnd]];
for($m=0;$m<10;$m++){
 $ms=$seasonStart->modify('+'.$m.' months');$key=$ms->format('Y-m');
 $leaderPeriods[$key]=[$ms->format('M'),$ms,$ms->modify('+1 month')];
}
$leaderPeriod=(string)($_GET['table']??'season');
if(!isset($leaderPeriods[$leaderPeriod]))$leaderPeriod='season';
[$leaderTitle,$leaderStart,$leaderEnd]=$leaderPeriods[$leaderPeriod];
$leagues=$db->prepare("SELECT l.id,l.name,l.code,(SELECT COUNT(*) FROM league_members mc WHERE mc.league_id=l.id) member_count FROM leagues l JOIN league_members m ON m.league_id=l.id WHERE m.user_id=? ORDER BY l.created_at DESC");$leagues->execute([$uid]);$myLeagues=$leagues->fetchAll(PDO::FETCH_ASSOC);
$selectedLeagueId=(int)($_GET['league']??0);$selectedLeague=null;
foreach($myLeagues as $ml){if((int)$ml['id']===$selectedLeagueId){$selectedLeague=$ml;break;}}
if(!$selectedLeague)$selectedLeagueId=0;
$leaderScope=$selectedLeagueId?' WHERE u.id IN (SELECT user_id FROM league_members WHERE league_id='.(int)$selectedLeagueId.')':' WHERE ('.pc_listed_sql().' OR u.id='.$uid.')';
$leaderScopeQuery=$selectedLeagueId?'&league='.$selectedLeagueId:((($_GET['screen']??'')==='leagues')?'&global=1':'');
$leaderPageSize=20;
$leaderCount=(int)$db->query('SELECT COUNT(*) FROM users u'.$leaderScope)->fetchColumn();
$leaderPages=max(1,(int)ceil($leaderCount/$leaderPageSize));
$leaderPage=max(1,min($leaderPages,(int)($_GET['page']??1)));
$leaderOffset=($leaderPage-1)*$leaderPageSize;
$leaderSql="SELECT u.id,u.display_name,CASE WHEN ".pc_listed_sql()." THEN 1 ELSE 0 END listed,CAST(COALESCE(u.is_bot,0) AS INTEGER) is_bot,CAST(COALESCE(u.bot_grade,5) AS INTEGER) bot_grade,COALESCE(SUM(CASE WHEN f.id IS NOT NULL THEN p.points ELSE 0 END),0) pts
 FROM users u LEFT JOIN predictions p ON p.user_id=u.id
 LEFT JOIN fixtures f ON f.id=p.fixture_id AND f.kickoff_utc>=:period_start AND f.kickoff_utc<:period_end
 ".$leaderScope." GROUP BY u.id ORDER BY pts DESC,u.is_bot ASC,u.id LIMIT ".$leaderPageSize." OFFSET ".$leaderOffset;
// Locate the current player's page using the same ordering as the table.
$leaderFocusMe=$uid>0&&((($_GET['focus']??'')==='me')||((($_GET['screen']??'')==='leagues')&&!isset($_GET['page'])));
if($leaderFocusMe&&!$selectedLeagueId){
 $positionSql=preg_replace('/ LIMIT [0-9]+ OFFSET [0-9]+$/','',$leaderSql);
 $positionStmt=$db->prepare($positionSql);
 $positionStmt->execute([':period_start'=>$leaderStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),':period_end'=>$leaderEnd->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
 foreach($positionStmt->fetchAll(PDO::FETCH_ASSOC) as $index=>$entry){
  if((int)$entry['id']===$uid){
   $leaderPage=intdiv($index,$leaderPageSize)+1;
   $leaderOffset=($leaderPage-1)*$leaderPageSize;
   $leaderSql=preg_replace('/ LIMIT [0-9]+ OFFSET [0-9]+$/',' LIMIT '.$leaderPageSize.' OFFSET '.$leaderOffset,$leaderSql);
   break;
  }
 }
}
$leaderStmt=$db->prepare($leaderSql);
$leaderStmt->execute([':period_start'=>$leaderStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),':period_end'=>$leaderEnd->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
$leaders=$leaderStmt->fetchAll(PDO::FETCH_ASSOC);
$friendProgress=null;
if($selectedLeagueId){
 $friendProgress=friendLeagueProgress($db,$selectedLeagueId,$uid,$leaderStart,$leaderEnd);
 if($leaderFocusMe){
  foreach($friendProgress['members'] as $index=>$entry){
   if((int)$entry['id']===$uid){$leaderPage=intdiv($index,$leaderPageSize)+1;$leaderOffset=($leaderPage-1)*$leaderPageSize;break;}
  }
 }
 $leaders=array_slice($friendProgress['members'],$leaderOffset,$leaderPageSize);
}
