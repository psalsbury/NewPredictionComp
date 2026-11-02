-- Stage 2 (2026-11-02): joker (one match per gameweek, double points) and awards.
--   sudo -u www-data sqlite3 /var/lib/predictioncomp/predictioncomp.sqlite < 2026-11-02-stage2.sql
PRAGMA busy_timeout=30000;
BEGIN;
ALTER TABLE predictions ADD COLUMN joker INTEGER NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS player_awards(user_id INTEGER NOT NULL REFERENCES users(id),award TEXT NOT NULL,period TEXT NOT NULL,
 label TEXT NOT NULL,detail TEXT NOT NULL DEFAULT '',fixture_id INTEGER,awarded_at TEXT DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,award,period));
CREATE INDEX IF NOT EXISTS player_awards_period ON player_awards(period);
COMMIT;
