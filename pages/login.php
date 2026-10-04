<!doctype html>
<html lang="<?= \Rugby\I18n::current() ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php _e('page.login'); ?> — Rugby State Machine</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="screen menu-screen">
    <?php require __DIR__ . '/partials/lang-switch.php'; ?>
    <div class="menu-title">🏉 Rugby State Machine</div>
    <div class="menu-sub"><?php _e('page.login'); ?></div>

    <form class="auth-form" id="login-form">
        <label for="username"><?php _e('l.username'); ?></label>
        <input type="text" id="username" name="username" autocomplete="username" autocapitalize="none" required autofocus>

        <label for="password"><?php _e('l.password'); ?></label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>

        <div class="form-error" id="form-error" hidden></div>
        <button type="submit" class="btn primary full"><?php _e('l.login'); ?></button>
    </form>
</div>

<?php require __DIR__ . '/partials/common-scripts.php'; ?>
<script>
(function () {
    'use strict';

    const form = document.getElementById('login-form');
    const errorBox = document.getElementById('form-error');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        errorBox.hidden = true;

        const res = await fetch('api/login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                username: form.username.value,
                password: form.password.value,
                next: new URLSearchParams(window.location.search).get('next') || '',
            }),
        });
        const body = await res.json().catch(() => ({}));

        if (!res.ok) {
            errorBox.textContent = body.error || t('unknown_error');
            errorBox.hidden = false;
            form.password.value = '';
            form.password.focus();
            return;
        }

        window.location.href = body.redirect;
    });
})();
</script>
</body>
</html>
