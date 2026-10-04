<?php

/**
 * Converte una partita registrata con "Rugby Tagger" (l'altro tagger, file JSON con
 * state.events in stile Opta) in un file di export di Rugby State Machine, importabile dal menu:
 *
 *   php tools/convert-rugby-tagger.php <partita-rugby-tagger.json> <uscita.json> [nome casa] [nome ospiti]
 *
 * La sorgente registra azioni sparse (prese, placcaggi, calci, sequenze); qui serve invece una
 * sequenza di transizioni legali del grafo. Per questo la conversione PILOTA il motore
 * (StateMachine): ogni evento rilevante diventa l'azione valida nello stato corrente e, quando
 * la sorgente salta un passaggio (es. chi raccoglie un calcio), lo si deduce dall'evento
 * successivo. Ogni evento generato e' quindi una mossa del grafo: undo e statistiche funzionano.
 *
 * Il cronometro segue il video come nella modalita' split: secondi di gioco = tempo video meno
 * l'offset fissato al calcio d'inizio di ciascun tempo.
 */

require __DIR__ . '/../bootstrap.php';

use Rugby\MatchArchive;
use Rugby\StateMachine;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Profondita' (in metri dalla linea di meta) rappresentata dal widget dei calci ai pali.
const KICK_MAP_DEPTH_METERS = 50;
// Larghezza del campo nelle coordinate della sorgente (metriY).
const SOURCE_PITCH_WIDTH = 70;
// Lunghezza tra le linee di meta nelle coordinate della sorgente (metriX).
const SOURCE_PITCH_LENGTH = 100;
// Secondi tra l'ultimo evento del primo tempo e il fischio dell'intervallo, e dopo l'ultimo evento per la fine.
const WHISTLE_DELAY_SECONDS = 20;
// Stati in cui il possesso e' "in aria" (calci, ripartenze): lo decide la presa successiva.
const LOOSE_BALL_STATES = ['kickoff', 'dropout_22', 'dropout_tryline', 'kicked_infield', 'penalty_goal_result', 'drop_kick_result', 'charge_down'];

[$script, $input, $output] = array_pad($argv, 3, null);
if ($input === null || $output === null) {
    fwrite(STDERR, "Uso: php tools/convert-rugby-tagger.php <partita.json> <uscita.json> [nome casa] [nome ospiti]\n");
    exit(1);
}

$source = json_decode((string) @file_get_contents($input), true);
if (!is_array($source) || !isset($source['state']['events'], $source['teamsData']['A'], $source['teamsData']['B'])) {
    fwrite(STDERR, "File non riconosciuto: mancano state.events o teamsData.\n");
    exit(1);
}

$converter = new RugbyTaggerConverter($source, $argv[3] ?? null, $argv[4] ?? null);
$archive = $converter->convert();

file_put_contents($output, json_encode($archive, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$match = $archive['matches'][0]['match'];
printf(
    "%s %d - %d %s | %d eventi | %d avvisi | scritto %s\n",
    $match['home_name'],
    $match['home_score'],
    $match['away_score'],
    $match['away_name'],
    count($archive['matches'][0]['events']),
    count($converter->warnings),
    $output
);
foreach ($converter->warnings as $warning) {
    fwrite(STDERR, "  avviso: {$warning}\n");
}

/**
 * Traduttore evento per evento. Tiene una riga "partita" in memoria (stato, contesto, punteggio)
 * e la fa avanzare con StateMachine::applyAction, come farebbe MatchRepository.
 */
class RugbyTaggerConverter
{
    /** @var string[] */
    public array $warnings = [];

    private StateMachine $engine;
    private array $match;
    private array $events = [];
    private array $sourceEvents;

    private float $videoOffset = 0.0;
    private int $frozenSeconds = 0;
    private bool $secondHalf = false;

    /** Squadra a cui la sorgente ha assegnato la punizione in attesa di decisione (calcio, touche...). */
    private ?string $penaltyPendingFor = null;

    public function __construct(private array $source, ?string $homeName, ?string $awayName)
    {
        $this->engine = new StateMachine();
        $teams = $source['teamsData'];

        $this->match = [
            'home_name' => $homeName ?? $teams['A']['name'],
            'home_color' => $teams['A']['color'] ?? '#c0392b',
            'away_name' => $awayName ?? $teams['B']['name'],
            'away_color' => $teams['B']['color'] ?? '#2854c7',
            'current_state' => 'kickoff_choice',
            'state_context' => '{}',
            'home_score' => 0,
            'away_score' => 0,
        ];

        $this->sourceEvents = $this->sortedEvents($source['state']['events']);
    }

    public function convert(): array
    {
        foreach ($this->sourceEvents as $index => $event) {
            $this->handle($event, $index);
        }

        $last = end($this->sourceEvents);
        $this->clockEvent($this->seconds($last['videoTime']) + WHISTLE_DELAY_SECONDS, __('msg.full_time'));

        $video = $this->source['state']['videoSource'] ?? [];
        $match = $this->match + [
            'status' => 'full_time',
            'possession' => null,
            'match_seconds' => end($this->events)['match_seconds'],
            'clock_running' => 0,
            'clock_started_at' => null,
            'half' => 2,
            'video_url' => $video['url'] ?? null,
            'video_offset' => $this->videoOffset,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        return [
            'format' => MatchArchive::FORMAT,
            'version' => MatchArchive::VERSION,
            'app_version' => APP_VERSION,
            'exported_at' => gmdate('Y-m-d H:i:s'),
            'matches' => [['match' => $match, 'events' => $this->events]],
        ];
    }

    // ---------- Smistamento degli eventi della sorgente ----------

    private function handle(array $event, int $index): void
    {
        if ((int) ($event['period'] ?? 1) === 2 && !$this->secondHalf) {
            $this->startSecondHalf($event);
        }

        $team = $event['teamKey'] === 'A' ? 'home' : 'away';
        $how = (string) $event['how'];

        match (true) {
            $event['what'] === 'Restart' => $this->restart($team, $event),
            $event['what'] === 'Lineout Throw' => $this->lineoutThrow($team, $how, $event),
            $event['what'] === 'Lineout Take' => $this->lineoutTake($team, $event),
            $event['what'] === 'Scrum' => $this->scrum($team, $event),
            $event['what'] === 'Penalty Conceded' => $this->penaltyConceded($team, $how, $event),
            $event['what'] === 'Goal Kick' => $this->goalKick($team, $how, $event),
            $event['what'] === 'Try' => $this->try($team, $event),
            $event['what'] === 'Maul' => $this->maul($team, $event),
            $event['what'] === 'Turnover' => $this->turnover($team, $how, $event, $index),
            $event['what'] === 'Kick' => $this->kick($team, $how, $event),
            $event['what'] === 'Catch' => $this->catchBall($team, $event),
            default => null,
        };
    }

    private function restart(string $team, array $event): void
    {
        if ($this->state() === 'kickoff_choice') {
            $this->act($team === 'home' ? 'choice_home' : 'choice_away', $event);
            return;
        }

        if ($this->state() !== 'kickoff') {
            $this->warn($event, 'calcio d\'inizio fuori sequenza (stato ' . $this->state() . '), ignorato');
            return;
        }

        if ($this->subject() !== $team) {
            $this->warn($event, 'la sorgente fa ripartire l\'altra squadra rispetto alla Law 12');
        }
    }

    private function lineoutThrow(string $team, string $how, array $event): void
    {
        if ($this->state() !== 'lineout' || $this->subject() !== $team) {
            // Il pallone e' uscito: l'ha mandato in touche l'altra squadra.
            $this->settle($this->other($team), $event);
            $this->act('ball_in_touch', $event);
        }

        if (str_contains($how, 'Quick')) {
            $this->act('quick_lineout', $event);
        }
    }

    private function lineoutTake(string $team, array $event): void
    {
        if ($this->state() === 'lineout') {
            $this->act($team === $this->subject() ? 'won_subject' : 'won_other', $event);
        }
    }

    private function scrum(string $team, array $event): void
    {
        // La sorgente registra la stessa mischia su piu' righe: si usa solo la prima.
        if ($this->state() === 'scrum' && $this->subject() === $team) {
            return;
        }

        if ($this->state() === 'penalty_option' && $this->subject() === $team) {
            $this->penaltyPendingFor = null;
            $this->act('scrum_option', $event);
            return;
        }

        $this->settle($this->other($team), $event);
        // Mischia all'altra squadra: l'origine piu' comune e' un avanti di chi aveva il pallone.
        $this->act('knock_on', $event);
    }

    private function penaltyConceded(string $offender, string $how, array $event): void
    {
        $awarded = $this->other($offender);

        if ($this->state() === 'penalty_option' && $this->subject() === $awarded) {
            return; // stessa punizione registrata due volte
        }

        if (in_array($this->state(), ['scrum', 'lineout', 'kicked_infield'], true)) {
            $this->act($this->subject() === $awarded ? 'penalty_subject' : 'penalty_other', $event);
        } else {
            $this->settle($awarded, $event);
            $this->act('penalty', $event);
        }

        $this->act($this->penaltyCause($how), $event);
        $this->penaltyPendingFor = $awarded;
    }

    private function goalKick(string $team, string $how, array $event): void
    {
        $scored = str_contains($how, 'Goal Kicked');
        $meta = $this->kickPosition($event);

        if (str_contains($how, 'Conversion')) {
            if ($this->state() !== 'conversion') {
                $this->warn($event, 'trasformazione senza meta, ignorata');
                return;
            }
            $this->act($scored ? 'converted' : 'conv_missed', $event, $meta);
            return;
        }

        if (str_contains($how, 'Drop Goal')) {
            $this->settle($team, $event);
            $this->act('drop_kick', $event);
            if ($scored) {
                $this->act('goal_scored', $event, $meta);
            }
            return;
        }

        // Piazzato: serve una punizione a favore di chi calcia.
        if ($this->state() !== 'penalty_option' || $this->subject() !== $team) {
            $this->settle($team, $event);
            $this->act('penalty', $event);
            $this->act('unknown', $event);
        }

        $this->penaltyPendingFor = null;
        $this->act('kick_at_goal', $event);
        if ($scored) {
            $this->act('scored', $event, $meta);
        }
    }

    private function try(string $team, array $event): void
    {
        $this->settle($team, $event);
        $this->act('try', $event);
    }

    private function maul(string $team, array $event): void
    {
        if ($this->penaltyPendingFor !== null) {
            return;
        }
        $this->settle($team, $event);
        $this->act('maul_formed', $event);
    }

    private function turnover(string $loser, string $how, array $event, int $index): void
    {
        // Una punizione e' in attesa: il gioco successivo e' il vantaggio, non cambia l'esito.
        if ($this->penaltyPendingFor !== null) {
            return;
        }

        $this->settle($loser, $event);

        // Pallone perso in avanti seguito da una mischia all'altra squadra: e' un avanti.
        $next = $this->nextSequence($index);
        if (str_contains($how, 'Dropped Ball') && $next !== null && $next['what'] === 'Scrum'
            && ($next['teamKey'] === 'A' ? 'home' : 'away') === $this->other($loser)) {
            $this->act('knock_on', $event);
            return;
        }

        $this->act('turnover', $event);
    }

    private function kick(string $team, string $how, array $event): void
    {
        $toTouch = str_contains($how, 'Touch Kick') || str_contains($how, 'Kick In Touch');

        if ($this->penaltyPendingFor === $team && $this->state() === 'penalty_option') {
            $this->penaltyPendingFor = null;
            $this->act($toTouch ? 'kick_to_touch' : 'tap_and_go', $event);
            if ($toTouch) {
                return;
            }
        }

        $this->settle($team, $event);
        $this->act('kick', $event);
        if ($toTouch) {
            $this->act('in_touch', $event);
        }
    }

    private function catchBall(string $team, array $event): void
    {
        // Le prese servono solo a decidere chi raccoglie calci e ripartenze.
        if (in_array($this->state(), LOOSE_BALL_STATES, true)) {
            $this->settle($team, $event);
        }
    }

    // ---------- Gestione degli stati ----------

    /**
     * Porta la partita in gioco aperto con il pallone a $team, usando l'azione che chiude lo
     * stato corrente (chi raccoglie il calcio, chi vince la mischia...) o un turnover.
     */
    private function settle(string $team, array $event): void
    {
        $isSubject = $this->subject() === $team;

        $closing = match ($this->state()) {
            'general_play' => $isSubject ? null : 'turnover',
            'kickoff', 'dropout_22', 'dropout_tryline' => $isSubject ? 'regather' : 'catch',
            'scrum', 'lineout' => $isSubject ? 'won_subject' : 'won_other',
            'kicked_infield' => $isSubject ? 'caught_subject' : 'caught_other',
            'penalty_goal_result', 'drop_kick_result' => $isSubject ? 'missed_regathered' : 'missed_caught',
            'charge_down' => $isSubject ? 'gathered_subject' : 'gathered_other',
            'penalty_option', 'free_kick_option' => 'tap_and_go',
            default => false,
        };

        if ($closing === false) {
            $this->warn($event, 'impossibile riprendere il gioco dallo stato ' . $this->state());
            return;
        }

        if ($closing !== null) {
            $this->penaltyPendingFor = null;
            $this->act($closing, $event);
        }

        // Dopo una battuta veloce il pallone e' di chi ha battuto: se serve all'altra squadra, turnover.
        if ($this->state() === 'general_play' && $this->subject() !== $team) {
            $this->act('turnover', $event);
        }
    }

    private function startSecondHalf(array $event): void
    {
        $previous = end($this->events);
        $halfTime = $previous['match_seconds'] + WHISTLE_DELAY_SECONDS;
        $this->clockEvent($halfTime, __('msg.half_time'));

        $this->secondHalf = true;
        $this->frozenSeconds = $halfTime;
        $this->videoOffset = (float) $event['videoTime'] - $halfTime;
        $this->match['current_state'] = 'kickoff_choice';
        $this->match['state_context'] = '{}';
        $this->penaltyPendingFor = null;
    }

    /**
     * Applica un'azione con il motore e registra l'evento come farebbe MatchRepository::applyAction.
     */
    private function act(string $actionId, array $event, array $meta = []): void
    {
        if ($this->videoOffset === 0.0 && !$this->secondHalf) {
            $this->videoOffset = (float) $event['videoTime'];
        }

        $transition = $this->engine->applyAction($this->match, $actionId, $meta);

        if ($transition['points'] > 0 && $transition['team'] !== null) {
            $this->match[$transition['team'] . '_score'] += $transition['points'];
        }

        $this->match['current_state'] = $transition['state_after'];
        $this->match['state_context'] = json_encode($transition['context_after']);

        $this->events[] = [
            'match_seconds' => $this->seconds($event['videoTime']),
            'kind' => 'state',
            'state_before' => $transition['state_before'],
            'state_after' => $transition['state_after'],
            'action_id' => $transition['action_id'],
            'team' => $transition['team'],
            'label' => $transition['label'],
            'points' => $transition['points'],
            'meta' => json_encode((object) $transition['meta']),
            'context_before' => json_encode((object) $transition['context_before']),
            'context_after' => json_encode((object) $transition['context_after']),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    private function clockEvent(int $matchSeconds, string $label): void
    {
        $this->events[] = [
            'match_seconds' => $matchSeconds,
            'kind' => 'clock',
            'state_before' => null,
            'state_after' => null,
            'action_id' => null,
            'team' => null,
            'label' => $label,
            'points' => 0,
            'meta' => '{}',
            'context_before' => '{}',
            'context_after' => '{}',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    // ---------- Supporto ----------

    private function state(): string
    {
        return $this->match['current_state'];
    }

    private function subject(): ?string
    {
        return json_decode($this->match['state_context'], true)['subject'] ?? null;
    }

    private function other(string $team): string
    {
        return $team === 'home' ? 'away' : 'home';
    }

    private function seconds(float $videoTime): int
    {
        return max($this->frozenSeconds, (int) floor($videoTime - $this->videoOffset));
    }

    /**
     * Ordine cronologico per tempo video; a parita' di tempo prima le sequenze (calci d'inizio,
     * mischie...) e poi le azioni, che di solito ne sono la conseguenza.
     */
    private function sortedEvents(array $events): array
    {
        $order = array_keys($events);
        usort($order, fn (int $a, int $b) => [$events[$a]['videoTime'], $events[$a]['type'] === 'sequence' ? 0 : 1, $a]
            <=> [$events[$b]['videoTime'], $events[$b]['type'] === 'sequence' ? 0 : 1, $b]);

        return array_map(fn (int $i) => $events[$i], $order);
    }

    private function nextSequence(int $index): ?array
    {
        for ($i = $index + 1, $n = count($this->sourceEvents); $i < $n; $i++) {
            if ($this->sourceEvents[$i]['type'] === 'sequence') {
                return $this->sourceEvents[$i];
            }
        }

        return null;
    }

    private function penaltyCause(string $how): string
    {
        return match (true) {
            str_contains($how, 'Offside') => 'offside',
            str_contains($how, 'Not Releasing') => 'tackler_not_releasing',
            str_contains($how, 'Not Rolling Away'), str_contains($how, 'Ruck') => 'ruck_offence',
            str_contains($how, 'Obstruction') => 'obstruction',
            str_contains($how, 'High Tackle'), str_contains($how, 'Dangerous') => 'dangerous_tackle',
            str_contains($how, 'Foul Play') => 'violent_foul_play',
            default => 'unknown',
        };
    }

    /**
     * Posizione del calcio sul widget dei calci ai pali: x = larghezza del campo (0-100%),
     * y = distanza dalla linea di meta su KICK_MAP_DEPTH_METERS metri (0% = linea dei pali).
     */
    private function kickPosition(array $event): array
    {
        $position = $event['position'] ?? null;
        if (!isset($position['metriX'], $position['metriY'])) {
            return [];
        }

        $fromGoalLine = $position['metriX'] >= SOURCE_PITCH_LENGTH / 2
            ? SOURCE_PITCH_LENGTH - $position['metriX']
            : $position['metriX'];

        return [
            'x' => round(max(0, min(100, $position['metriY'] / SOURCE_PITCH_WIDTH * 100)), 1),
            'y' => round(max(0, min(100, $fromGoalLine / KICK_MAP_DEPTH_METERS * 100)), 1),
        ];
    }

    private function warn(array $event, string $message): void
    {
        $this->warnings[] = "{$event['when']} {$event['what']} ({$event['teamKey']}): {$message}";
    }
}
