<?php

use Rugby\MatchRepository;

$matchId = (int) ($_GET['match'] ?? 0);

$repository = new MatchRepository();
$match = $repository->find($matchId);

if ($match === null) {
    http_response_code(404);
    _e('err.match_not_found');
    return;
}
?>
<!doctype html>
<html lang="<?= \Rugby\I18n::current() ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Split — <?= htmlspecialchars($match['home_name']) ?> vs <?= htmlspecialchars($match['away_name']) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<!-- Video e tagger nella stessa pagina (niente iframe): il cronometro del tagger deve
     poter leggere la posizione del player e reagire a play/pausa. -->
<div class="split-layout">
    <div class="split-video">
        <!-- La sorgente si sceglie una volta sola, nella creazione della partita (setup.php). -->
        <div class="video-area" id="video-area"></div>
        <div class="video-status" id="video-status" hidden></div>
    </div>

    <div class="split-tagger">
        <?php require __DIR__ . '/partials/tagger.php'; ?>
    </div>
</div>

<?php require __DIR__ . '/partials/common-scripts.php'; ?>
<script src="assets/js/video.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
