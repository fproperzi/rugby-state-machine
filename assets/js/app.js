(function () {
    'use strict';

    const appEl = document.getElementById('app');
    const matchId = Number(appEl.dataset.matchId);

    const GOAL_ATTEMPT_STATES = ['penalty_goal_result', 'conversion', 'drop_kick_result'];
    // Col cronometro guidato dal video il readout si ricalcola spesso: il video puo' saltare in ogni momento.
    const VIDEO_CLOCK_REFRESH_MS = 500;
    const SWAP_STORAGE_KEY = `rugby.swapSides.${matchId}`;

    let state = null;
    let clockTicker = null;
    let advantageTicker = null;
    let lastKickPosition = null;
    let syncInFlight = false;
    let pendingSync = null;

    async function postJSON(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });

        if (!res.ok) {
            const err = await res.json().catch(() => ({ error: t('unknown_error') }));
            alert(err.error || t('unknown_error'));
            throw new Error(err.error || 'request failed');
        }

        return res.json();
    }

    function formatClock(totalSeconds) {
        const m = Math.floor(totalSeconds / 60).toString().padStart(2, '0');
        const s = (totalSeconds % 60).toString().padStart(2, '0');
        return `${m}:${s}`;
    }

    // ---------- Render ----------

    function render(payload) {
        state = payload;
        lastKickPosition = null;

        document.getElementById('home-name').textContent = payload.home_name;
        document.getElementById('away-name').textContent = payload.away_name;
        document.getElementById('home-score').textContent = payload.home_score;
        document.getElementById('away-score').textContent = payload.away_score;
        document.getElementById('state-title').textContent = payload.current.title;

        const timeOffBtn = document.getElementById('btn-timeoff');
        timeOffBtn.textContent = t(payload.clock_running ? 'time_off' : 'time_on');
        timeOffBtn.classList.toggle('active', !payload.clock_running);

        const phaseBtn = document.getElementById('btn-phase');
        if (payload.status === 'full_time') {
            phaseBtn.style.display = 'none';
        } else {
            phaseBtn.style.display = '';
            phaseBtn.textContent = t(payload.half >= 2 ? 'full_time' : 'half_time');
        }

        renderActions(payload);
        renderGoalPitch(payload);
        renderAdvantage(payload);

        const undoBtn = document.getElementById('btn-undo');
        undoBtn.disabled = !payload.undo_label;
        undoBtn.textContent = payload.undo_label ? t('undo_label', { label: payload.undo_label }) : t('undo');

        updateClockReadout();
        restartClockTicker();

        // Annuncia il nuovo stato al player video della modalita' split (se presente).
        document.dispatchEvent(new CustomEvent('rugby:state', { detail: payload }));
    }

    function renderActions(payload) {
        const grid = document.getElementById('action-grid');
        grid.innerHTML = '';
        grid.classList.toggle('single', payload.current.actions.length <= 1);
        payload.current.actions.forEach((action) => {
            const btn = document.createElement('button');
            btn.className = 'action-btn team-' + (action.team || 'neutral');
            btn.textContent = action.label;
            btn.addEventListener('click', () => applyAction(action.id));
            grid.appendChild(btn);
        });
    }

    function renderGoalPitch(payload) {
        const wrap = document.getElementById('goal-pitch-wrap');
        wrap.hidden = !GOAL_ATTEMPT_STATES.includes(payload.current.state);
        document.getElementById('goal-pitch').querySelectorAll('.mark').forEach((el) => el.remove());
    }

    function renderAdvantage(payload) {
        const banner = document.getElementById('advantage-banner');
        if (advantageTicker) {
            clearInterval(advantageTicker);
            advantageTicker = null;
        }

        if (!payload.advantage) {
            banner.hidden = true;
            return;
        }

        const teamName = payload.advantage.team === 'home' ? payload.home_name : payload.away_name;
        let seconds = payload.advantage.seconds;

        const paint = () => {
            banner.textContent = t('advantage_banner', { team: teamName, time: formatClock(seconds) });
        };

        banner.hidden = false;
        paint();
        advantageTicker = setInterval(() => {
            seconds += 1;
            paint();
        }, 1000);
    }

    // ---------- Cronometro (eventualmente guidato dal video) ----------

    /** True se c'e' un video caricato nella pagina (modalita' split). */
    function hasVideo() {
        return Boolean(window.RugbyVideo && window.RugbyVideo.isActive());
    }

    /**
     * True quando il tempo di gioco segue il video: partita in corso e video gia' ancorato
     * al calcio d'inizio (video_offset valorizzato dal server).
     */
    function clockFollowsVideo() {
        return hasVideo() && state.status === 'live' && state.video_offset !== null;
    }

    function displayedMatchSeconds() {
        if (clockFollowsVideo()) {
            return Math.max(0, Math.floor(window.RugbyVideo.getTime() - state.video_offset));
        }
        return state.match_seconds;
    }

    function updateClockReadout() {
        document.getElementById('clock-readout').textContent = formatClock(displayedMatchSeconds());
    }

    function restartClockTicker() {
        if (clockTicker) {
            clearInterval(clockTicker);
            clockTicker = null;
        }

        if (hasVideo()) {
            clockTicker = setInterval(updateClockReadout, VIDEO_CLOCK_REFRESH_MS);
            return;
        }

        if (!state.clock_running) {
            return;
        }

        clockTicker = setInterval(() => {
            state.match_seconds += 1;
            updateClockReadout();
        }, 1000);
    }

    // ---------- Azioni di stato ----------

    async function loadState() {
        const res = await fetch(`api/state.php?match_id=${matchId}`);
        render(await res.json());
    }

    /** Posizione del video da allegare alle richieste, perche' il server marchi l'evento col tempo del video. */
    function videoPlayback() {
        if (!hasVideo()) {
            return {};
        }
        return { video_time: window.RugbyVideo.getTime(), video_playing: window.RugbyVideo.isPlaying() };
    }

    async function applyAction(actionId) {
        // Il primo calcio d'inizio (e quello del secondo tempo) ancora il cronometro al video.
        const body = { match_id: matchId, action_id: actionId, ...videoPlayback() };
        if (lastKickPosition) {
            body.x = lastKickPosition.x;
            body.y = lastKickPosition.y;
        }
        render(await postJSON('api/action.php', body));
    }

    /**
     * Allinea il cronometro del server al video. Una richiesta alla volta: se ne arrivano altre
     * nel frattempo si tiene solo l'ultima, che e' l'unica che conta.
     */
    async function syncClockWithVideo(playback) {
        if (syncInFlight) {
            pendingSync = playback;
            return;
        }

        syncInFlight = true;
        try {
            const res = await fetch('api/clock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    match_id: matchId,
                    op: 'video_sync',
                    video_time: playback.time,
                    video_playing: playback.playing,
                }),
            });
            if (res.ok) {
                render(await res.json());
            } else {
                // Niente alert: la sincronizzazione e' continua e un popup bloccherebbe il tagging.
                console.error('Sincronizzazione video fallita', res.status, await res.text());
            }
        } catch (err) {
            console.error('Sincronizzazione video fallita', err);
        } finally {
            syncInFlight = false;
            if (pendingSync) {
                const next = pendingSync;
                pendingSync = null;
                syncClockWithVideo(next);
            }
        }
    }

    // Prima del calcio d'inizio e all'intervallo il server ignora il video: inutile chiamarlo.
    document.addEventListener('rugby:video', (e) => {
        if (state && state.status === 'live') {
            syncClockWithVideo(e.detail);
        }
    });

    // Il player diventa pronto dopo il primo render: da li' il readout va calcolato sul video.
    document.addEventListener('rugby:video-ready', () => {
        if (state) restartClockTicker();
    });

    document.getElementById('btn-undo').addEventListener('click', async () => {
        render(await postJSON('api/undo.php', { match_id: matchId }));
    });

    document.getElementById('btn-timeoff').addEventListener('click', async () => {
        // Col cronometro agganciato al video, Time Off/On mette in pausa/riprende il video:
        // e' il video a fermare il tempo (la sincronizzazione arriva dall'evento 'rugby:video').
        if (clockFollowsVideo()) {
            window.RugbyVideo.togglePlay();
            return;
        }
        render(await postJSON('api/clock.php', { match_id: matchId, op: 'toggle' }));
    });

    // ---------- Scambio squadre sinistra/destra ----------

    // Preferenza solo visiva e per questo browser: la partita (home/away) non cambia.
    function readSwapPreference() {
        try {
            return localStorage.getItem(SWAP_STORAGE_KEY) === '1';
        } catch (err) {
            return false;
        }
    }

    // Classe sul body: oltre all'intestazione, segue lo scambio anche l'ordine dei selettori di squadra nei modali.
    function applySwap(swapped) {
        document.body.classList.toggle('sides-swapped', swapped);
        try {
            localStorage.setItem(SWAP_STORAGE_KEY, swapped ? '1' : '0');
        } catch (err) {
            // Storage non disponibile (es. navigazione privata): lo scambio vale finche' la pagina resta aperta.
        }
    }

    document.getElementById('btn-swap').addEventListener('click', () => {
        applySwap(!document.body.classList.contains('sides-swapped'));
    });

    applySwap(readSwapPreference());

    document.getElementById('btn-phase').addEventListener('click', async () => {
        const op = state.half >= 2 ? 'full_time' : 'half_time';
        if (!confirm(t(op === 'full_time' ? 'confirm_full_time' : 'confirm_half_time'))) {
            return;
        }
        render(await postJSON('api/clock.php', { match_id: matchId, op }));
    });

    document.getElementById('btn-showstats').addEventListener('click', () => {
        window.open(`index.php?page=stats&match=${matchId}`, '_blank');
    });

    // ---------- Campo "tap to mark" per i calci a palo ----------

    document.getElementById('goal-pitch').addEventListener('click', (e) => {
        const pitch = document.getElementById('goal-pitch');
        const rect = pitch.getBoundingClientRect();
        const x = ((e.clientX - rect.left) / rect.width) * 100;
        const y = ((e.clientY - rect.top) / rect.height) * 100;
        lastKickPosition = { x, y };

        pitch.querySelectorAll('.mark').forEach((el) => el.remove());
        const mark = document.createElement('div');
        mark.className = 'mark';
        mark.style.left = x + '%';
        mark.style.top = y + '%';
        pitch.appendChild(mark);
    });

    // ---------- Modali generici ----------

    function setupToggle(containerId, defaultValue) {
        const container = document.getElementById(containerId);
        let selected = defaultValue;
        container.querySelectorAll('button').forEach((btn) => {
            btn.classList.toggle('selected', btn.dataset.value === selected);
            btn.addEventListener('click', () => {
                selected = btn.dataset.value;
                container.querySelectorAll('button').forEach((b) => b.classList.toggle('selected', b === btn));
            });
        });
        return () => selected;
    }

    function openModal(id) {
        document.getElementById(id).hidden = false;
    }

    function closeModal(id) {
        document.getElementById(id).hidden = true;
    }

    // ---------- Cartellino ----------

    const getCardTeam = setupToggle('card-team-toggle', 'home');
    const getCardColour = setupToggle('card-colour-toggle', 'yellow');

    document.getElementById('btn-card').addEventListener('click', () => openModal('modal-card'));
    document.getElementById('card-cancel').addEventListener('click', () => closeModal('modal-card'));
    document.getElementById('card-confirm').addEventListener('click', async () => {
        const playerNumber = document.getElementById('card-player').value;
        render(await postJSON('api/card.php', {
            match_id: matchId,
            team: getCardTeam(),
            colour: getCardColour(),
            player_number: playerNumber,
        }));
        document.getElementById('card-player').value = '';
        closeModal('modal-card');
    });

    // ---------- Sostituzione ----------

    const getSubTeam = setupToggle('sub-team-toggle', 'home');

    document.getElementById('btn-sub').addEventListener('click', () => openModal('modal-sub'));
    document.getElementById('sub-cancel').addEventListener('click', () => closeModal('modal-sub'));
    document.getElementById('sub-confirm').addEventListener('click', async () => {
        const playerOff = document.getElementById('sub-player-off').value;
        const playerOn = document.getElementById('sub-player-on').value;
        render(await postJSON('api/substitution.php', {
            match_id: matchId,
            team: getSubTeam(),
            player_off: playerOff,
            player_on: playerOn,
        }));
        document.getElementById('sub-player-off').value = '';
        document.getElementById('sub-player-on').value = '';
        closeModal('modal-sub');
    });

    // ---------- Vantaggio ----------

    const getAdvantageTeam = setupToggle('advantage-team-toggle', 'home');

    document.getElementById('btn-advantage').addEventListener('click', async () => {
        if (state.advantage) {
            if (confirm(t('confirm_advantage_over'))) {
                render(await postJSON('api/advantage.php', { match_id: matchId, op: 'stop' }));
            }
            return;
        }
        openModal('modal-advantage');
    });

    document.getElementById('advantage-banner').addEventListener('click', async () => {
        if (state.advantage && confirm(t('confirm_advantage_over'))) {
            render(await postJSON('api/advantage.php', { match_id: matchId, op: 'stop' }));
        }
    });

    document.getElementById('advantage-cancel').addEventListener('click', () => closeModal('modal-advantage'));
    document.getElementById('advantage-confirm').addEventListener('click', async () => {
        render(await postJSON('api/advantage.php', { match_id: matchId, op: 'start', team: getAdvantageTeam() }));
        closeModal('modal-advantage');
    });

    loadState();
})();
