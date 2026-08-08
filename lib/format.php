<?php
declare(strict_types=1);

/**
 * Fonctions de formatage – ARA Tech WiFi
 * -----------------------------------------------------------------
 * Utilisées par les pages admin et les scripts de génération de tickets.
 * Aucune dépendance externe.
 * -----------------------------------------------------------------
 */

/**
 * Convertit des octets en chaîne lisible (Ko, Mo, Go…).
 */
function formatBytes(int|float $bytes, int $precision = 2): string
{
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int) floor(log((float)$bytes, 1024));
    return round($bytes / (1024 ** $i), $precision) . ' ' . $units[$i];
}

/**
 * Convertit une durée en secondes (ou chaîne HH:MM:SS) en format lisible.
 * Accepte un entier (secondes) ou une chaîne "HH:MM:SS".
 * Retourne "Xj HH:MM:SS" si > 24h, sinon "HH:MM:SS".
 */
function formatDTM(int|string $input): string
{
    // Si c'est déjà une chaîne, on essaie de la convertir en secondes
    if (is_string($input)) {
        $parts = explode(':', $input);
        if (count($parts) === 3) {
            $seconds = (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
        } else {
            return $input; // on ne sait pas parser, on retourne tel quel
        }
    } else {
        $seconds = $input;
    }

    if ($seconds < 0) $seconds = 0;

    $d = floor($seconds / 86400);
    $h = floor(($seconds % 86400) / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;

    $result = sprintf('%02d:%02d:%02d', $h, $m, $s);
    if ($d > 0) {
        $result = $d . 'j ' . $result;
    }
    return $result;
}

/**
 * Formate un montant en FCFA (format français : séparateur d'espace, pas de décimale).
 */
function formatMoney(float|int $amount): string
{
    return number_format($amount, 0, ',', ' ') . ' FCFA';
}

/**
 * Tronque une chaîne trop longue.
 */
function truncate(string $text, int $maxLength = 30): string
{
    if (mb_strlen($text) <= $maxLength) return $text;
    return mb_substr($text, 0, $maxLength - 3) . '...';
}
