<?php
// Live scores for the homepage (display only): /live.php?ids=1,2,3 -> {"1":{"h":2,"a":0,"clock":"67'"}}.
// A fixture with a confirmed result returns {"final":true} so the page reloads to show points.
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: public, max-age=20');
header('X-Robots-Tag: noindex');
$ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))))), 0, 40);
if (!$ids) { echo '{}'; exit; }
$db = new PDO('sqlite:' . (getenv('PREDICTIONCOMP_DB') ?: '/var/lib/predictioncomp/predictioncomp.sqlite'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA busy_timeout=3000');
$in = implode(',', $ids);
$out = [];
try {
    foreach ($db->query("SELECT f.id, f.status, f.home_score fh, f.away_score fa, l.home_score lh, l.away_score la, l.clock FROM fixtures f LEFT JOIN live_scores l ON l.fixture_id=f.id WHERE f.id IN ($in)") as $r) {
        if (in_array($r['status'], ['FT', 'AET', 'PEN'], true) && $r['fh'] !== null) { $out[(string)$r['id']] = ['final' => true]; continue; }
        if ($r['lh'] !== null) $out[(string)$r['id']] = ['h' => (int)$r['lh'], 'a' => (int)$r['la'], 'clock' => (string)$r['clock']];
    }
} catch (Throwable $e) { $out = []; }
echo json_encode($out);
