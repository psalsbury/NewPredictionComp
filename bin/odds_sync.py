#!/usr/bin/env python3
"""One daily EPL 1X2 request; preserve pre-match prices after kick-off."""
import sqlite3,json,urllib.request,urllib.parse,urllib.error,math
from pathlib import Path
from datetime import datetime,timezone
from zoneinfo import ZoneInfo
from club_catalog import load_catalog,canonical
DB='/var/lib/predictioncomp/predictioncomp.sqlite'
def sync_odds(c,force=False):
 c.execute("""CREATE TABLE IF NOT EXISTS fixture_odds(fixture_id INTEGER PRIMARY KEY REFERENCES fixtures(id),event_id TEXT NOT NULL,bookmaker TEXT NOT NULL,home REAL NOT NULL,draw REAL NOT NULL,away REAL NOT NULL,observed_at TEXT NOT NULL,fetched_at TEXT NOT NULL)""")
 c.execute("CREATE TABLE IF NOT EXISTS sync_meta(key TEXT PRIMARY KEY,value TEXT NOT NULL,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)")
 day=datetime.now(ZoneInfo('Europe/London')).date().isoformat()
 last=c.execute("SELECT value FROM sync_meta WHERE key='odds_attempt_day'").fetchone()
 if not force and last and last[0]==day:return
 c.execute("INSERT OR REPLACE INTO sync_meta(key,value) VALUES('odds_attempt_day',?)",(day,));c.commit()
 key=Path('/etc/predictioncomp/odds-api.key').read_text().strip()
 params=urllib.parse.urlencode(dict(apiKey=key,regions='uk',markets='h2h',oddsFormat='decimal',dateFormat='iso'))
 url='https://api.the-odds-api.com/v4/sports/soccer_epl/odds/?'+params
 try:
  with urllib.request.urlopen(url,timeout=30) as response:
   events=json.load(response);remaining=response.headers.get('x-requests-remaining','unknown')
 except urllib.error.HTTPError as e:
  raise RuntimeError('Odds API HTTP '+str(e.code)) from None
 except (OSError,ValueError):
  raise RuntimeError('Odds feed unavailable; stored prices retained') from None
 catalog=load_catalog(c);now=datetime.now(timezone.utc);updated=unmatched=0
 season=now.astimezone(ZoneInfo('Europe/London')).year if now.astimezone(ZoneInfo('Europe/London')).month>=7 else now.year-1
 fixtures=c.execute("SELECT id,home,away,kickoff_utc,status FROM fixtures WHERE kickoff_utc>=? AND kickoff_utc<?",(f'{season}-07-01',f'{season+1}-07-01')).fetchall()
 for event in events:
  home=canonical(event['home_team'],catalog);away=canonical(event['away_team'],catalog)
  candidates=[f for f in fixtures if canonical(f[1],catalog)==home and canonical(f[2],catalog)==away]
  if len(candidates)!=1:unmatched+=1;continue
  fid,_,_,kick,status=candidates[0];kick=datetime.fromisoformat(kick.replace('Z','+00:00'))
  if kick.tzinfo is None:kick=kick.replace(tzinfo=timezone.utc)
  event_kick=datetime.fromisoformat(event['commence_time'].replace('Z','+00:00'))
  if kick<=now or event_kick<=now or status in ('FT','AET','PEN','CANC','ABD','PST','SIM'):continue
  if abs((event_kick-kick).total_seconds())>21600:unmatched+=1;continue
  bookmakers=sorted(event.get('bookmakers',[]),key=lambda b:({'williamhill':0,'ladbrokes':1,'paddypower':2,'betfair_ex_uk':3}.get(b['key'],10),b['key']))
  for book in bookmakers:
   market=next((m for m in book.get('markets',[]) if m['key']=='h2h'),None)
   if not market:continue
   prices={canonical(o['name'],catalog) if o['name']!='Draw' else 'Draw':o['price'] for o in market['outcomes']}
   if not all(k in prices and math.isfinite(float(prices[k])) and float(prices[k])>1 for k in (home,'Draw',away)):continue
   observed=market.get('last_update') or book.get('last_update')
   if not observed:continue
   timestamp=datetime.fromisoformat(observed.replace('Z','+00:00'))
   if timestamp>=kick or timestamp>now:continue
   c.execute("""INSERT INTO fixture_odds VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(fixture_id) DO UPDATE SET event_id=excluded.event_id,bookmaker=excluded.bookmaker,home=excluded.home,draw=excluded.draw,away=excluded.away,observed_at=excluded.observed_at,fetched_at=excluded.fetched_at""",(fid,event['id'],book['title'],prices[home],prices['Draw'],prices[away],observed,now.isoformat()))
   updated+=1;break
 summary={'updated_at':now.isoformat(),'events':len(events),'matched':updated,'unmatched':unmatched,'credits_remaining':remaining}
 c.execute("INSERT OR REPLACE INTO sync_meta(key,value) VALUES('last_odds_job',?)",(json.dumps(summary),));c.commit()
 print('odds_updated',updated,'unmatched',unmatched,'credits_remaining',remaining)
 return summary
if __name__=='__main__':
 with sqlite3.connect(DB,timeout=30) as c:sync_odds(c)
