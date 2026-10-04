(function () {
    'use strict';

    /*
     * Player video della modalita' split. Carica la sorgente indicata dal tagger (payload.video,
     * descrittore costruito da VideoSource in PHP) e avvisa il tagger con l'evento
     * 'rugby:video' {time, playing} quando il video va in play/pausa, salta in un altro punto,
     * o periodicamente mentre scorre: e' cosi' che il cronometro segue il video.
     *
     * Ogni tipo di player (file locale, YouTube, Vimeo) e' ridotto allo stesso adattatore:
     * { getTime(), isPlaying(), play(), pause(), seekTo(seconds) }.
     */

    const POLL_MS = 1000;
    // Risincronizza anche senza eventi, per correggere la deriva tra orologio del server e video
    // (buffering, velocita' di riproduzione diversa da 1x).
    const HEARTBEAT_MS = 5000;
    // Scarto oltre il quale una differenza di posizione e' un salto (seek) e non rumore del polling.
    const JUMP_TOLERANCE_S = 1.5;

    const videoArea = document.getElementById('video-area');
    const statusEl = document.getElementById('video-status');

    let player = null;
    let loadedKey = null;
    let latestState = null;
    let lastSample = null;
    let lastEmitAt = 0;
    let pollTimer = null;
    const scriptPromises = {};

    // ---------- Comunicazione col tagger ----------

    function emit(time, playing) {
        lastEmitAt = Date.now();
        document.dispatchEvent(new CustomEvent('rugby:video', { detail: { time, playing } }));
    }

    function poll() {
        if (!player) return;

        const time = player.getTime();
        const playing = player.isPlaying();
        const now = Date.now();

        // Il primo campione fa solo da riferimento: un video appena caricato (fermo a 0) non deve
        // azzerare il cronometro di una partita gia' in corso.
        if (lastSample === null) {
            lastSample = { time, playing, at: now };
            return;
        }

        const expected = lastSample.time + (lastSample.playing ? (now - lastSample.at) / 1000 : 0);
        const changed = playing !== lastSample.playing || Math.abs(time - expected) > JUMP_TOLERANCE_S;
        const heartbeatDue = playing && now - lastEmitAt >= HEARTBEAT_MS;

        lastSample = { time, playing, at: now };
        if (changed || heartbeatDue) {
            emit(time, playing);
        }
    }

    function onPlayerReady(adapter) {
        player = adapter;
        lastSample = null;
        resumeFromMatchClock();

        clearInterval(pollTimer);
        pollTimer = setInterval(poll, POLL_MS);
        // Riferimento preso dopo l'eventuale seek di ripresa.
        setTimeout(poll, 300);

        document.dispatchEvent(new CustomEvent('rugby:video-ready'));
    }

    /**
     * Riaprendo una partita gia' ancorata al video, riporta il video al punto corrispondente
     * al cronometro: video e tempo di gioco ripartono allineati.
     */
    function resumeFromMatchClock() {
        if (!latestState || latestState.status !== 'live' || latestState.video_offset === null) {
            return;
        }
        player.seekTo(latestState.video_offset + latestState.match_seconds);
    }

    function showStatus(message) {
        statusEl.textContent = message || '';
        statusEl.hidden = !message;
    }

    function resetPlayer() {
        player = null;
        lastSample = null;
        clearInterval(pollTimer);
        pollTimer = null;
        videoArea.innerHTML = '';
    }

    // ---------- Adattatori ----------

    function loadScript(src) {
        if (!scriptPromises[src]) {
            scriptPromises[src] = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = src;
                script.onload = resolve;
                script.onerror = () => reject(new Error('Impossibile caricare ' + src));
                document.head.appendChild(script);
            });
        }
        return scriptPromises[src];
    }

    function loadYouTubeApi() {
        if (window.YT && window.YT.Player) {
            return Promise.resolve();
        }
        // L'API di YouTube segnala di essere pronta con una callback globale, non con onload.
        return new Promise((resolve, reject) => {
            const previous = window.onYouTubeIframeAPIReady;
            window.onYouTubeIframeAPIReady = () => {
                if (previous) previous();
                resolve();
            };
            loadScript('https://www.youtube.com/iframe_api').catch(reject);
        });
    }

    function showHtml5(src) {
        const video = document.createElement('video');
        video.src = src;
        video.controls = true;
        video.preload = 'metadata';
        videoArea.appendChild(video);

        const adapter = {
            getTime: () => video.currentTime,
            isPlaying: () => !video.paused && !video.ended,
            play: () => video.play(),
            pause: () => video.pause(),
            seekTo: (seconds) => { video.currentTime = seconds; },
        };

        video.addEventListener('loadedmetadata', () => onPlayerReady(adapter), { once: true });
        ['play', 'pause', 'seeked', 'ended'].forEach((name) => video.addEventListener(name, poll));
        video.addEventListener('error', () => {
            showStatus(t('video_unsupported'));
        });
    }

    async function showYouTube(videoId) {
        const holder = document.createElement('div');
        videoArea.appendChild(holder);
        await loadYouTubeApi();

        const yt = new window.YT.Player(holder, {
            videoId,
            width: '100%',
            height: '100%',
            playerVars: { playsinline: 1, rel: 0 },
            events: {
                onReady: () => onPlayerReady({
                    getTime: () => yt.getCurrentTime(),
                    isPlaying: () => yt.getPlayerState() === window.YT.PlayerState.PLAYING,
                    play: () => yt.playVideo(),
                    pause: () => yt.pauseVideo(),
                    seekTo: (seconds) => yt.seekTo(seconds, true),
                }),
                onStateChange: poll,
                onError: (e) => showStatus(t('video_youtube_error', { code: e.data })),
            },
        });
    }

    async function showVimeo(videoId) {
        await loadScript('https://player.vimeo.com/api/player.js');

        const iframe = document.createElement('iframe');
        iframe.src = `https://player.vimeo.com/video/${videoId}`;
        iframe.allow = 'autoplay; fullscreen';
        iframe.allowFullscreen = true;
        videoArea.appendChild(iframe);

        // Le API Vimeo sono asincrone: si tiene una copia locale aggiornata dagli eventi,
        // cosi' l'adattatore resta sincrono come gli altri.
        const vimeo = new window.Vimeo.Player(iframe);
        const cache = { time: 0, playing: false };
        const track = (playing) => (data) => {
            cache.time = data.seconds;
            if (playing !== null) cache.playing = playing;
            poll();
        };

        vimeo.on('timeupdate', (data) => { cache.time = data.seconds; });
        vimeo.on('play', track(true));
        vimeo.on('pause', track(false));
        vimeo.on('ended', track(false));
        vimeo.on('seeked', track(null));

        await vimeo.ready();
        onPlayerReady({
            getTime: () => cache.time,
            isPlaying: () => cache.playing,
            play: () => vimeo.play(),
            pause: () => vimeo.pause(),
            seekTo: (seconds) => {
                cache.time = seconds;
                vimeo.setCurrentTime(seconds);
            },
        });
    }

    function load(descriptor) {
        resetPlayer();
        showStatus('');

        const shown = {
            local: () => showHtml5(descriptor.src),
            youtube: () => showYouTube(descriptor.id),
            vimeo: () => showVimeo(descriptor.id),
        }[descriptor.kind];

        if (!shown) {
            showStatus(t('video_unknown_kind', { kind: descriptor.kind }));
            return;
        }

        Promise.resolve(shown()).catch((err) => {
            console.error(err);
            showStatus(t('video_player_failed'));
        });
    }

    // ---------- Eventi ----------

    // Il tagger annuncia ogni nuovo stato: alla prima occasione (o se cambia la sorgente) si carica il video.
    document.addEventListener('rugby:state', (e) => {
        latestState = e.detail;
        const descriptor = latestState.video;

        // Sorgente salvata ma non utilizzabile (es. file spostato o rinominato dopo il setup).
        if (!descriptor) {
            if (latestState.video_url && loadedKey === null) {
                showStatus(t('video_not_available', { source: latestState.video_url }));
            }
            return;
        }

        const key = JSON.stringify(descriptor);
        if (key !== loadedKey) {
            loadedKey = key;
            load(descriptor);
        }
    });

    // Interfaccia usata dal tagger (app.js): lettura della posizione e play/pausa da "Time Off".
    window.RugbyVideo = {
        isActive: () => player !== null,
        getTime: () => player.getTime(),
        isPlaying: () => player.isPlaying(),
        togglePlay: () => (player.isPlaying() ? player.pause() : player.play()),
    };
})();
