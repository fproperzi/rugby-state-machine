-- Schema SQLite per il tagger live di rugby.
-- Una riga in `matches` per partita, una riga in `events` per ogni azione taggata:
-- lo storico eventi e' la fonte di verita' per punteggio, statistiche e scrubber temporale.

CREATE TABLE IF NOT EXISTS matches (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    home_name       TEXT NOT NULL DEFAULT 'Home Team',
    home_color      TEXT NOT NULL DEFAULT '#e3242b',
    away_name       TEXT NOT NULL DEFAULT 'Away Team',
    away_color      TEXT NOT NULL DEFAULT '#1f4fd6',
    status          TEXT NOT NULL DEFAULT 'setup' CHECK (status IN ('setup', 'live', 'half_time', 'full_time')),
    home_score      INTEGER NOT NULL DEFAULT 0,
    away_score      INTEGER NOT NULL DEFAULT 0,
    possession      TEXT CHECK (possession IN ('home', 'away')),
    current_state   TEXT NOT NULL DEFAULT 'kickoff_choice',
    -- dati temporanei dello stato corrente (es. causa penalty gia' selezionata), libero JSON
    state_context   TEXT NOT NULL DEFAULT '{}',
    match_seconds   INTEGER NOT NULL DEFAULT 0,
    clock_running   INTEGER NOT NULL DEFAULT 0,
    clock_started_at TEXT,
    half            INTEGER NOT NULL DEFAULT 1,
    video_url       TEXT,
    -- secondi video meno secondi di gioco, fissato al calcio d'inizio di ogni tempo:
    -- in modalita' split il cronometro segue il video (match_seconds = tempo video - video_offset)
    video_offset    REAL,
    -- utente che ha creato la partita (NULL per le partite precedenti alla gestione utenti)
    created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS events (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id        INTEGER NOT NULL REFERENCES matches(id) ON DELETE CASCADE,
    match_seconds   INTEGER NOT NULL,
    kind            TEXT NOT NULL CHECK (kind IN ('state', 'card', 'substitution', 'clock')),
    state_before    TEXT,
    state_after     TEXT,
    action_id       TEXT,
    team            TEXT CHECK (team IN ('home', 'away')),
    label           TEXT NOT NULL,
    points          INTEGER NOT NULL DEFAULT 0,
    meta            TEXT NOT NULL DEFAULT '{}',
    -- snapshot del contesto di stato prima/dopo, per poter annullare l'ultima azione senza ricalcolare tutto
    context_before  TEXT NOT NULL DEFAULT '{}',
    context_after   TEXT NOT NULL DEFAULT '{}',
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_events_match ON events(match_id, id);

-- Utenti dell'app. Ruoli: admin (gestisce gli utenti), tagger (crea e tagga partite), viewer (sola lettura).
CREATE TABLE IF NOT EXISTS users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    username        TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash   TEXT NOT NULL,
    role            TEXT NOT NULL CHECK (role IN ('admin', 'tagger', 'viewer')),
    active          INTEGER NOT NULL DEFAULT 1,
    -- incrementato a ogni cambio/reset password o disattivazione: invalida le sessioni gia' aperte
    session_version INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    last_login_at   TEXT
);

-- Tentativi di accesso falliti, per bloccare i tentativi a forza bruta (vedi Auth::login).
CREATE TABLE IF NOT EXISTS login_failures (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    username        TEXT NOT NULL COLLATE NOCASE,
    ip              TEXT NOT NULL,
    failed_at       INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_login_failures_user ON login_failures(username, failed_at);
CREATE INDEX IF NOT EXISTS idx_login_failures_ip ON login_failures(ip, failed_at);
