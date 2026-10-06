# PredictionComp

Premier League prediction game deployed at https://predictioncomp.com/.

## Club names and cache

Team names are data, not lists embedded in code. SQLite `clubs` and `club_aliases` store display names, source aliases and badge locations. Season membership comes from recorded fixtures, using July-to-June seasons. Existing metadata was migrated into these tables without changing fixture IDs or predictions.

PHP `clubs.php` and Python `bin/club_catalog.py` share `/var/cache/predictioncomp/clubs.json`. A valid cache serves metadata without querying SQLite. It expires after six hours; the fixture sync refreshes it during season/upcoming updates, and the bot job refreshes it after its run. Updates use a file lock and atomic rename. PHP keeps the last valid cache if a refresh fails. Historical metadata remains available while current-season club lists exclude previous-season clubs.

The profile dropdown, club pages, standings, badges, sitemap and bots use this catalog. Feed payloads register previously unseen clubs and available badge metadata. Existing aliases and custom badge locations are database records; unknown badges display the football placeholder.

## Guest play

Visitors can predict without registering. The first save opens a dialog where they confirm a suggested display name or type their own; that commitment turns their browser token into a player (`users.guest_since` set, no email). Guest sign-ups are limited to 8 an hour and 30 a day per IP (`guest_signups`).

To keep drive-by players off the global table, a guest is only listed once they have predicted in two different matchweeks (Friday to Thursday) or added an email. Until then only the guest sees their own row, tagged "Only you". Players created before guest play are unaffected. Friends leagues still need an email.

## Daily owner summary

`bin/daily_summary.py` (installed in `/opt/predictioncomp/bin`, run by `predictioncomp-daily-summary.timer` at 08:00 UK time as www-data) emails the owner one summary of the previous UK day: new players (guest or registered, and whether guests are on the table yet), guests who came back, prediction activity and running totals. It replaces the old one-email-per-new-player alert. Quiet days send nothing. Each day is recorded in `sync_meta` (`daily_summary:<date>`) so it is never sent twice. Use `--dry-run` to preview.

## Look and feel

`assets/theme.css` is the shared visual layer, loaded last on every page so it overrides older inline styles. Fonts (Barlow, Barlow Condensed; SIL OFL) are self-hosted in `assets/fonts`.

## Deployment

- PHP and static files: `/var/www/predictioncomp.com/public_html`.
- Python jobs: `/opt/predictioncomp/bin`.
- SQLite database: `/var/lib/predictioncomp/predictioncomp.sqlite`.
- Systemd units/timers: `systemd/`.
- `schema.sql` contains schema only, without user data or fixed club names.

Create the cache directory writable by the application identity:

```sh
sudo install -d -o www-data -g www-data -m 2775 /var/cache/predictioncomp
sudo -u www-data python3 -c "import sys; sys.path.insert(0, '/opt/predictioncomp/bin'); from club_catalog import load_catalog; load_catalog(force=True)"
```

Both job units permit writes to the database and cache directories. Reload systemd after changing units. Back up the database before applying migrations. Existing installations must preserve their club metadata and alias records, not replace the database with an empty schema.

## Fixtures and season rollover

The sync selects the active season at runtime. API-Football is the primary source; a validated published Premier League season schedule and the season-specific football-data.co.uk results CSV provide fallbacks. The fixed next-round fixture seed was removed. Source failures retain stored fixtures.

The official published article URL is stored in `sync_meta` under `published_fixture_source:<season>`. For a new season, the sync attempts to discover its article from the official news listing. Operators can configure `PREDICTIONCOMP_PUBLISHED_URL`, with placeholders `{season}`, `{next_season}` and `{season_code}`, if discovery is unavailable. No previous-season article is reused for a different season.

Secrets remain outside the repository: `/etc/predictioncomp/quiz-agent.env` and `/etc/predictioncomp/google-oauth.json`.

## Validation

PHP lint and Python compilation passed. Isolated database tests verified six-hour cache expiry, shared PHP/Python warm-cache reads without database queries, aliases, badges and next-season membership. The live homepage, club list, standings and sitemap returned HTTP 200, and all 20 current-season badge redirects passed.
