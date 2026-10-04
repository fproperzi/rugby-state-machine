<?php

/**
 * Recupero d'emergenza di un account (da riga di comando, sul server):
 *
 *   php tools/reset-password.php <nome-utente>
 *
 * Imposta una password temporanea casuale e la stampa, chiude le sessioni aperte dell'utente,
 * cancella il blocco per tentativi falliti e, se l'account era disattivato, lo riattiva.
 * Serve quando l'unico amministratore ha dimenticato la password: dal sito nessuno potrebbe
 * reimpostarla. Non e' raggiungibile via web (tools/ e' bloccata da .htaccess e router.php).
 */

require __DIR__ . '/../bootstrap.php';

use Rugby\Role;
use Rugby\UserRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// 18 byte casuali in base64 "sicura per URL": 24 caratteri, ben oltre PASSWORD_MIN_LENGTH.
const TEMPORARY_PASSWORD_BYTES = 18;
const SYSTEM_OPERATOR_ID = 0;

$username = $argv[1] ?? '';
if ($username === '') {
    fwrite(STDERR, "Uso: php tools/reset-password.php <nome-utente>\n");
    exit(1);
}

$users = new UserRepository();
$user = $users->findByUsername($username);
if ($user === null) {
    fwrite(STDERR, "Utente '{$username}' non trovato.\n");
    exit(1);
}

$password = rtrim(strtr(base64_encode(random_bytes(TEMPORARY_PASSWORD_BYTES)), '+/', '-_'), '=');
$users->setPassword((int) $user['id'], $password);
$users->clearLoginFailures($user['username']);

if (!(bool) $user['active']) {
    // Chi agisce e' l'operatore del server, non un utente: id 0 non corrisponde a nessun account,
    // cosi' le regole "non puoi modificare te stesso" di UserRepository::update non si applicano.
    $users->update((int) $user['id'], Role::from($user['role']), true, SYSTEM_OPERATOR_ID);
}

echo "Password temporanea per '{$user['username']}': {$password}\n";
echo "Accedi e cambiala subito da Account. Le sessioni aperte di questo utente sono state chiuse.\n";
