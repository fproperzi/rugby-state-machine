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
    <title><?= htmlspecialchars($match['home_name']) ?> vs <?= htmlspecialchars($match['away_name']) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php require __DIR__ . '/partials/tagger.php'; ?>

<?php require __DIR__ . '/partials/common-scripts.php'; ?>
<script src="assets/js/app.js"></script>
</body>
</html>
