# Rugby Tagger — CLAUDE.md di progetto

Questo file aggiunge dettagli specifici del progetto alle direttive globali
(`~/.claude/CLAUDE.md`) e ha la precedenza in caso di conflitto.

## Cos'è

App web per il tagging live di una partita di rugby: punteggio, cronometro,
macchina a stati per gli eventi di gioco (mischia, touche, punizioni, mete...),
cartellini/sostituzioni, vantaggio, statistiche con mappa dei calci e scrubber
temporale. Due modalità: **live** (solo tagger) e **split** (video a sinistra +
tagger a destra, nella stessa pagina: il tagger è il partial
`pages/partials/tagger.php`, incluso sia da `live.php` sia da `split.php`).

Ricostruita analizzando ~100 screenshot di un'app commerciale equivalente
(presenti nella root del progetto, `IMG_*.jpg` + `Photos.zip`): sono materiale
di riferimento, **non cancellarli**.

## Stack — deviazione deliberata dalle convenzioni globali

Le direttive globali descrivono le convenzioni Fat-Free Framework, ma qui
**non è usato alcun framework** (scelta esplicita dell'utente) e il backend è
**SQLite su file**, non MySQL/`.ini`. Di conseguenza:

- Niente `app/controllers`, `app/views`, `routes.ini`: struttura piatta descritta sotto.
- Niente `Controller` base, niente `crudTrait`: i pochi endpoint sono script PHP
  indipendenti in `api/`.
- Autoload custom in `bootstrap.php` (namespace `Rugby\`, mappato su `src/`),
  non Composer/PSR-4 (nessun `composer.json`: l'utente ha rifiutato esplicitamente
  di aggiungere PHPUnit/Composer — richiedere di nuovo solo se lo chiede lui).
- Le altre regole globali restano valide: italiano in conversazione/commenti,
  identificatori in inglese, niente valori magici (vedi `config.php`), niente
  astrazioni premature, verifica con `php -l` prima di dire "fatto".

## Architettura — il grafo è il cuore del progetto

**`src/States.php`** contiene l'intera logica di gioco come dato dichiarativo:
un array `[stato => [title, actions]]`. Ogni stato usa i ruoli relativi
`SUBJECT`/`OTHER` (la squadra protagonista del momento e l'altra) invece di
duplicare gli stati per home/away — se devi aggiungere o modificare una regola
di gioco, **quasi sempre basta toccare questo file**, non il motore.

**`src/StateMachine.php`** interpreta il grafo: data un'azione nello stato
corrente, calcola stato successivo, squadra/punti/etichetta dell'evento e il
nuovo contesto (`{"subject": "home"|"away", "advantage": {...}?}`). Il contesto
si eredita tra le transizioni (solo `subject` viene sovrascritto), cosa che
permette a dati laterali come il vantaggio di sopravvivere alle azioni normali.

**`src/MatchRepository.php`** è l'unico punto che scrive partite ed eventi (gli utenti li scrive
`src/UserRepository.php`): crea
partite, applica azioni (wrappando motore + persistenza in una transazione),
gestisce l'**undo** (basato sullo snapshot `context_before`/`context_after`
salvato con ogni evento — non ricalcola nulla, ripristina lo stato esatto),
cronometro (`clock_running` + `clock_started_at`, il tempo trascorso si calcola
sempre a richiesta, mai con un timer lato server), cartellini, sostituzioni,
vantaggio.

**`src/StatsCalculator.php`** calcola tutte le statistiche (possesso %,
marcature, mauls, turnover, knock-on, kick-off recuperati, mappa dei calci)
**rileggendo lo storico eventi** — nessun contatore tenuto a mano nel DB.

## Video e cronometro (modalità split)

- **Sorgente** (`src/VideoSource.php`): `matches.video_url` contiene un link
  YouTube/Vimeo *oppure* il percorso di un file sul disco. Il riconoscimento dei
  link sta solo in PHP: il payload espone un descrittore `video`
  (`{kind: youtube|vimeo, id}` / `{kind: local, src}`) che `video.js` carica.
- **File locale**: servito da `api/video_stream.php` leggendo il percorso salvato
  per la partita (mai un percorso dalla richiesta), con supporto Range e risposte
  da max 4 MB: il server PHP integrato su Windows è single-thread e uno streaming
  lungo bloccherebbe le API.
- **Cronometro agganciato al video**: al calcio d'inizio di ogni tempo
  `MatchRepository::syncVideoClock` fissa `video_offset` (secondi video − secondi
  di gioco); poi `match_seconds = tempo video − video_offset`. `video.js` emette
  `rugby:video` su play/pausa/salto (+ heartbeat ogni 5 s), `app.js` sincronizza via
  `api/clock.php` (`op: video_sync`) e allega `video_time` a ogni azione.
  Il server ignora il video se la partita non è `live`; `video_offset` torna NULL
  all'intervallo e al cambio di video (si riancora alla sincronizzazione successiva).
  In questa modalità "Time Off/On" mette in pausa/riprende il video.
- Comunicazione tra `video.js` e `app.js` solo via eventi DOM (`rugby:state`,
  `rugby:video`, `rugby:video-ready`, `rugby:payload`) + `window.RugbyVideo`.
- La sorgente video si sceglie **solo nel setup** (richiesta dell'utente): nello
  split non ci sono controlli per cambiarla. `api/video.php` esiste ma oggi
  nessuna pagina lo usa.
- **Scambio squadre** (pulsante ⇄ nell'intestazione): solo visivo, salvato in
  `localStorage` per partita; home/away nei dati non cambiano. La classe
  `sides-swapped` sta sul `<body>` e inverte anche i selettori di squadra dei
  modali (cartellino/sostituzione/vantaggio), che mostrano nome e colore.

## Lingue (italiano / inglese)

- `src/I18n.php`: lingua da `?lang=` (salvata nel cookie `rugby_lang`) → cookie →
  `Accept-Language` → inglese. `index.php` la determina **prima di ogni output**
  (altrimenti il cookie non parte).
- Dizionari `lang/en.php`, `lang/it.php`. Nei template `_e('chiave')`, nel PHP
  `__('chiave', [param])`, nel JS `t('chiave')` (le voci `js.` arrivano al JS tramite
  `pages/partials/common-scripts.php`, che carica anche `session.js`). Prefissi: `l.` `h.` `page.` `msg.` `err.` `js.`.
- Le etichette del grafo restano in inglese in `States.php`; la traduzione italiana sta
  nella sezione `graph` di `lang/it.php`, indicizzata dal testo inglese, e la applica
  `StateMachine::resolveLabel`. Le etichette degli eventi si salvano già tradotte.
- Dopo ogni modifica a testi o grafo: `php tools/check-i18n.php`.

## GitHub, sicurezza, README

- `.gitignore` esclude foto di riferimento, `Photos.zip`, il DB, gli export JSON,
  `src/__to-do.txt` e `.claude/settings.local.json`.
- `.htaccess` (root + `data/ src/ pages/ lang/ tools/`) per Apache; `router.php` replica
  le stesse regole per `php -S` (usato da `start-server.bat`). Pubblici solo
  `index.php`, `api/*.php`, `assets/*`.
- Licenza **GPL-3.0-or-later** (scelta dell'utente, 2026-10-04): testo ufficiale in `LICENSE`,
  sezione "License/Licenza" in fondo ai due README.
- `README.md` (inglese) e `README.it.md`: grafo completo e tabella delle profondità sono
  generati da `php tools/graph-mermaid.php graph|depth [it]`: rigenerarli quando cambia il grafo.

## Accesso, utenti e ruoli

- Ruoli (`src/Role.php`, enum): `admin` > `tagger` > `viewer`. Partite condivise tra tutti;
  `matches.created_by` registra chi le crea (o le importa).
- `src/Auth.php`: sessione PHP (cookie `rugby_sid` HttpOnly/SameSite=Lax/Secure su HTTPS,
  strict mode, id rigenerato al login), utente riletto dal DB a ogni richiesta
  (`users.session_version` invalida le sessioni dopo cambio/reset password o disattivazione),
  scadenza per inattività, blocco tentativi falliti (tabella `login_failures`).
- **Pagine**: ruolo minimo in `PAGE_ROLES` di `index.php`, applicato da `Auth::pageToRender`
  (senza utenti nel DB mostra `install`, senza login rimanda a `login?next=`).
- **API**: ogni endpoint chiama `Auth::requireApi(Role::...)` subito dopo gli `use` (viewer per
  le letture, tagger per le modifiche, admin per `api/users.php`). Per le richieste non-GET
  pretende JSON e Origin dello stesso sito: è la protezione CSRF (niente token).
  Un nuovo endpoint **deve** avere la sua guardia.
- `assets/js/session.js` (incluso da `common-scripts.php`): su qualsiasi 401 rimanda al login.
- Recupero dell'unico admin: `php tools/reset-password.php <utente>` (solo CLI).
- Configurazione in `config.php` (`SESSION_*`, `PASSWORD_MIN_LENGTH`, `LOGIN_*`).

## Export / import partite

Dal menu si spuntano una o più partite → `api/export.php?ids=…` scarica un JSON
(formato in `src/MatchArchive.php`: partita + tutti gli eventi, senza id, con il
cronometro congelato). "Importa…" manda il file a `api/import.php`, che valida
tutto (stati esistenti nel grafo, enum, JSON dei contesti) e inserisce in
un'unica transazione via `MatchRepository::importMatches`: tutto o niente.
Ogni import crea partite nuove (id nuovi), anche se il file è già stato importato.
Se il formato cambia, aumentare `MatchArchive::VERSION` e gestire le versioni precedenti.
- Colonne aggiunte dopo la prima versione dello schema: vanno anche in
  `Database::ADDED_COLUMNS`, che le aggiunge ai DB esistenti.

## Struttura cartelle

```
config.php, bootstrap.php, schema.sql    config/costanti, autoload, schema SQLite
src/                                      logica di dominio (vedi sopra)
api/*.php                                 endpoint JSON, uno per operazione
                                           (matches, state, action, undo, clock,
                                           card, substitution, video, video_stream,
                                           stats, advantage, export, import)
pages/*.php, pages/partials/              le schermate server-rendered (shell HTML,
                                           niente logica: il resto lo fa il JS via fetch)
assets/css/style.css, assets/js/*.js      stile unico + app.js (tagger), stats.js,
                                           video.js (player YouTube/Vimeo/file locale,
                                           mai caricato sul server; vedi sotto)
start-server.bat                          avvia php -S su localhost:8000 e apre il browser
data/rugby.sqlite                         DB runtime, creato al volo dal primo avvio
                                           (NON versionarlo/committarlo con dati di prova)
```

## Eventi: la fonte di verità

Tabella `events`: ogni riga è un'azione taggata, con `kind` (`state` = cambia la
macchina a stati ed è annullabile con Undo; `card`/`substitution`/`clock` = eventi
laterali, non annullabili dall'Undo principale), `match_seconds` (per lo
scrubber), `meta` JSON libero (es. `{cause: "offside"}` per le punizioni, o
`{x, y}` per la posizione di un calcio a palo).

## Come verificare prima di dire "fatto"

```
php -l <file>                      # su ogni file toccato
php -S localhost:8000               # per provare a runtime
```

Poi controllare `pages/menu.php`, `setup.php`, `live.php`, `split.php`,
`stats.php` nel browser e i log del server built-in per warning/notice.
Niente PHPUnit in questo progetto (rifiutato esplicitamente) — se in futuro
serve testare la logica di `StateMachine`/`StatsCalculator`, proporlo prima di
installarlo, come da direttiva globale.

## Semplificazioni note rispetto all'app originale

Scelte deliberate per tenere lo scope ragionevole — cambiarle solo se richiesto:

- "Kicks At Goal": mappa con punto tappato dall'utente (facoltativo), non un
  sistema di marcatura automatica della traiettoria.
- Il "vantaggio" è manuale (pulsante Avvia/Ferma con cronometro), non rilevato
  automaticamente dal flusso di gioco.
- Free Kick e Mark portano a un sotto-menu comune (`free_kick_option`: Kick To
  Touch / Tap and Go / Lineout) coerente con la regola (niente tiro in porta,
  niente opzione mischia: Law 20.3 la concede solo alla punizione).

## Conformità al regolamento

Il grafo in `src/States.php` è stato allineato alle Laws of the Game World Rugby
in vigore dal 1° luglio 2026 (sessione del 2026-10-03); i commenti inline citano
il numero di regola. Punti da ricordare: dopo una marcatura batte il calcio
d'inizio chi ha **subito** i punti (Law 12); quando gli attaccanti portano/calciano
il pallone in area di meta senza segnare si riparte con il **try-line drop-out**
(Law 12.12); il drop dai 22 m resta solo per piazzati/drop falliti e calci usciti
*oltre* l'area di meta (Law 12.11). Alcune scelte (es. "Lineout (at lineout only)",
"Not Straight - Uncontested") dipendono dal contesto e le valuta chi tagga.

## Da sapere per le prossime sessioni

- L'utente vuole pubblicarlo su GitHub (2026-10-04): `.gitignore` e README sono pronti;
  `git init`, commit e push si fanno solo su sua richiesta esplicita.
- L'utente ha dato il via libera a procedere in autonomia su questo progetto
  senza chiedere conferma ad ogni passo (sessione del 2026-10-03) — resta comunque
  prudente su azioni distruttive (es. non cancellare `IMG_*.jpg`/`Photos.zip`).
