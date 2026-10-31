<?php
declare(strict_types=1);
// Live league table: confirmed points plus what each pick is earning from matches in progress
// (live_scores, filled every minute by bin/live_scores.py). Display only: real points still come
// from the confirmed full-time result.

/** Points a pick would earn with this score (same rule as the sync: 5 exact, 3 result, 0). */
function pcPickPoints(int $ph, int $pa, int $h, int $a): int {
    if ($ph === $h && $pa === $a) return 5;
    return (($ph <=> $pa) === ($h <=> $a)) ? 3 : 0;
}

/**
 * @return array|null null when nothing in this period is being played right now.
 * rows: id, name, is_bot, bot_grade, pts (confirmed), live (provisional from live matches), total, rank, was (confirmed rank), move, you
 */
function pcLiveTable(PDO $db, int $uid, int $leagueId, DateTimeImmutable $start, DateTimeImmutable $end): ?array {
    try {
        $q = $db->prepare("SELECT f.id, f.home, f.away, l.home_score h, l.away_score a, l.clock FROM live_scores l JOIN fixtures f ON f.id=l.fixture_id
            WHERE COALESCE(f.status,'NS') NOT IN ('FT','AET','PEN','PST','CANC','ABD','AWD','WO') AND l.updated_at>=datetime('now','-30 minutes')
            AND f.kickoff_utc>=? AND f.kickoff_utc<?");
        $q->execute([$start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
        $live = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return null; }
    if (!$live) return null;
    $scope = $leagueId
        ? 'u.id IN (SELECT user_id FROM league_members WHERE league_id=' . (int)$leagueId . ')'
        : '(' . pc_listed_sql() . ' OR u.id=' . (int)$uid . ')';
    $q = $db->prepare("SELECT u.id, u.display_name, CAST(COALESCE(u.is_bot,0) AS INTEGER) is_bot, CAST(COALESCE(u.bot_grade,5) AS INTEGER) bot_grade,
        COALESCE(SUM(CASE WHEN f.id IS NOT NULL THEN p.points ELSE 0 END),0) pts
        FROM users u LEFT JOIN predictions p ON p.user_id=u.id
        LEFT JOIN fixtures f ON f.id=p.fixture_id AND f.kickoff_utc>=:s AND f.kickoff_utc<:e
        WHERE $scope GROUP BY u.id");
    $q->execute([':s' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), ':e' => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
    $rows = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[(int)$r['id']] = ['id' => (int)$r['id'], 'name' => $r['display_name'], 'is_bot' => (int)$r['is_bot'], 'bot_grade' => (int)$r['bot_grade'],
            'pts' => (int)$r['pts'], 'live' => 0, 'you' => (int)$r['id'] === $uid];
    }
    if (!$rows) return null;
    $ids = implode(',', array_map(fn($m) => (int)$m['id'], $live));
    $byFixture = [];
    foreach ($live as $m) $byFixture[(int)$m['id']] = $m;
    foreach ($db->query("SELECT user_id, fixture_id, home_score, away_score FROM predictions WHERE fixture_id IN ($ids)") as $p) {
        $u = (int)$p['user_id']; $m = $byFixture[(int)$p['fixture_id']];
        if (isset($rows[$u])) $rows[$u]['live'] += pcPickPoints((int)$p['home_score'], (int)$p['away_score'], (int)$m['h'], (int)$m['a']);
    }
    $rank = function (array $list, string $key): array {
        usort($list, fn($a, $b) => ($b[$key] <=> $a[$key]) ?: ($a['is_bot'] <=> $b['is_bot']) ?: ($a['id'] <=> $b['id']));
        $out = []; $prev = null; $pos = 0;
        foreach ($list as $i => $r) { if ($r[$key] !== $prev) $pos = $i + 1; $out[$r['id']] = $pos; $prev = $r[$key]; }
        return $out;
    };
    foreach ($rows as &$r) $r['total'] = $r['pts'] + $r['live'];
    unset($r);
    $was = $rank(array_values($rows), 'pts');
    $now = $rank(array_values($rows), 'total');
    foreach ($rows as &$r) { $r['was'] = $was[$r['id']]; $r['rank'] = $now[$r['id']]; $r['move'] = $r['was'] - $r['rank']; }
    unset($r);
    $list = array_values($rows);
    usort($list, fn($a, $b) => ($a['rank'] <=> $b['rank']) ?: ($a['is_bot'] <=> $b['is_bot']) ?: ($a['id'] <=> $b['id']));
    return ['rows' => $list, 'matches' => $live, 'count' => count($list)];
}

/** The live table box. Big tables show the top 10 plus the viewer's row. */
function pcLiveTableHtml(?array $t, int $leagueId, string $period): string {
    if (!$t) return '';
    $rows = $t['rows'];
    $show = array_slice($rows, 0, 10);
    $me = null;
    foreach ($rows as $r) if ($r['you']) $me = $r;
    $meShown = $me && in_array($me['id'], array_column($show, 'id'), true);
    $games = implode(' · ', array_map(fn($m) => htmlspecialchars(pcShortTeam($m['home']) . ' ' . (int)$m['h'] . '–' . (int)$m['a'] . ' ' . pcShortTeam($m['away'])) . ' <em>' . htmlspecialchars($m['clock'] === 'FT' || $m['clock'] === 'HT' ? $m['clock'] : $m['clock']) . '</em>', $t['matches']));
    $h = '<section class="livetable" id="liveTable" data-league="' . (int)$leagueId . '" data-period="' . htmlspecialchars($period, ENT_QUOTES) . '" aria-live="polite">';
    $h .= '<div class="livetablehead"><strong><span class="livedot" aria-hidden="true"></span>Live table</strong><small>If the scores stay like this</small></div>';
    $h .= '<p class="livegames">' . $games . '</p>';
    if ($me) {
        $h .= '<p class="liveyou">You: <b>' . pcOrdinal($me['was']) . '</b>' . ($me['move'] ? ' → <b>' . pcOrdinal($me['rank']) . '</b>' : '') . ' · <b>+' . $me['live'] . '</b> live ' . ($me['live'] === 1 ? 'point' : 'points') . '</p>';
    }
    $h .= '<ol class="liverows">';
    $row = function (array $r): string {
        $mv = $r['move'] > 0 ? '<span class="lmove up">↑' . $r['move'] . '</span>' : ($r['move'] < 0 ? '<span class="lmove down">↓' . abs($r['move']) . '</span>' : '<span class="lmove same">–</span>');
        return '<li class="' . ($r['you'] ? 'you' : '') . '"><b class="lpos">' . $r['rank'] . '</b>' . $mv . '<span class="lname">' . htmlspecialchars($r['name']) . ($r['is_bot'] ? ' <small class="simbadge">SIM' . max(1, min(5, $r['bot_grade'])) . '</small>' : '') . '</span><span class="llive">' . ($r['live'] ? '+' . $r['live'] : '') . '</span><b class="ltotal">' . $r['total'] . '</b></li>';
    };
    foreach ($show as $r) $h .= $row($r);
    if ($me && !$meShown) $h .= '<li class="gap" aria-hidden="true">…</li>' . $row($me);
    $h .= '</ol><p class="livenote">Points from matches in progress are provisional until full time is confirmed.</p></section>';
    return $h;
}

function pcOrdinal(int $n): string {
    $s = ['th', 'st', 'nd', 'rd']; $v = $n % 100;
    return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
}

function pcShortTeam(string $name): string {
    $map = ['Manchester United' => 'Man Utd', 'Manchester City' => 'Man City', 'Tottenham Hotspur' => 'Spurs', 'Nottingham Forest' => "Nott'm Forest",
        'Brighton & Hove Albion' => 'Brighton', 'AFC Bournemouth' => 'Bournemouth', 'Wolverhampton Wanderers' => 'Wolves', 'West Ham United' => 'West Ham',
        'Newcastle United' => 'Newcastle', 'Leeds United' => 'Leeds', 'Crystal Palace' => 'Palace', 'Ipswich Town' => 'Ipswich', 'Coventry City' => 'Coventry', 'Hull City' => 'Hull'];
    return $map[$name] ?? $name;
}
