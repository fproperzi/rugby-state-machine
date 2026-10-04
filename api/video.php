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
$videoUrl = VideoSource::normalize((string) ($input['video_url'] ?? ''));

if ($matchId <= 0) {
    Http::errorResponse(__('err.match_id_missing'));
}

// Stringa vuota = togli il video; altrimenti dev'essere un link o un file utilizzabile.
if ($videoUrl !== '') {
    $error = VideoSource::validationError($videoUrl);
    if ($error !== null) {
        Http::errorResponse($error, 422);
    }
}

$repository = new MatchRepository();
if ($repository->find($matchId) === null) {
    Http::errorResponse(__('err.match_not_found'), 404);
}

$repository->setVideoUrl($matchId, $videoUrl);

Http::jsonResponse($repository->toPayload($repository->find($matchId)));
