#!/usr/bin/env python3
"""Daily owner summary for PredictionComp.

Replaces the per-player "new player" email. Runs each morning and reports the previous
UK day: new players (guest or registered), guests who came back, and prediction activity.
Quiet days with no human activity send nothing. Each day is sent at most once.
"""
import argparse,fcntl,json,os,sqlite3,subprocess
from datetime import datetime,timedelta,timezone
from email.message import EmailMessage
from zoneinfo import ZoneInfo

DB='/var/lib/predictioncomp/predictioncomp.sqlite'
TO='pete@salsbury.co.uk'
FROM='PredictionComp <admin@predictioncomp.com>'
UK=ZoneInfo('Europe/London')
# Same rule as pc_listed_sql() in index.php: guests join the global table after two matchweeks (Fri-Thu) or an email.
LISTED="(u.guest_since IS NULL OR u.email IS NOT NULL OR (SELECT COUNT(DISTINCT CAST((julianday(gf.kickoff_utc)-2451550.5)/7 AS INTEGER)) FROM predictions gp JOIN fixtures gf ON gf.id=gp.fixture_id WHERE gp.user_id=u.id)>=2)"

def utc(d):return d.astimezone(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')

def report_window(now):
 day=now.astimezone(UK).date()-timedelta(days=1)
 start=datetime(day.year,day.month,day.day,tzinfo=UK)
 return day,start,start+timedelta(days=1)

def gather(db,start,end):
 s,e=utc(start),utc(end)
 kind="CASE WHEN u.email IS NOT NULL THEN 'registered' WHEN u.guest_since IS NOT NULL THEN 'guest' ELSE 'no email' END"
 new=db.execute(f"""SELECT u.id,u.display_name,{kind} kind,{LISTED} listed,(SELECT COUNT(*) FROM predictions p WHERE p.user_id=u.id) picks
  FROM users u WHERE COALESCE(u.is_bot,0)=0 AND u.first_played_at>=? AND u.first_played_at<? ORDER BY u.first_played_at""",(s,e)).fetchall()
 back=db.execute(f"""SELECT u.id,u.display_name,{LISTED} listed,COUNT(p.id) picks FROM users u JOIN predictions p ON p.user_id=u.id
  WHERE COALESCE(u.is_bot,0)=0 AND u.guest_since IS NOT NULL AND u.email IS NULL AND u.first_played_at<? AND p.saved_at>=? AND p.saved_at<?
  GROUP BY u.id ORDER BY u.display_name""",(s,s,e)).fetchall()
 activity=db.execute("""SELECT COUNT(*) picks,COUNT(DISTINCT p.user_id) players FROM predictions p JOIN users u ON u.id=p.user_id
  WHERE COALESCE(u.is_bot,0)=0 AND p.saved_at>=? AND p.saved_at<?""",(s,e)).fetchone()
 totals=db.execute(f"""SELECT SUM(u.email IS NOT NULL) registered,SUM(u.email IS NULL) guests,
  SUM(u.email IS NULL AND {LISTED}) guests_listed FROM users u
  WHERE COALESCE(u.is_bot,0)=0 AND u.first_played_at IS NOT NULL""").fetchone()
 return {'new':[dict(r) for r in new],'back':[dict(r) for r in back],'picks':activity['picks'],'active':activity['players'],
  'registered':totals['registered'] or 0,'guests':totals['guests'] or 0,'guests_listed':totals['guests_listed'] or 0}

def plural(n,word):return f"{n} {word}{'' if n==1 else 's'}"

def build(day,data):
 n,b=len(data['new']),len(data['back'])
 subject=f"PredictionComp daily: {plural(n,'new player')}"+(f", {b} guest{'' if b==1 else 's'} back" if b else '')+f" ({day:%a %-d %b})"
 lines=[f"PredictionComp summary for {day:%A %-d %B %Y} (UK time)",'']
 lines.append(f"New players ({n})")
 for r in data['new']:
  table='' if r['kind']!='guest' else (' · on the table' if r['listed'] else ' · not on the table yet')
  lines.append(f"- {r['display_name']} · {r['kind']}{table} · {plural(r['picks'],'pick')}")
 if not n:lines.append('- None')
 lines+=['',f"Guests who came back ({b})"]
 for r in data['back']:
  lines.append(f"- {r['display_name']} · {plural(r['picks'],'pick')} saved"+(' · on the table' if r['listed'] else ' · not on the table yet'))
 if not b:lines.append('- None')
 lines+=['','Activity',f"- {plural(data['picks'],'prediction')} saved or changed by {plural(data['active'],'player')}",
  '','Players so far',f"- {data['registered']} registered",f"- {plural(data['guests'],'guest')}, {data['guests_listed']} on the global table",
  '','Admin: https://predictioncomp.com/admin.php']
 msg=EmailMessage();msg['From']=FROM;msg['To']=TO;msg['Subject']=subject;msg.set_content('\n'.join(lines)+'\n',cte='quoted-printable')
 return msg

def run(db,now,dry=False,force=False):
 day,start,end=report_window(now);key='daily_summary:'+day.isoformat()
 if not force and db.execute('SELECT 1 FROM sync_meta WHERE key=?',(key,)).fetchone():return {'day':str(day),'status':'already_sent'}
 data=gather(db,start,end)
 if not data['new'] and not data['back'] and not data['picks']:
  if not dry:db.execute("INSERT OR REPLACE INTO sync_meta(key,value,updated_at) VALUES(?,'quiet',CURRENT_TIMESTAMP)",(key,));db.commit()
  return {'day':str(day),'status':'quiet'}
 msg=build(day,data)
 if dry:print(msg.as_string());return {'day':str(day),'status':'dry_run'}
 subprocess.run(['/usr/sbin/sendmail','-t','-oi'],input=msg.as_bytes(),check=True,timeout=30)
 ids=[r['id'] for r in data['new']]
 db.executemany('UPDATE users SET play_alert_sent_at=COALESCE(play_alert_sent_at,CURRENT_TIMESTAMP) WHERE id=?',[(i,) for i in ids])
 db.execute("INSERT OR REPLACE INTO sync_meta(key,value,updated_at) VALUES(?,'sent',CURRENT_TIMESTAMP)",(key,));db.commit()
 return {'day':str(day),'status':'sent','new_players':len(ids),'guests_back':len(data['back'])}

if __name__=='__main__':
 ap=argparse.ArgumentParser();ap.add_argument('--dry-run',action='store_true',help='print the email instead of sending');ap.add_argument('--force',action='store_true',help='send even if this day was already sent');ap.add_argument('--db',default=DB)
 args=ap.parse_args()
 with open(os.path.join(os.path.dirname(args.db),'daily-summary.lock'),'a') as lock:
  try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
  except BlockingIOError:raise SystemExit(0)
  db=sqlite3.connect(args.db,timeout=30);db.row_factory=sqlite3.Row
  print(json.dumps(run(db,datetime.now(timezone.utc),args.dry_run,args.force)))
