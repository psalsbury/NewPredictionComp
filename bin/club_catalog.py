"""Shared club metadata, sourced from SQLite and cached for six hours."""
import json
import os
import sqlite3
import tempfile
import time
import fcntl
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo

DB = os.environ.get('PREDICTIONCOMP_DB', '/var/lib/predictioncomp/predictioncomp.sqlite')
CACHE = Path(os.environ.get('PREDICTIONCOMP_CLUB_CACHE', '/var/cache/predictioncomp/clubs.json'))
TTL = 21600

def season_year():
    now = datetime.now(ZoneInfo('Europe/London'))
    return now.year - (now.month < 7)

def build_catalog(connection):
    clubs = {name: {'name': name, 'badge': badge} for name, badge in connection.execute('SELECT name,badge FROM clubs')}
    aliases = {name.lower(): name for name in clubs}
    aliases.update(dict(connection.execute('SELECT alias,club_name FROM club_aliases')))
    seasons = {}
    sql = '''SELECT home, CAST(substr(kickoff_utc,1,4) AS INTEGER) - (CAST(substr(kickoff_utc,6,2) AS INTEGER)<7) FROM fixtures
             UNION SELECT away, CAST(substr(kickoff_utc,1,4) AS INTEGER) - (CAST(substr(kickoff_utc,6,2) AS INTEGER)<7) FROM fixtures'''
    for raw, season in connection.execute(sql):
        raw = raw.strip()
        name = aliases.get(raw.lower(), raw)
        clubs.setdefault(name, {'name': name, 'badge': None})
        aliases[raw.lower()] = name
        seasons.setdefault(str(season), set()).add(name)
    return {'generated_at': int(time.time()), 'clubs': clubs, 'aliases': aliases,
            'seasons': {key: sorted(value) for key, value in seasons.items()}}

def load_catalog(connection=None, force=False):
    def read():
        try:
            value = json.loads(CACHE.read_text())
            if all(key in value for key in ('generated_at', 'clubs', 'aliases', 'seasons')):
                return value
        except (OSError, ValueError):
            pass
        return None
    cached = read()
    if not force and cached and time.time() - cached['generated_at'] < TTL:
        return cached
    CACHE.parent.mkdir(parents=True, exist_ok=True)
    with (CACHE.parent / 'clubs.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        cached = read()
        if not force and cached and time.time() - cached['generated_at'] < TTL:
            return cached
        own_connection = connection is None
        connection = connection or sqlite3.connect(DB)
        try:
            result = build_catalog(connection)
            fd, temp = tempfile.mkstemp(prefix='clubs-', dir=CACHE.parent)
            try:
                with os.fdopen(fd, 'w') as stream:
                    json.dump(result, stream, separators=(',', ':'))
                os.chmod(temp, 0o664)
                os.replace(temp, CACHE)
            finally:
                if os.path.exists(temp):
                    os.unlink(temp)
            return result
        except Exception:
            if cached and not force:
                return cached
            raise
        finally:
            if own_connection:
                connection.close()

def register_team(connection, name, badge=None):
    """Store names/badges supplied by a feed; never guess a club identity."""
    raw = name.strip()
    row = connection.execute('SELECT club_name FROM club_aliases WHERE alias=?', (raw.lower(),)).fetchone()
    canonical = row[0] if row else raw
    connection.execute('INSERT INTO clubs(name,badge) VALUES(?,?) ON CONFLICT(name) DO UPDATE SET badge=excluded.badge WHERE clubs.badge IS NULL AND excluded.badge IS NOT NULL', (canonical, badge))
    connection.execute('INSERT OR IGNORE INTO club_aliases(alias,club_name) VALUES(?,?)', (raw.lower(), canonical))
    return canonical

def canonical(name, catalog):
    raw = ' '.join(name.split())
    return catalog['aliases'].get(raw.lower(), raw)
