<?php

use Rugby\Role;
?>
<!doctype html>
<html lang="<?= \Rugby\I18n::current() ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php _e('page.users'); ?> — Rugby Tagger</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="screen menu-screen users-screen">
    <?php require __DIR__ . '/partials/user-bar.php'; ?>
    <a class="back-link" href="index.php">← <?php _e('l.menu'); ?></a>
    <div class="menu-title"><?php _e('page.users'); ?></div>
    <p class="field-hint"><?php _e('h.roles'); ?></p>

    <div class="form-error" id="users-error" hidden></div>
    <div class="archive-status" id="users-ok" hidden></div>

    <div class="user-list" id="user-list"></div>

    <form class="auth-form" id="create-form">
        <h3><?php _e('l.new_user'); ?></h3>

        <label for="new-username"><?php _e('l.username'); ?></label>
        <input type="text" id="new-username" name="username" autocomplete="off" autocapitalize="none" required>
        <div class="field-hint"><?php _e('h.username_rules'); ?></div>

        <label for="new-password"><?php _e('l.temporary_password'); ?></label>
        <input type="password" id="new-password" name="password" autocomplete="new-password" required minlength="<?= PASSWORD_MIN_LENGTH ?>">
        <div class="field-hint"><?php _e('h.password_rules', ['min' => PASSWORD_MIN_LENGTH]); ?> <?php _e('h.temporary_password'); ?></div>

        <label for="new-role"><?php _e('l.role'); ?></label>
        <select id="new-role" name="role" class="setup-select">
            <?php foreach ([Role::Tagger, Role::Viewer, Role::Admin] as $role): ?>
                <option value="<?= $role->value ?>"><?php _e('l.role_' . $role->value); ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn primary full"><?php _e('l.create_user'); ?></button>
    </form>
</div>

<?php require __DIR__ . '/partials/common-scripts.php'; ?>
<script>
(function () {
    'use strict';

    const ROLES = ['admin', 'tagger', 'viewer'];
    const listEl = document.getElementById('user-list');
    const errorBox = document.getElementById('users-error');
    const okBox = document.getElementById('users-ok');

    function showResult(message, isError) {
        errorBox.hidden = !isError;
        okBox.hidden = isError;
        (isError ? errorBox : okBox).textContent = message;
    }

    /** POST all'API utenti: aggiorna l'elenco se va a buon fine, mostra l'errore altrimenti. */
    async function send(payload, successMessage) {
        const res = await fetch('api/users.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const body = await res.json().catch(() => ({}));

        if (!res.ok) {
            showResult(body.error || t('unknown_error'), true);
            return false;
        }

        render(body);
        showResult(successMessage, false);
        return true;
    }

    function button(label, className, onClick) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn small ' + className;
        btn.textContent = label;
        btn.addEventListener('click', onClick);
        return btn;
    }

    function renderUser(user, isMe) {
        const row = document.createElement('div');
        row.className = 'user-row' + (Number(user.active) ? '' : ' inactive');

        const name = document.createElement('div');
        name.className = 'user-name';
        name.textContent = user.username + (isMe ? ' (' + t('you') + ')' : '');
        const meta = document.createElement('small');
        meta.textContent = user.last_login_at ? t('last_login', { date: user.last_login_at }) : t('never_logged_in');
        name.appendChild(meta);

        // L'admin non puo' cambiare ruolo o disattivare se stesso: lo impedisce anche il server.
        const role = document.createElement('select');
        role.className = 'setup-select';
        role.disabled = isMe;
        ROLES.forEach((value) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = t('role_' + value);
            option.selected = value === user.role;
            role.appendChild(option);
        });

        const activeLabel = document.createElement('label');
        activeLabel.className = 'user-active';
        const active = document.createElement('input');
        active.type = 'checkbox';
        active.checked = Boolean(Number(user.active));
        active.disabled = isMe;
        activeLabel.append(active, ' ' + t('active'));

        const update = () => send(
            { op: 'update', id: user.id, role: role.value, active: active.checked },
            t('user_updated', { user: user.username })
        );
        role.addEventListener('change', update);
        active.addEventListener('change', update);

        const actions = document.createElement('div');
        actions.className = 'user-actions';

        // Reimpostazione password: campo in linea invece di un prompt() (che mostrerebbe la password in chiaro).
        const resetBox = document.createElement('div');
        resetBox.className = 'user-reset';
        resetBox.hidden = true;
        const resetInput = document.createElement('input');
        resetInput.type = 'password';
        resetInput.autocomplete = 'new-password';
        resetInput.placeholder = t('temporary_password');
        resetBox.append(resetInput, button(t('save'), 'primary', async () => {
            if (await send({ op: 'password', id: user.id, password: resetInput.value }, t('password_reset_done', { user: user.username }))) {
                resetBox.hidden = true;
            }
        }));

        actions.appendChild(button(t('reset_password'), '', () => {
            resetBox.hidden = !resetBox.hidden;
            resetInput.focus();
        }));

        if (!isMe) {
            actions.appendChild(button(t('delete'), 'danger', () => {
                if (confirm(t('confirm_delete_user', { user: user.username }))) {
                    send({ op: 'delete', id: user.id }, t('user_deleted', { user: user.username }));
                }
            }));
        }

        row.append(name, role, activeLabel, actions, resetBox);
        return row;
    }

    function render(data) {
        listEl.innerHTML = '';
        data.users.forEach((user) => listEl.appendChild(renderUser(user, user.id === data.me)));
    }

    document.getElementById('create-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const created = await send(
            { op: 'create', username: form.username.value, password: form.password.value, role: form.role.value },
            t('user_created', { user: form.username.value })
        );
        if (created) {
            form.reset();
        }
    });

    fetch('api/users.php').then((res) => res.json()).then(render);
})();
</script>
</body>
</html>
