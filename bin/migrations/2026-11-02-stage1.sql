-- Stage 1 (code review follow-up, 2026-11-02). Run once, as www-data, before deploying the matching code:
--   sudo -u www-data sqlite3 /var/lib/predictioncomp/predictioncomp.sqlite < 2026-11-02-stage1.sql
PRAGMA busy_timeout=30000;
BEGIN;
-- Per-device logins (inc/session.php)
CREATE TABLE IF NOT EXISTS user_sessions(id INTEGER PRIMARY KEY,token_hash TEXT UNIQUE NOT NULL,user_id INTEGER NOT NULL REFERENCES users(id),
 created_at TEXT DEFAULT CURRENT_TIMESTAMP,last_seen_at TEXT,user_agent TEXT);
CREATE INDEX IF NOT EXISTS user_sessions_user ON user_sessions(user_id);
-- Stored "on the global table" flag (inc/listing.php). Same rule as before: not a guest, has an email,
-- or has predicted in two different matchweeks (Friday to Thursday).
ALTER TABLE users ADD COLUMN listed_at TEXT;
UPDATE users SET listed_at=COALESCE(created_at,CURRENT_TIMESTAMP) WHERE guest_since IS NULL OR email IS NOT NULL
 OR (SELECT COUNT(DISTINCT CAST((julianday(gf.kickoff_utc)-2451550.5)/7 AS INTEGER)) FROM predictions gp JOIN fixtures gf ON gf.id=gp.fixture_id WHERE gp.user_id=users.id)>=2;
CREATE TRIGGER IF NOT EXISTS users_listed_on_insert AFTER INSERT ON users
 WHEN NEW.listed_at IS NULL AND (NEW.guest_since IS NULL OR NEW.email IS NOT NULL)
 BEGIN UPDATE users SET listed_at=CURRENT_TIMESTAMP WHERE id=NEW.id; END;
CREATE TRIGGER IF NOT EXISTS users_listed_on_update AFTER UPDATE OF email,guest_since ON users
 WHEN NEW.listed_at IS NULL AND (NEW.guest_since IS NULL OR NEW.email IS NOT NULL)
 BEGIN UPDATE users SET listed_at=CURRENT_TIMESTAMP WHERE id=NEW.id; END;
CREATE TRIGGER IF NOT EXISTS predictions_listed_on_insert AFTER INSERT ON predictions
 WHEN (SELECT listed_at FROM users WHERE id=NEW.user_id) IS NULL
 BEGIN UPDATE users SET listed_at=CURRENT_TIMESTAMP WHERE id=NEW.user_id
  AND (SELECT COUNT(DISTINCT CAST((julianday(gf.kickoff_utc)-2451550.5)/7 AS INTEGER)) FROM predictions gp JOIN fixtures gf ON gf.id=gp.fixture_id WHERE gp.user_id=NEW.user_id)>=2; END;
COMMIT;
