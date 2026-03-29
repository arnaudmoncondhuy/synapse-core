# RagSourceProviderInterface & RagSourceProviderFactoryInterface

Interfaces pour déclarer des sources de documents RAG (Retrieval-Augmented Generation) personnalisées.

---

## `RagSourceProviderInterface`

Représente une source unique de documents indexables (Google Drive, Notion, fichiers locaux, base de données, API distante, etc.).

```php
interface RagSourceProviderInterface
{
    /**
     * Identifiant unique de la source (ex: 'lycee_intranet', 'notion-kb-123')
     * Utilisé en BDD et dans les commandes CLI.
     */
    public function getSlug(): string;

    /**
     * Nom d'affichage dans l'interface admin.
     */
    public function getName(): string;

    /**
     * Description longue de la source.
     */
    public function getDescription(): string;

    /**
     * Récupère les documents depuis la source.
     *
     * Retourne : iterable<DocumentInterface>
     * Chaque document doit avoir :
     *   - getIdentifier(): string — ID unique dans la source (ex: Google Drive file ID)
     *   - getTitle(): string — Nom du fichier/document
     *   - getContent(): string — Contenu textuel (sera chunkifié)
     *   - getMetadata(): array — Données additionnelles (page, URL, type MIME, etc.)
     */
    public function fetchDocuments(): iterable;

    /**
     * Compte le nombre total de documents dans la source.
     * Optionnel pour l'affichage du progrès lors de la réindexation.
     */
    public function countDocuments(): int;
}
```

### Exemple : Google Drive

```php
use ArnaudMoncondhuy\SynapseCore\Contract\RagSourceProviderInterface;

class GoogleDriveSourceProvider implements RagSourceProviderInterface
{
    private string $folderId;
    private \Google_Service_Drive $driveService;

    public function __construct(string $folderId, \Google_Service_Drive $driveService)
    {
        $this->folderId = $folderId;
        $this->driveService = $driveService;
    }

    public function getSlug(): string
    {
        return 'lycee_intranet';
    }

    public function getName(): string
    {
        return 'Intranet du Lycée';
    }

    public function getDescription(): string
    {
        return 'Documents partagés sur Google Drive (supports pédagogiques, règlements, etc.)';
    }

    public function fetchDocuments(): iterable
    {
        $results = $this->driveService->files->listFiles([
            'q' => "'{$this->folderId}' in parents",
            'spaces' => 'drive',
            'pageSize' => 100,
            'fields' => 'files(id, name, mimeType, webContentLink)',
        ]);

        foreach ($results->getFiles() as $file) {
            yield new class($file) implements DocumentInterface {
                public function __construct(private \Google_Service_Drive_DriveFile $file) {}

                public function getIdentifier(): string { return $this->file->getId(); }
                public function getTitle(): string { return $this->file->getName(); }

                public function getContent(): string
                {
                    // Télécharger et extraire le contenu
                    $link = $this->file->getWebContentLink();
                    // ... implémenter extraction texte ...
                    return $extractedText;
                }

                public function getMetadata(): array
                {
                    return [
                        'mime_type' => $this->file->getMimeType(),
                        'drive_link' => $this->file->getWebViewLink(),
                    ];
                }
            };
        }
    }

    public function countDocuments(): int
    {
        $results = $this->driveService->files->listFiles([
            'q' => "'{$this->folderId}' in parents",
            'spaces' => 'drive',
            'fields' => 'files(id)',
        ]);
        return count($results->getFiles());
    }
}
```

---

## `RagSourceProviderFactoryInterface`

Fabrique qui enregistre toutes les sources RAG disponibles dans l'application.

```php
interface RagSourceProviderFactoryInterface
{
    /**
     * Retourne un itérable de tous les providers de source RAG disponibles.
     *
     * @return iterable<RagSourceProviderInterface>
     */
    public function createProviders(): iterable;
}
```

### Exemple : Fabrique multi-sources

```php
class RagSourceProviderFactory implements RagSourceProviderFactoryInterface
{
    private \Google_Service_Drive $driveService;
    private NotionDatabase $notionDb;

    public function __construct(\Google_Service_Drive $driveService, NotionDatabase $notionDb)
    {
        $this->driveService = $driveService;
        $this->notionDb = $notionDb;
    }

    public function createProviders(): iterable
    {
        // Source 1 : Google Drive (dossier "Lycée Ressources")
        yield new GoogleDriveSourceProvider('FOLDER_ID_HERE', $this->driveService);

        // Source 2 : Notion (base de connaissances)
        yield new NotionSourceProvider('NOTION_DB_ID', $this->notionDb);

        // Source 3 : Fichiers locaux (uploads utilisateurs)
        yield new LocalFilesSourceProvider('/var/synapse-uploads');
    }
}
```

---

## Enregistrement dans l'Application

### 1. Implémenter la fabrique

```php
// src/Rag/RagSourceProviderFactory.php
namespace App\Rag;

use ArnaudMoncondhuy\SynapseCore\Contract\RagSourceProviderFactoryInterface;

class RagSourceProviderFactory implements RagSourceProviderFactoryInterface
{
    // ... (voir exemple ci-dessus)
}
```

### 2. Déclarer le service

```yaml
# config/services.yaml
services:
  App\Rag\RagSourceProviderFactory: ~

  synapse.rag_source_provider_factory:
    alias: App\Rag\RagSourceProviderFactory
    public: false
```

### 3. Activer dans la config Synapse

```yaml
# config/packages/synapse.yaml
synapse:
    rag:
        source_provider_factory: '@synapse.rag_source_provider_factory'
```

---

## Utilisation

Une fois enregistrées, les sources apparaissent dans :

1. **Interface Admin** : `Mémoire › Sources RAG`
2. **Commandes CLI** :
   ```bash
   # Réindexer une source
   php bin/console synapse:rag:reindex lycee_intranet

   # Tester une recherche
   php bin/console synapse:rag:test "ma requête" --source=lycee_intranet
   ```
3. **Code PHP** : Via `RagSourceRegistry::getSources()` ou `getSource($slug)`

---

## Interface `DocumentInterface` (Helper)

Bien que non requise (iterable suffit), l'utilisation d'une interface commune facilite la typage :

```php
interface DocumentInterface
{
    public function getIdentifier(): string;       // ID unique dans la source
    public function getTitle(): string;            // Nom/titre
    public function getContent(): string;          // Texte à chunkifier
    public function getMetadata(): array;          // Données additionnelles
}
```

---

## Bonnes Pratiques

- **Caching** : Mettez en cache les listes de documents si la source est lente
- **Gestion d'erreurs** : Loggez les erreurs plutôt que de lever (permet la réindexation partielle)
- **Pagination** : Pour les sources volumineuses (100+ docs), utilisez des générateurs
- **Contenu** : Nettoyez le texte (trim, supprimer formatage, etc.) avant de retourner
- **Métadonnées** : Incluez toujours une URL ou lien de retour vers le document original

---

## Exemple Complet : Source Notion

```php
namespace App\Rag;

use ArnaudMoncondhuy\SynapseCore\Contract\RagSourceProviderInterface;

class NotionSourceProvider implements RagSourceProviderInterface
{
    private string $databaseId;
    private NotionClient $client;

    public function __construct(string $databaseId, NotionClient $client)
    {
        $this->databaseId = $databaseId;
        $this->client = $client;
    }

    public function getSlug(): string { return 'notion-kb'; }
    public function getName(): string { return 'Base de Connaissances Notion'; }
    public function getDescription(): string { return 'Articles et guides dans Notion'; }

    public function fetchDocuments(): iterable
    {
        $pages = $this->client->database($this->databaseId)->query([
            'filter' => ['property' => 'Status', 'select' => ['equals' => 'Published']],
        ]);

        foreach ($pages as $page) {
            // Convertir les blocs Notion en texte brut
            $content = $this->extractTextFromBlocks($page['id']);

            yield new class($page, $content) implements DocumentInterface {
                private array $page;
                private string $content;

                public function __construct(array $page, string $content)
                {
                    $this->page = $page;
                    $this->content = $content;
                }

                public function getIdentifier(): string { return $this->page['id']; }
                public function getTitle(): string { return $this->page['properties']['Title']['title'][0]['plain_text'] ?? 'Untitled'; }
                public function getContent(): string { return $this->content; }
                public function getMetadata(): array {
                    return [
                        'notion_url' => $this->page['public_url'],
                        'created_at' => $this->page['created_time'],
                    ];
                }
            };
        }
    }

    public function countDocuments(): int
    {
        return $this->client->database($this->databaseId)->query()->count();
    }

    private function extractTextFromBlocks(string $pageId): string
    {
        $blocks = $this->client->page($pageId)->blocks()->list();
        $text = [];
        foreach ($blocks as $block) {
            if (isset($block['paragraph'])) {
                $text[] = $block['paragraph']['rich_text'][0]['plain_text'] ?? '';
            }
        }
        return implode("\n", array_filter($text));
    }
}
```
