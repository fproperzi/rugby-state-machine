<?php

/**
 * Router per il server PHP integrato (php -S localhost:8000 router.php): il server integrato
 * ignora .htaccess, quindi qui si replicano le stesse regole. Pubblici restano solo
 * index.php, api/*.php e assets/*; tutto il resto (DB, sorgenti, pagine interne, dotfile) e' 404.
 */

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$root = __DIR__;

/**
 * True se il percorso richiesto puo' essere servito.
 */
function isPublicPath(string $path, string $root): bool
{
    // Niente byte nulli o risalite: il percorso reale deve restare dentro la root del progetto.
    if (str_contains($path, "\0") || str_contains($path, '..')) {
        return false;
    }

    // Nessun segmento nascosto (.git, .htaccess, .env, ...).
    foreach (explode('/', trim($path, '/')) as $segment) {
        if ($segment !== '' && $segment[0] === '.') {
            return false;
        }
    }

    if ($path === '/' || $path === '/index.php') {
        return true;
    }

    $real = realpath($root . $path);
    if ($real === false || !str_starts_with($real, realpath($root) . DIRECTORY_SEPARATOR)) {
        return false;
    }

    return (bool) preg_match('~^/(api/[\w-]+\.php|assets/[\w./-]+)$~', $path);
}

if (!isPublicPath($path, $root)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    return true;
}

// Percorso pubblico: lo serve il server integrato (file statico o script PHP).
return false;
