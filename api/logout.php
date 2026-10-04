<?php

/**
 * Uscita: POST -> chiude la sessione corrente.
 */

require_once __DIR__ . '/../bootstrap.php';

use Rugby\Auth;
use Rugby\Http;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Http::errorResponse(__('err.method_not_allowed'), 405);
}

Auth::requireSameOriginJson();
Auth::logout();

Http::jsonResponse(['redirect' => 'index.php?page=login']);
