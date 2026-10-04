<?php

/**
 * Accesso: POST {username, password, next?} -> {user, redirect}. Pubblica (e' la porta d'ingresso).
 */

require_once __DIR__ . '/../bootstrap.php';

use Rugby\Auth;
use Rugby\Http;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Http::errorResponse(__('err.method_not_allowed'), 405);
}

Auth::requireSameOriginJson();
$input = Http::jsonInput();

try {
    $user = Auth::login((string) ($input['username'] ?? ''), (string) ($input['password'] ?? ''));
} catch (InvalidArgumentException $e) {
    Http::errorResponse($e->getMessage(), 401);
}

Http::jsonResponse([
    'user' => ['username' => $user['username'], 'role' => $user['role']->value],
    'redirect' => Auth::safeNextUrl($input['next'] ?? null),
]);
