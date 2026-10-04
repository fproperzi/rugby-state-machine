<?php

use Rugby\Auth;
use Rugby\MatchRepository;
use Rugby\Role;

$repository = new MatchRepository();
$matches = $repository->listAll();
// I viewer vedono elenco e statistiche, ma non creano, taggano o importano partite.
$canTag = Auth::user()['role']->allows(Role::Tagger);
?>
<!doctype html>
<html lang="<?= \Rugby\I18n::current() ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rugby State Machine</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="screen menu-screen">
    <?php require __DIR__ . '/partials/user-bar.php'; ?>
    <div class="menu-title">🏉 Rugby State Machine</div>
    <div class="menu-sub"><?php _e('l.app_tagline'); ?></div>

    <?php if ($canTag): ?>
        <a class="menu-card" href="index.php?page=setup">
            <h3><?php _e('l.new_match'); ?></h3>
            <p><?php _e('h.new_match'); ?></p>
        </a>
    <?php endif; ?>

    <!-- Sempre visibile, anche senza partite: serve per importarne. -->
    <div class="menu-card" style="padding-bottom:10px;">
        <h3><?php _e('l.matches'); ?></h3>
        <p><?php _e('h.matches'); ?></p>

        <div class="archive-bar">
            <?php if (!empty($matches)): ?>
                <button type="button" class="btn small" id="btn-select-all"><?php _e('js.select_all'); ?></button>
                <button type="button" class="btn small primary" id="btn-export" disabled><?php _e('js.export'); ?></button>
            <?php endif; ?>
            <?php if ($canTag): ?>
                <label class="btn small">
                    <?php _e('l.import'); ?>
                    <input type="file" id="import-file" accept=".json,application/json" hidden>
                </label>
            <?php endif; ?>
        </div>
        <div class="archive-status" id="archive-status" hidden></div>

        <div class="match-list">
            <?php foreach ($matches as $match): ?>
                <?php
                    $target = match (true) {
                        !$canTag, $match['status'] === 'full_time' => 'stats',
                        !empty($match['video_url']) => 'split',
                        default => 'live',
                    };
                ?>
                <div class="match-row">
                    <input type="checkbox" class="match-select" value="<?= (int) $match['id'] ?>"
                           aria-label="<?php _e('l.select_match', ['match' => $match['home_name'] . ' vs ' . $match['away_name']]); ?>">
                    <a href="index.php?page=<?= $target ?>&match=<?= (int) $match['id'] ?>">
                        <span><?= htmlspecialchars($match['home_name']) ?> vs <?= htmlspecialchars($match['away_name']) ?></span>
                        <span class="score"><?= (int) $match['home_score'] ?> - <?= (int) $match['away_score'] ?></span>
                    </a>
                    <?php if ($match['created_by_name'] !== null): ?>
                        <small class="created-by" title="<?php _e('l.created_by'); ?>"><?= htmlspecialchars($match['created_by_name']) ?></small>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <footer class="app-version">Rugby State Machine v<?= htmlspecialchars(APP_VERSION) ?> · GPL-3.0-or-later</footer>
</div>

<?php require __DIR__ . '/partials/common-scripts.php'; ?>
<script>
(function () {
    'use strict';

    const checkboxes = [...document.querySelectorAll('.match-select')];
    const exportBtn = document.getElementById('btn-export');
    const selectAllBtn = document.getElementById('btn-select-all');
    const statusEl = document.getElementById('archive-status');

    function selectedIds() {
        return checkboxes.filter((cb) => cb.checked).map((cb) => cb.value);
    }

    function refreshExportButton() {
        if (!exportBtn) return;
        const count = selectedIds().length;
        exportBtn.disabled = count === 0;
        exportBtn.textContent = count > 0 ? t('export_count', { count }) : t('export');
        selectAllBtn.textContent = t(count === checkboxes.length ? 'deselect_all' : 'select_all');
    }

    function showStatus(message, isError) {
        statusEl.textContent = message;
        statusEl.classList.toggle('error', Boolean(isError));
        statusEl.hidden = false;
    }

    checkboxes.forEach((cb) => cb.addEventListener('change', refreshExportButton));

    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', () => {
            const selectAll = selectedIds().length !== checkboxes.length;
            checkboxes.forEach((cb) => { cb.checked = selectAll; });
            refreshExportButton();
        });
    }

    if (exportBtn) {
        // Download diretto: l'endpoint risponde con Content-Disposition: attachment.
        exportBtn.addEventListener('click', () => {
            window.location.href = 'api/export.php?ids=' + selectedIds().join(',');
        });
    }

    const importInput = document.getElementById('import-file');
    importInput && importInput.addEventListener('change', async (e) => {
        const file = e.target.files[0];
        e.target.value = '';
        if (!file) return;

        let archive;
        try {
            archive = JSON.parse(await file.text());
        } catch (err) {
            showStatus(t('import_not_json', { file: file.name }), true);
            return;
        }

        const res = await fetch('api/import.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(archive),
        });
        const body = await res.json().catch(() => ({}));

        if (!res.ok) {
            showStatus(t('import_failed', { error: body.error || t('unknown_error') }), true);
            return;
        }

        showStatus(t('import_done', { count: body.imported.length }), false);
        setTimeout(() => window.location.reload(), 1200);
    });

    refreshExportButton();
})();
</script>
</body>
</html>
