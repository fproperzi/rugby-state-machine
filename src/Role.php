<?php

namespace Rugby;

/**
 * Ruoli degli utenti, dal piu' al meno potente. Ogni ruolo puo' fare tutto quello che fanno
 * i ruoli inferiori: admin > tagger > viewer.
 */
enum Role: string
{
    /** Gestisce gli utenti, oltre a tutto il resto. */
    case Admin = 'admin';
    /** Crea partite, tagga, esporta e importa. */
    case Tagger = 'tagger';
    /** Sola lettura: elenco partite, statistiche, export. */
    case Viewer = 'viewer';

    /**
     * True se questo ruolo comprende i permessi del ruolo richiesto.
     *
     * @param Role $required ruolo minimo richiesto dall'operazione
     */
    public function allows(Role $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Admin => 3,
            self::Tagger => 2,
            self::Viewer => 1,
        };
    }
}
