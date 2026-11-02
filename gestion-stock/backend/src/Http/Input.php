<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Validation stricte des champs d'un corps JSON. Toute erreur renvoie 422
 * avec le nom du champ fautif.
 */
final class Input
{
    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function string(string $key, int $max, int $min = 1): string
    {
        $value = $this->data[$key] ?? null;
        if (!is_string($value)) {
            self::fail($key, 'Texte attendu.');
        }
        $value = trim($value);
        $length = mb_strlen($value);
        if ($length < $min) {
            self::fail($key, $min === 1 ? 'Champ obligatoire.' : "Au moins {$min} caractères.");
        }
        if ($length > $max) {
            self::fail($key, "{$max} caractères maximum.");
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            self::fail($key, 'Caractères de contrôle interdits.');
        }
        return $value;
    }

    public function optionalString(string $key, int $max, string $default = ''): string
    {
        return ($this->data[$key] ?? null) === null || $this->data[$key] === '' ? $default : $this->string($key, $max, 0);
    }

    public function int(string $key, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        $value = $this->data[$key] ?? null;
        if (!is_int($value)) {
            self::fail($key, 'Nombre entier attendu.');
        }
        if ($value < $min || $value > $max) {
            self::fail($key, "Valeur entre {$min} et {$max} attendue.");
        }
        return $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->data[$key] ?? null;
        if (!is_bool($value)) {
            self::fail($key, 'Booléen attendu.');
        }
        return $value;
    }

    /** @param list<string> $allowed */
    public function enum(string $key, array $allowed): string
    {
        $value = $this->data[$key] ?? null;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            self::fail($key, 'Valeur non autorisée.');
        }
        return $value;
    }

    /** Couleur « #rrggbb », renvoyée en minuscules. */
    public function color(string $key): string
    {
        $value = $this->data[$key] ?? null;
        if (!is_string($value) || !preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            self::fail($key, 'Couleur au format #rrggbb attendue.');
        }
        return strtolower($value);
    }

    /**
     * Liste de points [[x, z], …] en entiers.
     *
     * @return list<array{int, int}>
     */
    public function points(string $key, int $minPoints, int $maxPoints, int $min, int $max): array
    {
        $value = $this->data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value) || count($value) < $minPoints || count($value) > $maxPoints) {
            self::fail($key, "Entre {$minPoints} et {$maxPoints} points attendus.");
        }
        $points = [];
        foreach ($value as $i => $point) {
            if (!is_array($point) || !array_is_list($point) || count($point) !== 2 || !is_int($point[0]) || !is_int($point[1])) {
                self::fail($key, 'Point n° ' . ($i + 1) . ' invalide.');
            }
            if (min($point) < $min || max($point) > $max) {
                self::fail($key, 'Point n° ' . ($i + 1) . " hors limites ({$min} à {$max}).");
            }
            $points[] = [$point[0], $point[1]];
        }
        return $points;
    }

    /**
     * Quantité décimale (3 décimales au plus), renvoyée sous forme de chaîne exacte pour SQL.
     */
    public function quantity(string $key, bool $allowZero = false): string
    {
        $value = $this->data[$key] ?? null;
        if (!is_int($value) && !is_float($value)) {
            self::fail($key, 'Quantité numérique attendue.');
        }
        if ($value < 0 || (!$allowZero && $value == 0) || $value > 999_999_999) {
            self::fail($key, $allowZero ? 'La quantité ne peut pas être négative.' : 'La quantité doit être supérieure à zéro.');
        }
        $thousandths = $value * 1000;
        if (abs($thousandths - round($thousandths)) > 1e-6) {
            self::fail($key, '3 décimales au maximum.');
        }
        return number_format(round($thousandths) / 1000, 3, '.', '');
    }

    public function optionalQuantity(string $key, bool $allowZero = false): ?string
    {
        return ($this->data[$key] ?? null) === null ? null : $this->quantity($key, $allowZero);
    }

    /** Entier facultatif : $default si absent ou null. */
    public function optionalInt(string $key, ?int $default, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int
    {
        return ($this->data[$key] ?? null) === null ? $default : $this->int($key, $min, $max);
    }

    /** @return list<string> */
    public function stringList(string $key, int $maxItems, int $maxLength): array
    {
        $value = $this->data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value) || count($value) > $maxItems) {
            self::fail($key, "Liste de {$maxItems} éléments maximum attendue.");
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '' || mb_strlen($item) > $maxLength) {
                self::fail($key, 'Élément de liste invalide.');
            }
        }
        return array_values(array_unique($value));
    }

    public static function fail(string $field, string $message): never
    {
        throw new HttpException(422, 'Données invalides.', ['fields' => [$field => $message]]);
    }
}
