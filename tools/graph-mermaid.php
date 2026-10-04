<?php

/**
 * Genera dal grafo (src/States.php) i diagrammi Mermaid usati nei README:
 *
 *   php tools/graph-mermaid.php graph   # flowchart completo degli stati, raggruppato per fase di gioco
 *   php tools/graph-mermaid.php depth   # tabella Markdown: profondita' di ogni stato dal calcio d'inizio
 *   php tools/graph-mermaid.php depth it
 *
 * La profondita' e' il numero minimo di azioni necessarie per raggiungere lo stato partendo da
 * 'kickoff_choice' (visita in ampiezza). Va rilanciato dopo ogni modifica al grafo.
 */

require __DIR__ . '/../bootstrap.php';

use Rugby\I18n;
use Rugby\States;

const START_STATE = 'kickoff_choice';

// Fase di gioco di ogni stato, solo per raggruppare i nodi nel diagramma.
const AREAS = [
    'Kick-off' => ['kickoff_choice', 'kickoff', 'kickoff_retake_option', 'kickoff_touch_option'],
    'Open play' => ['general_play', 'unplayable_ruck_option', 'kicked_infield', 'charge_down', 'kick_dead_option'],
    'Set pieces' => ['scrum', 'lineout', 'lineout_not_straight_option'],
    'Penalties & free kicks' => ['penalty_cause', 'penalty_option', 'free_kick_option', 'penalty_goal_result'],
    'Scoring' => ['conversion', 'drop_kick_result'],
    'Drop-outs' => [
        'dropout_22', 'dropout_22_short_option', 'dropout_22_touch_option',
        'dropout_tryline', 'dropout_tryline_short_option', 'dropout_tryline_touch_option',
    ],
];

$graph = States::graph();
$mode = $argv[1] ?? 'graph';
$lang = $argv[2] ?? 'en';
// Da riga di comando non c'e' un browser: la lingua dei titoli si sceglie come farebbe ?lang=.
$_GET['lang'] = $lang;

echo match ($mode) {
    'graph' => mermaidFlowchart($graph),
    'depth' => depthTable($graph, $lang),
    default => "Uso: php tools/graph-mermaid.php graph|depth [en|it]\n",
};

function mermaidFlowchart(array $graph): string
{
    $lines = ['```mermaid', 'flowchart TD'];

    $placed = [];
    foreach (AREAS as $area => $states) {
        $lines[] = sprintf('    subgraph %s["%s"]', preg_replace('/\W+/', '_', $area), $area);
        foreach ($states as $state) {
            if (isset($graph[$state])) {
                $lines[] = "        {$state}([{$state}])";
                $placed[$state] = true;
            }
        }
        $lines[] = '    end';
    }

    // Stati non classificati in AREAS: compaiono comunque, fuori dai gruppi.
    foreach (array_keys($graph) as $state) {
        if (!isset($placed[$state])) {
            $lines[] = "    {$state}([{$state}])";
        }
    }

    // Un arco per coppia di stati, con il numero di azioni che lo percorrono quando sono piu' d'una.
    $edges = [];
    foreach ($graph as $from => $state) {
        foreach ($state['actions'] as $action) {
            $edges["{$from}|{$action['next']}"] = ($edges["{$from}|{$action['next']}"] ?? 0) + 1;
        }
    }

    foreach ($edges as $pair => $count) {
        [$from, $to] = explode('|', $pair);
        $lines[] = $count > 1 ? "    {$from} -->|{$count}| {$to}" : "    {$from} --> {$to}";
    }

    $lines[] = '```';

    return implode("\n", $lines) . "\n";
}

function depthTable(array $graph, string $lang): string
{
    $depth = [START_STATE => 0];
    $queue = [START_STATE];
    while ($queue !== []) {
        $state = array_shift($queue);
        foreach ($graph[$state]['actions'] as $action) {
            if (!isset($depth[$action['next']])) {
                $depth[$action['next']] = $depth[$state] + 1;
                $queue[] = $action['next'];
            }
        }
    }

    asort($depth);
    $headers = $lang === 'it'
        ? ['Profondità', 'Stato', 'Azioni', 'Titolo']
        : ['Depth', 'State', 'Actions', 'Title'];

    $rows = ['| ' . implode(' | ', $headers) . ' |', '|---:|---|---:|---|'];
    foreach ($depth as $state => $level) {
        $rows[] = sprintf('| %d | `%s` | %d | %s |', $level, $state, count($graph[$state]['actions']), I18n::graph($graph[$state]['title']));
    }

    foreach (array_diff(array_keys($graph), array_keys($depth)) as $orphan) {
        $rows[] = sprintf('| — | `%s` | %d | %s |', $orphan, count($graph[$orphan]['actions']), $graph[$orphan]['title']);
    }

    return implode("\n", $rows) . "\n";
}
