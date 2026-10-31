<?php
// Live league table fragment for the homepage / league pages: /live-table.php?league=ID&period=season|YYYY-MM
// Returns the HTML box, or nothing when no match in the period is in progress.
declare(strict_types=1);
require __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/listing.php';
require_once __DIR__ . '/clubs.php';
require_once __DIR__ . '/inc/live-table.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex');
$db = new PDO('sqlite:' . pc_db_path(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA busy_timeout=3000');
$uid = 0;
if (!empty($_COOKIE['pc_token'])) { $q = $db->prepare('SELECT id FROM users WHERE token=?'); $q->execute([(string)$_COOKIE['pc_token']]); $uid = (int)$q->fetchColumn(); }
$league = (int)($_GET['league'] ?? 0);
if ($league) { // friend leagues: members only
    $q = $db->prepare('SELECT 1 FROM league_members WHERE league_id=? AND user_id=?'); $q->execute([$league, $uid]);
    if (!$q->fetchColumn()) { http_response_code(403); exit; }
}
$uk = new DateTimeZone('Europe/London');
$season = pcSeasonYear();
$period = (string)($_GET['period'] ?? '');
if ($period === 'season') { $start = new DateTimeImmutable($season . '-08-01 00:00:00', $uk); $end = $start->modify('+10 months'); }
elseif (preg_match('/^(\d{4})-(\d{2})$/', $period)) { $start = new DateTimeImmutable($period . '-01 00:00:00', $uk); $end = $start->modify('+1 month'); }
else { http_response_code(400); exit; }
echo pcLiveTableHtml(pcLiveTable($db, $uid, $league, $start, $end), $league, $period);
