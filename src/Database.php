<?php

namespace Rugby;

/**
 * Connessione PDO a SQLite, condivisa come singleton.
 * A ogni connessione applica schema.sql, che e' idempotente (solo CREATE ... IF NOT EXISTS): cosi'
 * anche un DB gia' esistente riceve le tabelle aggiunte in seguito. Le colonne aggiunte a tabelle
 * esistenti richiedono invece ALTER TABLE (vedi ADDED_COLUMNS).
 */
class Database
{
    private static ?\PDO $instance = null;

    /**
     * Colonne aggiunte a schema.sql dopo la prima versione: [tabella => [colonna => definizione]].
     * SQLite non ha "ADD COLUMN IF NOT EXISTS", quindi si confronta con PRAGMA table_info.
     */
    private const ADDED_COLUMNS = [
        'matches' => [
            'video_offset' => 'REAL',
            'created_by' => 'INTEGER REFERENCES users(id) ON DELETE SET NULL',
        ],
    ];

    public static function connection(): \PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $pdo = new \PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $pdo->exec(file_get_contents(SCHEMA_PATH));
        self::addMissingColumns($pdo);

        self::$instance = $pdo;

        return $pdo;
    }

    private static function addMissingColumns(\PDO $pdo): void
    {
        foreach (self::ADDED_COLUMNS as $table => $columns) {
            $existing = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(), 'name');

            foreach ($columns as $column => $definition) {
                if (!in_array($column, $existing, true)) {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                }
            }
        }
    }
}
