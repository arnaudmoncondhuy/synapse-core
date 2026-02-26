# RetentionPolicyInterface

L'interface `RetentionPolicyInterface` répond aux exigences de conservation des données. Elle permet d'automatiser le nettoyage des anciennes conversations pour respecter vos engagements de confidentialité ou le RGPD.

## 🛠 Pourquoi l'utiliser ?

*   **Droit à l'oubli** : Supprimer automatiquement les messages après X jours.
*   **Hygiène des données** : Éviter l'accumulation inutile de données lourdes en base.
*   **Conformité** : Appliquer des règles différentes selon les types d'utilisateurs ou les pays.

---

## 📋 Résumé du Contrat

| Méthode | Rôle |
| :--- | :--- |
| `shouldDeleteConversation(...)`| Décide si une conversation doit être purgée maintenant. |
| `getMaxRetentionDays()` | Temps de conservation par défaut pour les rapports. |

---

## 🚀 Exemple : Politique de 30 jours

=== "StandardRetentionPolicy.php"

    ```php
    namespace App\Synapse\Security;

    use ArnaudMoncondhuy\SynapseCore\Contract\RetentionPolicyInterface;

    class StandardRetentionPolicy implements RetentionPolicyInterface
    {
        public function shouldDeleteConversation($conversation): bool
        {
            $updatedAt = $conversation->getUpdatedAt();
            $diff = $updatedAt->diff(new \DateTime());
            
            return $diff->days > 30;
        }

        public function getMaxRetentionDays(): int { return 30; }
    }
    ```

---

## 💡 Conseils d'implémentation

> [!TIP]
> **Action Différée** : Synapse Core fournit une commande console `synapse:purge` qui utilise cette interface. Vous pouvez planifier cette commande via un **CRON** pour un nettoyage quotidien.

*   **Ciblage** : Vous pouvez affiner la logique pour, par exemple, ne jamais supprimer les conversations des comptes "VIP".

---


