# EncryptionServiceInterface

L'interface `EncryptionServiceInterface` est la sentinelle de la vie privée dans Synapse Core. Elle permet de chiffrer automatiquement les conversations et les données sensibles en base de données.

## 🛠 Pourquoi l'utiliser ?

*   **Sécurité maximale** : Même en cas de fuite de la base de données, les messages des utilisateurs restent illisibles.
*   **Confiance** : Offrir des garanties de confidentialité à vos clients.
*   **RGPD** : Facilite la mise en conformité en protégeant les données à caractère personnel (PII).

---

## 📋 Résumé du Contrat

| Méthode | Entrée | Sortie | Rôle |
| :--- | :--- | :--- | :--- |
| `encrypt(string $data)` | Texte brut | `string` | Transforme le message en suite de caractères chiffrée. |
| `decrypt(string $data)` | Texte chiffré | `string` | Restaure le message original pour l'affichage ou le LLM. |
| `isEncrypted(string $data)` | Texte | `bool` | Détecte si une donnée est déjà chiffrée (prévention). |

---

## 🚀 Exemple : Implémentation basée sur Libsodium

=== "SodiumEncryption.php"

    ```php
    namespace App\Synapse\Security;

    use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;

    class SodiumEncryption implements EncryptionServiceInterface
    {
        public function __construct(private string $key) {}

        public function encrypt(string $data): string
        {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($data, $nonce, $this->key);
            return base64_encode($nonce . $cipher);
        }

        public function decrypt(string $data): string
        {
            $decoded = base64_decode($data);
            $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        }

        public function isEncrypted(string $data): bool
        {
            return str_ends_with($data, '=='); // Détection simpliste
        }
    }
    ```

---

## 💡 Conseils d'implémentation

> [!CAUTION]
> **Gestion des clés** : Ne stockez JAMAIS votre clé de chiffrement dans le code source. Utilisez des variables d'environnement (`.env.local`) ou un coffre-fort de secrets (Vault).

*   **Transparence** : Synapse Core appelle automatiquement ces méthodes via le `ConversationManager`. Vous n'avez pas à gérer le chiffrement manuellement dans vos contrôleurs.

---


