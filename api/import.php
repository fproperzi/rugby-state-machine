<?php

/**
 * Importa le partite di un file creato da api/export.php: POST con il contenuto JSON del file.
 * Risponde con gli id delle partite create; se il file non e' valido non importa nulla.
 */

require_once __DIR__ . '/../bootstrap.php';

use Rugby\Auth;
use Rugby\Http;
use Rugby\MatchArchive;
use Rugby\Role;

$currentUser = Auth::requireApi(Role::Tagger);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Http::errorResponse(__('err.method_not_allowed'), 405);
}

$archive = Http::jsonInput();
if ($archive === []) {
    Http::errorResponse(__('err.import_empty'));
}

try {
    $ids = (new MatchArchive())->import($archive, $currentUser['id']);
} catch (InvalidArgumentException $e) {
    Http::errorResponse($e->getMessage(), 422);
}

Http::jsonResponse(['imported' => $ids]);
