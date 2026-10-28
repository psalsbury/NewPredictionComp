#!/usr/bin/env python3
"""Read-only walk-forward evaluation; never changes real league entries."""
import hashlib
import json
import os
import sqlite3
from bot_strategy import from_database,result,when
from bot_history import load_archive
from club_catalog import load_catalog,canonical,season_year

def evaluate(db):
    catalog=load_catalog(db)
    name=lambda s:canonical(s,catalog)
    model=from_database(db,name,load_archive(season_year(),name,fetch=False))
    # Match the model's archive/store deduplication key and canonical names.
    # Keep native external_id types: the odds dictionary uses these exact keys.
    stored_ids={(when(kick).date(),name(home),name(away)):external
                for external,kick,home,away in db.execute(
                    "SELECT external_id,kickoff_utc,home,away FROM fixtures "
                    "WHERE status IN ('FT','AET','PEN') AND home_score IS NOT NULL AND away_score IS NOT NULL")}
    totals={g:[0,0] for g in range(1,6)}
    for fixture in model.matches:
        key=(when(fixture['kickoff']).date(),fixture['home'],fixture['away'])
        external=stored_ids.get(key)
        if external is None:
            external='archive:'+hashlib.sha256((fixture['kickoff']+fixture['home']+fixture['away']).encode()).hexdigest()[:12]
        for grade in totals:
            for identity in range(5):
                home,away=model.predict(grade,1000+grade*10+identity,external,fixture['home'],fixture['away'],fixture['kickoff'])
                points=5 if (home,away)==(fixture['hg'],fixture['ag']) else 3 if result(home,away)==result(fixture['hg'],fixture['ag']) else 0
                totals[grade][0]+=points;totals[grade][1]+=1
    return {'fixtures':len(model.matches),'points_per_pick':{g:round(points/n,4) for g,(points,n) in totals.items() if n},
            'method':'walk-forward at kickoff: own, live and future results excluded; stored fixture IDs link cached odds, with observed/fetched timestamps checked by the strategy; archive-only fixtures use form; synthetic players, without gameweek uniqueness adjustments'}

if __name__=='__main__':
    path=os.environ.get('PREDICTIONCOMP_DB','/var/lib/predictioncomp/predictioncomp.sqlite')
    with sqlite3.connect('file:'+path+'?mode=ro',uri=True) as db:print(json.dumps(evaluate(db),sort_keys=True))
