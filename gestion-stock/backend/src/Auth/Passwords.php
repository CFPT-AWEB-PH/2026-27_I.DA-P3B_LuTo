<?php

declare(strict_types=1);

namespace App\Auth;

/** Mots de passe et identifiants des comptes créés par un gestionnaire. */
final class Passwords
{
    // Sans les caractères que l'on confond sur une fiche imprimée : l, 1, i, o, 0
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** Mot de passe aléatoire lisible : « kx7mp-4ht92 ». */
    public static function generate(): string
    {
        $chars = '';
        for ($i = 0; $i < 10; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return substr($chars, 0, 5) . '-' . substr($chars, 5);
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /** « ANDRE FILIPE » + « SANCHES MOTA » → « andre.sanchesmota ». */
    public static function usernameBase(string $firstName, string $lastName): string
    {
        $first = explode(' ', self::slug($firstName))[0] ?? '';
        $last = str_replace(' ', '', self::slug($lastName));
        $base = trim(mb_substr($first, 0, 25) . '.' . mb_substr($last, 0, 30), '.');
        return $base === '' ? 'utilisateur' : $base;
    }

    /** Minuscules sans accents : seuls a-z, 0-9 et des espaces restent. */
    public static function slug(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c', 'č' => 'c', 'ć' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'œ' => 'oe', 'š' => 's', 'ß' => 'ss',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ž' => 'z',
        ]);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';
        return trim($text);
    }
}
