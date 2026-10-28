<?php
require __DIR__.'/../inc/results-odds.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE fixtures(id INTEGER,kickoff_utc TEXT,home TEXT,away TEXT,home_score INTEGER,away_score INTEGER,status TEXT);CREATE TABLE predictions(user_id INTEGER,fixture_id INTEGER,home_score INTEGER,away_score INTEGER);CREATE TABLE fixture_odds(fixture_id INTEGER,home REAL,draw REAL,away REAL,observed_at TEXT,fetched_at TEXT)");
$insert=$db->prepare('INSERT INTO fixtures VALUES(?,?,?,?,?,?,?)');
$pick=$db->prepare('INSERT INTO predictions VALUES(?,?,?,?)');
$odds=$db->prepare('INSERT INTO fixture_odds VALUES(?,?,?,?,?,?)');
$cases=[
 [1,'2025-08-01T15:00:00Z',2,0,'FT',1,0,2.5,3,4,'2025-08-01T10:00:00Z'],
 [2,'2025-08-02T15:00:00Z',1,1,'FT',0,0,2,5,3,'2025-08-02T10:00:00Z'],
 [3,'2025-08-03T15:00:00Z',0,1,'FT',1,0,2,3,4,'2025-08-03T10:00:00Z'],
 [4,'2025-08-04T15:00:00Z',1,0,'FT',1,0,9,3,4,'2025-08-04T16:00:00Z'],
 [5,'2025-08-05T15:00:00Z',1,0,'PST',1,0,9,3,4,'2025-08-05T10:00:00Z'],
 [6,'2025-08-06T15:00:00Z',1,0,'FT',1,0,9,3,4,null],
 [7,'2024-08-06T15:00:00Z',1,0,'FT',1,0,9,3,4,'2024-08-06T10:00:00Z']];
foreach($cases as [$id,$kick,$h,$a,$status,$ph,$pa,$ho,$dr,$aw,$fetched]){
 $insert->execute([$id,$kick,'<Home>','Away',$h,$a,$status]);$pick->execute([1,$id,$ph,$pa]);
 if($id!==6)$odds->execute([$id,$ho,$dr,$aw,substr($kick,0,10).'T09:00:00Z',$fetched]);
}
$s=personalOddsResults($db,1,2025);
check($s['settled']===3&&$s['wins']===2,'settlement and outcomes');
check($s['stake']===300&&$s['returns']===750&&$s['net']===450,'stake-inclusive returns and net');
check($s['best']['id']==2&&$s['best']['price']===5.0,'highest odds draw wins best');
check($s['excluded']===2,'missing and late odds excluded');
check(personalOddsResults($db,2,2025)['settled']===0,'user isolation');
$html=renderPersonalOddsResults($s);check(str_contains($html,'&lt;Home&gt;')&&!str_contains($html,'<Home>'),'HTML escaping');
check(str_contains($html,'+£4.50'),'net output');
$db->exec('CREATE TABLE fixture_gameweeks(fixture_id INTEGER,season INTEGER,gameweek INTEGER);INSERT INTO fixture_gameweeks VALUES(1,2025,1),(2,2025,2),(3,2025,1)');
$week=personalOddsResults($db,1,2025,['season'=>2025,'week'=>1]);
check($week['settled']===2&&$week['net']===50&&$week['best']['id']==1,'official gameweek totals and best');
$second=personalOddsResults($db,1,2025,['season'=>2025,'week'=>2]);
check($second['settled']===1&&$second['net']===400,'another gameweek');
$insert->execute([8,'2025-08-31T23:30:00Z','Home','Away',1,0,'FT']);
$pick->execute([1,8,1,0]);$odds->execute([8,2,3,4,'2025-08-31T10:00:00Z','2025-08-31T10:01:00Z']);
$periods=personalOddsPeriods($db,1,2025,'month:2025-09');
check($periods['key']==='month:2025-09','month selection retained');
$month=personalOddsResults($db,1,2025,$periods['selected']);
check($month['settled']===1&&$month['best']['id']==8,'UK monthly boundary includes BST match');
check(personalOddsPeriods($db,1,2025,'invalid')['key']==='season:2025','invalid choice defaults safely');
check(isset($periods['options']['season:2024']),'previous season available');
check(personalOddsResults($db,1,2025,$periods['options']['season:2024'])['settled']===1,'previous season totals');
$selector=renderPersonalOddsSelector($periods);
check(str_contains($selector,'name="tab" value="odds"')&&str_contains($selector,'value="month:2025-09" selected'),'selector keeps odds tab and selected month');
check(str_contains(renderPersonalOddsResults($month,$periods['selected']['label']),'September 2025'),'selected period label');
$db->exec('DROP TABLE fixture_odds');
check(personalOddsResults($db,1,2025)['settled']===0,'missing odds table');
echo "Results odds checks passed\n";
