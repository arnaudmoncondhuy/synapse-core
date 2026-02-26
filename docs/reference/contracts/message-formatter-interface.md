# MessageFormatterInterface

L'interface `MessageFormatterInterface` gère la traduction entre vos entités Doctrine (`SynapseMessage`) et le format d'échange utilisé par les API LLM.

## 🛠 Pourquoi l'utiliser ?

*   **Personnalisation du format** : Si vous utilisez une API IA qui attend un format de message très spécifique (autre que le standard OpenAI).
*   **Nettoyage des données** : Filtrer ou modifier le contenu des messages avant qu'ils ne sortent de votre serveur.
*   **Enrichissement** : Ajouter des flags ou des métadonnées supplémentaires pour le traitement par le LLM.

---

## 📋 Résumé du Contrat

| Méthode | Entrée | Sortie | Rôle |
| :--- | :--- | :--- | :--- |
| `formatAsArray(...)` | `SynapseMessage` | `array` | Convertit une entité en tableau simple. |
| `formatCollection(...)`| Liste d'entités | `array` | Convertit tout l'historique de chat. |

---

## 💡 Conseils d'implémentation

*   **Format par défaut** : Synapse Core inclut déjà un formateur conforme au standard OpenAI. N'implémentez cette interface que si vous avez des besoins de transformation de données très spécifiques.
*   **Performance** : Cette interface n'est généralement pas le lieu pour des calculs lourds, car elle est appelée juste avant chaque requête API.

---


