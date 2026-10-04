<?php

namespace Rugby;

/**
 * Helper minimi per gli endpoint JSON in api/: niente framework, solo le due
 * operazioni che ogni endpoint ripete (leggere il body, rispondere in JSON).
 */
class Http
{
    public static function jsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    public static function jsonResponse(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }

    public static function errorResponse(string $message, int $status = 400): never
    {
        self::jsonResponse(['error' => $message], $status);
    }
}
