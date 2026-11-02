#!/usr/bin/env python3
"""Keep the database small: delete correct-score prices that are no longer needed.

Each match stores ~78 correct-score prices before kick-off (fixture_score_odds). Once the match
has finished and the result has had SETTLE_DAYS to be corrected, only these rows are kept:
  * every scoreline any player (human or bot) predicted for the match  - Called it, £1 score bets, SIM1 page
  * the actual final score                                             - "How likely was that score?"
  * the 6 most likely scores and the best pick for points              - match page table, the Bookie
Everything else is deleted. Before deleting, one row per match is written to fixture_score_summary
with what the full market said (sum of 1/price used to remove the margin, home/draw/away chances,
the rank of each kept score), so inc/score-odds.php gives exactly the same figures afterwards.

Also tidies sync_meta cs_attempt:<fixture> retry markers for finished matches.

  prune_odds.py            prune what is due (run nightly by predictioncomp-prune-odds.timer)
  prune_odds.py --dry-run  report only
"""
import argparse, json, os, sqlite3
from datetime import datetime, timezone

DB = os.environ.get('PREDICTIONCOMP_DB', '/var/lib/predictioncomp/predictioncomp.sqlite')
SETTLE_DAYS = 3
TOP_N = 6


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry-run', action='store_true')
    dry = ap.parse_args().dry_run
    c = sqlite3.connect(DB, timeout=60)
    c.execute('PRAGMA busy_timeout=60000')
    if not c.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='fixture_score_odds'").fetchone():
        print('No fixture_score_odds table yet.')
        return
    c.execute("""CREATE TABLE IF NOT EXISTS fixture_score_summary(fixture_id INTEGER PRIMARY KEY REFERENCES fixtures(id),
     scorelines INTEGER NOT NULL,inv_total REAL NOT NULL,p_home REAL NOT NULL,p_draw REAL NOT NULL,p_away REAL NOT NULL,
     ranks TEXT NOT NULL,pruned_at TEXT NOT NULL)""")
    c.commit()
    due = c.execute(f"""SELECT f.id,f.home_score,f.away_score FROM fixtures f
     WHERE f.status IN ('FT','AET','PEN') AND f.home_score IS NOT NULL AND f.away_score IS NOT NULL
     AND julianday(f.kickoff_utc)<=julianday('now','-{SETTLE_DAYS} days')
     AND EXISTS(SELECT 1 FROM fixture_score_odds s WHERE s.fixture_id=f.id)
     AND NOT EXISTS(SELECT 1 FROM fixture_score_summary m WHERE m.fixture_id=f.id)
     ORDER BY f.kickoff_utc""").fetchall()
    now = datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
    deleted = kept = 0
    for fid, ah, aa in due:
        rows = c.execute('SELECT home_goals,away_goals,price FROM fixture_score_odds WHERE fixture_id=?', (fid,)).fetchall()
        inv = {(h, a): 1 / p for h, a, p in rows if p > 1}
        total = sum(inv.values())
        if not total:
            continue
        prob = {k: v / total for k, v in inv.items()}
        res = {1: 0.0, 0: 0.0, -1: 0.0}
        for (h, a), p in prob.items():
            res[(h > a) - (h < a)] += p
        order = sorted(prob, key=lambda k: -prob[k])
        ev = {k: 3 * res[(k[0] > k[1]) - (k[0] < k[1])] + 2 * prob[k] for k in prob}
        keep = set(order[:TOP_N]) | {max(ev, key=ev.get), (ah, aa)}
        keep |= {tuple(r) for r in c.execute('SELECT DISTINCT home_score,away_score FROM predictions WHERE fixture_id=?', (fid,))}
        ranks = {f'{h}-{a}': order.index((h, a)) + 1 for h, a in keep if (h, a) in prob}
        drop = [(fid, h, a) for h, a, _ in rows if (h, a) not in keep]
        kept += len(rows) - len(drop)
        deleted += len(drop)
        print(f'fixture {fid}: keep {len(rows) - len(drop)}, delete {len(drop)}')
        if dry:
            continue
        with c:  # summary + delete in one transaction
            c.execute('INSERT INTO fixture_score_summary VALUES(?,?,?,?,?,?,?,?)',
                      (fid, len(rows), total, res[1], res[0], res[-1], json.dumps(ranks, separators=(',', ':')), now))
            c.executemany('DELETE FROM fixture_score_odds WHERE fixture_id=? AND home_goals=? AND away_goals=?', drop)
    meta = 0
    if not dry and c.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='sync_meta'").fetchone():
        with c:
            meta = c.execute("""DELETE FROM sync_meta WHERE key LIKE 'cs_attempt:%' AND CAST(substr(key,12) AS INTEGER) IN
             (SELECT id FROM fixtures WHERE status IN ('FT','AET','PEN','CANC','ABD'))""").rowcount
            c.execute("INSERT INTO sync_meta(key,value,updated_at) VALUES('odds_prune_last',?,CURRENT_TIMESTAMP) "
                      "ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=CURRENT_TIMESTAMP",
                      (f'{now} {len(due)} fixtures, deleted {deleted} price rows, kept {kept}',))
    print(f'{"Would delete" if dry else "Deleted"} {deleted} price rows from {len(due)} finished match(es); kept {kept}.'
          + (f' Removed {meta} old retry markers.' if meta else ''))
    c.close()


if __name__ == '__main__':
    main()
