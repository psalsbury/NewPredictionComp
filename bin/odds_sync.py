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
 c.execute("INSERT OR REPLACE INTO sync_meta(key,value) VALUES('last_odds_job',?)",(json.dumps(summary),))
 if str(remaining).replace('.','',1).isdigit():c.execute("INSERT OR REPLACE INTO sync_meta(key,value,updated_at) VALUES('odds_credits_remaining',?,CURRENT_TIMESTAMP)",(str(int(float(remaining))),))
 c.commit()
 print('odds_updated',updated,'unmatched',unmatched,'credits_remaining',remaining)
 return summary
CS_BOOK_ORDER={'paddypower':0,'williamhill':1,'ladbrokes':2,'betfair_ex_uk':3}
def sync_score_odds(c,window_hours=8,retry_minutes=60,max_events=12,min_credits=60):
 """One correct-score snapshot per fixture, taken in the last few hours before kick-off (1 credit each)."""
 c.execute("""CREATE TABLE IF NOT EXISTS fixture_score_odds(fixture_id INTEGER NOT NULL REFERENCES fixtures(id),home_goals INTEGER NOT NULL,away_goals INTEGER NOT NULL,price REAL NOT NULL,bookmaker TEXT NOT NULL,observed_at TEXT NOT NULL,fetched_at TEXT NOT NULL,PRIMARY KEY(fixture_id,home_goals,away_goals))""")
 c.execute("CREATE TABLE IF NOT EXISTS sync_meta(key TEXT PRIMARY KEY,value TEXT NOT NULL,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)")
 now=datetime.now(timezone.utc)
 credits=c.execute("SELECT value FROM sync_meta WHERE key='odds_credits_remaining'").fetchone()
 if credits and credits[0].isdigit() and int(credits[0])<min_credits:return
 due=c.execute("""SELECT f.id,f.kickoff_utc,o.event_id FROM fixtures f JOIN fixture_odds o ON o.fixture_id=f.id
  WHERE f.status NOT IN ('FT','AET','PEN','CANC','ABD','PST','SIM')
  AND julianday(f.kickoff_utc)>julianday('now','+20 minutes') AND julianday(f.kickoff_utc)<=julianday('now',?)
  AND NOT EXISTS(SELECT 1 FROM fixture_score_odds s WHERE s.fixture_id=f.id)
  AND NOT EXISTS(SELECT 1 FROM sync_meta m WHERE m.key='cs_attempt:'||f.id AND julianday(m.value)>julianday('now',?))
  ORDER BY julianday(f.kickoff_utc) LIMIT ?""",(f'+{window_hours} hours',f'-{retry_minutes} minutes',max_events)).fetchall()
 if not due:return
 key=Path('/etc/predictioncomp/odds-api.key').read_text().strip()
 saved=0
 for fid,kick,event_id in due:
  c.execute("INSERT OR REPLACE INTO sync_meta(key,value,updated_at) VALUES(?,?,CURRENT_TIMESTAMP)",('cs_attempt:'+str(fid),now.strftime('%Y-%m-%d %H:%M:%S')));c.commit()
  params=urllib.parse.urlencode(dict(apiKey=key,regions='uk',markets='correct_score',oddsFormat='decimal',dateFormat='iso'))
  url='https://api.the-odds-api.com/v4/sports/soccer_epl/events/'+urllib.parse.quote(event_id)+'/odds?'+params
  try:
   with urllib.request.urlopen(url,timeout=30) as response:
    event=json.load(response);remaining=response.headers.get('x-requests-remaining','')
  except (OSError,ValueError):
   print('score_odds_unavailable',fid);continue
  if remaining:c.execute("INSERT OR REPLACE INTO sync_meta(key,value,updated_at) VALUES('odds_credits_remaining',?,CURRENT_TIMESTAMP)",(str(int(float(remaining))),))
  kickdt=datetime.fromisoformat(kick.replace('Z','+00:00'))
  if kickdt.tzinfo is None:kickdt=kickdt.replace(tzinfo=timezone.utc)
  home,away=event.get('home_team'),event.get('away_team')
  for book in sorted(event.get('bookmakers',[]),key=lambda b:(CS_BOOK_ORDER.get(b['key'],10),b['key'])):
   market=next((m for m in book.get('markets',[]) if m['key']=='correct_score'),None)
   observed=market and (market.get('last_update') or book.get('last_update'))
   if not market or not observed or datetime.fromisoformat(observed.replace('Z','+00:00'))>=kickdt:continue
   rows=[]
   for o in market.get('outcomes',[]):
    try:
     parts=dict(x.rsplit(':',1) for x in o['name'].split('|'));h=int(parts[home]);a=int(parts[away]);price=float(o['price'])
    except (KeyError,ValueError):continue
    if math.isfinite(price) and price>1:rows.append((fid,h,a,price,book['title'],observed,now.isoformat()))
   if len(rows)<10:continue
   c.executemany("INSERT OR REPLACE INTO fixture_score_odds VALUES(?,?,?,?,?,?,?)",rows);saved+=1;break
  c.commit()
 print('score_odds_saved',saved,'of',len(due))
if __name__=='__main__':
 with sqlite3.connect(DB,timeout=30) as c:sync_odds(c)
