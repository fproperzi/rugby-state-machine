(function () {
    'use strict';

    /*
     * Sessione scaduta o chiusa (es. utente disattivato, password cambiata da un'altra parte):
     * le API rispondono 401. Invece di mostrare errori in ogni punto della pagina, qualunque
     * chiamata che riceve 401 riporta al login, che poi torna alla pagina corrente.
     * Fanno eccezione le API di accesso, dove 401 significa "credenziali sbagliate".
     */
    const ACCESS_APIS = ['api/login.php', 'api/install.php'];
    const originalFetch = window.fetch.bind(window);

    window.fetch = async function (input, init) {
        const response = await originalFetch(input, init);
        const url = typeof input === 'string' ? input : input.url;

        if (response.status === 401 && !ACCESS_APIS.some((api) => url.includes(api))) {
            const next = encodeURIComponent(window.location.search.replace(/^\?/, ''));
            window.location.href = 'index.php?page=login&next=' + next;
        }

        return response;
    };

    // Pulsante "Esci" presente nelle pagine con la barra utente.
    document.addEventListener('click', async (e) => {
        if (!e.target.closest('[data-logout]')) return;
        e.preventDefault();
        const res = await originalFetch('api/logout.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: '{}',
        });
        const body = await res.json().catch(() => ({}));
        window.location.href = body.redirect || 'index.php?page=login';
    });
})();
