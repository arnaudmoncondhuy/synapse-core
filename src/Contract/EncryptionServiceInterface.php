<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

/**
 * Interface pour les services de chiffrement des données sensibles.
 *
 * Permet au bundle de sécuriser les messages et les credentials en base de données.
 * Plusieurs stratégies peuvent être implémentées (libsodium, openssl, ou NullService pour le dev).
 */
interface EncryptionServiceInterface
{
    /**
     * Chiffre un texte en clair.
     *
     * @param string $plaintext Le texte original à protéger.
     *
     * @return string Le texte chiffré (souvent encodé en base64 ou hex).
     *
     * @throws \RuntimeException Si le chiffrement échoue ou si la clé est manquante.
     */
    public function encrypt(string $plaintext): string;

    /**
     * Déchiffre un texte chiffré précédemment.
     *
     * @param string $ciphertext Le texte sous forme chiffrée.
     *
     * @return string Le texte original déchiffré.
     *
     * @throws \RuntimeException Si le déchiffrement est impossible (clé incorrecte, corruption).
     */
    public function decrypt(string $ciphertext): string;

    /**
     * Identifie si une chaîne de caractères semble être chiffrée.
     *
     * Permet à Synapse de savoir s'il doit tenter un déchiffrement ou traiter la donnée
     * comme étant déjà en clair (utile lors de l'activation du chiffrement sur des données existantes).
     *
     * @param string $data La chaîne à analyser.
     *
     * @return bool True si la donnée porte la signature du service de chiffrement.
     */
    public function isEncrypted(string $data): bool;
}
