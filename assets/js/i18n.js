(function () {
    'use strict';

    /**
     * Testo tradotto per il JavaScript: le stringhe arrivano dal server (window.I18N, voci 'js.'
     * del dizionario, nella lingua scelta). Segnaposto {nome} sostituiti da params.
     * Una chiave mancante si vede in pagina e finisce in console, invece di sparire.
     */
    window.t = function (key, params) {
        const strings = window.I18N || {};
        let text = strings[key];
        if (text === undefined) {
            console.error('I18n: traduzione mancante', key);
            text = key;
        }

        Object.entries(params || {}).forEach(([name, value]) => {
            text = text.split('{' + name + '}').join(String(value));
        });

        return text;
    };
})();
