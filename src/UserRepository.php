<?php

namespace Rugby;

/**
 * Utenti dell'app: lettura e scrittura della tabella `users`, con le regole che proteggono
 * l'accesso amministrativo (deve sempre restare almeno un admin attivo).
 *
 * Le password arrivano qui in chiaro solo per essere verificate o trasformate in hash
 * (password_hash, algoritmo di default di PHP): non vengono mai salvate ne' restituite.
 */
class UserRepository
{
    /** Nomi utente: lettere, cifre e . _ - (niente spazi, cosi' non si confondono). */
    private const USERNAME_PATTERN = '/^[A-Za-z0-9._-]{3,40}$/';
    /** password_hash tronca oltre 72 byte con bcrypt: un limite esplicito evita sorprese. */
    private const PASSWORD_MAX_BYTES = 72;

    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function hasAny(): bool
    {
        return (bool) $this->db->query('SELECT EXISTS (SELECT 1 FROM users)')->fetchColumn();
    }

    public function find(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        return $stmt->fetch() ?: null;
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Elenco per la pagina di gestione utenti (senza hash delle password).
     */
    public function listAll(): array
    {
        return $this->db->query(
            'SELECT id, username, role, active, created_at, last_login_at FROM users ORDER BY username COLLATE NOCASE'
        )->fetchAll();
    }

    /**
     * Crea un utente.
     *
     * @return int id del nuovo utente
     * @throws \InvalidArgumentException se nome utente, password o ruolo non sono validi, o il nome e' gia' usato
     */
    public function create(string $username, string $password, Role $role): int
    {
        $username = trim($username);
        $this->assertValidUsername($username);
        $this->assertValidPassword($password);

        if ($this->findByUsername($username) !== null) {
            throw new \InvalidArgumentException(__('err.username_taken', ['username' => $username]));
        }

        $stmt = $this->db->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role->value]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Crea il primo amministratore, solo se non esiste ancora nessun utente. Il controllo e
     * l'inserimento stanno nella stessa transazione "immediate", cosi' due richieste simultanee
     * sulla pagina di primo avvio non possono creare due admin.
     *
     * @return int id dell'admin creato
     * @throws \RuntimeException se esiste gia' almeno un utente
     */
    public function createFirstAdmin(string $username, string $password): int
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if ($this->hasAny()) {
                throw new \RuntimeException(__('err.already_installed'));
            }
            $id = $this->create($username, $password, Role::Admin);
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }

        return $id;
    }

    /**
     * Cambia il ruolo e/o lo stato attivo di un utente, rispettando le regole di sicurezza:
     * un admin non puo' togliersi i permessi da solo e deve restare almeno un admin attivo.
     *
     * @param int $actingUserId utente che esegue la modifica
     * @throws \InvalidArgumentException se la modifica non e' permessa
     */
    public function update(int $userId, Role $role, bool $active, int $actingUserId): void
    {
        $user = $this->requireUser($userId);

        if ($userId === $actingUserId && ($role !== Role::Admin || !$active)) {
            throw new \InvalidArgumentException(__('err.cannot_demote_self'));
        }

        $losesAdmin = $user['role'] === Role::Admin->value && (bool) $user['active'] && ($role !== Role::Admin || !$active);
        if ($losesAdmin && $this->countActiveAdmins() <= 1) {
            throw new \InvalidArgumentException(__('err.last_admin'));
        }

        // Disattivare un utente chiude anche le sue sessioni aperte.
        $stmt = $this->db->prepare(
            'UPDATE users SET role = ?, active = ?,
             session_version = session_version + CASE WHEN ? = 0 AND active = 1 THEN 1 ELSE 0 END
             WHERE id = ?'
        );
        $stmt->execute([$role->value, $active ? 1 : 0, $active ? 1 : 0, $userId]);
    }

    /**
     * Imposta una nuova password e chiude tutte le sessioni aperte di quell'utente.
     *
     * @throws \InvalidArgumentException se la password non rispetta i requisiti
     */
    public function setPassword(int $userId, string $password): void
    {
        $this->requireUser($userId);
        $this->assertValidPassword($password);

        $stmt = $this->db->prepare(
            'UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?'
        );
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
    }

    /**
     * Elimina un utente. Le partite che aveva creato restano (created_by diventa NULL).
     *
     * @throws \InvalidArgumentException se si tenta di eliminare se stessi o l'ultimo admin
     */
    public function delete(int $userId, int $actingUserId): void
    {
        $user = $this->requireUser($userId);

        if ($userId === $actingUserId) {
            throw new \InvalidArgumentException(__('err.cannot_delete_self'));
        }

        if ($user['role'] === Role::Admin->value && (bool) $user['active'] && $this->countActiveAdmins() <= 1) {
            throw new \InvalidArgumentException(__('err.last_admin'));
        }

        $stmt = $this->db->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$userId]);
    }

    public function recordLogin(int $userId): void
    {
        $stmt = $this->db->prepare('UPDATE users SET last_login_at = datetime(\'now\') WHERE id = ?');
        $stmt->execute([$userId]);
    }

    /**
     * Aggiorna l'hash se PHP ora usa un algoritmo o un costo piu' robusto di quello salvato.
     */
    public function rehashIfNeeded(array $user, string $password): void
    {
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $stmt = $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
    }

    // ---------- Tentativi di accesso falliti ----------

    public function recordLoginFailure(string $username, string $ip): void
    {
        $stmt = $this->db->prepare('INSERT INTO login_failures (username, ip, failed_at) VALUES (?, ?, ?)');
        $stmt->execute([$username, $ip, time()]);
    }

    /**
     * Numero di tentativi falliti recenti per nome utente e per indirizzo IP.
     *
     * @param int $since timestamp Unix d'inizio della finestra
     * @return array{user: int, ip: int}
     */
    public function recentLoginFailures(string $username, string $ip, int $since): array
    {
        // I tentativi piu' vecchi della finestra non servono piu': si puliscono qui, senza job a parte.
        $this->db->prepare('DELETE FROM login_failures WHERE failed_at < ?')->execute([$since]);

        $byUser = $this->db->prepare('SELECT COUNT(*) FROM login_failures WHERE username = ? AND failed_at >= ?');
        $byUser->execute([$username, $since]);
        $byIp = $this->db->prepare('SELECT COUNT(*) FROM login_failures WHERE ip = ? AND failed_at >= ?');
        $byIp->execute([$ip, $since]);

        return ['user' => (int) $byUser->fetchColumn(), 'ip' => (int) $byIp->fetchColumn()];
    }

    public function clearLoginFailures(string $username): void
    {
        $this->db->prepare('DELETE FROM login_failures WHERE username = ?')->execute([$username]);
    }

    private function countActiveAdmins(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn();
    }

    private function requireUser(int $userId): array
    {
        $user = $this->find($userId);
        if ($user === null) {
            throw new \InvalidArgumentException(__('err.user_not_found'));
        }

        return $user;
    }

    private function assertValidUsername(string $username): void
    {
        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            throw new \InvalidArgumentException(__('err.username_invalid'));
        }
    }

    private function assertValidPassword(string $password): void
    {
        // Caratteri, non byte (accenti ed emoji contano uno); preg evita di dipendere da mbstring.
        if (preg_match_all('/./su', $password) < PASSWORD_MIN_LENGTH) {
            throw new \InvalidArgumentException(__('err.password_too_short', ['min' => PASSWORD_MIN_LENGTH]));
        }

        if (strlen($password) > self::PASSWORD_MAX_BYTES) {
            throw new \InvalidArgumentException(__('err.password_too_long', ['max' => self::PASSWORD_MAX_BYTES]));
        }
    }
}
