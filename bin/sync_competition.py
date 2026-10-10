#!/usr/bin/env python3
"""Sync Premier League fixtures and scores; use published round when API plan blocks 2026/27."""
import os, sqlite3, urllib.request, urllib.parse, json, csv, io, hashlib, re, html, subprocess, contextlib, traceback
from email.message import EmailMessage
from datetime import datetime, timezone
from zoneinfo import ZoneInfo

DB='/var/lib/predictioncomp/predictioncomp.sqlite'
KEY=os.environ.get('API_FOOTBALL_KEY','')
FINAL={'FT','AET','PEN'}
TERMINAL=FINAL|{'PST','CANC','ABD','AWD','WO'}
from club_catalog import load_catalog, canonical as catalog_name, register_team, season_year
_catalog=load_catalog()
def canonical(name): return catalog_name(name,_catalog)
def api(params):
 if not KEY: raise RuntimeError('API_FOOTBALL_KEY missing')
 u='https://v3.football.api-sports.io/fixtures?'+urllib.parse.urlencode(params)
 with urllib.request.urlopen(urllib.request.Request(u,headers={'x-apisports-key':KEY}),timeout=30) as r: data=json.load(r)
 if data.get('errors'): raise RuntimeError('API-Football: '+str(data['errors']))
 return data.get('response',[])

def published_source(season,connection=None):
 configured=os.environ.get('PREDICTIONCOMP_PUBLISHED_URL')
 if configured:
  return configured.format(season=season,next_season=season+1,season_code=f'{season%100:02d}{(season+1)%100:02d}')
 with sqlite3.connect(DB) as db:
  row=db.execute('SELECT value FROM sync_meta WHERE key=?',(f'published_fixture_source:{season}',)).fetchone()
  if row:return row[0]
 request=urllib.request.Request('https://www.premierleague.com/en/news',headers={'User-Agent':'PredictionComp fixture sync'})
 with urllib.request.urlopen(request,timeout=30) as response: page=response.read().decode('utf-8')
 code=f'{season%100:02d}{(season+1)%100:02d}'
 match=re.search(r'/en/news/[0-9]+/all-380-fixtures-for-'+code+r'[^"<>\s]*',page,re.I)
 if not match:raise RuntimeError(f'No published fixture source found for season {season}; existing fixtures retained')
 url=urllib.parse.urljoin('https://www.premierleague.com',html.unescape(match.group(0)).rstrip("'"))
 if urllib.parse.urlparse(url).hostname!='www.premierleague.com':raise RuntimeError('Unexpected published fixture host')
 if connection is not None:connection.execute('INSERT OR REPLACE INTO sync_meta(key,value) VALUES(?,?)',(f'published_fixture_source:{season}',url))
 return url
def published_fixtures(season,connection=None):
 request=urllib.request.Request(published_source(season,connection),headers={'User-Agent':'PredictionComp fixture sync'})
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
 fixtures=published_fixtures(season,c)
 for home,away in fixtures:
  register_team(c,home);register_team(c,away)
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
 for team in x['teams'].values():register_team(c,team['name'],team.get('logo'))
 f=x['fixture'];t=x['teams'];g=x.get('goals') or {};status=f['status']['short']
 home,away=canonical(t['home']['name']),canonical(t['away']['name'])
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
def fallback_results(c):
 season=season_year();season_code=f'{season%100:02d}{(season+1)%100:02d}'
 url=f'https://www.football-data.co.uk/mmz4281/{season_code}/E0.csv'
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
def scoreboard_results(c):
 # Poll only dates with unresolved matches old enough to have finished.
 dates=[r[0] for r in c.execute("""SELECT DISTINCT substr(kickoff_utc,1,10) FROM fixtures
  WHERE julianday(kickoff_utc)<=julianday('now','-105 minutes')
  AND julianday(kickoff_utc)>=julianday('now','-7 days')
  AND COALESCE(status,'NS') NOT IN ('FT','AET','PEN','PST','CANC','ABD','AWD','WO')
  ORDER BY 1""")]
 updated=0
 for date in dates:
  url='https://site.api.espn.com/apis/site/v2/sports/soccer/eng.1/scoreboard?'+urllib.parse.urlencode({'dates':date.replace('-',''),'limit':100})
  with urllib.request.urlopen(url,timeout=20) as response: data=json.load(response)
  for event in data.get('events',[]):
   for competition in event.get('competitions',[]):
    status=competition.get('status',event.get('status',{})).get('type',{})
    if status.get('completed') is not True or status.get('name')!='STATUS_FULL_TIME': continue
    teams={x.get('homeAway'):x for x in competition.get('competitors',[])}
    if 'home' not in teams or 'away' not in teams: continue
    home,away=teams['home'],teams['away']
    hs,aws=str(home.get('score','')),str(away.get('score',''))
    if not hs.isdigit() or not aws.isdigit(): continue
    fid=matching(c,home['team']['displayName'],away['team']['displayName'],event.get('date','')[:10])
    if fid is None: continue
    c.execute("""UPDATE fixtures SET home_score=?,away_score=?,status='FT',updated_at=CURRENT_TIMESTAMP
     WHERE id=? AND status NOT IN ('FT','AET','PEN','PST','CANC','ABD','AWD','WO')
     AND julianday(kickoff_utc)<=julianday('now','-105 minutes')""",(int(hs),int(aws),fid))
    updated+=c.execute('SELECT changes()').fetchone()[0]
 return updated

def score(c):
 for fid,h,a in c.execute("SELECT id,home_score,away_score FROM fixtures WHERE status IN ('FT','AET','PEN') AND home_score IS NOT NULL AND away_score IS NOT NULL").fetchall():
  for pid,ph,pa in c.execute('SELECT id,home_score,away_score FROM predictions WHERE fixture_id=?',(fid,)).fetchall():
   outcome=lambda x,y:(x>y)-(x<y)
   pts=5 if (ph==h and pa==a) else (3 if outcome(ph,pa)==outcome(h,a) else 0)
   c.execute('UPDATE predictions SET points=? WHERE id=?',(pts,pid))
def run_sync():
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
 # Fixed fixture seeds removed; source failures retain existing stored fixtures.
 marks=','.join('?' for _ in TERMINAL)
 sql=f"""SELECT external_id FROM fixtures WHERE external_id IS NOT NULL
  AND julianday(kickoff_utc)<=julianday('now','-105 minutes') AND julianday(kickoff_utc)>=julianday('now','-24 hours')
  AND COALESCE(status,'NS') NOT IN ({marks})"""
 ids=[str(r[0]) for r in c.execute(sql,tuple(TERMINAL)).fetchall() if r[0]<900000000]
 if ids:
  try:
   for item in api({'ids':'-'.join(ids)}): save(c,item,False);polled+=1
   c.execute("INSERT INTO sync_meta(key,value) VALUES('results_checked',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP",(datetime.now(timezone.utc).isoformat(),))
  except (RuntimeError,OSError,ValueError) as exc: print('results_api_unavailable',str(exc)[:200])
 try:
  results=fallback_results(c)
  c.execute("INSERT INTO sync_meta(key,value) VALUES('results_checked',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP",(datetime.now(timezone.utc).isoformat(),))
 except (OSError,ValueError) as exc: print('results_fallback_unavailable',str(exc)[:200])
 try:
  fast_results=scoreboard_results(c)
  print('scoreboard_results_updated',fast_results)
 except (OSError,ValueError,KeyError,TypeError) as exc: print('scoreboard_results_unavailable',str(exc)[:200])
 score(c);c.commit()
 load_catalog(c,force=night_due or bool(up))
 print('upcoming_refreshed',up,'official_round_seeded',seeded,'results_polled',polled,'csv_results_updated',results)

def report_snapshot():
 with sqlite3.connect(DB) as db:
  return ({r[0]:r[1:] for r in db.execute('SELECT id,home,away,kickoff_utc,status,home_score,away_score FROM fixtures')},dict(db.execute('SELECT id,points FROM predictions')))
def daily_report(before,after,log,error=None):
 old,old_points=before;new,new_points=after
 added=set(new)-set(old);changed={fid for fid in set(new)&set(old) if new[fid]!=old[fid]}
 lines=['PredictionComp daily fixture job',datetime.now(ZoneInfo('Europe/London')).strftime('%d %b %Y %H:%M UK'),'',
 'Outcome: '+('FAILED' if error else ('Completed with source warnings' if 'unavailable' in log else 'Completed')),
 f'Fixtures added: {len(added)}',f'Existing fixtures changed: {len(changed)}',
 f'Prediction point values changed: {sum(old_points.get(pid)!=value for pid,value in new_points.items())}',
 f'Total fixtures in database: {len(new)}']
 if added or changed:
  lines+=['','Fixture changes (UK time):']
  for fid in sorted(added|changed):
   row=new[fid];dt=datetime.fromisoformat(row[2].replace('Z','+00:00')).replace(tzinfo=timezone.utc).astimezone(ZoneInfo('Europe/London'))
   lines.append(f'{"Added" if fid in added else "Updated"}: {row[0]} v {row[1]} — {dt:%d %b %Y %H:%M}; status {row[3]}')
 lines+=['','Source/run details:',log.strip() or 'No output.']
 if error: lines+=['','Failure details:',error]
 lines+=['','https://predictioncomp.com/']
 message=EmailMessage()
 message['From']='PredictionComp <admin@clubdailyfive.com>'
 message['To']='pete@salsbury.co.uk'
 message['Subject']='PredictionComp daily fixtures — '+('FAILED — ' if error else '')+datetime.now(ZoneInfo('Europe/London')).strftime('%d %b %Y')
 message.set_content(chr(10).join(lines))
 subprocess.run(['/usr/sbin/sendmail','-t','-oi'],input=message.as_bytes(),check=True,timeout=30)
 print('daily_report_queued pete@salsbury.co.uk')
if __name__=='__main__':
 now=datetime.now(ZoneInfo('Europe/London'));season=now.year if now.month>=7 else now.year-1
 with sqlite3.connect(DB) as db:
  db.execute("CREATE TABLE IF NOT EXISTS sync_meta(key TEXT PRIMARY KEY,value TEXT NOT NULL,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)")
  last=db.execute('SELECT value FROM sync_meta WHERE key=?',(f'season_refresh_attempt:{season}',)).fetchone()
 report_due=now.hour>=2 and (not last or last[0]!=now.date().isoformat())
 report_due=report_due or os.environ.get('PREDICTIONCOMP_REPORT_NOW')=='1'
 before=report_snapshot() if report_due else None
 output=io.StringIO();error=None
 try:
  with contextlib.redirect_stdout(output): run_sync()
 except Exception:
  error=traceback.format_exc()
 finally:
  print(output.getvalue(),end='')
  if report_due:
   after=report_snapshot();old,old_points=before;new,new_points=after
   summary={'finished_at':datetime.now(timezone.utc).isoformat(),'status':'Failed' if error else ('Completed with source warnings' if 'unavailable' in output.getvalue() else 'Completed'),'added':len(set(new)-set(old)),'changed':sum(new[fid]!=old[fid] for fid in set(new)&set(old)),'points_changed':sum(old_points.get(pid)!=value for pid,value in new_points.items()),'total_fixtures':len(new),'details':output.getvalue(),'error':error}
   with sqlite3.connect(DB) as report_db:
    report_db.execute("INSERT INTO sync_meta(key,value) VALUES('last_daily_job',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP",(json.dumps(summary),))
   try: daily_report(before,after,output.getvalue(),error)
   except Exception as mail_error: print('daily_report_failed',str(mail_error))
 if error: raise RuntimeError(error)
