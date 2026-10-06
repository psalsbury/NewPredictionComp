#!/usr/bin/env python3
import argparse,fcntl,hashlib,json,secrets,sqlite3,subprocess
from datetime import datetime,timedelta,timezone
from zoneinfo import ZoneInfo
from email.message import EmailMessage
DB='/var/lib/predictioncomp/predictioncomp.sqlite'
UK=ZoneInfo('Europe/London')
def parse(value):
 d=datetime.fromisoformat(value.replace('Z','+00:00'))
 return d.replace(tzinfo=timezone.utc) if d.tzinfo is None else d
def week_key(d):
 d=d.astimezone(UK)
 return (d-timedelta(days=(d.weekday()+3)%7)).date().isoformat()
def candidates(db,now):
 fixtures=db.execute("SELECT id,home,away,kickoff_utc,status FROM fixtures WHERE julianday(kickoff_utc)>julianday(?) AND julianday(kickoff_utc)<julianday(?,'+8 days') AND COALESCE(status,'NS') NOT IN ('PST','CANC','ABD','SUSP','FT','AET','PEN','SIM') ORDER BY julianday(kickoff_utc),id",(now.isoformat(),now.isoformat())).fetchall()
 result=[]
 for user in db.execute("SELECT id,email,display_name,prediction_reminders,reminder_lead_hours FROM users WHERE prediction_reminders=1 AND is_bot=0 AND email IS NOT NULL AND (email_verified_at IS NOT NULL OR google_sub IS NOT NULL)"):
  email=user['email'].strip()
  if '@' not in email or any(x in email for x in '\r\n'):continue
  saved={r[0] for r in db.execute('SELECT fixture_id FROM predictions WHERE user_id=?',(user['id'],))}
  missing=[f for f in fixtures if f['id'] not in saved]
  if not missing:continue
  first=missing[0];kick=parse(first['kickoff_utc']);lead=user['reminder_lead_hours'] if user['reminder_lead_hours'] in (2,24) else 24
  if not (0<(kick-now).total_seconds()<=lead*3600):continue
  week=week_key(kick);games=[f for f in missing if week_key(parse(f['kickoff_utc']))==week]
  log=db.execute('SELECT status,attempts,attempted_at FROM prediction_reminder_log WHERE user_id=? AND week_key=?',(user['id'],week)).fetchone()
  if log:
   if log['status']=='sent' or log['attempts']>=3:continue
   if log['attempted_at'] and (now-parse(log['attempted_at'])).total_seconds()<3600:continue
  result.append({'user':dict(user),'week':week,'games':games,'first':first})
 return result
def build_message(candidate,token):
 user=candidate['user'];first=candidate['first'];kick=parse(first['kickoff_utc']).astimezone(UK);n=len(candidate['games'])
 unsubscribe='https://predictioncomp.com/reminder-settings.php?token='+token
 msg=EmailMessage();msg['From']='PredictionComp <admin@clubdailyfive.com>';msg['To']=user['email'];msg['Subject']=f'PredictionComp: {n} prediction'+('s' if n!=1 else '')+' still to make'
 msg.set_content(f"Hi {user['display_name']},\n\nYou have {n} unfinished prediction"+('s' if n!=1 else '')+f" for the week of {candidate['week']}.\n\nYour next missing pick is {first['home']} v {first['away']}.\nIt locks at {kick:%a %d %b, %H:%M} UK time.\n\nFinish your picks:\nhttps://predictioncomp.com/?fixture={first['id']}&finish_picks=1#predict\n\nA saved 0–0 counts as complete.\n\nYou enabled optional prediction reminders. We send at most one per matchweek.\nChange timing or switch them off in Account:\nhttps://predictioncomp.com/?view=account\n\nTurn off reminders:\n{unsubscribe}\n")
 return msg
def run(db,now,dry=False,sender=None):
 eligible=candidates(db,now);sent=failed=0
 for item in eligible:
  if dry:continue
  uid=item['user']['id'];week=item['week'];token=secrets.token_urlsafe(32);token_hash=hashlib.sha256(token.encode()).hexdigest()
  # Recheck opt-in before claiming and sending.
  if db.execute('SELECT prediction_reminders FROM users WHERE id=?',(uid,)).fetchone()[0]!=1:continue
  db.execute("INSERT INTO prediction_reminder_log(user_id,week_key,status,attempts,attempted_at,unsubscribe_hash) VALUES(?,?,'sending',1,?,?) ON CONFLICT(user_id,week_key) DO UPDATE SET status='sending',attempts=attempts+1,attempted_at=excluded.attempted_at,unsubscribe_hash=excluded.unsubscribe_hash",(uid,week,now.isoformat(),token_hash));db.commit()
  try:
   msg=build_message(item,token)
   if sender:sender(msg)
   else:subprocess.run(['/usr/sbin/sendmail','-t','-oi'],input=msg.as_bytes(),check=True,timeout=30)
   db.execute("UPDATE prediction_reminder_log SET status='sent',sent_at=? WHERE user_id=? AND week_key=?",(now.isoformat(),uid,week));db.commit();sent+=1
  except Exception as exc:
   db.execute("UPDATE prediction_reminder_log SET status='failed' WHERE user_id=? AND week_key=?",(uid,week));db.commit();failed+=1
   print(json.dumps({'event':'reminder_send_failed','user_id':uid,'error_type':type(exc).__name__}))
 return {'eligible':len(eligible),'queued':sent,'failed':failed,'dry_run':dry}
if __name__=='__main__':
 parser=argparse.ArgumentParser();parser.add_argument('--dry-run',action='store_true');args=parser.parse_args()
 with open('/var/lib/predictioncomp/reminders.lock','a') as lock:
  try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
  except BlockingIOError:raise SystemExit(0)
  db=sqlite3.connect(DB,timeout=30);db.row_factory=sqlite3.Row
  print(json.dumps(run(db,datetime.now(timezone.utc),args.dry_run)))
