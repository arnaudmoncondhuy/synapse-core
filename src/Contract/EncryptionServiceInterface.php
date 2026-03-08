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
     * @param string $plaintext le texte original à protéger
     *
     * @throws \RuntimeException si le chiffrement échoue ou si la clé est manquante
     *
     * @return string le texte chiffré (souvent encodé en base64 ou hex)
     */
    public function encrypt(string $plaintext): string;

    /**
     * Déchiffre un texte chiffré précédemment.
     *
     * @param string $ciphertext le texte sous forme chiffrée
     *
     * @throws \RuntimeException si le déchiffrement est impossible (clé incorrecte, corruption)
     *
     * @return string le texte original déchiffré
     */
    public function decrypt(string $ciphertext): string;

    /**
     * Identifie si une chaîne de caractères semble être chiffrée.
     *
     * Permet à Synapse de savoir s'il doit tenter un déchiffrement ou traiter la donnée
     * comme étant déjà en clair (utile lors de l'activation du chiffrement sur des données existantes).
     *
     * @param string $data la chaîne à analyser
     *
     * @return bool true si la donnée porte la signature du service de chiffrement
     */
    public function isEncrypted(string $data): bool;
}
