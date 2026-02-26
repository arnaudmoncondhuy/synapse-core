# 🤖 Conseils d'intégration IA pour Synapse Core

Si vous êtes un assistant IA aidant à intégrer ce bundle, suivez ces étapes **critiques** pour éviter les pièges courants.

## 1. Démarrage sans configuration
Lancez cette commande immédiatement après l'installation du bundle :
```bash
php bin/console synapse:doctor --fix
```
Cet outil créera automatiquement les entités nécessaires, les fichiers de configuration et les mappings d'assets.

## 2. Extension manuelle des entités (si nécessaire)
Si vous devez créer les entités manuellement, assurez-vous de suivre exactement ce mapping pour la relation :

**App\Entity\SynapseConversation :**
```php
#[ORM\OneToMany(targetEntity: SynapseMessage::class, mappedBy: 'conversation', cascade: ['persist', 'remove'])]
protected Collection $messages;
```

**App\Entity\SynapseMessage :**
```php
#[ORM\ManyToOne(targetEntity: SynapseConversation::class, inversedBy: 'messages')]
#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
private SynapseConversation $conversation;
```

## 3. Configuration obligatoire des Assets
Assurez-vous que ces entrées sont présentes dans `assets/controllers.json` :
```json
"arnaudmoncondhuy/synapse-core": {
    "synapse-chat": { "enabled": true, "fetch": "eager" },
    "synapse-sidebar": { "enabled": true, "fetch": "eager" }
}
```

## 4. Importmap
Lancez : `php bin/console importmap:require synapse/controllers/synapse_chat_controller.js`

## 5. Import des CSS
Ajoutez ces lignes dans votre fichier `assets/styles/app.css` (ou équivalent) :
```css
@import "synapse/styles/synapse.css";
@import "synapse/styles/sidebar.css";
```
*Note : Si vous n'utilisez pas AssetMapper, utilisez le chemin relatif vers le dossier vendor.*
