import sqlite3
import sys
import unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'bin'))
import bot_backtest
from bot_strategy import Strategy,SCORES

class BacktestTests(unittest.TestCase):
    def run_case(self, observed='2026-01-01T10:00:00Z', fetched='2026-01-01T10:01:00Z'):
        db = sqlite3.connect(':memory:')
        db.executescript("""
        CREATE TABLE fixtures(id INTEGER PRIMARY KEY, external_id INTEGER, kickoff_utc TEXT,
          home TEXT, away TEXT, home_score INTEGER, away_score INTEGER, status TEXT);
        CREATE TABLE fixture_odds(fixture_id INTEGER, home REAL, draw REAL, away REAL,
          observed_at TEXT, fetched_at TEXT);
        INSERT INTO fixtures VALUES(1,123,'2026-01-02T15:00:00Z','A','B',0,1,'FT');
        """)
        db.execute('INSERT INTO fixture_odds VALUES(1,20,10,1.05,?,?)', (observed,fetched))
        archive = [
          dict(kickoff='2026-01-02T15:00:00Z', home='A',away='B',hg=0,ag=1),
          dict(kickoff='2025-12-01T15:00:00Z', home='C',away='D',hg=1,ag=0)]
        calls=[]
        original=Strategy.predict
        def predict(model, grade, uid, external, home, away, kickoff):
            if grade==1:
                calls.append((external,model.forecast(external,home,away,kickoff)[0]))
            return original(model,grade,uid,external,home,away,kickoff)
        before=db.total_changes
        with patch.object(bot_backtest,'load_catalog',return_value={}), \
             patch.object(bot_backtest,'canonical',side_effect=lambda s,c:s), \
             patch.object(bot_backtest,'load_archive',return_value=archive), \
             patch.object(Strategy,'predict',predict):
            output=bot_backtest.evaluate(db)
        self.assertEqual(db.total_changes,before)
        self.assertEqual(output['fixtures'],2)  # Overlap is not counted twice.
        db.close()
        return calls,output

    def test_stored_fixture_uses_real_id_and_archive_has_fallback(self):
        calls,output=self.run_case()
        ids={c[0] for c in calls}
        self.assertIn(123,ids)
        self.assertEqual(len(ids),2)
        self.assertTrue(any(isinstance(i,str) and i.startswith('archive:') for i in ids))
        fresh=next(p for external,p in calls if external==123)
        away=sum(p for (h,a),p in zip(SCORES,fresh) if h<a)
        self.assertGreater(away,.75)
        self.assertEqual(output,self.run_case()[1])

    def test_post_cutoff_odds_are_ignored(self):
        baseline=Strategy([dict(kickoff='2025-12-01T15:00:00Z',home='C',away='D',hg=1,ag=0)],now='2026-01-02T15:00:00Z').forecast(123,'A','B')[0]
        for observed,fetched in [
          ('2026-01-03T10:00:00Z','2026-01-03T10:01:00Z'),
          ('2026-01-01T10:00:00Z','2026-01-03T10:01:00Z'),
          ('2025-12-01T10:00:00Z','2025-12-01T10:01:00Z')]:
            calls,_=self.run_case(observed,fetched)
            actual=next(p for external,p in calls if external==123)
            for actual_p,baseline_p in zip(actual,baseline):
                self.assertAlmostEqual(actual_p,baseline_p,places=14)

if __name__=='__main__':
    unittest.main()
