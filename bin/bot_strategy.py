"""Pre-match score probabilities and progressively weaker 5/3/0 strategies.

No fixture result is available to its own forecast. All levels share the same
plausible score prior; increasing skill increases the preference for scores
with higher expected competition points, rather than adding club bias.
"""
import math
import random
from datetime import datetime, timedelta, timezone

VERSION = 'expected-points-v4'
BETAS = {1: 60.0, 2: 6.0, 3: 2.5, 4: .75, 5: 0.0}
SCORES = [(h, a) for h in range(6) for a in range(6)]

def when(value):
    date = datetime.fromisoformat(str(value).replace('Z', '+00:00'))
    return date.replace(tzinfo=timezone.utc) if date.tzinfo is None else date.astimezone(timezone.utc)

def result(h, a):
    return (h > a) - (h < a)

def normalise(values):
    total = sum(values)
    return [v / total for v in values]

def poisson_grid(hx, ax):
    def p(k, mean):
        return math.exp(-mean) * mean ** k / math.factorial(k)
    return normalise([p(h, hx) * p(a, ax) for h, a in SCORES])

PRIOR = poisson_grid(1.45, 1.15)

def utilities(probabilities):
    outcomes = {r: sum(p for (h,a),p in zip(SCORES,probabilities) if result(h,a)==r) for r in [-1,0,1]}
    # Exact earns 5 total, not 5 plus the 3 for the correct result.
    return [3 * outcomes[result(h,a)] + 2 * p for (h,a),p in zip(SCORES,probabilities)]

def selection_weights(grade, values):
    beta = BETAS[int(grade)]
    top = max(values)
    return normalise([prior * math.exp(beta * (value - top)) for prior,value in zip(PRIOR,values)])

class Strategy:
    def __init__(self, matches, odds=None, now=None):
        self.matches = sorted(matches, key=lambda m: when(m['kickoff']))
        self.odds = odds or {}
        self.now = when(now) if now else datetime.now(timezone.utc)
        self.cache = {}

    def expectations(self, home, away, cutoff):
        # Results need time to have finished. Matches still in progress and
        # simultaneous fixtures cannot leak into the forecast.
        matches = [m for m in self.matches if when(m['kickoff']) + timedelta(hours=2) <= cutoff
                   and when(m['kickoff']) >= cutoff - timedelta(days=730)]
        weights = [2 ** (-(cutoff-when(m['kickoff'])).total_seconds()/86400/180) for m in matches]
        n = sum(weights)
        home_base = (sum(m['hg']*w for m,w in zip(matches,weights)) + 20*1.55)/(n+20)
        away_base = (sum(m['ag']*w for m,w in zip(matches,weights)) + 20*1.20)/(n+20)
        league = (home_base+away_base)/2
        def team(club, venue=None):
            rows=[]
            for m,w in zip(matches,weights):
                if m['home']==club and venue in (None,'H'): rows.append((m['hg'],m['ag'],w))
                if m['away']==club and venue in (None,'A'): rows.append((m['ag'],m['hg'],w))
            # Sparse venue samples shrink toward league rates rather than a
            # fictitious 1.35-1.35 head-to-head record.
            gf_base=home_base if venue=='H' else away_base if venue=='A' else league
            ga_base=away_base if venue=='H' else home_base if venue=='A' else league
            k=6 if venue is None else 5
            total=sum(r[2] for r in rows)
            return ((sum(gf*w for gf,ga,w in rows)+k*gf_base)/(total+k)/gf_base,
                    (sum(ga*w for gf,ga,w in rows)+k*ga_base)/(total+k)/ga_base)
        h,a=team(home),team(away);hv,av=team(home,'H'),team(away,'A')
        hx=home_base*(.65*h[0]+.35*hv[0])*(.65*a[1]+.35*av[1])
        ax=away_base*(.65*a[0]+.35*av[0])*(.65*h[1]+.35*hv[1])
        return max(.25,min(3.8,hx)),max(.25,min(3.8,ax))

    def forecast(self, external, home, away, kickoff=None):
        cutoff = min(self.now, when(kickoff)) if kickoff else self.now
        key=(external,home,away,cutoff.isoformat())
        if key in self.cache:return self.cache[key]
        hx,ax=self.expectations(home,away,cutoff)
        probs=poisson_grid(hx,ax)
        price=self.odds.get(external)
        if price:
            observed,fetched=when(price['observed_at']),when(price['fetched_at'])
            prices=[float(price[k]) for k in ['away','draw','home']]
            if all(math.isfinite(v) and v>1 for v in prices) and observed<=cutoff and fetched<=cutoff:
                age=(cutoff-observed).total_seconds()/86400
                blend=.85 if age<=3 else .45 if age<=7 else 0
                market=normalise([1/v for v in prices])
                masses=[sum(p for (h,a),p in zip(SCORES,probs) if result(h,a)==r) for r in [-1,0,1]]
                desired=[blend*m+(1-blend)*p for m,p in zip(market,masses)]
                probs=normalise([p*desired[result(h,a)+1]/masses[result(h,a)+1] for (h,a),p in zip(SCORES,probs)])
        values=utilities(probs)
        self.cache[key]=(probs,values)
        return probs,values

    def predict(self, grade, uid, external, home, away, kickoff=None):
        # Level 5 never reads club form or prices: plausible, independent random
        # scores, concentrated on normal football scores instead of 0..5 uniform.
        values=[0.0]*len(SCORES) if grade==5 else self.forecast(external,home,away,kickoff)[1]
        weights=selection_weights(grade,values)
        rng=random.Random(f'{VERSION}:{uid}:{external}:{grade}')
        return rng.choices(SCORES,weights=weights,k=1)[0]

    def alternatives(self, grade, external, home, away, kickoff=None):
        values=[0.0]*len(SCORES) if grade==5 else self.forecast(external,home,away,kickoff)[1]
        weights=selection_weights(grade,values)
        return sorted(zip(SCORES,weights),key=lambda p:-p[1])

def from_database(db, canonical=lambda name:name, archive=(), now=None):
    matches=list(archive)
    for kick,home,away,hg,ag in db.execute("SELECT kickoff_utc,home,away,home_score,away_score FROM fixtures WHERE status IN ('FT','AET','PEN') AND home_score IS NOT NULL AND away_score IS NOT NULL"):
        matches.append(dict(kickoff=kick,home=canonical(home),away=canonical(away),hg=hg,ag=ag))
    # Deduplicate overlapping stored/archive fixtures without using future data.
    matches=list({(when(m['kickoff']).date(),m['home'],m['away']):m for m in matches}.values())
    odds={}
    try:
        for external,h,d,a,observed,fetched in db.execute('SELECT f.external_id,o.home,o.draw,o.away,o.observed_at,o.fetched_at FROM fixture_odds o JOIN fixtures f ON f.id=o.fixture_id'):
            odds[external]=dict(home=h,draw=d,away=a,observed_at=observed,fetched_at=fetched)
    except Exception as exc:
        import sqlite3
        if not isinstance(exc,sqlite3.OperationalError) or 'no such table' not in str(exc): raise
    return Strategy(matches,odds,now)
