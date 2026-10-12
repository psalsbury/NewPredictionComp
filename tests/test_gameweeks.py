import sys, unittest, sqlite3
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'bin'))
from gameweeks import unique_gameweek, generate_gameweek_predictions, SCHEMA

class GameweekTests(unittest.TestCase):
 def test_fifty_bots_one_remaining_match_distinct_full_week(self):
  used=set()
  for uid in range(50):
   slate=[(uid%7,uid//7),(1,1)]
   result=unique_gameweek(slate,used,uid,[False,True])
   self.assertEqual(result,slate)
  self.assertEqual(len(used),50)
 def test_collision_changes_only_future_fixture(self):
  slate=[(2,1),(0,0)];used={tuple(slate)}
  result=unique_gameweek(slate,used,7,[False,True])
  self.assertEqual(result[0],slate[0]);self.assertNotEqual(result,slate)
 def test_locked_collision_preserved(self):
  slate=[(2,1),(0,0)]
  self.assertEqual(unique_gameweek(slate,{tuple(slate)},1,[False,False]),slate)
 def test_complete_week_is_not_truncated_to_remaining_twenty(self):
  db=sqlite3.connect(':memory:')
  db.executescript("CREATE TABLE fixtures(id INTEGER PRIMARY KEY,external_id,home,away,kickoff_utc,status); CREATE TABLE predictions(user_id,fixture_id,home_score,away_score,saved_at,UNIQUE(user_id,fixture_id)); CREATE TABLE users(id,is_bot);")
  db.execute(SCHEMA)
  for uid in range(1,51):
   db.execute('INSERT INTO users VALUES(?,1)',(uid,))
  for fid in range(1,31):
   past=fid<=9
   db.execute('INSERT INTO fixtures VALUES(?,?,?,?,?,?)',(fid,fid,'H','A','2000-01-01T15:00:00Z' if past else '2099-01-01T15:00:00Z','FT' if past else 'NS'))
   db.execute('INSERT INTO fixture_gameweeks VALUES(?,?,?,?,?)',(fid,2098,(fid-1)//10+1,'official',0))
   if past:
    for uid in range(1,51):
     db.execute('INSERT INTO predictions VALUES(?,?,?,?,?)',(uid,fid,uid%7,uid//7,'original'))
  before=db.execute('SELECT * FROM predictions').fetchall()
  written,groups=generate_gameweek_predictions(db,[(uid,'',1) for uid in range(1,51)],lambda *args:(1,1))
  self.assertEqual(written,21*50)
  self.assertEqual(groups,{(2098,1):50,(2098,2):50,(2098,3):50})
  self.assertEqual(db.execute('SELECT * FROM predictions WHERE fixture_id<=9').fetchall(),before)
  self.assertTrue(all(db.execute('SELECT COUNT(*) FROM predictions WHERE user_id=?',(uid,)).fetchone()[0]==30 for uid in range(1,51)))
if __name__=='__main__':unittest.main()
