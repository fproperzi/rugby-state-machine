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
$op = (string) ($input['op'] ?? 'toggle');

if ($matchId <= 0) {
    Http::errorResponse(__('err.match_id_missing'));
}

$repository = new MatchRepository();

$match = match ($op) {
    'toggle' => $repository->toggleClock($matchId),
    'half_time' => $repository->halfTime($matchId),
    'full_time' => $repository->fullTime($matchId),
    'video_sync' => syncWithVideo($repository, $matchId, $input),
    default => Http::errorResponse(__('err.invalid_operation', ['op' => $op])),
};

Http::jsonResponse($repository->toPayload($match));

function syncWithVideo(MatchRepository $repository, int $matchId, array $input): array
{
    $playback = VideoSource::playbackFromInput($input);
    if ($playback === null) {
        Http::errorResponse(__('err.video_time_invalid'));
    }

    return $repository->syncVideoClock($matchId, $playback['time'], $playback['playing']);
}
