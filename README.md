# PredictionComp

Live Premier League score prediction game at https://predictioncomp.com/. This repository tracks the deployed PHP site, the fixture and bot jobs, and the SQLite schema. The live database and credentials are intentionally excluded.

## Layout

- Root PHP, SVG, robots, sitemap and .htaccess files: Apache document root at `/var/www/predictioncomp.com/public_html`.
- `bin/sync_competition.py`: fixtures, final scores and points; API-Football plus a published next-round fallback and football-data.co.uk results fallback.
- `bin/simulated_players.py`: 50 graded bots (10 per SIM level), personalities and predictions for real fixtures.
- `systemd/`: production units and timers.
- `schema.sql`: schema of the live SQLite database, without user data.

The PHP app expects `/var/lib/predictioncomp/predictioncomp.sqlite`. The fixture jobs use `/etc/predictioncomp/quiz-agent.env` for `API_FOOTBALL_KEY`; OAuth settings are read from `/etc/predictioncomp/google-oauth.json`. These files must stay outside the document root and repository.

## Current fixture source

API-Football's free plan does not provide 2026/27 fixtures. The fixture sync detects API errors rather than marking an empty response as a successful refresh. Round 6 (10–12 October 2026) is seeded from the [Premier League's published fixture list](https://www.premierleague.com/en/news/4675097/all-380-fixtures-for-202627-premier-league-season), with UK times converted to UTC in storage. The published schedule may change; review the official fixture list before each subsequent round. A real API fixture replaces a matching seeded fixture without discarding predictions. Completed scores also come from the current season football-data.co.uk CSV when the API is unavailable.

## Operational checks

```sh
php -l index.php
python3 -m py_compile bin/*.py
sudo systemctl start predictioncomp-sync.service
sudo systemctl start predictioncomp-simulated-players.service
```

Back up the SQLite database before a production migration. Keep live personal data, tokens and API credentials out of Git.
