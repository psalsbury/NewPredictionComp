<?php
declare(strict_types=1);
// League table periods: the whole season, a calendar month (YYYY-MM) or a gameweek (gwN).
// Gameweeks use the fixture_gameweeks mapping, so a rearranged match still counts in its own gameweek.
// Each period: ['key','kind'=>season|month|gameweek,'title','short','start','end','season','gw'=>?int,'range'=>string].
// start/end always bracket every fixture in the period; for gameweeks also add pc_period_fixture_sql().

function pc_periods(PDO $db, int $seasonYear): array {
    static $cache = [];
    if (isset($cache[$seasonYear])) return $cache[$seasonYear];
    $uk = new DateTimeZone('Europe/London');
    $now = new DateTimeImmutable('now', $uk);
    $seasonStart = new DateTimeImmutable($seasonYear . '-08-01 00:00:00', $uk);
    $seasonEnd = $seasonStart->modify('+10 months');
    $out = ['season' => ['key' => 'season', 'kind' => 'season', 'title' => 'Season ' . substr((string)$seasonYear, 2) . '/' . substr((string)($seasonYear + 1), 2),
        'short' => 'Season', 'start' => $seasonStart, 'end' => $seasonEnd, 'season' => $seasonYear, 'gw' => null, 'range' => '', 'started' => true]];
    for ($m = 0; $m < 10; $m++) {
        $ms = $seasonStart->modify('+' . $m . ' months');
        $out[$ms->format('Y-m')] = ['key' => $ms->format('Y-m'), 'kind' => 'month', 'title' => $ms->format('F Y'), 'short' => $ms->format('M'),
            'start' => $ms, 'end' => $ms->modify('+1 month'), 'season' => $seasonYear, 'gw' => null, 'range' => '', 'started' => $ms <= $now];
    }
    try {
        $q = $db->prepare("SELECT g.gameweek, MIN(f.kickoff_utc) first_ko, MAX(f.kickoff_utc) last_ko FROM fixture_gameweeks g JOIN fixtures f ON f.id=g.fixture_id
            WHERE g.season=? AND COALESCE(f.status,'') NOT IN ('SIM') GROUP BY g.gameweek ORDER BY g.gameweek");
        $q->execute([$seasonYear]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $gw = (int)$r['gameweek'];
            $first = (new DateTimeImmutable($r['first_ko'], new DateTimeZone('UTC')))->setTimezone($uk);
            $last = (new DateTimeImmutable($r['last_ko'], new DateTimeZone('UTC')))->setTimezone($uk);
            // Range shown under the title uses the bulk of the round (the first and last kick-offs).
            $range = $first->format('D j M') . ($first->format('Y-m-d') === $last->format('Y-m-d') ? '' : ' – ' . $last->format('D j M'));
            $out['gw' . $gw] = ['key' => 'gw' . $gw, 'kind' => 'gameweek', 'title' => 'Gameweek ' . $gw, 'short' => 'GW ' . $gw,
                'start' => $first, 'end' => $last->modify('+1 second'), 'season' => $seasonYear, 'gw' => $gw, 'range' => $range, 'started' => $first <= $now];
        }
    } catch (Throwable $e) {}
    return $cache[$seasonYear] = $out;
}

/** Look up a period key; null when unknown. */
function pc_period(PDO $db, int $seasonYear, string $key): ?array {
    return pc_periods($db, $seasonYear)[$key] ?? null;
}

/** Extra SQL condition limiting fixtures (alias $f) to a gameweek; '' for season/month. */
function pc_period_fixture_sql(?array $period, string $f = 'f'): string {
    if (!$period || $period['kind'] !== 'gameweek') return '';
    return ' AND ' . $f . '.id IN (SELECT fixture_id FROM fixture_gameweeks WHERE season=' . (int)$period['season'] . ' AND gameweek=' . (int)$period['gw'] . ')';
}

/** Periods of one kind that have started (gameweeks/months), oldest first. */
function pc_periods_of(PDO $db, int $seasonYear, string $kind): array {
    return array_values(array_filter(pc_periods($db, $seasonYear), fn($p) => $p['kind'] === $kind && $p['started']));
}

/** The gameweek being played now, or the latest one that has started. */
function pc_current_gameweek(PDO $db, int $seasonYear): ?array {
    $gws = pc_periods_of($db, $seasonYear, 'gameweek');
    return $gws ? end($gws) : null;
}

/** Compact data for the browser (friends league dialog). */
function pc_periods_client(PDO $db, int $seasonYear): array {
    $out = [];
    foreach (pc_periods($db, $seasonYear) as $p) {
        if (!$p['started']) continue;
        $out[] = ['key' => $p['key'], 'kind' => $p['kind'], 'title' => $p['title'], 'short' => $p['short'], 'range' => $p['range']];
    }
    return $out;
}

/**
 * Period picker: Season | Month | Gameweek tabs, then a ‹ prev · title + select · next › stepper for months and gameweeks.
 * $href(string $key): string returns the link for a period (already HTML-safe is not assumed; it is escaped here).
 */
function pc_period_picker_html(PDO $db, int $seasonYear, string $currentKey, callable $href, string $label = 'league table'): string {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    $periods = pc_periods($db, $seasonYear);
    $cur = $periods[$currentKey] ?? $periods['season'];
    $months = pc_periods_of($db, $seasonYear, 'month');
    $gws = pc_periods_of($db, $seasonYear, 'gameweek');
    $nowMonth = (new DateTimeImmutable('now', new DateTimeZone('Europe/London')))->format('Y-m');
    $monthDefault = isset($periods[$nowMonth]) ? $nowMonth : ($months ? end($months)['key'] : 'season');
    $gwDefault = $gws ? end($gws)['key'] : null;
    $tabs = [['season', 'Season', 'season'], ['month', 'Month', $cur['kind'] === 'month' ? $cur['key'] : $monthDefault]];
    if ($gwDefault) $tabs[] = ['gameweek', 'Gameweek', $cur['kind'] === 'gameweek' ? $cur['key'] : $gwDefault];
    $h = '<div class="periodpicker" data-kind="' . $e($cur['kind']) . '"><nav class="periodkinds" aria-label="Choose ' . $e($label) . ' type">';
    foreach ($tabs as [$kind, $text, $key]) {
        $on = $cur['kind'] === $kind;
        $h .= '<a href="' . $e($href($key)) . '"' . ($on ? ' class="active" aria-current="true"' : '') . '>' . $text . '</a>';
    }
    $h .= '</nav>';
    if ($cur['kind'] !== 'season') {
        $list = $cur['kind'] === 'month' ? $months : $gws;
        $keys = array_column($list, 'key');
        $i = array_search($cur['key'], $keys, true);
        $prev = ($i !== false && $i > 0) ? $list[$i - 1] : null;
        $next = ($i !== false && $i < count($list) - 1) ? $list[$i + 1] : null;
        $unit = $cur['kind'] === 'month' ? 'month' : 'gameweek';
        $arrow = fn($p, $sym, $dir) => $p
            ? '<a class="periodarrow" href="' . $e($href($p['key'])) . '" aria-label="' . $dir . ' ' . $unit . ': ' . $e($p['title']) . '">' . $sym . '</a>'
            : '<span class="periodarrow disabled" aria-hidden="true">' . $sym . '</span>';
        $sub = $cur['kind'] === 'gameweek' ? $cur['range'] : '';
        if ($cur['kind'] === 'gameweek' && $cur['key'] === $gwDefault) $sub = trim($sub . ' · Latest');
        $h .= '<div class="periodstep">' . $arrow($prev, '‹', 'Previous') . '<div class="periodnow"><strong>' . $e($cur['title']) . '</strong>' . ($sub !== '' ? '<small>' . $e($sub) . '</small>' : '');
        $h .= '<label class="periodjump"><span class="sr-only">Choose ' . $unit . '</span><select onchange="if(this.value)location.href=this.value" aria-label="Choose ' . $unit . '">';
        foreach (array_reverse($list) as $p) $h .= '<option value="' . $e($href($p['key'])) . '"' . ($p['key'] === $cur['key'] ? ' selected' : '') . '>' . $e($cur['kind'] === 'month' ? $p['title'] : $p['short'] . ($p['range'] ? ' · ' . $p['range'] : '')) . '</option>';
        $h .= '</select></label></div>' . $arrow($next, '›', 'Next') . '</div>';
    }
    return $h . '</div>';
}
