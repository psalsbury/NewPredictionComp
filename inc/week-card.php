<?php
declare(strict_types=1);
// "Share your week": a summary of the player's latest matchweek with results, drawn as an image by assets/week-card.js.

function pcWeekCard(PDO $db, int $uid, array $user, int $season, int $week, array $myLeagues, DateTimeImmutable $seasonStart, DateTimeImmutable $seasonEnd): ?array {
    if ($uid < 1) return null;
    $final = "f.status IN ('FT','AET','PEN') AND f.home_score IS NOT NULL AND f.away_score IS NOT NULL";
    foreach ([$week, $week - 1] as $gw) {
        if ($gw < 1) continue;
        $q = $db->prepare("SELECT f.id, f.home, f.away, f.home_score, f.away_score, ($final) AS done, p.home_score ph, p.away_score pa, p.points
            FROM fixture_gameweeks g JOIN fixtures f ON f.id=g.fixture_id LEFT JOIN predictions p ON p.fixture_id=f.id AND p.user_id=?
            WHERE g.season=? AND g.gameweek=? AND COALESCE(f.status,'') NOT IN ('PST','CANC','ABD') ORDER BY f.kickoff_utc, f.id");
        $q->execute([$uid, $season, $gw]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $scored = array_values(array_filter($rows, fn($r) => $r['done'] && $r['ph'] !== null));
        if (!$scored) continue;
        $done = array_values(array_filter($rows, fn($r) => $r['done']));
        $pts = array_sum(array_map(fn($r) => (int)$r['points'], $scored));
        $exact = array_values(array_filter($scored, fn($r) => (int)$r['points'] === 5));
        $correct = count(array_filter($scored, fn($r) => (int)$r['points'] === 3));
        // Best SIM1 bot over the same finished matches.
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $done));
        $sim1 = (int)$db->query("SELECT COALESCE(MAX(s),0) FROM (SELECT SUM(COALESCE(p.points,0)) s FROM predictions p JOIN users u ON u.id=p.user_id
            WHERE u.is_bot=1 AND COALESCE(u.bot_grade,5)=1 AND p.fixture_id IN ($ids) GROUP BY p.user_id)")->fetchColumn();
        // League position for the season: first friend league, else the overall table.
        $place = null;
        if ($myLeagues) {
            $l = $myLeagues[0];
            // Same rule as flowLeagueStanding(): rank = 1 + members with more points this season.
            $q = $db->prepare('SELECT u.id,COALESCE(SUM(CASE WHEN f.id IS NOT NULL THEN p.points ELSE 0 END),0) pts FROM league_members m JOIN users u ON u.id=m.user_id LEFT JOIN predictions p ON p.user_id=u.id LEFT JOIN fixtures f ON f.id=p.fixture_id AND julianday(f.kickoff_utc)>=julianday(?) AND julianday(f.kickoff_utc)<julianday(?) WHERE m.league_id=? GROUP BY u.id');
            $q->execute([$seasonStart->format('c'), $seasonEnd->format('c'), (int)$l['id']]);
            $all = $q->fetchAll(PDO::FETCH_ASSOC); $mine = 0;
            foreach ($all as $r) if ((int)$r['id'] === $uid) $mine = (int)$r['pts'];
            $place = ['rank' => 1 + count(array_filter($all, fn($r) => (int)$r['pts'] > $mine)), 'of' => (int)$l['member_count'], 'name' => (string)$l['name'], 'code' => (string)$l['code']];
        } else {
            $q = $db->prepare("SELECT u.id, COALESCE(SUM(CASE WHEN f.id IS NOT NULL THEN p.points ELSE 0 END),0) pts FROM users u LEFT JOIN predictions p ON p.user_id=u.id
                LEFT JOIN fixtures f ON f.id=p.fixture_id AND f.kickoff_utc>=? AND f.kickoff_utc<? WHERE (" . pc_listed_sql() . " OR u.id=?) GROUP BY u.id");
            $q->execute([$seasonStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $seasonEnd->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $uid]);
            $all = $q->fetchAll(PDO::FETCH_ASSOC); $mine = 0;
            foreach ($all as $r) if ((int)$r['id'] === $uid) $mine = (int)$r['pts'];
            $place = ['rank' => 1 + count(array_filter($all, fn($r) => (int)$r['pts'] > $mine)), 'of' => count($all), 'name' => 'the overall table', 'code' => ''];
        }
        $short = fn($n) => function_exists('pcShortTeam') ? pcShortTeam($n) : $n;
        return [
            'name' => (string)$user['display_name'], 'week' => $gw, 'season' => $season . '/' . substr((string)($season + 1), 2),
            'pts' => $pts, 'exact' => count($exact), 'correct' => $correct, 'scored' => count($scored), 'finished' => count($done), 'total' => count($rows),
            'complete' => count($done) === count($rows), 'sim1' => $sim1, 'beat_sim1' => $pts > $sim1, 'place' => $place,
            'exact_list' => array_map(fn($r) => $short($r['home']) . ' ' . (int)$r['home_score'] . '–' . (int)$r['away_score'] . ' ' . $short($r['away']), array_slice($exact, 0, 2)),
        ];
    }
    return null;
}

function pcWeekShareHtml(?array $c): string {
    if (!$c) return '';
    $sum = $c['pts'] . ' pts' . ($c['complete'] ? '' : ' so far') . ' · ' . $c['exact'] . ' exact · ' . $c['correct'] . ' correct';
    return '<div class="weekshare"><div><strong>Your Matchweek ' . (int)$c['week'] . '</strong><span>' . htmlspecialchars($sum) . '</span></div>'
        . '<button type="button" class="weeksharebtn" onclick="shareWeekCard(this)">Share your week</button></div>';
}
