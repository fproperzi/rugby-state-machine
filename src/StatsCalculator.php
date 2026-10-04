<?php

namespace Rugby;

/**
 * Calcola le statistiche aggregate di una partita a partire dallo storico eventi.
 * Nessuna query SQL qui dentro: riceve l'elenco eventi gia' caricato (ordine cronologico)
 * cosi' la logica resta testabile senza DB.
 */
class StatsCalculator
{
    private const KNOCK_ON_ACTION_IDS = [
        'knock_on', 'knock_on_ingoal', 'knockon_subject', 'knockon_other', 'knockedon_subject', 'knockedon_other',
    ];

    private const GOAL_ATTEMPT_STATES = ['penalty_goal_result', 'conversion', 'drop_kick_result'];

    /**
     * @param array $events eventi di kind='state', ordinati per id crescente
     * @param int $totalSeconds durata totale su cui calcolare il possesso (tempo di gioco corrente)
     */
    public function compute(array $events, int $totalSeconds): array
    {
        return [
            'possession' => $this->possession($events, $totalSeconds),
            'scores' => $this->scores($events),
            'kicks_at_goal' => $this->kicksAtGoal($events),
            'mauls' => $this->countByTeam($events, ['maul_formed']),
            'turnovers' => $this->countByTeam($events, ['turnover', 'interception']),
            'knock_ons' => $this->countByTeam($events, self::KNOCK_ON_ACTION_IDS),
            'kick_offs' => $this->kickOffStats($events),
        ];
    }

    private function possession(array $events, int $totalSeconds): array
    {
        $seconds = ['home' => 0, 'away' => 0];
        $cursorTime = 0;
        $cursorSubject = null;

        foreach ($events as $event) {
            if ($cursorSubject !== null) {
                $seconds[$cursorSubject] += max(0, $event['match_seconds'] - $cursorTime);
            }

            $context = json_decode($event['context_after'] ?? '{}', true) ?: [];
            $cursorSubject = $context['subject'] ?? $cursorSubject;
            $cursorTime = $event['match_seconds'];
        }

        if ($cursorSubject !== null) {
            $seconds[$cursorSubject] += max(0, $totalSeconds - $cursorTime);
        }

        $total = $seconds['home'] + $seconds['away'];
        if ($total === 0) {
            return ['home' => 0, 'away' => 0];
        }

        return [
            'home' => round($seconds['home'] / $total * 100),
            'away' => round($seconds['away'] / $total * 100),
        ];
    }

    private function scores(array $events): array
    {
        $scores = ['home' => [], 'away' => []];

        foreach ($events as $event) {
            if ((int) $event['points'] <= 0 || $event['team'] === null) {
                continue;
            }

            $scores[$event['team']][] = [
                'time' => $this->formatTime((int) $event['match_seconds']),
                'label' => $event['label'],
                'points' => (int) $event['points'],
            ];
        }

        return $scores;
    }

    private function kicksAtGoal(array $events): array
    {
        $result = [
            'home' => ['attempts' => 0, 'scored' => 0, 'positions' => []],
            'away' => ['attempts' => 0, 'scored' => 0, 'positions' => []],
        ];

        foreach ($events as $event) {
            if (!in_array($event['state_before'], self::GOAL_ATTEMPT_STATES, true)) {
                continue;
            }

            $context = json_decode($event['context_before'] ?? '{}', true) ?: [];
            $team = $context['subject'] ?? $event['team'];
            if ($team === null) {
                continue;
            }

            $scored = (int) $event['points'] > 0;
            $result[$team]['attempts']++;
            if ($scored) {
                $result[$team]['scored']++;
            }

            $meta = json_decode($event['meta'] ?? '{}', true) ?: [];
            if (isset($meta['x'], $meta['y'])) {
                $result[$team]['positions'][] = ['x' => $meta['x'], 'y' => $meta['y'], 'scored' => $scored];
            }
        }

        return $result;
    }

    private function kickOffStats(array $events): array
    {
        $result = [
            'home' => ['total' => 0, 'reclaimed' => 0],
            'away' => ['total' => 0, 'reclaimed' => 0],
        ];

        foreach ($events as $event) {
            if ($event['state_before'] !== 'kickoff') {
                continue;
            }

            $context = json_decode($event['context_before'] ?? '{}', true) ?: [];
            $team = $context['subject'] ?? null;
            if ($team === null) {
                continue;
            }

            $result[$team]['total']++;
            if ($event['action_id'] === 'regather') {
                $result[$team]['reclaimed']++;
            }
        }

        return $result;
    }

    private function countByTeam(array $events, array $actionIds): array
    {
        $counts = ['home' => 0, 'away' => 0];

        foreach ($events as $event) {
            if (in_array($event['action_id'], $actionIds, true) && $event['team'] !== null) {
                $counts[$event['team']]++;
            }
        }

        return $counts;
    }

    private function formatTime(int $seconds): string
    {
        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
