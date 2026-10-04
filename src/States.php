<?php

namespace Rugby;

/**
 * Grafo degli stati del tagger, in forma dichiarativa.
 *
 * Ogni stato ha un titolo (placeholder {SUBJECT}/{OTHER}/{HOME}/{AWAY}) e un elenco di azioni.
 * Il "soggetto" (subject) e' la squadra protagonista dello stato corrente (chi ha rimesso in
 * gioco, chi ha il possesso, chi batte la punizione...); "other" e' l'altra squadra. Usare questi
 * ruoli relativi invece di duplicare ogni stato per home/away e' cio' che tiene il grafo compatto.
 *
 * Ogni azione puo' definire:
 *  - team: ruolo a cui viene attribuito l'evento ('subject'|'other'|'home'|'away'|null)
 *  - next: id dello stato successivo
 *  - next_subject: ruolo che diventa il nuovo soggetto nello stato successivo
 *  - points: punti assegnati alla squadra indicata da `team`
 *  - meta: dati descrittivi aggiuntivi da loggare con l'evento (es. causa punizione)
 *
 * Penalty convention: "Penalty Awarded" means the penalty is WON by the team the button is
 * coloured with (and attributed to), as in the original app ("Penalty to X" in X's colour);
 * the offence was committed by the other team. That team becomes the subject of
 * penalty_cause/penalty_option, and penalty_cause events are attributed to it as well.
 * Free kicks follow the same rule ("Free Kick to {SUBJECT}" in the receiving team's colour).
 *
 * Rule references ("Law x.y") point to the World Rugby Laws of the Game, including the
 * amendments in force from 1 July 2026: https://passport.world.rugby/laws-of-the-game/
 */
class States
{
    public static function graph(): array
    {
        return [
            'kickoff_choice' => [
                'title' => 'Which team is kicking off?',
                'actions' => [
                    ['id' => 'choice_home', 'label' => '{HOME}', 'team' => 'home', 'next' => 'kickoff', 'next_subject' => 'home'],
                    ['id' => 'choice_away', 'label' => '{AWAY}', 'team' => 'away', 'next' => 'kickoff', 'next_subject' => 'away'],
                ],
            ],

            // Law 12: after a score the OPPONENTS of the scoring team restart play, so every
            // scoring action below enters this state with 'next_subject' => 'other'.
            'kickoff' => [
                'title' => '{SUBJECT} Have Kicked-Off',
                'actions' => [
                    ['id' => 'regather', 'label' => '{SUBJECT} - Regather', 'team' => 'subject', 'next' => 'general_play', 'next_subject' => 'subject'],
                    ['id' => 'catch', 'label' => '{OTHER} - Catch', 'team' => 'other', 'next' => 'general_play', 'next_subject' => 'other'],
                    ['id' => 'knockon_subject', 'label' => '{SUBJECT} - Knock On', 'team' => 'subject', 'next' => 'scrum', 'next_subject' => 'other'],
                    ['id' => 'knockon_other', 'label' => '{OTHER} - Knock On', 'team' => 'other', 'next' => 'scrum', 'next_subject' => 'subject'],
                    // Law 12: kicker's team-mates must be behind the ball; non-kicking team
                    // chooses retake or scrum.
                    ['id' => 'in_front_of_kicker', 'label' => 'In Front Of Kicker', 'team' => 'subject', 'next' => 'kickoff_retake_option', 'next_subject' => 'subject'],
                    // Law 12: the ball must reach the 10-metre line; retake or scrum.
                    ['id' => 'not_10m', 'label' => 'Kick Not 10 Meters', 'team' => 'subject', 'next' => 'kickoff_retake_option', 'next_subject' => 'subject'],
                    // Law 12.8: directly into touch -> retake, scrum, lineout or quick throw.
                    ['id' => 'direct_touch', 'label' => 'Directly Into Touch', 'team' => 'subject', 'next' => 'kickoff_touch_option', 'next_subject' => 'subject'],
                    // Bounced into touch is not an infringement: ordinary lineout to the receivers.
                    ['id' => 'bounced_touch', 'label' => 'Bounced Into Touch', 'team' => 'subject', 'next' => 'lineout', 'next_subject' => 'other'],
                    // Law 12.9: untouched into in-goal and grounded without delay (or dead
                    // through in-goal) -> retake or scrum.
                    ['id' => 'ingoal_grounded', 'label' => 'Into In-Goal - Made Dead by {OTHER}', 'team' => 'subject', 'next' => 'kickoff_retake_option', 'next_subject' => 'subject'],
                    // Law 12.9 / 12.12b: if the defenders delay, the kick is deemed accepted
                    // and play restarts with a try-line drop-out.
                    ['id' => 'ingoal_delayed', 'label' => 'Into In-Goal - {OTHER} Delay', 'team' => 'other', 'next' => 'dropout_tryline', 'next_subject' => 'other'],
                ],
            ],

            'kickoff_retake_option' => [
                'title' => 'Kick-Off Infringement — {OTHER} Option:',
                'actions' => self::retakeOptions('kickoff', '{SUBJECT} Retake Kick-Off'),
            ],

            // Law 12.8: the quick throw is tagged from the lineout state ("Quick Line Out").
            'kickoff_touch_option' => [
                'title' => 'Kick-Off Directly Into Touch — {OTHER} Option:',
                'actions' => self::touchRetakeOptions('kickoff', '{SUBJECT} Retake Kick-Off'),
            ],

            'general_play' => [
                'title' => 'Possession: {SUBJECT}',
                'actions' => [
                    ['id' => 'drop_kick', 'label' => 'Drop Kick', 'team' => 'subject', 'next' => 'drop_kick_result', 'next_subject' => 'subject'],
                    ['id' => 'try', 'label' => 'TRY', 'team' => 'subject', 'next' => 'conversion', 'next_subject' => 'subject', 'points' => POINTS_TRY],
                    // Law 8.3: awarded between the posts, no conversion is attempted.
                    ['id' => 'penalty_try', 'label' => 'PENALTY TRY', 'team' => 'subject', 'next' => 'kickoff', 'next_subject' => 'other', 'points' => POINTS_PENALTY_TRY],
                    ['id' => 'kick', 'label' => 'Kick', 'team' => 'subject', 'next' => 'kicked_infield', 'next_subject' => 'subject'],
                    ['id' => 'penalty', 'label' => 'Penalty Awarded', 'team' => 'subject', 'next' => 'penalty_cause', 'next_subject' => 'subject'],
                    ['id' => 'penalty_defence', 'label' => 'Penalty Awarded', 'team' => 'other', 'next' => 'penalty_cause', 'next_subject' => 'other'],
                    ['id' => 'ball_in_touch', 'label' => 'Ball In Touch', 'team' => 'subject', 'next' => 'lineout', 'next_subject' => 'other'],
                    ['id' => 'knock_on', 'label' => 'Knock-On', 'team' => 'subject', 'next' => 'scrum', 'next_subject' => 'other'],
                    ['id' => 'maul_formed', 'label' => 'Maul Formed', 'team' => 'subject', 'next' => 'general_play', 'next_subject' => 'subject'],
                    // Law 19.1: after an unsuccessful maul, the team not in possession at the
                    // start of the maul throws in.
                    ['id' => 'maul_unsuccessful', 'label' => 'Maul Unsuccessful', 'team' => 'subject', 'next' => 'scrum', 'next_subject' => 'other'],
                    ['id' => 'ruck_unplayable', 'label' => 'Unplayable Ruck/Tackle', 'team' => 'subject', 'next' => 'unplayable_ruck_option', 'next_subject' => 'subject'],
                    // Law 12.12a: attacker held up, or ball otherwise made dead in in-goal
                    // without a try -> try-line drop-out to the defenders (was a 5m scrum).
                    ['id' => 'held_up', 'label' => 'Held Up / Dead In-Goal', 'team' => 'subject', 'next' => 'dropout_tryline', 'next_subject' => 'other'],
                    // Law 12.12c: attacker knocks forward in the opponents' in-goal.
                    ['id' => 'knock_on_ingoal', 'label' => 'Knock-On In In-Goal', 'team' => 'subject', 'next' => 'dropout_tryline', 'next_subject' => 'other'],
                    // Law 21.16: when the DEFENDERS took the ball into their own in-goal and it
                    // is made dead, play restarts with a 5m scrum, attacking team throwing in.
                    ['id' => 'carried_back', 'label' => 'Carried Back Into Own In-Goal', 'team' => 'subject', 'next' => 'scrum', 'next_subject' => 'other'],
                    ['id' => 'interception', 'label' => 'Interception', 'team' => 'other', 'next' => 'general_play', 'next_subject' => 'other'],
                    ['id' => 'turnover', 'label' => 'Turn-Over', 'team' => 'other', 'next' => 'general_play', 'next_subject' => 'other'],
                ],
            ],

            // Law 19.1: the team last moving forward throws in; if neither, the attacking team.
            // The tagger picks it, since the graph cannot know who was going forward.
            'unplayable_ruck_option' => [
                'title' => 'Unplayable Ruck/Tackle — Scrum To:',
                'actions' => [
                    ['id' => 'scrum_subject', 'label' => 'Scrum to {SUBJECT}', 'team' => 'subject', 'next' => 'scrum', 'next_subject' => 'subject'],
                    ['id' => 'scrum_other', 'label' => 'Scrum to {OTHER}', 'team' => 'other', 'next' => 'scrum', 'next_subject' => 'other'],
                ],
            ],

            'scrum' => [
                'title' => 'Scrum to {SUBJECT}',
                'actions' => self::restartResultActions(),
            ],

            'lineout' => [
                'title' => 'Lineout to {SUBJECT}',
                'actions' => array_merge([
                    ['id' => 'quick_lineout', 'label' => 'Quick Line Out', 'team' => 'subject', 'next' => 'general_play', 'next_subject' => 'subject'],
                    // Law 18.23a: if the non-throwing team lifted a jumper, they choose
                    // lineout or scrum.
                    ['id' => 'not_straight', 'label' => 'Lineout Not Straight', 'team' => 'subject', 'next' => 'lineout_not_straight_option', 'next_subject' => 'other'],
                    // Law 18.23a (Global Law Trial): if the non-throwing team did not lift a
                    // jumper to compete, a not-straight throw is ignored and play continues.
                    ['id' => 'not_straight_play_on', 'label' => 'Not Straight - Uncontested, Play On', 'team' => 'subject', 'next' => 'general_play', 'next_subject' => 'subject'],
                ], self::restartResultActions()),
            ],

            'lineout_not_straight_option' => [
                'title' => 'Lineout Not Straight — {SUBJECT} Option:',
                'actions' => [
                    ['id' => 'lineout_option', 'label' => 'Lineout to {SUBJECT}', 'team' => 'subject', 'next' => 'lineout', 'next_subject' => 'subject'],
                    ['id' => 'scrum_option', 'label' => 'Scrum to {SUBJECT}', 'team' => 'subject', 'next' => 'scrum', 'next_subject' => 'subject'],
                ],
            ],

            'penalty_cause' => [
                'title' => 'Penalty {SUBJECT} — Cause',
                'actions' => array_map(
                    fn (array $cause) => [
                        'id' => $cause['id'],
                        'label' => $cause['label'],
                        'team' => 'subject',
                        'next' => 'penalty_option',
                        'next_subject' => 'subject',
                        'meta' => ['cause' => $cause['id']],
                    ],
                    self::penaltyCauses()
                ),
            ],

            'penalty_option' => [
                'title' => 'Penalty {SUBJECT} — Option',
                'actions' => [
                    ['id' => 'kick_at_goal', 'label' => 'Kick At Goal', 'team' => 'subject', 'next' => 'penalty_goal_result', 'next_subject' => 'subject'],
                    // Law 18.8c: a penalty kicked into touch -> the kicking team throws in.
                    ['id' => 'kick_to_touch', 'label' => 'Kick To Touch', 'team' => 'subject', 'next' => 'lineout', 'next_subject' => 'subject'],
                    ['id' => 'tap_and_go', 'label' => 'Tap and Go', 'team' => 'subject', 'next' => 'general_play', 'next_subject' => 'subject'],
                    // Law 20.3: a team awarded a penalty may instead choose a scrum.
                    ['id' => 'scrum_option', 'label' => 'Scrum', 'team' => 'subject', 'next' => 'scrum', 'next_subject' => 'subject'],
                    // Law 20.4a: only when the penalty is awarded at a lineout.
                    ['id' => 'lineout_option', 'label' => 'Lineout (at lineout only)', 'team' => 'subject', 'next' => 'lineout', 'next_subject' => 'subject'],
                ],
            ],

            // Reached from a free kick at scrum/lineout and from a mark (Law 17).
            // Law 20.3 offers the scrum option to penalties only, so there is none here;
            // no goal can be scored from a free kick, so there is no kick at goal either.
            'free_kick_option' => [
                'title' => 'Free Kick {SUBJECT} — Option',
                'actions' => [
                    // Law 18.8e: a free kick kicked into touch -> the NON-kicking team throws in,
                    // no gain in ground (unlike a penalty).
                    ['id' => 'kick_to_touch', 'label' => 'Kick To Touch', 'team' => 'subject', 'next' => 'lineout', 'next_subject' => 'other'],
                    ['id' => 'tap_and_go', 'label' => 'Tap and Go', 'team' => 'subject', 'next' => 'general_play', 'next_subject' => 'subject'],
                    // Law 20.4b: a free kick at a lineout may instead be a lineout at the same mark.
                    ['id' => 'lineout_option', 'label' => 'Lineout (at lineout only)', 'team' => 'subject', 'next' => 'lineout', 'next_subject' => 'subject'],
                ],
            ],

            'penalty_goal_result' => [
                'title' => 'Penalty Kick at Goal — {SUBJECT}',
                'actions' => [
                    ['id' => 'scored', 'label' => 'Goal Scored', 'team' => 'subject', 'next' => 'kickoff', 'next_subject' => 'other', 'points' => POINTS_PENALTY_GOAL],
                    // Law 12.11a: an unsuccessful penalty goal made dead in in-goal -> 22m drop-out.
                    ['id' => 'missed', 'label' => 'Missed - 22 Drop Out', 'team' => 'subject', 'next' => 'dropout_22', 'next_subject' => 'other'],
                    // A missed kick that stays in the field of play (e.g. off the posts) is live.
                    ['id' => 'missed_caught', 'label' => 'Missed - Caught By {OTHER}', 'team' => 'other', 'next' => 'general_play', 'next_subject' => 'other'],
                    ['id' => 'missed_regathered', 'label' => 'Missed - Regathered By {SUBJECT}', 'team' => 'subject', 'next' => 'general_play', 'next_subject' => 'subject'],
                ],
            ],

            'drop_kick_result' => [
                'title' => 'Drop Kick by {SUBJECT}',
                'actions' => [
                    ['id' => 'goal_scored', 'label' => 'Drop Goal Scored', 'team' => 'subject', 'next' => 'kickoff', 'next_subject' => 'other', 'points' => POINTS_DROP_GOAL],
                    // Law 12.11a: an unsuccessful dropped goal made dead in in-goal -> 22m drop-out.
                    ['id' => 'missed_22', 'label' => 'Missed - 22 Drop Out', 'team' => 'subject', 'next' => 'dropout_22', 'next_subject' => 'other'],
                    ['id' => 'missed_caught', 'label' => 'Missed - Caught By {OTHER}', 'team' => 'other', 'next' => 'general_play', 'next_subject' => 'other'],
                    ['id' => 'missed_regathered', 'label' => 'Missed - Regathered By {SUBJECT}', 'team' => 'subject', 'next' => 'general_play', 'next_subject' => 'subject'],
                ],
            ],

            // Law 12.11: taken on or behind the 22m line; the ball must cross the 22m line.
            'dropout_22' => [
                'title' => '22 Drop-Out {SUBJECT}',
                'actions' => self::dropOutActions('dropout_22_short_option', 'Did Not Cross 22', 'dropout_22_touch_option'),
            ],

            // Law 12.13c: drop-out not crossing its sanction line -> retake or scrum.
            'dropout_22_short_option' => [
                'title' => 'Drop-Out Not Past 22 — {OTHER} Option:',
                'actions' => self::retakeOptions('dropout_22', '{SUBJECT} Retake Drop Out'),
            ],

            'dropout_22_touch_option' => [
                'title' => 'Drop Out Straight Into Touch — {OTHER} Option:',
                'actions' => self::touchRetakeOptions('dropout_22', '{SUBJECT} Retake Drop Out'),
            ],

            // Law 12.12: taken on or behind the defending team's try line; the sanction line
            // is the 5m line. Since July 2026 it is the restart whenever the attackers carry,
            // play or kick the ball into in-goal and fail to score.
            'dropout_tryline' => [
                'title' => 'Try-Line Drop-Out {SUBJECT}',
                'actions' => self::dropOutActions('dropout_tryline_short_option', 'Did Not Cross 5m', 'dropout_tryline_touch_option'),
            ],

            'dropout_tryline_short_option' => [
                'title' => 'Drop-Out Not Past 5m — {OTHER} Option:',
                'actions' => self::retakeOptions('dropout_tryline', '{SUBJECT} Retake Drop Out'),
            ],

            'dropout_tryline_touch_option' => [
                'title' => 'Drop Out Straight Into Touch — {OTHER} Option:',
                'actions' => self::touchRetakeOptions('dropout_tryline', '{SUBJECT} Retake Drop Out'),
            ],

            'kicked_infield' => [
                'title' => 'Kicked In Field by {SUBJECT}',
                'actions' => [
                    // Law 17: a mark gives a free kick (never from a kick-off, which does not
                    // pass through this state).
                    ['id' => 'mark', 'label' => 'Mark', 'team' => 'other', 'next' => 'free_kick_option', 'next_subject' => 'other'],
                    ['id' => 'caught_subject', 'label' => 'Caught by {SUBJECT}', 'team' => 'subject', 'next' => 'general_play', 'next_subject' => 'subject'],
                    ['id' => 'caught_other', 'label' => 'Caught by {OTHER}', 'team' => 'other', 'next' => 'general_play', 'next_subject' => 'other'],
                    ['id' => 'knockedon_subject', 'label' => 'Knocked On by {SUBJECT}', 'team' => 'subject', 'next' => 'scrum', 'next_subject' => 'other'],
                    ['id' => 'knockedon_other', 'label' => 'Knocked On by {OTHER}', 'team' => 'other', 'next' => 'scrum', 'next_subject' => 'subject'],
                    ['id' => 'penalty_subject', 'label' => 'Penalty Awarded', 'team' => 'subject', 'next' => 'penalty_cause', 'next_subject' => 'subject'],
                    ['id' => 'penalty_other', 'label' => 'Penalty Awarded', 'team' => 'other', 'next' => 'penalty_cause', 'next_subject' => 'other'],
                    ['id' => 'charged_down', 'label' => 'Charged Down by {OTHER}', 'team' => 'other', 'next' => 'charge_down', 'next_subject' => 'subject'],
                    // Law 18.8: kicked into touch -> the non-kicking team throws in.
                    ['id' => 'in_touch', 'label' => 'Ball In Touch', 'team' => 'subject', 'next' => 'lineout', 'next_subject' => 'other'],
                    // Law 18.8a: kicked from own half, bouncing into touch inside the
                    // opponents' 22 -> the kicking team throws in.
                    ['id' => 'fifty_22', 'label' => '50:22', 'team' => 'subject', 'next' => 'lineout', 'next_subject' => 'subject'],
                    // Law 12.12a: an open-play kick made dead INSIDE in-goal by the defenders
                    // -> try-line drop-out (was a 22m drop-out before July 2026).
                    ['id' => 'grounded_ingoal', 'label' => 'Grounded in In-Goal by {OTHER}', 'team' => 'other', 'next' => 'dropout_tryline', 'next_subject' => 'other'],
                    // Law 12.11b / 21.11: kicked THROUGH in-goal into touch-in-goal or over the
                    // dead-ball line -> defenders choose 22m drop-out or scrum where kicked.
                    ['id' => 'dead_through_ingoal', 'label' => 'Dead Through In-Goal', 'team' => 'subject', 'next' => 'kick_dead_option', 'next_subject' => 'other'],
                ],
            ],

            'kick_dead_option' => [
                'title' => 'Kick Dead Through In-Goal — {SUBJECT} Option:',
                'actions' => [
                    ['id' => 'dropout_22', 'label' => '22 Drop Out', 'team' => 'subject', 'next' => 'dropout_22', 'next_subject' => 'subject'],
                    ['id' => 'scrum_where_kicked', 'label' => 'Scrum Where Kicked', 'team' => 'subject', 'next' => 'scrum', 'next_subject' => 'subject'],
                ],
            ],

            'charge_down' => [
                'title' => 'Charge Down by {OTHER}',
                'actions' => [
                    ['id' => 'gathered_other', 'label' => 'Gathered By {OTHER}', 'team' => 'other', 'next' => 'general_play', 'next_subject' => 'other'],
                    ['id' => 'gathered_subject', 'label' => 'Gathered By {SUBJECT}', 'team' => 'subject', 'next' => 'general_play', 'next_subject' => 'subject'],
                    ['id' => 'ball_in_touch', 'label' => 'Ball in Touch', 'team' => 'other', 'next' => 'lineout', 'next_subject' => 'subject'],
                    // Law 12.12d: charged down by an attacker and dead through in-goal ->
                    // try-line drop-out. Assumes the usual case: the ball rebounds into the
                    // kicker's own in-goal, so the kicker's team drops out.
                    ['id' => 'dead_ingoal', 'label' => 'Dead Through In-Goal', 'team' => 'other', 'next' => 'dropout_tryline', 'next_subject' => 'subject'],
                ],
            ],

            'conversion' => [
                'title' => 'Conversion Attempt — {SUBJECT}',
                'actions' => [
                    ['id' => 'converted', 'label' => 'Conversion Scored', 'team' => 'subject', 'next' => 'kickoff', 'next_subject' => 'other', 'points' => POINTS_CONVERSION],
                    ['id' => 'conv_missed', 'label' => 'Conversion Missed', 'team' => 'subject', 'next' => 'kickoff', 'next_subject' => 'other'],
                ],
            ],
        ];
    }

    /**
     * Griglia di esito comune a scrum e lineout: chi la vince, chi subisce punizione,
     * chi subisce calcio di punizione rapido (free kick), chi va in avanti.
     */
    private static function restartResultActions(): array
    {
        return [
            ['id' => 'won_subject', 'label' => 'Won by {SUBJECT}', 'team' => 'subject', 'next' => 'general_play', 'next_subject' => 'subject'],
            ['id' => 'won_other', 'label' => 'Won by {OTHER}', 'team' => 'other', 'next' => 'general_play', 'next_subject' => 'other'],
            ['id' => 'penalty_subject', 'label' => 'Penalty Awarded', 'team' => 'subject', 'next' => 'penalty_cause', 'next_subject' => 'subject'],
            ['id' => 'penalty_other', 'label' => 'Penalty Awarded', 'team' => 'other', 'next' => 'penalty_cause', 'next_subject' => 'other'],
            ['id' => 'freekick_subject', 'label' => 'Free Kick to {SUBJECT}', 'team' => 'subject', 'next' => 'free_kick_option', 'next_subject' => 'subject'],
            ['id' => 'freekick_other', 'label' => 'Free Kick to {OTHER}', 'team' => 'other', 'next' => 'free_kick_option', 'next_subject' => 'other'],
            ['id' => 'knockon_subject', 'label' => 'Knock On by {SUBJECT}', 'team' => 'subject', 'next' => 'scrum', 'next_subject' => 'other'],
            ['id' => 'knockon_other', 'label' => 'Knock On by {OTHER}', 'team' => 'other', 'next' => 'scrum', 'next_subject' => 'subject'],
        ];
    }

    /**
     * Outcomes shared by the 22m drop-out and the try-line drop-out (Law 12.11-12.13).
     *
     * @param string $shortOptionState state offering the options when the ball fails to cross the sanction line
     * @param string $shortLabel label of that infringement (names the sanction line)
     * @param string $touchOptionState state offering the options when the ball goes directly into touch
     * @return array actions of the drop-out state, with {SUBJECT} as the kicking team
     */
    private static function dropOutActions(string $shortOptionState, string $shortLabel, string $touchOptionState): array
    {
        return [
            ['id' => 'regather', 'label' => '{SUBJECT} - Regather', 'team' => 'subject', 'next' => 'general_play', 'next_subject' => 'subject'],
            ['id' => 'catch', 'label' => '{OTHER} - Catch', 'team' => 'other', 'next' => 'general_play', 'next_subject' => 'other'],
            ['id' => 'knockon_subject', 'label' => '{SUBJECT} - Knock-On', 'team' => 'subject', 'next' => 'scrum', 'next_subject' => 'other'],
            ['id' => 'knockon_other', 'label' => '{OTHER} - Knock-On', 'team' => 'other', 'next' => 'scrum', 'next_subject' => 'subject'],
            ['id' => 'not_crossed', 'label' => $shortLabel, 'team' => 'subject', 'next' => $shortOptionState, 'next_subject' => 'subject'],
            ['id' => 'direct_touch', 'label' => 'Directly Into Touch', 'team' => 'subject', 'next' => $touchOptionState, 'next_subject' => 'subject'],
        ];
    }

    /**
     * Options of the non-kicking team after a restart-kick infringement: retake or scrum
     * (Law 12 kick-off sanctions, Law 12.13c for drop-outs).
     *
     * @param string $retakeState state of the restart kick to take again
     * @param string $retakeLabel label of the retake button
     * @return array actions, with {SUBJECT} as the team that kicked
     */
    private static function retakeOptions(string $retakeState, string $retakeLabel): array
    {
        return [
            ['id' => 'retake', 'label' => $retakeLabel, 'team' => 'subject', 'next' => $retakeState, 'next_subject' => 'subject'],
            ['id' => 'scrum_other', 'label' => 'Scrum to {OTHER}', 'team' => 'other', 'next' => 'scrum', 'next_subject' => 'other'],
        ];
    }

    /**
     * Options when a restart kick goes directly into touch: retake, scrum or lineout
     * (Law 12.8 for kick-offs, the same options for drop-outs).
     *
     * @param string $retakeState state of the restart kick to take again
     * @param string $retakeLabel label of the retake button
     * @return array actions, with {SUBJECT} as the team that kicked
     */
    private static function touchRetakeOptions(string $retakeState, string $retakeLabel): array
    {
        return array_merge(self::retakeOptions($retakeState, $retakeLabel), [
            ['id' => 'lineout_other', 'label' => 'Lineout to {OTHER}', 'team' => 'other', 'next' => 'lineout', 'next_subject' => 'other'],
        ]);
    }

    public static function penaltyCauses(): array
    {
        return [
            ['id' => 'ruck_offence', 'label' => 'Ruck Offence'],
            ['id' => 'tackler_not_releasing', 'label' => 'Tackler Not Releasing Player'],
            ['id' => 'offside', 'label' => 'Offside'],
            ['id' => 'obstruction', 'label' => 'Obstruction'],
            ['id' => 'dangerous_tackle', 'label' => 'Dangerous Tackle'],
            ['id' => 'deliberate_knockon', 'label' => 'Deliberate Knock-On'],
            ['id' => 'violent_foul_play', 'label' => 'Violent/Foul Play'],
            ['id' => 'unknown', 'label' => 'Unknown'],
        ];
    }
}
