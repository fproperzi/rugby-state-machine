<?php

/**
 * Streaming del file video locale di una partita (modalita' split), con supporto alle richieste
 * HTTP Range: senza, il player non potrebbe saltare in un punto qualsiasi del video.
 *
 * Il percorso NON arriva dalla richiesta: si legge quello salvato (e validato) per la partita,
 * cosi' l'endpoint non diventa un lettore di file arbitrari.
 */

require_once __DIR__ . '/../bootstrap.php';

use Rugby\Auth;
use Rugby\Http;
use Rugby\MatchRepository;
use Rugby\Role;
use Rugby\VideoSource;

Auth::requireApi(Role::Viewer);

// Il server PHP integrato su Windows serve una richiesta alla volta: rispondere a blocchi
// limitati (il browser chiede da solo i successivi) evita che lo streaming blocchi le API del tagger.
const STREAM_CHUNK_BYTES = 4 * 1024 * 1024;
const STREAM_READ_BYTES = 256 * 1024;

$matchId = (int) ($_GET['match_id'] ?? 0);
if ($matchId <= 0) {
    Http::errorResponse(__('err.match_id_missing'));
}

$match = (new MatchRepository())->find($matchId);
if ($match === null) {
    Http::errorResponse(__('err.match_not_found'), 404);
}

$descriptor = VideoSource::describe($match['video_url'], $matchId);
if ($descriptor === null || $descriptor['kind'] !== VideoSource::KIND_LOCAL) {
    Http::errorResponse(__('err.no_local_video'), 404);
}

$path = $match['video_url'];
$size = filesize($path);
[$start, $end] = requestedRange($_SERVER['HTTP_RANGE'] ?? null, $size);

if ($start === null) {
    header("Content-Range: bytes */{$size}");
    Http::errorResponse(__('err.invalid_range'), 416);
}

$handle = fopen($path, 'rb');
if ($handle === false) {
    error_log("video_stream: impossibile aprire {$path}");
    Http::errorResponse(__('err.video_read_failed'), 500);
}

http_response_code(206);
header('Content-Type: ' . VideoSource::localMimeType($path));
header('Accept-Ranges: bytes');
header("Content-Range: bytes {$start}-{$end}/{$size}");
header('Content-Length: ' . ($end - $start + 1));
header('Cache-Control: no-store');

fseek($handle, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($handle)) {
    $buffer = fread($handle, min(STREAM_READ_BYTES, $remaining));
    if ($buffer === false) {
        break;
    }
    echo $buffer;
    $remaining -= strlen($buffer);
}
fclose($handle);

/**
 * Interpreta l'header Range (solo la forma a intervallo singolo, l'unica usata dai player video)
 * e limita la risposta a STREAM_CHUNK_BYTES.
 *
 * @return array{0: int|null, 1: int|null} [inizio, fine] inclusivi; [null, null] se non soddisfacibile
 */
function requestedRange(?string $header, int $size): array
{
    $start = 0;
    $end = $size - 1;

    if ($header !== null && preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) {
        if ($m[1] === '' && $m[2] !== '') {
            // "bytes=-N": gli ultimi N byte.
            $start = max(0, $size - (int) $m[2]);
        } else {
            $start = (int) $m[1];
            if ($m[2] !== '') {
                $end = min((int) $m[2], $size - 1);
            }
        }
    }

    if ($size === 0 || $start > $end || $start >= $size) {
        return [null, null];
    }

    return [$start, min($end, $start + STREAM_CHUNK_BYTES - 1)];
}
