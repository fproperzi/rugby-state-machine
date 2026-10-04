<?php

/**
 * Cambio della propria password: POST {current_password, new_password}.
 * Le altre sessioni dell'utente vengono chiuse; quella corrente resta aperta.
 */

require_once __DIR__ . '/../bootstrap.php';

use Rugby\Auth;
use Rugby\Http;
use Rugby\Role;
use Rugby\UserRepository;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Http::errorResponse(__('err.method_not_allowed'), 405);
}

Auth::requireSameOriginJson();
$user = Auth::user();
if ($user === null) {
    Http::errorResponse(__('err.not_logged_in'), 401);
}

$input = Http::jsonInput();
$users = new UserRepository();
$row = $users->find($user['id']);

// Serve la password attuale: una sessione lasciata aperta non basta per impossessarsi dell'account.
if (!password_verify((string) ($input['current_password'] ?? ''), $row['password_hash'])) {
    Http::errorResponse(__('err.current_password_wrong'), 422);
}

try {
    $users->setPassword($user['id'], (string) ($input['new_password'] ?? ''));
} catch (InvalidArgumentException $e) {
    Http::errorResponse($e->getMessage(), 422);
}

Auth::refreshCurrentSession();

Http::jsonResponse(['ok' => true]);
