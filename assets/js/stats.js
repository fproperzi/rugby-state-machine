(function () {
    'use strict';

    const appEl = document.getElementById('app');
    const matchId = Number(appEl.dataset.matchId);

    function formatClock(totalSeconds) {
        const m = Math.floor(totalSeconds / 60).toString().padStart(2, '0');
        const s = (totalSeconds % 60).toString().padStart(2, '0');
        return `${m}:${s}`;
    }

    function splitBar(container, homeValue, awayValue, homeLabel, awayLabel, homeColor, awayColor) {
        const total = homeValue + awayValue;
        const homePct = total === 0 ? 50 : (homeValue / total) * 100;
        container.innerHTML = '';

        const home = document.createElement('div');
        home.style.width = homePct + '%';
        home.style.background = homeColor;
        home.textContent = homeLabel;

        const away = document.createElement('div');
        away.style.width = (100 - homePct) + '%';
        away.style.background = awayColor;
        away.textContent = awayLabel;

        container.appendChild(home);
        container.appendChild(away);
    }

    function renderScores(container, entries) {
        container.innerHTML = '';
        if (entries.length === 0) {
            const empty = document.createElement('div');
            empty.style.opacity = '.5';
            empty.textContent = t('no_scores');
            container.appendChild(empty);
            return;
        }
        entries.forEach((entry) => {
            const row = document.createElement('div');
            row.textContent = `(${entry.time}) - ${entry.label}`;
            container.appendChild(row);
        });
    }

    function renderGoalPitch(container, positions) {
        container.querySelectorAll('.mark').forEach((el) => el.remove());
        positions.forEach((pos) => {
            const mark = document.createElement('div');
            mark.className = 'mark ' + (pos.scored ? 'scored' : 'missed');
            mark.style.left = pos.x + '%';
            mark.style.top = pos.y + '%';
            container.appendChild(mark);
        });
    }

    async function load() {
        const res = await fetch(`api/stats.php?match_id=${matchId}`);
        const data = await res.json();
        const { match, stats, timeline } = data;

        document.getElementById('home-score').textContent = match.home_score;
        document.getElementById('away-score').textContent = match.away_score;

        splitBar(
            document.getElementById('possession-bar'),
            stats.possession.home, stats.possession.away,
            stats.possession.home + '%', stats.possession.away + '%',
            match.home_color, match.away_color
        );

        renderScores(document.getElementById('scores-home'), stats.scores.home);
        renderScores(document.getElementById('scores-away'), stats.scores.away);

        const kagHome = stats.kicks_at_goal.home;
        const kagAway = stats.kicks_at_goal.away;
        document.getElementById('kag-home-text').textContent = `${kagHome.scored}/${kagHome.attempts}`;
        document.getElementById('kag-away-text').textContent = `${kagAway.scored}/${kagAway.attempts}`;
        splitBar(document.getElementById('kag-home-bar'), kagHome.scored, Math.max(0, kagHome.attempts - kagHome.scored), kagHome.scored ? t('scored') : '', '', match.home_color, '#3a4552');
        splitBar(document.getElementById('kag-away-bar'), kagAway.scored, Math.max(0, kagAway.attempts - kagAway.scored), kagAway.scored ? t('scored') : '', '', match.away_color, '#3a4552');
        renderGoalPitch(document.getElementById('kag-home-pitch'), kagHome.positions);
        renderGoalPitch(document.getElementById('kag-away-pitch'), kagAway.positions);

        const breakdown = document.getElementById('breakdown-rows');
        breakdown.innerHTML = '';
        const rows = [
            [t('mauls'), stats.mauls],
            [t('turnovers'), stats.turnovers],
            [t('knock_ons'), stats.knock_ons],
            [t('kick_offs_reclaimed'), { home: stats.kick_offs.home.reclaimed, away: stats.kick_offs.away.reclaimed }],
        ];
        rows.forEach(([title, values]) => {
            const row = document.createElement('div');
            row.className = 'stat-row';
            row.innerHTML = '<div class="label"><span></span></div>';
            row.querySelector('span').textContent = title;
            const bar = document.createElement('div');
            bar.className = 'split-bar';
            row.appendChild(bar);
            breakdown.appendChild(row);
            splitBar(bar, values.home, values.away, String(values.home), String(values.away), match.home_color, match.away_color);
        });

        setupScrubber(timeline, match.match_seconds);
    }

    function setupScrubber(timeline, totalSeconds) {
        const scrub = document.getElementById('scrub');
        scrub.max = String(totalSeconds);

        const update = (value) => {
            document.getElementById('scrub-time').textContent = formatClock(value);

            let current = null;
            for (const entry of timeline) {
                if (entry.match_seconds <= value) {
                    current = entry;
                } else {
                    break;
                }
            }

            if (current) {
                document.getElementById('scrub-score').textContent = `${current.home_score} - ${current.away_score}`;
                document.getElementById('scrub-event').textContent = current.label;
            } else {
                document.getElementById('scrub-score').textContent = '0 - 0';
                document.getElementById('scrub-event').textContent = '';
            }
        };

        scrub.addEventListener('input', () => update(Number(scrub.value)));
        update(totalSeconds);
        scrub.value = String(totalSeconds);
    }

    load();
})();
