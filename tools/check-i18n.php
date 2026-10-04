<?php

/**
 * Controllo dei dizionari (da riga di comando): php tools/check-i18n.php
 *
 * Verifica che:
 *  - ogni chiave usata nel codice (_e/__ in PHP, t() in JS) esista in tutti i dizionari;
 *  - i dizionari abbiano tutti le stesse chiavi;
 *  - ogni titolo/etichetta del grafo (States.php) abbia la traduzione in ogni lingua diversa dall'inglese.
 * Esce con codice 1 se trova problemi, cosi' si puo' usare anche in CI.
 */

require __DIR__ . '/../bootstrap.php';

use Rugby\I18n;
use Rugby\States;

$root = dirname(__DIR__);
$problems = [];

$dictionaries = [];
foreach (I18n::SUPPORTED as $lang) {
    $dictionaries[$lang] = require "{$root}/lang/{$lang}.php";
}

// ---------- Chiavi usate nel codice ----------
$used = [];
$phpFiles = array_merge(glob("{$root}/*.php"), glob("{$root}/{api,src,pages,pages/partials}/*.php", GLOB_BRACE));
foreach ($phpFiles as $file) {
    preg_match_all("/(?:_e|__)\(\s*'([a-z][A-Za-z0-9_.]+)'/", file_get_contents($file), $m);
    // Chiavi scelte con un ternario: __($cond ? 'a.b' : 'c.d').
    preg_match_all("/(?:_e|__)\([^;]*?\?\s*'([a-z]+\.[A-Za-z0-9_.]+)'\s*:\s*'([a-z]+\.[A-Za-z0-9_.]+)'/", file_get_contents($file), $t);
    foreach (array_merge($m[1], $t[1], $t[2]) as $key) {
        $used[$key][] = basename($file);
    }
}

$jsSources = array_merge(glob("{$root}/assets/js/*.js"), glob("{$root}/pages/*.php"));
foreach ($jsSources as $file) {
    $source = file_get_contents($file);
    // Primo argomento di ogni t('...'), e chiavi scelte con un ternario: t(cond ? 'a' : 'b').
    preg_match_all("/\\bt\\(\\s*'([a-z][a-z0-9_]*)'/", $source, $direct);
    preg_match_all("/\\bt\\([^;()]*?\\?\\s*'([a-z][a-z0-9_]*)'\\s*:\\s*'([a-z][a-z0-9_]*)'/", $source, $ternary);
    foreach (array_merge($direct[1], $ternary[1], $ternary[2]) as $key) {
        $used['js.' . $key][] = basename($file);
    }
}

foreach ($used as $key => $files) {
    // Chiave composta a runtime ('l.role_' . $role->value): servono le varianti per ogni ruolo.
    $variants = str_ends_with($key, '_')
        ? array_map(fn (Rugby\Role $role) => $key . $role->value, Rugby\Role::cases())
        : [$key];

    foreach ($dictionaries as $lang => $dictionary) {
        foreach ($variants as $variant) {
            if (!array_key_exists($variant, $dictionary)) {
                $problems[] = "[{$lang}] chiave mancante '{$variant}' (usata in " . implode(', ', array_unique($files)) . ')';
            }
        }
    }
}

// ---------- Stesse chiavi in tutti i dizionari ----------
$reference = array_diff(array_keys($dictionaries[I18n::FALLBACK]), ['graph']);
foreach ($dictionaries as $lang => $dictionary) {
    $keys = array_diff(array_keys($dictionary), ['graph']);
    foreach (array_diff($reference, $keys) as $key) {
        $problems[] = "[{$lang}] manca la chiave '{$key}' presente in " . I18n::FALLBACK;
    }
    foreach (array_diff($keys, $reference) as $key) {
        $problems[] = "[{$lang}] chiave '{$key}' non presente in " . I18n::FALLBACK;
    }
}

// ---------- Etichette del grafo ----------
$graphTexts = [];
foreach (States::graph() as $state) {
    $graphTexts[$state['title']] = true;
    foreach ($state['actions'] as $action) {
        $graphTexts[$action['label']] = true;
    }
}
unset($graphTexts['{HOME}'], $graphTexts['{AWAY}']);

foreach ($dictionaries as $lang => $dictionary) {
    if ($lang === I18n::FALLBACK) {
        continue;
    }
    foreach (array_keys($graphTexts) as $text) {
        if (!isset($dictionary['graph'][$text])) {
            $problems[] = "[{$lang}] grafo: manca la traduzione di '{$text}'";
        }
    }
    foreach (array_keys($dictionary['graph'] ?? []) as $text) {
        if (!isset($graphTexts[$text])) {
            $problems[] = "[{$lang}] grafo: traduzione inutilizzata '{$text}'";
        }
    }
}

if ($problems === []) {
    echo 'OK: ' . count($used) . ' chiavi usate, ' . count($graphTexts) . " etichette del grafo, lingue: " . implode(', ', I18n::SUPPORTED) . "\n";
    exit(0);
}

echo implode("\n", $problems) . "\n";
exit(1);
