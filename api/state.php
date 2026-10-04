<?php

require_once __DIR__ . '/../bootstrap.php';

use Rugby\Auth;
use Rugby\Http;
use Rugby\MatchRepository;
use Rugby\Role;

Auth::requireApi(Role::Viewer);

$matchId = (int) ($_GET['match_id'] ?? 0);
if ($matchId <= 0) {
    Http::errorResponse(__('err.match_id_missing'));
}

$repository = new MatchRepository();
$match = $repository->find($matchId);

if ($match === null) {
    Http::errorResponse(__('err.match_not_found'), 404);
}

Http::jsonResponse($repository->toPayload($match));
