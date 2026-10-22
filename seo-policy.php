<?php
declare(strict_types=1);
/** Index useful near-term previews and completed reviews; keep all fixtures playable. */
function pcSeoPreviewReady(array $fixture): bool {
    if (in_array($fixture['status'] ?? '', ['FT','AET','PEN'], true)
        && ($fixture['home_score'] ?? null) !== null && ($fixture['away_score'] ?? null) !== null) return true;
    $kickoff = new DateTimeImmutable($fixture['kickoff_utc'], new DateTimeZone('UTC'));
    return $kickoff <= new DateTimeImmutable('+14 days', new DateTimeZone('UTC'));
}
function pcSeoGuideReady(string $week): bool {
    return $week <= (new DateTimeImmutable('+14 days', new DateTimeZone('Europe/London')))->format('Y-m-d');
}
