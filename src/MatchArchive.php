<?php

namespace Rugby;

/**
 * Export/import di partite in un file JSON: la partita (riga di `matches`) con tutto il suo
 * storico eventi, che e' la fonte di verita' per punteggio, statistiche e undo.
 *
 * Formato:
 *   { "format": "rugby-tagger-matches", "version": 1, "exported_at": "...",
 *     "matches": [ { "match": {...campi MATCH_FIELDS}, "events": [ {...campi EVENT_FIELDS}, ... ] } ] }
 *
 * Gli id non vengono esportati: all'import ogni partita riceve un id nuovo, quindi importare
 * lo stesso file due volte crea due copie.
 */
class MatchArchive
{
    public const FORMAT = 'rugby-tagger-matches';
    public const VERSION = 1;

    private const MATCH_FIELDS = [
        'home_name', 'home_color', 'away_name', 'away_color', 'status', 'home_score', 'away_score',
        'possession', 'current_state', 'state_context', 'match_seconds', 'clock_running',
        'clock_started_at', 'half', 'video_url', 'video_offset', 'created_at', 'updated_at',
    ];

    private const EVENT_FIELDS = [
        'match_seconds', 'kind', 'state_before', 'state_after', 'action_id', 'team', 'label',
        'points', 'meta', 'context_before', 'context_after', 'created_at',
    ];

    // Stessi vincoli dei CHECK di schema.sql: validati qui per dare errori leggibili.
    private const STATUSES = ['setup', 'live', 'half_time', 'full_time'];
    private const EVENT_KINDS = ['state', 'card', 'substitution', 'clock'];
    private const TEAMS = ['home', 'away'];

    private MatchRepository $matches;
    private EventRepository $events;

    public function __construct()
    {
        $this->matches = new MatchRepository();
        $this->events = new EventRepository();
    }

    /**
     * Costruisce l'archivio delle partite indicate.
     *
     * Il cronometro viene "congelato": se una partita e' in corso si esportano i secondi maturati
     * e il cronometro fermo, altrimenti all'import il tempo trascorso dall'export si sommerebbe al gioco.
     *
     * @param int[] $matchIds partite da esportare (almeno una)
     * @return array archivio pronto per json_encode
     * @throws \InvalidArgumentException se l'elenco e' vuoto o una partita non esiste
     */
    public function export(array $matchIds): array
    {
        if ($matchIds === []) {
            throw new \InvalidArgumentException(__('err.no_match_selected'));
        }

        $entries = [];
        foreach ($matchIds as $matchId) {
            $match = $this->matches->find($matchId);
            if ($match === null) {
                throw new \InvalidArgumentException(__('err.match_id_not_found', ['id' => $matchId]));
            }

            $match['match_seconds'] = $this->matches->currentMatchSeconds($match);
            $match['clock_running'] = 0;
            $match['clock_started_at'] = null;

            $entries[] = [
                'match' => $this->pick($match, self::MATCH_FIELDS),
                'events' => array_map(
                    fn (array $event) => $this->pick($event, self::EVENT_FIELDS),
                    $this->events->forMatch($matchId)
                ),
            ];
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => gmdate('Y-m-d H:i:s'),
            'matches' => $entries,
        ];
    }

    /**
     * Valida l'archivio e importa tutte le partite, tutte o nessuna (unica transazione).
     *
     * @param array $archive contenuto del file JSON decodificato
     * @param int $importedBy utente che importa: diventa il creatore delle partite (gli id utente
     *                        del sito di origine non hanno significato qui, quindi non vengono esportati)
     * @return int[] id delle partite create
     * @throws \InvalidArgumentException con il motivo, se l'archivio non e' valido
     */
    public function import(array $archive, int $importedBy): array
    {
        if (($archive['format'] ?? null) !== self::FORMAT) {
            throw new \InvalidArgumentException(__('err.import_not_rugby'));
        }

        $version = $archive['version'] ?? null;
        if (!is_int($version) || $version < 1 || $version > self::VERSION) {
            throw new \InvalidArgumentException(__('err.import_version', ['version' => json_encode($version)]));
        }

        $entries = $archive['matches'] ?? null;
        if (!is_array($entries) || $entries === []) {
            throw new \InvalidArgumentException(__('err.import_no_matches'));
        }

        $states = array_keys(States::graph());
        $normalized = [];
        foreach (array_values($entries) as $index => $entry) {
            $where = __('err.import_where_match', ['n' => $index + 1]);
            if (!is_array($entry) || !is_array($entry['match'] ?? null) || !is_array($entry['events'] ?? null)) {
                throw new \InvalidArgumentException(__('err.import_structure', ['where' => $where]));
            }

            $normalized[] = [
                'match' => $this->normalizeMatch($entry['match'], $states, $where) + ['created_by' => $importedBy],
                'events' => array_map(
                    fn (array $event) => $this->normalizeEvent($event, $states, $where),
                    $this->assertListOfObjects($entry['events'], __('err.import_where_events', ['where' => $where]))
                ),
            ];
        }

        return $this->matches->importMatches($normalized);
    }

    // ---------- Validazione ----------

    private function normalizeMatch(array $match, array $states, string $where): array
    {
        $now = gmdate('Y-m-d H:i:s');

        return [
            'home_name' => $this->requireText($match, 'home_name', $where),
            'home_color' => $this->requireText($match, 'home_color', $where),
            'away_name' => $this->requireText($match, 'away_name', $where),
            'away_color' => $this->requireText($match, 'away_color', $where),
            'status' => $this->requireOneOf($match, 'status', self::STATUSES, $where),
            'home_score' => $this->requireNonNegativeInt($match, 'home_score', $where),
            'away_score' => $this->requireNonNegativeInt($match, 'away_score', $where),
            'possession' => $this->optionalOneOf($match, 'possession', self::TEAMS, $where),
            'current_state' => $this->requireOneOf($match, 'current_state', $states, $where),
            'state_context' => $this->requireJsonText($match, 'state_context', $where),
            'match_seconds' => $this->requireNonNegativeInt($match, 'match_seconds', $where),
            // Mai un cronometro in corso: vedi export().
            'clock_running' => 0,
            'clock_started_at' => null,
            'half' => $this->requireOneOf($match, 'half', [1, 2], $where),
            'video_url' => $this->optionalText($match, 'video_url', $where),
            'video_offset' => $this->optionalNumber($match, 'video_offset', $where),
            'created_at' => $this->optionalText($match, 'created_at', $where) ?? $now,
            'updated_at' => $this->optionalText($match, 'updated_at', $where) ?? $now,
        ];
    }

    private function normalizeEvent(array $event, array $states, string $where): array
    {
        $where = __('err.import_where_event', ['where' => $where]);
        $kind = $this->requireOneOf($event, 'kind', self::EVENT_KINDS, $where);

        // Gli eventi di stato servono all'undo: devono puntare a stati che esistono nel grafo attuale.
        $stateRule = fn (string $field) => $kind === 'state'
            ? $this->requireOneOf($event, $field, $states, $where)
            : $this->optionalOneOf($event, $field, $states, $where);

        return [
            'match_seconds' => $this->requireNonNegativeInt($event, 'match_seconds', $where),
            'kind' => $kind,
            'state_before' => $stateRule('state_before'),
            'state_after' => $stateRule('state_after'),
            'action_id' => $this->optionalText($event, 'action_id', $where),
            'team' => $this->optionalOneOf($event, 'team', self::TEAMS, $where),
            'label' => $this->requireText($event, 'label', $where),
            'points' => $this->requireNonNegativeInt($event, 'points', $where),
            'meta' => $this->requireJsonText($event, 'meta', $where),
            'context_before' => $this->requireJsonText($event, 'context_before', $where),
            'context_after' => $this->requireJsonText($event, 'context_after', $where),
            'created_at' => $this->optionalText($event, 'created_at', $where) ?? gmdate('Y-m-d H:i:s'),
        ];
    }

    private function assertListOfObjects(array $items, string $where): array
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException(__('err.import_item', ['where' => $where]));
            }
        }

        return array_values($items);
    }

    private function requireText(array $data, string $field, string $where): string
    {
        $value = $data[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(__('err.import_field_missing', ['where' => $where, 'field' => $field]));
        }

        return $value;
    }

    private function optionalText(array $data, string $field, string $where): ?string
    {
        $value = $data[$field] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new \InvalidArgumentException(__('err.import_field_text', ['where' => $where, 'field' => $field]));
        }

        return $value;
    }

    private function requireNonNegativeInt(array $data, string $field, string $where): int
    {
        $value = $data[$field] ?? null;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \InvalidArgumentException(__('err.import_field_int', ['where' => $where, 'field' => $field]));
        }

        $value = (int) $value;
        if ($value < 0) {
            throw new \InvalidArgumentException(__('err.import_field_int', ['where' => $where, 'field' => $field]));
        }

        return $value;
    }

    private function optionalNumber(array $data, string $field, string $where): ?float
    {
        $value = $data[$field] ?? null;
        if ($value === null) {
            return null;
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(__('err.import_field_number', ['where' => $where, 'field' => $field]));
        }

        return (float) $value;
    }

    private function requireOneOf(array $data, string $field, array $allowed, string $where): string|int
    {
        $value = $data[$field] ?? null;
        // SQLite puo' restituire gli interi come stringhe: '2' vale come 2.
        foreach ($allowed as $candidate) {
            if ($value === $candidate || (is_int($candidate) && $value === (string) $candidate)) {
                return $candidate;
            }
        }

        throw new \InvalidArgumentException(__('err.import_field_value', ['where' => $where, 'field' => $field, 'value' => json_encode($value)]));
    }

    private function optionalOneOf(array $data, string $field, array $allowed, string $where): string|int|null
    {
        return ($data[$field] ?? null) === null ? null : $this->requireOneOf($data, $field, $allowed, $where);
    }

    private function requireJsonText(array $data, string $field, string $where): string
    {
        $value = $this->requireText($data, $field, $where);
        json_decode($value);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException(__('err.import_field_json', ['where' => $where, 'field' => $field]));
        }

        return $value;
    }

    private function pick(array $row, array $fields): array
    {
        $picked = [];
        foreach ($fields as $field) {
            $picked[$field] = $row[$field] ?? null;
        }

        return $picked;
    }
}
