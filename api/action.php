<?php

require_once __DIR__ . '/../bootstrap.php';

use Rugby\Auth;
use Rugby\Http;
use Rugby\MatchRepository;
use Rugby\Role;
use Rugby\VideoSource;

Auth::requireApi(Role::Tagger);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Http::errorResponse(__('err.method_not_allowed'), 405);
}

$input = Http::jsonInput();
$matchId = (int) ($input['match_id'] ?? 0);
$actionId = (string) ($input['action_id'] ?? '');

if ($matchId <= 0 || $actionId === '') {
    Http::errorResponse(__('err.action_params_missing'));
}

// Posizione del calcio sul campo (facoltativa), inviata dal widget "tap to mark" del frontend.
$extraMeta = [];
if (isset($input['x'], $input['y'])) {
    $extraMeta['x'] = round((float) $input['x'], 1);
    $extraMeta['y'] = round((float) $input['y'], 1);
}

$repository = new MatchRepository();

// In modalita' split il tagger manda anche la posizione del video. Si sincronizza due volte:
// prima, perche' l'evento venga marcato col tempo del video; dopo, perche' se l'azione e' un
// calcio d'inizio la partita e' appena passata a 'live' ed e' il momento di ancorare il video.
$playback = VideoSource::playbackFromInput($input);

try {
    if ($playback !== null) {
        $repository->syncVideoClock($matchId, $playback['time'], $playback['playing']);
    }

    $match = $repository->applyAction($matchId, $actionId, $extraMeta);

    if ($playback !== null) {
        $match = $repository->syncVideoClock($matchId, $playback['time'], $playback['playing']);
    }
} catch (InvalidArgumentException $e) {
    Http::errorResponse($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Http::errorResponse($e->getMessage(), 404);
}

Http::jsonResponse($repository->toPayload($match));
