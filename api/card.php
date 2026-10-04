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
$team = (string) ($input['team'] ?? '');
$colour = (string) ($input['colour'] ?? '');
$playerNumber = isset($input['player_number']) && $input['player_number'] !== '' ? (int) $input['player_number'] : null;

if ($matchId <= 0 || !in_array($team, ['home', 'away'], true) || !in_array($colour, ['yellow', 'red'], true)) {
    Http::errorResponse(__('err.invalid_params'));
}

$repository = new MatchRepository();
$match = $repository->recordCard($matchId, $team, $colour, $playerNumber);

Http::jsonResponse($repository->toPayload($match));
