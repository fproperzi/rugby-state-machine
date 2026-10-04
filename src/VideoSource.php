<?php

namespace Rugby;

/**
 * Sorgente video di una partita in modalita' split: un link YouTube/Vimeo oppure il percorso
 * di un file sul disco locale. Il valore grezzo e' salvato in matches.video_url; qui si
 * normalizza, si valida e si traduce in un descrittore che il player JS sa caricare.
 *
 * Il file locale non viene mai copiato o caricato: lo legge api/video_stream.php dal percorso
 * salvato, perche' il browser non puo' riaprire da solo un file locale dopo un ricaricamento.
 */
class VideoSource
{
    public const KIND_YOUTUBE = 'youtube';
    public const KIND_VIMEO = 'vimeo';
    public const KIND_LOCAL = 'local';

    /** Estensioni accettate per i file locali, con il Content-Type da inviare al browser. */
    private const LOCAL_MIME_TYPES = [
        'mp4' => 'video/mp4',
        'm4v' => 'video/mp4',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
        'ogv' => 'video/ogg',
        'mkv' => 'video/x-matroska',
    ];

    private const YOUTUBE_PATTERN = '~(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|embed/|live/|shorts/))([\w-]{6,})~';
    private const VIMEO_PATTERN = '~vimeo\.com/(?:video/)?(\d+)~';

    /**
     * Ripulisce l'input dell'utente: spazi e virgolette attorno al percorso
     * (Esplora risorse, con "Copia come percorso", lo racchiude tra virgolette).
     *
     * @param string $raw valore digitato o incollato
     * @return string valore normalizzato, stringa vuota se assente
     */
    public static function normalize(string $raw): string
    {
        return trim(trim($raw), '"\'');
    }

    /**
     * Controlla che la sorgente sia utilizzabile.
     *
     * @param string $source valore gia' normalizzato (non vuoto)
     * @return string|null messaggio d'errore per l'utente, null se valida
     */
    public static function validationError(string $source): ?string
    {
        if (self::isUrl($source)) {
            return self::remoteDescriptor($source) === null
                ? __('err.video_link_unknown')
                : null;
        }

        if (!is_file($source)) {
            return __('err.video_file_not_found', ['path' => $source]);
        }

        if (!is_readable($source)) {
            return __('err.video_file_unreadable', ['path' => $source]);
        }

        if (self::localMimeType($source) === null) {
            return __('err.video_format', ['extensions' => implode(', ', array_keys(self::LOCAL_MIME_TYPES))]);
        }

        return null;
    }

    /**
     * Descrittore per il player JS.
     *
     * @param string|null $source valore salvato in matches.video_url
     * @param int $matchId partita, serve a costruire l'URL di streaming del file locale
     * @return array|null ['kind' => youtube|vimeo, 'id' => ...] oppure ['kind' => local, 'src' => ...];
     *                    null se non c'e' un video o la sorgente non e' (piu') valida
     */
    public static function describe(?string $source, int $matchId): ?array
    {
        if ($source === null || $source === '') {
            return null;
        }

        if (self::isUrl($source)) {
            return self::remoteDescriptor($source);
        }

        // Il file puo' essere stato spostato dopo il salvataggio: meglio nessun video che un player rotto.
        if (self::validationError($source) !== null) {
            return null;
        }

        return ['kind' => self::KIND_LOCAL, 'src' => 'api/video_stream.php?match_id=' . $matchId];
    }

    /**
     * Content-Type di un file locale ammesso, in base all'estensione.
     *
     * @return string|null null se l'estensione non e' tra quelle ammesse
     */
    public static function localMimeType(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::LOCAL_MIME_TYPES[$extension] ?? null;
    }

    /**
     * Legge dal body JSON di una richiesta la posizione del player (campi video_time, video_playing),
     * inviata dal tagger quando il cronometro deve seguire il video.
     *
     * @param array $input body JSON decodificato
     * @return array|null ['time' => float, 'playing' => bool]; null se la richiesta non riguarda il video
     */
    public static function playbackFromInput(array $input): ?array
    {
        if (!isset($input['video_time']) || !is_numeric($input['video_time'])) {
            return null;
        }

        $time = (float) $input['video_time'];
        if ($time < 0 || !is_finite($time)) {
            return null;
        }

        return ['time' => $time, 'playing' => (bool) ($input['video_playing'] ?? false)];
    }

    private static function isUrl(string $source): bool
    {
        return (bool) preg_match('~^https?://~i', $source);
    }

    private static function remoteDescriptor(string $url): ?array
    {
        if (preg_match(self::YOUTUBE_PATTERN, $url, $m)) {
            return ['kind' => self::KIND_YOUTUBE, 'id' => $m[1]];
        }

        if (preg_match(self::VIMEO_PATTERN, $url, $m)) {
            return ['kind' => self::KIND_VIMEO, 'id' => $m[1]];
        }

        return null;
    }
}
