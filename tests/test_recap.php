<?php
require __DIR__.'/../inc/recap.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE users(id INTEGER,display_name TEXT,is_bot INTEGER,guest_since TEXT,email TEXT);CREATE TABLE fixtures(id INTEGER,kickoff_utc TEXT,home TEXT,away TEXT,home_score INTEGER,away_score INTEGER,status TEXT);CREATE TABLE predictions(user_id INTEGER,fixture_id INTEGER,home_score INTEGER,away_score INTEGER);CREATE TABLE fixture_odds(fixture_id INTEGER,home REAL,draw REAL,away REAL,observed_at TEXT,fetched_at TEXT);
INSERT INTO users VALUES(1,'Alice',0,NULL,NULL),(2,'Bob',0,NULL,NULL),(3,'SIM',1,NULL,NULL),(4,'Hidden',0,'2025-08-01',NULL),(5,'Carol',0,NULL,NULL);
INSERT INTO fixtures VALUES(1,'2025-08-01T15:00:00Z','A','B',2,1,'FT'),(2,'2025-08-02T15:00:00Z','C','D',0,0,'FT'),(3,'2025-08-03T15:00:00Z','E','F',0,2,'FT'),(4,'2099-08-03T15:00:00Z','G','H',1,0,'NS');
INSERT INTO fixture_odds VALUES(1,4,3,2,'2025-08-01T10:00:00Z','2025-08-01T10:01:00Z'),(2,2,5,3,'2025-08-02T10:00:00Z','2025-08-02T10:01:00Z'),(3,2,3,9,'2025-08-03T10:00:00Z','2025-08-03T16:01:00Z');
INSERT INTO predictions VALUES(1,1,2,1),(1,2,0,0),(1,3,1,2),(2,1,1,0),(2,2,1,1),(2,3,2,0),(3,1,2,1),(4,1,2,1),(5,1,0,1),(5,2,2,1),(5,3,2,0),(1,4,1,0)");
$s=recapData($db,[1,2,3,4]);
check(count($s['players'])===3&&$s['picks']===9,'bots hidden guests and pending excluded');
check($s['top'][0]['name']==='Alice'&&$s['top'][0]['points']===13,'points leader');
check(count($s['best'])===2&&$s['best'][0]['price']===5.0,'highest odds shared winners and late odds ignored');
check(count($s['rare'])===2&&count($s['rare'][0]['entries'])===1,'rarity ties');
check($s['sharp'][0]['name']==='Alice','minimum sample scoring rate');
check($s['hard'][0]['fixture']['id']==3,'hardest result');
check(recapData($db,[])['picks']===0,'empty period');
$db->exec('DROP TABLE fixture_odds');
check(recapData($db,[1,2,3])['best']===[],'missing odds graceful');
echo "Recap checks passed\n";
