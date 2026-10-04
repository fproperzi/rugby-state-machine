<?php
/**
 * Configurazione dell'applicazione. Nessuna credenziale qui dentro:
 * il backend e' SQLite su file locale, non serve autenticazione DB.
 */

define('APP_ROOT', __DIR__);

// Versione dell'app (Semantic Versioning: MAJOR.MINOR.PATCH). Unica fonte del numero di versione:
// la procedura di rilascio e' descritta nel README, le modifiche di ogni versione in CHANGELOG.md.
define('APP_VERSION', '1.0.0');
define('DB_PATH', APP_ROOT . '/data/rugby.sqlite');
define('SCHEMA_PATH', APP_ROOT . '/schema.sql');

// Durata di un tempo in secondi, usata solo come riferimento per la UI (non blocca il cronometro).
define('HALF_DURATION_SECONDS', 40 * 60);

// Punteggi standard rugby union.
define('POINTS_TRY', 5);
define('POINTS_CONVERSION', 2);
define('POINTS_PENALTY_GOAL', 3);
define('POINTS_DROP_GOAL', 3);
// Law 8.1/8.3: a penalty try is worth 7 points and no conversion is attempted.
define('POINTS_PENALTY_TRY', 7);

// ---------- Accesso e sessioni ----------

// Nome del cookie di sessione (diverso dal PHPSESSID di default, per non mescolarsi con altre app sullo stesso dominio).
define('SESSION_NAME', 'rugby_sid');
// Dopo quanto tempo di inattivita' la sessione scade: piu' lungo di una partita, tempi supplementari compresi.
define('SESSION_IDLE_SECONDS', 8 * 3600);
// Lunghezza minima delle password (NIST SP 800-63B: lunghezza piu' che complessita').
define('PASSWORD_MIN_LENGTH', 10);
// Blocco dei tentativi di accesso: oltre questi errori nella finestra di tempo il login si rifiuta.
define('LOGIN_MAX_FAILURES_PER_USER', 5);
define('LOGIN_MAX_FAILURES_PER_IP', 20);
define('LOGIN_LOCK_WINDOW_SECONDS', 15 * 60);
