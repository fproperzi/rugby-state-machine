<?php
/**
 * Script comuni a tutte le pagine, da includere prima di quelli della pagina:
 * stringhe tradotte per il JavaScript (voci 'js.' del dizionario) con la funzione t(),
 * e gestione della sessione (ritorno al login su 401, pulsante "Esci").
 */
?>
<script>window.I18N = <?= json_encode(\Rugby\I18n::clientStrings(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="assets/js/i18n.js"></script>
<script src="assets/js/session.js"></script>
