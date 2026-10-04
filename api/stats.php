<?php

require_once __DIR__ . '/../bootstrap.php';

use Rugby\Auth;
use Rugby\EventRepository;
use Rugby\Http;
use Rugby\MatchRepository;
use Rugby\Role;
use Rugby\StatsCalculator;

Auth::requireApi(Role::Viewer);

$matchId = (int) ($_GET['match_id'] ?? 0);
if ($matchId <= 0) {
    Http::errorResponse(__('err.match_id_missing'));
}

$matchRepository = new MatchRepository();
$match = $matchRepository->find($matchId);
if ($match === null) {
    Http::errorResponse(__('err.match_not_found'), 404);
}

$eventRepository = new EventRepository();
$stateEvents = $eventRepository->forMatch($matchId, 'state');
$totalSeconds = $matchRepository->currentMatchSeconds($match);

$calculator = new StatsCalculator();

Http::jsonResponse([
    'match' => $matchRepository->toPayload($match),
    'stats' => $calculator->compute($stateEvents, $totalSeconds),
    'timeline' => $eventRepository->timeline($matchId),
]);
