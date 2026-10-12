"""Official Premier League gameweek membership and full-week bot uniqueness."""
import json
import time
import urllib.request
from datetime import datetime, timezone
from club_catalog import canonical, load_catalog

SCHEMA = """CREATE TABLE IF NOT EXISTS fixture_gameweeks(
 fixture_id INTEGER PRIMARY KEY REFERENCES fixtures(id), season INTEGER NOT NULL,
 gameweek INTEGER NOT NULL, source TEXT NOT NULL, refreshed_at INTEGER NOT NULL)"""

def refresh_gameweeks(db, season, force=False):
    db.execute(SCHEMA)
    latest = db.execute('SELECT MAX(refreshed_at) FROM fixture_gameweeks WHERE season=?', (season,)).fetchone()[0]
    if not force and latest and time.time() - latest < 21600:
        return
    def fetch(path):
        request = urllib.request.Request('https://fantasy.premierleague.com/api/' + path,
                                         headers={'User-Agent': 'PredictionComp gameweek sync'})
        with urllib.request.urlopen(request, timeout=20) as response:
            return json.load(response)
    fixtures = fetch('fixtures/')
    teams = fetch('bootstrap-static/')['teams']
    if len(fixtures) != 380 or len(teams) != 20:
        raise RuntimeError('Official gameweek feed is incomplete; existing membership retained')
    catalog = load_catalog(db)
    clubs = set(catalog['seasons'].get(str(season), []))
    names = {t['id']: canonical(t['name'], catalog) for t in teams}
    # Resolve a sole abbreviated name by the validated season team set, never
    # hard-code teams that can change after promotion/relegation.
    unknown = [tid for tid, name in names.items() if name not in clubs]
    remaining = clubs - set(names.values())
    if len(unknown) == len(remaining) == 1:
        names[unknown[0]] = remaining.pop()
    if set(names.values()) != clubs or len(clubs) != 20:
        raise RuntimeError('Official team names cannot be matched uniquely')
    stored = {}
    for fid, home, away in db.execute('SELECT id,home,away FROM fixtures WHERE kickoff_utc>=? AND kickoff_utc<?',
                                     (f'{season}-07-01', f'{season+1}-07-01')):
        pair = (canonical(home, catalog), canonical(away, catalog))
        if pair in stored:
            raise RuntimeError('Duplicate season fixture pairing')
        stored[pair] = fid
    rows = []
    pairs = set()
    for fixture in fixtures:
        pair = (names[fixture['team_h']], names[fixture['team_a']])
        if pair in pairs:
            raise RuntimeError('Duplicate official fixture pairing')
        pairs.add(pair)
        kickoff = fixture.get('kickoff_time')
        if kickoff and not f'{season}-07-01' <= kickoff < f'{season+1}-07-01':
            raise RuntimeError('Official feed belongs to a different season')
        event = fixture.get('event')
        if event is not None and not 1 <= event <= 38:
            raise RuntimeError('Invalid official gameweek')
        if pair in stored and event is not None:
            rows.append((stored[pair], season, event, 'https://fantasy.premierleague.com/api/fixtures/', int(time.time())))
    if len(pairs) != 380 or len(stored) != 380 or len(rows) < 370:
        raise RuntimeError('Official membership coverage is incomplete')
    db.executemany('INSERT OR REPLACE INTO fixture_gameweeks VALUES(?,?,?,?,?)', rows)
    print('official_gameweeks_refreshed', len(rows))


def unique_gameweek(slate, used, uid, mutable):
    """Compare every fixture, changing only picks that have not locked."""
    signature = tuple(slate)
    if signature not in used:
        used.add(signature)
        return slate
    indexes = [i for i, can_change in enumerate(mutable) if can_change]
    space = 49 ** len(indexes)
    encoded = 0
    for i in indexes:
        home, away = slate[i]
        encoded = encoded * 49 + home * 7 + away
    step = (uid * 2 + 1) % space or 1
    if step % 7 == 0:
        step += 2
    # Only signatures with the same locked prefix can consume this space.
    occupied = sum(all(s[i] == slate[i] for i in range(len(slate)) if not mutable[i]) for s in used)
    for attempt in range(1, min(space, occupied + 1) + 1):
        work = (encoded + attempt * step) % space
        candidate = list(slate)
        for i in reversed(indexes):
            digit = work % 49
            candidate[i] = (digit // 7, digit % 7)
            work //= 49
        signature = tuple(candidate)
        if signature not in used:
            used.add(signature)
            return candidate
    # A fully locked or genuinely exhausted week cannot be made unique without
    # changing past predictions. Preserve it and report it rather than crash.
    print('gameweek_uniqueness_locked_or_exhausted', uid)
    used.add(tuple(slate))
    return slate


def generate_gameweek_predictions(db, bots, prediction):
    now = datetime.now(timezone.utc).isoformat()
    rows = db.execute("""SELECT f.id,COALESCE(f.external_id,f.id),g.season,g.gameweek,
        f.home,f.away,(julianday(f.kickoff_utc)>julianday(?) AND
        COALESCE(f.status,'NS') NOT IN ('FT','AET','PEN','PST','CANC','ABD','AWD','WO','SUSP'))
        FROM fixtures f JOIN fixture_gameweeks g ON g.fixture_id=f.id
        ORDER BY g.season,g.gameweek,f.id""", (now,)).fetchall()
    groups = {}
    for row in rows:
        groups.setdefault((row[2], row[3]), []).append(row)
    written = 0
    unique = {}
    for key, fixtures in groups.items():
        if not any(f[6] for f in fixtures):
            continue
        ids = [f[0] for f in fixtures]
        marks = ','.join('?' for _ in ids)
        existing = {(uid, fid): (h, a) for uid, fid, h, a in db.execute(
            f'SELECT user_id,fixture_id,home_score,away_score FROM predictions WHERE fixture_id IN ({marks})', ids)}
        used = set()
        # Bots also avoid copying a human's complete gameweek entry; people
        # remain free to choose their own predictions.
        for (uid,) in db.execute('SELECT id FROM users WHERE is_bot=0'):
            if all((uid, fid) in existing for fid in ids):
                used.add(tuple(existing[(uid, fid)] for fid in ids))
        for uid, supported, grade in bots:
            slate = [existing.get((uid, f[0])) if not f[6] else
                     existing.get((uid, f[0]), prediction(grade, uid, f[1], f[4], f[5], supported))
                     for f in fixtures]
            slate = unique_gameweek(slate, used, uid, [bool(f[6]) for f in fixtures])
            for f, score in zip(fixtures, slate):
                if not f[6]:
                    continue
                db.execute("""INSERT INTO predictions(user_id,fixture_id,home_score,away_score,saved_at)
                    VALUES(?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(user_id,fixture_id) DO UPDATE SET
                    home_score=excluded.home_score,away_score=excluded.away_score,saved_at=CURRENT_TIMESTAMP
                    WHERE EXISTS(SELECT 1 FROM fixtures WHERE id=excluded.fixture_id
                    AND julianday(kickoff_utc)>julianday('now') AND
                    COALESCE(status,'NS') NOT IN ('FT','AET','PEN','PST','CANC','ABD','AWD','WO','SUSP'))""",
                    (uid, f[0], *score))
                written += 1
        unique[key] = len(used)
    return written, unique
