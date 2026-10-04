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
$op = (string) ($input['op'] ?? 'start');
$team = (string) ($input['team'] ?? '');

if ($matchId <= 0) {
    Http::errorResponse(__('err.match_id_missing'));
}

$repository = new MatchRepository();

if ($op === 'start') {
    if (!in_array($team, ['home', 'away'], true)) {
        Http::errorResponse(__('err.invalid_team'));
    }
    $match = $repository->startAdvantage($matchId, $team);
} elseif ($op === 'stop') {
    $match = $repository->stopAdvantage($matchId);
} else {
    Http::errorResponse(__('err.invalid_operation', ['op' => $op]));
}

Http::jsonResponse($repository->toPayload($match));
