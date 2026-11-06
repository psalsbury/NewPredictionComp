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

# SIM1 personalities (2026-11-06). Every SIM1 bot is an expert, but each one reads a match its own way
# and always takes the pick with the highest expected points under its own view; no randomness.
# Keyed by users.id so a rename never changes a bot's style. Other grades keep the shared model.
PERSONAS = {
    1:  dict(key='long',   name='The Long Game',  blurb='Two seasons of form, slow to change its mind',
             window=730, half_life=365, last_n=None, venue=.35, market=.35, streaks=False, draws=False, style='likeliest'),
    9:  dict(key='form',   name='The Form Guide', blurb='Only the last six games count',
             window=120, half_life=None, last_n=6, venue=.2, market=.3, streaks=False, draws=False, style='goals', lean=0.0),
    11: dict(key='streak', name='The Streak Chaser', blurb='Hot scorers score more; leaky defences leak more',
             window=365, half_life=90, last_n=None, venue=.35, market=.3, streaks=True, draws=False, style='goals', lean=.15),
    14: dict(key='market', name='The Market Reader', blurb='Trusts the bookmakers’ prices above all',
             window=730, half_life=180, last_n=None, venue=.35, market=1.0, streaks=False, draws=False, style='likeliest'),
    21: dict(key='venue',  name='The Home & Away Specialist', blurb='Home record at home, away record away; backs draws in tight games',
             window=540, half_life=240, last_n=None, venue=1.0, market=.3, streaks=False, draws=True, style='likeliest'),
}

class Strategy:
    def __init__(self, matches, odds=None, now=None, score_odds=None):
        self.matches = sorted(matches, key=lambda m: when(m['kickoff']))
        self.odds = odds or {}
        self.score_odds = score_odds or {}
        self.now = when(now) if now else datetime.now(timezone.utc)
        self.cache = {}
        self.pcache = {}

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
        # Correct-score market (fixture_score_odds): when a fresh pre-match snapshot exists it
        # replaces most of the model's score grid, so every bot level reads the bookmakers' view.
        market=self.score_odds.get(external)
        if market:
            observed,fetched=when(market['observed_at']),when(market['fetched_at'])
            if observed<=cutoff and fetched<=cutoff:
                age=(cutoff-observed).total_seconds()/86400
                blend=.85 if age<=3 else .6 if age<=7 else 0
                inv={k:1/v for k,v in market['prices'].items() if v>1}
                total=sum(inv.values())
                if blend and total:
                    grid=[inv.get((h,a),0)/total for h,a in SCORES]
                    if sum(grid)>0:
                        grid=normalise(grid)
                        probs=normalise([blend*g+(1-blend)*p for g,p in zip(grid,probs)])
        values=utilities(probs)
        self.cache[key]=(probs,values)
        return probs,values

    def persona_expectations(self, cfg, home, away, cutoff):
        """Expected goals for one SIM1 personality: its own memory length, recency and home/away weighting."""
        done=[m for m in self.matches if when(m['kickoff'])+timedelta(hours=2)<=cutoff]
        league=[m for m in done if when(m['kickoff'])>=cutoff-timedelta(days=730)]
        lw=[2**(-(cutoff-when(m['kickoff'])).total_seconds()/86400/180) for m in league]
        n=sum(lw)
        home_base=(sum(m['hg']*w for m,w in zip(league,lw))+20*1.55)/(n+20)
        away_base=(sum(m['ag']*w for m,w in zip(league,lw))+20*1.20)/(n+20)
        mid=(home_base+away_base)/2
        recent=[m for m in done if when(m['kickoff'])>=cutoff-timedelta(days=cfg['window'])]
        def rows(club,venue=None):
            out=[]
            for m in recent:
                if m['home']==club and venue in (None,'H'):out.append((m['hg'],m['ag'],m))
                if m['away']==club and venue in (None,'A'):out.append((m['ag'],m['hg'],m))
            out.sort(key=lambda r:when(r[2]['kickoff']))
            return out[-cfg['last_n']:] if cfg['last_n'] else out
        def weight(m):
            if not cfg['half_life']:return 1.0
            return 2**(-(cutoff-when(m['kickoff'])).total_seconds()/86400/cfg['half_life'])
        def team(club,venue=None):
            rs=rows(club,venue)
            gf_base=home_base if venue=='H' else away_base if venue=='A' else mid
            ga_base=away_base if venue=='H' else home_base if venue=='A' else mid
            k=3 if cfg['last_n'] else (6 if venue is None else 5)
            total=sum(weight(r[2]) for r in rs)
            return ((sum(gf*weight(m) for gf,ga,m in rs)+k*gf_base)/(total+k)/gf_base,
                    (sum(ga*weight(m) for gf,ga,m in rs)+k*ga_base)/(total+k)/ga_base)
        v=cfg['venue']
        h,a=team(home),team(away);hv,av=team(home,'H'),team(away,'A')
        hx=home_base*((1-v)*h[0]+v*hv[0])*((1-v)*a[1]+v*av[1])
        ax=away_base*((1-v)*a[0]+v*av[0])*((1-v)*h[1]+v*hv[1])
        if cfg['streaks']:
            # Last four games: scoring 2+ in three of them = hot attack, conceding 2+ in three = leaky defence,
            # three clean sheets = locked door, failing to score in three = cold attack.
            def last4(club):
                rs=[r for r in rows(club) ][-4:]
                return (sum(gf>=2 for gf,ga,m in rs)>=3, sum(ga>=2 for gf,ga,m in rs)>=3,
                        sum(ga==0 for gf,ga,m in rs)>=3, sum(gf==0 for gf,ga,m in rs)>=3)
            hh,ha=last4(home),last4(away)
            hx*=(1.2 if hh[0] else 1)*(1.2 if ha[1] else 1)*(.85 if ha[2] else 1)*(.85 if hh[3] else 1)
            ax*=(1.2 if ha[0] else 1)*(1.2 if hh[1] else 1)*(.85 if hh[2] else 1)*(.85 if ha[3] else 1)
        return max(.25,min(3.8,hx)),max(.25,min(3.8,ax))

    def persona_forecast(self, cfg, external, home, away, kickoff=None):
        cutoff=min(self.now,when(kickoff)) if kickoff else self.now
        key=(cfg['key'],external,home,away,cutoff.isoformat())
        if key in self.pcache:return self.pcache[key][:2]
        hx,ax=self.persona_expectations(cfg,home,away,cutoff)
        probs=poisson_grid(hx,ax)
        trust=cfg['market']
        price=self.odds.get(external)
        if price and trust:
            observed,fetched=when(price['observed_at']),when(price['fetched_at'])
            prices=[float(price[k]) for k in ['away','draw','home']]
            if all(math.isfinite(v) and v>1 for v in prices) and observed<=cutoff and fetched<=cutoff:
                age=(cutoff-observed).total_seconds()/86400
                blend=(.9 if age<=3 else .5 if age<=7 else 0)*trust
                market=normalise([1/v for v in prices])
                masses=[sum(p for (h,a),p in zip(SCORES,probs) if result(h,a)==r) for r in [-1,0,1]]
                desired=[blend*m+(1-blend)*p for m,p in zip(market,masses)]
                probs=normalise([p*desired[result(h,a)+1]/masses[result(h,a)+1] for (h,a),p in zip(SCORES,probs)])
        market=self.score_odds.get(external)
        if market and trust:
            observed,fetched=when(market['observed_at']),when(market['fetched_at'])
            if observed<=cutoff and fetched<=cutoff:
                age=(cutoff-observed).total_seconds()/86400
                blend=(.95 if age<=3 else .7 if age<=7 else 0)*trust
                inv={k:1/v for k,v in market['prices'].items() if v>1}
                total=sum(inv.values())
                if blend and total:
                    grid=[inv.get((h,a),0)/total for h,a in SCORES]
                    if sum(grid)>0:
                        grid=normalise(grid)
                        probs=normalise([blend*g+(1-blend)*p for g,p in zip(grid,probs)])
        values=utilities(probs)
        if cfg['draws']:
            res={r:sum(p for (h,a),p in zip(SCORES,probs) if result(h,a)==r) for r in [-1,0,1]}
            if res[0]>=.27 and abs(res[1]-res[-1])<=.10:
                # Tight game: the specialist always backs the likeliest draw.
                best=max((p,i) for i,((h,a),p) in enumerate(zip(SCORES,probs)) if h==a)[1]
                values=list(values);values[best]=max(values)+.01
        self.pcache[key]=(probs,values,hx,ax)
        return probs,values

    def persona_pick(self, cfg, external, home, away, kickoff=None):
        """Result first (the outcome worth most to this persona), then the scoreline in its own style:
        'likeliest' = most probable score for that result; 'goals' = the score nearest its expected goals
        (lean > 0 rounds up, so a goal-hungry read predicts more goals)."""
        probs,values=self.persona_forecast(cfg,external,home,away,kickoff)
        cutoff=min(self.now,when(kickoff)) if kickoff else self.now
        hx,ax=self.pcache[(cfg['key'],external,home,away,cutoff.isoformat())][2:]
        best=max(range(len(SCORES)),key=lambda i:values[i])
        want=result(*SCORES[best])
        if cfg['style']=='goals':
            tx,ty=hx+cfg['lean'],ax+cfg['lean']
            if want==0:tx=ty=(tx+ty)/2
            options=[(h,a) for h,a in SCORES if result(h,a)==want]
            return min(options,key=lambda s:(abs(s[0]-tx)+abs(s[1]-ty),-probs[SCORES.index(s)]))
        return SCORES[best]

    def predict(self, grade, uid, external, home, away, kickoff=None):
        cfg=PERSONAS.get(int(uid)) if int(grade)==1 else None
        if cfg:return self.persona_pick(cfg,external,home,away,kickoff)

        # Level 5 never reads club form or prices: plausible, independent random
        # scores, concentrated on normal football scores instead of 0..5 uniform.
        values=[0.0]*len(SCORES) if grade==5 else self.forecast(external,home,away,kickoff)[1]
        weights=selection_weights(grade,values)
        rng=random.Random(f'{VERSION}:{uid}:{external}:{grade}')
        return rng.choices(SCORES,weights=weights,k=1)[0]

    def alternatives(self, grade, external, home, away, kickoff=None, uid=None):
        cfg=PERSONAS.get(int(uid)) if uid is not None and int(grade)==1 else None
        if cfg:
            values=self.persona_forecast(cfg,external,home,away,kickoff)[1]
            return sorted(zip(SCORES,selection_weights(1,values)),key=lambda p:-p[1])
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
    score_odds={}
    try:
        # skip finished matches whose grid was pruned by bin/prune_odds.py (only some scorelines remain)
        pruned=' WHERE s.fixture_id NOT IN (SELECT fixture_id FROM fixture_score_summary)' if db.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='fixture_score_summary'").fetchone() else ''
        for external,h,a,price,observed,fetched in db.execute('SELECT f.external_id,s.home_goals,s.away_goals,s.price,s.observed_at,s.fetched_at FROM fixture_score_odds s JOIN fixtures f ON f.id=s.fixture_id'+pruned):
            m=score_odds.setdefault(external,{'prices':{},'observed_at':observed,'fetched_at':fetched})
            m['prices'][(int(h),int(a))]=float(price)
    except Exception as exc:
        import sqlite3
        if not isinstance(exc,sqlite3.OperationalError) or 'no such table' not in str(exc): raise
    return Strategy(matches,odds,now,score_odds)
