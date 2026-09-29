#!/usr/bin/env python3
"""Sync Premier League fixtures and scores; use published round when API plan blocks 2026/27."""
import os, sqlite3, urllib.request, urllib.parse, json, csv, io, hashlib
from datetime import datetime, timezone
from zoneinfo import ZoneInfo

DB='/var/lib/predictioncomp/predictioncomp.sqlite'
KEY=os.environ.get('API_FOOTBALL_KEY','')
FINAL={'FT','AET','PEN'}
TERMINAL=FINAL|{'PST','CANC','ABD','AWD','WO'}
# Premier League fixture list, https://www.premierleague.com/en/news/4675097/
# UK local time; published fixtures may change and should be reviewed against the official list.
NEXT_ROUND=[
 ('2026-10-10','12:30','Arsenal','Leeds United'),
 ('2026-10-10','15:00','Aston Villa','Brentford'),
 ('2026-10-10','15:00','Chelsea','AFC Bournemouth'),
 ('2026-10-10','15:00','Ipswich Town','Fulham'),
 ('2026-10-10','15:00','Sunderland','Brighton & Hove Albion'),
 ('2026-10-10','17:30','Manchester United','Tottenham Hotspur'),
 ('2026-10-11','14:00','Crystal Palace','Nottingham Forest'),
 ('2026-10-11','14:00','Hull City','Everton'),
 ('2026-10-11','16:30','Liverpool','Manchester City'),
 ('2026-10-12','20:00','Coventry City','Newcastle United'),
]
ALIASES={'Bournemouth':'AFC Bournemouth','Brighton':'Brighton & Hove Albion','Coventry':'Coventry City','Hull':'Hull City','Ipswich':'Ipswich Town','Leeds':'Leeds United','Man City':'Manchester City','Man United':'Manchester United',"Nott'm Forest":'Nottingham Forest','Newcastle':'Newcastle United','Tottenham':'Tottenham Hotspur'}
def canonical(name): return ALIASES.get(name,name)
def api(params):
 if not KEY: raise RuntimeError('API_FOOTBALL_KEY missing')
 u='https://v3.football.api-sports.io/fixtures?'+urllib.parse.urlencode(params)
 with urllib.request.urlopen(urllib.request.Request(u,headers={'x-apisports-key':KEY}),timeout=30) as r: data=json.load(r)
 if data.get('errors'): raise RuntimeError('API-Football: '+str(data['errors']))
 return data.get('response',[])
def matching(c,home,away,date):
 for fid,h,a in c.execute("SELECT id,home,away FROM fixtures WHERE substr(kickoff_utc,1,10)=?",(date,)):
  if canonical(h)==canonical(home) and canonical(a)==canonical(away): return fid
 return None
def save(c,x,full=True):
 f=x['fixture'];t=x['teams'];g=x.get('goals') or {};status=f['status']['short']
 home,away=t['home']['name'],t['away']['name']
 existing=matching(c,home,away,f['date'][:10])
 if existing and not c.execute('SELECT 1 FROM fixtures WHERE external_id=?',(f['id'],)).fetchone():
  c.execute('UPDATE fixtures SET external_id=? WHERE id=?',(f['id'],existing))
 if full:
  c.execute("""INSERT INTO fixtures(external_id,round,kickoff_utc,home,away,home_score,away_score,status,updated_at)
   VALUES(?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(external_id) DO UPDATE SET
   round=excluded.round,kickoff_utc=excluded.kickoff_utc,home=excluded.home,away=excluded.away,
   home_score=excluded.home_score,away_score=excluded.away_score,status=excluded.status,updated_at=CURRENT_TIMESTAMP""",
   (f['id'],x['league'].get('round'),f['date'],home,away,g.get('home'),g.get('away'),status))
 else:
  c.execute('UPDATE fixtures SET home_score=?,away_score=?,status=?,updated_at=CURRENT_TIMESTAMP WHERE external_id=?',
   (g.get('home'),g.get('away'),status,f['id']))
def seed_round(c):
 count=0
 for day,clock,home,away in NEXT_ROUND:
  dt=datetime.strptime(day+' '+clock,'%Y-%m-%d %H:%M').replace(tzinfo=ZoneInfo('Europe/London')).astimezone(timezone.utc)
  if dt<=datetime.now(timezone.utc) or matching(c,home,away,day): continue
  external=900000000+int(hashlib.sha256(f'PL2627:{day}:{home}:{away}'.encode()).hexdigest()[:10],16)%90000000
  c.execute("""INSERT OR IGNORE INTO fixtures(external_id,round,kickoff_utc,home,away,status)
   VALUES(?,?,?,?,?,'NS')""",(external,'Premier League 2026/27 · Round 6',dt.strftime('%Y-%m-%dT%H:%M:%SZ'),home,away))
  count+=c.execute('SELECT changes()').fetchone()[0]
 return count
def fallback_results(c):
 url='https://www.football-data.co.uk/mmz4281/2627/E0.csv'
 with urllib.request.urlopen(url,timeout=30) as response:
  rows=csv.DictReader(io.StringIO(response.read().decode('utf-8-sig')))
  updated=0
  for row in rows:
   if not row.get('FTHG') or not row.get('FTAG'): continue
   date=datetime.strptime(row['Date'],'%d/%m/%Y').strftime('%Y-%m-%d')
   fid=matching(c,row['HomeTeam'],row['AwayTeam'],date)
   if fid:
    c.execute("UPDATE fixtures SET home_score=?,away_score=?,status='FT',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status NOT IN ('FT','AET','PEN')",
      (int(row['FTHG']),int(row['FTAG']),fid))
    updated+=c.execute('SELECT changes()').fetchone()[0]
  return updated
def score(c):
 for fid,h,a in c.execute("SELECT id,home_score,away_score FROM fixtures WHERE status IN ('FT','AET','PEN') AND home_score IS NOT NULL AND away_score IS NOT NULL").fetchall():
  for pid,ph,pa in c.execute('SELECT id,home_score,away_score FROM predictions WHERE fixture_id=?',(fid,)).fetchall():
   outcome=lambda x,y:(x>y)-(x<y)
   pts=5 if (ph==h and pa==a) else (3 if outcome(ph,pa)==outcome(h,a) else 0)
   c.execute('UPDATE predictions SET points=? WHERE id=?',(pts,pid))
c=sqlite3.connect(DB)
c.execute("CREATE TABLE IF NOT EXISTS sync_meta(key TEXT PRIMARY KEY,value TEXT NOT NULL,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)")
last=c.execute("SELECT value FROM sync_meta WHERE key='upcoming_sync'").fetchone()
hourly=True
if last:
 try: hourly=(datetime.now(timezone.utc)-datetime.fromisoformat(last[0])).total_seconds()>=3300
 except ValueError: pass
up=polled=seeded=results=0
if hourly:
 try:
  upcoming=api({'league':39,'season':2026,'next':20})
  for item in upcoming: save(c,item,True)
  up=len(upcoming)
  c.execute("""INSERT INTO sync_meta(key,value) VALUES('upcoming_sync',?)
   ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP""",(datetime.now(timezone.utc).isoformat(),))
 except (RuntimeError, OSError, ValueError) as exc: print('upcoming_api_unavailable',str(exc)[:200])
seeded=seed_round(c)
marks=','.join('?' for _ in TERMINAL)
sql=f"""SELECT external_id FROM fixtures WHERE external_id IS NOT NULL
 AND kickoff_utc<=datetime('now','-105 minutes') AND kickoff_utc>=datetime('now','-24 hours')
 AND COALESCE(status,'NS') NOT IN ({marks})"""
ids=[str(r[0]) for r in c.execute(sql,tuple(TERMINAL)).fetchall() if r[0]<900000000]
if ids:
 try:
  for item in api({'ids':'-'.join(ids)}): save(c,item,False);polled+=1
 except (RuntimeError,OSError,ValueError) as exc: print('results_api_unavailable',str(exc)[:200])
try: results=fallback_results(c)
except (OSError,ValueError) as exc: print('results_fallback_unavailable',str(exc)[:200])
score(c);c.commit()
print('upcoming_refreshed',up,'official_round_seeded',seeded,'results_polled',polled,'csv_results_updated',results)
