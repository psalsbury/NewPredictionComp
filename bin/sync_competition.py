#!/usr/bin/env python3
"""Sync Premier League fixtures and scores; use published round when API plan blocks 2026/27."""
import os, sqlite3, urllib.request, urllib.parse, json, csv, io, hashlib, re, html
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

PUBLISHED_URL='https://www.premierleague.com/en/news/4675097/all-380-fixtures-for-202627-premier-league-season'
ALIASES.update({'Man Utd':'Manchester United','Spurs':'Tottenham Hotspur'})
def published_fixtures(season):
 if season!=2026: raise RuntimeError('Published source is configured for 2026/27 only')
 request=urllib.request.Request(PUBLISHED_URL,headers={'User-Agent':'PredictionComp fixture sync'})
 with urllib.request.urlopen(request,timeout=30) as response: page=response.read().decode('utf-8')
 fixtures={}
 for paragraph in re.findall(r'<p\b[^>]*>(.*?)</p>',page,re.S|re.I):
  lines=[html.unescape(re.sub(r'<[^>]+>','',line)).strip() for line in re.split(r'<br\s*/?>',paragraph,flags=re.I)]
  if not lines: continue
  date_match=re.fullmatch(r'(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday) (\d{1,2}) ([A-Za-z]+)(?: (\d{4}))?',lines[0])
  if not date_match: continue
  weekday,day,month,year=date_match.groups()
  month_number=datetime.strptime(month,'%B').month
  year=int(year) if year else (season if month_number>=7 else season+1)
  for line in lines[1:]:
   line=re.sub(r'\s*\([^)]*\).*$', '',line).rstrip('*').strip()
   match=re.fullmatch(r'(?:(\d{1,2}:\d{2})\s+(?:GMT\s+)?)?(.+?)\s+v\s+(.+)',line)
   if not match: continue
   clock,home,away=match.groups();home=canonical(home.strip());away=canonical(away.strip())
   clock=clock or ('15:00' if weekday in ('Saturday','Sunday') else '20:00')
   local=datetime.strptime(f'{year}-{month_number:02d}-{int(day):02d} {clock}','%Y-%m-%d %H:%M').replace(tzinfo=ZoneInfo('Europe/London'))
   fixtures[(home,away)]=local.astimezone(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
 # Repeated pairings in the article can reflect later TV changes: last listing wins.
 teams={team for pair in fixtures for team in pair}
 if len(teams)!=20 or len(fixtures)!=380 or any((h,a) not in fixtures for h in teams for a in teams if h!=a):
  raise RuntimeError(f'Published fixture validation failed: {len(fixtures)} pairings, {len(teams)} teams')
 return fixtures
def refresh_published(c,season):
 fixtures=published_fixtures(season)
 now=datetime.now(timezone.utc);added=updated=0
 for (home,away),kick in fixtures.items():
  if datetime.fromisoformat(kick.replace('Z','+00:00'))<=now: continue
  rows=[(fid,external,status) for fid,h,a,external,status in c.execute("SELECT id,home,away,external_id,status FROM fixtures WHERE kickoff_utc>=? AND kickoff_utc<?",(f'{season}-07-01',f'{season+1}-07-01')) if canonical(h)==home and canonical(a)==away]
  if len(rows)>1: raise RuntimeError(f'Ambiguous existing pairing: {home} v {away}')
  if rows:
   fid,external,status=rows[0]
   if status in FINAL or (external is not None and external<900000000): continue
   c.execute("UPDATE fixtures SET kickoff_utc=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND kickoff_utc<>?",(kick,fid,kick))
   updated+=c.execute('SELECT changes()').fetchone()[0]
  else:
   external=900000000+int(hashlib.sha256(f'PL{season}:{home}:{away}'.encode()).hexdigest()[:12],16)
   c.execute("INSERT INTO fixtures(external_id,round,kickoff_utc,home,away,status) VALUES(?,?,?,?,?,'NS')",(external,f'Premier League {season}/{str(season+1)[2:]}',kick,home,away))
   added+=1
 return added,updated

def matching(c,home,away,date):
 for fid,h,a in c.execute("SELECT id,home,away FROM fixtures WHERE substr(kickoff_utc,1,10)=?",(date,)):
  if canonical(h)==canonical(home) and canonical(a)==canonical(away): return fid
 return None
def save(c,x,full=True):
 f=x['fixture'];t=x['teams'];g=x.get('goals') or {};status=f['status']['short']
 home,away=t['home']['name'],t['away']['name']
 existing=None
 linked=c.execute('SELECT id FROM fixtures WHERE external_id=?',(f['id'],)).fetchone()
 if linked: existing=linked[0]
 else:
  # A Premier League season has one home/away pairing: retain seeded IDs after a date change.
  season=int(x['league'].get('season') or f['date'][:4])
  candidates=[fid for fid,h,a in c.execute("SELECT id,home,away FROM fixtures WHERE external_id>=900000000 AND kickoff_utc>=? AND kickoff_utc<?",(f'{season}-07-01',f'{season+1}-07-01')) if canonical(h)==canonical(home) and canonical(a)==canonical(away)]
  if len(candidates)==1: existing=candidates[0]
  if existing is None: existing=matching(c,home,away,f['date'][:10])
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
  season_pair=any(canonical(h)==home and canonical(a)==away for h,a in c.execute("SELECT home,away FROM fixtures WHERE kickoff_utc>=? AND kickoff_utc<?",('2026-07-01','2027-07-01')))
  if dt<=datetime.now(timezone.utc) or season_pair: continue
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
uk_now=datetime.now(ZoneInfo('Europe/London'))
season=uk_now.year if uk_now.month>=7 else uk_now.year-1
night_key=f'season_refresh_attempt:{season}'
last_night=c.execute('SELECT value FROM sync_meta WHERE key=?',(night_key,)).fetchone()
night_due=uk_now.hour>=2 and (not last_night or last_night[0]!=uk_now.date().isoformat())
if night_due:
 # Record attempts too, so an unavailable subscription does not retry every two minutes.
 c.execute("INSERT INTO sync_meta(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP",(night_key,uk_now.date().isoformat()))
 c.commit()
 try:
  season_items=api({'league':39,'season':season})
  if not season_items: raise RuntimeError('Season source returned no fixtures')
  for item in season_items: save(c,item,True)
  c.execute("INSERT INTO sync_meta(key,value) VALUES('season_refresh_success',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP",(datetime.now(timezone.utc).isoformat(),))
  print('season_fixtures_refreshed',len(season_items),'season',season)
 except (RuntimeError,OSError,ValueError) as exc:
  print('season_refresh_unavailable',str(exc)[:200])
  try:
   c.execute('SAVEPOINT published_refresh')
   added,changed=refresh_published(c,season)
   c.execute('RELEASE published_refresh')
   c.execute("INSERT INTO sync_meta(key,value) VALUES('published_refresh_success',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP",(datetime.now(timezone.utc).isoformat(),))
   print('published_season_added',added,'updated',changed)
  except (RuntimeError,OSError,ValueError,sqlite3.Error) as fallback_exc:
   c.execute('ROLLBACK TO published_refresh');c.execute('RELEASE published_refresh')
   print('published_refresh_unavailable',str(fallback_exc)[:200])
if hourly:
 try:
  c.execute("INSERT INTO sync_meta(key,value) VALUES('upcoming_sync',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP",(datetime.now(timezone.utc).isoformat(),))
  c.commit()
  upcoming=api({'league':39,'season':season,'next':20})
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