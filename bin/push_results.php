<?php
declare(strict_types=1);
// PredictionComp iPhone app push notifications (Pete, 2026-11-21). Run every 10 minutes by predictioncomp-push.timer.
// When every match of a matchweek has finished and been scored, each player with the app (push_tokens) who predicted
// in it gets one notification: their points for the matchweek and their place in their first league.
//   php push_results.php            send what is due
//   php push_results.php --test ID  send a test notification to user ID's phones
// APNs settings: /etc/predictioncomp/apns.json {"key_id","team_id","bundle","key_file"} (the .p8 key from
// developer.apple.com > Keys). No settings file = nothing is sent.
const PC_ROOT = '/var/www/predictioncomp.com/public_html';
$cfgFile = '/etc/predictioncomp/apns.json';
if (!is_readable($cfgFile)) exit(0);
$cfg = json_decode((string)file_get_contents($cfgFile), true);
$db = new PDO('sqlite:/var/lib/predictioncomp/predictioncomp.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA busy_timeout=5000');
require PC_ROOT . '/inc/app.php';
pc_app_tables($db);

function apns_jwt(array $cfg): string {
    static $jwt = null, $at = 0;
    if ($jwt && time() - $at < 1800) return $jwt;
    $b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $head = $b64(json_encode(['alg' => 'ES256', 'kid' => $cfg['key_id']]));
    $claims = $b64(json_encode(['iss' => $cfg['team_id'], 'iat' => time()]));
    openssl_sign("$head.$claims", $der, openssl_pkey_get_private((string)file_get_contents($cfg['key_file'])), OPENSSL_ALGO_SHA256);
    // DER ECDSA signature -> the raw 64-byte r||s that JWT wants
    $pos = 3; $rl = ord($der[$pos]); $r = substr($der, $pos + 1, $rl); $pos += $rl + 2; $sl = ord($der[$pos]); $s = substr($der, $pos + 1, $sl);
    $fix = fn($x) => str_pad(ltrim($x, "\0"), 32, "\0", STR_PAD_LEFT);
    $at = time();
    return $jwt = "$head.$claims." . $b64($fix($r) . $fix($s));
}

/** Sends one alert to one device. Returns the HTTP status (200 = delivered to Apple, 410/400 BadDeviceToken = drop the token). */
function apns_send(array $cfg, string $token, string $title, string $body): array {
    $payload = json_encode(['aps' => ['alert' => ['title' => $title, 'body' => $body], 'sound' => 'default']]);
    $cmd = ['curl', '-s', '--http2', '-o', '-', '-w', "\n%{http_code}", '--max-time', '15',
        '-H', 'authorization: bearer ' . apns_jwt($cfg), '-H', 'apns-topic: ' . $cfg['bundle'], '-H', 'apns-push-type: alert',
        '-H', 'apns-priority: 10', '--data-binary', $payload, 'https://api.push.apple.com/3/device/' . $token];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); proc_close($p);
    $lines = explode("\n", trim($out)); $code = (int)array_pop($lines);
    return [$code, implode("\n", $lines)];
}

function send_user(PDO $db, array $cfg, int $uid, string $title, string $body): int {
    $s = $db->prepare('SELECT token FROM push_tokens WHERE user_id=?'); $s->execute([$uid]); $sent = 0;
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $tok) {
        [$code, $resp] = apns_send($cfg, $tok, $title, $body);
        if ($code === 200) $sent++;
        elseif ($code === 410 || str_contains($resp, 'BadDeviceToken') || str_contains($resp, 'Unregistered')) $db->prepare('DELETE FROM push_tokens WHERE token=?')->execute([$tok]);
        else fwrite(STDERR, "push to user $uid failed: $code $resp\n");
    }
    return $sent;
}

$ord = fn(int $n) => $n . (in_array($n % 100, [11, 12, 13]) ? 'th' : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th'));

if (($argv[1] ?? '') === '--test') {
    $uid = (int)($argv[2] ?? 0);
    $n = send_user($db, $cfg, $uid, 'PredictionComp', 'Notifications are working. You will hear from us when each matchweek\'s results are in.');
    echo "sent to $n device(s)\n"; exit(0);
}

// Matchweeks whose matches have all finished and been scored, in the last 3 days
$weeks = $db->query("SELECT g.season, g.gameweek, MAX(f.kickoff_utc) last FROM fixture_gameweeks g JOIN fixtures f ON f.id=g.fixture_id
  GROUP BY g.season, g.gameweek
  HAVING SUM(CASE WHEN COALESCE(f.status,'NS') IN ('PST','CANC','ABD') THEN 0 WHEN f.status='FT' AND f.home_score IS NOT NULL THEN 0 ELSE 1 END)=0
   AND julianday(MAX(f.kickoff_utc)) > julianday('now','-3 days')")->fetchAll(PDO::FETCH_ASSOC);
foreach ($weeks as $w) {
    $ref = $w['season'] . '-' . $w['gameweek'];
    $unscored = $db->prepare("SELECT COUNT(*) FROM predictions p JOIN fixture_gameweeks g ON g.fixture_id=p.fixture_id JOIN fixtures f ON f.id=p.fixture_id
      WHERE g.season=? AND g.gameweek=? AND f.status='FT' AND p.points IS NULL");
    $unscored->execute([$w['season'], $w['gameweek']]);
    if ((int)$unscored->fetchColumn() > 0) continue; // scoring not finished yet
    $q = $db->prepare("SELECT p.user_id, SUM(COALESCE(p.points,0)) pts, COUNT(*) n FROM predictions p JOIN fixture_gameweeks g ON g.fixture_id=p.fixture_id
      WHERE g.season=? AND g.gameweek=? AND p.user_id IN (SELECT user_id FROM push_tokens)
      AND NOT EXISTS (SELECT 1 FROM push_log l WHERE l.user_id=p.user_id AND l.kind='gw_result' AND l.ref=?) GROUP BY p.user_id");
    $q->execute([$w['season'], $w['gameweek'], $ref]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['user_id']; $pts = (int)$r['pts'];
        $sum = pc_app_summary($db, $uid);
        $body = 'You scored ' . $pts . ' point' . ($pts === 1 ? '' : 's') . '.';
        if ($sum['league']) {
            $l = $sum['league'];
            $body .= " You're " . $ord($l['position']) . ' of ' . $l['of'] . ' in ' . $l['name']
                . ($l['movement'] > 0 ? ' (up ' . $l['movement'] . ')' : ($l['movement'] < 0 ? ' (down ' . abs($l['movement']) . ')' : '')) . '.';
        }
        $n = send_user($db, $cfg, $uid, 'Matchweek ' . $w['gameweek'] . ' results', $body);
        $db->prepare("INSERT OR IGNORE INTO push_log(user_id,kind,ref) VALUES(?,'gw_result',?)")->execute([$uid, $ref]);
        echo date('c') . " gw $ref user $uid: $n sent: $body\n";
    }
}
