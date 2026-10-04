<?php

use Rugby\Auth;

$user = Auth::user();
?>
<!doctype html>
<html lang="<?= \Rugby\I18n::current() ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php _e('page.account'); ?> — Rugby Tagger</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="screen menu-screen">
    <?php require __DIR__ . '/partials/user-bar.php'; ?>
    <a class="back-link" href="index.php">← <?php _e('l.menu'); ?></a>
    <div class="menu-title"><?php _e('page.account'); ?></div>
    <div class="menu-sub"><?= htmlspecialchars($user['username']) ?> · <?php _e('l.role_' . $user['role']->value); ?></div>

    <form class="auth-form" id="password-form">
        <h3><?php _e('l.change_password'); ?></h3>

        <label for="current_password"><?php _e('l.current_password'); ?></label>
        <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>

        <label for="new_password"><?php _e('l.new_password'); ?></label>
        <input type="password" id="new_password" name="new_password" autocomplete="new-password" required minlength="<?= PASSWORD_MIN_LENGTH ?>">

        <label for="new_password2"><?php _e('l.password_repeat'); ?></label>
        <input type="password" id="new_password2" name="new_password2" autocomplete="new-password" required minlength="<?= PASSWORD_MIN_LENGTH ?>">
        <div class="field-hint"><?php _e('h.password_rules', ['min' => PASSWORD_MIN_LENGTH]); ?> <?php _e('h.password_change_sessions'); ?></div>

        <div class="form-error" id="form-error" hidden></div>
        <div class="archive-status" id="form-ok" hidden></div>
        <button type="submit" class="btn primary full"><?php _e('l.save'); ?></button>
    </form>
</div>

<?php require __DIR__ . '/partials/common-scripts.php'; ?>
<script>
(function () {
    'use strict';

    const form = document.getElementById('password-form');
    const errorBox = document.getElementById('form-error');
    const okBox = document.getElementById('form-ok');

    function showError(message) {
        errorBox.textContent = message;
        errorBox.hidden = false;
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        errorBox.hidden = true;
        okBox.hidden = true;

        if (form.new_password.value !== form.new_password2.value) {
            showError(t('passwords_differ'));
            return;
        }

        const res = await fetch('api/account.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                current_password: form.current_password.value,
                new_password: form.new_password.value,
            }),
        });
        const body = await res.json().catch(() => ({}));

        if (!res.ok) {
            showError(body.error || t('unknown_error'));
            return;
        }

        form.reset();
        okBox.textContent = t('password_changed');
        okBox.hidden = false;
    });
})();
</script>
</body>
</html>
