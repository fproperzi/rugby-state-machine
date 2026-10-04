<?php

require_once __DIR__ . '/bootstrap.php';

use Rugby\Auth;
use Rugby\I18n;
use Rugby\Role;

// La lingua va decisa prima di qualsiasi output: un ?lang= esplicito viene salvato in un cookie,
// e i cookie non si possono piu' inviare dopo che la pagina ha iniziato a scrivere HTML.
I18n::current();

// Pagine protette e ruolo minimo richiesto. 'login' e 'install' sono pubbliche e le gestisce Auth.
const PAGE_ROLES = [
    'menu' => Role::Viewer,
    'stats' => Role::Viewer,
    'account' => Role::Viewer,
    'setup' => Role::Tagger,
    'live' => Role::Tagger,
    'split' => Role::Tagger,
    'users' => Role::Admin,
];

$requested = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'menu';
$page = Auth::pageToRender($requested, PAGE_ROLES);

require __DIR__ . '/pages/' . $page . '.php';
