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
