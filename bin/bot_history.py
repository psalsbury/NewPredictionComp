"""Cached prior-season results. Never add these games to the live competition."""
import csv
import io
import json
import os
import urllib.request
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo

def load_archive(season, canonical=lambda name:name, fetch=True):
    folder=Path(os.environ.get('PREDICTIONCOMP_BOT_CACHE','/var/cache/predictioncomp'))
    rows=[]
    for year in [season-2,season-1]:
        cache=folder/f'bot-history-{year}.json'
        try:
            data=json.loads(cache.read_text())
        except (OSError,ValueError):
            if not fetch:continue
            code=f'{year%100:02d}{(year+1)%100:02d}'
            source=f'https://www.football-data.co.uk/mmz4281/{code}/E0.csv'
            try:
                req=urllib.request.Request(source,headers={'User-Agent':'PredictionComp bot form history'})
                with urllib.request.urlopen(req,timeout=20) as response:
                    table=csv.DictReader(io.StringIO(response.read().decode('utf-8-sig')))
                    data=[]
                    for r in table:
                        if not r.get('FTHG') or not r.get('FTAG'):continue
                        kick=datetime.strptime(r['Date']+' '+(r.get('Time') or '15:00'),'%d/%m/%Y %H:%M').replace(tzinfo=ZoneInfo('Europe/London'))
                        if not f'{year}-07-01'<=kick.date().isoformat()<f'{year+1}-07-01':continue
                        data.append(dict(kickoff=kick.isoformat(),home=r['HomeTeam'],away=r['AwayTeam'],hg=int(r['FTHG']),ag=int(r['FTAG'])))
                if len(data)!=380:raise ValueError('Incomplete prior-season results')
                folder.mkdir(parents=True,exist_ok=True)
                tmp=cache.with_suffix('.tmp');tmp.write_text(json.dumps(data));tmp.replace(cache)
            except (OSError,ValueError,KeyError) as exc:
                print('bot_archive_unavailable',year,type(exc).__name__)
                continue
        for r in data:
            if r['kickoff'][:10]<f'{season}-07-01':
                rows.append(dict(r,home=canonical(r['home']),away=canonical(r['away'])))
    return rows
