#!/usr/bin/env python3
"""Refresh unlocked bot picks using current form and pre-match market prices.

Existing identities, grades, historical predictions, earned points and human
entries are never rewritten. Fixture/result sync remains in sync_competition.
"""
import argparse
import json
import os
import sqlite3
from bot_strategy import from_database, selection_weights, VERSION, when
from bot_history import load_archive
from club_catalog import load_catalog, canonical, season_year
from gameweeks import refresh_gameweeks, generate_gameweek_predictions

DB=os.environ.get('PREDICTIONCOMP_DB','/var/lib/predictioncomp/predictioncomp.sqlite')

def refresh(db, archive_fetch=True, gameweek_refresh=True):
    catalog=load_catalog(db)
    name=lambda value:canonical(value,catalog)
    archive=load_archive(season_year(),name,fetch=archive_fetch)
    if gameweek_refresh:
        try:refresh_gameweeks(db,season_year())
        except (OSError,ValueError,RuntimeError,KeyError) as exc:
            print('bot_gameweeks_existing_membership_retained',type(exc).__name__)
    model=from_database(db,name,archive)
    bots=db.execute('SELECT id,supported_club,bot_grade FROM users WHERE is_bot=1 ORDER BY bot_grade,id').fetchall()
    if not bots or any(g not in range(1,6) for uid,supported,g in bots):
        raise RuntimeError('Bot fleet missing or invalid grades; identities were not modified')
    fixtures={external:(kick,name(h),name(a)) for external,kick,h,a in db.execute('SELECT external_id,kickoff_utc,home,away FROM fixtures')}
    def pick(grade,uid,external,home,away,supported):
        return model.predict(grade,uid,external,name(home),name(away),fixtures[external][0])
    def alternatives(grade,external,home,away):
        return model.alternatives(grade,external,name(home),name(away),fixtures[external][0])
    written,weeks=generate_gameweek_predictions(db,bots,pick,refresh=True,alternatives=alternatives)
    totals={g:[] for g in range(1,6)}
    for external,(kick,home,away) in fixtures.items():
        if when(kick)<=model.now:continue
        values=model.forecast(external,home,away,kick)[1]
        for grade in totals:
            totals[grade].append(sum(p*v for p,v in zip(selection_weights(grade,values),values)))
    summary={'model':VERSION,'at':model.now.isoformat(),'bots':len(bots),'archive_matches':len(archive),
        'future_predictions_changed':written,'gameweeks':len(weeks),'stored_odds':len(model.odds),
        'expected_points_by_level':{g:round(sum(v)/len(v),4) for g,v in totals.items() if v}}
    db.execute('CREATE TABLE IF NOT EXISTS sync_meta(key TEXT PRIMARY KEY,value TEXT NOT NULL,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)')
    db.execute("INSERT INTO sync_meta(key,value) VALUES('last_bot_strategy_job',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP",(json.dumps(summary),))
    return summary

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--dry-run',action='store_true',help='Compute picks, then roll back database changes')
    parser.add_argument('--no-archive-fetch',action='store_true',help='Use cached prior seasons only')
    parser.add_argument('--no-gameweek-refresh',action='store_true',help='Use stored official membership')
    args=parser.parse_args()
    with sqlite3.connect(DB,timeout=30) as db:
        try:
            summary=refresh(db,not args.no_archive_fetch,not args.no_gameweek_refresh)
            if args.dry_run:db.rollback()
            else:db.commit()
            print('simulated_players',json.dumps(summary,sort_keys=True),'dry_run',args.dry_run)
        except Exception:
            db.rollback()
            raise

if __name__=='__main__':main()

