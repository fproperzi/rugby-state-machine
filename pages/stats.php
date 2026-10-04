<?php

use Rugby\Auth;
use Rugby\MatchRepository;
use Rugby\Role;

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
    <title><?php _e('page.stats'); ?> — <?= htmlspecialchars($match['home_name']) ?> vs <?= htmlspecialchars($match['away_name']) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="screen stats-screen" id="app" data-match-id="<?= $matchId ?>">
    <div class="stats-header" style="background: linear-gradient(90deg, <?= htmlspecialchars($match['home_color']) ?> 0 50%, <?= htmlspecialchars($match['away_color']) ?> 50% 100%);">
        <div class="name" id="home-name"><?= htmlspecialchars($match['home_name']) ?></div>
        <div class="score" id="home-score">0</div>
        <div class="score" id="away-score">0</div>
        <div class="name" id="away-name" style="text-align:right;"><?= htmlspecialchars($match['away_name']) ?></div>
    </div>

    <div class="stats-tabs">
        <?php
            // Chi non puo' taggare torna al menu, non al tagger (che non potrebbe aprire).
            $backUrl = match (true) {
                !Auth::user()['role']->allows(Role::Tagger) => 'index.php',
                empty($match['video_url']) => 'index.php?page=live&match=' . $matchId,
                default => 'index.php?page=split&match=' . $matchId,
            };
        ?>
        <a href="<?= $backUrl ?>"><?php _e('l.back'); ?></a>
        <a class="active" href="#"><?php _e('l.overview'); ?></a>
        <a href="index.php?page=menu"><?php _e('l.menu'); ?></a>
    </div>

    <div class="stats-section">
        <h3><?php _e('l.possession'); ?></h3>
        <div class="split-bar" id="possession-bar"></div>
    </div>

    <div class="stats-section">
        <h3><?php _e('l.scores'); ?></h3>
        <div class="score-columns">
            <div class="col" id="scores-home"></div>
            <div class="col" id="scores-away"></div>
        </div>
    </div>

    <div class="stats-section">
        <h3><?php _e('l.kicks_at_goal'); ?></h3>
        <div class="stat-row"><div class="label"><span><?= htmlspecialchars($match['home_name']) ?></span><span id="kag-home-text"></span></div><div class="split-bar" id="kag-home-bar"></div></div>
        <div class="stat-row"><div class="label"><span><?= htmlspecialchars($match['away_name']) ?></span><span id="kag-away-text"></span></div><div class="split-bar" id="kag-away-bar"></div></div>
        <div class="score-columns" style="margin-top:12px;">
            <div class="col"><div class="goal-pitch readonly" id="kag-home-pitch"><div class="posts"></div></div></div>
            <div class="col"><div class="goal-pitch readonly" id="kag-away-pitch"><div class="posts"></div></div></div>
        </div>
    </div>

    <div class="stats-section">
        <h3><?php _e('l.breakdown'); ?></h3>
        <div id="breakdown-rows"></div>
    </div>

    <div class="scrubber">
        <input type="range" id="scrub" min="0" max="0" value="0">
        <div class="readout"><span id="scrub-time">00:00</span><span id="scrub-score"></span></div>
        <div class="event-label" id="scrub-event"></div>
    </div>
</div>

<?php require __DIR__ . '/partials/common-scripts.php'; ?>
<script src="assets/js/stats.js"></script>
</body>
</html>
