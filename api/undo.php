<?php

require_once __DIR__ . '/../bootstrap.php';

use Rugby\Auth;
use Rugby\Http;
use Rugby\MatchRepository;
use Rugby\Role;

Auth::requireApi(Role::Tagger);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Http::errorResponse(__('err.method_not_allowed'), 405);
}

$input = Http::jsonInput();
$matchId = (int) ($input['match_id'] ?? 0);

if ($matchId <= 0) {
    Http::errorResponse(__('err.match_id_missing'));
}

$repository = new MatchRepository();
$match = $repository->undoLast($matchId);

Http::jsonResponse($repository->toPayload($match));
