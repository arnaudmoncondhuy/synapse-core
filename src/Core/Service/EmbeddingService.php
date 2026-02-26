<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Service;

use ArnaudMoncondhuy\SynapseCore\Contract\EmbeddingClientInterface;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Shared\Event\SynapseEmbeddingCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseProvider;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Service de haut niveau pour exécuter des générations d'embeddings.
 * Détermine dynamiquement le provider actif et le modèle à utiliser.
 */
class EmbeddingService
{
    /** @var array<string, EmbeddingClientInterface> */
    private array $clients = [];

    public function __construct(
        #[TaggedIterator('synapse.llm_client')] iterable $clients,
        private SynapseProviderRepository $providerRepository,
        private ModelCapabilityRegistry $capabilityRegistry,
        private EventDispatcherInterface $eventDispatcher,
        private \ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository $configRepository,
    ) {
        foreach ($clients as $client) {
            // Seuls les clients implémentant l'interface d'embedding nous intéressent ici
            if ($client instanceof EmbeddingClientInterface && method_exists($client, 'getProviderName')) {
                $this->clients[$client->getProviderName()] = $client;
            }
        }
    }

    /**
     * Génère des embeddings pour un texte ou un tableau de textes.
     *
     * @param string|array $input Texte unique ou liste de textes.
     * @param string|null  $model Modèle optionnel (si null, le défaut est résolu dynamiquement).
     *
     * @return array Structure : ['embeddings' => [...], 'usage' => ['prompt_tokens' => X, 'total_tokens' => Y]]
     */
    public function generateEmbeddings(string|array $input, ?string $model = null): array
    {
        $config = $this->configRepository->getGlobalConfig();

        // 1. Déterminer le provider actif
        // On donne la priorité au provider d'embedding configuré globalement
        $providerName = $config->getEmbeddingProvider();

        if (!$providerName) {
            $providerEntity = $this->getActiveProvider();
            if (!$providerEntity) {
                throw new \RuntimeException("Aucun provider Synapse actif et configuré n'a été trouvé.");
            }
            $providerName = $providerEntity->getName();
        }

        if (!isset($this->clients[$providerName])) {
            throw new \RuntimeException(sprintf("Le client d'embeddings pour le provider '%s' n'existe pas ou n'implémente pas EmbeddingClientInterface.", $providerName));
        }
        $client = $this->clients[$providerName];

        // 2. Déterminer le modèle et les options à utiliser
        $resolvedModel = $model ?? $config->getEmbeddingModel() ?? $this->resolveDefaultEmbeddingModel($providerName);
        $options = [];
        if ($config->getEmbeddingDimension()) {
            $options['output_dimensionality'] = $config->getEmbeddingDimension();
        }

        // 3. Appel au client
        $result = $client->generateEmbeddings($input, $resolvedModel, $options);

        // 4. Token Accounting (Emission d'événement)
        if (isset($result['usage'])) {
            $event = new SynapseEmbeddingCompletedEvent(
                $resolvedModel,
                $providerName,
                $result['usage']['prompt_tokens'] ?? 0,
                $result['usage']['total_tokens'] ?? 0
            );
            $this->eventDispatcher->dispatch($event, SynapseEmbeddingCompletedEvent::NAME);
        }

        return $result;
    }

    /**
     * Retourne le le 1er provider configuré et activé.
     */
    private function getActiveProvider(): ?SynapseProvider
    {
        foreach ($this->providerRepository->findAll() as $provider) {
            if ($provider->isEnabled() && $provider->isConfigured()) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * Recherche le premier modèle connu de type "embedding" pour le provider donné.
     */
    private function resolveDefaultEmbeddingModel(string $providerName): string
    {
        $modelsForProvider = $this->capabilityRegistry->getModelsForProvider($providerName);

        foreach ($modelsForProvider as $modelId) {
            $caps = $this->capabilityRegistry->getCapabilities($modelId);
            if ($caps->type === 'embedding') {
                return $modelId;
            }
        }

        throw new \RuntimeException(sprintf("Aucun modèle par défaut de type 'embedding' trouvé pour le provider '%s'.", $providerName));
    }
}
