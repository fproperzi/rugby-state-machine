<?php

/**
 * Gestione utenti (solo admin).
 *   GET                                   -> {users: [...]}
 *   POST {op: "create", username, password, role}
 *   POST {op: "update", id, role, active}
 *   POST {op: "password", id, password}   (reset da parte dell'admin)
 *   POST {op: "delete", id}
 * Ogni POST risponde con l'elenco aggiornato.
 */

require_once __DIR__ . '/../bootstrap.php';

use Rugby\Auth;
use Rugby\Http;
use Rugby\Role;
use Rugby\UserRepository;

$admin = Auth::requireApi(Role::Admin);
$users = new UserRepository();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = Http::jsonInput();
    $op = (string) ($input['op'] ?? '');
    $userId = (int) ($input['id'] ?? 0);

    try {
        match ($op) {
            'create' => $users->create(
                (string) ($input['username'] ?? ''),
                (string) ($input['password'] ?? ''),
                roleFromInput($input)
            ),
            'update' => $users->update($userId, roleFromInput($input), (bool) ($input['active'] ?? false), $admin['id']),
            'password' => $users->setPassword($userId, (string) ($input['password'] ?? '')),
            'delete' => $users->delete($userId, $admin['id']),
            default => Http::errorResponse(__('err.invalid_operation', ['op' => $op])),
        };
    } catch (InvalidArgumentException $e) {
        Http::errorResponse($e->getMessage(), 422);
    }
}

Http::jsonResponse(['users' => $users->listAll(), 'me' => $admin['id']]);

function roleFromInput(array $input): Role
{
    $role = Role::tryFrom((string) ($input['role'] ?? ''));
    if ($role === null) {
        Http::errorResponse(__('err.invalid_role'), 422);
    }

    return $role;
}
