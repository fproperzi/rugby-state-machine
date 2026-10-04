<?php

namespace Rugby;

/**
 * Letture sullo storico eventi di una partita: usato dalla pagina statistiche e dallo scrubber.
 */
class EventRepository
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function forMatch(int $matchId, ?string $kind = null): array
    {
        if ($kind !== null) {
            $stmt = $this->db->prepare('SELECT * FROM events WHERE match_id = ? AND kind = ? ORDER BY id ASC');
            $stmt->execute([$matchId, $kind]);
        } else {
            $stmt = $this->db->prepare('SELECT * FROM events WHERE match_id = ? ORDER BY id ASC');
            $stmt->execute([$matchId]);
        }

        return $stmt->fetchAll();
    }

    /**
     * Timeline per lo scrubber: punteggio cumulativo e etichetta ad ogni evento,
     * cosi' il frontend puo' mostrare "cosa succedeva al minuto X" senza ricalcolare nulla.
     */
    public function timeline(int $matchId): array
    {
        $events = $this->forMatch($matchId);

        $homeScore = 0;
        $awayScore = 0;
        $timeline = [];

        foreach ($events as $event) {
            if ((int) $event['points'] > 0 && $event['team'] !== null) {
                if ($event['team'] === 'home') {
                    $homeScore += (int) $event['points'];
                } else {
                    $awayScore += (int) $event['points'];
                }
            }

            $timeline[] = [
                'match_seconds' => (int) $event['match_seconds'],
                'kind' => $event['kind'],
                'label' => $event['label'],
                'team' => $event['team'],
                'home_score' => $homeScore,
                'away_score' => $awayScore,
            ];
        }

        return $timeline;
    }
}
