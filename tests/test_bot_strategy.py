import sys
import sqlite3
import unittest
from pathlib import Path
from datetime import datetime,timezone,timedelta
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'bin'))
from bot_strategy import Strategy,selection_weights,utilities,poisson_grid,SCORES,PRIOR
from gameweeks import generate_gameweek_predictions,unique_gameweek

class StrategyTests(unittest.TestCase):
    def test_points_formula(self):
        p=poisson_grid(1.8,.8);u=utilities(p)
        for i,(h,a) in enumerate(SCORES):
            actual=sum(prob*(5 if (h,a)==(x,y) else 3 if (h>a)-(h<a)==(x>y)-(x<y) else 0) for (x,y),prob in zip(SCORES,p))
            self.assertAlmostEqual(u[i],actual)

    def test_expected_strength_is_ordered_for_many_fixtures(self):
        for hx in [.3,.7,1.2,1.8,2.8,3.8]:
            for ax in [.3,.9,1.4,2.2,3.5]:
                u=utilities(poisson_grid(hx,ax))
                expected=[sum(p*v for p,v in zip(selection_weights(g,u),u)) for g in range(1,6)]
                self.assertEqual(expected,sorted(expected,reverse=True))
                self.assertGreater(expected[0],expected[-1])

    def test_level_five_is_team_blind_and_sensible(self):
        m=Strategy([],now='2026-10-20T12:00:00Z')
        self.assertEqual(m.predict(5,7,123,'A','B'),m.predict(5,7,123,'B','A'))
        self.assertAlmostEqual(sum(PRIOR),1)
        self.assertGreater(sum(p for (h,a),p in zip(SCORES,PRIOR) if h<=3 and a<=3),.9)
        self.assertLess(sum(p for (h,a),p in zip(SCORES,PRIOR) if h+a>=8),.01)

    def test_future_and_live_results_cannot_leak(self):
        cutoff='2026-10-20T12:00:00Z'
        clean=Strategy([],now=cutoff)
        dirty=Strategy([dict(kickoff='2026-10-20T11:30:00Z',home='A',away='B',hg=9,ag=0),dict(kickoff='2026-10-21T12:00:00Z',home='A',away='B',hg=0,ag=9)],now=cutoff)
        self.assertEqual(clean.forecast(1,'A','B'),dirty.forecast(1,'A','B'))

    def test_market_probabilities_and_timestamp_guard(self):
        odds={1:dict(home=1.3,draw=5,away=10,observed_at='2026-10-20T10:00:00Z',fetched_at='2026-10-20T10:01:00Z')}
        m=Strategy([],odds,now='2026-10-20T12:00:00Z');p=m.forecast(1,'A','B')[0]
        home=sum(v for (h,a),v in zip(SCORES,p) if h>a)
        self.assertGreater(home,.65)
        odds[1]['fetched_at']='2026-10-21T12:00:00Z'
        self.assertEqual(Strategy([],odds,now=m.now).forecast(1,'A','B'),Strategy([],now=m.now).forecast(1,'A','B'))

    def test_uniqueness_uses_small_plausible_change(self):
        slate=[(1,0)]*10;used={tuple(slate)}
        opts=[[((1,0),.8),((2,0),.19),((0,5),.0001)] for _ in slate]
        chosen=unique_gameweek(slate,used,3,[False]*9+[True],opts)
        self.assertEqual(chosen[:-1],slate[:-1]);self.assertEqual(chosen[-1],(2,0))

    def test_refresh_only_unlocked_bots_and_is_idempotent(self):
        c=sqlite3.connect(':memory:')
        c.executescript('CREATE TABLE users(id INTEGER PRIMARY KEY,is_bot INTEGER);CREATE TABLE fixtures(id INTEGER PRIMARY KEY,external_id INTEGER,kickoff_utc TEXT,home TEXT,away TEXT,status TEXT);CREATE TABLE fixture_gameweeks(fixture_id INTEGER PRIMARY KEY,season INTEGER,gameweek INTEGER);CREATE TABLE predictions(user_id INTEGER,fixture_id INTEGER,home_score INTEGER,away_score INTEGER,points INTEGER,saved_at TEXT,PRIMARY KEY(user_id,fixture_id));')
        c.executemany('INSERT INTO users VALUES(?,?)',[(1,1),(2,1),(3,0)])
        now=datetime.now(timezone.utc)
        rows=[(1,11,(now-timedelta(days=2)).isoformat(),'A','B','FT'),(2,12,(now+timedelta(days=2)).isoformat(),'C','D','NS'),(3,13,(now+timedelta(days=2)).isoformat(),'E','F','PST')]
        c.executemany('INSERT INTO fixtures VALUES(?,?,?,?,?,?)',rows)
        c.executemany('INSERT INTO fixture_gameweeks VALUES(?,2026,8)',[(1,),(2,),(3,)])
        c.executemany("INSERT INTO predictions VALUES(?,?,4,4,5,'original')",[(u,f) for u in [1,2,3] for f in [1,2,3]])
        frozen=c.execute('SELECT * FROM predictions WHERE user_id=3 OR fixture_id IN (1,3) ORDER BY user_id,fixture_id').fetchall()
        bots=[(1,'A',1),(2,'B',5)]
        args=(c,bots,lambda *a:(1,0))
        written,_=generate_gameweek_predictions(*args,refresh=True)
        self.assertEqual(written,2)
        self.assertEqual(frozen,c.execute('SELECT * FROM predictions WHERE user_id=3 OR fixture_id IN (1,3) ORDER BY user_id,fixture_id').fetchall())
        self.assertNotEqual(c.execute('SELECT home_score,away_score FROM predictions WHERE user_id=1 AND fixture_id=2').fetchone(),c.execute('SELECT home_score,away_score FROM predictions WHERE user_id=2 AND fixture_id=2').fetchone())
        self.assertEqual(generate_gameweek_predictions(*args,refresh=True)[0],0)

if __name__=='__main__':unittest.main()
