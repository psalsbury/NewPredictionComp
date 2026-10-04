#!/usr/bin/env python3
import os, sqlite3, urllib.request, urllib.parse, json, random, math, hashlib, csv, io
from datetime import datetime, timezone, timedelta
from zoneinfo import ZoneInfo

DB = os.environ.get('PREDICTIONCOMP_DB', '/var/lib/predictioncomp/predictioncomp.sqlite')
KEY = os.environ.get('API_FOOTBALL_KEY', '')
NAMES = [
    "Macca","Smudger","Sparky","TheCat","TopBins","Nutmeg","TheWall","Silky","MagicBoots","SleepyHead",
    "BigDave","GrumpyBadger","TeaAndBiscuits","CaptainCrumpet","DaveFromAccounts","CrispyBacon","GardenGnome","BiscuitBandit","MoodyGoose","SneakyFerret",
    "HappyCamper","PintAndPie","BananaBoots","BigCheese","CaptainChaos","QuietStorm","MrAverage","ProbablyDave","RandomPenguin","DiscoStu",
    "OopsMyBad","JustHereForFun","PieEater","MadHatter","LuckyDuck","WheresMyKeys","SecretSquirrel","PacketOfCrisps","PubQuizPro","DadJokes",
    "TwoSugars","EasyTiger","BobbleHat","SpareChange","TurboSnail","KingOfTea","LateAgain","NoIdeaMate","WeekendHero","SleepyHeadJr"
]
TARGET_BOTS = 50
BOTS_PER_GRADE = 10
from club_catalog import load_catalog, canonical, register_team, season_year
_catalog=load_catalog()
season=season_year()
season_code=f'{season%100:02d}{(season+1)%100:02d}'
CLUBS=_catalog['seasons'].get(str(season),[])
if not CLUBS: raise RuntimeError('No clubs available for the current season')
def same_team(left,right): return canonical(left,_catalog)==canonical(right,_catalog)
def api(params):
    if not KEY:
        return {'response': []}
    url = 'https://v3.football.api-sports.io/fixtures?' + urllib.parse.urlencode(params)
    req = urllib.request.Request(url, headers={'x-apisports-key': KEY})
    with urllib.request.urlopen(req, timeout=45) as response:
        return json.load(response)

def poisson(lam, rng):
    limit = math.exp(-max(.15, min(4.5, lam)))
    k, p = 0, 1.0
    while p > limit:
        k += 1
        p *= rng.random()
    return min(6, k - 1)

def weighted_average(values, default):
    if not values:
        return default
    weights = list(range(1, len(values) + 1))
    return sum(v * w for v, w in zip(values, weights)) / sum(weights)

def team_stats(club, opponent=None, venue=None, limit=8):
    rows = history.get(club, [])
    if opponent is not None:
        rows = [r for r in rows if r['opponent'] == opponent]
    if venue is not None:
        rows = [r for r in rows if r['venue'] == venue]
    rows = rows[-limit:]
    return {
        'gf': weighted_average([r['gf'] for r in rows], 1.35),
        'ga': weighted_average([r['ga'] for r in rows], 1.35),
        'ppg': weighted_average([r['pts'] for r in rows], 1.35),
        'played': len(rows),
    }

def clamp(value, low=.15, high=4.2):
    return max(low, min(high, value))

def grade_one_xg(home, away):
    # Grade 1 blends recency-weighted form, home/away splits, head-to-head,
    # attack/defence balance and points-per-game momentum.
    hr, ar = team_stats(home), team_stats(away)
    hv, av = team_stats(home, venue='H', limit=6), team_stats(away, venue='A', limit=6)
    hh, ah = team_stats(home, opponent=away, limit=5), team_stats(away, opponent=home, limit=5)
    hx = .42 * ((hr['gf'] + ar['ga']) / 2) + .33 * ((hv['gf'] + av['ga']) / 2) + .25 * ((hh['gf'] + ah['ga']) / 2)
    ax = .42 * ((ar['gf'] + hr['ga']) / 2) + .33 * ((av['gf'] + hv['ga']) / 2) + .25 * ((ah['gf'] + hh['ga']) / 2)
    hx += .22 + (hr['ppg'] - ar['ppg']) * .11
    ax += (ar['ppg'] - hr['ppg']) * .09
    return clamp(hx), clamp(ax)

def stable_goal(lam, rng):
    base = int(math.floor(lam))
    goal = base + (1 if rng.random() < lam - base else 0)
    if rng.random() < .10:
        goal += rng.choice([-1, 1])
    return max(0, min(6, goal))

ARCHETYPES = [
    'Cautious Analyst', 'Home-Day Believer', 'Draw Hunter', 'Scoreline Optimist',
    'Low-Score Purist', 'Form Follower', 'Contrarian', 'Favourite Backer',
    'Defence First', 'Attack First', 'Momentum Rider', 'Stats Traditionalist',
    'Upset Seeker', 'Safe Picker', 'Bold Caller', 'Fixture Specialist',
    'Patient Planner', 'Weekend Gambler', 'Numbers Nerd', 'Chaos Merchant',
]

def bot_profile(uid, index, grade):
    # Every bot receives a permanent, reproducible personality. The traits are
    # deliberately continuous so no two of the 100 profiles are identical.
    rng = random.Random(f'predictioncomp-personality-v1:{uid}:{index}')
    return {
        'label': f'{ARCHETYPES[index % len(ARCHETYPES)]} {index + 1:03d}',
        'risk': rng.uniform(-.22, .32),
        'optimism': rng.uniform(-.22, .30),
        'home_bias': rng.uniform(-.08, .24),
        'draw_bias': rng.uniform(.04, .34),
        'form_weight': rng.uniform(.72, 1.30),
        'h2h_weight': rng.uniform(.65, 1.35),
        'club_loyalty': rng.uniform(.02, .18),
        'upset_bias': rng.uniform(.02, .16),
        'grade': grade,
    }

def shape_expectation(hx, ax, profile):
    midpoint = (hx + ax) / 2
    hx = midpoint + (hx - midpoint) * profile['form_weight']
    ax = midpoint + (ax - midpoint) * profile['form_weight']
    hx += profile['home_bias'] + profile['optimism']
    ax += profile['optimism']
    spread = 1 + profile['risk']
    midpoint = (hx + ax) / 2
    return clamp(midpoint + (hx - midpoint) * spread), clamp(midpoint + (ax - midpoint) * spread)

def shape_score(home_goal, away_goal, profile, rng, strength):
    # Draw seekers, upset seekers and optimistic/cautious players retain their
    # habits from match to match, without overpowering the underlying grade.
    if abs(home_goal - away_goal) <= 1 and rng.random() < profile['draw_bias'] * strength:
        level = max(0, min(6, round((home_goal + away_goal) / 2)))
        home_goal = away_goal = level
    if home_goal != away_goal and rng.random() < profile['upset_bias'] * strength:
        home_goal, away_goal = away_goal, home_goal
    return max(0, min(6, home_goal)), max(0, min(6, away_goal))

def predict(grade, uid, external, home, away, supported, suffix, profile):
    home,away=canonical(home,_catalog),canonical(away,_catalog)
    rng = random.Random(f'{uid}:{external}:{suffix}:grade-{grade}:{profile["label"]}')
    if grade == 1:
        # Strongest model: recent and venue form, head-to-head, attack/defence,
        # momentum, then the individual bot's measured biases.
        hx, ax = grade_one_xg(home, away)
        hh, ah = team_stats(home, opponent=away, limit=5), team_stats(away, opponent=home, limit=5)
        h2h_gap = (hh['ppg'] - ah['ppg']) * .06 * profile['h2h_weight']
        hx, ax = hx + h2h_gap, ax - h2h_gap
        if same_team(supported, home): hx += profile['club_loyalty']
        if same_team(supported, away): ax += profile['club_loyalty']
        hx, ax = shape_expectation(hx, ax, profile)
        return shape_score(stable_goal(hx, rng), stable_goal(ax, rng), profile, rng, .22)
    if grade == 2:
        # Strong recent-form model, personalised but without detailed venue/H2H.
        hf, af = team_stats(home, limit=6), team_stats(away, limit=6)
        hx = clamp((hf['gf'] + af['ga']) / 2 + .20 + (hf['ppg'] - af['ppg']) * .08)
        ax = clamp((af['gf'] + hf['ga']) / 2 + (af['ppg'] - hf['ppg']) * .06)
        if same_team(supported, home): hx += profile['club_loyalty'] * .7
        if same_team(supported, away): ax += profile['club_loyalty'] * .7
        hx, ax = shape_expectation(hx, ax, profile)
        return shape_score(poisson(hx, rng), poisson(ax, rng), profile, rng, .38)
    if grade == 3:
        # Season averages plus more personality-driven noise.
        hf, af = team_stats(home, limit=38), team_stats(away, limit=38)
        hx = (hf['gf'] + af['ga']) / 2 + .16 + rng.uniform(-.30, .30)
        ax = (af['gf'] + hf['ga']) / 2 + rng.uniform(-.30, .30)
        hx, ax = shape_expectation(clamp(hx), clamp(ax), profile)
        return shape_score(poisson(hx, rng), poisson(ax, rng), profile, rng, .60)
    if grade == 4:
        # Weak generic model: personality matters more than team information.
        cap = 3 if profile['optimism'] < -.08 else 5 if profile['optimism'] > .12 else 4
        home_goal, away_goal = rng.randint(0, cap), rng.randint(0, cap)
        if rng.random() < .12 + max(0, profile['home_bias']) and home_goal <= away_goal:
            home_goal = min(6, away_goal + 1)
        return shape_score(home_goal, away_goal, profile, rng, .82)
    # Grade 5 remains completely random. Its stable seed gives each bot its own
    # repeatable chaotic pattern; no form, club or opponent information is used.
    return rng.randint(0, 5), rng.randint(0, 5)

def unique_slate(slate, used, uid):
    signature = tuple(slate)
    if signature not in used:
        used.add(signature)
        return slate
    # Walk the full scoreline combination space deterministically. This makes
    # every bot's collective matchweek entry unique even when individual match
    # predictions legitimately coincide.
    space = 49 ** len(slate)
    encoded = 0
    for home_goal, away_goal in slate:
        encoded = encoded * 49 + home_goal * 7 + away_goal
    step = (uid * 2 + 1) % space or 1
    if step % 7 == 0:
        step += 2
    for attempt in range(1, min(space, 100000) + 1):
        value = (encoded + attempt * step) % space
        candidate = []
        work = value
        for _ in slate:
            digit = work % 49
            candidate.append((digit // 7, digit % 7))
            work //= 49
        candidate.reverse()
        signature = tuple(candidate)
        if signature not in used:
            used.add(signature)
            return candidate
    raise RuntimeError('Could not create a unique bot prediction slate')

c = sqlite3.connect(DB)
cols = {r[1] for r in c.execute('PRAGMA table_info(users)')}
if 'is_bot' not in cols:
    c.execute('ALTER TABLE users ADD COLUMN is_bot INTEGER NOT NULL DEFAULT 0')
if 'bot_grade' not in cols:
    c.execute('ALTER TABLE users ADD COLUMN bot_grade INTEGER')

# Convert only untouched generic test identities; named and signed-in users stay human.
generic = c.execute("""SELECT id FROM users WHERE email IS NULL AND display_name GLOB 'Player ????'
 AND display_name NOT IN ('Pete','Pete Salsbury') ORDER BY id""").fetchall()
for i, (uid,) in enumerate(generic):
    c.execute('UPDATE users SET display_name=?,is_bot=1,supported_club=COALESCE(supported_club,?) WHERE id=?',
              (NAMES[i % 100], CLUBS[i % len(CLUBS)], uid))

# Remove surplus simulated identities while preserving every chosen bot's history.
marks = ','.join('?' for _ in NAMES)
retired = [row[0] for row in c.execute(
    f'SELECT id FROM users WHERE is_bot=1 AND display_name NOT IN ({marks})', NAMES
)]
if retired:
    retired_marks = ','.join('?' for _ in retired)
    c.execute(f'DELETE FROM league_members WHERE user_id IN ({retired_marks})', retired)
    c.execute(f'DELETE FROM predictions WHERE user_id IN ({retired_marks})', retired)
    c.execute(f'DELETE FROM users WHERE id IN ({retired_marks})', retired)

count = c.execute('SELECT COUNT(*) FROM users WHERE is_bot=1').fetchone()[0]
for i in range(count, TARGET_BOTS):
    token = 'sim_' + hashlib.sha256(f'predictioncomp-sim-{i}'.encode()).hexdigest()[:28]
    c.execute('INSERT OR IGNORE INTO users(token,display_name,supported_club,is_bot) VALUES(?,?,?,1)',
              (token, NAMES[i % len(NAMES)], CLUBS[i % len(CLUBS)]))

# The curated name order fixes ten distinctive identities at each level.
for position, name in enumerate(NAMES):
    c.execute('UPDATE users SET bot_grade=? WHERE is_bot=1 AND display_name=?',
              (position // BOTS_PER_GRADE + 1, name))

data = api({'league': 39, 'season': season, 'status': 'FT'})
completed = []
for item in data.get('response', []):
    fixture, teams, goals, league = item['fixture'], item['teams'], item.get('goals') or {}, item.get('league') or {}
    if goals.get('home') is None or goals.get('away') is None:
        continue
    for team in teams.values():register_team(c,team['name'],team.get('logo'))
    completed.append({
        'external': int(fixture['id']), 'timestamp': int(fixture.get('timestamp', 0)),
        'kickoff': datetime.fromtimestamp(int(fixture.get('timestamp', 0)), timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
        'round': str(league.get('round') or ''), 'home': teams['home']['name'], 'away': teams['away']['name'],
        'hg': int(goals['home']), 'ag': int(goals['away']), 'status': 'FT'
    })
for match in completed:
    match['home']=canonical(match['home'],_catalog)
    match['away']=canonical(match['away'],_catalog)
completed.sort(key=lambda row: row['timestamp'])

# Never fabricate past fixtures. If API-Football is temporarily unavailable,
# load verified 2026/27 Premier League results from football-data.co.uk.
if not completed:
    try:
        source = f'https://www.football-data.co.uk/mmz4281/{season_code}/E0.csv'
        with urllib.request.urlopen(source, timeout=45) as response:
            rows = csv.DictReader(io.StringIO(response.read().decode('utf-8-sig')))
            for row in rows:
                if not row.get('FTHG') or not row.get('FTAG'):
                    continue
                local = datetime.strptime(
                    f"{row['Date']} {row.get('Time') or '15:00'}", '%d/%m/%Y %H:%M'
                ).replace(tzinfo=ZoneInfo('Europe/London'))
                when = local.astimezone(timezone.utc)
                identity = f"PL{season_code}:{row['Date']}:{row['HomeTeam']}:{row['AwayTeam']}"
                external = 100000000 + int(hashlib.sha256(identity.encode()).hexdigest()[:12], 16) % 800000000
                completed.append({
                    'external': external, 'timestamp': int(when.timestamp()),
                    'kickoff': when.strftime('%Y-%m-%dT%H:%M:%SZ'),
                    'round': f'Premier League {season}/{str(season+1)[2:]}', 'home': row['HomeTeam'],
                    'away': row['AwayTeam'], 'hg': int(row['FTHG']),
                    'ag': int(row['FTAG']), 'status': 'FT'
                })
    except Exception as exc:
        print('verified_results_fallback_failed', type(exc).__name__)

# A network failure may still reuse previously stored real results, never fake ones.
if not completed:
    for external, round_name, kickoff, home, away, hg, ag in c.execute("""
        SELECT external_id,round,kickoff_utc,home,away,home_score,away_score
        FROM fixtures
        WHERE external_id > 0 AND status='FT'
          AND home_score IS NOT NULL AND away_score IS NOT NULL
          AND kickoff_utc >= ? AND kickoff_utc < ?
        ORDER BY kickoff_utc
    """, (f'{season}-07-01',f'{season+1}-07-01')):
        try:
            when = datetime.fromisoformat(str(kickoff).replace('Z', '+00:00'))
        except ValueError:
            continue
        completed.append({
            'external': int(external), 'timestamp': int(when.timestamp()),
            'kickoff': when.astimezone(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
            'round': str(round_name or ''), 'home': home, 'away': away,
            'hg': int(hg), 'ag': int(ag), 'status': 'FT'
        })
for match in completed:
    match['home']=canonical(match['home'],_catalog)
    match['away']=canonical(match['away'],_catalog)
completed.sort(key=lambda row: row['timestamp'])

# Remove the old synthetic matchweeks and all predictions attached to them.
fake_fixture_ids = [row[0] for row in c.execute(
    "SELECT id FROM fixtures WHERE external_id < 0 OR status='SIM' OR round LIKE 'Simulated matchweek %'"
)]
if fake_fixture_ids:
    marks = ','.join('?' for _ in fake_fixture_ids)
    c.execute(f'DELETE FROM predictions WHERE fixture_id IN ({marks})', fake_fixture_ids)
    c.execute(f'DELETE FROM fixtures WHERE id IN ({marks})', fake_fixture_ids)

bots = c.execute('SELECT id,supported_club,COALESCE(bot_grade,5) FROM users WHERE is_bot=1 ORDER BY id LIMIT ?', (TARGET_BOTS,)).fetchall()
c.execute("""CREATE TABLE IF NOT EXISTS bot_profiles(
 user_id INTEGER PRIMARY KEY, personality TEXT NOT NULL UNIQUE,
 risk REAL NOT NULL, optimism REAL NOT NULL, home_bias REAL NOT NULL,
 draw_bias REAL NOT NULL, form_weight REAL NOT NULL, h2h_weight REAL NOT NULL,
 club_loyalty REAL NOT NULL, upset_bias REAL NOT NULL, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
)""")
c.execute('DELETE FROM bot_profiles WHERE user_id NOT IN (SELECT id FROM users WHERE is_bot=1)')
profiles = {}
for index, (uid, supported, grade) in enumerate(bots):
    profile = bot_profile(uid, index, grade)
    profiles[uid] = profile
    c.execute("""INSERT INTO bot_profiles(user_id,personality,risk,optimism,home_bias,draw_bias,
      form_weight,h2h_weight,club_loyalty,upset_bias,updated_at)
      VALUES(?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)
      ON CONFLICT(user_id) DO UPDATE SET personality=excluded.personality,risk=excluded.risk,
      optimism=excluded.optimism,home_bias=excluded.home_bias,draw_bias=excluded.draw_bias,
      form_weight=excluded.form_weight,h2h_weight=excluded.h2h_weight,
      club_loyalty=excluded.club_loyalty,upset_bias=excluded.upset_bias,updated_at=CURRENT_TIMESTAMP""",
      (uid, profile['label'], profile['risk'], profile['optimism'], profile['home_bias'],
       profile['draw_bias'], profile['form_weight'], profile['h2h_weight'],
       profile['club_loyalty'], profile['upset_bias']))
c.execute('DELETE FROM bot_profiles WHERE user_id NOT IN (SELECT id FROM users WHERE is_bot=1)')
history = {}
historical_written = 0
for game in completed:
    c.execute("""INSERT INTO fixtures(external_id,round,kickoff_utc,home,away,home_score,away_score,status,updated_at)
      VALUES(?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)
      ON CONFLICT(external_id) DO UPDATE SET round=excluded.round,kickoff_utc=excluded.kickoff_utc,
      home=excluded.home,away=excluded.away,home_score=excluded.home_score,away_score=excluded.away_score,
      status=excluded.status,updated_at=CURRENT_TIMESTAMP""",
      (game['external'],game['round'],game['kickoff'],game['home'],game['away'],game['hg'],game['ag'],game['status']))
    fid = c.execute('SELECT id FROM fixtures WHERE external_id=?', (game['external'],)).fetchone()[0]
    for uid, supported, grade in bots:
        ph, pa = predict(grade, uid, game['external'], game['home'], game['away'], supported, 'history-v3', profiles[uid])
        exact = ph == game['hg'] and pa == game['ag']
        pred_result, actual_result = (ph > pa) - (ph < pa), (game['hg'] > game['ag']) - (game['hg'] < game['ag'])
        points = 5 if exact else 3 if pred_result == actual_result else 0
        saved = datetime.fromtimestamp(game['timestamp'] - 3600, timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
        c.execute("""INSERT INTO predictions(user_id,fixture_id,home_score,away_score,points,saved_at)
          VALUES(?,?,?,?,?,?) ON CONFLICT(user_id,fixture_id) DO UPDATE SET
          home_score=excluded.home_score,away_score=excluded.away_score,points=excluded.points,saved_at=excluded.saved_at""",
          (uid, fid, ph, pa, points, saved))
        historical_written += 1
    home_pts = 3 if game['hg'] > game['ag'] else 1 if game['hg'] == game['ag'] else 0
    away_pts = 3 if game['ag'] > game['hg'] else 1 if game['ag'] == game['hg'] else 0
    history.setdefault(game['home'], []).append({'opponent': game['away'], 'venue': 'H', 'gf': game['hg'], 'ga': game['ag'], 'pts': home_pts})
    history.setdefault(game['away'], []).append({'opponent': game['home'], 'venue': 'A', 'gf': game['ag'], 'ga': game['hg'], 'pts': away_pts})

fixtures = c.execute("""SELECT id,COALESCE(external_id,id),COALESCE(NULLIF(round,''),'Upcoming'),
 home,away FROM fixtures WHERE kickoff_utc>datetime('now') ORDER BY kickoff_utc LIMIT 20""").fetchall()
fixture_groups = {}
for fixture in fixtures:
    fixture_groups.setdefault(fixture[2], []).append(fixture)

written = 0
unique_matchweeks = {}
for round_name, round_fixtures in fixture_groups.items():
    used_slates = set()
    for uid, supported, grade in bots:
        slate = []
        for fid, external, _, home, away in round_fixtures:
            slate.append(predict(grade, uid, external, home, away, supported, 'future-v3', profiles[uid]))
        slate = unique_slate(slate, used_slates, uid)
        for (fid, external, _, home, away), (ph, pa) in zip(round_fixtures, slate):
            c.execute("""INSERT INTO predictions(user_id,fixture_id,home_score,away_score,saved_at)
              VALUES(?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(user_id,fixture_id) DO UPDATE SET
              home_score=excluded.home_score,away_score=excluded.away_score,saved_at=CURRENT_TIMESTAMP
              WHERE (SELECT kickoff_utc FROM fixtures WHERE id=excluded.fixture_id)>datetime('now')""",
              (uid, fid, ph, pa))
            written += 1
    unique_matchweeks[round_name] = len(used_slates)

c.commit()
distribution = dict(c.execute('SELECT bot_grade,COUNT(*) FROM users WHERE is_bot=1 GROUP BY bot_grade ORDER BY bot_grade'))
print('simulated_players', len(bots), 'grades', distribution, 'completed_fixtures', len(completed),
      'historical_predictions_written', historical_written, 'future_fixtures', len(fixtures),
      'future_predictions_written', written)

load_catalog(c,force=True)
