<?php

require_once __DIR__ . '/../bootstrap.php';

use Rugby\Auth;
use Rugby\Http;
use Rugby\MatchRepository;
use Rugby\Role;
use Rugby\VideoSource;

// Elenco: chiunque sia collegato; creazione: almeno tagger.
$currentUser = Auth::requireApi($_SERVER['REQUEST_METHOD'] === 'POST' ? Role::Tagger : Role::Viewer);

$repository = new MatchRepository();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = Http::jsonInput();

    $homeName = trim((string) ($input['home_name'] ?? 'Home Team'));
    $awayName = trim((string) ($input['away_name'] ?? 'Away Team'));
    $homeColor = trim((string) ($input['home_color'] ?? '#e3242b'));
    $awayColor = trim((string) ($input['away_color'] ?? '#1f4fd6'));

    // Facoltativo: presente solo per la modalita' split (video + tagger).
    $videoUrl = VideoSource::normalize((string) ($input['video_url'] ?? ''));

    if ($homeName === '' || $awayName === '') {
        Http::errorResponse(__('err.team_names_required'));
    }

    // Validato prima di creare la partita, per non lasciare partite orfane se il video non va.
    if ($videoUrl !== '') {
        $error = VideoSource::validationError($videoUrl);
        if ($error !== null) {
            Http::errorResponse($error, 422);
        }
    }

    $matchId = $repository->create($homeName, $homeColor, $awayName, $awayColor, $currentUser['id']);

    if ($videoUrl !== '') {
        $repository->setVideoUrl($matchId, $videoUrl);
    }

    Http::jsonResponse(['id' => $matchId]);
}

// GET: elenco partite per il menu (Continue Match / View Stats)
Http::jsonResponse(['matches' => $repository->listAll()]);
