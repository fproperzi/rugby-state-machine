<?php

namespace Rugby;

/**
 * Accesso all'app: sessione, login/logout e controllo dei permessi per pagine e API.
 *
 * Scelte di sicurezza:
 *  - sessione PHP con cookie HttpOnly, SameSite=Lax, Secure su HTTPS, use_strict_mode (niente id
 *    di sessione imposti dall'esterno) e id rigenerato al login (session fixation);
 *  - a ogni richiesta l'utente viene riletto dal DB: un utente disattivato, o a cui e' stata
 *    cambiata la password (session_version), perde subito le sessioni aperte;
 *  - le API che modificano dati accettano solo POST in JSON dallo stesso sito: un altro sito non
 *    puo' inviare JSON senza il permesso CORS, e con SameSite il cookie non partirebbe comunque
 *    (protezione CSRF senza token da distribuire al JavaScript);
 *  - tentativi falliti limitati per utente e per IP (config LOGIN_*), messaggio d'errore generico
 *    e verifica della password anche per utenti inesistenti, per non rivelare quali nomi esistono.
 */
class Auth
{
    private const SESSION_USER_ID = 'user_id';
    private const SESSION_VERSION = 'session_version';
    private const SESSION_LAST_SEEN = 'last_seen';

    /** Hash bcrypt di una stringa casuale: rende uguale il tempo di risposta per utenti inesistenti. */
    private const DUMMY_HASH = '$2y$10$l/N0bBKFcK6PQEHA9IO3gOyTqMPUR1UbaY1JXHTRsLZvrZzmo21eG';

    private static bool $resolved = false;
    private static ?array $user = null;

    /**
     * Avvia la sessione con parametri sicuri (idempotente).
     */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => self::isHttps(),
        ]);
        session_start();
    }

    /**
     * Utente collegato, o null. Il risultato e' calcolato una volta per richiesta.
     *
     * @return array{id: int, username: string, role: Role}|null
     */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        self::startSession();

        $userId = $_SESSION[self::SESSION_USER_ID] ?? null;
        if (!is_int($userId)) {
            return null;
        }

        $lastSeen = $_SESSION[self::SESSION_LAST_SEEN] ?? 0;
        $row = (new UserRepository())->find($userId);

        $valid = $row !== null
            && (bool) $row['active']
            && (int) $row['session_version'] === ($_SESSION[self::SESSION_VERSION] ?? null)
            && time() - $lastSeen <= SESSION_IDLE_SECONDS;

        if (!$valid) {
            self::destroySession();
            return null;
        }

        $_SESSION[self::SESSION_LAST_SEEN] = time();
        self::$user = ['id' => (int) $row['id'], 'username' => $row['username'], 'role' => Role::from($row['role'])];

        return self::$user;
    }

    /**
     * Verifica le credenziali e apre la sessione.
     *
     * @throws \InvalidArgumentException con un messaggio generico se l'accesso non e' possibile
     */
    public static function login(string $username, string $password): array
    {
        $username = trim($username);
        $ip = self::clientIp();
        $users = new UserRepository();

        $failures = $users->recentLoginFailures($username, $ip, time() - LOGIN_LOCK_WINDOW_SECONDS);
        if ($failures['user'] >= LOGIN_MAX_FAILURES_PER_USER || $failures['ip'] >= LOGIN_MAX_FAILURES_PER_IP) {
            error_log("Auth: login bloccato per '{$username}' da {$ip}");
            throw new \InvalidArgumentException(__('err.login_locked', ['minutes' => intdiv(LOGIN_LOCK_WINDOW_SECONDS, 60)]));
        }

        $row = $username === '' ? null : $users->findByUsername($username);
        $passwordOk = password_verify($password, $row['password_hash'] ?? self::DUMMY_HASH);

        if ($row === null || !$passwordOk || !(bool) $row['active']) {
            $users->recordLoginFailure($username, $ip);
            throw new \InvalidArgumentException(__('err.login_failed'));
        }

        $users->clearLoginFailures($username);
        $users->rehashIfNeeded($row, $password);
        $users->recordLogin((int) $row['id']);

        self::startSession();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_USER_ID] = (int) $row['id'];
        $_SESSION[self::SESSION_VERSION] = (int) $row['session_version'];
        $_SESSION[self::SESSION_LAST_SEEN] = time();

        self::$resolved = false;

        return self::user();
    }

    /**
     * Riallinea la sessione corrente dopo che l'utente ha cambiato la propria password
     * (session_version incrementata): le altre sessioni decadono, questa resta valida.
     */
    public static function refreshCurrentSession(): void
    {
        $user = self::user();
        if ($user === null) {
            return;
        }

        $row = (new UserRepository())->find($user['id']);
        session_regenerate_id(true);
        $_SESSION[self::SESSION_VERSION] = (int) $row['session_version'];
    }

    public static function logout(): void
    {
        self::startSession();
        self::destroySession();
    }

    /**
     * Guardia delle API: utente collegato con almeno il ruolo richiesto. Per le richieste che
     * modificano dati verifica anche che siano JSON dallo stesso sito (vedi intestazione della classe).
     * In caso negativo risponde direttamente con 401/403/400 e termina.
     *
     * @param Role $required ruolo minimo
     * @return array{id: int, username: string, role: Role} utente collegato
     */
    public static function requireApi(Role $required): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            self::requireSameOriginJson();
        }

        $user = self::user();
        if ($user === null) {
            Http::errorResponse(__('err.not_logged_in'), 401);
        }

        if (!$user['role']->allows($required)) {
            Http::errorResponse(__('err.forbidden'), 403);
        }

        // Da qui la sessione serve solo in lettura: liberarla evita che le richieste parallele
        // dello stesso utente (es. streaming video + tagging) si mettano in coda sul lock del file.
        session_write_close();

        return $user;
    }

    /**
     * Guardia delle API pubbliche che modificano dati (login, primo avvio): stesso controllo
     * di origine di requireApi, senza richiedere un utente.
     */
    public static function requireSameOriginJson(): void
    {
        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        if (!str_starts_with($contentType, 'application/json')) {
            Http::errorResponse(__('err.bad_request'), 400);
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin !== null && $origin !== self::ownOrigin()) {
            error_log("Auth: richiesta da origine non ammessa {$origin}");
            Http::errorResponse(__('err.forbidden'), 403);
        }
    }

    /**
     * Decide quale pagina mostrare in base allo stato dell'installazione e dell'utente,
     * reindirizzando dove serve (login, menu). Chiamata da index.php prima di ogni output.
     *
     * @param string $requested pagina richiesta (?page=)
     * @param array<string, Role> $pageRoles ruolo minimo di ogni pagina protetta
     * @return string pagina da includere
     */
    public static function pageToRender(string $requested, array $pageRoles): string
    {
        if (!(new UserRepository())->hasAny()) {
            return 'install';
        }

        if ($requested === 'install') {
            self::redirect('index.php');
        }

        $user = self::user();
        if ($user === null) {
            if ($requested === 'login') {
                return 'login';
            }
            self::redirect('index.php?page=login&next=' . rawurlencode(http_build_query($_GET)));
        }

        session_write_close();

        if ($requested === 'login' || !isset($pageRoles[$requested])) {
            self::redirect('index.php');
        }

        if (!$user['role']->allows($pageRoles[$requested])) {
            self::redirect('index.php');
        }

        return $requested;
    }

    /**
     * Destinazione dopo il login. $next contiene solo la query string della pagina richiesta:
     * l'URL viene ricostruito da zero su index.php, quindi non puo' puntare a un altro sito.
     *
     * @param string|null $next query string (es. "page=live&match=3")
     * @return string URL relativo dentro l'app
     */
    public static function safeNextUrl(?string $next): string
    {
        parse_str((string) $next, $query);
        $query = array_filter($query, fn ($value, $key) => is_string($value) && $key !== 'lang', ARRAY_FILTER_USE_BOTH);
        unset($query['next']);

        if (($query['page'] ?? 'login') === 'login') {
            return 'index.php';
        }

        return 'index.php?' . http_build_query($query);
    }

    private static function redirect(string $url): never
    {
        header('Location: ' . $url, true, 302);
        exit;
    }

    private static function destroySession(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            session_destroy();
        }
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    private static function ownOrigin(): string
    {
        return (self::isHttps() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
    }

    private static function clientIp(): string
    {
        // Solo REMOTE_ADDR: X-Forwarded-For e' falsificabile e renderebbe inutile il limite per IP.
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}
