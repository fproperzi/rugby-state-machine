<?php
/**
 * Barra in cima alle pagine di servizio: utente collegato, link ad account e gestione utenti
 * (solo admin), uscita, selettore di lingua.
 */
use Rugby\Auth;
use Rugby\Role;

$barUser = Auth::user();
?>
<div class="user-bar">
    <?php if ($barUser !== null): ?>
        <span class="who">👤 <?= htmlspecialchars($barUser['username']) ?>
            <small>(<?php _e('l.role_' . $barUser['role']->value); ?>)</small></span>
        <a href="index.php?page=account"><?php _e('l.account'); ?></a>
        <?php if ($barUser['role']->allows(Role::Admin)): ?>
            <a href="index.php?page=users"><?php _e('l.users'); ?></a>
        <?php endif; ?>
        <a href="#" data-logout><?php _e('l.logout'); ?></a>
    <?php endif; ?>
    <?php require __DIR__ . '/lang-switch.php'; ?>
</div>
