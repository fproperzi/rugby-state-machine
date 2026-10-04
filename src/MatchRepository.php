<?php

namespace Rugby;

/**
 * Accesso ai dati di una partita: creazione, lettura, applicazione azioni (via StateMachine),
 * undo, controllo cronometro, cartellini e sostituzioni. Unico punto che scrive su SQLite.
 */
class MatchRepository
{
    private \PDO $db;
    private StateMachine $engine;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->engine = new StateMachine();
    }

    /**
     * Crea una partita.
     *
     * @param int $createdBy utente che la crea
     * @return int id della partita
     */
    public function create(string $homeName, string $homeColor, string $awayName, string $awayColor, int $createdBy): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO matches (home_name, home_color, away_name, away_color, created_by) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$homeName, $homeColor, $awayName, $awayColor, $createdBy]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Inserisce partite importate (gia' validate da MatchArchive) con i loro eventi, in un'unica
     * transazione: o entrano tutte o nessuna.
     *
     * @param array $entries elenco di ['match' => [colonna => valore], 'events' => [[colonna => valore], ...]]
     * @return int[] id assegnati alle nuove partite, nello stesso ordine
     */
    public function importMatches(array $entries): array
    {
        $ids = [];

        $this->db->beginTransaction();
        try {
            foreach ($entries as $entry) {
                $ids[] = $matchId = $this->insertRow('matches', $entry['match']);

                foreach ($entry['events'] as $event) {
                    $this->insertRow('events', ['match_id' => $matchId] + $event);
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $ids;
    }

    /**
     * INSERT generico: nomi di tabella e colonne arrivano solo da codice (mai dall'input utente).
     */
    private function insertRow(string $table, array $row): int
    {
        $columns = implode(', ', array_keys($row));
        $placeholders = implode(', ', array_fill(0, count($row), '?'));

        $stmt = $this->db->prepare("INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})");
        $stmt->execute(array_values($row));

        return (int) $this->db->lastInsertId();
    }

    public function find(int $matchId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM matches WHERE id = ?');
        $stmt->execute([$matchId]);
        $match = $stmt->fetch();

        return $match ?: null;
    }

    public function listAll(): array
    {
        return $this->db->query(
            'SELECT m.id, m.home_name, m.away_name, m.home_score, m.away_score, m.status, m.video_url, m.updated_at,
                    u.username AS created_by_name
             FROM matches m LEFT JOIN users u ON u.id = m.created_by
             ORDER BY m.updated_at DESC'
        )->fetchAll();
    }

    /**
     * Salva la sorgente video (gia' validata con VideoSource). Se cambia, azzera l'ancoraggio
     * video/cronometro: il nuovo video ha un'altra timeline e verra' riancorato alla prima
     * sincronizzazione (vedi syncVideoClock).
     *
     * @param string $videoUrl link o percorso locale; stringa vuota per togliere il video
     */
    public function setVideoUrl(int $matchId, string $videoUrl): void
    {
        $stmt = $this->db->prepare(
            'UPDATE matches SET video_offset = CASE WHEN video_url IS ? THEN video_offset ELSE NULL END,
             video_url = ?, updated_at = datetime(\'now\') WHERE id = ?'
        );
        $stored = $videoUrl === '' ? null : $videoUrl;
        $stmt->execute([$stored, $stored, $matchId]);
    }

    /**
     * Allinea il cronometro al video: con il video in pausa il cronometro e' fermo, in play corre,
     * e un salto nella timeline del video si riflette sul tempo di gioco.
     *
     * Agisce solo a partita in corso (status 'live'): prima del calcio d'inizio e durante
     * l'intervallo il tempo di gioco non deve muoversi anche se il video scorre. Alla prima
     * chiamata dopo un calcio d'inizio (video_offset NULL) fissa l'ancoraggio tra le due timeline.
     *
     * @param float $videoTime posizione corrente del video in secondi
     * @param bool $isPlaying true se il video sta riproducendo
     * @return array riga match aggiornata
     */
    public function syncVideoClock(int $matchId, float $videoTime, bool $isPlaying): array
    {
        $match = $this->find($matchId);
        if ($match === null) {
            throw new \RuntimeException(__('err.match_not_found'));
        }

        if ($match['status'] !== 'live') {
            return $match;
        }

        $offset = $match['video_offset'] !== null
            ? (float) $match['video_offset']
            : $videoTime - $this->currentMatchSeconds($match);

        $stmt = $this->db->prepare(
            'UPDATE matches SET video_offset = ?, match_seconds = ?, clock_running = ?, clock_started_at = ?,
             updated_at = datetime(\'now\') WHERE id = ?'
        );
        $stmt->execute([
            $offset,
            max(0, (int) floor($videoTime - $offset)),
            $isPlaying ? 1 : 0,
            $isPlaying ? $this->nowUtc() : null,
            $matchId,
        ]);

        return $this->find($matchId);
    }

    /**
     * Restituisce lo stato corrente gia' risolto per la UI (titolo + azioni con etichette).
     */
    public function describeState(array $match): array
    {
        return $this->engine->describeCurrentState($match);
    }

    /**
     * Applica un'azione dello stato corrente: aggiorna punteggio/stato/contesto e logga l'evento.
     */
    public function applyAction(int $matchId, string $actionId, array $extraMeta = []): array
    {
        $match = $this->find($matchId);
        if ($match === null) {
            throw new \RuntimeException(__('err.match_not_found'));
        }

        $transition = $this->engine->applyAction($match, $actionId, $extraMeta);

        $this->db->beginTransaction();
        try {
            $homeScore = (int) $match['home_score'];
            $awayScore = (int) $match['away_score'];
            if ($transition['points'] > 0 && $transition['team'] !== null) {
                if ($transition['team'] === 'home') {
                    $homeScore += $transition['points'];
                } else {
                    $awayScore += $transition['points'];
                }
            }

            // Il primo calcio (inizio gara o ripresa del secondo tempo) rimette in moto il cronometro.
            $kicksOffPlay = in_array($match['status'], ['setup', 'half_time'], true);

            $update = $this->db->prepare(
                'UPDATE matches SET current_state = ?, state_context = ?, home_score = ?, away_score = ?,
                 status = ?, clock_running = ?, clock_started_at = ?, updated_at = datetime(\'now\') WHERE id = ?'
            );
            $update->execute([
                $transition['state_after'],
                json_encode($transition['context_after']),
                $homeScore,
                $awayScore,
                $kicksOffPlay ? 'live' : $match['status'],
                $kicksOffPlay ? 1 : $match['clock_running'],
                $kicksOffPlay ? $this->nowUtc() : $match['clock_started_at'],
                $matchId,
            ]);

            $matchSeconds = $this->currentMatchSeconds($this->find($matchId));

            $insert = $this->db->prepare(
                'INSERT INTO events
                    (match_id, match_seconds, kind, state_before, state_after, action_id, team, label, points, meta, context_before, context_after)
                 VALUES (?, ?, \'state\', ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $matchId,
                $matchSeconds,
                $transition['state_before'],
                $transition['state_after'],
                $transition['action_id'],
                $transition['team'],
                $transition['label'],
                $transition['points'],
                json_encode($transition['meta']),
                json_encode($transition['context_before']),
                json_encode($transition['context_after']),
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->find($matchId);
    }

    /**
     * Annulla l'ultimo evento di tipo 'state': ripristina stato/contesto/punteggio precedenti.
     */
    public function undoLast(int $matchId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM events WHERE match_id = ? AND kind = \'state\' ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$matchId]);
        $lastEvent = $stmt->fetch();

        if ($lastEvent === false) {
            return $this->find($matchId);
        }

        $match = $this->find($matchId);

        $this->db->beginTransaction();
        try {
            $homeScore = (int) $match['home_score'];
            $awayScore = (int) $match['away_score'];
            if ($lastEvent['points'] > 0 && $lastEvent['team'] !== null) {
                if ($lastEvent['team'] === 'home') {
                    $homeScore -= $lastEvent['points'];
                } else {
                    $awayScore -= $lastEvent['points'];
                }
            }

            $update = $this->db->prepare(
                'UPDATE matches SET current_state = ?, state_context = ?, home_score = ?, away_score = ?, updated_at = datetime(\'now\') WHERE id = ?'
            );
            $update->execute([
                $lastEvent['state_before'],
                $lastEvent['context_before'],
                $homeScore,
                $awayScore,
                $matchId,
            ]);

            $delete = $this->db->prepare('DELETE FROM events WHERE id = ?');
            $delete->execute([$lastEvent['id']]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->find($matchId);
    }

    public function lastEventLabel(int $matchId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT label FROM events WHERE match_id = ? AND kind = \'state\' ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$matchId]);
        $row = $stmt->fetch();

        return $row ? $row['label'] : null;
    }

    public function toggleClock(int $matchId): array
    {
        $match = $this->find($matchId);
        $running = (int) $match['clock_running'];

        if ($running) {
            // Time Off: congela i secondi maturati finora e ferma il cronometro.
            $elapsed = $this->currentMatchSeconds($match);
            $stmt = $this->db->prepare(
                'UPDATE matches SET match_seconds = ?, clock_running = 0, clock_started_at = NULL, updated_at = datetime(\'now\') WHERE id = ?'
            );
            $stmt->execute([$elapsed, $matchId]);
        } else {
            $stmt = $this->db->prepare(
                'UPDATE matches SET clock_running = 1, clock_started_at = ?, updated_at = datetime(\'now\') WHERE id = ?'
            );
            $stmt->execute([$this->nowUtc(), $matchId]);
        }

        return $this->find($matchId);
    }

    /**
     * Vantaggio in corso: non cambia lo stato della macchina a stati, e' solo un'informazione
     * laterale (cronometro giallo + evento) che l'arbitro/taggatore chiude manualmente.
     */
    public function startAdvantage(int $matchId, string $team): array
    {
        $match = $this->find($matchId);
        $context = json_decode($match['state_context'], true) ?: [];
        $context['advantage'] = [
            'team' => $team,
            'started_at' => $this->nowUtc(),
        ];

        $stmt = $this->db->prepare('UPDATE matches SET state_context = ?, updated_at = datetime(\'now\') WHERE id = ?');
        $stmt->execute([json_encode($context), $matchId]);

        $this->insertSideEvent($matchId, $match, 'clock', $team, __('msg.advantage', ['team' => $match["{$team}_name"]]), []);

        return $this->find($matchId);
    }

    public function stopAdvantage(int $matchId): array
    {
        $match = $this->find($matchId);
        $context = json_decode($match['state_context'], true) ?: [];
        $team = $context['advantage']['team'] ?? null;
        unset($context['advantage']);

        $stmt = $this->db->prepare('UPDATE matches SET state_context = ?, updated_at = datetime(\'now\') WHERE id = ?');
        $stmt->execute([json_encode($context), $matchId]);

        if ($team !== null) {
            $this->insertSideEvent($matchId, $match, 'clock', $team, __('msg.advantage_over', ['team' => $match["{$team}_name"]]), []);
        }

        return $this->find($matchId);
    }

    public function halfTime(int $matchId): array
    {
        $match = $this->find($matchId);
        $elapsed = $this->currentMatchSeconds($match);

        $stmt = $this->db->prepare(
            'UPDATE matches SET match_seconds = ?, clock_running = 0, clock_started_at = NULL,
             status = \'half_time\', half = 2, current_state = \'kickoff_choice\', state_context = \'{}\',
             video_offset = NULL, updated_at = datetime(\'now\') WHERE id = ?'
        );
        $stmt->execute([$elapsed, $matchId]);

        $this->logClockEvent($matchId, $elapsed, __('msg.half_time'));

        return $this->find($matchId);
    }

    public function fullTime(int $matchId): array
    {
        $match = $this->find($matchId);
        $elapsed = $this->currentMatchSeconds($match);

        $stmt = $this->db->prepare(
            'UPDATE matches SET match_seconds = ?, clock_running = 0, clock_started_at = NULL,
             status = \'full_time\', updated_at = datetime(\'now\') WHERE id = ?'
        );
        $stmt->execute([$elapsed, $matchId]);

        $this->logClockEvent($matchId, $elapsed, __('msg.full_time'));

        return $this->find($matchId);
    }

    public function recordCard(int $matchId, string $team, string $colour, ?int $playerNumber): array
    {
        $match = $this->find($matchId);
        $label = __($colour === 'red' ? 'msg.red_card' : 'msg.yellow_card', ['team' => $match["{$team}_name"]])
            . ($playerNumber ? " (#{$playerNumber})" : '');

        $this->insertSideEvent($matchId, $match, 'card', $team, $label, ['colour' => $colour, 'player' => $playerNumber]);

        return $this->find($matchId);
    }

    public function recordSubstitution(int $matchId, string $team, ?int $playerOff, ?int $playerOn): array
    {
        $match = $this->find($matchId);
        $label = __('msg.substitution', ['team' => $match["{$team}_name"]]) . ($playerOff && $playerOn ? " (#{$playerOff} \u{2192} #{$playerOn})" : '');

        $this->insertSideEvent($matchId, $match, 'substitution', $team, $label, ['player_off' => $playerOff, 'player_on' => $playerOn]);

        return $this->find($matchId);
    }

    private function insertSideEvent(int $matchId, array $match, string $kind, string $team, string $label, array $meta): void
    {
        $matchSeconds = $this->currentMatchSeconds($match);
        $context = $match['state_context'];

        $stmt = $this->db->prepare(
            'INSERT INTO events (match_id, match_seconds, kind, team, label, meta, context_before, context_after)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$matchId, $matchSeconds, $kind, $team, $label, json_encode($meta), $context, $context]);
    }

    private function logClockEvent(int $matchId, int $matchSeconds, string $label): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO events (match_id, match_seconds, kind, label) VALUES (?, ?, \'clock\', ?)'
        );
        $stmt->execute([$matchId, $matchSeconds, $label]);
    }

    /**
     * Struttura condivisa da tutti gli endpoint che restituiscono "lo stato della partita":
     * punteggio, cronometro, stato corrente con azioni disponibili, etichetta per l'Undo.
     */
    public function toPayload(array $match): array
    {
        $context = json_decode($match['state_context'], true) ?: [];
        $advantage = null;
        if (isset($context['advantage'])) {
            $started = new \DateTime($context['advantage']['started_at'], new \DateTimeZone('UTC'));
            $now = new \DateTime('now', new \DateTimeZone('UTC'));
            $advantage = [
                'team' => $context['advantage']['team'],
                'seconds' => max(0, $now->getTimestamp() - $started->getTimestamp()),
            ];
        }

        return [
            'id' => (int) $match['id'],
            'home_name' => $match['home_name'],
            'home_color' => $match['home_color'],
            'away_name' => $match['away_name'],
            'away_color' => $match['away_color'],
            'home_score' => (int) $match['home_score'],
            'away_score' => (int) $match['away_score'],
            'status' => $match['status'],
            'half' => (int) $match['half'],
            'clock_running' => (bool) $match['clock_running'],
            'match_seconds' => $this->currentMatchSeconds($match),
            'video_url' => $match['video_url'],
            'video' => VideoSource::describe($match['video_url'], (int) $match['id']),
            'video_offset' => $match['video_offset'] !== null ? (float) $match['video_offset'] : null,
            'advantage' => $advantage,
            'current' => $this->describeState($match),
            'undo_label' => $this->lastEventLabel((int) $match['id']),
        ];
    }

    /** Istante corrente nel formato con cui SQLite salva i timestamp (UTC). */
    private function nowUtc(): string
    {
        return (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    public function currentMatchSeconds(array $match): int
    {
        $base = (int) $match['match_seconds'];

        if (!$match['clock_running'] || !$match['clock_started_at']) {
            return $base;
        }

        $started = new \DateTime($match['clock_started_at'], new \DateTimeZone('UTC'));
        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        return $base + max(0, $now->getTimestamp() - $started->getTimestamp());
    }
}
