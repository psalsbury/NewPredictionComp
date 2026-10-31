#!/usr/bin/env python3
"""Live scores for matches in progress (ESPN scoreboard), every minute.

Live scores are kept in their own table (live_scores) and are display-only:
final results, points and tables still come from sync_competition.py, which
confirms full time from 1h45 after kick-off. Nothing is fetched unless a
fixture kicked off within the last 2h30 and has no final result yet.
"""
import json, os, sqlite3, sys, urllib.parse, urllib.request
from datetime import datetime, timezone

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from club_catalog import load_catalog, canonical as catalog_name

DB = os.environ.get('PREDICTIONCOMP_DB', '/var/lib/predictioncomp/predictioncomp.sqlite')
TERMINAL = ('FT', 'AET', 'PEN', 'PST', 'CANC', 'ABD', 'AWD', 'WO')
LIVE_WINDOW = f"""julianday(kickoff_utc)<=julianday('now','+1 minute') AND julianday(kickoff_utc)>=julianday('now','-150 minutes')
  AND COALESCE(status,'NS') NOT IN ({','.join("'" + s + "'" for s in TERMINAL)})"""
UA = 'PredictionComp live scores'


def main():
    c = sqlite3.connect(DB, timeout=30)
    c.execute('PRAGMA busy_timeout=30000')
    c.execute("""CREATE TABLE IF NOT EXISTS live_scores(fixture_id INTEGER PRIMARY KEY REFERENCES fixtures(id),
        home_score INTEGER NOT NULL, away_score INTEGER NOT NULL, state TEXT NOT NULL, clock TEXT NOT NULL,
        updated_at TEXT NOT NULL)""")
    c.execute("DELETE FROM live_scores WHERE updated_at < datetime('now','-1 day')")
    dates = [r[0] for r in c.execute(f'SELECT DISTINCT substr(kickoff_utc,1,10) FROM fixtures WHERE {LIVE_WINDOW}')]
    if not dates:
        c.commit(); return
    catalog = load_catalog()
    canon = lambda n: catalog_name(n, catalog)
    updated = 0
    for date in dates:
        url = 'https://site.api.espn.com/apis/site/v2/sports/soccer/eng.1/scoreboard?' + urllib.parse.urlencode({'dates': date.replace('-', ''), 'limit': 100})
        try:
            req = urllib.request.Request(url, headers={'User-Agent': UA})
            data = json.load(urllib.request.urlopen(req, timeout=15))
        except Exception as exc:
            print('live_unavailable', str(exc)[:200]); continue
        fixtures = c.execute(f'SELECT id,home,away FROM fixtures WHERE substr(kickoff_utc,1,10)=? AND {LIVE_WINDOW}', (date,)).fetchall()
        for event in data.get('events', []):
            for comp in event.get('competitions', []):
                st = comp.get('status', event.get('status', {}))
                kind = st.get('type', {})
                state = kind.get('state')  # pre / in / post
                if state not in ('in', 'post'):
                    continue
                teams = {x.get('homeAway'): x for x in comp.get('competitors', [])}
                if 'home' not in teams or 'away' not in teams:
                    continue
                hs, aws = str(teams['home'].get('score', '')), str(teams['away'].get('score', ''))
                if not hs.isdigit() or not aws.isdigit():
                    continue
                h, a = canon(teams['home']['team']['displayName']), canon(teams['away']['team']['displayName'])
                fid = next((f for f, fh, fa in fixtures if canon(fh) == h and canon(fa) == a), None)
                if fid is None:
                    continue
                name = kind.get('name', '')
                if name == 'STATUS_HALFTIME':
                    clock = 'HT'
                elif state == 'post':
                    clock = 'FT'
                else:
                    clock = (st.get('displayClock') or kind.get('shortDetail') or '').strip() or 'Live'
                c.execute("""INSERT INTO live_scores(fixture_id,home_score,away_score,state,clock,updated_at) VALUES(?,?,?,?,?,?)
                    ON CONFLICT(fixture_id) DO UPDATE SET home_score=excluded.home_score,away_score=excluded.away_score,
                    state=excluded.state,clock=excluded.clock,updated_at=excluded.updated_at""",
                          (fid, int(hs), int(aws), 'post' if state == 'post' else 'in', clock[:12], datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')))
                updated += 1
    c.execute("INSERT INTO sync_meta(key,value) VALUES('live_checked',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP",
              (datetime.now(timezone.utc).isoformat(),))
    c.commit()
    print('live_scores_updated', updated)


if __name__ == '__main__':
    main()
