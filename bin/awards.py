#!/usr/bin/env python3
"""Work out player awards from finished matches and store them in player_awards.

Real players only (bots never win awards). Awards for a gameweek are given once every match in it
has finished (a match moved more than a week later doesn't hold the gameweek up); monthly awards once
the month (UK time) is over. Re-running is safe: rows are keyed (user, award, period) and recomputed.

  gw_winner    🥇 Gameweek winner         most points in a completed gameweek (ties share it)
  month_best   🏆 Player of the month     most points in a completed month (min 5 picks)
  month_shot   🎯 Exact score of the month the longest-priced exact score that month (correct-score price,
                                          or the fewest players on that pick when no price was stored)
  hat_trick    🔥 Hat-trick               3+ exact scores in one gameweek
  perfect      💯 Perfect week            correct result on every match of a gameweek (min 8)
  joker_hit    🃏 Joker jackpot           exact score on your joker
  sim1_slayer  🤖 SIM1 slayer             beat SIM1's average in 3 completed gameweeks in a row

Points for the gameweek/month awards are competition points (predictions.points, joker included);
the accuracy awards use the raw 5/3/0 rule from bin/scoring.py.
"""
import argparse, os, sqlite3
from collections import defaultdict
from datetime import datetime, timedelta, timezone
from zoneinfo import ZoneInfo
from scoring import points as raw_points, POINTS_EXACT

DB = os.environ.get('PREDICTIONCOMP_DB', '/var/lib/predictioncomp/predictioncomp.sqlite')
UK = ZoneInfo('Europe/London')
FINAL = ('FT', 'AET', 'PEN')


def when(s):
    return datetime.fromisoformat(s.replace('Z', '+00:00')).astimezone(timezone.utc)


def label_score(h, a):
    return f'{h}–{a}'


def compute(c, now):
    c.row_factory = sqlite3.Row
    fixtures = {r['id']: r for r in c.execute("""SELECT f.id,f.home,f.away,f.kickoff_utc,f.status,f.home_score,f.away_score,g.season,g.gameweek
        FROM fixtures f LEFT JOIN fixture_gameweeks g ON g.fixture_id=f.id""")}
    humans = {r['id']: r['display_name'] for r in c.execute('SELECT id,display_name FROM users WHERE COALESCE(is_bot,0)=0')}
    sim1 = {r['id'] for r in c.execute('SELECT id FROM users WHERE is_bot=1 AND bot_grade=1')}
    preds = c.execute('SELECT user_id,fixture_id,home_score,away_score,COALESCE(points,0) points,joker FROM predictions').fetchall()
    prices = {}
    try:
        for r in c.execute('SELECT s.fixture_id,s.home_goals,s.away_goals,s.price,s.observed_at,f.kickoff_utc FROM fixture_score_odds s JOIN fixtures f ON f.id=s.fixture_id'):
            if when(r['observed_at']) < when(r['kickoff_utc']):
                prices[(r['fixture_id'], r['home_goals'], r['away_goals'])] = r['price']
    except sqlite3.OperationalError:
        pass

    def final(f):
        return f['status'] in FINAL and f['home_score'] is not None

    # completed gameweeks
    gws = defaultdict(list)
    for f in fixtures.values():
        if f['season'] is not None:
            gws[(f['season'], f['gameweek'])].append(f)
    complete = {}
    for key, fs in gws.items():
        first = min(when(f['kickoff_utc']) for f in fs)
        window = [f for f in fs if when(f['kickoff_utc']) <= first + timedelta(days=7)]
        if window and all(final(f) or f['status'] in ('PST', 'CANC', 'ABD') for f in window) and any(final(f) for f in window):
            complete[key] = [f['id'] for f in fs if final(f)]

    awards = []  # (user, award, period, label, detail, fixture_id)
    by_fixture_user = defaultdict(dict)
    for p in preds:
        by_fixture_user[p['fixture_id']][p['user_id']] = p

    # gameweek awards
    sim_week = {}  # (season,gw) -> SIM1 average points
    human_week = defaultdict(dict)  # (season,gw) -> {user: points}
    for key, fids in sorted(complete.items()):
        season, gw = key
        period = f'gw:{season}-{gw}'
        pts = defaultdict(int); exact = defaultdict(int); correct = defaultdict(int); picks = defaultdict(int)
        sim_tot = defaultdict(int)
        for fid in fids:
            f = fixtures[fid]
            for uid, p in by_fixture_user[fid].items():
                raw = raw_points(p['home_score'], p['away_score'], f['home_score'], f['away_score'])
                if uid in sim1:
                    sim_tot[uid] += p['points']
                if uid not in humans:
                    continue
                pts[uid] += p['points']; picks[uid] += 1
                exact[uid] += raw == POINTS_EXACT; correct[uid] += raw > 0
                if p['joker'] and raw == POINTS_EXACT:
                    awards.append((uid, 'joker_hit', f'fx:{fid}', '🃏 Joker jackpot',
                                   f"{label_score(p['home_score'], p['away_score'])} on {f['home']} v {f['away']} · +{p['points']}", fid))
        sim_week[key] = (sum(sim_tot.values()) / len(sim_tot)) if sim_tot else None
        human_week[key] = dict(pts)
        if pts:
            best = max(pts.values())
            if best > 0:
                for uid, v in pts.items():
                    if v == best:
                        awards.append((uid, 'gw_winner', period, '🥇 Gameweek winner', f'Gameweek {gw} · {v} pts', None))
        for uid in picks:
            if exact[uid] >= 3:
                awards.append((uid, 'hat_trick', period, '🔥 Hat-trick', f'Gameweek {gw} · {exact[uid]} exact scores', None))
            if picks[uid] >= 8 and picks[uid] == len(fids) and correct[uid] == picks[uid]:
                awards.append((uid, 'perfect', period, '💯 Perfect week', f'Gameweek {gw} · every result right ({picks[uid]}/{picks[uid]})', None))

    # SIM1 slayer: 3 completed gameweeks in a row above SIM1's average
    ordered = sorted(complete)
    streak = defaultdict(int)
    for key in ordered:
        s = sim_week.get(key)
        for uid in humans:
            v = human_week[key].get(uid)
            if s is not None and v is not None and v > s:
                streak[uid] += 1
                if streak[uid] == 3:
                    awards.append((uid, 'sim1_slayer', f'gw:{key[0]}-{key[1]}', '🤖 SIM1 slayer',
                                   f'Beat SIM1 three gameweeks running (to gameweek {key[1]})', None))
                    streak[uid] = 0
            else:
                streak[uid] = 0

    # monthly awards (UK months that have ended)
    month_now = now.astimezone(UK).strftime('%Y-%m')
    months = defaultdict(list)
    for f in fixtures.values():
        if final(f):
            m = when(f['kickoff_utc']).astimezone(UK).strftime('%Y-%m')
            if m < month_now:
                months[m].append(f['id'])
    for m, fids in sorted(months.items()):
        period = f'month:{m}'
        name = datetime.strptime(m, '%Y-%m').strftime('%B %Y')
        pts = defaultdict(int); picks = defaultdict(int); shots = []
        for fid in fids:
            f = fixtures[fid]
            counts = defaultdict(int)
            for uid, p in by_fixture_user[fid].items():
                if uid in humans:
                    counts[(p['home_score'], p['away_score'])] += 1
            for uid, p in by_fixture_user[fid].items():
                if uid not in humans:
                    continue
                pts[uid] += p['points']; picks[uid] += 1
                if (p['home_score'], p['away_score']) == (f['home_score'], f['away_score']):
                    price = prices.get((fid, p['home_score'], p['away_score']))
                    shots.append((price or 0, -counts[(p['home_score'], p['away_score'])], uid, fid, p))
        eligible = {u: v for u, v in pts.items() if picks[u] >= 5}
        if eligible and max(eligible.values()) > 0:
            best = max(eligible.values())
            for uid, v in eligible.items():
                if v == best:
                    awards.append((uid, 'month_best', period, '🏆 Player of the month', f'{name} · {v} pts', None))
        if shots:
            top = max(shots, key=lambda s: (s[0], s[1]))
            price, neg, uid, fid, p = top
            f = fixtures[fid]
            how = f'at {price:.2f}' if price else (f'only {-neg} player' + ('' if -neg == 1 else 's') + ' picked it')
            awards.append((uid, 'month_shot', period, '🎯 Exact score of the month',
                           f"{name} · {label_score(p['home_score'], p['away_score'])} {f['home']} v {f['away']} ({how})", fid))
    return awards


def main():
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    ap.add_argument('--dry-run', action='store_true')
    a = ap.parse_args()
    c = sqlite3.connect(DB, timeout=60)
    c.execute('PRAGMA busy_timeout=60000')
    awards = compute(c, datetime.now(timezone.utc))
    c.row_factory = None
    if a.dry_run:
        for x in awards:
            print(x)
        print(f'{len(awards)} awards (dry run)')
        return
    with c:
        keys = {(u, k, p) for u, k, p, *_ in awards}
        existing = {tuple(r) for r in c.execute('SELECT user_id,award,period FROM player_awards')}
        for row in existing - keys:  # e.g. a corrected result removed an award
            c.execute('DELETE FROM player_awards WHERE user_id=? AND award=? AND period=?', row)
        for u, k, p, label, detail, fid in awards:
            c.execute("""INSERT INTO player_awards(user_id,award,period,label,detail,fixture_id) VALUES(?,?,?,?,?,?)
                ON CONFLICT(user_id,award,period) DO UPDATE SET label=excluded.label,detail=excluded.detail,fixture_id=excluded.fixture_id""",
                      (u, k, p, label, detail, fid))
    print(f'awards: {len(awards)} current, {len(keys - existing)} new, {len(existing - keys)} removed')


if __name__ == '__main__':
    main()
