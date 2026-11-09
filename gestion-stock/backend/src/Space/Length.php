<?php

declare(strict_types=1);

namespace App\Space;

/** Affichage d'une longueur stockée en millimètres dans l'unité choisie pour la salle. */
final class Length
{
    public static function format(int|float $mm, string $unit = 'mm'): string
    {
        return match ($unit) {
            'cm' => self::number($mm / 10, 1) . ' cm',
            'm' => self::number($mm / 1000, 3) . ' m',
            default => self::number($mm, 0) . ' mm',
        };
    }

    private static function number(float $value, int $decimals): string
    {
        $text = number_format($value, $decimals, ',', "\u{202F}");
        return $decimals > 0 ? rtrim(rtrim($text, '0'), ',') : $text;
    }
}
