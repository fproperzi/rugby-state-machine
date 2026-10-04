<?php
/**
 * Selettore di lingua: ricarica la pagina corrente con ?lang=..., che I18n ricorda in un cookie.
 */
use Rugby\I18n;
?>
<nav class="lang-switch" aria-label="<?php _e('l.language'); ?>">
    <?php foreach (I18n::SUPPORTED as $lang): ?>
        <a href="<?= htmlspecialchars(I18n::switchUrl($lang)) ?>"
           class="<?= $lang === I18n::current() ? 'active' : '' ?>"
           lang="<?= $lang ?>"><?= strtoupper($lang) ?></a>
    <?php endforeach; ?>
</nav>
