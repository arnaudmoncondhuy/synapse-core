<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Util;

/**
 * Utilitaires pour le traitement et l'assainissement de texte.
 *
 * Cette classe finale fournit des méthodes statiques pour garantir l'intégrité des donnés
 * échangées avec l'API, notamment l'encodage UTF-8 qui est critique pour le JSON.
 */
final class TextUtil
{
    /**
     * Garantit que la chaîne est en UTF-8 valide.
     *
     * Si la chaîne contient des caractères invalides (ex: latin1 mixé),
     * elle est convertie pour éviter que `json_encode` ne retourne une erreur ou `false`.
     *
     * @param string $input la chaîne brute
     *
     * @return string la chaîne assainie en UTF-8
     */
    public static function sanitizeUtf8(string $input): string
    {
        if (mb_check_encoding($input, 'UTF-8')) {
            return $input;
        }

        return mb_convert_encoding($input, 'UTF-8', 'UTF-8');
    }

    /**
     * Assainit récursivement toutes les chaînes d'un tableau (multidimensionnel).
     *
     * @param array $data le tableau à nettoyer
     *
     * @return array le tableau propre
     */
    public static function sanitizeArrayUtf8(array $data): array
    {
        $cleaned = [];
        foreach ($data as $key => $value) {
            $cleanKey = is_string($key) ? self::sanitizeUtf8($key) : $key;
            if (is_string($value)) {
                $cleaned[$cleanKey] = self::sanitizeUtf8($value);
            } elseif (is_array($value)) {
                $cleaned[$cleanKey] = self::sanitizeArrayUtf8($value);
            } else {
                $cleaned[$cleanKey] = $value;
            }
        }

        return $cleaned;
    }
}
