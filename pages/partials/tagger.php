<?php
/**
 * Tagger live (punteggio, cronometro, azioni, modali), condiviso da live.php e split.php.
 * Richiede nello scope $match (riga della partita) e $matchId; il comportamento e' in assets/js/app.js.
 */

/**
 * Selettore di squadra dei modali (cartellino, sostituzione, vantaggio): nome e colore della
 * squadra, nello stesso ordine sinistra/destra dell'intestazione (anche dopo lo scambio, via CSS).
 */
$renderTeamToggle = function (string $toggleId) use ($match): void {
    echo '<div class="toggle-row team-toggle" id="' . $toggleId . '">';
    foreach (['home', 'away'] as $side) {
        printf(
            '<button type="button" data-value="%s" style="--team-color: %s;">%s</button>',
            $side,
            htmlspecialchars($match["{$side}_color"]),
            htmlspecialchars($match["{$side}_name"])
        );
    }
    echo '</div>';
};
?>
<div class="screen" id="app" data-match-id="<?= $matchId ?>"
     style="--home-color: <?= htmlspecialchars($match['home_color']) ?>; --away-color: <?= htmlspecialchars($match['away_color']) ?>;">

    <div class="live-header">
        <div class="team-half" style="background: <?= htmlspecialchars($match['home_color']) ?>;">
            <div class="name" id="home-name"><?= htmlspecialchars($match['home_name']) ?></div>
            <div class="score" id="home-score">0</div>
        </div>
        <button type="button" class="swap-sides" id="btn-swap" title="<?php _e('l.swap_sides'); ?>" aria-label="<?php _e('l.swap_sides'); ?>">&#8644;</button>
        <div class="team-half" style="background: <?= htmlspecialchars($match['away_color']) ?>;">
            <div class="name" id="away-name"><?= htmlspecialchars($match['away_name']) ?></div>
            <div class="score" id="away-score">0</div>
        </div>
    </div>

    <div class="toolbar">
        <button id="btn-phase"><?php _e('js.half_time'); ?></button>
        <button id="btn-timeoff"><?php _e('js.time_off'); ?></button>
        <button id="btn-showstats"><?php _e('l.show_stats'); ?> ▲</button>
    </div>

    <div class="side-actions">
        <button id="btn-card">🟨 <?php _e('l.card'); ?></button>
        <button id="btn-sub">🔁 <?php _e('l.sub'); ?></button>
        <button id="btn-advantage">⏱ <?php _e('l.advantage'); ?></button>
        <span style="flex:1; text-align:center; align-self:center; color:var(--text-dim); font-size:13px;" id="clock-readout">00:00</span>
    </div>

    <div class="advantage-banner" id="advantage-banner" hidden></div>

    <div class="state-panel">
        <div class="state-title" id="state-title"><?php _e('l.loading'); ?></div>

        <div class="goal-pitch-wrap" id="goal-pitch-wrap" hidden>
            <div class="hint"><?php _e('h.kick_position'); ?></div>
            <div class="goal-pitch" id="goal-pitch">
                <div class="posts"></div>
            </div>
        </div>

        <div class="action-grid" id="action-grid"></div>
    </div>

    <div class="undo-bar">
        <button class="undo-btn" id="btn-undo" disabled><?php _e('js.undo'); ?></button>
    </div>
</div>

<div class="modal-overlay" id="modal-card" hidden>
    <div class="modal-box">
        <h3><?php _e('l.card_title'); ?></h3>

        <label><?php _e('l.team'); ?></label>
        <?php $renderTeamToggle('card-team-toggle'); ?>

        <label><?php _e('l.colour'); ?></label>
        <div class="toggle-row" id="card-colour-toggle">
            <button type="button" data-value="yellow">🟨 <?php _e('l.yellow'); ?></button>
            <button type="button" data-value="red">🟥 <?php _e('l.red'); ?></button>
        </div>

        <label><?php _e('l.shirt_number'); ?></label>
        <input type="number" id="card-player" min="1" max="99">

        <div class="modal-actions">
            <button class="btn" id="card-cancel"><?php _e('l.cancel'); ?></button>
            <button class="btn primary" id="card-confirm"><?php _e('l.confirm'); ?></button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="modal-sub" hidden>
    <div class="modal-box">
        <h3><?php _e('l.sub_title'); ?></h3>

        <label><?php _e('l.team'); ?></label>
        <?php $renderTeamToggle('sub-team-toggle'); ?>

        <label><?php _e('l.player_off'); ?></label>
        <input type="number" id="sub-player-off" min="1" max="99">

        <label><?php _e('l.player_on'); ?></label>
        <input type="number" id="sub-player-on" min="1" max="99">

        <div class="modal-actions">
            <button class="btn" id="sub-cancel"><?php _e('l.cancel'); ?></button>
            <button class="btn primary" id="sub-confirm"><?php _e('l.confirm'); ?></button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="modal-advantage" hidden>
    <div class="modal-box">
        <h3><?php _e('l.advantage_title'); ?></h3>

        <?php $renderTeamToggle('advantage-team-toggle'); ?>

        <div class="modal-actions">
            <button class="btn" id="advantage-cancel"><?php _e('l.cancel'); ?></button>
            <button class="btn primary" id="advantage-confirm"><?php _e('l.start'); ?></button>
        </div>
    </div>
</div>
