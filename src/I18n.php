<?php

namespace Rugby;

/**
 * Traduzioni dell'interfaccia (italiano/inglese).
 *
 * Lingua corrente, in ordine di priorita':
 *  1. parametro ?lang=it|en nella richiesta (scelta esplicita: viene ricordata in un cookie);
 *  2. cookie della scelta precedente;
 *  3. header Accept-Language del browser (la prima lingua supportata, rispettando i pesi q=);
 *  4. inglese.
 *
 * Dizionari in lang/<lingua>.php. Due tipi di voci:
 *  - chiavi a punti ('l.', 'h.', 'page.', 'msg.', 'err.', 'js.'): testi dell'interfaccia;
 *    quelle con prefisso 'js.' vengono passate anche al JavaScript (vedi clientStrings());
 *  - sezione 'graph': etichette del grafo di gioco (States.php), indicizzate dal loro testo
 *    inglese, che e' la terminologia canonica del rugby e resta la lingua di riferimento del grafo.
 *
 * Una chiave mancante non blocca la pagina: si mostra il testo inglese (o la chiave) e si logga.
 */
class I18n
{
    public const SUPPORTED = ['en', 'it'];
    public const FALLBACK = 'en';

    private const COOKIE = 'rugby_lang';
    private const COOKIE_DAYS = 365;
    private const CLIENT_PREFIX = 'js.';

    private static ?string $current = null;
    private static array $dictionaries = [];

    /**
     * Lingua da usare per questa richiesta (calcolata una volta sola).
     *
     * @return string codice lingua tra SUPPORTED
     */
    public static function current(): string
    {
        if (self::$current === null) {
            self::$current = self::detect();
        }

        return self::$current;
    }

    /**
     * Testo tradotto per una chiave, con segnaposto {nome} sostituiti dai parametri.
     *
     * @param string $key chiave del dizionario (es. 'l.new_match')
     * @param array $params valori dei segnaposto, es. ['name' => 'Rovigo'] per {name}
     * @return string testo tradotto; la chiave stessa se manca anche in inglese
     */
    public static function t(string $key, array $params = []): string
    {
        $text = self::dictionary(self::current())[$key]
            ?? self::missing($key, self::dictionary(self::FALLBACK)[$key] ?? $key);

        return self::interpolate($text, $params);
    }

    /**
     * Traduce un'etichetta del grafo di gioco (testo inglese con eventuali {SUBJECT}/{OTHER}).
     *
     * @param string $english template inglese come scritto in States.php
     * @return string template tradotto (i segnaposto restano da risolvere)
     */
    public static function graph(string $english): string
    {
        if (self::current() === self::FALLBACK) {
            return $english;
        }

        return self::dictionary(self::current())['graph'][$english] ?? self::missing('graph: ' . $english, $english);
    }

    /**
     * Voci destinate al JavaScript (prefisso 'js.'), senza prefisso, nella lingua corrente.
     *
     * @return array [chiave => testo]
     */
    public static function clientStrings(): array
    {
        $strings = [];
        $keys = array_keys(self::dictionary(self::FALLBACK) + self::dictionary(self::current()));

        foreach ($keys as $key) {
            if (is_string($key) && str_starts_with($key, self::CLIENT_PREFIX)) {
                $strings[substr($key, strlen(self::CLIENT_PREFIX))] = self::t($key);
            }
        }

        return $strings;
    }

    /**
     * URL della pagina corrente con la lingua cambiata (per il selettore di lingua).
     */
    public static function switchUrl(string $lang): string
    {
        $query = $_GET;
        $query['lang'] = $lang;

        return 'index.php?' . http_build_query($query);
    }

    private static function detect(): string
    {
        $requested = $_GET['lang'] ?? null;
        if (is_string($requested) && in_array($requested, self::SUPPORTED, true)) {
            self::remember($requested);
            return $requested;
        }

        $cookie = $_COOKIE[self::COOKIE] ?? null;
        if (is_string($cookie) && in_array($cookie, self::SUPPORTED, true)) {
            return $cookie;
        }

        return self::fromAcceptLanguage($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') ?? self::FALLBACK;
    }

    /**
     * Prima lingua supportata nell'header Accept-Language, es. "it-IT,it;q=0.9,en;q=0.8" -> it.
     */
    private static function fromAcceptLanguage(string $header): ?string
    {
        $weighted = [];
        foreach (explode(',', $header) as $position => $part) {
            if (!preg_match('/^\s*([a-zA-Z]{2,3})(?:-[\w-]+)?\s*(?:;\s*q=([0-9.]+))?\s*$/', $part, $m)) {
                continue;
            }

            $quality = isset($m[2]) ? (float) $m[2] : 1.0;
            // A parita' di peso vince l'ordine di comparsa nell'header.
            $weighted[] = [strtolower($m[1]), $quality, $position];
        }

        usort($weighted, fn (array $a, array $b) => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

        foreach ($weighted as [$lang, $quality]) {
            if ($quality > 0 && in_array($lang, self::SUPPORTED, true)) {
                return $lang;
            }
        }

        return null;
    }

    private static function remember(string $lang): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE, $lang, [
            'expires' => time() + self::COOKIE_DAYS * 86400,
            'path' => '/',
            'samesite' => 'Lax',
        ]);
    }

    private static function dictionary(string $lang): array
    {
        if (!isset(self::$dictionaries[$lang])) {
            $file = APP_ROOT . '/lang/' . $lang . '.php';
            if (!is_file($file)) {
                throw new \RuntimeException("Dizionario mancante: {$file}");
            }
            self::$dictionaries[$lang] = require $file;
        }

        return self::$dictionaries[$lang];
    }

    private static function missing(string $key, string $fallback): string
    {
        error_log('I18n: traduzione mancante [' . self::current() . '] ' . $key);

        return $fallback;
    }

    private static function interpolate(string $text, array $params): string
    {
        if ($params === []) {
            return $text;
        }

        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements['{' . $name . '}'] = (string) $value;
        }

        return strtr($text, $replacements);
    }
}
