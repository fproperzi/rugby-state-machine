<?php

require_once __DIR__ . '/config.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'Rugby\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = APP_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Testo tradotto (vedi Rugby\I18n::t). Da usare nel codice PHP e per costruire stringhe.
 *
 * @param string $key chiave del dizionario
 * @param array $params valori dei segnaposto {nome}
 * @return string testo nella lingua corrente
 */
function __(string $key, array $params = []): string
{
    return \Rugby\I18n::t($key, $params);
}

/**
 * Stampa il testo tradotto gia' escapato per l'HTML: e' la forma da usare nei template.
 *
 * @param string $key chiave del dizionario
 * @param array $params valori dei segnaposto {nome}
 */
function _e(string $key, array $params = []): void
{
    echo htmlspecialchars(\Rugby\I18n::t($key, $params), ENT_QUOTES);
}
