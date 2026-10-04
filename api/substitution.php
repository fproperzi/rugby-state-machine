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
$playerOff = isset($input['player_off']) && $input['player_off'] !== '' ? (int) $input['player_off'] : null;
$playerOn = isset($input['player_on']) && $input['player_on'] !== '' ? (int) $input['player_on'] : null;

if ($matchId <= 0 || !in_array($team, ['home', 'away'], true)) {
    Http::errorResponse(__('err.invalid_params'));
}

$repository = new MatchRepository();
$match = $repository->recordSubstitution($matchId, $team, $playerOff, $playerOn);

Http::jsonResponse($repository->toPayload($match));
