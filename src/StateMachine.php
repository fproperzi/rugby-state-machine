<?php

namespace Rugby;

/**
 * Motore che applica le transizioni del grafo in States::graph() a una partita.
 * Non tocca il DB direttamente: riceve ed restituisce semplici array associativi,
 * cosi' resta testabile senza bisogno di SQLite.
 */
class StateMachine
{
    private array $graph;

    public function __construct()
    {
        $this->graph = States::graph();
    }

    /**
     * Restituisce lo stato corrente risolto per la UI: titolo e azioni con etichette
     * e colori gia' calcolati rispetto a home/away.
     *
     * @param array $match Riga match (home_name, away_name, current_state, state_context...)
     */
    public function describeCurrentState(array $match): array
    {
        $stateId = $match['current_state'];
        $state = $this->graph[$stateId] ?? null;

        if ($state === null) {
            throw new \RuntimeException(__('err.unknown_state', ['state' => $stateId]));
        }

        $context = json_decode($match['state_context'], true) ?: [];
        $subject = $context['subject'] ?? null;
        $other = $this->flip($subject);

        $names = [
            'subject' => $subject ? $match["{$subject}_name"] : null,
            'other' => $other ? $match["{$other}_name"] : null,
            'home' => $match['home_name'],
            'away' => $match['away_name'],
        ];

        $actions = array_map(function (array $action) use ($subject, $other, $names) {
            return [
                'id' => $action['id'],
                'label' => $this->resolveLabel($action['label'], $names),
                'team' => $this->resolveRole($action['team'] ?? null, $subject, $other),
            ];
        }, $state['actions']);

        return [
            'state' => $stateId,
            'title' => $this->resolveLabel($state['title'], $names),
            'subject' => $subject,
            'actions' => $actions,
        ];
    }

    /**
     * Applica un'azione allo stato corrente e restituisce il risultato della transizione:
     * nuovo stato, nuovo contesto, squadra/punti/etichetta dell'evento da loggare.
     *
     * @throws \InvalidArgumentException se l'azione non esiste nello stato corrente
     */
    public function applyAction(array $match, string $actionId, array $extraMeta = []): array
    {
        $stateId = $match['current_state'];
        $state = $this->graph[$stateId] ?? null;

        if ($state === null) {
            throw new \RuntimeException(__('err.unknown_state', ['state' => $stateId]));
        }

        $action = null;
        foreach ($state['actions'] as $candidate) {
            if ($candidate['id'] === $actionId) {
                $action = $candidate;
                break;
            }
        }

        if ($action === null) {
            throw new \InvalidArgumentException(__('err.invalid_action', ['action' => $actionId, 'state' => $stateId]));
        }

        $contextBefore = json_decode($match['state_context'], true) ?: [];
        $subject = $contextBefore['subject'] ?? null;
        $other = $this->flip($subject);

        $names = [
            'subject' => $subject ? $match["{$subject}_name"] : null,
            'other' => $other ? $match["{$other}_name"] : null,
            'home' => $match['home_name'],
            'away' => $match['away_name'],
        ];

        $team = $this->resolveRole($action['team'] ?? null, $subject, $other);
        $nextSubject = $this->resolveRole($action['next_subject'] ?? null, $subject, $other);

        // Il contesto si eredita (es. un vantaggio in corso) e si aggiorna solo il soggetto.
        $contextAfter = array_merge($contextBefore, ['subject' => $nextSubject]);

        return [
            'state_before' => $stateId,
            'state_after' => $action['next'],
            'context_before' => $contextBefore,
            'context_after' => $contextAfter,
            'action_id' => $actionId,
            'team' => $team,
            'label' => $this->resolveLabel($action['label'], $names),
            'points' => $action['points'] ?? 0,
            'meta' => array_merge($action['meta'] ?? [], $extraMeta),
        ];
    }

    /**
     * Risolve un ruolo dichiarativo ('subject'|'other'|'home'|'away') nella squadra reale.
     */
    private function resolveRole(?string $role, ?string $subject, ?string $other): ?string
    {
        return match ($role) {
            'subject' => $subject,
            'other' => $other,
            'home', 'away' => $role,
            default => null,
        };
    }

    private function flip(?string $team): ?string
    {
        if ($team === null) {
            return null;
        }

        return $team === 'home' ? 'away' : 'home';
    }

    /**
     * Traduce il template del grafo nella lingua corrente e sostituisce i nomi delle squadre.
     * Le etichette degli eventi si salvano gia' risolte: restano nella lingua usata al momento del tagging.
     */
    private function resolveLabel(string $template, array $names): string
    {
        return strtr(I18n::graph($template), [
            '{SUBJECT}' => $names['subject'] ?? '',
            '{OTHER}' => $names['other'] ?? '',
            '{HOME}' => $names['home'],
            '{AWAY}' => $names['away'],
        ]);
    }
}
