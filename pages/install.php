<!doctype html>
<html lang="<?= \Rugby\I18n::current() ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php _e('page.install'); ?> — Rugby Tagger</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<!-- Mostrata solo finche' non esiste nessun utente (vedi Auth::pageToRender). -->
<div class="screen menu-screen">
    <?php require __DIR__ . '/partials/lang-switch.php'; ?>
    <div class="menu-title">🏉 Rugby Tagger</div>
    <div class="menu-sub"><?php _e('page.install'); ?></div>
    <p class="field-hint"><?php _e('h.install'); ?></p>

    <form class="auth-form" id="install-form">
        <label for="username"><?php _e('l.username'); ?></label>
        <input type="text" id="username" name="username" autocomplete="username" autocapitalize="none" required autofocus>
        <div class="field-hint"><?php _e('h.username_rules'); ?></div>

        <label for="password"><?php _e('l.password'); ?></label>
        <input type="password" id="password" name="password" autocomplete="new-password" required minlength="<?= PASSWORD_MIN_LENGTH ?>">

        <label for="password2"><?php _e('l.password_repeat'); ?></label>
        <input type="password" id="password2" name="password2" autocomplete="new-password" required minlength="<?= PASSWORD_MIN_LENGTH ?>">
        <div class="field-hint"><?php _e('h.password_rules', ['min' => PASSWORD_MIN_LENGTH]); ?></div>

        <div class="form-error" id="form-error" hidden></div>
        <button type="submit" class="btn primary full"><?php _e('l.create_admin'); ?></button>
    </form>
</div>

<?php require __DIR__ . '/partials/common-scripts.php'; ?>
<script>
(function () {
    'use strict';

    const form = document.getElementById('install-form');
    const errorBox = document.getElementById('form-error');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        errorBox.hidden = true;

        if (form.password.value !== form.password2.value) {
            errorBox.textContent = t('passwords_differ');
            errorBox.hidden = false;
            return;
        }

        const res = await fetch('api/install.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username: form.username.value, password: form.password.value }),
        });
        const body = await res.json().catch(() => ({}));

        if (!res.ok) {
            errorBox.textContent = body.error || t('unknown_error');
            errorBox.hidden = false;
            return;
        }

        window.location.href = body.redirect;
    });
})();
</script>
</body>
</html>
