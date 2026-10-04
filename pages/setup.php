<!doctype html>
<html lang="<?= \Rugby\I18n::current() ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php _e('page.setup'); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="screen">
    <?php require __DIR__ . '/partials/lang-switch.php'; ?>
    <div class="live-header">
        <div class="team-half" style="background:#c0392b;">
            <div class="name"><?php _e('l.home'); ?></div>
        </div>
        <div class="team-half" style="background:#2854c7;">
            <div class="name"><?php _e('l.away'); ?></div>
        </div>
    </div>

    <form class="setup-form" id="setup-form">
        <div class="team-block" style="border-top-color:#c0392b;">
            <label><?php _e('l.home_team'); ?></label>
            <input type="text" name="home_name" placeholder="<?php _e('l.team_name'); ?>" required>
            <label><?php _e('l.colour'); ?></label>
            <input type="color" name="home_color" value="#c0392b">
        </div>

        <div class="team-block" style="border-top-color:#2854c7;">
            <label><?php _e('l.away_team'); ?></label>
            <input type="text" name="away_name" placeholder="<?php _e('l.team_name'); ?>" required>
            <label><?php _e('l.colour'); ?></label>
            <input type="color" name="away_color" value="#2854c7">
        </div>

        <div class="team-block">
            <label><?php _e('l.mode'); ?></label>
            <select name="mode" id="mode-select" class="setup-select">
                <option value="live"><?php _e('l.mode_live'); ?></option>
                <option value="split"><?php _e('l.mode_split'); ?></option>
            </select>
        </div>

        <div class="team-block" id="video-block" hidden>
            <label><?php _e('l.video_source'); ?></label>
            <div class="toggle-row" id="video-kind-toggle">
                <button type="button" data-value="remote" class="selected"><?php _e('l.video_remote'); ?></button>
                <button type="button" data-value="local"><?php _e('l.video_local'); ?></button>
            </div>

            <label for="video-url" class="spaced" id="video-url-label"><?php _e('js.video_remote_label'); ?></label>
            <input type="text" name="video_url" id="video-url" autocomplete="off">
            <div class="field-hint" id="video-hint"></div>
        </div>

        <div class="form-error" id="setup-error" hidden></div>

        <button type="submit" class="btn primary full"><?php _e('l.start_match'); ?></button>
    </form>
</div>

<?php require __DIR__ . '/partials/common-scripts.php'; ?>
<script>
(function () {
    'use strict';

    const VIDEO_KINDS = {
        remote: {
            label: t('video_remote_label'),
            placeholder: 'https://www.youtube.com/watch?v=…',
            hint: t('video_remote_hint'),
        },
        local: {
            label: t('video_local_label'),
            placeholder: t('video_local_placeholder'),
            hint: t('video_local_hint'),
        },
    };

    const form = document.getElementById('setup-form');
    const modeSelect = document.getElementById('mode-select');
    const videoBlock = document.getElementById('video-block');
    const videoInput = document.getElementById('video-url');
    const errorBox = document.getElementById('setup-error');
    const kindButtons = document.querySelectorAll('#video-kind-toggle button');

    function selectVideoKind(kind) {
        kindButtons.forEach((btn) => btn.classList.toggle('selected', btn.dataset.value === kind));
        document.getElementById('video-url-label').textContent = VIDEO_KINDS[kind].label;
        document.getElementById('video-hint').textContent = VIDEO_KINDS[kind].hint;
        videoInput.placeholder = VIDEO_KINDS[kind].placeholder;
    }

    function syncVideoBlock() {
        const isSplit = modeSelect.value === 'split';
        videoBlock.hidden = !isSplit;
        videoInput.required = isSplit;
        if (isSplit) {
            videoInput.focus();
        }
    }

    function showError(message) {
        errorBox.textContent = message;
        errorBox.hidden = false;
    }

    kindButtons.forEach((btn) => btn.addEventListener('click', () => {
        selectVideoKind(btn.dataset.value);
        videoInput.focus();
    }));
    modeSelect.addEventListener('change', syncVideoBlock);

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        errorBox.hidden = true;

        const data = new FormData(form);
        const mode = data.get('mode');
        const payload = {
            home_name: data.get('home_name'),
            home_color: data.get('home_color'),
            away_name: data.get('away_name'),
            away_color: data.get('away_color'),
            // Il tipo (link/file) lo ricava il server dal valore: il toggle serve solo a guidare l'utente.
            video_url: mode === 'split' ? data.get('video_url').trim() : '',
        };

        const res = await fetch('api/matches.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const body = await res.json().catch(() => ({}));

        if (!res.ok) {
            showError(body.error || t('create_failed'));
            return;
        }

        window.location.href = `index.php?page=${mode}&match=${body.id}`;
    });

    selectVideoKind('remote');
    syncVideoBlock();
})();
</script>
</body>
</html>
