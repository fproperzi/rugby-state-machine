<?php

/**
 * Primo avvio: POST {username, password} crea il primo amministratore e lo collega.
 * Funziona solo finche' non esiste nessun utente (controllo atomico in UserRepository).
 */

require_once __DIR__ . '/../bootstrap.php';

use Rugby\Auth;
use Rugby\Http;
use Rugby\UserRepository;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Http::errorResponse(__('err.method_not_allowed'), 405);
}

Auth::requireSameOriginJson();
$input = Http::jsonInput();
$username = (string) ($input['username'] ?? '');
$password = (string) ($input['password'] ?? '');

try {
    (new UserRepository())->createFirstAdmin($username, $password);
} catch (InvalidArgumentException $e) {
    Http::errorResponse($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Http::errorResponse($e->getMessage(), 409);
}

Auth::login($username, $password);

Http::jsonResponse(['redirect' => 'index.php']);
