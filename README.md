# PredictionComp

Premier League prediction game deployed at https://predictioncomp.com/.

## Club names and cache

Team names are data, not lists embedded in code. SQLite `clubs` and `club_aliases` store display names, source aliases and badge locations. Season membership comes from recorded fixtures, using July-to-June seasons. Existing metadata was migrated into these tables without changing fixture IDs or predictions.

PHP `clubs.php` and Python `bin/club_catalog.py` share `/var/cache/predictioncomp/clubs.json`. A valid cache serves metadata without querying SQLite. It expires after six hours; the fixture sync refreshes it during season/upcoming updates, and the bot job refreshes it after its run. Updates use a file lock and atomic rename. PHP keeps the last valid cache if a refresh fails. Historical metadata remains available while current-season club lists exclude previous-season clubs.

The profile dropdown, club pages, standings, badges, sitemap and bots use this catalog. Feed payloads register previously unseen clubs and available badge metadata. Existing aliases and custom badge locations are database records; unknown badges display the football placeholder.

## Guest play

Visitors can predict without registering. The first save opens a dialog where they confirm a suggested display name or type their own; that commitment turns their browser token into a player (`users.guest_since` set, no email). Guest sign-ups are limited to 8 an hour and 30 a day per IP (`guest_signups`).

To keep drive-by players off the global table, a guest is only listed once they have predicted in two different matchweeks (Friday to Thursday) or added an email. Until then only the guest sees their own row, tagged "Only you". Players created before guest play are unaffected. Guests can create and join friends leagues; managing bots in a league needs an email.

## Prediction reminders

`bin/prediction_reminders.py` (run by `predictioncomp-reminders.timer` every 15 minutes as www-data) emails players who opted in to reminders and have missing picks, 2 or 24 hours before their next missing fixture locks. It sends at most one email per player per matchweek, logged in `prediction_reminder_log` with a hashed one-click unsubscribe token (`reminder-settings.php`). Use `--dry-run` to list who would be emailed.

## Daily owner summary

`bin/daily_summary.py` (installed in `/opt/predictioncomp/bin`, run by `predictioncomp-daily-summary.timer` at 08:00 UK time as www-data) emails the owner one summary of the previous UK day: new players (guest or registered, and whether guests are on the table yet), guests who came back, prediction activity and running totals. It replaces the old one-email-per-new-player alert. Quiet days send nothing. Each day is recorded in `sync_meta` (`daily_summary:<date>`) so it is never sent twice. Use `--dry-run` to preview.

## Look and feel

`assets/theme.css` is the shared visual layer, loaded last on every page so it overrides older inline styles. Fonts (Barlow, Barlow Condensed; SIL OFL) are self-hosted in `assets/fonts`. Browsers cache CSS and JS for a week (`.htaccess`), so bump the `?v=` number on `theme.css` links in `index.php`, `public.php` and `info.php` after editing it.

## Search (SEO)

- Match, club and weekly guide pages use plain-English form summaries and key numbers (`seo-content.php`). Finished weeks get a "Week in review" built from game data: hardest and easiest calls, exact scores and top scorers (bots are labelled).
- Match titles target "X v Y prediction & preview" before kick-off and "X 2–1 Y: result & prediction review" afterwards.
- Every page has Open Graph and Twitter tags with `assets/og-image.png`. The homepage carries WebSite, Organization and WebApplication structured data, and match pages carry SportsEvent.
- `/sitemap.xml` is served by `sitemap.php` (rewrite in `.htaccess`; there is no static file). It gives each URL a `lastmod` from fixture sync times (`info.php` mtime for static pages). `/p.html` (an old static homepage copy) 301-redirects to `/`.

## Code layout

`index.php` is a short front controller for the homepage and the JSON API (`/?api=<action>`). It loads, in order:

- `inc/config.php`: database and club-cache paths. `PREDICTIONCOMP_DB` and `PREDICTIONCOMP_CLUB_CACHE` override them, so the site can run against a test database (`php -S` with a small router that mirrors `.htaccess`).
- `inc/home-bootstrap.php`: session, the current player from the `pc_token` cookie, account merging and invite helpers. `inc/listing.php` holds `pc_listed_sql()`, the rule for who appears on the global table, shared with `public.php`. `inc/league-share.php` holds the league invite URL and share wording; the homepage passes them to its JS as `PC.leagueShare` / `PC.inviteBase`, and `assets/share.js` (`copyText`) is the copy-to-clipboard helper for both pages.
- `inc/home-auth.php`: invite links and sign-in routes (Google, email links, sign-out).
- `inc/home-api.php`: the JSON API.
- `inc/home-data.php`: queries for the homepage.
- `templates/home.php`: homepage markup. Styles are in `assets/home.css` (before `theme.css`) and `assets/home-late.css` (after it); behaviour is in `assets/home.js` and `assets/home-guest.js`. Per-page values (CSRF token, matchweek, picks, leagues) reach the scripts as `window.PC`. Bump the `?v=` on these links after editing them.

`inc/` and `templates/` are denied to the web by their own `.htaccess`. `player-page.php` (`/my-results`, `/leagues`, `/account`) renders the homepage and reshapes one dialog into a page, so it picks up homepage changes automatically.

Google's sign-in script is loaded on demand (when the sign-in dialog opens, or on the standalone sign-in page), not on every visit.

## Bots as content

`bot-insights.php` (used by `public.php`, styled by `assets/public-extras.css`):

- **What the bots predict** on match pages: the result split from all bots. Before kick-off the most-picked scores come from SIM2–SIM5 only, and SIM1 shows just its lean on the result; SIM1's exact scores appear once the match locks, so nobody can copy the strongest bots. After full time, once every prediction has its points, it shows average points by SIM level and the real-player average (3+ predictions).
- **Bot consensus** line on each weekly guide card.
- **Can you beat SIM1?** on weekly guides and `/table` (latest week with a finished match): the best SIM1 bot's points against real players, over finished matches whose points have been written. Postponed, cancelled and abandoned matches don't stop a week counting as complete. Guests who aren't on the global table yet are counted but not named.

Week pages use `pcWeekFixtures()` (in `seo-content.php`) for the Friday-to-Thursday UK week, and the season start comes from `pcSeasonYear()` in `clubs.php`.

The bot section of "Player prediction insights" was folded into the new panel; that block now covers real players only.

## Friends leagues

- After a first save, the homepage offers to create a league on the spot and share it (WhatsApp, copy link, native share). Players who are already in a league get its share links instead.
- League names are checked as 2–80 characters (not bytes), so accents and emoji count once.
- Invite links stay `/?invite=CODE`. Following one, or signing in with an invite pending, lands on the predictions (`/?joined_league=1&league=ID#predict`) with the league table alongside, instead of on `/leagues`.
- `/league/CODE` is a read-only league page for anyone with the code (`noindex,nofollow`, not in the sitemap): table, this week's pick progress (counts only until kick-off), picks for matches that have kicked off, and this week against SIM1.

## Homepage for first-time visitors

A visitor on a plain `/` (no query string) sees the next three open matches and a "Show all N upcoming matches" button (N is the number of open matches on that page). Days with no visible matches get an `extraday` class from the server, so this works without CSS `:has()`. The full list opens as soon as they open the score picker. Deep links (`?fixture=`, `?invite=`, paging) always show everything.

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


### Distinct predictions across official gameweeks

Bot uniqueness compares the ordered predictions for **every fixture in the same official Premier League gameweek**, including completed and live fixtures. Matching scores on individual matches are allowed. Membership comes from the Premier League's official fixture feed (`fantasy.premierleague.com/api/fixtures/`, `event`), matched to stored fixtures by season and teams and cached in `fixture_gameweeks` for six hours. It does not use a Friday-to-Monday date window or a truncated list of upcoming matches. The prediction progress summary uses that same membership, including midweek or rescheduled games assigned to the gameweek by the official feed.

On reruns, saved predictions are retained, and only future unlocked bot picks can be adjusted to keep full gameweek entries distinct. Locked predictions and human choices are never modified to enforce uniqueness. Bots avoid copying complete human gameweek entries too. If locked history makes uniqueness impossible, preserve that history and log the exception instead of failing the entire scheduled job. Completed fixture seeding only fills missing bot predictions; it no longer overwrites existing picks.
