<?php

/**
 * Scarica come file JSON le partite indicate: GET api/export.php?ids=1,4,7
 */

require_once __DIR__ . '/../bootstrap.php';

use Rugby\Auth;
use Rugby\Http;
use Rugby\MatchArchive;
use Rugby\Role;

Auth::requireApi(Role::Viewer);

$ids = array_values(array_unique(array_filter(
    array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))),
    fn (int $id) => $id > 0
)));

try {
    $archive = (new MatchArchive())->export($ids);
} catch (InvalidArgumentException $e) {
    Http::errorResponse($e->getMessage(), 404);
}

$filename = sprintf('rugby-tagger-%d-partite-%s.json', count($ids), gmdate('Ymd-His'));
header('Content-Disposition: attachment; filename="' . $filename . '"');

Http::jsonResponse($archive);
