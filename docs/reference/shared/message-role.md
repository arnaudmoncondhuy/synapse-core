# MessageRole (Enum)

L'énumération `MessageRole` définit l'origine et la fonction de chaque message. C'est le dictionnaire qui permet aux modèles d'IA de comprendre la structure de la conversation.

---

## 📋 Les 4 Rôles Clés

| Rôle | Description | Usage |
| :--- | :--- | :--- |
| **`USER`** | L'humain | Vos messages envoyés au chatbot. |
| **`MODEL`** | L'IA | Les réponses générées par Synapse. |
| **`SYSTEM`** | Le cadre | Instructions invisibles pour l'utilisateur (ex: "Sois poli"). |
| **`FUNCTION`** | La tech | Résultat de l'exécution d'un outil PHP. |

---

## 🚀 Utilisation dans votre code

=== "RoleUsage.php"

    ```php
    use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MessageRole;

    // Vérifier si un message doit être affiché dans le chat
    if ($message->getRole()->isDisplayable()) {
        echo $message->getRole()->getEmoji() . " " . $message->getContent();
    }
    ```

---

## 🔍 Référence complète

