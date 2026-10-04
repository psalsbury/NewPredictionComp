CREATE TABLE auth_magic_links(
 id INTEGER PRIMARY KEY,
 email TEXT NOT NULL,
 token_hash TEXT UNIQUE NOT NULL,
 originating_user_id INTEGER NOT NULL,
 expires_at TEXT NOT NULL,
 used_at TEXT,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE bot_profiles(
 user_id INTEGER PRIMARY KEY, personality TEXT NOT NULL UNIQUE,
 risk REAL NOT NULL, optimism REAL NOT NULL, home_bias REAL NOT NULL,
 draw_bias REAL NOT NULL, form_weight REAL NOT NULL, h2h_weight REAL NOT NULL,
 club_loyalty REAL NOT NULL, upset_bias REAL NOT NULL, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
, bio TEXT, title TEXT);

CREATE TABLE club_aliases(alias TEXT PRIMARY KEY,club_name TEXT NOT NULL REFERENCES clubs(name));

CREATE TABLE clubs(name TEXT PRIMARY KEY,badge TEXT);

CREATE TABLE fixtures(id INTEGER PRIMARY KEY, external_id INTEGER UNIQUE, round TEXT, kickoff_utc TEXT NOT NULL, home TEXT NOT NULL, away TEXT NOT NULL, home_score INTEGER, away_score INTEGER, status TEXT DEFAULT 'NS', updated_at TEXT DEFAULT CURRENT_TIMESTAMP);

CREATE TABLE league_members(league_id INTEGER NOT NULL,user_id INTEGER NOT NULL,joined_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(league_id,user_id));

CREATE TABLE leagues(id INTEGER PRIMARY KEY, code TEXT UNIQUE NOT NULL, name TEXT NOT NULL, owner_user_id INTEGER NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP);

CREATE TABLE password_login_attempts (key TEXT PRIMARY KEY, attempts INTEGER NOT NULL DEFAULT 0, window_start INTEGER NOT NULL);

CREATE TABLE prediction_reminder_log(user_id INTEGER NOT NULL,week_key TEXT NOT NULL,status TEXT NOT NULL,attempts INTEGER NOT NULL DEFAULT 0,attempted_at TEXT,sent_at TEXT,unsubscribe_hash TEXT,PRIMARY KEY(user_id,week_key),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);

CREATE TABLE predictions(id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, fixture_id INTEGER NOT NULL, home_score INTEGER NOT NULL, away_score INTEGER NOT NULL, points INTEGER, saved_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(user_id,fixture_id));

CREATE TABLE sync_meta(key TEXT PRIMARY KEY,value TEXT NOT NULL,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);

CREATE TABLE users(id INTEGER PRIMARY KEY, token TEXT UNIQUE NOT NULL, display_name TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, supported_club TEXT, email TEXT, google_sub TEXT, email_verified_at TEXT, avatar_url TEXT, is_bot INTEGER NOT NULL DEFAULT 0, bot_grade INTEGER, first_played_at TEXT, play_alert_sent_at TEXT, prediction_reminders INTEGER NOT NULL DEFAULT 0, reminder_lead_hours INTEGER NOT NULL DEFAULT 24, password_hash TEXT, onboarding_seen INTEGER NOT NULL DEFAULT 1);

CREATE INDEX auth_magic_links_email_created ON auth_magic_links(email,created_at);

CREATE UNIQUE INDEX reminder_unsubscribe_hash ON prediction_reminder_log(unsubscribe_hash);

CREATE UNIQUE INDEX users_email_unique ON users(email) WHERE email IS NOT NULL;

CREATE UNIQUE INDEX users_google_sub_unique ON users(google_sub) WHERE google_sub IS NOT NULL;