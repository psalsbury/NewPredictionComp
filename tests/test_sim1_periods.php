<?php
require __DIR__.'/../inc/sim1-periods.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$db=new PDO('sqlite::memory:');
$db->exec("CREATE TABLE fixtures(id INTEGER,kickoff_utc TEXT,status TEXT,home_score INTEGER,away_score INTEGER);
CREATE TABLE fixture_gameweeks(fixture_id INTEGER,season INTEGER,gameweek INTEGER);
INSERT INTO fixtures VALUES(1,'2025-08-31T23:30:00Z','FT',1,0),(2,'2025-09-10T15:00:00Z','FT',0,0),(3,'2025-10-01T15:00:00Z','NS',NULL,NULL);
INSERT INTO fixture_gameweeks VALUES(1,2025,1),(2,2025,1),(3,2025,2)");
$s=sim1Periods($db,2025);
check($s['key']==='week:2025:1'&&count($s['selected']['rows'])===2,'latest finished official gameweek includes rescheduled match');
check(!isset($s['options']['week:2025:2']),'unplayed weeks not offered');
$s=sim1Periods($db,2025,'month:2025-09');
check(count($s['selected']['rows'])===2,'UK month boundary');
$s=sim1Periods($db,2025,'season:2025');
check(count($s['selected']['rows'])===3,'season includes unfinished fixtures for completion status');
check(sim1Periods($db,2025,'invalid')['key']==='week:2025:1','invalid selection fallback');
$html=renderSim1Periods(sim1Periods($db,2025,'month:2025-09'));
check(str_contains($html,'value="month:2025-09" selected'),'selected month retained');
$db->exec('DROP TABLE fixture_gameweeks');
check(sim1Periods($db,2025)['key']==='season:2025','unmapped data defaults safely');
echo "SIM1 period checks passed\n";
